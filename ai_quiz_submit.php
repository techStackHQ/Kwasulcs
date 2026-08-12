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
// Raised from 120s (Task 22): the automatic retry-on-reasoning-exhaustion
// in call_openai_responses() (config.php) means the grading call can now
// legitimately make up to 3 attempts at up to 100s each — needs real
// headroom to finish a retry rather than being killed by PHP's own
// execution timer partway through one.
set_time_limit(350);
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

YOUR #1 PRIORITY: grade what the student actually UNDERSTANDS, not how closely their wording matches a model answer. Two different graders making the same mistake in opposite directions are both wrong: (a) a grader who deducts marks because the student used different words, a different order, a more casual tone, or skipped a phrase from the "expected" answer even though the underlying concept is correctly conveyed — that grader is TOO HARSH and is the failure mode you must actively avoid; (b) a grader who awards marks for confident-sounding but substantively wrong or empty answers. Aim for (c): a fair university lecturer who reads for MEANING.

CALIBRATION EXAMPLES — use these as your anchor for how generous correct-but-differently-worded answers should be:
- Criterion: "Correctly explains CSMA/CD". Student writes, in their own words and order, that Ethernet devices listen before sending, detect if two signals collide, and stop and retry after a random wait. This is CSMA/CD correctly explained in different language — award FULL marks for this criterion, not a partial deduction for not using the term "carrier sense" verbatim.
- Criterion: "Explains the purpose of a subnet mask". Student says it separates the network and host parts of an IP address (without using the exact phrase "network portion"/"host portion"). This is substantively correct — award FULL or near-full marks, not a low score for imprecise terminology.
- Only award LOW marks (not zero, unless truly unaddressed) when the core concept itself is missing, confused, or factually wrong — never merely because the phrasing, structure, or level of formality differs from an idealized model answer.

For questions with "format": "list_explain":
- The rubric has "expected_items" (N), "marks_per_list_item", "marks_per_explain_item", and "item_key_points" (the N correct items with what a good explanation covers — these describe the CONCEPT expected, not a script to match verbatim).
- For EACH of the N expected items, check independently whether the student's answer:
  (a) correctly LISTED/NAMED that item anywhere in their answer (any reasonable synonym or rephrasing counts) — if yes, award marks_per_list_item for that item
  (b) correctly EXPLAINED that item with genuine understanding of the concept, in ANY wording — if yes, award marks_per_explain_item IN FULL. Only reduce this below full marks when the explanation is genuinely incomplete or partly wrong, and even then award the proportion that reflects what WAS correctly conveyed (e.g. an explanation that captures the main idea but misses one secondary detail should still get the large majority of marks_per_explain_item, not half or less).
- The student does not need to present items in the same order as item_key_points — match by meaning, not position.
- If the student lists/explains MORE items than expected, only grade up to the expected_items count using the best N matches — do not penalize extra correct items, but do not award more than the question's total marks.
- Sum all awarded item marks for the question's total awarded_marks.

For questions with "format": "general":
- The rubric has "criteria": an array of {description, marks}.
- For EACH criterion, judge SEMANTICALLY whether the underlying concept the criterion is checking for was correctly conveyed anywhere in the student's answer — regardless of phrasing, order, or which exact words were used. Award a proportional mark on a genuine spectrum:
  - Concept fully and correctly conveyed (even briefly, even in different words) -> full marks for that criterion.
  - Concept mostly correct with a minor gap or imprecision -> a small deduction only, most of the marks (roughly 70-90%), not a heavy penalty.
  - Concept partially addressed or only vaguely gestured at -> genuine partial credit proportional to what's actually there (roughly 30-60%), not near-zero.
  - Concept entirely missing, confused, or contradicted -> 0 for that criterion.
- Do NOT default to the middle or low end of this spectrum out of caution — if the substance is there, award it.
- Sum all awarded criterion marks for the question's total awarded_marks.

