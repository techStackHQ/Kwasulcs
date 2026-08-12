<?php

/**
 * ai_quiz.php — Generates quiz questions using Anthropic API
 *
 * POST JSON:
 * { "course_id": 3, "topic_id": null, "scope": "course", "count": 10,
 *   "images_b64": ["data:image/...", ...], "question_type": "hybrid" }
 *
 * Marking model:
 *   - Total marks for the quiz = 100, split unevenly across questions by the AI
 *     based on complexity (not divided equally).
 *   - MCQ / True-False: full marks or zero, exact match.
 *   - Theory questions carry a "rubric":
 *       format = "list_explain" -> items (N), marks_per_list_item, marks_per_explain_item
 *       format = "general"      -> criteria: [{description, marks}, ...] (3-4 criteria)
 *   - A theory question may reference one of the uploaded images (image_ref index)
 *     when a diagram is essential to answering it.
 */
require_once __DIR__ . '/config.php';
require_login();
// Raised from 150s (Task 22): the automatic retry-on-reasoning-exhaustion
// in call_openai_responses() (config.php) means a single generation call
// can now legitimately make up to 3 attempts at up to 120s each, and the
// Task 18 count top-up can add up to 2 more rounds on top of that in the
// rare worst case — needs real headroom to actually finish a retry rather
// than being killed by PHP's own execution timer partway through one.
set_time_limit(450);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId   = (int)($body['course_id'] ?? 0);
$topicId    = isset($body['topic_id']) && $body['topic_id'] ? (int)$body['topic_id'] : null;
$scope      = $body['scope'] === 'topic' && $topicId ? 'topic' : 'course';
$count      = max(3, min(20, (int)($body['count'] ?? 10)));
$imagesB64  = $body['images_b64'] ?? []; // array of base64 images (past question photos / diagrams)
if (!is_array($imagesB64)) $imagesB64 = [];
$imagesB64  = array_slice($imagesB64, 0, 5); // cap at 5 images
$questionType = $body['question_type'] ?? 'hybrid';
$user         = current_user();

if ($courseId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing course_id']);
    exit();
}

