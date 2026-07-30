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
set_time_limit(150);
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

$context .= "COURSE CONTENT:\n";
foreach ($topics as $topic) {
    $context .= "\nWeek {$topic['week_number']}: {$topic['title']}\n";

    $docs = db()->prepare('SELECT * FROM documents WHERE topic_id=?');
    $docs->execute([$topic['id']]);
    foreach ($docs->fetchAll() as $doc) {
        $abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($doc['file_path'], '/');
        $ext = strtolower($doc['file_type'] ?? pathinfo($abs, PATHINFO_EXTENSION));

        if (in_array($ext, ['docx', 'doc'])) {
            $text = extract_docx($abs);
            if ($text) {
                $context .= "  [Lecture Notes: {$doc['title']}]\n";
                $context .= substr($text, 0, 4000) . "\n";
            }
        } elseif ($ext === 'pdf' && file_exists($abs)) {
            $pdfDocs[] = [
                'type'    => 'document',
                'source'  => ['type' => 'base64', 'media_type' => 'application/pdf',
                              'data' => base64_encode(file_get_contents($abs))],
                'title'   => "Week {$topic['week_number']}: {$doc['title']}",
                'context' => "Course document for {$course['code']}",
            ];
            $context .= "  [PDF: {$doc['title']} — content provided separately]\n";
        } elseif ($ext === 'txt' && file_exists($abs)) {
            $context .= "  [Document: {$doc['title']}]\n" . substr(file_get_contents($abs), 0, 3000) . "\n";
        }
    }

    $vids = db()->prepare('SELECT title, original_url FROM videos WHERE topic_id=?');
    $vids->execute([$topic['id']]);
    foreach ($vids->fetchAll() as $v) {
        $context .= "  [Video: {$v['title']}]\n";
        if (defined('YOUTUBE_API_KEY') && YOUTUBE_API_KEY !== '' && !empty($v['original_url'])) {
            $videoId = youtube_id($v['original_url']);
            if ($videoId) {
                $transcript = fetch_youtube_transcript($videoId);
                if ($transcript) {
                    $context .= "  [Video Transcript: {$v['title']}]\n";
                    $context .= substr($transcript, 0, 3000) . (strlen($transcript) > 3000 ? "\n...(continues)" : '') . "\n";
                }
            }
        }
    }
}

// Tutorial/exam sections
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
            $pdfDocs[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($abs))], 'title' => $row['res_title']];
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
- The quiz has a TOTAL of exactly 100 marks. You must distribute these 100 marks across all {$count} questions.
- Do NOT split marks equally — weight each question by its actual complexity and depth. A simple MCQ might be worth 4-6 marks; a rich "list and explain 5 things" theory question might be worth 15-20 marks. All question "marks" values MUST sum to exactly 100.
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

// ── Call Anthropic ────────────────────────────────────────────────────────────
$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 6000,
    'system'     => $systemPrompt,
    'messages'   => $messages,
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: pdfs-2024-09-25',
    ],
    CURLOPT_TIMEOUT => 120,
]);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => $curlError]);
    exit();
}

$data = json_decode($response, true);
if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
    http_response_code(500);
    echo json_encode(['error' => $data['error']['message'] ?? 'API error']);
    exit();
}

// ── Parse questions from Claude's response ────────────────────────────────────
$rawText = $data['content'][0]['text'];
$rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
$rawText = preg_replace('/```\s*$/m', '', $rawText);
$parsed  = json_decode(trim($rawText), true);

if (!$parsed || !isset($parsed['questions']) || !is_array($parsed['questions'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not parse quiz questions from AI response.']);
    exit();
}

$questions = $parsed['questions'];

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
