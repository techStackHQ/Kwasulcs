<?php
require_once __DIR__ . '/config.php';
require_login();

$user     = current_user();
$courseId = (int)($_GET['course'] ?? 0);

if ($courseId <= 0) {
    header('Location: dashboard.php');
    exit();
}

// Load course
$courseStmt = db()->prepare('SELECT c.*, u.full_name AS lecturer_name FROM courses c JOIN users u ON u.id=c.lecturer_id WHERE c.id=?');
$courseStmt->execute([$courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    header('Location: dashboard.php');
    exit();
}

// Load topics for scope selector
$topicsStmt = db()->prepare('SELECT id, week_number, title FROM topics WHERE course_id=? ORDER BY week_number');
$topicsStmt->execute([$courseId]);
$topics = $topicsStmt->fetchAll();

// ── Optional per-week pre-selection (Task 25) ─────────────────────────────────
// Arriving via course.php's per-week "Quiz" link passes ?topic=<id> so the
// setup screen below lands with "Specific Week" already selected instead
// of defaulting to "Whole Course" — cosmetic pre-fill only, NOT a security
// boundary: ai_quiz.php itself already re-validates that topic_id actually
// belongs to course_id server-side (WHERE course_id=? AND id=?) regardless
// of what's passed here, so a mismatched/tampered id can't leak another
// course's topic — it would just fail to match anything at generation
// time. Validated here purely so the dropdown doesn't try to pre-select an
// option value that isn't actually in it.
$preselectTopicId = (int) ($_GET['topic'] ?? 0);
if ($preselectTopicId > 0) {
    $validPreselect = false;
    foreach ($topics as $t) {
        if ((int) $t['id'] === $preselectTopicId) {
            $validPreselect = true;
            break;
        }
    }
    if (!$validPreselect) {
        $preselectTopicId = 0;
    }
}

// Ensure quiz tables exist
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
} catch (\Throwable $e) { /* tables may already exist */
}

