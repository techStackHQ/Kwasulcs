<?php
require_once __DIR__ . '/config.php';
require_login();

$user     = current_user();
$courseId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('
    SELECT c.*, u.full_name AS lecturer_name
    FROM courses c
    JOIN users u ON u.id = c.lecturer_id
    WHERE c.id = ?
    LIMIT 1
');
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    exit('Course not found');
}

$isStudent  = $user['role'] === 'student';
$isStaff    = in_array($user['role'], ['admin', 'lecturer'], true);
$registered = $isStudent ? is_enrolled((int) $user['id'], $courseId) : true;

// ── Topics ────────────────────────────────────────────────────────────────────
$topicsStmt = db()->prepare('SELECT * FROM topics WHERE course_id = ? ORDER BY week_number ASC, id ASC');
$topicsStmt->execute([$courseId]);
$topics = $topicsStmt->fetchAll();

// ── Sections ─────────────────────────────────────────────────────────────────
$sectionsStmt = db()->prepare('SELECT * FROM course_sections WHERE course_id = ? ORDER BY section_type ASC, id ASC');
$sectionsStmt->execute([$courseId]);
$sections = $sectionsStmt->fetchAll();

// ── Videos & documents per topic ─────────────────────────────────────────────
$videosByTopic = [];
$docsByTopic   = [];
$videoIds      = [];

if ($topics) {
    $topicIds = array_column($topics, 'id');
    $in       = implode(',', array_fill(0, count($topicIds), '?'));

    $vStmt = db()->prepare("SELECT * FROM videos WHERE topic_id IN ($in) ORDER BY id ASC");
    $vStmt->execute($topicIds);
    foreach ($vStmt->fetchAll() as $v) {
        $videosByTopic[(int) $v['topic_id']][] = $v;
        $videoIds[]                             = (int) $v['id'];
    }

    $dStmt = db()->prepare("SELECT * FROM documents WHERE topic_id IN ($in) ORDER BY id ASC");
    $dStmt->execute($topicIds);
    foreach ($dStmt->fetchAll() as $d) {
        $docsByTopic[(int) $d['topic_id']][] = $d;
    }
}

// ── Progress & bookmarks ──────────────────────────────────────────────────────
$progressMap = [];
$bookmarkMap = [];

if ($isStudent) {
    if ($videoIds) {
        $in    = implode(',', array_fill(0, count($videoIds), '?'));
        $pStmt = db()->prepare("SELECT video_id, watched FROM video_progress WHERE student_id = ? AND video_id IN ($in)");
        $pStmt->execute(array_merge([$user['id']], $videoIds));
        foreach ($pStmt->fetchAll() as $row) {
            $progressMap[(int) $row['video_id']] = (int) $row['watched'];
        }
    }

    $bStmt = db()->prepare('SELECT topic_id FROM bookmarks WHERE student_id = ?');
    $bStmt->execute([$user['id']]);
    foreach ($bStmt->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        $bookmarkMap[(int) $tid] = true;
    }
}

// ── Section resources ─────────────────────────────────────────────────────────
$sectionResources = [];
if ($sections) {
    $sectionIds = array_column($sections, 'id');
    $in         = implode(',', array_fill(0, count($sectionIds), '?'));
    $srStmt     = db()->prepare("SELECT * FROM section_resources WHERE section_id IN ($in) ORDER BY id ASC");
    $srStmt->execute($sectionIds);
    foreach ($srStmt->fetchAll() as $res) {
        $sectionResources[(int) $res['section_id']][] = $res;
    }
}

