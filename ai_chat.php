<?php
// Temporary error catching — remove after debugging
ini_set('display_errors', 0);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line']]);
    }
});

/**
 * ai_chat.php — Course Q&A AI backend
 *
 * Actions (POST JSON):
 *   { "action": "send",       "session_id": N|null, "message": "...", "course_id": N }
 *   { "action": "new_session","course_id": N }
 *   { "action": "get_sessions","course_id": N }
 *   { "action": "get_messages","session_id": N }
 *   { "action": "delete_session","session_id": N }
 */
require_once __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$action   = (string) ($body['action'] ?? 'send');
$user     = current_user();

// ── Ensure tables exist ───────────────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS ai_chat_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        course_id INT NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT 'New Chat',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ais (user_id, course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS ai_chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        role ENUM('user','assistant') NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_aim (session_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) { /* tables may already exist */
}

// One-time, idempotent migration: course_id must be nullable to support a
// general (non-course) AI assistant session pinned at the top of chat.php.
try {
    db()->exec("ALTER TABLE ai_chat_sessions MODIFY course_id INT NULL");
} catch (\Throwable $e) { /* already nullable, or column locked by another request */
}

// ── Action: get_sessions ──────────────────────────────────────────────────────
if ($action === 'get_sessions') {
    $courseId = (int)($body['course_id'] ?? 0);
    if ($courseId > 0) {
        $stmt = db()->prepare("
            SELECT s.id, s.title, s.created_at, s.updated_at,
                   COUNT(m.id) AS message_count
            FROM ai_chat_sessions s
            LEFT JOIN ai_chat_messages m ON m.session_id = s.id
            WHERE s.user_id = ? AND s.course_id = ?
            GROUP BY s.id
            ORDER BY COALESCE(s.updated_at, s.created_at) DESC
            LIMIT 20
        ");
        $stmt->execute([$user['id'], $courseId]);
    } else {
        $stmt = db()->prepare("
            SELECT s.id, s.title, s.created_at, s.updated_at,
                   COUNT(m.id) AS message_count
            FROM ai_chat_sessions s
            LEFT JOIN ai_chat_messages m ON m.session_id = s.id
            WHERE s.user_id = ? AND s.course_id IS NULL
            GROUP BY s.id
            ORDER BY COALESCE(s.updated_at, s.created_at) DESC
            LIMIT 20
        ");
        $stmt->execute([$user['id']]);
    }
    echo json_encode(['sessions' => $stmt->fetchAll()]);
    exit();
}

// ── Action: new_session ───────────────────────────────────────────────────────
if ($action === 'new_session') {
    $courseId = (int)($body['course_id'] ?? 0);
    $stmt = db()->prepare("INSERT INTO ai_chat_sessions (user_id, course_id, title) VALUES (?,?,'New Chat')");
    $stmt->execute([$user['id'], $courseId > 0 ? $courseId : null]);
    echo json_encode(['session_id' => (int)db()->lastInsertId()]);
    exit();
}

// ── Action: get_messages ──────────────────────────────────────────────────────
if ($action === 'get_messages') {
    $sessionId = (int)($body['session_id'] ?? 0);
    // Verify ownership
    $check = db()->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $check->execute([$sessionId, $user['id']]);
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
    $stmt = db()->prepare("SELECT role, content, created_at FROM ai_chat_messages WHERE session_id = ? ORDER BY created_at ASC");
    $stmt->execute([$sessionId]);
    echo json_encode(['messages' => $stmt->fetchAll()]);
    exit();
}

// ── Action: delete_session ────────────────────────────────────────────────────
if ($action === 'delete_session') {
    $sessionId = (int)($body['session_id'] ?? 0);
    $check = db()->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $check->execute([$sessionId, $user['id']]);
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
    db()->prepare("DELETE FROM ai_chat_sessions WHERE id = ?")->execute([$sessionId]);
    echo json_encode(['ok' => true]);
    exit();
}

// ── Action: rename_session ────────────────────────────────────────────────────
if ($action === 'rename_session') {
    $sessionId = (int)($body['session_id'] ?? 0);
    $newTitle  = trim((string)($body['title'] ?? ''));
    if ($newTitle === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Title cannot be empty']);
        exit();
    }
    $check = db()->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $check->execute([$sessionId, $user['id']]);
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
    $title = mb_strlen($newTitle) > 100 ? mb_substr($newTitle, 0, 97) . '…' : $newTitle;
    db()->prepare("UPDATE ai_chat_sessions SET title = ? WHERE id = ?")->execute([$title, $sessionId]);
    echo json_encode(['ok' => true, 'title' => $title]);
    exit();
}

// ── Action: send ──────────────────────────────────────────────────────────────
// Extend execution time — PDF processing + API call can take longer than default
set_time_limit(120);

$message     = trim((string)($body['message'] ?? ''));
$courseId    = (int)($body['course_id'] ?? 0);
$sessionId   = isset($body['session_id']) && $body['session_id'] ? (int)$body['session_id'] : null;
$attachments = $body['attachments'] ?? []; // [{name, type, b64, isImage}]

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing message']);
    exit();
}

// Create session if none provided
if (!$sessionId) {
    $stmt = db()->prepare("INSERT INTO ai_chat_sessions (user_id, course_id, title) VALUES (?,?,'New Chat')");
    $stmt->execute([$user['id'], $courseId > 0 ? $courseId : null]);
    $sessionId = (int)db()->lastInsertId();
}

// Verify session ownership
$check = db()->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
$check->execute([$sessionId, $user['id']]);
if (!$check->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// Load existing conversation history for multi-turn context
$histStmt = db()->prepare("SELECT role, content FROM ai_chat_messages WHERE session_id = ? ORDER BY created_at ASC LIMIT 20");
$histStmt->execute([$sessionId]);
$history = $histStmt->fetchAll();

// Save the user's message
db()->prepare("INSERT INTO ai_chat_messages (session_id, role, content) VALUES (?, 'user', ?)")
    ->execute([$sessionId, $message]);

// Auto-title the session from the first user message (truncated to 60 chars)
if (empty($history)) {
    $title = mb_strlen($message) > 60 ? mb_substr($message, 0, 57) . '…' : $message;
    db()->prepare("UPDATE ai_chat_sessions SET title = ? WHERE id = ?")->execute([$title, $sessionId]);
}

// ── Load course context (skipped entirely for the general assistant, where
//    $courseId is 0 — no course to scope answers to) ─────────────────────────
$course = null;
if ($courseId > 0) {
    $courseStmt = db()->prepare('SELECT c.*, u.full_name AS lecturer_name FROM courses c JOIN users u ON u.id = c.lecturer_id WHERE c.id = ?');
    $courseStmt->execute([$courseId]);
    $course = $courseStmt->fetch();
    if (!$course) {
        http_response_code(404);
        echo json_encode(['error' => 'Course not found']);
        exit();
    }
}

// ── Document text extraction helpers ─────────────────────────────────────────
function extract_docx_text(string $absPath): string
{
    if (!file_exists($absPath)) return '';
    $zip = new ZipArchive();
    if ($zip->open($absPath) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $xml  = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return preg_replace('/\n{3,}/', "\n\n", trim($text));
}

// ── Decide whether to load full PDF content ──────────────────────────────────
// Only send actual PDF bytes when the question is clearly about reading documents.
// This prevents 2MB+ payloads on every simple question, which caused timeouts.
$docKeywords = [
    'document',
    'pdf',
    'file',
    'read',
    'notes',
    'lecture note',
    'material',
    'handout',
    'content',
    'what does',
    'according to',
    'summarize',
    'summary',
    'explain from',
    'from the'
];
$msgLower = strtolower($message);
$loadPdfs = false;
foreach ($docKeywords as $kw) {
    if (str_contains($msgLower, $kw)) {
        $loadPdfs = true;
        break;
    }
}

// ── Topic relevance pre-filter (retrieval step) ───────────────────────────────
// Rebuilding the ENTIRE course's document text on every single message — even
// a "hi" — was the single biggest source of wasted tokens. Instead: figure out
// which topic(s) the question is actually about using cheap, free keyword
// matching in PHP first, and only pay for full document extraction / PDF
// loading / transcript fetching on those matched topics. Unmatched topics
// still get listed by title so the AI knows they exist, just without their
// full content — near-zero extra cost.
$STOPWORDS = ['a','an','and','are','as','at','be','by','for','from','has','he','in','is','it',
    'its','of','on','that','the','to','was','were','will','with','this','what','when','where',
    'who','how','why','does','do','did','can','could','would','should','please','i','you','your',
    'my','me','we','our','us','they','them','their','about','into','than','then','there','here',
    'which','or','but','not','no','yes','ok','okay','hello','hi','hey','thanks','thank','course',
    'week','topic'];

function extract_keywords(string $text): array
{
    global $STOPWORDS;
    $text  = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text));
    $words = array_filter(explode(' ', $text), fn($w) => strlen($w) > 2 && !in_array($w, $STOPWORDS));
    return array_values(array_unique($words));
}

function score_relevance(array $questionKeywords, string $candidateText): int
{
    if (empty($questionKeywords)) return 0;
    $candidateLower = strtolower($candidateText);
    $score = 0;
    foreach ($questionKeywords as $kw) {
        if (str_contains($candidateLower, $kw)) $score++;
    }
    return $score;
}

// Broad/course-wide questions should see every topic's title even without a
// keyword match (e.g. "what topics does this course cover?").
$broadKeywords = ['course cover', 'all topics', 'whole course', 'entire course', 'every week',
    'course structure', 'course overview', 'what topics', 'list the topics', 'course outline'];
$isBroadQuestion = false;
foreach ($broadKeywords as $kw) {
    if (str_contains($msgLower, $kw)) { $isBroadQuestion = true; break; }
}

$questionKeywords = extract_keywords($message);

// ── Build course context (empty for the general assistant) ──────────────────
$context = '';
if ($course) {
    $context .= "COURSE: {$course['code']} — {$course['title']}\n";
    $context .= "Lecturer: {$course['lecturer_name']}\n";
    $context .= "Semester: " . ucfirst($course['semester']) . "\n\n";
}

$pdfDocuments = [];

$topics = [];
if ($course) {
    $topicsStmt = db()->prepare('SELECT * FROM topics WHERE course_id = ? ORDER BY week_number ASC');
    $topicsStmt->execute([$courseId]);
    $topics = $topicsStmt->fetchAll();
}

// ── Pass 1: lightweight metadata only (titles) — cheap, always loaded ────────
// Also fetch each topic's document/video titles here (titles only, no content)
// so we can score relevance without paying for extraction yet.
$topicMeta = [];
foreach ($topics as $topic) {
    $docs = db()->prepare('SELECT * FROM documents WHERE topic_id = ?');
    $docs->execute([$topic['id']]);
    $docRows = $docs->fetchAll();

    $vids = db()->prepare('SELECT title, original_url FROM videos WHERE topic_id = ?');
    $vids->execute([$topic['id']]);
    $vidRows = $vids->fetchAll();

    $titleBlob = $topic['title'] . ' ' . implode(' ', array_column($docRows, 'title')) . ' ' . implode(' ', array_column($vidRows, 'title'));
    $score = score_relevance($questionKeywords, $titleBlob);

    $topicMeta[] = [
        'topic' => $topic, 'docs' => $docRows, 'vids' => $vidRows, 'score' => $score,
    ];
}

// ── Decide which topics get FULL extraction ───────────────────────────────────
// - Any topic with a keyword match (score > 0) is relevant -> full extraction.
// - If nothing matched at all, fall back to the top 2 topics by week order
//   recency isn't meaningful here, so just avoid extracting everything blind;
//   broad questions get metadata-only for all topics instead (handled below).
$maxScore = max(array_column($topicMeta, 'score') ?: [0]);
$relevantTopicIds = [];
if ($maxScore > 0) {
    foreach ($topicMeta as $tm) {
        if ($tm['score'] > 0) $relevantTopicIds[] = $tm['topic']['id'];
    }
} elseif (!$isBroadQuestion && count($topicMeta) > 0) {
    // No keyword match and not an explicit broad question — likely a short/
    // generic message ("hi", "thanks"). Don't extract anything heavy; the AI
    // still sees every topic's title via the metadata line below.
    $relevantTopicIds = [];
}

if ($topics) {
    $context .= "WEEKLY TOPICS:\n";
    foreach ($topicMeta as $tm) {
        $topic   = $tm['topic'];
        $isFull  = in_array($topic['id'], $relevantTopicIds, true);
        $context .= "\nWeek {$topic['week_number']}: {$topic['title']}\n";

        if (!$isFull) {
            // Metadata-only: just list what exists, no extraction cost at all.
            foreach ($tm['docs'] as $doc) {
                $context .= "  [Document available: {$doc['title']}]\n";
            }
            foreach ($tm['vids'] as $vid) {
                $context .= "  [Video available: {$vid['title']}]\n";
            }
            continue;
        }

        // Relevant topic — pay for full extraction.
        foreach ($tm['docs'] as $doc) {
            $absPath = PRIVATE_UPLOAD_ROOT . '/' . ltrim($doc['file_path'], '/');
            $ext     = strtolower($doc['file_type'] ?? pathinfo($absPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['docx', 'doc'])) {
                $text = extract_docx_text($absPath);
                if ($text) {
                    $context .= "  [Document: {$doc['title']}]\n" . substr($text, 0, 3000) . "\n";
                }
            } elseif ($ext === 'txt' && file_exists($absPath)) {
                $context .= "  [Document: {$doc['title']}]\n" . substr(file_get_contents($absPath), 0, 3000) . "\n";
            } elseif ($ext === 'pdf' && file_exists($absPath)) {
                if ($loadPdfs) {
                    $pdfDocuments[] = [
                        'type'   => 'document',
                        'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($absPath))],
                        'title'  => "Week {$topic['week_number']}: {$doc['title']}",
                    ];
                    $context .= "  [PDF: {$doc['title']} — full content provided]\n";
                } else {
                    $context .= "  [PDF: {$doc['title']} — available on request]\n";
                }
            } else {
                $context .= "  [Document: {$doc['title']} ({$ext})]\n";
            }
        }
        foreach ($tm['vids'] as $vid) {
            $context .= "  [Video: {$vid['title']}]\n";
            if (!empty($vid['original_url'])) {
                $videoId = youtube_id($vid['original_url']);
                if ($videoId) {
                    $transcript = fetch_youtube_transcript($videoId);
                    if ($transcript) {
                        $context .= "  [Video Transcript: {$vid['title']}]\n";
                        // Limit to 3000 chars to stay within token budget
                        $context .= substr($transcript, 0, 3000) . (strlen($transcript) > 3000 ? "\n...(transcript continues)" : '') . "\n";
                    }
                }
            }
        }
    }
}

$sections = [];
if ($course) {
    $sectionsStmt = db()->prepare('SELECT cs.*, sr.title AS res_title, sr.resource_type, sr.file_path, sr.file_type FROM course_sections cs LEFT JOIN section_resources sr ON sr.section_id = cs.id WHERE cs.course_id = ? ORDER BY cs.section_type, cs.id');
    $sectionsStmt->execute([$courseId]);
    $sections = $sectionsStmt->fetchAll();
}
if ($sections) {
    $context .= "\nTUTORIAL AND EXAM SECTIONS:\n";
    $seen = [];
    foreach ($sections as $row) {
        $label = $row['section_type'] === 'tutorial_update' ? 'Tutorial Update' : 'Exam Update';
        if (!isset($seen[$row['id']])) {
            $seen[$row['id']] = true;
            $context .= "\n{$label}: {$row['title']}\n";
        }
        if ($row['res_title'] && $row['resource_type'] === 'document' && $row['file_path']) {
            $absPath = PRIVATE_UPLOAD_ROOT . '/' . ltrim($row['file_path'], '/');
            $ext = strtolower($row['file_type'] ?? pathinfo($absPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['docx', 'doc'])) {
                $text = extract_docx_text($absPath);
                if ($text) {
                    $context .= "  [Document: {$row['res_title']}]\n" . substr($text, 0, 2000) . "\n";
                }
            } elseif ($ext === 'pdf' && file_exists($absPath)) {
                if ($loadPdfs) {
                    $pdfDocuments[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($absPath))], 'title' => "{$label}: {$row['res_title']}"];
                    $context .= "  [PDF: {$row['res_title']} — full content provided]\n";
                } else {
                    $context .= "  [PDF: {$row['res_title']} — available on request]\n";
                }
            }
        }
    }
}