// Past quiz sessions for this user + course
$history = [];
try {
    $histStmt = db()->prepare("
        SELECT qs.*, COUNT(qq.id) AS q_count
        FROM quiz_sessions qs
        LEFT JOIN quiz_questions qq ON qq.session_id = qs.id
        WHERE qs.user_id=? AND qs.course_id=? AND qs.status='completed'
        GROUP BY qs.id
        ORDER BY qs.created_at DESC LIMIT 10
    ");
    $histStmt->execute([$user['id'], $courseId]);
    $history = $histStmt->fetchAll();
} catch (\Throwable $e) { /* table may not exist yet */
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Quiz — ' . $course['code'];
    include __DIR__ . '/partials/head.php'; ?>
    <style>
        /* ── Quiz-specific styles ─────────────────────────────────────── */
        .quiz-shell {
            max-width: 760px;
            margin: 0 auto;
        }

        /* Setup card */
        .setup-card {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, .07);
            margin-bottom: 24px;
        }

        .setup-card h2 {
            margin: 0 0 20px;
            font-size: 20px;
        }

        .scope-tabs,
        .type-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        .scope-tab,
        .type-tab {
            flex: 1;
            padding: 12px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: #fff;
            font: inherit;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all .15s;
            text-align: center;
        }

        .scope-tab.active,
        .type-tab.active {
            border-color: #07a701;
            background: #f0fdf4;
            color: var(--primary-dark);
        }

        .count-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 16px 0;
        }

        .count-row label {
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }

        .count-row input[type=range] {
            flex: 1;
            accent-color: #07a701;
        }

        .count-val {
            font-weight: 900;
            color: var(--primary-dark);
            min-width: 28px;
            text-align: center;
            font-size: 18px;
        }

        .image-upload-row {
            margin: 16px 0;
        }

        .image-upload-label {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 2px dashed #e2e8f0;
            border-radius: 14px;
            padding: 14px 18px;
            cursor: pointer;
            transition: border-color .15s;
            font-size: 14px;
            color: #64748b;
        }

        .image-upload-label:hover {
            border-color: #07a701;
            color: var(--primary-dark);
        }

        .img-thumb {
            position: relative;
            width: 84px;
            height: 84px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
        }

        .img-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .img-thumb .img-remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(15, 23, 42, .75);
            color: #fff;
            border: none;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .generate-btn {
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            background-color: #07a701;
            background-image: url('assets/images/buttons/primary-btn-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: #fff;
            border: none;
            font: inherit;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: opacity .15s;
        }

        .generate-btn:hover {
            opacity: .9;
        }

        .generate-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        /* Loading state */
        .quiz-loading {
            text-align: center;
            padding: 60px 24px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, .07);
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e2e8f0;
            border-top-color: #07a701;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Quiz area */
        .quiz-progress {
            background: #fff;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 12px rgba(15, 23, 42, .05);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .progress-bar-wrap {
            flex: 1;
            background: #e2e8f0;
            border-radius: 999px;
            height: 8px;
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, #07a701, #059669);
            height: 100%;
            border-radius: 999px;
            transition: width .3s;
        }

        .progress-label {
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap;
        }

        .question-card {
            background: #fff;
            border-radius: 20px;
            padding: 28px 32px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, .07);
            margin-bottom: 16px;
        }

        .q-eyebrow {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        .q-type-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            margin-bottom: 12px;
        }

        .q-type-badge.mcq {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .q-type-badge.short {
            background: #fef9c3;
            color: #854d0e;
        }

        .q-type-badge.truefalse {
            background: #f3e8ff;
            color: #7e22ce;
        }

        .q-marks-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            margin-left: 8px;
            vertical-align: middle;
        }

        .question-diagram {
            margin: 14px 0;
            text-align: center;
        }

        .question-diagram img {
            max-width: 100%;
            max-height: 380px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .marks-awarded-line {
            margin-top: 14px;
            font-size: 14px;
            color: #334155;
        }

        .marks-awarded-line strong {
            color: var(--primary-dark);
        }

        .breakdown-box {
            margin-top: 14px;
            padding: 14px 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .breakdown-title {
            font-weight: 800;
            font-size: 13px;
            margin-bottom: 10px;
            color: #334155;
        }

        .breakdown-item {
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-item-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .breakdown-row {
            display: flex;
            gap: 14px;
            font-size: 12px;
            margin-top: 4px;
        }

        .bd-yes {
            color: #16a34a;
            font-weight: 700;
        }

        .bd-no {
            color: #dc2626;
            font-weight: 700;
        }

        .breakdown-feedback {
            color: #64748b;
            font-size: 12px;
            margin-top: 4px;
        }

        .question-text {
            font-size: 17px;
            font-weight: 600;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        /* MCQ options */
        .mcq-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .mcq-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 2px solid #e2e8f0;
            cursor: pointer;
            transition: all .15s;
            font-size: 15px;
            text-align: left;
        }

        .mcq-option:hover:not(.locked) {
            border-color: #07a701;
            background: #f0fdf4;
        }

        .mcq-option.selected {
            border-color: #07a701;
            background: #f0fdf4;
        }

        .mcq-option.correct {
            border-color: #16a34a;
            background: #dcfce7;
        }

        .mcq-option.wrong {
            border-color: #dc2626;
            background: #fee2e2;
        }

        .opt-letter {
            font-weight: 900;
            font-size: 14px;
            color: #64748b;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all .15s;
        }

        .mcq-option.selected .opt-letter {
            background: #07a701;
            color: #fff;
        }

        .mcq-option.correct .opt-letter {
            background: #16a34a;
            color: #fff;
        }

        .mcq-option.wrong .opt-letter {
            background: #dc2626;
            color: #fff;
        }

        /* Short answer */
        .short-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font: inherit;
            font-size: 15px;
            resize: vertical;
            min-height: 90px;
            outline: none;
            transition: border-color .15s;
            box-sizing: border-box;
        }

        .short-input:focus {
            border-color: #07a701;
        }

        /* Explanation */
        .explanation-box {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #f8fafc;
            border-left: 4px solid #07a701;
            font-size: 14px;
            line-height: 1.6;
            display: none;
        }

        .explanation-box.show {
            display: block;
        }

        .explanation-box strong {
            color: var(--primary-dark);
        }

        /* Nav */
        .quiz-nav {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 8px;
        }

        .nav-btn {
            padding: 12px 24px;
            border-radius: 12px;
            font: inherit;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all .15s;
        }

        .nav-btn.secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .nav-btn.secondary:hover {
            background: #e2e8f0;
        }

        .nav-btn.primary {
            background-color: #07a701;
            background-image: url('assets/images/buttons/primary-btn-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: #fff;
        }

        .nav-btn.primary:hover {
            background-color: #059669;
        }

        .nav-btn.submit-all {
            background-color: #07a701;
            background-image: url('assets/images/buttons/primary-btn-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: #fff;
            flex: 1;
            padding: 16px;
            font-size: 16px;
            border-radius: 14px;
        }

        /* Results */
        .results-card {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, .07);
            text-align: center;
            margin-bottom: 24px;
        }

        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(#07a701 var(--pct), #e2e8f0 0);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 28px;
            font-weight: 900;
            position: relative;
        }

        .score-circle::before {
            content: '';
            position: absolute;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #fff;
        }

        .score-num {
            position: relative;
            z-index: 1;
        }

        .grade-badge {
            display: inline-block;
            font-size: 32px;
            font-weight: 900;
            padding: 8px 24px;
            border-radius: 14px;
            margin: 8px 0;
        }

        .grade-A {
            background: #dcfce7;
            color: #16a34a;
        }

        .grade-B {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .grade-C {
            background: #fef9c3;
            color: #854d0e;
        }

        .grade-D {
            background: #ffedd5;
            color: #c2410c;
        }

        .grade-F {
            background: #fee2e2;
            color: #dc2626;
        }

        /* History */
        .history-card {
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(15, 23, 42, .05);
        }

        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        @media (max-width: 600px) {
            .question-card {
                padding: 20px 16px;
            }

            .quiz-nav {
                flex-direction: column;
            }
        }
    </style>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>
    <header class="topbar">
        <div>
            <div class="eyebrow">Quiz</div>
            <h1><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></h1>
            <p class="muted">AI-generated practice quiz · <?php echo h($course['lecturer_name']); ?></p>
        </div>
        <div class="topbar-actions">
            <a class="btn glass" href="course.php?id=<?php echo $courseId; ?>">← Back to Course</a>
        </div>
    </header>

    <main class="page">
        <div class="quiz-shell">

            <!-- ── Setup ──────────────────────────────────────────────────────────── -->
            <div id="setupSection">
                <div class="setup-card">
                    <h2><i class="bi bi-bullseye icon"></i> Generate Practice Quiz</h2>

                    <!-- Scope: whole course or specific topic -->
                    <label style="font-weight:700;font-size:14px;display:block;margin-bottom:8px;">Quiz Scope</label>
                    <div class="scope-tabs">
                        <button class="scope-tab active" onclick="setScope('course',this)"><i class="bi bi-journal-bookmark-fill icon"></i> Whole Course</button>
                        <button class="scope-tab" id="scopeTabTopic" onclick="setScope('topic',this)"><i class="bi bi-book-half icon"></i> Specific Week</button>
                    </div>

                    <div id="topicPicker" style="display:none;margin-bottom:16px;">
                        <label style="font-weight:600;font-size:14px;display:block;margin-bottom:6px;">Select Week</label>
                        <select id="topicSelect" style="width:100%;padding:12px 14px;border-radius:12px;border:2px solid #e2e8f0;font:inherit;font-size:15px;">
                            <?php foreach ($topics as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>">
                                    Week <?php echo (int)$t['week_number']; ?> — <?php echo h($t['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Question type -->
                    <label style="font-weight:700;font-size:14px;display:block;margin-bottom:8px;">Question Type</label>
                    <div class="type-tabs" style="margin-bottom:16px;">
                        <button class="type-tab" onclick="setQuestionType('objective',this)"><i class="bi bi-pencil-fill icon"></i> Objective</button>
                        <button class="type-tab" onclick="setQuestionType('theory',this)"><i class="bi bi-book-half icon"></i> Theory</button>
                        <button class="type-tab" onclick="setQuestionType('truefalse',this)"><i class="bi bi-check-circle-fill icon"></i> True/False</button>
                        <button class="type-tab active" onclick="setQuestionType('hybrid',this)"><i class="bi bi-shuffle icon"></i> Hybrid</button>
                    </div>

                    <!-- Number of questions -->
                    <div class="count-row">
                        <label>Questions</label>
                        <input type="range" id="qCount" min="3" max="20" value="10" oninput="document.getElementById('qCountVal').textContent=this.value">
                        <span class="count-val" id="qCountVal">10</span>
                    </div>

                    <!-- Past question images upload (up to 5) -->
                    <div class="image-upload-row">
                        <label style="font-weight:700;font-size:14px;display:block;margin-bottom:8px;">
                            <i class="bi bi-camera-fill icon"></i> Upload Past Question Papers / Diagrams (optional, up to 5)
                        </label>
                        <label class="image-upload-label" for="imageInput">
                            <span style="font-size:24px;"><i class="bi bi-camera-fill"></i></span>
                            <span id="imageLabel">Upload past question papers for style matching, or diagrams the AI can attach to relevant questions</span>
                        </label>
                        <input type="file" id="imageInput" accept="image/*" multiple style="display:none" onchange="handleImages(this)">
                        <div id="imagePreviewGrid" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;"></div>
                    </div>

                    <button class="generate-btn" id="generateBtn" onclick="generateQuiz()">
                        <i class="bi bi-stars icon"></i> Generate Quiz
                    </button>
                </div>

                <!-- History -->
                <?php if ($history): ?>
                    <div class="history-card">
                        <h3 style="margin:0 0 16px;">Previous Quiz Results</h3>
                        <?php foreach ($history as $hist): ?>
                            <div class="history-item">
                                <div>
                                    <div style="font-weight:600;"><?php echo $hist['scope'] === 'course' ? 'Whole course' : 'Topic quiz'; ?></div>
                                    <div class="muted"><?php echo date('d M Y, g:i A', strtotime($hist['created_at'])); ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-weight:900;font-size:18px;color:var(--primary-dark);"><?php echo $hist['score']; ?>/<?php echo $hist['total']; ?></div>
                                    <div class="muted"><?php echo $hist['total'] > 0 ? round($hist['score'] / $hist['total'] * 100) : 0; ?>%</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Loading ────────────────────────────────────────────────────────── -->
            <div id="loadingSection" style="display:none;">
                <div class="quiz-loading">
                    <div class="spinner"></div>
                    <h2 style="margin:0 0 8px;">Generating your quiz…</h2>
                    <p class="muted" id="quizLoadingStatus">Reading course materials…</p>
                </div>
            </div>

            <!-- ── Quiz ──────────────────────────────────────────────────────────── -->
            <div id="quizSection" style="display:none;">
                <div class="quiz-progress">
                    <div class="progress-bar-wrap">
                        <div class="progress-bar" id="progressBar" style="width:0%"></div>
                    </div>
                    <span class="progress-label" id="progressLabel">Question 1 of 10</span>
                </div>
                <div id="questionArea"></div>
                <div class="quiz-nav" id="quizNav"></div>
            </div>

            <!-- ── Submitting (grading can take a real, noticeable amount of time for
                 theory-heavy quizzes — this replaces what used to be no loading
                 indicator at all during submission) ──────────────────────────────── -->
            <div id="submittingSection" style="display:none;">
                <div class="quiz-loading">
                    <div class="spinner"></div>
                    <h2 style="margin:0 0 8px;">Grading your quiz…</h2>
                    <p class="muted" id="submitLoadingStatus">Submitting your answers…</p>
                </div>
            </div>

            <!-- ── Results ───────────────────────────────────────────────────────── -->
            <div id="resultsSection" style="display:none;"></div>

        </div>
    </main>

    <script>
        const COURSE_ID = <?php echo $courseId; ?>;
        let quizData = null; // {session_id, questions, total, uploaded_images}
        let answers = {}; // {question_no: answer}
        let currentQ = 0;
        let submitted = false;
        let imagesB64 = []; // array of base64 images, up to 5
        let scope = 'course';
        let topicId = null;
        let questionType = 'hybrid';

        // ── Scope toggle ──────────────────────────────────────────────────────────────
        function setScope(s, btn) {
            scope = s;
            document.querySelectorAll('.scope-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('topicPicker').style.display = s === 'topic' ? 'block' : 'none';
        }

        // ── Pre-select "Specific Week" from a per-week "Quiz" link (Task 25) ────────────
        // Lands on the normal setup screen with the week already chosen — count
        // slider, question type, and image upload all stay fully available;
        // generation only starts when the student clicks "Generate Quiz" as
        // usual. A student arriving at quiz.php normally (no ?topic= param)
        // sees the unchanged "Whole Course" default.
        const PRESELECT_TOPIC_ID = <?php echo $preselectTopicId; ?>;
        if (PRESELECT_TOPIC_ID > 0) {
            setScope('topic', document.getElementById('scopeTabTopic'));
            document.getElementById('topicSelect').value = String(PRESELECT_TOPIC_ID);
        }

        // ── Question type toggle ──────────────────────────────────────────────────────
        function setQuestionType(t, btn) {
            questionType = t;
            document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
        }

        // ── Image upload (up to 5) ───────────────────────────────────────────────────────
        // Downscales client-side (canvas) before it's turned into base64 —
        // these are usually full-size phone photos of past-question papers,
        // and shrinking to ~1600px/JPEG here cuts the upload payload well
        // before it hits the network. Falls back to the original file as-is
        // if anything about the resize fails.
        function resizeImageToDataUrl(file, maxDimension = 1600, quality = 0.8) {
            return new Promise(resolve => {
                const readOriginal = () => {
                    const reader = new FileReader();
                    reader.onload = e => resolve(e.target.result);
                    reader.onerror = () => resolve(null);
                    reader.readAsDataURL(file);
                };
                const img = new Image();
                const url = URL.createObjectURL(file);
                img.onload = () => {
                    URL.revokeObjectURL(url);
                    const longest = Math.max(img.width, img.height);
                    const canvas = document.createElement('canvas');
                    const scale = longest > maxDimension ? maxDimension / longest : 1;
                    canvas.width = Math.round(img.width * scale);
                    canvas.height = Math.round(img.height * scale);
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    resolve(canvas.toDataURL('image/jpeg', quality));
                };
                img.onerror = () => {
                    URL.revokeObjectURL(url);
                    readOriginal();
                };
                img.src = url;
            });
        }

        async function handleImages(input) {
            const files = Array.from(input.files || []);
            if (!files.length) return;

            const room = 5 - imagesB64.length;
            if (room <= 0) {
                alert('Maximum of 5 images allowed. Remove one first.');
                input.value = '';
                return;
            }
            const toAdd = files.slice(0, room);

            for (const file of toAdd) {
                const b64 = await resizeImageToDataUrl(file);
                if (b64) imagesB64.push(b64);
            }
            renderImageGrid();
            input.value = '';
        }

        function removeImage(idx) {
            imagesB64.splice(idx, 1);
            renderImageGrid();
        }

        function renderImageGrid() {
            const grid = document.getElementById('imagePreviewGrid');
            grid.innerHTML = imagesB64.map((src, i) => `
                <div class="img-thumb">
                    <img src="${src}" alt="Uploaded image ${i+1}">
                    <button class="img-remove-btn" onclick="removeImage(${i})" title="Remove">✕</button>
                </div>
            `).join('');
            document.getElementById('imageLabel').innerHTML = imagesB64.length ?
                `<i class="bi bi-check-circle-fill icon"></i> ${imagesB64.length} image(s) loaded — ${5 - imagesB64.length} slot(s) remaining` :
                'Upload past question papers for style matching, or diagrams the AI can attach to relevant questions';
        }

        // ── Generate quiz ─────────────────────────────────────────────────────────────
        async function generateQuiz() {
            const btn = document.getElementById('generateBtn');
            const count = parseInt(document.getElementById('qCount').value);
            topicId = scope === 'topic' ? parseInt(document.getElementById('topicSelect').value) : null;

            btn.disabled = true;
            document.getElementById('setupSection').style.display = 'none';
            document.getElementById('loadingSection').style.display = 'block';

            // Quiz pacing (Task 24) — deliberately slower/smoother than AI
            // chat's loader (2000ms/message + 600ms cross-dissolve fade,
            // ~6s per full loop of these 3 messages) since generation
            // realistically takes 20-80s+, not the few seconds chat
            // typically takes. This LOOPS for as long as stopStatusCycler()
            // hasn't been called yet — it does not run out after one pass.
            const stopStatusCycler = startStatusCycler(document.getElementById('quizLoadingStatus'), [
                'Reading course materials…',
                'Generating questions…',
                'Finalizing your quiz…',
            ], 2000, 600);

            try {
                const res = await fetch('ai_quiz.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        course_id: COURSE_ID,
                        topic_id: topicId,
                        scope: scope,
                        count: count,
                        question_type: questionType,
                        images_b64: imagesB64,
                    })
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Generation failed');

                quizData = data;
                answers = {};
                currentQ = 0;
                submitted = false;

                stopStatusCycler();
                document.getElementById('loadingSection').style.display = 'none';
                document.getElementById('quizSection').style.display = 'block';
                renderQuestion();
            } catch (err) {
                stopStatusCycler();
                document.getElementById('loadingSection').style.display = 'none';
                document.getElementById('setupSection').style.display = 'block';
                btn.disabled = false;
                alert('Could not generate quiz: ' + err.message);
            }
        }

        // ── Render current question ───────────────────────────────────────────────────
        function renderQuestion() {
            const q = quizData.questions[currentQ];
            const no = currentQ + 1;
            const total = quizData.total;

            // Progress
            document.getElementById('progressBar').style.width = ((no - 1) / total * 100) + '%';
            document.getElementById('progressLabel').textContent = `Question ${no} of ${total}`;

            const typeLabels = {
                mcq: 'Multiple Choice',
                short: 'Short Answer',
                truefalse: 'True / False'
            };
            const answer = answers[no];
            const isAnswered = answer !== undefined && answer !== '';
            const locked = submitted;

            let html = `
        <div class="question-card">
            <div class="q-eyebrow">Question ${no} of ${total}</div>
            <span class="q-type-badge ${q.type}">${typeLabels[q.type] || q.type}</span>
            <span class="q-marks-badge">${q.marks != null ? q.marks : '—'} marks</span>
            <div class="question-text">${escHtml(q.question)}</div>`;

            // Diagram image, if this question references one of the uploaded images
            if (q.image_ref !== null && q.image_ref !== undefined && quizData.uploaded_images && quizData.uploaded_images[q.image_ref]) {
                html += `<div class="question-diagram">
                <img src="${quizData.uploaded_images[q.image_ref]}" alt="Diagram for this question">
            </div>`;
            }

            if (q.type === 'mcq' || q.type === 'truefalse') {
                html += '<div class="mcq-options">';
                for (const [letter, text] of Object.entries(q.options || {})) {
                    let cls = 'mcq-option';
                    if (locked) {
                        if (letter === q.correct) cls += ' correct';
                        else if (letter === answer) cls += ' wrong';
                    } else if (letter === answer) {
                        cls += ' selected';
                    }
                    html += `<button class="${cls} ${locked ? 'locked' : ''}" onclick="selectOption('${letter}')">
                <span class="opt-letter">${letter}</span>
                <span>${escHtml(text)}</span>
            </button>`;
                }
                html += '</div>';
                if (locked) {
                    html += `<div class="marks-awarded-line">Marks awarded: <strong>${q.awarded_marks != null ? q.awarded_marks : (q.is_correct ? q.marks : 0)} / ${q.marks}</strong></div>`;
                }
            } else {
                // Theory (short) answer
                const val = answer || '';
                html += `<textarea class="short-input" id="shortAnswer" placeholder="Type your answer here…" ${locked ? 'readonly' : ''}
            oninput="answers[${no}]=this.value">${escHtml(val)}</textarea>`;
                if (locked) {
                    html += `<div style="margin-top:12px;padding:12px 16px;background:#f0fdf4;border-radius:10px;font-size:14px;">
                <strong style="color:var(--primary-dark);">Model answer:</strong> ${escHtml(q.correct)}</div>`;
                    html += `<div class="marks-awarded-line">Marks awarded: <strong>${q.awarded_marks != null ? q.awarded_marks : '—'} / ${q.marks}</strong></div>`;
                    html += renderBreakdown(q.breakdown);
                }
            }

            // Explanation (show when submitted)
            if (locked && q.explanation) {
                html += `<div class="explanation-box show">
            <strong><i class="bi bi-lightbulb-fill icon"></i> Explanation:</strong> ${escHtml(q.explanation)}</div>`;
            }

            html += '</div>';
            document.getElementById('questionArea').innerHTML = html;

            // Navigation buttons
            let nav = '';
            if (currentQ > 0) nav += `<button class="nav-btn secondary" onclick="goTo(${currentQ-1})">← Previous</button>`;
            else nav += '<span></span>';

            if (!submitted) {
                if (currentQ < total - 1) {
                    nav += `<button class="nav-btn primary" onclick="goTo(${currentQ+1})">Next →</button>`;
                } else {
                    nav += `<button class="nav-btn submit-all" onclick="submitQuiz()">Submit Quiz ✓</button>`;
                }
            } else {
                if (currentQ < total - 1) {
                    nav += `<button class="nav-btn primary" onclick="goTo(${currentQ+1})">Next →</button>`;
                } else {
                    nav += `<button class="nav-btn primary" onclick="showResults()">See Results →</button>`;
                }
            }
            document.getElementById('quizNav').innerHTML = nav;
        }

        function selectOption(letter) {
            if (submitted) return;
            answers[currentQ + 1] = letter;
            renderQuestion();
        }

        function goTo(index) {
            // Save short answer before navigating
            const ta = document.getElementById('shortAnswer');
            if (ta) answers[currentQ + 1] = ta.value;
            currentQ = index;
            renderQuestion();
        }

        // ── Submit quiz ───────────────────────────────────────────────────────────────
        async function submitQuiz() {
            // Save any open short answer
            const ta = document.getElementById('shortAnswer');
            if (ta) answers[currentQ + 1] = ta.value;

            // Check all answered
            const unanswered = quizData.questions.filter((q, i) => {
                const a = answers[i + 1];
                return a === undefined || a === '';
            });
            if (unanswered.length > 0 && !confirm(`${unanswered.length} question(s) unanswered. Submit anyway?`)) return;

            document.getElementById('quizSection').style.display = 'none';
            document.getElementById('submittingSection').style.display = 'block';

            // Same quiz pacing as generation above (Task 24) — loops
            // continuously for as long as grading is still pending.
            const stopStatusCycler = startStatusCycler(document.getElementById('submitLoadingStatus'), [
                'Reviewing your answers…',
                'Marking your responses…',
                'Calculating final marks…',
            ], 2000, 600);

            try {
                const res = await fetch('ai_quiz_submit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        session_id: quizData.session_id,
                        answers
                    })
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Submit failed');

                stopStatusCycler();
                document.getElementById('submittingSection').style.display = 'none';

                // Merge results back into questions for display
                submitted = true;
                data.results.forEach(r => {
                    const q = quizData.questions[r.question_no - 1];
                    if (q) {
                        q.correct = r.correct;
                        q.explanation = r.explanation;
                        q.is_correct = r.is_correct;
                        q.user_answer = r.user_answer;
                        q.awarded_marks = r.awarded_marks;
                        q.marks = r.marks != null ? r.marks : q.marks;
                        q.breakdown = r.breakdown;
                    }
                });

                // Store for results page
                quizData._results = data;

                // Show results from question 1
                document.getElementById('quizSection').style.display = 'block';
                currentQ = 0;
                renderQuestion();
                showResultsBanner(data);
            } catch (err) {
                stopStatusCycler();
                document.getElementById('submittingSection').style.display = 'none';
                document.getElementById('quizSection').style.display = 'block';
                alert('Could not submit: ' + err.message);
            }
        }

        function showResultsBanner(data) {
            const pct = data.percent;
            document.getElementById('resultsSection').style.display = 'block';
            document.getElementById('resultsSection').innerHTML = `
        <div class="results-card">
            <div class="score-circle" style="--pct:${pct * 3.6}deg">
                <span class="score-num" style="font-size:22px;">${pct}%</span>
            </div>
            <h2 style="margin:0 0 4px;">Quiz Complete!</h2>
            <p class="muted">You scored ${data.score} out of ${data.total}</p>
            <div class="grade-badge grade-${data.grade}">${data.grade}</div>
            <p class="muted" style="margin:12px 0 20px;">${gradeMsg(data.grade)}</p>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <button class="nav-btn primary" onclick="reviewAll()">Review All Answers</button>
                <button class="nav-btn secondary" onclick="location.reload()">Take Another Quiz</button>
            </div>
        </div>`;
            document.getElementById('resultsSection').scrollIntoView({
                behavior: 'smooth'
            });
        }

        function showResults() {
            document.getElementById('resultsSection').scrollIntoView({
                behavior: 'smooth'
            });
        }

        function reviewAll() {
            currentQ = 0;
            document.getElementById('quizSection').scrollIntoView({
                behavior: 'smooth'
            });
            renderQuestion();
        }

        function gradeMsg(g) {
            const msgs = {
                A: 'Excellent work! <i class="bi bi-stars icon"></i>',
                B: 'Good job! Keep it up <i class="bi bi-hand-thumbs-up-fill icon"></i>',
                C: 'You passed — review your weak areas',
                D: 'Just passed — more revision needed',
                F: 'Keep studying — you can do better!'
            };
            return msgs[g] || '';
        }

        function escHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // ── Render the per-item / per-criterion grading breakdown for a theory question ─
        function renderBreakdown(breakdown) {
            if (!breakdown || breakdown.format === 'fallback') return '';

            let html = '<div class="breakdown-box">';

            if (breakdown.format === 'list_explain' && Array.isArray(breakdown.items)) {
                html += '<div class="breakdown-title"><i class="bi bi-clipboard-fill icon"></i> Marking breakdown (per item)</div>';
                breakdown.items.forEach((it, i) => {
                    html += `
                <div class="breakdown-item">
                    <div class="breakdown-item-head">
                        <span>${i + 1}. ${escHtml(it.key_point || '')}</span>
                    </div>
                    <div class="breakdown-row">
                        <span class="${it.listed ? 'bd-yes' : 'bd-no'}">${it.listed ? '✓ Listed' : '✗ Not listed'} (${it.list_marks_awarded ?? 0})</span>
                        <span class="${it.explained ? 'bd-yes' : 'bd-no'}">${it.explained ? '✓ Explained' : '✗ Not explained'} (${it.explain_marks_awarded ?? 0})</span>
                    </div>
                    ${it.feedback ? `<div class="breakdown-feedback">${escHtml(it.feedback)}</div>` : ''}
                </div>`;
                });
            } else if (breakdown.format === 'general' && Array.isArray(breakdown.criteria)) {
                html += '<div class="breakdown-title"><i class="bi bi-clipboard-fill icon"></i> Marking breakdown (per criterion)</div>';
                breakdown.criteria.forEach(c => {
                    html += `
                <div class="breakdown-item">
                    <div class="breakdown-item-head">
                        <span>${escHtml(c.description || '')}</span>
                        <strong>${c.awarded_marks ?? 0} / ${c.max_marks ?? '—'}</strong>
                    </div>
                    ${c.feedback ? `<div class="breakdown-feedback">${escHtml(c.feedback)}</div>` : ''}
                </div>`;
                });
            } else {
                return '';
            }

            html += '</div>';
            return html;
        }
    </script>
</body>

</html>