// ── Course calendar events (upcoming, max 5) ──────────────────────────────────
$calStmt = db()->prepare("
    SELECT * FROM calendar_events
    WHERE (scope = 'course' AND course_id = ?)
       OR scope = 'global'
    ORDER BY start_datetime ASC
    LIMIT 10
");
$calStmt->execute([$courseId]);
$courseCalEvents = $calStmt->fetchAll();

function section_label(string $type): string
{
    return $type === 'tutorial_update' ? 'Tutorial Update' : 'Exam Update';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($course['code']); ?> - <?php echo h($course['title']); ?></title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/theme.js" defer></script>
</head>

<body class="app-body">
    <header class="topbar">
        <div>
            <div class="eyebrow">Course</div>
            <h1><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></h1>
            <p class="muted"><?php echo strtoupper(h($course['semester'])); ?> • Lecturer: <?php echo h($course['lecturer_name']); ?></p>
        </div>
        <div class="topbar-actions">
            <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
            <?php if ($registered || $isStaff): ?>
                <a class="btn primary" href="quiz.php?course=<?php echo (int) $courseId; ?>">🎯 Take Quiz</a>
            <?php endif; ?>
            <a class="btn secondary" href="calendar.php?course=<?php echo (int) $courseId; ?>">📅 Course Calendar</a>
            <a class="btn secondary" href="dashboard.php">Back</a>
            <a class="btn danger" href="logout.php">Logout</a>
        </div>
    </header>

    <main class="page">

        <?php if (!empty($_GET['registered'])): ?>
            <div class="alert success">You have successfully registered for this course!</div>
        <?php endif; ?>
        <?php if (!empty($_GET['error']) && $_GET['error'] === 'course_unavailable'): ?>
            <div class="alert error">This course is not available for registration.</div>
        <?php endif; ?>

        <?php if ($isStudent && !$registered): ?>
            <section class="hero-card course-cta">
                <div>
                    <h2>Register for this course</h2>
                    <p class="muted">Registration unlocks weekly topics, videos, documents, tutorials, and exam updates.</p>
                </div>
                <form method="post" action="register_course.php">
                    <input type="hidden" name="course_id" value="<?php echo (int) $courseId; ?>">
                    <button class="btn primary" type="submit">Register Now</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($isStudent && $registered): ?>
            <section class="hero-card">
                <div>
                    <h2>You are registered</h2>
                    <p class="muted">Browse the weekly content below. You can bookmark topics and mark videos as watched.</p>
                </div>
                <div class="stat-pill ok">Unlocked</div>
            </section>
        <?php endif; ?>

        <section class="stack">
            <div class="panel">
                <h2>Weekly Topics</h2>
                <?php if (!$topics): ?>
                    <p class="muted">No week topics have been uploaded yet.</p>
                <?php endif; ?>

                <?php foreach ($topics as $topic): ?>
                    <?php
                    $isBookmarked = isset($bookmarkMap[(int) $topic['id']]);
                    $topicVideos  = $videosByTopic[(int) $topic['id']] ?? [];
                    $topicDocs    = $docsByTopic[(int) $topic['id']] ?? [];
                    ?>
                    <article class="topic-card">
                        <div class="topic-head">
                            <div>
                                <div class="eyebrow">Week <?php echo (int) $topic['week_number']; ?></div>
                                <h3><?php echo h($topic['title']); ?></h3>
                            </div>
                            <?php if ($isStudent && $registered): ?>
                                <form method="post" action="bookmark.php">
                                    <input type="hidden" name="topic_id" value="<?php echo (int) $topic['id']; ?>">
                                    <input type="hidden" name="course_id" value="<?php echo (int) $courseId; ?>">
                                    <button class="btn tiny <?php echo $isBookmarked ? 'secondary' : 'primary'; ?>" type="submit">
                                        <?php echo $isBookmarked ? '★ Bookmarked' : '☆ Bookmark'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="resource-columns">
                            <div>
                                <h4>Videos</h4>
                                <?php if (!$topicVideos): ?>
                                    <p class="muted">No videos yet.</p>
                                <?php endif; ?>
                                <?php foreach ($topicVideos as $video): ?>
                                    <?php $watched = isset($progressMap[(int) $video['id']]) && (int) $progressMap[(int) $video['id']] === 1; ?>
                                    <div class="resource-item">
                                        <div class="resource-item-head">
                                            <strong><?php echo h($video['title']); ?></strong>
                                            <?php if ($isStudent && $registered): ?>
                                                <form method="post" action="progress.php">
                                                    <input type="hidden" name="course_id" value="<?php echo (int) $courseId; ?>">
                                                    <input type="hidden" name="video_id" value="<?php echo (int) $video['id']; ?>">
                                                    <button class="btn tiny <?php echo $watched ? 'secondary' : 'primary'; ?>" type="submit" name="watched" value="1">
                                                        <?php echo $watched ? '✓ Watched' : 'Mark Watched'; ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="video-wrap">
                                            <iframe src="<?php echo h($video['embed_url']); ?>" title="<?php echo h($video['title']); ?>" allowfullscreen></iframe>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div>
                                <h4>Documents</h4>
                                <?php if (!$topicDocs): ?>
                                    <p class="muted">No documents yet.</p>
                                <?php endif; ?>
                                <?php foreach ($topicDocs as $doc): ?>
                                    <div class="resource-item file-item">
                                        <div>
                                            <strong><?php echo h($doc['title']); ?></strong>
                                            <div class="muted"><?php echo strtoupper(h($doc['file_type'])); ?></div>
                                        </div>
                                        <?php if ($registered || $isStaff): ?>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <a class="btn tiny primary" href="download.php?type=document&id=<?php echo (int)$doc['id']; ?>&view=1" target="_blank">📄 Open</a>
                                                <a class="btn tiny secondary" href="download.php?type=document&id=<?php echo (int)$doc['id']; ?>">⬇ Download</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="panel">
                <h2>Tutorial and Exam Updates</h2>

                <?php if (!$sections): ?>
                    <p class="muted">No tutorial or exam sections have been uploaded yet.</p>
                <?php endif; ?>

                <?php foreach ($sections as $section): ?>
                    <?php $resources = $sectionResources[(int) $section['id']] ?? []; ?>
                    <div class="special-box">
                        <div class="topic-head">
                            <div>
                                <div class="eyebrow"><?php echo h(section_label($section['section_type'])); ?></div>
                                <h3><?php echo h($section['title']); ?></h3>
                            </div>
                        </div>

                        <?php if (!$resources): ?>
                            <p class="muted">No items uploaded yet.</p>
                        <?php endif; ?>

                        <?php foreach ($resources as $res): ?>
                            <div class="resource-item">
                                <div class="resource-item-head">
                                    <strong><?php echo h($res['title']); ?></strong>
                                    <?php if ($res['resource_type'] === 'document' && $res['file_path']): ?>
                                        <?php if ($registered || $isStaff): ?>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <a class="btn tiny primary" href="download.php?type=section&id=<?php echo (int)$res['id']; ?>&view=1" target="_blank">📄 Open</a>
                                                <a class="btn tiny secondary" href="download.php?type=section&id=<?php echo (int)$res['id']; ?>">⬇ Download</a>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge warn">🔒 Enrolled students only</span>
                                        <?php endif; ?>
                                    <?php elseif ($res['resource_type'] === 'video' && $res['embed_url']): ?>
                                        <span class="badge ok">Video</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($res['resource_type'] === 'video' && $res['embed_url']): ?>
                                    <div class="video-wrap">
                                        <iframe src="<?php echo h($res['embed_url']); ?>" title="<?php echo h($res['title']); ?>" allowfullscreen></iframe>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <!-- ══ QUIZ MODAL ════════════════════════════════════════════════════════════ -->
    <style>
        #quiz-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        #quiz-overlay.hidden {
            display: none;
        }

        #quiz-box {
            background: #fff;
            border-radius: 24px;
            width: 100%;
            max-width: 640px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, .22);
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            overflow: hidden;
        }

        #quiz-header {
            background: linear-gradient(135deg, #07a701, #059669);
            color: #fff;
            padding: 20px 24px;
            flex-shrink: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        #quiz-header h2 {
            margin: 0;
            font-size: 18px;
        }

        #quiz-header p {
            margin: 4px 0 0;
            font-size: 13px;
            opacity: .85;
        }

        #quiz-close {
            background: rgba(255, 255, 255, .2);
            border: none;
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #quiz-close:hover {
            background: rgba(255, 255, 255, .35);
        }

        #quiz-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        /* Setup screen */
        #quiz-setup label {
            font-weight: 600;
            font-size: 14px;
            display: block;
            margin-bottom: 6px;
        }

        #quiz-setup select {
            width: 100%;
            margin-bottom: 16px;
        }

        #quiz-start-btn {
            width: 100%;
            font-size: 16px;
            padding: 14px;
        }

        /* Progress bar */
        #quiz-progress-wrap {
            margin-bottom: 20px;
        }

        #quiz-progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
        }

        #quiz-progress-fill {
            height: 100%;
            background: #07a701;
            border-radius: 99px;
            transition: width .3s;
        }

        #quiz-progress-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
            text-align: right;
        }

        /* Question */
        #quiz-question-text {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.45;
            color: #0f172a;
        }

        .quiz-option {
            display: block;
            width: 100%;
            text-align: left;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            margin-bottom: 10px;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: border-color .15s, background .15s;
            color: #0f172a;
        }

        .quiz-option:hover:not(:disabled) {
            border-color: #07a701;
            background: #f0fdf4;
        }

        .quiz-option.correct {
            border-color: #07a701;
            background: #f0fdf4;
            color: #166534;
        }

        .quiz-option.wrong {
            border-color: #dc2626;
            background: #fef2f2;
            color: #991b1b;
        }

        .quiz-option.reveal {
            border-color: #07a701;
            background: #f0fdf4;
            color: #166534;
        }

        .quiz-option:disabled {
            cursor: default;
        }

        #quiz-explanation {
            margin-top: 14px;
            padding: 12px 16px;
            background: #f8fafc;
            border-left: 4px solid #07a701;
            border-radius: 0 10px 10px 0;
            font-size: 13px;
            color: #334155;
            display: none;
        }

        #quiz-next-btn {
            margin-top: 18px;
            width: 100%;
            font-size: 15px;
        }

        /* Results screen */
        #quiz-results {
            text-align: center;
        }

        #quiz-score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            border: 6px solid #07a701;
            color: #07a701;
        }

        #quiz-score-label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
        }

        .quiz-review-item {
            text-align: left;
            margin-bottom: 14px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .quiz-review-q {
            padding: 10px 14px;
            background: #f8fafc;
            font-size: 13px;
            font-weight: 600;
        }

        .quiz-review-ans {
            padding: 10px 14px;
            font-size: 13px;
        }

        .quiz-review-ans.ok {
            background: #f0fdf4;
            color: #166534;
        }

        .quiz-review-ans.fail {
            background: #fef2f2;
            color: #991b1b;
        }

        /* Loading */
        #quiz-loading {
            text-align: center;
            padding: 40px 0;
        }

        .quiz-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e2e8f0;
            border-top-color: #07a701;
            border-radius: 50%;
            animation: spin .8s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <div id="quiz-overlay" class="hidden">
        <div id="quiz-box">
            <div id="quiz-header">
                <div>
                    <h2>📝 Quiz Generator</h2>
                    <p id="quiz-header-sub"><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></p>
                </div>
                <button id="quiz-close">✕</button>
            </div>
            <div id="quiz-body">

                <!-- Setup screen -->
                <div id="quiz-setup">
                    <p style="color:#64748b;margin:0 0 20px;font-size:14px;">
                        The AI will generate 10 multiple choice questions based on your course content and uploaded documents.
                    </p>
                    <label>Quiz scope</label>
                    <select id="quiz-scope">
                        <option value="course">Entire Course</option>
                        <?php foreach ($topics as $t): ?>
                            <option value="week_<?php echo (int)$t['week_number']; ?>">
                                Week <?php echo (int)$t['week_number']; ?> — <?php echo h($t['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn primary" id="quiz-start-btn">Generate Quiz →</button>
                </div>

                <!-- Loading screen -->
                <div id="quiz-loading" style="display:none;">
                    <div class="quiz-spinner"></div>
                    <p style="color:#64748b;font-size:14px;">Generating your quiz…<br>This takes about 10–20 seconds.</p>
                </div>

                <!-- Question screen -->
                <div id="quiz-question" style="display:none;">
                    <div id="quiz-progress-wrap">
                        <div id="quiz-progress-bar">
                            <div id="quiz-progress-fill" style="width:0%"></div>
                        </div>
                        <div id="quiz-progress-label">Question 1 of 10</div>
                    </div>
                    <div id="quiz-question-text"></div>
                    <div id="quiz-options"></div>
                    <div id="quiz-explanation"></div>
                    <button class="btn primary" id="quiz-next-btn" style="display:none;">Next Question →</button>
                </div>

                <!-- Results screen -->
                <div id="quiz-results" style="display:none;">
                    <div id="quiz-score-circle">
                        <span id="quiz-score-num">0</span>
                        <span id="quiz-score-label">/ 10</span>
                    </div>
                    <p id="quiz-score-msg" style="font-size:16px;font-weight:700;margin-bottom:6px;"></p>
                    <p style="color:#64748b;font-size:13px;margin-bottom:24px;">Review the questions you got wrong below:</p>
                    <div id="quiz-review"></div>
                    <button class="btn primary" id="quiz-retry-btn" style="margin-top:8px;width:100%;">Try Another Quiz</button>
                </div>

            </div>
        </div>
    </div>

    <script>
        (function() {
            const COURSE_ID = <?php echo (int)$courseId; ?>;
            const overlay = document.getElementById('quiz-overlay');
            const openBtn = document.getElementById('quiz-open-btn');
            const closeBtn = document.getElementById('quiz-close');
            const scopeSel = document.getElementById('quiz-scope');
            const startBtn = document.getElementById('quiz-start-btn');
            const retryBtn = document.getElementById('quiz-retry-btn');

            const screens = {
                setup: document.getElementById('quiz-setup'),
                loading: document.getElementById('quiz-loading'),
                question: document.getElementById('quiz-question'),
                results: document.getElementById('quiz-results'),
            };

            let questions = [],
                current = 0,
                score = 0,
                wrongOnes = [];

            function show(screen) {
                Object.values(screens).forEach(s => s.style.display = 'none');
                screens[screen].style.display = '';
            }

            if (openBtn) openBtn.addEventListener('click', () => {
                overlay.classList.remove('hidden');
                show('setup');
                questions = [];
                current = 0;
                score = 0;
                wrongOnes = [];
            });
            closeBtn.addEventListener('click', () => overlay.classList.add('hidden'));
            overlay.addEventListener('click', e => {
                if (e.target === overlay) overlay.classList.add('hidden');
            });

            startBtn.addEventListener('click', async () => {
                const scopeVal = scopeSel.value; // 'course' or 'week_2'
                let scope = 'course',
                    weekNumber = 0;
                if (scopeVal.startsWith('week_')) {
                    scope = 'week';
                    weekNumber = parseInt(scopeVal.split('_')[1]);
                }

                show('loading');

                try {
                    const res = await fetch('ai_quiz.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            course_id: COURSE_ID,
                            scope,
                            week_number: weekNumber
                        }),
                    });
                    const data = await res.json();

                    if (data.error) {
                        show('setup');
                        alert('Error: ' + data.error);
                        return;
                    }

                    questions = data.questions;
                    current = 0;
                    score = 0;
                    wrongOnes = [];
                    showQuestion();
                } catch (e) {
                    show('setup');
                    alert('Could not generate quiz. Please try again.');
                }
            });

            function showQuestion() {
                show('question');
                const q = questions[current];
                const pct = (current / questions.length) * 100;

                document.getElementById('quiz-progress-fill').style.width = pct + '%';
                document.getElementById('quiz-progress-label').textContent =
                    `Question ${current + 1} of ${questions.length}`;
                document.getElementById('quiz-question-text').textContent = q.question;
                document.getElementById('quiz-explanation').style.display = 'none';
                document.getElementById('quiz-next-btn').style.display = 'none';

                const optWrap = document.getElementById('quiz-options');
                optWrap.innerHTML = '';
                q.options.forEach((opt, i) => {
                    const btn = document.createElement('button');
                    btn.className = 'quiz-option';
                    btn.textContent = opt;
                    btn.addEventListener('click', () => handleAnswer(btn, opt, q));
                    optWrap.appendChild(btn);
                });
            }

            function handleAnswer(clickedBtn, opt, q) {
                // Disable all options
                document.querySelectorAll('.quiz-option').forEach(b => b.disabled = true);

                const letter = opt.charAt(0).toUpperCase(); // 'A', 'B', 'C', 'D'
                const isCorrect = letter === q.answer;

                if (isCorrect) {
                    clickedBtn.classList.add('correct');
                    score++;
                } else {
                    clickedBtn.classList.add('wrong');
                    wrongOnes.push({
                        q,
                        chosen: opt
                    });
                    // Reveal the correct answer
                    document.querySelectorAll('.quiz-option').forEach(b => {
                        if (b.textContent.charAt(0).toUpperCase() === q.answer) {
                            b.classList.add('reveal');
                        }
                    });
                }

                if (q.explanation) {
                    const exp = document.getElementById('quiz-explanation');
                    exp.textContent = q.explanation;
                    exp.style.display = 'block';
                }

                const nextBtn = document.getElementById('quiz-next-btn');
                nextBtn.style.display = 'block';
                nextBtn.textContent = current + 1 < questions.length ? 'Next Question →' : 'See Results';
            }

            document.getElementById('quiz-next-btn').addEventListener('click', () => {
                current++;
                if (current < questions.length) {
                    showQuestion();
                } else {
                    showResults();
                }
            });

            function showResults() {
                show('results');
                document.getElementById('quiz-progress-fill').style.width = '100%';

                const total = questions.length;
                const pct = Math.round((score / total) * 100);
                document.getElementById('quiz-score-num').textContent = score;
                document.getElementById('quiz-score-label').textContent = `/ ${total}`;

                const circle = document.getElementById('quiz-score-circle');
                circle.style.borderColor = pct >= 70 ? '#07a701' : pct >= 50 ? '#f59e0b' : '#dc2626';
                circle.style.color = pct >= 70 ? '#07a701' : pct >= 50 ? '#f59e0b' : '#dc2626';

                const msgs = pct === 100 ? '🏆 Perfect score!' :
                    pct >= 80 ? '🎉 Excellent work!' :
                    pct >= 60 ? '👍 Good effort!' :
                    pct >= 40 ? '📚 Keep studying!' : '💪 Don\'t give up!';
                document.getElementById('quiz-score-msg').textContent = msgs;

                const review = document.getElementById('quiz-review');
                review.innerHTML = '';
                if (wrongOnes.length === 0) {
                    review.innerHTML = '<p style="color:#16a34a;font-weight:600;">You got everything right! 🎯</p>';
                } else {
                    wrongOnes.forEach(({
                        q,
                        chosen
                    }) => {
                        const correctOpt = q.options.find(o => o.charAt(0).toUpperCase() === q.answer);
                        review.innerHTML += `
                    <div class="quiz-review-item">
                        <div class="quiz-review-q">${escHtml(q.question)}</div>
                        <div class="quiz-review-ans fail">❌ Your answer: ${escHtml(chosen)}</div>
                        <div class="quiz-review-ans ok">✅ Correct: ${escHtml(correctOpt || q.answer)}</div>
                        ${q.explanation ? `<div class="quiz-review-ans" style="background:#f8fafc;color:#475569;">💡 ${escHtml(q.explanation)}</div>` : ''}
                    </div>`;
                    });
                }
            }

            retryBtn.addEventListener('click', () => {
                questions = [];
                current = 0;
                score = 0;
                wrongOnes = [];
                show('setup');
            });

            function escHtml(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
        })();
    </script>

    <!-- ══ AI COURSE ASSISTANT ════════════════════════════════════════════════════ -->
    <style>
        /* ── Chat widget ─────────────────────────────────────────────────────────── */
        #ai-fab {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 1000;
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: linear-gradient(135deg, #07a701, #059669);
            color: #fff;
            border: none;
            cursor: pointer;
            box-shadow: 0 6px 24px rgba(7, 167, 1, .4);
            font-size: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .2s, box-shadow .2s;
        }

        #ai-fab:hover {
            transform: scale(1.08);
            box-shadow: 0 10px 32px rgba(7, 167, 1, .5);
        }

        #ai-panel {
            position: fixed;
            bottom: 100px;
            right: 28px;
            z-index: 1000;
            width: 420px;
            max-width: calc(100vw - 40px);
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, .18);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: opacity .2s, transform .2s;
            max-height: calc(100vh - 140px);
        }

        #ai-panel.hidden {
            opacity: 0;
            pointer-events: none;
            transform: translateY(16px);
        }

        /* ── Panel header ────────────────────────────────────────────────────────── */
        #ai-panel-header {
            background: linear-gradient(135deg, #07a701, #059669);
            color: #fff;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        #ai-panel-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
        }

        #ai-panel-header p {
            margin: 2px 0 0;
            font-size: 11px;
            opacity: .85;
        }

        .ai-header-btns {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .ai-icon-btn {
            background: rgba(255, 255, 255, .2);
            border: none;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .15s;
        }

        .ai-icon-btn:hover {
            background: rgba(255, 255, 255, .35);
        }

        /* ── Sessions sidebar ────────────────────────────────────────────────────── */
        #ai-sessions-panel {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            flex-shrink: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height .25s ease;
        }

        #ai-sessions-panel.open {
            max-height: 220px;
        }

        #ai-sessions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px 6px;
        }

        #ai-sessions-header span {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        #ai-new-chat-btn {
            font-size: 12px;
            font-weight: 700;
            background: #07a701;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 4px 10px;
            cursor: pointer;
        }

        #ai-sessions-list {
            overflow-y: auto;
            max-height: 160px;
            padding: 0 8px 8px;
        }

        .ai-session-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s;
            font-size: 13px;
            color: #334155;
        }

        .ai-session-item:hover {
            background: #e2e8f0;
        }

        .ai-session-item.active {
            background: #dcfce7;
            color: #166534;
            font-weight: 600;
        }

        .ai-session-title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-session-del,
        .ai-session-rename {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 13px;
            padding: 2px 4px;
            border-radius: 4px;
            flex-shrink: 0;
            line-height: 1;
            transition: color .15s;
        }

        .ai-session-del {
            color: #94a3b8;
        }

        .ai-session-del:hover {
            color: #dc2626;
        }

        .ai-session-rename {
            color: #94a3b8;
        }

        .ai-session-rename:hover {
            color: #07a701;
        }

        /* ── Messages area ───────────────────────────────────────────────────────── */
        #ai-messages {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 180px;
        }

        .ai-msg {
            max-width: 88%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.55;
            word-break: break-word;
        }

        .ai-msg.user {
            align-self: flex-end;
            background: #07a701;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .ai-msg.bot {
            align-self: flex-start;
            background: #f1f5f9;
            color: #0f172a;
            border-bottom-left-radius: 4px;
        }

        .ai-msg.bot.thinking {
            color: #64748b;
            font-style: italic;
        }

        /* ── Input row ───────────────────────────────────────────────────────────── */
        #ai-input-row {
            display: flex;
            flex-direction: column;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            flex-shrink: 0;
        }

        #ai-attachment-preview {
            display: none;
            padding: 8px 14px 0;
            gap: 8px;
            flex-wrap: wrap;
        }

        .ai-attach-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #e2e8f0;
            border-radius: 8px;
            padding: 4px 8px 4px 6px;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            max-width: 200px;
        }

        .ai-attach-chip img {
            width: 32px;
            height: 32px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .ai-attach-chip .chip-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        .ai-attach-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            font-size: 14px;
            padding: 0 2px;
            line-height: 1;
            flex-shrink: 0;
        }

        .ai-attach-remove:hover {
            color: #dc2626;
        }

        #ai-input-bottom {
            display: flex;
            gap: 8px;
            padding: 10px 14px;
            align-items: flex-end;
        }

        #ai-input {
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            resize: none;
            min-height: 40px;
            max-height: 100px;
            transition: border-color .15s;
        }

        #ai-input:focus {
            border-color: #07a701;
        }

        #ai-attach-btn {
            background: #f1f5f9;
            color: #64748b;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 18px;
            flex-shrink: 0;
            transition: background .15s;
            align-self: flex-end;
        }

        #ai-attach-btn:hover {
            background: #e2e8f0;
            color: #07a701;
        }

        #ai-send-btn {
            background: #07a701;
            color: #fff;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 18px;
            flex-shrink: 0;
            transition: background .15s;
            align-self: flex-end;
        }

        #ai-send-btn:hover {
            background: #059669;
        }

        #ai-send-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
    </style>

    <button id="ai-fab" title="Ask AI about this course" aria-label="Open AI assistant">🤖</button>

    <div id="ai-panel" class="hidden">
        <div id="ai-panel-header">
            <div>
                <h3>🤖 Course Assistant</h3>
                <p id="ai-session-label"><?php echo h($course['code']); ?> — Ask me anything</p>
            </div>
            <div class="ai-header-btns">
                <button class="ai-icon-btn" id="ai-history-btn" title="Chat history">🕐</button>
                <button class="ai-icon-btn" id="ai-close-btn" aria-label="Close">✕</button>
            </div>
        </div>

        <div id="ai-sessions-panel">
            <div id="ai-sessions-header">
                <span>Your Chats</span>
                <button id="ai-new-chat-btn">+ New Chat</button>
            </div>
            <div id="ai-sessions-list">
                <div style="text-align:center;padding:16px;color:#94a3b8;font-size:13px;">Loading…</div>
            </div>
        </div>

        <div id="ai-messages"></div>

        <div id="ai-input-row">
            <!-- Attachment preview chips appear here -->
            <div id="ai-attachment-preview"></div>
            <!-- Bottom row: attach + textarea + send -->
            <div id="ai-input-bottom">
                <button id="ai-attach-btn" title="Attach image or document">📎</button>
                <input type="file" id="ai-file-input" style="display:none"
                    accept="image/*,.pdf,.doc,.docx,.txt">
                <textarea id="ai-input" placeholder="Ask a question about this course…" rows="1"></textarea>
                <button id="ai-send-btn" title="Send">➤</button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const COURSE_ID = <?php echo (int)$courseId; ?>;
            const FIRST_NAME = <?php echo json_encode(explode(' ', $user['full_name'])[0]); ?>;
            const fab = document.getElementById('ai-fab');
            const panel = document.getElementById('ai-panel');
            const closeBtn = document.getElementById('ai-close-btn');
            const historyBtn = document.getElementById('ai-history-btn');
            const sessionsDiv = document.getElementById('ai-sessions-panel');
            const sessionsList = document.getElementById('ai-sessions-list');
            const newChatBtn = document.getElementById('ai-new-chat-btn');
            const messages = document.getElementById('ai-messages');
            const input = document.getElementById('ai-input');
            const sendBtn = document.getElementById('ai-send-btn');
            const sessionLabel = document.getElementById('ai-session-label');
            const attachBtn = document.getElementById('ai-attach-btn');
            const fileInput = document.getElementById('ai-file-input');
            const attachPrev = document.getElementById('ai-attachment-preview');

            let currentSessionId = null;
            let pendingAttachments = []; // [{name, type, b64, isImage}]

            // ── Attachment button ─────────────────────────────────────────────────────
            attachBtn.addEventListener('click', () => fileInput.click());

            fileInput.addEventListener('change', () => {
                const file = fileInput.files[0];
                if (!file) return;
                fileInput.value = '';
                const isImage = file.type.startsWith('image/');
                const maxMB = isImage ? 5 : 10;
                if (file.size > maxMB * 1024 * 1024) {
                    alert(`File too large. Max ${maxMB}MB for ${isImage ? 'images' : 'documents'}.`);
                    return;
                }
                const reader = new FileReader();
                reader.onload = e => {
                    pendingAttachments.push({
                        name: file.name,
                        type: file.type,
                        b64: e.target.result,
                        isImage
                    });
                    renderAttachPreviews();
                };
                reader.readAsDataURL(file);
            });

            function renderAttachPreviews() {
                if (!pendingAttachments.length) {
                    attachPrev.style.display = 'none';
                    attachPrev.innerHTML = '';
                    return;
                }
                attachPrev.style.display = 'flex';
                attachPrev.innerHTML = pendingAttachments.map((a, i) => {
                    const icon = a.isImage ?
                        `<img src="${a.b64}" alt="${escHtml(a.name)}">` :
                        `<span style="font-size:20px;">${docIcon(a.name)}</span>`;
                    return `<div class="ai-attach-chip">${icon}
                <span class="chip-name" title="${escHtml(a.name)}">${escHtml(a.name)}</span>
                <button class="ai-attach-remove" onclick="window._removeAttach(${i})" title="Remove">✕</button>
            </div>`;
                }).join('');
            }

            window._removeAttach = i => {
                pendingAttachments.splice(i, 1);
                renderAttachPreviews();
            };

            function docIcon(name) {
                const ext = (name.split('.').pop() || '').toLowerCase();
                return {
                    pdf: '📄',
                    doc: '📝',
                    docx: '📝',
                    txt: '📃'
                } [ext] || '📁';
            }

            // ── Open/close panel ──────────────────────────────────────────────────────
            fab.addEventListener('click', () => {
                panel.classList.toggle('hidden');
                if (!panel.classList.contains('hidden')) {
                    if (!currentSessionId) initNewChat(true);
                    input.focus();
                }
            });
            closeBtn.addEventListener('click', () => {
                panel.classList.add('hidden');
                sessionsDiv.classList.remove('open');
            });

            // ── History toggle ─────────────────────────────────────────────────────────
            historyBtn.addEventListener('click', () => {
                const isOpen = sessionsDiv.classList.toggle('open');
                if (isOpen) loadSessions();
            });

            // ── New chat ───────────────────────────────────────────────────────────────
            newChatBtn.addEventListener('click', () => {
                sessionsDiv.classList.remove('open');
                initNewChat(false);
            });

            function initNewChat(isFirst) {
                currentSessionId = null;
                messages.innerHTML = '';
                sessionLabel.textContent = `${<?php echo json_encode($course['code']); ?>} — Ask me anything`;
                if (isFirst) {
                    addMessage(`Hi ${FIRST_NAME}! 👋 I'm your AI assistant for <strong><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></strong>. Ask me about the topics, documents, or anything in this course. I can also read the actual uploaded materials!`, 'bot');
                } else {
                    addMessage('New chat started! What would you like to know?', 'bot');
                }
            }

            // ── Load sessions list ─────────────────────────────────────────────────────
            async function loadSessions() {
                sessionsList.innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;font-size:13px;">Loading…</div>';
                try {
                    const res = await fetch('ai_chat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'get_sessions',
                            course_id: COURSE_ID
                        })
                    });
                    const data = await res.json();
                    renderSessions(data.sessions || []);
                } catch (e) {
                    sessionsList.innerHTML = '<div style="text-align:center;padding:12px;color:#dc2626;font-size:13px;">Could not load chats.</div>';
                }
            }

            function renderSessions(sessions) {
                if (!sessions.length) {
                    sessionsList.innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;font-size:13px;">No previous chats yet.</div>';
                    return;
                }
                sessionsList.innerHTML = '';
                sessions.forEach(s => {
                    const div = document.createElement('div');
                    div.className = 'ai-session-item' + (s.id == currentSessionId ? ' active' : '');
                    div.setAttribute('data-sid', s.id);
                    div.innerHTML = `
                <span class="ai-session-title" title="${escHtml(s.title)}">${escHtml(s.title)}</span>
                <div style="display:flex;gap:2px;flex-shrink:0;">
                    <button class="ai-session-rename" data-id="${s.id}" data-title="${escHtml(s.title)}" title="Rename">✏️</button>
                    <button class="ai-session-del" data-id="${s.id}" title="Delete">🗑</button>
                </div>`;

                    // Click to load session
                    div.addEventListener('click', e => {
                        if (e.target.closest('.ai-session-rename,.ai-session-del')) return;
                        loadSession(s.id, s.title);
                        sessionsDiv.classList.remove('open');
                    });

                    // Rename button
                    div.querySelector('.ai-session-rename').addEventListener('click', async e => {
                        e.stopPropagation();
                        const btn = e.currentTarget;
                        const currentTitle = btn.dataset.title;
                        const titleSpan = div.querySelector('.ai-session-title');

                        // Replace title span with inline input
                        titleSpan.style.display = 'none';
                        const inputEl = document.createElement('input');
                        inputEl.type = 'text';
                        inputEl.value = currentTitle;
                        inputEl.style.cssText = 'flex:1;font-size:13px;padding:2px 6px;border:1px solid #07a701;border-radius:6px;outline:none;';
                        div.insertBefore(inputEl, titleSpan);
                        inputEl.focus();
                        inputEl.select();

                        const saveRename = async () => {
                            const newTitle = inputEl.value.trim();
                            inputEl.remove();
                            titleSpan.style.display = '';
                            if (!newTitle || newTitle === currentTitle) return;
                            const res = await fetch('ai_chat.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    action: 'rename_session',
                                    session_id: s.id,
                                    title: newTitle
                                })
                            });
                            const data = await res.json();
                            if (data.ok) {
                                titleSpan.textContent = data.title;
                                titleSpan.title = data.title;
                                btn.dataset.title = data.title;
                                s.title = data.title;
                                if (s.id == currentSessionId) sessionLabel.textContent = data.title;
                            }
                        };
                        inputEl.addEventListener('blur', saveRename);
                        inputEl.addEventListener('keydown', e => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                saveRename();
                            }
                            if (e.key === 'Escape') {
                                inputEl.value = currentTitle;
                                inputEl.blur();
                            }
                        });
                    });

                    // Delete button
                    div.querySelector('.ai-session-del').addEventListener('click', async e => {
                        e.stopPropagation();
                        if (!confirm('Delete this chat?')) return;
                        await fetch('ai_chat.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                action: 'delete_session',
                                session_id: s.id
                            })
                        });
                        if (s.id == currentSessionId) initNewChat(false);
                        loadSessions();
                    });

                    sessionsList.appendChild(div);
                });
            }

            // ── Load a previous session ────────────────────────────────────────────────
            async function loadSession(id, title) {
                currentSessionId = id;
                messages.innerHTML = '';
                sessionLabel.textContent = title;
                try {
                    const res = await fetch('ai_chat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'get_messages',
                            session_id: id
                        })
                    });
                    const data = await res.json();
                    if (data.messages && data.messages.length) {
                        data.messages.forEach(m => addMessage(m.content, m.role === 'user' ? 'user' : 'bot'));
                    } else {
                        addMessage('No messages in this chat yet.', 'bot');
                    }
                } catch (e) {
                    addMessage('Could not load this chat.', 'bot');
                }
            }

            // ── Textarea auto-resize ───────────────────────────────────────────────────
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 100) + 'px';
            });

            input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            sendBtn.addEventListener('click', sendMessage);

            function addMessage(text, role) {
                const div = document.createElement('div');
                div.className = 'ai-msg ' + role;
                div.innerHTML = text
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\n/g, '<br>');
                messages.appendChild(div);
                messages.scrollTop = messages.scrollHeight;
                return div;
            }

            async function sendMessage() {
                const text = input.value.trim();
                if ((!text && !pendingAttachments.length) || sendBtn.disabled) return;

                // Show user message with attachment previews
                let userHtml = text ? escHtml(text) : '';
                if (pendingAttachments.length) {
                    const previews = pendingAttachments.map(a => a.isImage ?
                        `<img src="${a.b64}" style="max-width:100%;max-height:140px;border-radius:8px;display:block;margin-top:6px;">` :
                        `<div style="background:rgba(255,255,255,.2);border-radius:8px;padding:6px 10px;margin-top:6px;font-size:12px;">${docIcon(a.name)} ${escHtml(a.name)}</div>`
                    ).join('');
                    userHtml += previews;
                }
                const userDiv = document.createElement('div');
                userDiv.className = 'ai-msg user';
                userDiv.innerHTML = userHtml;
                messages.appendChild(userDiv);
                messages.scrollTop = messages.scrollHeight;

                // Capture attachments before clearing
                const attachmentsToSend = [...pendingAttachments];
                pendingAttachments = [];
                renderAttachPreviews();

                input.value = '';
                input.style.height = 'auto';
                sendBtn.disabled = true;

                const thinking = addMessage('Thinking…', 'bot thinking');

                try {
                    const res = await fetch('ai_chat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'send',
                            message: text || '(See attached file)',
                            course_id: COURSE_ID,
                            session_id: currentSessionId,
                            attachments: attachmentsToSend.map(a => ({
                                name: a.name,
                                type: a.type,
                                b64: a.b64,
                                isImage: a.isImage,
                            })),
                        })
                    });
                    const data = await res.json();
                    thinking.remove();

                    if (data.reply) {
                        if (data.session_id) {
                            currentSessionId = data.session_id;
                            if (sessionLabel.textContent === `${<?php echo json_encode($course['code']); ?>} — Ask me anything`) {
                                sessionLabel.textContent = text.length > 40 ? text.slice(0, 37) + '…' : text;
                            }
                        }
                        addMessage(data.reply, 'bot');
                    } else {
                        addMessage('Sorry, something went wrong: ' + (data.error || 'Unknown error'), 'bot');
                    }
                } catch (e) {
                    thinking.remove();
                    addMessage('Could not reach the AI assistant. Please try again.', 'bot');
                } finally {
                    sendBtn.disabled = false;
                    input.focus();
                }
            }

            function escHtml(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            // Init welcome message on first load
            initNewChat(true);
        })();
    </script>

</body>