// ── Ensure quiz tables exist (with rubric columns) ────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS quiz_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id INT NOT NULL,
        topic_id INT NULL, scope ENUM('topic','course') NOT NULL DEFAULT 'course',
        uploaded_images JSON NULL, total_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00,
        status ENUM('pending','active','completed') NOT NULL DEFAULT 'pending',
        score INT NULL, total INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at TIMESTAMP NULL,
        INDEX idx_qs (user_id, course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS quiz_questions (
        id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, question_no INT NOT NULL,
        type ENUM('mcq','short','truefalse') NOT NULL,
        marks DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        format VARCHAR(20) NOT NULL DEFAULT 'general',
        question TEXT NOT NULL,
        options JSON NULL, rubric JSON NULL, image_ref INT NULL,
        correct TEXT NOT NULL, explanation TEXT NULL,
        user_answer TEXT NULL, is_correct TINYINT(1) NULL,
        awarded_marks DECIMAL(5,2) NULL, grading_breakdown JSON NULL,
        INDEX idx_qq (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Cache for extract_pdf_text_cached() below — keyed by the absolute
    // file path's hash + file_mtime, same "path+mtime" invalidation
    // convert_office_to_pdf_cached() already uses elsewhere in this
    // codebase (deliberately NOT a documents.id: PDFs are attached from
    // two different source tables — documents AND section_resources — and
    // their id sequences aren't related, so keying on a bare row id risks
    // a false cache hit if both tables happen to share a numeric id).
    // Placed here (before the ALTER TABLE calls below) deliberately: those
    // ALTERs throw once their columns already exist (a real PDOException,
    // not just a suppressed warning — @ doesn't stop that), which aborts
    // the rest of this try block on every run past the first. Anything
    // placed after them would silently never execute in production.
    db()->exec("CREATE TABLE IF NOT EXISTS document_text_cache (
        path_hash CHAR(32) NOT NULL,
        file_mtime INT NOT NULL,
        extracted_text MEDIUMTEXT NOT NULL,
        extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (path_hash, file_mtime)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // In case tables already existed without the new columns
    @db()->exec("ALTER TABLE quiz_questions ADD COLUMN marks DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER type");
    @db()->exec("ALTER TABLE quiz_questions ADD COLUMN format VARCHAR(20) NOT NULL DEFAULT 'general' AFTER marks");
    @db()->exec("ALTER TABLE quiz_questions ADD COLUMN rubric JSON NULL AFTER options");
    @db()->exec("ALTER TABLE quiz_questions ADD COLUMN image_ref INT NULL AFTER rubric");
    @db()->exec("ALTER TABLE quiz_questions ADD COLUMN awarded_marks DECIMAL(5,2) NULL AFTER user_answer");
    @db()->exec("ALTER TABLE quiz_questions ADD COLUMN grading_breakdown JSON NULL AFTER awarded_marks");
    @db()->exec("ALTER TABLE quiz_sessions ADD COLUMN uploaded_images JSON NULL AFTER scope");
    @db()->exec("ALTER TABLE quiz_sessions ADD COLUMN total_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00 AFTER uploaded_images");
} catch (\Throwable $e) { /* tables/columns may already exist */
}

// ── Load course ───────────────────────────────────────────────────────────────
$course = db()->prepare('SELECT c.*, u.full_name AS lecturer_name FROM courses c JOIN users u ON u.id=c.lecturer_id WHERE c.id=?');
$course->execute([$courseId]);
$course = $course->fetch();
if (!$course) {
    http_response_code(404);
    echo json_encode(['error' => 'Course not found']);
    exit();
}

// ── Document helpers ──────────────────────────────────────────────────────────
function extract_docx($path)
{
    if (!file_exists($path)) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $xml  = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return preg_replace('/\n{3,}/', "\n\n", trim($text));
}

/**
 * Cached PDF text extraction via `pdftotext` (Poppler), so a course PDF is
 * read + converted at most once per (file, file version) rather than being
 * re-read and re-attached as a ~1000x-larger base64 blob on every single
 * quiz-generation request. Cache key is (md5(absolute path), file_mtime) —
 * same "path + mtime" invalidation approach convert_office_to_pdf_cached()
 * already uses elsewhere in this codebase: a replaced file gets a
 * different mtime and misses automatically, no separate invalidation step
 * needed. Keyed on the path itself (not a documents.id) because PDFs are
 * attached from two different source tables here (documents and
 * section_resources) with unrelated id sequences.
 *
 * Returns '' (not an error) if `pdftotext` isn't installed on this host,
 * or the PDF has no extractable text layer (e.g. a pure scan/image PDF) —
 * callers MUST treat that as "fall back to attaching the raw PDF instead",
 * exactly like this function didn't exist, never as something to surface
 * to the user. Confirmed present at /usr/bin/pdftotext on production;
 * confirmed ABSENT on local XAMPP (no Poppler install) — this fallback is
 * what keeps local dev working unchanged despite that gap.
 */
function extract_pdf_text_cached(string $absPath): string
{
    if (!is_file($absPath)) return '';
    $pathHash = md5($absPath);
    $mtime    = @filemtime($absPath) ?: 0;

    $cacheStmt = db()->prepare('SELECT extracted_text FROM document_text_cache WHERE path_hash = ? AND file_mtime = ?');
    $cacheStmt->execute([$pathHash, $mtime]);
    $cached = $cacheStmt->fetchColumn();
    if ($cached !== false) {
        return $cached;
    }

    if (!function_exists('shell_exec')) {
        return '';
    }

    // `-` as the output arg makes pdftotext write to stdout, so shell_exec()
    // captures the text directly — no temp file to create/clean up.
    $cmd  = 'pdftotext ' . escapeshellarg($absPath) . ' - 2>/dev/null';
    $text = trim((string) @shell_exec($cmd));
    if ($text === '') {
        return ''; // not installed, or no extractable text layer — caller falls back
    }

    // Stale rows for this path (from before it was replaced) — same
    // "different mtime = old" cleanup convert_office_to_pdf_cached() does.
    db()->prepare('DELETE FROM document_text_cache WHERE path_hash = ? AND file_mtime <> ?')->execute([$pathHash, $mtime]);
    db()->prepare('INSERT INTO document_text_cache (path_hash, file_mtime, extracted_text) VALUES (?, ?, ?)')
        ->execute([$pathHash, $mtime, $text]);

    return $text;
}

// ── Get previously generated questions (for deduplication) ───────────────────
$prevStmt = db()->prepare("
    SELECT qq.question FROM quiz_questions qq
    JOIN quiz_sessions qs ON qs.id = qq.session_id
    WHERE qs.course_id = ? AND qs.user_id = ?
    ORDER BY qq.id DESC LIMIT 50
");
$prevStmt->execute([$courseId, $user['id']]);
$prevQuestions = array_column($prevStmt->fetchAll(), 'question');

// ── Build course context ──────────────────────────────────────────────────────
$context     = "COURSE: {$course['code']} — {$course['title']}\n";
$context    .= "Lecturer: {$course['lecturer_name']}\n\n";
$pdfDocs     = [];

// Load topics — filtered by topic if scope=topic
$topicWhere = $scope === 'topic' ? 'WHERE course_id=? AND id=?' : 'WHERE course_id=?';
$topicParams = $scope === 'topic' ? [$courseId, $topicId] : [$courseId];
$topicsStmt  = db()->prepare("SELECT * FROM topics $topicWhere ORDER BY week_number");
$topicsStmt->execute($topicParams);
$topics = $topicsStmt->fetchAll();

// ── Batch-load documents/videos for every topic in scope, 2 queries total
// (Task 21 fix #4) instead of 2 queries PER topic run in a loop. Negligible
// on localhost, but cuts round-trips meaningfully against AlwaysData's
// networked DB in production.
$topicIds    = array_column($topics, 'id');
$docsByTopic = [];
$vidsByTopic = [];
if ($topicIds) {
    $in = implode(',', array_fill(0, count($topicIds), '?'));
    $docsStmt = db()->prepare("SELECT * FROM documents WHERE topic_id IN ($in)");
    $docsStmt->execute($topicIds);
    foreach ($docsStmt->fetchAll() as $doc) {
        $docsByTopic[(int) $doc['topic_id']][] = $doc;
    }
    $vidsStmt = db()->prepare("SELECT topic_id, title, original_url FROM videos WHERE topic_id IN ($in)");
    $vidsStmt->execute($topicIds);
    foreach ($vidsStmt->fetchAll() as $v) {
        $vidsByTopic[(int) $v['topic_id']][] = $v;
    }
}

$context .= "COURSE CONTENT:\n";
foreach ($topics as $topic) {
    $context .= "\nWeek {$topic['week_number']}: {$topic['title']}\n";

    foreach ($docsByTopic[$topic['id']] ?? [] as $doc) {
        $abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($doc['file_path'], '/');
        $ext = strtolower($doc['file_type'] ?? pathinfo($abs, PATHINFO_EXTENSION));

        if (in_array($ext, ['docx', 'doc'])) {
            $text = extract_docx($abs);
            if ($text) {
                $context .= "  [Lecture Notes: {$doc['title']}]\n";
                $context .= substr($text, 0, 4000) . "\n";
            }
        } elseif ($ext === 'pdf' && file_exists($abs)) {
            // Cached text extraction first (cheap — a few hundred/thousand
            // tokens inlined into $context, same as the .docx branch above)
            // — only falls back to attaching the full base64 PDF (~1000x
            // more tokens, re-uploaded on every single request) when
            // pdftotext isn't available or the PDF has no text layer.
            $pdfText = extract_pdf_text_cached($abs);
            if ($pdfText !== '') {
                $context .= "  [Lecture Notes (PDF): {$doc['title']}]\n";
                $context .= substr($pdfText, 0, 4000) . "\n";
            } else {
                $pdfDocs[] = [
                    'type'    => 'document',
                    'source'  => ['type' => 'base64', 'media_type' => 'application/pdf',
                                  'data' => base64_encode(file_get_contents($abs))],
                    'title'   => "Week {$topic['week_number']}: {$doc['title']}",
                    'context' => "Course document for {$course['code']}",
                ];
                $context .= "  [PDF: {$doc['title']} — content provided separately]\n";
            }
        } elseif ($ext === 'txt' && file_exists($abs)) {
            $context .= "  [Document: {$doc['title']}]\n" . substr(file_get_contents($abs), 0, 3000) . "\n";
        }
    }

    foreach ($vidsByTopic[$topic['id']] ?? [] as $v) {
        $context .= "  [Video: {$v['title']}]\n";
        if (defined('YOUTUBE_API_KEY') && YOUTUBE_API_KEY !== '' && !empty($v['original_url'])) {
            $videoId = youtube_id($v['original_url']);
            if ($videoId) {
                // fetch_youtube_transcript() is now cache-backed (Task 21
                // fix #1, config.php) — this is a slow yt-dlp call only on
                // a genuine cache miss, an instant DB lookup otherwise.
                $transcript = fetch_youtube_transcript($videoId);
                if ($transcript) {
                    $context .= "  [Video Transcript: {$v['title']}]\n";
                    $context .= substr($transcript, 0, 3000) . (strlen($transcript) > 3000 ? "\n...(continues)" : '') . "\n";
                }
            }
        }
    }
}

// Tutorial/exam sections — course-level content, not topic-level (the
// course_sections table has no topic_id column at all), so a single-topic
// scoped request skips these entirely instead of pulling whole-course
// tutorial/exam resources it doesn't need (Task 21 fix #3) — the same
// scope !== 'topic' vs scope === 'topic' distinction topics/documents/
// videos above already apply.
if ($scope !== 'topic') {
    $secs = db()->prepare("SELECT cs.*, sr.title AS res_title, sr.file_path, sr.file_type
        FROM course_sections cs LEFT JOIN section_resources sr ON sr.section_id=cs.id
        WHERE cs.course_id=? ORDER BY cs.section_type");
    $secs->execute([$courseId]);
    $seenSec = [];
    foreach ($secs->fetchAll() as $row) {
        if (!isset($seenSec[$row['id']])) {
            $seenSec[$row['id']] = true;
            $label = $row['section_type'] === 'exam_update' ? 'Exam' : 'Tutorial';
            $context .= "\n[$label Section: {$row['title']}]\n";
        }
        if ($row['res_title'] && $row['file_path']) {
            $abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($row['file_path'], '/');
            $ext = strtolower($row['file_type'] ?? '');
            if (in_array($ext, ['docx', 'doc'])) {
                $text = extract_docx($abs);
                if ($text) $context .= "  [Document: {$row['res_title']}]\n" . substr($text, 0, 2000) . "\n";
            } elseif ($ext === 'pdf' && file_exists($abs)) {
                $pdfText = extract_pdf_text_cached($abs);
                if ($pdfText !== '') {
                    $context .= "  [Document (PDF): {$row['res_title']}]\n" . substr($pdfText, 0, 2000) . "\n";
                } else {
                    $pdfDocs[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($abs))], 'title' => $row['res_title']];
                }
            }
        }
    }
}

// ── System prompt ─────────────────────────────────────────────────────────────
$prevQText = '';
if ($prevQuestions) {
    $prevQText = "\n\nPREVIOUSLY GENERATED QUESTIONS FOR THIS USER (do NOT repeat these):\n";
    foreach (array_slice($prevQuestions, 0, 20) as $i => $q) {
        $prevQText .= ($i + 1) . ". " . $q . "\n";
    }
}

$scopeDesc = $scope === 'topic'
    ? "Week {$topics[0]['week_number']}: {$topics[0]['title']}"
    : "the entire {$course['code']} course";

$imageCountNote = count($imagesB64) > 0
    ? "\n\nUPLOADED IMAGES: " . count($imagesB64) . " image(s) have been provided (indexed 0 to " . (count($imagesB64) - 1) . " in the order shown). These may be past question papers (for style matching) and/or diagrams. If any image contains a diagram that would make a good theory question (e.g. \"label this diagram\", \"explain the process shown\"), reference it using \"image_ref\": <index>. Only set image_ref when the image is genuinely a diagram/figure relevant to that specific question — leave it null otherwise."
    : '';

// ── Question type instruction ───────────────────────────────────────────────────
switch ($questionType) {
    case 'objective':
        $typeRule = 'All questions must be multiple-choice with exactly 4 options (A, B, C, D). Every question format = "objective" with no rubric needed (correct answer is the letter).';
        $typeDesc = 'Generate only objective (MCQ) questions — all must have exactly 4 options with one correct answer';
        break;
    case 'theory':
        $typeRule = 'All questions must be theory / short-answer questions without multiple-choice options. Every question needs a rubric (see MARKING MODEL below).';
        $typeDesc = 'Generate only theory / short-answer questions — no multiple-choice or true/false';
        break;
    case 'truefalse':
        $typeRule = 'All questions must be true/false with options {"A": "True", "B": "False"}. No rubric needed for these.';
        $typeDesc = 'Generate only true/false questions — each must have exactly options A=True, B=False';
        break;
    default: // hybrid
        $typeRule = 'Mix of MCQ, true/false (no rubric needed for these) and theory questions (rubric required — see MARKING MODEL below).';
        $typeDesc = 'Mix question types: roughly 40% MCQ, 40% theory, 20% true/false';
        break;
}

// ── Reasoning effort, split by question type (Task 21 fix #2) ────────────────
// 'medium' effort was made the default for ALL generation during Task 17 to
// fix real quality defects (undermarking, mark-flattening, rubric-grounding)
// — but those fixes are specifically about THEORY question rubrics/marking.
// An objective-only request (MCQ and/or true/false, no theory questions at
// all) never generates a rubric and doesn't need that same reasoning depth
// — confirmed live that 'medium' costs roughly 2.3x 'low' for identical
// output. 'theory' and 'hybrid' (which always includes theory questions,
// per $typeDesc above) keep 'medium' — the Task 17 fixes this depends on
// must not regress.
$reasoningEffort = in_array($questionType, ['objective', 'truefalse'], true) ? 'low' : 'medium';

// ── Scaled max_output_tokens (Task 22 Part A) ─────────────────────────────────
// A flat ceiling doesn't account for how much reasoning + structured JSON
// output a request actually needs — confirmed live (both here and in
// grading) that GPT-5-family reasoning models can burn the ENTIRE ceiling
// on invisible reasoning before emitting any visible text, and this gets
// MORE likely as a request gets larger/richer, not less: a 20-question
// theory-heavy quiz has to reason through ~20 rubrics (criteria grounded
// in each question's own wording, marks weighted by complexity) in ONE
// call, vs. a handful for a small quiz. Scale generously — reliability is
// explicitly prioritized over token cost/latency here, so this errs on the
// side of clearly-more-than-enough rather than a tight estimate. Theory
// questions get the heaviest per-question weight (a full rubric plus the
// reasoning to ground it costs far more than a plain MCQ); hybrid sits
// between the two since it's a mix of both.
$perQuestionTokens = match ($questionType) {
    'objective', 'truefalse' => 500,
    'theory'                 => 1300,
    default                  => 900, // hybrid
};
$genMaxTokens = 8000 + ($count * $perQuestionTokens);

// A flat 120s curl timeout can also genuinely run out for a large request
// BEFORE any response comes back at all (confirmed live: a count=20 hybrid
// request hit "Operation timed out after 120000 milliseconds with 0 bytes
// received") — a distinct problem from the reasoning-exhaustion signature
// Part B retries (that one gets a response, just an empty one; this one
// gets no response in time). Scale the wall-clock budget too, capped well
// under Apache's own connection Timeout (300s on this local setup — flag
// as a production-config caveat, unverified on AlwaysData) to avoid
// trading a clean JSON error for a raw connection reset.
$genTimeoutSecs = min(180, 60 + ($count * 6));

$outputFormat = <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "questions": [
    {
      "type": "mcq",
      "marks": 6,
      "format": "objective",
      "question": "Question text here?",
      "options": {"A": "...", "B": "...", "C": "...", "D": "..."},
      "correct": "A",
      "explanation": "Brief explanation of why A is correct",
      "rubric": null,
      "image_ref": null
    },
    {
      "type": "truefalse",
      "marks": 4,
      "format": "objective",
      "question": "Statement to evaluate — True or False?",
      "options": {"A": "True", "B": "False"},
      "correct": "A",
      "explanation": "Why this is true/false",
      "rubric": null,
      "image_ref": null
    },
    {
      "type": "short",
      "marks": 10,
      "format": "list_explain",
      "question": "List and explain 5 causes of network congestion.",
      "options": null,
      "correct": "Model answer summarizing all 5 causes and explanations",
      "explanation": "Grading is per item — see rubric",
      "rubric": {
        "expected_items": 5,
        "marks_per_list_item": 0.4,
        "marks_per_explain_item": 1.6,
        "item_key_points": [
          "Bandwidth limitation — insufficient network capacity for traffic volume",
          "Traffic bursts — sudden spikes overwhelming the network",
          "Poor routing — inefficient paths increasing load on certain links",
          "Hardware failure — faulty equipment reducing capacity",
          "Broadcast storms — excessive broadcast traffic flooding the network"
        ]
      },
      "image_ref": null
    },
    {
      "type": "short",
      "marks": 12,
      "format": "general",
      "question": "Explain the client-server model of network communication.",
      "options": null,
      "correct": "Model answer describing the client-server model comprehensively",
      "explanation": "Grading is per criterion — see rubric",
      "rubric": {
        "criteria": [
          {"description": "Correctly defines the client role", "marks": 3},
          {"description": "Correctly defines the server role", "marks": 3},
          {"description": "Explains the request-response communication pattern", "marks": 4},
          {"description": "Gives a relevant real-world example", "marks": 2}
        ]
      },
      "image_ref": null
    }
  ]
}
FMT;

$systemPrompt = <<<PROMPT
You are an expert university examiner for KWASU (Kwara State University) generating quiz questions for {$course['code']} — {$course['title']}.

COURSE CONTENT:
{$context}
{$prevQText}
{$imageCountNote}

YOUR TASK:
Generate exactly {$count} quiz questions covering {$scopeDesc}.

{$typeRule}

MARKING MODEL (very important):
- The quiz has a TOTAL of exactly 100 marks. You must distribute these 100 marks UNEVENLY across all {$count} questions, weighted by each question's actual complexity and depth.
- DO NOT default to an equal or near-equal split (e.g. do not give every question roughly 100/{$count} marks each just because that's the simplest allocation) — that is a real failure mode you must actively avoid, not a hypothetical one. Force yourself to actually compare each question's depth against the others: a one-fact MCQ (e.g. "which layer does X belong to?") should be noticeably lower than a multi-part theory question asking the student to list AND explain several distinct things. As a concrete guide: simple MCQ/true-false = roughly 3-7 marks; a theory question covering one concept = roughly 6-10 marks; a rich "list and explain N things" or multi-part theory question = roughly 12-20 marks, scaling with how much content it's actually asking for (more expected_items or more criteria = more marks, not the same marks as a 1-criterion question). All question "marks" values MUST sum to exactly 100, but the individual values must show real variance reflecting real differences in what each question demands — a quiz where every question is worth nearly the same amount is a sign you did this wrong even if the total is correct.
- For MCQ and True/False questions: format = "objective", rubric = null. Full marks awarded only for the exact correct option.
- For THEORY questions, choose one of two rubric formats:
  1. "list_explain" — use this when the question asks the student to list/name/state a specific NUMBER of items and explain each (e.g. "list and explain 5 causes of X", "state and explain 3 differences between Y and Z"). The rubric must include:
     - "expected_items": the exact number requested in the question
     - "marks_per_list_item": a SMALL mark for correctly naming/listing the item alone (roughly 20-25% of that item's total share)
     - "marks_per_explain_item": a LARGER mark for correctly explaining that item (roughly 75-80% of that item's total share)
     - "item_key_points": an array of the {$count} — no, of exactly "expected_items" strings, each describing the specific correct item and what a good explanation should cover, so a grader can check student answers against them
     - (marks_per_list_item + marks_per_explain_item) * expected_items MUST equal the question's total "marks"
  2. "general" — use this for any other theory question (definitions, single open-ended explanations, "why does X happen", "describe Y") that does NOT have a fixed number of listed items. The rubric must include:
     - "criteria": an array of exactly 3 to 4 objects, each with a "description" (a specific, checkable point a good answer should cover) and a "marks" value. The sum of all criteria marks MUST equal the question's total "marks".

RUBRIC CRITERIA MUST BE GROUNDED IN THE QUESTION AS WRITTEN (applies to both rubric formats above — critically important, a confirmed real bug when violated):
- Every "criteria" description (or "item_key_points" entry) must be DIRECTLY traceable to something the question text itself explicitly asks for. Do NOT invent additional implicit expectations the question never requested.
- Concretely: if the question is "Discuss the difference between CSMA/CD and CSMA/CA in terms of media access control, and how these mechanisms influence collision handling and performance" — valid criteria are things like "correctly explains CSMA/CD", "correctly explains CSMA/CA", "explains the collision-handling difference", "explains the performance implication" — because the question itself asks for exactly these things. An INVALID criterion for that same question would be "provides a relevant example scenario", because the question never asked for an example — adding that criterion anyway means a student who fully answers the actual question still loses marks over something they were never told to include. This exact mistake (adding an unrequested "give an example" criterion) has happened before — do not repeat it.
- Before finalizing each rubric, check every criterion/item against the question's own wording and remove any that aren't clearly asked for.

OTHER RULES:
1. Base questions STRICTLY on the provided course content — do not invent facts
2. {$typeDesc}
3. Match the style and difficulty of a university exam for this course
4. If past questions are visible in the documents or uploaded images, mimic their style and format
5. Do NOT repeat any previously generated questions listed above
6. Cover different topics/weeks — avoid clustering all questions on one area
7. For MCQs: always provide exactly 4 options (A, B, C, D) with only one correct answer
8. Make distractors (wrong options) plausible but clearly wrong on reflection
9. Double-check before responding: the sum of every question's "marks" value must equal exactly 100
10. Double-check before responding: the "marks" values must show real variance by complexity (see MARKING MODEL above) — if you find yourself about to submit a set where most/all questions have nearly the same mark value, that is wrong; go back and reweight them
11. Double-check every rubric's criteria/item_key_points against the question's own text — remove anything not clearly asked for (see RUBRIC CRITERIA MUST BE GROUNDED above)

{$outputFormat}
PROMPT;

// ── Build API messages ────────────────────────────────────────────────────────
$contentBlocks = $pdfDocs; // PDFs first

// Attach all uploaded images (past question papers / diagrams), in order
foreach ($imagesB64 as $idx => $img) {
    $mime = 'image/jpeg';
    $clean = $img;
    if (str_starts_with($clean, 'data:')) {
        preg_match('/data:([^;]+);base64,/', $clean, $m);
        $mime  = $m[1] ?? 'image/jpeg';
        $clean = preg_replace('/^data:[^;]+;base64,/', '', $clean);
    }

    // Resize/recompress before it goes to the vision API and before it's
    // stored in quiz_sessions.uploaded_images — these are typically
    // full-size phone photos of past-question papers, far larger than the
    // model needs. Falls back to the original bytes if GD can't process it.
    $decoded = base64_decode($clean, true);
    if ($decoded !== false) {
        $compressed = compress_image_bytes($decoded);
        if ($compressed !== null) {
            [$compressedBytes, $newExt] = $compressed;
            $clean = base64_encode($compressedBytes);
            $mime  = $newExt === 'png' ? 'image/png' : 'image/jpeg';
            $imagesB64[$idx] = 'data:' . $mime . ';base64,' . $clean;
        }
    }

    $contentBlocks[] = [
        'type'   => 'image',
        'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $clean],
    ];
    $contentBlocks[] = ['type' => 'text', 'text' => "[Image index {$idx} above] This may be a past question paper (study its style/format) and/or a diagram (usable via image_ref if relevant to a generated question)."];
}

$contentBlocks[] = ['type' => 'text', 'text' => "Generate {$count} quiz questions now. Respond with ONLY the JSON object."];

$messages = [[
    'role' => 'user',
    'content' => empty($pdfDocs) && empty($imagesB64)
        ? "Generate {$count} quiz questions now. Respond with ONLY the JSON object."
        : $contentBlocks
]];

// ── Structured Outputs JSON schema (OpenAI only — ignored under the
// Anthropic rollback path, which has no native equivalent and keeps relying
// on the prompt-instruction "respond with ONLY JSON" + markdown-fence
// stripping below, same as before this migration). Mirrors the field shapes
// already described in $outputFormat above.
$quizQuestionSchema = [
    'type' => 'object',
    'properties' => [
        'questions' => [
            'type'  => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type'     => ['type' => 'string', 'enum' => ['mcq', 'truefalse', 'short']],
                    'marks'    => ['type' => 'number'],
                    'format'   => ['type' => 'string', 'enum' => ['objective', 'list_explain', 'general']],
                    'question' => ['type' => 'string'],
                    'options'  => [
                        'anyOf' => [
                            [
                                'type' => 'object',
                                'properties' => [
                                    'A' => ['type' => 'string'],
                                    'B' => ['type' => 'string'],
                                    'C' => ['type' => ['string', 'null']],
                                    'D' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['A', 'B', 'C', 'D'],
                                'additionalProperties' => false,
                            ],
                            ['type' => 'null'],
                        ],
                    ],
                    'correct'     => ['type' => 'string'],
                    'explanation' => ['type' => 'string'],
                    'rubric' => [
                        'anyOf' => [
                            [
                                'type' => 'object',
                                'properties' => [
                                    'expected_items'         => ['type' => 'integer'],
                                    'marks_per_list_item'    => ['type' => 'number'],
                                    'marks_per_explain_item' => ['type' => 'number'],
                                    'item_key_points'        => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['expected_items', 'marks_per_list_item', 'marks_per_explain_item', 'item_key_points'],
                                'additionalProperties' => false,
                            ],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'criteria' => [
                                        'type'  => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'description' => ['type' => 'string'],
                                                'marks'       => ['type' => 'number'],
                                            ],
                                            'required' => ['description', 'marks'],
                                            'additionalProperties' => false,
                                        ],
                                    ],
                                ],
                                'required' => ['criteria'],
                                'additionalProperties' => false,
                            ],
                            ['type' => 'null'],
                        ],
                    ],
                    'image_ref' => ['type' => ['integer', 'null']],
                ],
                'required' => ['type', 'marks', 'format', 'question', 'options', 'correct', 'explanation', 'rubric', 'image_ref'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['questions'],
    'additionalProperties' => false,
];

// ── Call AI provider ──────────────────────────────────────────────────────────
// $reasoningEffort ('low' for objective-only, 'medium' otherwise — see
// above): genuinely weighing each question's complexity against the others
// (MARKING MODEL above) and checking every rubric criterion against the
// question's own wording (RUBRIC CRITERIA MUST BE GROUNDED above) are
// harder reasoning tasks than the pure "sum to 100" arithmetic 'low' alone
// handles fine — but only theory questions actually need that depth.
// $genMaxTokens/$genTimeoutSecs (Task 22 Part A, scaled by count/type — see
// above) replace the old flat 12000/120s. Note: automatic retry-on-
// reasoning-exhaustion also now lives inside call_openai_responses()
// itself (config.php, Task 22 Part B) — a transient failure of that exact
// kind is retried there transparently (up to 2 more attempts, each with
// its own $genTimeoutSecs timeout) before this try/catch ever sees an
// exception; what reaches here is only a failure that persisted across
// those retries too. See the raised set_time_limit() near the top of this
// file — a worst-case run of 3 full attempts needs real headroom to
// actually finish rather than being killed by PHP's own execution timer
// partway through a retry.
try {
    $rawText = call_ai_api($systemPrompt, $messages, $genMaxTokens, ['name' => 'quiz_questions', 'schema' => $quizQuestionSchema], $genTimeoutSecs, $reasoningEffort);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

// ── Parse questions from the AI's response ────────────────────────────────────
// Fence-stripping stays in place for the Anthropic rollback path (no native
// JSON mode there); harmless no-op under OpenAI's Structured Outputs, which
// never wraps output in markdown fences.
$rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
$rawText = preg_replace('/```\s*$/m', '', $rawText);
$parsed  = json_decode(trim($rawText), true);

if (!$parsed || !isset($parsed['questions']) || !is_array($parsed['questions'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not parse quiz questions from AI response.']);
    exit();
}

$questions = $parsed['questions'];

// ── Enforce the exact requested question count ────────────────────────────────
// The AI is instructed above to generate exactly $count, but that
// instruction isn't always followed reliably (same class of instruction-
// adherence gap as the mark-variance issue above) — treat $count as a hard
// constraint the response must satisfy, not a suggestion. Applies uniformly
// regardless of $questionType (objective/theory/hybrid) since this is a
// count problem, not a question-content problem — the underlying
// generation prompt/logic above is untouched.
if (count($questions) > $count) {
    $questions = array_slice($questions, 0, $count);
} elseif (count($questions) < $count) {
    $topUpAttempts = 0;
    while (count($questions) < $count && $topUpAttempts < 2) {
        $topUpAttempts++;
        $shortfall = $count - count($questions);
        $existingQuestionText = array_column($questions, 'question');
        $topUpList = '';
        foreach ($existingQuestionText as $i => $qt) {
            $topUpList .= ($i + 1) . ". {$qt}\n";
        }

        // Deliberately a separate, lighter-weight prompt rather than
        // re-running the full generation prompt above — this is a rare
        // top-up path (only reached when the primary call under-delivers),
        // so it trades a bit of context depth (no PDFs/images re-sent) for
        // simplicity and for not touching the already-working primary
        // generation logic at all.
        $topUpPrompt = "You are continuing to generate quiz questions for {$course['code']} — {$course['title']}, covering {$scopeDesc}. {$typeRule}\n\n"
            . "Generate EXACTLY {$shortfall} more quiz question(s), in the same JSON schema as before, covering topics DIFFERENT from — and not closely resembling — these already-generated questions:\n{$topUpList}\n"
            . "MARKING MODEL: weight each new question's \"marks\" relative to a typical question in a 100-mark, {$count}-question quiz (roughly " . round(100 / max(1, $count)) . " as a rough baseline, more for complex questions, less for simple ones) — the full set is renormalized to sum to 100 afterward, so exact precision here isn't critical, sensible relative weighting is.\n\n"
            . "Respond with ONLY valid JSON: {\"questions\": [...]} — exactly {$shortfall} item(s) in the array, same object shape as the schema.";

        try {
            // Same $reasoningEffort as the primary call above — an
            // objective-only top-up doesn't need 'medium' any more than the
            // primary objective-only generation did. Token budget scaled the
            // same way as the primary call (Task 22 Part A), by $shortfall
            // instead of $count since that's all this call is generating.
            $topUpMaxTokens   = 4000 + ($shortfall * $perQuestionTokens);
            $topUpTimeoutSecs = min(150, 60 + ($shortfall * 8));
            $topUpRaw = call_ai_api($topUpPrompt, [['role' => 'user', 'content' => "Generate {$shortfall} quiz question(s) now. Respond with ONLY the JSON object."]], $topUpMaxTokens, ['name' => 'quiz_questions', 'schema' => $quizQuestionSchema], $topUpTimeoutSecs, $reasoningEffort);
            $topUpRaw = preg_replace('/^```(?:json)?\s*/m', '', $topUpRaw);
            $topUpRaw = preg_replace('/```\s*$/m', '', $topUpRaw);
            $topUpParsed = json_decode(trim($topUpRaw), true);
            if ($topUpParsed && isset($topUpParsed['questions']) && is_array($topUpParsed['questions'])) {
                foreach ($topUpParsed['questions'] as $tq) {
                    if (count($questions) >= $count) break;
                    $questions[] = $tq;
                }
            }
        } catch (\Throwable $e) {
            // Top-up call failed — loop retries once more (if attempts
            // remain), or falls through below with whatever was generated.
        }
    }
    // If still short after retries (a persistently uncooperative model),
    // this is the one case an exact count can't be guaranteed without
    // fabricating a question outright — return what was actually generated
    // rather than block the whole quiz. Every downstream count reference
    // (quiz_sessions.total, the JSON response's "total" field) is always
    // derived from count($questions) below, so nothing downstream goes
    // inconsistent — it may just be below the requested count in this rare
    // fallback case.
}

// Structured Outputs' strict mode requires every optional field to be
// present (as null) rather than omitted — e.g. a true/false question's
// options come back as {"A":"True","B":"False","C":null,"D":null}. Strip
// null-valued keys so quiz.php's Object.entries(q.options) rendering loop
// (which has no null-check) doesn't render phantom empty option buttons.
foreach ($questions as &$q) {
    if (isset($q['options']) && is_array($q['options'])) {
        $q['options'] = array_filter($q['options'], fn($v) => $v !== null);
    }
}
unset($q);

// Confirmed via live testing: OpenAI's Structured Outputs can compute a
// rubric's internal numbers correctly (marks_per_list_item/explain_item *
// expected_items, or criteria marks) while leaving the *sibling* top-level
// "marks" field on the same question at 0 — a JSON schema can't cross-
// validate that two sibling fields agree, so strict mode doesn't catch
// this. Re-derive "marks" from the rubric's own numbers whenever they
// disagree, since the rubric values are what's actually reliable and this
// question's "marks" directly controls its share of the quiz's 100-point
// total in the normalization pass below.
foreach ($questions as &$q) {
    $rubric = $q['rubric'] ?? null;
    if (!is_array($rubric)) continue;

    if (isset($rubric['item_key_points'])) {
        $derived = ((float)($rubric['marks_per_list_item'] ?? 0) + (float)($rubric['marks_per_explain_item'] ?? 0))
            * (int)($rubric['expected_items'] ?? 0);
    } elseif (isset($rubric['criteria']) && is_array($rubric['criteria'])) {
        $derived = array_sum(array_column($rubric['criteria'], 'marks'));
    } else {
        continue;
    }

    if ($derived > 0 && abs($derived - (float)($q['marks'] ?? 0)) > 0.01) {
        $q['marks'] = $derived;
    }
}
unset($q);

// ── Normalize marks so they always sum to exactly 100 ─────────────────────────
// The AI is instructed to do this, but we defensively re-normalize in case of
// rounding drift, so the quiz total is always exactly 100 regardless.
$rawMarksSum = 0;
foreach ($questions as $q) {
    $rawMarksSum += (float)($q['marks'] ?? (100 / max(1, count($questions))));
}
if ($rawMarksSum <= 0) $rawMarksSum = count($questions);

foreach ($questions as $i => &$q) {
    $orig = (float)($q['marks'] ?? (100 / max(1, count($questions))));
    $q['marks'] = round(($orig / $rawMarksSum) * 100, 2);
}
unset($q);
// Fix any rounding remainder onto the last question so the sum is exactly 100.00
$sumCheck = array_sum(array_column($questions, 'marks'));
$diff = round(100 - $sumCheck, 2);
if (abs($diff) >= 0.01 && count($questions) > 0) {
    $lastIdx = count($questions) - 1;
    $questions[$lastIdx]['marks'] = round($questions[$lastIdx]['marks'] + $diff, 2);
}

// ── Save session and questions to database ────────────────────────────────────
$sessStmt = db()->prepare("INSERT INTO quiz_sessions (user_id, course_id, topic_id, scope, status, total, uploaded_images, total_marks) VALUES (?,?,?,?,'active',?,?,100.00)");
$sessStmt->execute([
    $user['id'], $courseId, $topicId, $scope, count($questions),
    !empty($imagesB64) ? json_encode($imagesB64) : null,
]);
$sessionId = (int)db()->lastInsertId();

$qStmt = db()->prepare("INSERT INTO quiz_questions (session_id, question_no, type, marks, format, question, options, rubric, image_ref, correct, explanation) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
foreach ($questions as $i => $q) {
    $qStmt->execute([
        $sessionId,
        $i + 1,
        $q['type']        ?? 'short',
        $q['marks']       ?? (100 / max(1, count($questions))),
        $q['format']      ?? 'general',
        $q['question']    ?? '',
        isset($q['options']) ? json_encode($q['options']) : null,
        isset($q['rubric']) && $q['rubric'] ? json_encode($q['rubric']) : null,
        isset($q['image_ref']) ? $q['image_ref'] : null,
        $q['correct']     ?? '',
        $q['explanation'] ?? '',
    ]);
}

echo json_encode([
    'session_id'      => $sessionId,
    'questions'       => $questions,
    'total'           => count($questions),
    'total_marks'     => 100,
    'uploaded_images' => $imagesB64, // returned so the frontend can display image_ref'd images without re-uploading
]);
