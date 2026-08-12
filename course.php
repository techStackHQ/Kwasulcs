<?php
require_once __DIR__ . '/config.php';
require_login();
ensure_topic_overview_column();
ensure_announcements_table();

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

$progress = course_progress_for($user, $topics, $videosByTopic, $progressMap, $docsByTopic);

// ── Last-updated + (staff only) enrolled-student count for the stats card ────
$courseStats  = course_content_stats([$courseId]);
$lastUpdated  = $courseStats[$courseId]['updated_at'] ?? null;
$enrolledCount = 0;
if ($isStaff) {
    $encStmt = db()->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = ?');
    $encStmt->execute([$courseId]);
    $enrolledCount = (int) $encStmt->fetchColumn();
}

// ── Section resources, split into the Tutorial Videos / Resources tabs ───────
// (the existing tutorial_update/exam_update upload flow in admin.php already
// produces exactly this content — just re-presented as two flat lists here
// instead of one combined "Tutorial and Exam Updates" panel).
$tutorialVideos = [];
$resourceDocs   = [];
if ($sections) {
    $sectionIds = array_column($sections, 'id');
    $in         = implode(',', array_fill(0, count($sectionIds), '?'));
    $srStmt     = db()->prepare("SELECT * FROM section_resources WHERE section_id IN ($in) ORDER BY id DESC");
    $srStmt->execute($sectionIds);
    foreach ($srStmt->fetchAll() as $res) {
        if ($res['resource_type'] === 'video' && $res['embed_url']) {
            $tutorialVideos[] = $res;
        } elseif ($res['resource_type'] === 'document' && $res['file_path']) {
            $resourceDocs[] = $res;
        }
    }
}

