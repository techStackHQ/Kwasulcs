<?php

/**
 * ai_quiz_submit.php — Saves answers and scores the quiz
 *
 * POST JSON:
 * { "session_id": 1, "answers": {"1": "A", "2": "MIS is...", "3": "A"} }
 *
 * Marking model:
 *   - MCQ / True-False: full marks or zero, exact letter match.
 *   - Theory ("list_explain"): AI grades each expected item — a small mark for
 *     correctly listing it, a larger mark for correctly explaining it — using
 *     the rubric's item_key_points as the grading reference.
 *   - Theory ("general"): AI grades against 3-4 rubric criteria, awarding
 *     proportional marks per criterion based on how well the student's answer
 *     covers each one.
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

// ── Separate objective (instant) vs theory (needs AI grading) questions ──────
$objectiveResults = [];
$theoryQueue      = []; // questions needing AI grading

foreach ($questions as $q) {
    $no         = (int)$q['question_no'];
    $userAnswer = trim((string)($answers[$no] ?? ''));
    $marks      = (float)$q['marks'];

    if ($q['type'] === 'mcq' || $q['type'] === 'truefalse') {
        $correct   = trim($q['correct']);
        $isCorrect = strtoupper($userAnswer) === strtoupper($correct);
        $awarded   = $isCorrect ? $marks : 0;

        db()->prepare("UPDATE quiz_questions SET user_answer=?, is_correct=?, awarded_marks=? WHERE id=?")
            ->execute([$userAnswer, $isCorrect ? 1 : 0, $awarded, $q['id']]);

        $objectiveResults[$no] = [
            'question_no' => $no,
            'type'        => $q['type'],
            'question'    => $q['question'],
            'options'     => $q['options'] ? json_decode($q['options'], true) : null,
            'user_answer' => $userAnswer,
            'correct'     => $correct,
            'is_correct'  => $isCorrect,
            'marks'       => $marks,
            'awarded_marks' => $awarded,
            'explanation' => $q['explanation'],
            'breakdown'   => null,
        ];
    } else {
        // Theory question — queue for AI grading
        $theoryQueue[] = [
            'row'         => $q,
            'no'          => $no,
            'user_answer' => $userAnswer,
        ];
    }
}

// ── AI-grade all theory questions in a single batched call ───────────────────
$theoryResults = [];
if (!empty($theoryQueue)) {
    $gradingPayload = [];
    foreach ($theoryQueue as $item) {
        $q = $item['row'];
        $rubric = $q['rubric'] ? json_decode($q['rubric'], true) : null;
        $gradingPayload[] = [
            'question_no' => $item['no'],
            'question'    => $q['question'],
            'format'      => $q['format'],
            'marks'       => (float)$q['marks'],
            'rubric'      => $rubric,
            'student_answer' => $item['user_answer'],
        ];
    }

    $gradingJson = json_encode($gradingPayload, JSON_PRETTY_PRINT);

    $gradingPrompt = <<<PROMPT
You are grading university theory exam answers for a Nigerian university course. Below is a JSON array of questions, each with its rubric and the student's actual submitted answer.

GRADING INSTRUCTIONS:

For questions with "format": "list_explain":
- The rubric has "expected_items" (N), "marks_per_list_item", "marks_per_explain_item", and "item_key_points" (the N correct items with what a good explanation covers).
- For EACH of the N expected items, check independently whether the student's answer:
  (a) correctly LISTED/NAMED that item anywhere in their answer — if yes, award marks_per_list_item for that item
  (b) correctly EXPLAINED that item with genuine understanding (not just naming it) — if yes, award marks_per_explain_item for that item. Partial explanations can receive partial credit (e.g. half of marks_per_explain_item) if the explanation is present but weak/incomplete.
- The student does not need to present items in the same order as item_key_points — match by meaning, not position.
- If the student lists/explains MORE items than expected, only grade up to the expected_items count using the best N matches — do not penalize extra correct items, but do not award more than the question's total marks.
- Sum all awarded item marks for the question's total awarded_marks.

For questions with "format": "general":
- The rubric has "criteria": an array of {description, marks}.
- For EACH criterion, judge how well the student's answer satisfies that specific criterion, and award a proportional mark from 0 up to that criterion's max marks (partial credit is expected and encouraged — do not require perfection to award something).
- Sum all awarded criterion marks for the question's total awarded_marks.

General grading principles:
- Be fair but rigorous, as a real university lecturer would be — reward genuine understanding, don't reward vague or irrelevant padding.
- If the student's answer is empty or completely irrelevant, award 0 for every part.
- Round every individual awarded mark to 1 decimal place.
- total awarded_marks for each question must never exceed that question's total "marks" value.

QUESTIONS AND ANSWERS TO GRADE:
{$gradingJson}

OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "graded": [
    {
      "question_no": 3,
      "awarded_marks": 7.5,
      "breakdown": {
        "format": "list_explain",
        "items": [
          {"key_point": "Bandwidth limitation...", "listed": true, "list_marks_awarded": 0.4, "explained": true, "explain_marks_awarded": 1.2, "feedback": "Correctly listed and explained, though missed mentioning..."},
          {"key_point": "Traffic bursts...", "listed": false, "list_marks_awarded": 0, "explained": false, "explain_marks_awarded": 0, "feedback": "Not addressed in the answer."}
        ]
      }
    },
    {
      "question_no": 5,
      "awarded_marks": 9.0,
      "breakdown": {
        "format": "general",
        "criteria": [
          {"description": "Correctly defines the client role", "max_marks": 3, "awarded_marks": 3, "feedback": "Clear and accurate definition given."},
          {"description": "Correctly defines the server role", "max_marks": 3, "awarded_marks": 2, "feedback": "Mentioned but slightly vague."}
        ]
      }
    }
  ]
}
PROMPT;

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 6000,
        'messages'   => [['role' => 'user', 'content' => $gradingPrompt]],
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
        ],
        CURLOPT_TIMEOUT => 100,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $gradedByNo = [];
    if (!$curlError && $httpCode === 200) {
        $data = json_decode($response, true);
        $rawText = $data['content'][0]['text'] ?? '';
        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $rawText = preg_replace('/```\s*$/m', '', $rawText);
        $parsed  = json_decode(trim($rawText), true);
        if ($parsed && isset($parsed['graded'])) {
            foreach ($parsed['graded'] as $g) {
                $gradedByNo[(int)$g['question_no']] = $g;
            }
        }
    }

    // Apply grading results (or a safe fallback if the AI call failed)
    foreach ($theoryQueue as $item) {
        $q  = $item['row'];
        $no = $item['no'];
        $g  = $gradedByNo[$no] ?? null;

        if ($g) {
            $awarded   = min((float)$g['awarded_marks'], (float)$q['marks']);
            $breakdown = $g['breakdown'] ?? null;
        } else {
            // Fallback: lenient keyword match, capped at 50% of marks, if AI grading failed
            $correctWords = array_filter(str_word_count(strtolower($q['correct']), 1));
            $answerLower  = strtolower($item['user_answer']);
            $matchCount   = 0;
            foreach ($correctWords as $word) {
                if (strlen($word) > 3 && str_contains($answerLower, $word)) $matchCount++;
            }
            $ratio   = count($correctWords) > 0 ? ($matchCount / count($correctWords)) : 0;
            $awarded = round($ratio * (float)$q['marks'], 1);
            $breakdown = ['format' => 'fallback', 'note' => 'AI grading unavailable — used keyword fallback'];
        }

        db()->prepare("UPDATE quiz_questions SET user_answer=?, is_correct=?, awarded_marks=?, grading_breakdown=? WHERE id=?")
            ->execute([
                $item['user_answer'],
                $awarded >= (float)$q['marks'] * 0.5 ? 1 : 0, // rough correct/incorrect flag for legacy display
                $awarded,
                json_encode($breakdown),
                $q['id'],
            ]);

        $theoryResults[$no] = [
            'question_no'   => $no,
            'type'          => $q['type'],
            'format'        => $q['format'],
            'question'      => $q['question'],
            'options'       => null,
            'user_answer'   => $item['user_answer'],
            'correct'       => $q['correct'],
            'marks'         => (float)$q['marks'],
            'awarded_marks' => $awarded,
            'explanation'   => $q['explanation'],
            'breakdown'     => $breakdown,
        ];
    }
}

// ── Merge results back in question order ──────────────────────────────────────
$results = [];
foreach ($questions as $q) {
    $no = (int)$q['question_no'];
    $results[] = $objectiveResults[$no] ?? $theoryResults[$no] ?? null;
}
$results = array_values(array_filter($results));

$totalAwarded  = array_sum(array_column($results, 'awarded_marks'));
$totalPossible = array_sum(array_column($results, 'marks'));
$percent       = $totalPossible > 0 ? round(($totalAwarded / $totalPossible) * 100) : 0;
$grade         = match (true) {
    $percent >= 70 => 'A',
    $percent >= 60 => 'B',
    $percent >= 50 => 'C',
    $percent >= 45 => 'D',
    default        => 'F',
};

// Mark session complete — score/total kept as percent-based ints for legacy display compatibility
db()->prepare("UPDATE quiz_sessions SET status='completed', score=?, total=100, completed_at=NOW() WHERE id=?")
    ->execute([$percent, $sessionId]);

echo json_encode([
    'score'          => round($totalAwarded, 1),
    'total'          => round($totalPossible, 1),
    'percent'        => $percent,
    'grade'          => $grade,
    'results'        => $results,
]);