// ── Build messages for API (include conversation history for multi-turn) ──────
$systemPrompt = $course
    ? "You are an AI study assistant for the KWASU Lecture Capture System (LCS) at Kwara State University.\n\nYou are helping students with the following course:\n\n{$context}\n\nYour role:\n- Answer questions about the actual course content — you have access to the real documents above\n- If the student attaches an image or document, analyse it carefully and answer in relation to the course\n- Explain concepts, summarize topics, and help students understand the material\n- Reference specific weeks or documents when relevant\n- Be encouraging, clear, and appropriately detailed\n- Stay focused on this course; politely decline unrelated questions\n\nStudent: {$user['full_name']} ({$user['role']})"
    : "You are a general AI study assistant for KWASU Lecture Capture System (LCS) students at Kwara State University.\n\nYou are not scoped to any single course — help with general study questions, explain concepts, and if the student attaches an image or document, analyse it and answer helpfully.\nBe encouraging, clear, and appropriately detailed.\n\nStudent: {$user['full_name']} ({$user['role']})";

// Build messages array including history
$apiMessages = [];
foreach ($history as $h) {
    $apiMessages[] = ['role' => $h['role'], 'content' => $h['content']];
}

// ── Process user-attached files ───────────────────────────────────────────────
$userContentBlocks = $pdfDocuments; // course PDFs first