// ── Announcements ──────────────────────────────────────────────────────────
$annStmt = db()->prepare('
    SELECT a.*, u.full_name AS author_name
    FROM course_announcements a
    JOIN users u ON u.id = a.created_by
    WHERE a.course_id = ?
    ORDER BY a.created_at DESC
    LIMIT 20
');
$annStmt->execute([$courseId]);
$announcements = $annStmt->fetchAll();

function section_label(string $type): string
{
    return $type === 'tutorial_update' ? 'Tutorial Update' : 'Exam Update';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = $course['code'] . ' - ' . $course['title'];
    $extraCss = ['assets/course.css'];
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>
    <header class="topbar">
        <div>
            <div class="eyebrow">Course</div>
            <h1><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></h1>
            <p class="muted"><?php echo strtoupper(h($course['semester'])); ?> • Lecturer: <?php echo h($course['lecturer_name']); ?></p>
        </div>
        <div class="topbar-actions">
            <?php if ($registered || $isStaff): ?>
                <a class="btn glass" href="quiz.php?course=<?php echo (int) $courseId; ?>"><i class="bi bi-bullseye icon"></i> Take Quiz</a>
            <?php endif; ?>
            <a class="btn glass" href="calendar.php?course=<?php echo (int) $courseId; ?>"><i class="bi bi-calendar3 icon"></i> Course Calendar</a>
            <a class="btn glass btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
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

        <?php if ($topics): ?>
            <div class="course-progress-card">
                <div class="course-stat">
                    <span class="course-stat-value course-stat-value--pct" style="--pct:<?php echo $progress['percent']; ?>"><?php echo $progress['percent']; ?>%</span>
                    <span class="course-stat-label">Progress</span>
                </div>
                <div class="course-stat-sep"></div>
                <?php if ($progress['is_staff_view']): ?>
                    <div class="course-stat">
                        <span class="course-stat-value"><?php echo $progress['completed_weeks']; ?> / <?php echo $progress['total_weeks']; ?></span>
                        <span class="course-stat-label">Weeks With Content</span>
                    </div>
                    <div class="course-stat-sep"></div>
                    <div class="course-stat">
                        <span class="course-stat-value"><?php echo $enrolledCount; ?></span>
                        <span class="course-stat-label">Enrolled Students</span>
                    </div>
                <?php else: ?>
                    <div class="course-stat">
                        <span class="course-stat-value"><?php echo $progress['completed_weeks']; ?> / <?php echo $progress['total_weeks']; ?></span>
                        <span class="course-stat-label">Completed Weeks</span>
                    </div>
                    <div class="course-stat-sep"></div>
                    <div class="course-stat">
                        <span class="course-stat-value">Week <?php echo $progress['current_week']; ?></span>
                        <span class="course-stat-label">Current Week</span>
                    </div>
                <?php endif; ?>
                <div class="course-stat-sep"></div>
                <div class="course-stat">
                    <span class="course-stat-value"><?php echo $lastUpdated ? h(content_time_ago($lastUpdated)) : 'No content yet'; ?></span>
                    <span class="course-stat-label">Last Updated</span>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['announced'])): ?>
            <div class="alert success">Announcement posted.</div>
        <?php endif; ?>

        <div class="course-detail-layout">
            <!-- ══ LEFT: Weekly Topics accordion ══════════════════════════════════ -->
            <div class="panel weekly-topics-panel">
                <h2>Weekly Topics</h2>
                <?php if (!$topics): ?>
                    <div class="course-empty-state">
                        <i class="bi bi-calendar2-week"></i>
                        <p>No weekly materials yet.</p>
                        <span class="muted">Your lecturer hasn't published any weeks for this course.</span>
                    </div>
                <?php endif; ?>

                <div class="accordion" id="weeklyAccordion">
                    <?php foreach ($topics as $wi => $topic):
                        $tid          = (int) $topic['id'];
                        $isBookmarked = isset($bookmarkMap[$tid]);
                        $topicVideos  = $videosByTopic[$tid] ?? [];
                        $topicDocs    = $docsByTopic[$tid] ?? [];
                        $hasContent   = $topicVideos || $topicDocs;
                        $isOpen       = $wi === 0;

                        $videosJson = json_encode(array_map(fn($v) => [
                            'id'      => (int) $v['id'],
                            'title'   => $v['title'],
                            'embed'   => $v['embed_url'],
                            'watched' => !empty($progressMap[(int) $v['id']]),
                        ], $topicVideos));
                    ?>
                        <div class="accordion-item<?php echo $isOpen ? ' open' : ''; ?>">
                            <button type="button" class="accordion-header" aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>">
                                <span class="accordion-week-num"><?php echo (int) $topic['week_number']; ?></span>
                                <span class="accordion-title-wrap">
                                    <span class="accordion-eyebrow">WEEK <?php echo (int) $topic['week_number']; ?></span>
                                    <span class="accordion-title"><?php echo h($topic['title']); ?></span>
                                </span>
                                <?php if ($isStudent && $registered): ?>
                                    <span class="accordion-bookmark-btn" data-topic-id="<?php echo $tid; ?>" title="<?php echo $isBookmarked ? 'Bookmarked' : 'Bookmark this week'; ?>">
                                        <i class="bi <?php echo $isBookmarked ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                    </span>
                                <?php endif; ?>
                                <i class="bi bi-chevron-down accordion-chevron"></i>
                            </button>
                            <div class="accordion-panel" <?php echo $isOpen ? '' : 'hidden'; ?> data-week-videos='<?php echo h($videosJson); ?>'>
                                <?php if (!empty($topic['overview'])): ?>
                                    <p class="accordion-overview"><?php echo h($topic['overview']); ?></p>
                                <?php endif; ?>

                                <div class="week-columns">
                                    <div class="week-col">
                                        <div class="week-col-head"><i class="bi bi-play-circle-fill"></i> Videos (<?php echo count($topicVideos); ?>)</div>
                                        <?php if (!$topicVideos): ?>
                                            <p class="muted week-col-empty">No videos yet.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($topicVideos, 0, 4) as $video): ?>
                                                <button type="button" class="video-list-item" data-embed="<?php echo h($video['embed_url']); ?>" data-title="<?php echo h($video['title']); ?>" data-video-id="<?php echo (int) $video['id']; ?>">
                                                    <i class="bi bi-play-circle-fill"></i>
                                                    <span class="video-list-item-title"><?php echo h($video['title']); ?></span>
                                                    <?php if ($isStudent && $registered && !empty($progressMap[(int) $video['id']])): ?>
                                                        <i class="bi bi-check-circle-fill video-watched-tick" title="Watched"></i>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endforeach; ?>
                                            <?php if (count($topicVideos) > 4): ?>
                                                <button type="button" class="view-all-link view-all-videos-btn">View all videos <i class="bi bi-arrow-right"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="week-col">
                                        <div class="week-col-head"><i class="bi bi-file-earmark-text-fill"></i> Documents (<?php echo count($topicDocs); ?>)</div>
                                        <?php if (!$topicDocs): ?>
                                            <p class="muted week-col-empty">No documents yet.</p>
                                        <?php elseif (!($registered || $isStaff)): ?>
                                            <p class="muted week-col-empty"><i class="bi bi-lock-fill"></i> Enrolled students only</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($topicDocs, 0, 4) as $doc): ?>
                                                <div class="doc-list-row">
                                                    <?php echo course_render_document(
                                                        'download.php?type=document&id=' . (int) $doc['id'] . '&view=1',
                                                        $doc['title'],
                                                        $doc['file_type'],
                                                        'download.php?type=document&id=' . (int) $doc['id'] . '&stream=1'
                                                    ); ?>
                                                    <a class="doc-download-btn" href="download.php?type=document&id=<?php echo (int) $doc['id']; ?>" title="Download"><i class="bi bi-download"></i></a>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($topicDocs) > 4): ?>
                                                <button type="button" class="view-all-link view-all-docs-btn">View all documents <i class="bi bi-arrow-right"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($hasContent && ($registered || $isStaff)): ?>
                                        <div class="week-col week-quiz-col">
                                            <div class="week-col-head"><i class="bi bi-award-fill"></i> Quiz</div>
                                            <div class="week-quiz-card">
                                                <div class="week-quiz-icon"><i class="bi bi-clipboard-check-fill"></i></div>
                                                <strong>Weekly Quiz <?php echo (int) $topic['week_number']; ?></strong>
                                                <span class="muted">AI-generated practice quiz</span>
                                                <a href="quiz.php?course=<?php echo (int) $courseId; ?>&amp;topic=<?php echo (int) $topic['id']; ?>" class="btn primary tiny week-quiz-btn">Take Quiz <i class="bi bi-arrow-right"></i></a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Hidden document list for the "View all documents" modal (docs beyond the first 4 aren't otherwise in the DOM). -->
                                <div class="week-all-docs" hidden>
                                    <?php foreach ($topicDocs as $doc): ?>
                                        <div class="doc-list-row">
                                            <?php echo course_render_document(
                                                'download.php?type=document&id=' . (int) $doc['id'] . '&view=1',
                                                $doc['title'],
                                                $doc['file_type'],
                                                'download.php?type=document&id=' . (int) $doc['id'] . '&stream=1'
                                            ); ?>
                                            <a class="doc-download-btn" href="download.php?type=document&id=<?php echo (int) $doc['id']; ?>" title="Download"><i class="bi bi-download"></i></a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ══ RIGHT: Announcements & Tutorials ═══════════════════════════════ -->
            <div class="panel announcements-panel">
                <h2>Announcements &amp; Tutorials</h2>
                <div class="ann-tabs" role="tablist">
                    <button type="button" class="ann-tab active" data-tab="announcements">Announcements</button>
                    <button type="button" class="ann-tab" data-tab="tutorials">Tutorial Videos</button>
                    <button type="button" class="ann-tab" data-tab="resources">Resources</button>
                </div>

                <!-- ── Announcements tab ──────────────────────────────────────────── -->
                <div class="ann-tab-panel" data-panel="announcements">
                    <?php if ($isStaff): ?>
                        <button type="button" class="ann-new-btn" id="annNewBtn"><i class="bi bi-plus-lg"></i> New Announcement</button>
                        <form method="post" action="announcement_post.php" class="ann-new-form" id="annNewForm" hidden>
                            <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                            <input type="text" name="title" placeholder="Announcement title" required maxlength="200">
                            <textarea name="body" rows="3" placeholder="Write the announcement…" required></textarea>
                            <div class="ann-new-form-actions">
                                <button type="button" class="btn tiny secondary" id="annCancelBtn">Cancel</button>
                                <button type="submit" class="btn tiny primary">Post</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (!$announcements): ?>
                        <div class="course-empty-state">
                            <i class="bi bi-megaphone"></i>
                            <p>No announcements have been posted yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="ann-list">
                            <?php foreach (array_slice($announcements, 0, 5) as $ann): ?>
                                <div class="ann-item">
                                    <span class="ann-item-icon"><i class="bi bi-megaphone-fill"></i></span>
                                    <div class="ann-item-body">
                                        <strong><?php echo h($ann['title']); ?></strong>
                                        <p><?php echo h(mb_strimwidth($ann['body'], 0, 120, '…')); ?></p>
                                        <span class="ann-item-time"><?php echo h(content_time_ago($ann['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($announcements) > 5): ?>
                            <button type="button" class="view-all-link view-all-ann-btn">View all announcements <i class="bi bi-arrow-right"></i></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- ── Tutorial Videos tab ────────────────────────────────────────── -->
                <div class="ann-tab-panel" data-panel="tutorials" hidden>
                    <?php if (!$tutorialVideos): ?>
                        <div class="course-empty-state">
                            <i class="bi bi-camera-reels"></i>
                            <p>No tutorial videos available.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($tutorialVideos, 0, 4) as $tv):
                            $ytId = youtube_id((string) $tv['original_url']);
                        ?>
                            <button type="button" class="tutorial-video-item video-list-item" data-embed="<?php echo h($tv['embed_url']); ?>" data-title="<?php echo h($tv['title']); ?>">
                                <span class="tutorial-video-thumb">
                                    <?php if ($ytId): ?>
                                        <img src="https://img.youtube.com/vi/<?php echo h($ytId); ?>/mqdefault.jpg" alt="" loading="lazy">
                                    <?php endif; ?>
                                    <i class="bi bi-play-circle-fill"></i>
                                </span>
                                <span class="tutorial-video-info">
                                    <strong><?php echo h($tv['title']); ?></strong>
                                    <span class="muted"><?php echo h(content_time_ago($tv['created_at'])); ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (count($tutorialVideos) > 4): ?>
                            <button type="button" class="view-all-link view-all-tutorials-btn">View all tutorial videos <i class="bi bi-arrow-right"></i></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- ── Resources tab ──────────────────────────────────────────────── -->
                <div class="ann-tab-panel" data-panel="resources" hidden>
                    <?php if (!$resourceDocs): ?>
                        <div class="course-empty-state">
                            <i class="bi bi-folder2-open"></i>
                            <p>No resources uploaded.</p>
                        </div>
                    <?php elseif (!($registered || $isStaff)): ?>
                        <div class="course-empty-state">
                            <i class="bi bi-lock-fill"></i>
                            <p>Enrolled students only.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($resourceDocs as $res): ?>
                            <div class="doc-list-row">
                                <?php echo course_render_document(
                                    'download.php?type=section&id=' . (int) $res['id'] . '&view=1',
                                    $res['title'],
                                    $res['file_type'],
                                    'download.php?type=section&id=' . (int) $res['id'] . '&stream=1'
                                ); ?>
                                <a class="doc-download-btn" href="download.php?type=section&id=<?php echo (int) $res['id']; ?>" title="Download"><i class="bi bi-download"></i></a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══ Video player modal ══════════════════════════════════════════════ -->
        <div class="media-modal-overlay" id="videoPlayerModal" hidden>
            <div class="media-modal video-modal">
                <div class="media-modal-head">
                    <strong id="videoPlayerTitle"></strong>
                    <div class="media-modal-actions">
                        <button type="button" class="media-modal-btn" id="videoCloseBtn" title="Close"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="video-modal-body">
                    <!-- allowfullscreen + the allow list below give the embedded
                         YouTube player its own native fullscreen control — no
                         custom "theatre mode" button needed on top of it. -->
                    <iframe id="videoPlayerFrame" src="" title="Lecture video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
            </div>
        </div>

        <!-- ══ "View all" list modal (videos / documents / announcements / tutorials) ═ -->
        <div class="media-modal-overlay" id="listModal" hidden>
            <div class="media-modal list-modal">
                <div class="media-modal-head">
                    <strong id="listModalTitle"></strong>
                    <div class="media-modal-actions">
                        <button type="button" class="media-modal-btn" id="listModalCloseBtn" title="Close"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="list-modal-body" id="listModalBody"></div>
            </div>
        </div>

        <script>
            (function() {
                const COURSE_ID = <?php echo (int) $courseId; ?>;
                const IS_STUDENT = <?php echo $isStudent ? 'true' : 'false'; ?>;
                const REGISTERED = <?php echo $registered ? 'true' : 'false'; ?>;

                // ── Accordion ────────────────────────────────────────────────────────
                document.querySelectorAll('.accordion-header').forEach(function(header) {
                    header.addEventListener('click', function() {
                        const item = header.closest('.accordion-item');
                        const panel = item.querySelector('.accordion-panel');
                        const wasOpen = item.classList.contains('open');
                        document.querySelectorAll('.accordion-item.open').forEach(function(openItem) {
                            openItem.classList.remove('open');
                            openItem.querySelector('.accordion-header').setAttribute('aria-expanded', 'false');
                            openItem.querySelector('.accordion-panel').hidden = true;
                        });
                        if (!wasOpen) {
                            item.classList.add('open');
                            header.setAttribute('aria-expanded', 'true');
                            panel.hidden = false;
                        }
                    });
                });

                // ── Bookmark toggle (AJAX, no page reload) ──────────────────────────
                document.querySelectorAll('.accordion-bookmark-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const fd = new FormData();
                        fd.append('topic_id', btn.dataset.topicId);
                        fd.append('course_id', COURSE_ID);
                        fd.append('ajax', '1');
                        fetch('bookmark.php', {
                            method: 'POST',
                            body: fd
                        }).catch(() => {});
                        const icon = btn.querySelector('i');
                        const nowBookmarked = icon.classList.contains('bi-star');
                        icon.classList.toggle('bi-star', !nowBookmarked);
                        icon.classList.toggle('bi-star-fill', nowBookmarked);
                        btn.title = nowBookmarked ? 'Bookmarked' : 'Bookmark this week';
                    });
                });

                // ── Video player modal ───────────────────────────────────────────────
                const videoModal = document.getElementById('videoPlayerModal');
                const videoFrame = document.getElementById('videoPlayerFrame');
                const videoTitle = document.getElementById('videoPlayerTitle');
                let lastScrollY = 0;

                function openVideo(embed, title, videoId) {
                    lastScrollY = window.scrollY;
                    videoFrame.src = embed + (embed.includes('?') ? '&' : '?') + 'autoplay=1';
                    videoTitle.textContent = title;
                    videoModal.hidden = false;
                    document.body.classList.add('drawer-open');
                    if (IS_STUDENT && REGISTERED && videoId) {
                        const fd = new FormData();
                        fd.append('course_id', COURSE_ID);
                        fd.append('video_id', videoId);
                        fd.append('watched', '1');
                        fetch('progress.php', {
                            method: 'POST',
                            body: fd
                        }).catch(() => {});
                    }
                }

                function closeVideo() {
                    videoFrame.src = '';
                    videoModal.hidden = true;
                    document.body.classList.remove('drawer-open');
                    window.scrollTo({
                        top: lastScrollY
                    });
                }

                document.addEventListener('click', function(e) {
                    const item = e.target.closest('.video-list-item');
                    if (!item || !item.dataset.embed) return;
                    openVideo(item.dataset.embed, item.dataset.title || '', item.dataset.videoId);
                });
                document.getElementById('videoCloseBtn').addEventListener('click', closeVideo);
                videoModal.addEventListener('click', function(e) {
                    if (e.target === videoModal) closeVideo();
                });

                // ── "View all" list modal ────────────────────────────────────────────
                const listModal = document.getElementById('listModal');
                const listModalTitle = document.getElementById('listModalTitle');
                const listModalBody = document.getElementById('listModalBody');

                function openListModal(title, html) {
                    listModalTitle.textContent = title;
                    listModalBody.innerHTML = html;
                    listModal.hidden = false;
                    document.body.classList.add('drawer-open');
                }

                function closeListModal() {
                    listModal.hidden = true;
                    document.body.classList.remove('drawer-open');
                }
                document.getElementById('listModalCloseBtn').addEventListener('click', closeListModal);
                listModal.addEventListener('click', function(e) {
                    if (e.target === listModal) closeListModal();
                });

                function escHtml(str) {
                    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }

                document.querySelectorAll('.view-all-videos-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const panel = btn.closest('.accordion-panel');
                        const videos = JSON.parse(panel.dataset.weekVideos || '[]');
                        const html = videos.map(function(v) {
                            return '<button type="button" class="video-list-item" data-embed="' + escHtml(v.embed) + '" data-title="' + escHtml(v.title) + '" data-video-id="' + v.id + '">' +
                                '<i class="bi bi-play-circle-fill"></i><span class="video-list-item-title">' + escHtml(v.title) + '</span>' +
                                (v.watched ? '<i class="bi bi-check-circle-fill video-watched-tick" title="Watched"></i>' : '') +
                                '</button>';
                        }).join('');
                        openListModal('All Videos', html || '<p class="muted">No videos yet.</p>');
                    });
                });

                document.querySelectorAll('.view-all-docs-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const panel = btn.closest('.accordion-panel');
                        const allDocs = panel.querySelector('.week-all-docs');
                        openListModal('All Documents', allDocs ? allDocs.innerHTML : '<p class="muted">No documents yet.</p>');
                    });
                });

                const annViewAllBtn = document.querySelector('.view-all-ann-btn');
                if (annViewAllBtn) {
                    annViewAllBtn.addEventListener('click', function() {
                        window.location.href = 'notifications.php';
                    });
                }

                // ── Announcements & Tutorials tabs ───────────────────────────────────
                document.querySelectorAll('.ann-tab').forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        document.querySelectorAll('.ann-tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.ann-tab-panel').forEach(p => p.hidden = true);
                        tab.classList.add('active');
                        document.querySelector('.ann-tab-panel[data-panel="' + tab.dataset.tab + '"]').hidden = false;
                    });
                });

                // ── New announcement inline form (staff only) ────────────────────────
                const annNewBtn = document.getElementById('annNewBtn');
                const annNewForm = document.getElementById('annNewForm');
                const annCancelBtn = document.getElementById('annCancelBtn');
                if (annNewBtn) {
                    annNewBtn.addEventListener('click', function() {
                        annNewForm.hidden = false;
                        annNewBtn.hidden = true;
                    });
                }
                if (annCancelBtn) {
                    annCancelBtn.addEventListener('click', function() {
                        annNewForm.hidden = true;
                        annNewBtn.hidden = false;
                    });
                }
            })();
        </script>
    </main>
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
            background-color: #07a701;
            background-image: url('assets/images/buttons/primary-btn-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
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

        /* course.php's own AI fab sits in the same corner as the global
           feedback-fab (partials/nav.php) — push feedback up above it here
           so the two floating buttons don't overlap on this page only. */
        .feedback-fab {
            bottom: 100px;
        }

        @media (max-width: 768px) {
            #ai-fab {
                bottom: 84px;
            }

            #ai-panel {
                bottom: 156px;
            }

            .feedback-fab {
                bottom: 156px;
            }
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
            background-color: #07a701;
            background-image: url('assets/images/buttons/primary-btn-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
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
            color: var(--primary-dark);
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
            color: var(--primary-dark);
        }

        #ai-send-btn {
            background-color: #07a701;
            background-image: url('assets/images/buttons/primary-btn-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: #fff;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 18px;
            flex-shrink: 0;
            transition: background-color .15s;
            align-self: flex-end;
        }

        #ai-send-btn:hover {
            /* background-color only (not the "background" shorthand) so the
               button keeps its background-image on hover — same pattern as
               .btn.primary:hover in style.css. */
            background-color: #059669;
        }

        #ai-send-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
    </style>

    <button id="ai-fab" title="Ask AI about this course" aria-label="Open AI assistant"><i class="bi bi-robot"></i></button>

    <div id="ai-panel" class="hidden">
        <div id="ai-panel-header">
            <div>
                <h3><i class="bi bi-robot icon"></i> Course Assistant</h3>
                <p id="ai-session-label"><?php echo h($course['code']); ?> — Ask me anything</p>
            </div>
            <div class="ai-header-btns">
                <button class="ai-icon-btn" id="ai-history-btn" title="Chat history"><i class="bi bi-clock-fill"></i></button>
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
                <button id="ai-attach-btn" title="Attach image or document"><i class="bi bi-paperclip"></i></button>
                <input type="file" id="ai-file-input" style="display:none"
                    accept="image/*,.pdf,.doc,.docx,.txt">
                <textarea id="ai-input" placeholder="Ask a question about this course…" rows="1"></textarea>
                <button id="ai-send-btn" title="Send"><i class="bi bi-send-fill"></i></button>
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

            // Downscales an image client-side (canvas) before it's ever
            // turned into base64 — phone camera photos can be 4-8MB, and
            // sending that as base64 JSON is ~33% bigger again. Cutting it
            // to ~1600px/JPEG here is what actually saves upload time on a
            // slow connection, since it shrinks the payload before it ever
            // hits the network. Falls back to reading the original file as-is
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

            // ── Attachment button ─────────────────────────────────────────────────────
            attachBtn.addEventListener('click', () => fileInput.click());

            fileInput.addEventListener('change', async () => {
                const file = fileInput.files[0];
                if (!file) return;
                fileInput.value = '';
                const isImage = file.type.startsWith('image/');
                const maxMB = isImage ? 5 : 10;
                if (file.size > maxMB * 1024 * 1024) {
                    alert(`File too large. Max ${maxMB}MB for ${isImage ? 'images' : 'documents'}.`);
                    return;
                }
                if (isImage) {
                    const b64 = await resizeImageToDataUrl(file);
                    if (b64) {
                        pendingAttachments.push({
                            name: file.name,
                            type: 'image/jpeg',
                            b64,
                            isImage
                        });
                        renderAttachPreviews();
                    }
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
                    pdf: '<i class="bi bi-file-earmark-pdf-fill"></i>',
                    doc: '<i class="bi bi-file-earmark-word-fill"></i>',
                    docx: '<i class="bi bi-file-earmark-word-fill"></i>',
                    txt: '<i class="bi bi-file-earmark-text-fill"></i>'
                } [ext] || '<i class="bi bi-file-earmark-fill"></i>';
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
                    addMessage(`Hi ${FIRST_NAME}! I'm your AI assistant for <strong><?php echo h($course['code']); ?> — <?php echo h($course['title']); ?></strong>. Ask me about the topics, documents, or anything in this course. I can also read the actual uploaded materials!`, 'bot');
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
                    <button class="ai-session-rename" data-id="${s.id}" data-title="${escHtml(s.title)}" title="Rename"><i class="bi bi-pencil-fill"></i></button>
                    <button class="ai-session-del" data-id="${s.id}" title="Delete"><i class="bi bi-trash"></i></button>
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

                const thinking = addMessage('Reading your question…', 'bot thinking');
                const stopThinkingCycler = startStatusCycler(thinking, [
                    'Reading your question…',
                    'Searching course materials…',
                    'Thinking…',
                    'Composing a response…',
                ]);

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
                    stopThinkingCycler();
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
                    stopThinkingCycler();
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