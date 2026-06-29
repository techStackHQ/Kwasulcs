<?php

/**
 * ai_quiz.php — Generates quiz questions using Anthropic API
 *
 * POST JSON:
 * { "course_id": 3, "topic_id": null, "scope": "course", "count": 10, "image_b64": null }
 */
require_once __DIR__ . '/config.php';
require_login();
set_time_limit(120);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId = (int)($body['course_id'] ?? 0);
$topicId  = isset($body['topic_id']) && $body['topic_id'] ? (int)$body['topic_id'] : null;
$scope    = $body['scope'] === 'topic' && $topicId ? 'topic' : 'course';
$count    = max(3, min(20, (int)($body['count'] ?? 10)));
$imageB64     = $body['image_b64'] ?? null; // base64 image from student (past question photo)
$questionType = $body['question_type'] ?? 'hybrid';
$user         = current_user();

if ($courseId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing course_id']);
    exit();
}

// ── Ensure quiz tables exist ──────────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS quiz_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id INT NOT NULL,
        topic_id INT NULL, scope ENUM('topic','course') NOT NULL DEFAULT 'course',
        status ENUM('pending','active','completed') NOT NULL DEFAULT 'pending',
        score INT NULL, total INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at TIMESTAMP NULL,
        INDEX idx_qs (user_id, course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS quiz_questions (
        id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, question_no INT NOT NULL,
        type ENUM('mcq','short','truefalse') NOT NULL, question TEXT NOT NULL,
        options JSON NULL, correct TEXT NOT NULL, explanation TEXT NULL,
        user_answer TEXT NULL, is_correct TINYINT(1) NULL,
        INDEX idx_qq (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) { /* tables may exist */
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
                'source'  => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode(file_get_contents($abs))
                ],
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
        if (!empty($v['original_url'])) {
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

// ── Question type instruction ───────────────────────────────────────────────────
switch ($questionType) {
    case 'objective':
        $typeRule     = 'All questions must be multiple-choice with exactly 4 options (A, B, C, D).';
        $typeDesc     = 'Generate only objective (MCQ) questions — all must have exactly 4 options with one correct answer';
        $outputFormat = <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "questions": [
    {
      "type": "mcq",
      "question": "Question text here?",
      "options": {"A": "...", "B": "...", "C": "...", "D": "..."},
      "correct": "A",
      "explanation": "Brief explanation of why A is correct"
    }
  ]
}
FMT;
        break;
    case 'theory':
        $typeRule     = 'All questions must be short-answer / theory questions without multiple-choice options.';
        $typeDesc     = 'Generate only theory / short-answer questions — no multiple-choice or true/false';
        $outputFormat = <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "questions": [
    {
      "type": "short",
      "question": "Question text here?",
      "options": null,
      "correct": "Model answer here",
      "explanation": "Key points expected in the answer"
    }
  ]
}
FMT;
        break;
    case 'truefalse':
        $typeRule     = 'All questions must be true/false with options {"A": "True", "B": "False"}.';
        $typeDesc     = 'Generate only true/false questions — each must have exactly options A=True, B=False';
        $outputFormat = <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "questions": [
    {
      "type": "truefalse",
      "question": "Statement to evaluate — True or False?",
      "options": {"A": "True", "B": "False"},
      "correct": "A",
      "explanation": "Why this is true/false"
    }
  ]
}
FMT;
        break;
    default: // hybrid
        $typeRule     = '';
        $typeDesc     = 'Mix question types: roughly 50% MCQ, 30% short answer, 20% true/false';
        $outputFormat = <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "questions": [
    {
      "type": "mcq",
      "question": "Question text here?",
      "options": {"A": "...", "B": "...", "C": "...", "D": "..."},
      "correct": "A",
      "explanation": "Brief explanation of why A is correct"
    },
    {
      "type": "short",
      "question": "Question text here?",
      "options": null,
      "correct": "Model answer here",
      "explanation": "Key points expected in the answer"
    },
    {
      "type": "truefalse",
      "question": "Statement to evaluate — True or False?",
      "options": {"A": "True", "B": "False"},
      "correct": "A",
      "explanation": "Why this is true/false"
    }
  ]
}
FMT;
        break;
}

