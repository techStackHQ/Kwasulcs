<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$role = $user['role'];

$courses  = courses_for_user($user);
$enrolled = $role === 'student' ? enrolled_course_ids((int) $user['id']) : [];

$courseIds = array_map(fn($c) => (int) $c['id'], $courses);
$patterns  = course_pattern_map($courses);
$stats     = course_content_stats($courseIds);
$quizAvgs  = course_quiz_averages($courseIds, $user);

// Category = the course code's letter prefix (e.g. "CSC 402" -> "CSC") —
// there's no dedicated category/department column, so this is the one real,
// derived grouping available rather than a fabricated field.
foreach ($courses as &$c) {
    $c['category'] = preg_match('/^([A-Za-z]+)/', $c['code'], $m) ? strtoupper($m[1]) : 'General';
}
unset($c);
$categories = array_values(array_unique(array_column($courses, 'category')));
sort($categories);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $pageTitle = 'Courses'; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar">
        <div>
            <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
            <h1><i class="bi bi-journal-bookmark-fill icon"></i> Courses</h1>
            <p class="muted">
                <?php if ($role === 'student'): ?>
                    All courses — click one to register and access its content.
                <?php elseif ($role === 'lecturer'): ?>
                    Courses assigned to you.
                <?php else: ?>
                    All courses in the system.
                <?php endif; ?>
            </p>
        </div>
        <div class="topbar-actions">
            <a class="btn glass btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
        </div>
    </header>

    <main class="page">
        <div class="course-toolbar">
            <div class="course-search-input">
                <i class="bi bi-search"></i>
                <input type="search" id="courseSearchInput" placeholder="Search courses…" aria-label="Search courses">
            </div>
            <div class="course-select-wrap">
                <select id="courseCategoryFilter" class="course-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo h($cat); ?>"><?php echo h($cat); ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="bi bi-chevron-down"></i>
            </div>
            <div class="course-select-wrap">
                <select id="courseSemesterFilter" class="course-select">
                    <option value="">All Semesters</option>
                    <option value="rain">Rain Semester</option>
                    <option value="harmattan">Harmattan Semester</option>
                </select>
                <i class="bi bi-chevron-down"></i>
            </div>
            <div class="course-select-wrap">
                <select id="courseStatusFilter" class="course-select">
                    <option value="">All Status</option>
                    <option value="registered">Registered</option>
                    <option value="open">Open</option>
                </select>
                <i class="bi bi-chevron-down"></i>
            </div>
            <div class="course-select-wrap course-select-wrap--sort">
                <select id="courseSortSelect" class="course-select">
                    <option value="recent">Sort by: Recently Added</option>
                    <option value="code">Sort by: Course Code</option>
                    <option value="title">Sort by: Title</option>
                </select>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>

        <div class="course-rows" id="courseRows">
            <?php if (!$courses): ?>
                <p class="muted" style="padding:16px 0;">No courses yet.</p>
            <?php endif; ?>
            <?php foreach ($courses as $course):
                $courseId    = (int) $course['id'];
                $isRegistered = $role === 'student' ? course_status($course, $enrolled) === 'Registered' : true;
                $lecturerColor = user_color((int) $course['lecturer_id']);
                $lecturerAvatar = avatar_inner_html($course['lecturer_name'], $course['lecturer_picture'] ?? null);
                $s = $stats[$courseId];
                $quizAvg = $quizAvgs[$courseId] ?? null;
                $createdTs = strtotime($course['created_at'] ?? 'now');
            ?>
                <div class="course-row"
                    data-href="course.php?id=<?php echo $courseId; ?>"
                    data-pattern="<?php echo $patterns[$courseId] ?? 1; ?>"
                    data-search="<?php echo h(strtolower($course['code'] . ' ' . $course['title'] . ' ' . $course['lecturer_name'])); ?>"
                    data-category="<?php echo h($course['category']); ?>"
                    data-semester="<?php echo h($course['semester']); ?>"
                    data-status="<?php echo $isRegistered ? 'registered' : 'open'; ?>"
                    data-code="<?php echo h($course['code']); ?>"
                    data-title="<?php echo h($course['title']); ?>"
                    data-created="<?php echo (int) $createdTs; ?>"
                    style="--course-color:<?php echo h($course['color'] ?: '#2563eb'); ?>;">
                    <div class="course-row-accent" aria-hidden="true"></div>
                    <div class="course-row-body">
                        <div class="course-row-top">
                            <span class="course-row-code"><?php echo h($course['code']); ?></span>
                            <div class="course-row-top-actions">
                                <?php if ($isRegistered): ?>
                                    <span class="course-status-pill ok"><i class="bi bi-check-circle-fill"></i> Registered</span>
                                <?php else: ?>
                                    <span class="course-status-pill"><i class="bi bi-unlock-fill"></i> Open</span>
                                <?php endif; ?>
                                <div class="course-row-menu-wrap">
                                    <button type="button" class="course-row-menu-btn" aria-label="More options" aria-haspopup="true" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <div class="course-row-menu" hidden>
                                        <a href="course.php?id=<?php echo $courseId; ?>"><i class="bi bi-box-arrow-in-right"></i> Open Course</a>
                                        <a href="calendar.php?course=<?php echo $courseId; ?>"><i class="bi bi-calendar3"></i> View Calendar</a>
                                        <a href="chat_group.php?course_id=<?php echo $courseId; ?>"><i class="bi bi-chat-dots-fill"></i> Message Group</a>
                                        <?php if ($role !== 'student'): ?>
                                            <a href="admin.php?manage=<?php echo $courseId; ?>"><i class="bi bi-sliders"></i> Manage Content</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <h3 class="course-row-title"><?php echo h($course['title']); ?></h3>
                        <div class="course-row-meta">
                            <span class="course-row-lecturer">
                                <span class="course-row-avatar" style="background:<?php echo $lecturerColor; ?>;"><?php echo $lecturerAvatar; ?></span>
                                <?php echo h($course['lecturer_name']); ?>
                            </span>
                            <span class="course-row-meta-item"><i class="bi bi-calendar3"></i> <?php echo h(ucfirst($course['semester'])); ?> Semester</span>
                        </div>
                        <div class="course-row-stats">
                            <span><i class="bi bi-file-earmark-text"></i> <?php echo (int) $s['documents']; ?> Documents</span>
                            <span><i class="bi bi-camera-video-fill"></i> <?php echo (int) $s['videos']; ?> Videos</span>
                            <?php if ($quizAvg !== null): ?>
                                <span><i class="bi bi-bullseye"></i> <?php echo $quizAvg; ?>% Average Quiz Performance</span>
                            <?php else: ?>
                                <span class="muted-stat"><i class="bi bi-bullseye"></i> No quiz attempts yet</span>
                            <?php endif; ?>
                        </div>
                        <div class="course-row-footer">
                            <span class="course-row-updated">
                                <?php if ($s['updated_at']): ?>
                                    <i class="bi bi-clock"></i> Updated <?php echo h(content_time_ago($s['updated_at'])); ?>
                                <?php else: ?>
                                    <i class="bi bi-clock"></i> No content yet
                                <?php endif; ?>
                            </span>
                            <a class="course-open-btn" href="course.php?id=<?php echo $courseId; ?>">Open Course <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="muted course-no-results" id="courseNoResults" hidden>No courses match your filters.</p>
        </div>
    </main>

    <script>
        (function () {
            const rowsWrap = document.getElementById('courseRows');
            const rows = Array.from(rowsWrap.querySelectorAll('.course-row'));
            const searchInput = document.getElementById('courseSearchInput');
            const categoryFilter = document.getElementById('courseCategoryFilter');
            const semesterFilter = document.getElementById('courseSemesterFilter');
            const statusFilter = document.getElementById('courseStatusFilter');
            const sortSelect = document.getElementById('courseSortSelect');
            const noResults = document.getElementById('courseNoResults');

            function applyFilters() {
                const q = searchInput.value.trim().toLowerCase();
                const cat = categoryFilter.value;
                const sem = semesterFilter.value;
                const status = statusFilter.value;
                let anyVisible = false;
                rows.forEach(function (row) {
                    const match = (!q || row.dataset.search.includes(q))
                        && (!cat || row.dataset.category === cat)
                        && (!sem || row.dataset.semester === sem)
                        && (!status || row.dataset.status === status);
                    row.hidden = !match;
                    if (match) anyVisible = true;
                });
                noResults.hidden = anyVisible;
            }

            function applySort() {
                const mode = sortSelect.value;
                const sorted = rows.slice().sort(function (a, b) {
                    if (mode === 'code') return a.dataset.code.localeCompare(b.dataset.code);
                    if (mode === 'title') return a.dataset.title.localeCompare(b.dataset.title);
                    return +b.dataset.created - +a.dataset.created;
                });
                sorted.forEach(row => rowsWrap.appendChild(row));
            }

            [searchInput, categoryFilter, semesterFilter, statusFilter].forEach(function (el) {
                el.addEventListener('input', applyFilters);
                el.addEventListener('change', applyFilters);
            });
            sortSelect.addEventListener('change', applySort);

            // Whole-row click opens the course, except for clicks that
            // originate inside the overflow menu (button or its dropdown).
            rows.forEach(function (row) {
                row.addEventListener('click', function (e) {
                    if (e.target.closest('.course-row-menu-wrap')) return;
                    window.location.href = row.dataset.href;
                });
            });

            // Overflow menu open/close
            document.querySelectorAll('.course-row-menu-btn').forEach(function (btn) {
                const menu = btn.nextElementSibling;
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const wasOpen = !menu.hidden;
                    document.querySelectorAll('.course-row-menu').forEach(m => m.hidden = true);
                    document.querySelectorAll('.course-row-menu-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));
                    menu.hidden = wasOpen;
                    btn.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
                });
            });
            document.addEventListener('click', function () {
                document.querySelectorAll('.course-row-menu').forEach(m => m.hidden = true);
                document.querySelectorAll('.course-row-menu-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));
            });
        })();
    </script>
</body>

</html>