General grading principles:
- Be fair, not harsh — a real university lecturer rewards genuine understanding expressed in the student's own words; they do not run a keyword-matching script. Only withhold marks for what's actually missing or wrong, never for stylistic differences from a model answer.
- If the student's answer is empty or completely irrelevant to the question, award 0 for every part — but "different from the model answer's wording" is NOT the same thing as "irrelevant".
- Round every individual awarded mark to 1 decimal place.
- The question's total awarded_marks MUST equal the sum of that question's own item/criterion awarded marks below — never state a different total than what the breakdown actually adds up to, and never let it exceed that question's total "marks" value.
- EVERY item/criterion's "feedback" field must be a substantive 1-2 sentence explanation of what was and wasn't demonstrated (e.g. "Correctly explained that devices listen before transmitting and back off after a collision, capturing the core CSMA/CD mechanism; did not mention the random backoff timer, but this is a minor omission.") — never a bare restatement of the criterion's description, and never an empty string.

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
          {"key_point": "Bandwidth limitation...", "listed": true, "list_marks_awarded": 0.4, "explained": true, "explain_marks_awarded": 1.2, "feedback": "Correctly identified bandwidth limitation as a cause and explained that insufficient capacity for the traffic volume causes congestion — fully correct, in the student's own words."},
          {"key_point": "Traffic bursts...", "listed": false, "list_marks_awarded": 0, "explained": false, "explain_marks_awarded": 0, "feedback": "Not addressed anywhere in the answer."}
        ]
      }
    },
    {
      "question_no": 5,
      "awarded_marks": 9.0,
      "breakdown": {
        "format": "general",
        "criteria": [
          {"description": "Correctly defines the client role", "max_marks": 3, "awarded_marks": 3, "feedback": "Clearly identifies the client as the requester of services/resources — correct, though phrased differently from a textbook definition."},
          {"description": "Correctly defines the server role", "max_marks": 3, "awarded_marks": 2, "feedback": "Identifies the server as responding to requests, which is the core idea, but doesn't mention it hosting/providing the actual resource — minor gap, most credit given."}
        ]
      }
    }
  ]
}
PROMPT;

    // Structured Outputs JSON schema (OpenAI only — ignored under the
    // Anthropic rollback path, which keeps relying on the prompt-instruction
    // "respond with ONLY JSON" + markdown-fence stripping below, unchanged
    // from before this migration). Mirrors the shape already described in
    // the grading prompt's OUTPUT FORMAT section above.
    $gradingSchema = [
        'type' => 'object',
        'properties' => [
            'graded' => [
                'type'  => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question_no'   => ['type' => 'integer'],
                        'awarded_marks' => ['type' => 'number'],
                        'breakdown' => [
                            'anyOf' => [
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'format' => ['type' => 'string', 'enum' => ['list_explain']],
                                        'items'  => [
                                            'type'  => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'key_point'              => ['type' => 'string'],
                                                    'listed'                 => ['type' => 'boolean'],
                                                    'list_marks_awarded'     => ['type' => 'number'],
                                                    'explained'              => ['type' => 'boolean'],
                                                    'explain_marks_awarded'  => ['type' => 'number'],
                                                    'feedback'               => ['type' => 'string', 'minLength' => 15],
                                                ],
                                                'required' => ['key_point', 'listed', 'list_marks_awarded', 'explained', 'explain_marks_awarded', 'feedback'],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'required' => ['format', 'items'],
                                    'additionalProperties' => false,
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'format'   => ['type' => 'string', 'enum' => ['general']],
                                        'criteria' => [
                                            'type'  => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'description'   => ['type' => 'string'],
                                                    'max_marks'     => ['type' => 'number'],
                                                    'awarded_marks' => ['type' => 'number'],
                                                    'feedback'      => ['type' => 'string', 'minLength' => 15],
                                                ],
                                                'required' => ['description', 'max_marks', 'awarded_marks', 'feedback'],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'required' => ['format', 'criteria'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                    ],
                    'required' => ['question_no', 'awarded_marks', 'breakdown'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['graded'],
        'additionalProperties' => false,
    ];

    $gradedByNo = [];
    try {
        // 'medium' effort, not 'low' — judging whether a differently-worded
        // answer semantically satisfies a criterion is a harder reasoning
        // task than the pure arithmetic 'low' was originally chosen for
        // elsewhere in this file's history, and this is the highest-
        // priority correctness issue in the quiz feature (severe
        // undermarking of substantively-correct answers).
        //
        // Token budget scaled by theory-question count (Task 22 Part A),
        // not a flat number — the user's reported "grading becomes totally
        // trash at higher question counts" symptom and the "No text found
        // in OpenAI response" failures seen in prior testing are the SAME
        // root cause: this is one batched call grading every theory
        // question in the quiz at once, so a bigger batch means more
        // criteria to reason through and more structured per-criterion
        // feedback to produce — a flat ceiling that happened to be enough
        // for 2 theory questions runs out of room for 8. Base kept at least
        // as generous as the previous flat 10000 so small batches don't
        // regress; scales up substantially per additional theory question
        // since reliability is explicitly prioritized over cost/latency
        // here. Automatic retry-on-reasoning-exhaustion also now lives
        // inside call_openai_responses() itself (config.php) as a backstop
        // for whatever this ceiling still doesn't cover.
        $gradingMaxTokens = 10000 + (count($theoryQueue) * 1500);
        // Wall-clock budget scaled the same way (see the matching note in
        // ai_quiz.php's generation call) — a flat 100s can genuinely run out
        // BEFORE any response comes back for a large theory-heavy batch,
        // separate from the reasoning-exhaustion signature Part B retries.
        // Capped well under Apache's own connection Timeout (300s locally).
        $gradingTimeoutSecs = min(180, 80 + (count($theoryQueue) * 10));
        $rawText = call_ai_api('', [['role' => 'user', 'content' => $gradingPrompt]], $gradingMaxTokens, ['name' => 'quiz_grading', 'schema' => $gradingSchema], $gradingTimeoutSecs, 'medium');
        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $rawText = preg_replace('/```\s*$/m', '', $rawText);
        $parsed  = json_decode(trim($rawText), true);
        if ($parsed && isset($parsed['graded'])) {
            foreach ($parsed['graded'] as $g) {
                $gradedByNo[(int)$g['question_no']] = $g;
            }
        }
    } catch (\Throwable $e) {
        // AI grading unavailable — fall through to the keyword fallback below,
        // same as the pre-migration behavior when the Anthropic call failed.
    }

    // Apply grading results (or a safe fallback if the AI call failed)
    foreach ($theoryQueue as $item) {
        $q  = $item['row'];
        $no = $item['no'];
        $g  = $gradedByNo[$no] ?? null;

        if ($g) {
            $breakdown = $g['breakdown'] ?? null;

            // Sum the breakdown's own per-item/per-criterion awarded marks —
            // the same class of sibling-field mismatch confirmed live during
            // the OpenAI migration (ai_quiz.php's question "marks" field
            // could disagree with its own rubric's numbers; Structured
            // Outputs strict mode can't cross-validate that two fields in
            // the same object agree with each other). If this question's
            // top-level "awarded_marks" disagrees with what its own
            // breakdown actually adds up to, the breakdown sum is what's
            // trustworthy — it's the AI's line-by-line judgment; the
            // top-level figure is just a separately-stated summary that can
            // drift from it, and a low top-level figure next to a
            // reasonable-looking breakdown is exactly the undermarking
            // pattern this was written to catch.
            $derivedAwarded = null;
            if (is_array($breakdown)) {
                if (isset($breakdown['items']) && is_array($breakdown['items'])) {
                    $derivedAwarded = 0.0;
                    foreach ($breakdown['items'] as $it) {
                        $derivedAwarded += (float)($it['list_marks_awarded'] ?? 0) + (float)($it['explain_marks_awarded'] ?? 0);
                    }
                } elseif (isset($breakdown['criteria']) && is_array($breakdown['criteria'])) {
                    $derivedAwarded = (float) array_sum(array_column($breakdown['criteria'], 'awarded_marks'));
                }
            }

            $topLevelAwarded = (float)($g['awarded_marks'] ?? 0);
            $awarded = $derivedAwarded !== null ? $derivedAwarded : $topLevelAwarded;
            $awarded = min($awarded, (float)$q['marks']);
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
    $percent >= 40 => 'E',
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