$systemPrompt = <<<PROMPT
You are an expert university examiner for KWASU (Kwara State University) generating quiz questions for {$course['code']} — {$course['title']}.

COURSE CONTENT:
{$context}
{$prevQText}

YOUR TASK:
Generate exactly {$count} quiz questions covering {$scopeDesc}.

{$typeRule}
RULES:
1. Base questions STRICTLY on the provided course content — do not invent facts
2. {$typeDesc}
3. Match the style and difficulty of a university exam for this course
4. If past questions are visible in the documents, mimic their style and format
5. Do NOT repeat any previously generated questions listed above
6. Cover different topics/weeks — avoid clustering all questions on one area
7. For MCQs: always provide exactly 4 options (A, B, C, D) with only one correct answer
8. Make distractors (wrong options) plausible but clearly wrong on reflection

{$outputFormat}
PROMPT;

// ── Build API messages ────────────────────────────────────────────────────────
$contentBlocks = $pdfDocs; // PDFs first

// If student uploaded an image (past question paper photo)
if ($imageB64) {
    // Detect image type from base64 header
    $mime = 'image/jpeg';
    if (str_starts_with($imageB64, 'data:')) {
        preg_match('/data:([^;]+);base64,/', $imageB64, $m);
        $mime     = $m[1] ?? 'image/jpeg';
        $imageB64 = preg_replace('/^data:[^;]+;base64,/', '', $imageB64);
    }
    $contentBlocks[] = [
        'type'   => 'image',
        'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $imageB64],
    ];
    $contentBlocks[] = ['type' => 'text', 'text' => "The image above shows a past question paper for this course. Study its style, difficulty level, and question format carefully — generate questions in the same style."];
}

$contentBlocks[] = ['type' => 'text', 'text' => "Generate {$count} quiz questions now. Respond with ONLY the JSON object."];

$messages = [[
    'role' => 'user',
    'content' => empty($pdfDocs) && !$imageB64
        ? "Generate {$count} quiz questions now. Respond with ONLY the JSON object."
        : $contentBlocks
]];

// ── Call Anthropic ────────────────────────────────────────────────────────────
$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 4096,
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
    CURLOPT_TIMEOUT => 90,
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
// Strip any markdown code fences Claude might add despite instructions
$rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
$rawText = preg_replace('/```\s*$/m', '', $rawText);
$parsed  = json_decode(trim($rawText), true);

if (!$parsed || !isset($parsed['questions']) || !is_array($parsed['questions'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not parse quiz questions from AI response.']);
    exit();
}

$questions = $parsed['questions'];

// ── Save session and questions to database ────────────────────────────────────
$sessStmt = db()->prepare("INSERT INTO quiz_sessions (user_id, course_id, topic_id, scope, status, total) VALUES (?,?,?,?,'active',?)");
$sessStmt->execute([$user['id'], $courseId, $topicId, $scope, count($questions)]);
$sessionId = (int)db()->lastInsertId();

$qStmt = db()->prepare("INSERT INTO quiz_questions (session_id, question_no, type, question, options, correct, explanation) VALUES (?,?,?,?,?,?,?)");
foreach ($questions as $i => $q) {
    $qStmt->execute([
        $sessionId,
        $i + 1,
        $q['type']        ?? 'short',
        $q['question']    ?? '',
        isset($q['options']) ? json_encode($q['options']) : null,
        $q['correct']     ?? '',
        $q['explanation'] ?? '',
    ]);
}

echo json_encode([
    'session_id' => $sessionId,
    'questions'  => $questions,
    'total'      => count($questions),
]);