foreach ($attachments as $att) {
    $b64  = preg_replace('/^data:[^;]+;base64,/', '', $att['b64'] ?? '');
    $type = $att['type'] ?? 'application/octet-stream';
    $name = $att['name'] ?? 'file';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!$b64) continue;

    if (!empty($att['isImage']) || str_starts_with($type, 'image/')) {
        // Image — send as vision block
        $mime = in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
            ? $type : 'image/jpeg';
        $userContentBlocks[] = [
            'type'   => 'image',
            'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64],
        ];
    } elseif ($ext === 'pdf' || $type === 'application/pdf') {
        // PDF — send as document block (Claude reads natively)
        $userContentBlocks[] = [
            'type'   => 'document',
            'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64],
            'title'  => $name,
        ];
    } elseif (in_array($ext, ['docx', 'doc'])) {
        // DOCX — decode from base64, save to temp, extract text
        $tmp = tempnam(sys_get_temp_dir(), 'lcs_docx_') . '.docx';
        file_put_contents($tmp, base64_decode($b64));
        $text = extract_docx_text($tmp);
        @unlink($tmp);
        if ($text) {
            $userContentBlocks[] = [
                'type' => 'text',
                'text' => "Student attached document \"{$name}\":\n\n" . substr($text, 0, 4000),
            ];
        }
    } elseif ($ext === 'txt') {
        $text = base64_decode($b64);
        $userContentBlocks[] = [
            'type' => 'text',
            'text' => "Student attached text file \"{$name}\":\n\n" . substr($text, 0, 4000),
        ];
    }
}

// Build the final user message content
$userContentBlocks[] = ['type' => 'text', 'text' => $message ?: '(Please analyse the attached file and help me with it in context of this course.)'];

$apiMessages[] = [
    'role'    => 'user',
    'content' => count($userContentBlocks) === 1 ? $message : $userContentBlocks,
];

// ── Call Anthropic API ────────────────────────────────────────────────────────
$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'system'     => $systemPrompt,
    'messages'   => $apiMessages,
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
    CURLOPT_TIMEOUT => 60,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed: ' . $curlError]);
    exit();
}

$data = json_decode($response, true);
if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
    $errMsg = $data['error']['message'] ?? 'Unknown API error';
    http_response_code(500);
    echo json_encode(['error' => $errMsg]);
    exit();
}

$reply = $data['content'][0]['text'];

// Save assistant reply
db()->prepare("INSERT INTO ai_chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)")
    ->execute([$sessionId, $reply]);

// Touch session updated_at
db()->prepare("UPDATE ai_chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$sessionId]);

echo json_encode(['reply' => $reply, 'session_id' => $sessionId]);
