<?php

/**
 * ai_quiz_submit.php — Saves answers and scores the quiz
 *
 * POST JSON:
 * { "session_id": 1, "answers": {"1": "A", "2": "MIS is...", "3": "A"} }
 */
require_once __DIR__ . '/config.php';
require_login();
set_time_limit(60);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId = (int)($body['session_id'] ?? 0);
$answers   = $body['answers'] ?? [];
$user      = current_user();

// Verify session ownership
$sess = db()->prepare("SELECT * FROM quiz_sessions WHERE id=? AND user_id=?");
$sess->execute([$sessionId, $user['id']]);
$session = $sess->fetch();
if (!$session) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// Load questions
$qStmt = db()->prepare("SELECT * FROM quiz_questions WHERE session_id=? ORDER BY question_no");
$qStmt->execute([$sessionId]);
$questions = $qStmt->fetchAll();

$score   = 0;
$results = [];

foreach ($questions as $q) {
    $no         = (int)$q['question_no'];
    $userAnswer = trim((string)($answers[$no] ?? ''));
    $correct    = trim($q['correct']);
    $isCorrect  = false;

    if ($q['type'] === 'mcq' || $q['type'] === 'truefalse') {
        // Exact match on the letter (A/B/C/D)
        $isCorrect = strtoupper($userAnswer) === strtoupper($correct);
    } else {
        // Short answer — use keyword matching (lenient)
        // Consider correct if answer contains at least 40% of key words
        $correctWords = array_filter(str_word_count(strtolower($correct), 1));
        $answerLower  = strtolower($userAnswer);
        $matchCount   = 0;
        foreach ($correctWords as $word) {
            if (strlen($word) > 3 && str_contains($answerLower, $word)) $matchCount++;
        }
        $isCorrect = count($correctWords) > 0 && ($matchCount / count($correctWords)) >= 0.4;
    }

    if ($isCorrect) $score++;

    // Save answer back to DB
    db()->prepare("UPDATE quiz_questions SET user_answer=?, is_correct=? WHERE id=?")
        ->execute([$userAnswer, $isCorrect ? 1 : 0, $q['id']]);

    $options = $q['options'] ? json_decode($q['options'], true) : null;
    $results[] = [
        'question_no'  => $no,
        'type'         => $q['type'],
        'question'     => $q['question'],
        'options'      => $options,
        'user_answer'  => $userAnswer,
        'correct'      => $correct,
        'is_correct'   => $isCorrect,
        'explanation'  => $q['explanation'],
    ];
}

// Mark session complete
db()->prepare("UPDATE quiz_sessions SET status='completed', score=?, completed_at=NOW() WHERE id=?")
    ->execute([$score, $sessionId]);

$total   = count($questions);
$percent = $total > 0 ? round(($score / $total) * 100) : 0;
$grade   = match (true) {
    $percent >= 70 => 'A',
    $percent >= 60 => 'B',
    $percent >= 50 => 'C',
    $percent >= 45 => 'D',
    default        => 'F',
};

echo json_encode([
    'score'   => $score,
    'total'   => $total,
    'percent' => $percent,
    'grade'   => $grade,
    'results' => $results,
]);
