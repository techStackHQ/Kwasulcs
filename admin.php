<?php
require_once __DIR__ . '/config.php';
require_login();
ensure_topic_overview_column();
ensure_announcements_table();
// ensure_department_schema() removed here — schema already established in
// production; see the note in current_user() in config.php.

$user = current_user();
if (!in_array($user['role'], ['admin', 'lecturer'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$messages = [];
$errors   = [];
$__coursePalette = course_color_palette();

/**
 * Department-scoped (Task 19 Part B) — an admin no longer has blanket
 * access to every course system-wide; they can only see/manage courses in
 * their OWN department, exactly like a lecturer is already scoped to their
 * own courses. There is no cross-department "super admin" authority. This
 * is the single gate function every admin.php mutation (topics, sections,
 * videos, documents) already routes through via a course/topic/section
 * lookup, so fixing it here closes the gap everywhere at once.
 */
function can_see_course(array $course, array $user): bool
{
    if ((int)($course['department_id'] ?? 0) !== (int)($user['department_id'] ?? 0)) {
        return false;
    }
    return $user['role'] === 'admin' || (int)$course['lecturer_id'] === (int)$user['id'];
}

// ── Which course are we managing right now? ───────────────────────────────────
$activeCourseId = (int)($_GET['manage'] ?? $_POST['active_course_id'] ?? 0);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    // Preserve active course across POSTs
    if (!$activeCourseId) {
        $activeCourseId = (int)($_POST['active_course_id'] ?? 0);
    }

    try {
        if ($action === 'save_course') {
            $courseId   = (int)($_POST['course_id'] ?? 0);
            $title      = trim((string)($_POST['title'] ?? ''));
            $code       = trim((string)($_POST['code'] ?? ''));
            $semester   = (string)($_POST['semester'] ?? '');
            $lecturerId = (int)($_POST['lecturer_id'] ?? 0);
            $palette    = course_color_palette();
            $color      = (string)($_POST['color'] ?? '');
            if (!in_array($color, $palette, true)) {
                $color = reset($palette);
            }
            // Department is NEVER taken from client input (Task 19 Part B) —
            // there is no cross-department admin/lecturer authority, so the
            // only value that could ever legitimately apply is the acting
            // user's own department. A submitted department_id here would
            // be exactly the "trust a client-submitted department_id"
            // mistake the branded-login-URL security requirement (Task 14)
            // already established the same principle against.
            $departmentId = (int)($user['department_id'] ?? 0);
            $level        = (int)($_POST['level'] ?? 0);

            if (
                $title === '' || $code === '' || !in_array($semester, ['rain', 'harmattan'], true) || $lecturerId <= 0
                || $departmentId <= 0 || !in_array($level, [100, 200, 300, 400], true)
            ) {
                throw new RuntimeException('Fill all course fields correctly, including level (and confirm your own account has a department assigned).');
            }
            if ($user['role'] !== 'admin') {
                $lecturerId = (int)$user['id'];
            }

            if ($courseId > 0) {
                $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
                $stmt->execute([$courseId]);
                $existing = $stmt->fetch();
                if (!$existing || !can_see_course($existing, $user)) throw new RuntimeException('Course not found.');
                db()->prepare('UPDATE courses SET title=?,code=?,semester=?,lecturer_id=?,color=?,department_id=?,level=? WHERE id=?')
                    ->execute([$title, $code, $semester, $lecturerId, $color, $departmentId, $level, $courseId]);
                $messages[] = 'Course updated.';
                if (!$activeCourseId) $activeCourseId = $courseId;
            } else {
                db()->prepare('INSERT INTO courses (title,code,semester,lecturer_id,color,department_id,level) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$title, $code, $semester, $lecturerId, $color, $departmentId, $level]);
                $newId = (int)db()->lastInsertId();
                $messages[] = 'Course created.';
                $activeCourseId = $newId;
            }
        }

        if ($action === 'delete_course') {
            $courseId = (int)($_POST['course_id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if (!$course || !can_see_course($course, $user)) throw new RuntimeException('Course not found.');
            db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
            $messages[] = 'Course deleted.';
            $activeCourseId = 0;
        }

        if ($action === 'save_topic') {
            $courseId   = (int)($_POST['active_course_id'] ?? 0);
            $weekNumber = (int)($_POST['week_number'] ?? 0);
            $title      = trim((string)($_POST['topic_title'] ?? ''));
            $overview   = trim((string)($_POST['topic_overview'] ?? ''));
            $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if (!$course || !can_see_course($course, $user)) throw new RuntimeException('Course not found.');
            if ($weekNumber <= 0 || $title === '') throw new RuntimeException('Provide a week number and topic title.');
            db()->prepare('INSERT INTO topics (course_id,week_number,title,overview) VALUES (?,?,?,?)')
                ->execute([$courseId, $weekNumber, $title, $overview !== '' ? $overview : null]);
            $messages[] = 'Topic added.';
        }

        if ($action === 'save_section') {
            $courseId    = (int)($_POST['active_course_id'] ?? 0);
            $sectionType = (string)($_POST['section_type'] ?? '');
            $title       = trim((string)($_POST['section_title'] ?? ''));
            $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if (!$course || !can_see_course($course, $user)) throw new RuntimeException('Course not found.');
            if (!in_array($sectionType, ['tutorial_update', 'exam_update'], true) || $title === '') throw new RuntimeException('Provide a valid section.');
            db()->prepare('INSERT INTO course_sections (course_id,section_type,title) VALUES (?,?,?)')
                ->execute([$courseId, $sectionType, $title]);
            $messages[] = 'Section added.';
        }

        if ($action === 'save_video') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $title   = trim((string)($_POST['video_title'] ?? ''));
            $url     = trim((string)($_POST['youtube_url'] ?? ''));
            $stmt = db()->prepare('SELECT t.*,c.lecturer_id,c.is_approved,c.department_id FROM topics t JOIN courses c ON c.id=t.course_id WHERE t.id=? LIMIT 1');
            $stmt->execute([$topicId]);
            $topic = $stmt->fetch();
            if (!$topic || !can_see_course($topic, $user)) throw new RuntimeException('Topic not found.');
            $embed = youtube_embed_url($url);
            if ($title === '' || !$embed) throw new RuntimeException('Provide a valid YouTube link.');
            db()->prepare('INSERT INTO videos (topic_id,title,original_url,embed_url) VALUES (?,?,?,?)')
                ->execute([$topicId, $title, $url, $embed]);
            $messages[] = 'Video added.';
        }

        if ($action === 'save_document') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $title   = trim((string)($_POST['document_title'] ?? ''));
            $stmt = db()->prepare('SELECT t.*,c.lecturer_id,c.is_approved,c.department_id FROM topics t JOIN courses c ON c.id=t.course_id WHERE t.id=? LIMIT 1');
            $stmt->execute([$topicId]);
            $topic = $stmt->fetch();
            if (!$topic || !can_see_course($topic, $user)) throw new RuntimeException('Topic not found.');
            if ($title === '') throw new RuntimeException('Provide a document title.');
            $upload = save_upload($_FILES['document_file'] ?? [], 'topics');
            db()->prepare('INSERT INTO documents (topic_id,title,file_path,file_type) VALUES (?,?,?,?)')
                ->execute([$topicId, $title, $upload['path'], $upload['ext']]);
            $messages[] = 'Document uploaded.';
        }

        if ($action === 'save_section_video') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $title     = trim((string)($_POST['section_video_title'] ?? ''));
            $url       = trim((string)($_POST['section_video_url'] ?? ''));
            $stmt = db()->prepare('SELECT cs.*,c.lecturer_id,c.department_id FROM course_sections cs JOIN courses c ON c.id=cs.course_id WHERE cs.id=? LIMIT 1');
            $stmt->execute([$sectionId]);
            $section = $stmt->fetch();
            if (!$section || !can_see_course($section, $user)) throw new RuntimeException('Section not found.');
            $embed = youtube_embed_url($url);
            if ($title === '' || !$embed) throw new RuntimeException('Provide a valid YouTube link.');
            db()->prepare('INSERT INTO section_resources (section_id,title,resource_type,original_url,embed_url) VALUES (?,?,"video",?,?)')
                ->execute([$sectionId, $title, $url, $embed]);
            $messages[] = 'Section video added.';
        }

        if ($action === 'save_section_document') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $title     = trim((string)($_POST['section_document_title'] ?? ''));
            $stmt = db()->prepare('SELECT cs.*,c.lecturer_id,c.department_id FROM course_sections cs JOIN courses c ON c.id=cs.course_id WHERE cs.id=? LIMIT 1');
            $stmt->execute([$sectionId]);
            $section = $stmt->fetch();
            if (!$section || !can_see_course($section, $user)) throw new RuntimeException('Section not found.');
            if ($title === '') throw new RuntimeException('Provide a document title.');
            $upload = save_upload($_FILES['section_document_file'] ?? [], 'sections');
            db()->prepare('INSERT INTO section_resources (section_id,title,resource_type,file_path,file_type) VALUES (?,?,"document",?,?)')
                ->execute([$sectionId, $title, $upload['path'], $upload['ext']]);
            $messages[] = 'Section document uploaded.';
        }

        if ($action === 'delete_topic') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $stmt = db()->prepare('SELECT t.*,c.lecturer_id,c.department_id FROM topics t JOIN courses c ON c.id=t.course_id WHERE t.id=? LIMIT 1');
            $stmt->execute([$topicId]);
            $topic = $stmt->fetch();
            if (!$topic || !can_see_course($topic, $user)) throw new RuntimeException('Topic not found.');
            db()->prepare('DELETE FROM topics WHERE id=?')->execute([$topicId]);
            $messages[] = 'Topic deleted.';
        }

        if ($action === 'delete_video') {
            $videoId = (int)($_POST['video_id'] ?? 0);
            $stmt = db()->prepare('SELECT v.*,c.lecturer_id,c.department_id FROM videos v JOIN topics t ON t.id=v.topic_id JOIN courses c ON c.id=t.course_id WHERE v.id=? LIMIT 1');
            $stmt->execute([$videoId]);
            $video = $stmt->fetch();
            if (!$video || !can_see_course($video, $user)) throw new RuntimeException('Video not found.');
            db()->prepare('DELETE FROM videos WHERE id=?')->execute([$videoId]);
            $messages[] = 'Video deleted.';
        }

        if ($action === 'delete_document') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            $stmt = db()->prepare('SELECT d.*,c.lecturer_id,c.department_id FROM documents d JOIN topics t ON t.id=d.topic_id JOIN courses c ON c.id=t.course_id WHERE d.id=? LIMIT 1');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            if (!$doc || !can_see_course($doc, $user)) throw new RuntimeException('Document not found.');
            // Delete physical file too
            $abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($doc['file_path'], '/');
            if (is_file($abs)) @unlink($abs);
            db()->prepare('DELETE FROM documents WHERE id=?')->execute([$docId]);
            $messages[] = 'Document deleted.';
        }

        if ($action === 'delete_section') {
            $sectionId = (int)($_POST['section_id'] ?? 0);
            $stmt = db()->prepare('SELECT cs.*,c.lecturer_id,c.department_id FROM course_sections cs JOIN courses c ON c.id=cs.course_id WHERE cs.id=? LIMIT 1');
            $stmt->execute([$sectionId]);
            $section = $stmt->fetch();
            if (!$section || !can_see_course($section, $user)) throw new RuntimeException('Section not found.');
            db()->prepare('DELETE FROM course_sections WHERE id=?')->execute([$sectionId]);
            $messages[] = 'Section deleted.';
        }

        // After POST redirect to same manage page
        if ($activeCourseId) {
            $redir = 'admin.php?manage=' . $activeCourseId;
            if ($messages) $_SESSION['admin_flash'] = implode(' ', $messages);
            header('Location: ' . $redir);
            exit();
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ── Flash messages ────────────────────────────────────────────────────────────
if (!empty($_SESSION['admin_flash'])) {
    $messages[] = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

// ── Load all courses ──────────────────────────────────────────────────────────
// Department-scoped for admins too (Task 19 Part B) — see can_see_course()
// above for the full reasoning; this is the same boundary applied to the
// listing query so an admin never even SEES another department's courses,
// not just gets blocked from mutating them.
if ($user['role'] === 'admin') {
    $stmt = db()->prepare('SELECT c.*,u.full_name AS lecturer_name, d.code AS department_code FROM courses c JOIN users u ON u.id=c.lecturer_id LEFT JOIN departments d ON d.id=c.department_id WHERE c.department_id=? ORDER BY c.created_at DESC');
    $stmt->execute([$user['department_id'] ?? 0]);
    $courses = $stmt->fetchAll();
} else {
    $stmt = db()->prepare('SELECT c.*,u.full_name AS lecturer_name, d.code AS department_code FROM courses c JOIN users u ON u.id=c.lecturer_id LEFT JOIN departments d ON d.id=c.department_id WHERE c.lecturer_id=? ORDER BY c.created_at DESC');
    $stmt->execute([$user['id']]);
    $courses = $stmt->fetchAll();
}

// Same department scoping applied to the "who can own this course" picker
// — an admin should not even see another department's staff list here.
if ($user['role'] === 'admin') {
    $stmt = db()->prepare("SELECT id,full_name,matric_no FROM users WHERE role IN ('lecturer','admin') AND department_id = ? ORDER BY full_name");
    $stmt->execute([$user['department_id'] ?? 0]);
    $allLecturers = $stmt->fetchAll();
} else {
    $allLecturers = [['id' => $user['id'], 'full_name' => $user['full_name'], 'matric_no' => $user['matric_no']]];
}

// $allDepartments is no longer used for a picker in the course form (Task
// 19 Part B — department is forced server-side to the acting user's own,
// never chosen) but is kept for looking up that department's display name.
$allDepartments = all_departments();
$myDepartmentName = 'Unassigned';
foreach ($allDepartments as $d) {
    if ((int)$d['id'] === (int)($user['department_id'] ?? 0)) {
        $myDepartmentName = $d['name'];
        break;
    }
}

// ── Active course data ────────────────────────────────────────────────────────
$activeCourse = null;
$courseTopics = [];
$courseSections = [];
$topicVideos = [];
$topicDocs = [];
$sectionResources = [];

if ($activeCourseId) {
    $stmt = db()->prepare('SELECT c.*,u.full_name AS lecturer_name FROM courses c JOIN users u ON u.id=c.lecturer_id WHERE c.id=? LIMIT 1');
    $stmt->execute([$activeCourseId]);
    $activeCourse = $stmt->fetch();

    if ($activeCourse && can_see_course($activeCourse, $user)) {
        // Topics for this course only
        $stmt = db()->prepare('SELECT * FROM topics WHERE course_id=? ORDER BY week_number,id');
        $stmt->execute([$activeCourseId]);
        $courseTopics = $stmt->fetchAll();

        // Sections for this course only
        $stmt = db()->prepare('SELECT * FROM course_sections WHERE course_id=? ORDER BY section_type,id');
        $stmt->execute([$activeCourseId]);
        $courseSections = $stmt->fetchAll();

        // Videos and docs for each topic
        if ($courseTopics) {
            $topicIds = array_column($courseTopics, 'id');
            $in = implode(',', array_fill(0, count($topicIds), '?'));
            $vStmt = db()->prepare("SELECT * FROM videos WHERE topic_id IN ($in) ORDER BY id");
            $vStmt->execute($topicIds);
            foreach ($vStmt->fetchAll() as $v) $topicVideos[(int)$v['topic_id']][] = $v;
            $dStmt = db()->prepare("SELECT * FROM documents WHERE topic_id IN ($in) ORDER BY id");
            $dStmt->execute($topicIds);
            foreach ($dStmt->fetchAll() as $d) $topicDocs[(int)$d['topic_id']][] = $d;
        }

        // Section resources
        if ($courseSections) {
            $sectionIds = array_column($courseSections, 'id');
            $in = implode(',', array_fill(0, count($sectionIds), '?'));
            $srStmt = db()->prepare("SELECT * FROM section_resources WHERE section_id IN ($in) ORDER BY id");
            $srStmt->execute($sectionIds);
            foreach ($srStmt->fetchAll() as $r) $sectionResources[(int)$r['section_id']][] = $r;
        }
    } else {
        $activeCourse = null;
        $activeCourseId = 0;
    }
}

// Edit course pre-fill
$editCourse = null;
if (!empty($_GET['edit_course'])) {
    foreach ($courses as $c) {
        if ((int)$c['id'] === (int)$_GET['edit_course']) {
            $editCourse = $c;
            break;
        }
    }
}

function selected($a, $b): string
{
    return (string)$a === (string)$b ? 'selected' : '';
}
function section_type_label(string $t): string
{
    return $t === 'tutorial_update' ? 'Tutorial Update' : 'Exam Update';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = $activeCourse ? h($activeCourse['code']) : 'Content Management';
    $extraCss = ['assets/admin.css'];
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar">
        <div>
            <div class="eyebrow">Management</div>
            <h1>Content Management</h1>
            <?php if ($activeCourse): ?>
                <p class="muted">Managing: <strong><?php echo h($activeCourse['code'] . ' — ' . $activeCourse['title']); ?></strong></p>
            <?php else: ?>
                <p class="muted">Select a course below to manage its content.</p>
            <?php endif; ?>
        </div>
        <div class="topbar-actions">
            <?php if ($activeCourse): ?>
                <a class="btn glass" href="admin.php">← All Courses</a>
            <?php endif; ?>
            <a class="btn glass btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
        </div>
    </header>

    <main class="page">

        <?php foreach ($messages as $m): ?>
            <div class="alert success"><?php echo h($m); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert error"><?php echo h($e); ?></div>
        <?php endforeach; ?>

        <?php if (!$activeCourseId): ?>
            <!-- ═══════════════════════════════════════════════════════════════════════════
     COURSE LIST + CREATE FORM
     ═══════════════════════════════════════════════════════════════════════════ -->

            <section class="panel">
                <h2><?php echo $editCourse ? 'Edit Course' : 'Create New Course'; ?></h2>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="save_course">
                    <input type="hidden" name="course_id" value="<?php echo (int)($_GET['edit_course'] ?? 0); ?>">
                    <label>Title
                        <input type="text" name="title" value="<?php echo h($editCourse['title'] ?? ''); ?>" required>
                    </label>
                    <label>Code
                        <input type="text" name="code" value="<?php echo h($editCourse['code'] ?? ''); ?>" required>
                    </label>
                    <label>Semester
                        <select name="semester" required>
                            <option value="">Select</option>
                            <option value="rain" <?php echo selected($editCourse['semester'] ?? '', 'rain'); ?>>Rain</option>
                            <option value="harmattan" <?php echo selected($editCourse['semester'] ?? '', 'harmattan'); ?>>Harmattan</option>
                        </select>
                    </label>
                    <label>Lecturer
                        <select name="lecturer_id" required>
                            <?php foreach ($allLecturers as $l): ?>
                                <option value="<?php echo (int)$l['id']; ?>" <?php echo selected($editCourse['lecturer_id'] ?? $user['id'], $l['id']); ?>>
                                    <?php echo h($l['full_name'] . ' (' . $l['matric_no'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Department
                        <!-- Read-only — always your own department (Task 19 Part B: no
                             cross-department admin authority, so this is never a real
                             choice). The server ignores any department_id in the POST
                             body regardless of what this shows. -->
                        <input type="text" value="<?php echo h($myDepartmentName); ?>" disabled>
                    </label>
                    <label>Level
                        <select name="level" required>
                            <option value="">Select</option>
                            <?php foreach ([100, 200, 300, 400] as $lvl): ?>
                                <option value="<?php echo $lvl; ?>" <?php echo selected($editCourse['level'] ?? '', $lvl); ?>><?php echo $lvl; ?> Level</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Color
                        <div class="color-swatch-picker">
                            <?php
                            $selectedColor = $editCourse['color'] ?? reset($__coursePalette);
                            foreach ($__coursePalette as $label => $hex):
                            ?>
                                <label class="color-swatch-wrap" title="<?php echo h($label); ?>">
                                    <input type="radio" name="color" value="<?php echo h($hex); ?>" class="color-swatch-input"
                                        <?php echo $selectedColor === $hex ? 'checked' : ''; ?>>
                                    <span class="color-swatch" style="background:<?php echo h($hex); ?>;"></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </label>
                    <button class="btn primary" type="submit"><?php echo $editCourse ? 'Update Course' : 'Create Course'; ?></button>
                </form>
            </section>

            <section class="panel" style="margin-top:18px;">
                <h2>Your Courses</h2>
                <?php if (!$courses): ?>
                    <p class="muted">No courses yet. Create one above.</p>
                <?php endif; ?>
                <div class="course-manage-grid">
                    <?php foreach ($courses as $c): ?>
                        <div class="course-manage-card">
                            <div class="course-manage-top">
                                <div class="course-manage-header">
                                    <div>
                                        <span class="course-code"><?php echo h($c['code']); ?></span>
                                    </div>
                                    <div class="course-manage-actions">
                                        <a class="btn tiny secondary" href="admin.php?edit_course=<?php echo (int)$c['id']; ?>">Edit</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this course and ALL its content?');">
                                            <input type="hidden" name="action" value="delete_course">
                                            <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                                            <button class="btn tiny danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <h3 title="<?php echo h($c['title']); ?>"><?php echo h($c['title']); ?></h3>
                                <p class="muted">
                                    <?php echo strtoupper(h($c['semester'])); ?> · <?php echo h($c['lecturer_name']); ?>
                                    <?php if ($c['department_code']): ?>
                                        · <?php echo h($c['department_code']); ?><?php echo $c['level'] ? ' ' . (int)$c['level'] . 'L' : ''; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <a class="btn primary"
                                href="admin.php?manage=<?php echo (int)$c['id']; ?>">
                                <i class="bi bi-folder2-open icon"></i> Manage Content →
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        <?php else: ?>
            <!-- ═══════════════════════════════════════════════════════════════════════════
     SINGLE COURSE CONTENT MANAGEMENT
     ═══════════════════════════════════════════════════════════════════════════ -->

            <!-- Course info bar -->
            <div class="course-info-bar">
                <div class="course-info-meta">
                    <span class="course-code" style="font-size:20px;"><?php echo h($activeCourse['code']); ?></span>
                    <span><?php echo h($activeCourse['title']); ?></span>
                    <span class="muted">·</span>
                    <span class="muted"><?php echo strtoupper(h($activeCourse['semester'])); ?></span>
                </div>
                <a class="btn tiny secondary" href="course.php?id=<?php echo $activeCourseId; ?>" target="_blank">
                    <i class="bi bi-eye-fill icon"></i> View Course →
                </a>
            </div>

            <!-- Tab navigation -->
            <div class="admin-tabs">
                <a class="admin-tab <?php echo ($_GET['tab'] ?? 'weeks') === 'weeks' ? 'active' : ''; ?>"
                    href="admin.php?manage=<?php echo $activeCourseId; ?>&tab=weeks"><i class="bi bi-calendar3 icon"></i> Weekly Topics</a>
                <a class="admin-tab <?php echo ($_GET['tab'] ?? '') === 'sections' ? 'active' : ''; ?>"
                    href="admin.php?manage=<?php echo $activeCourseId; ?>&tab=sections"><i class="bi bi-pin-angle-fill icon"></i> Tutorial / Exam Sections</a>
            </div>

            <?php $tab = $_GET['tab'] ?? 'weeks'; ?>

            <?php if ($tab === 'weeks'): ?>
                <!-- ── WEEKS TAB ─────────────────────────────────────────────────────────── -->

                <div class="grid-2" style="margin-top:0;">

                    <!-- Add Topic -->
                    <div class="panel">
                        <h2>Add Week / Topic</h2>
                        <form method="post" class="form-stack">
                            <input type="hidden" name="action" value="save_topic">
                            <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                            <label>Week Number
                                <input type="number" name="week_number" min="1" required placeholder="e.g. 3">
                            </label>
                            <label>Topic Title
                                <input type="text" name="topic_title" required placeholder="e.g. Introduction to Networking">
                            </label>
                            <label>Week Overview <span class="muted" style="font-weight:400;">(optional)</span>
                                <textarea name="topic_overview" rows="2" placeholder="A short 1-2 sentence summary students see before expanding this week…"></textarea>
                            </label>
                            <button class="btn primary" type="submit">Add Topic</button>
                        </form>
                    </div>

                    <!-- Add Video -->
                    <div class="panel">
                        <h2>Add Video to Topic</h2>
                        <?php if (!$courseTopics): ?>
                            <p class="muted">Add a topic first before uploading videos.</p>
                        <?php else: ?>
                            <form method="post" class="form-stack">
                                <input type="hidden" name="action" value="save_video">
                                <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                <label>Topic
                                    <select name="topic_id" required>
                                        <option value="">Select topic…</option>
                                        <?php foreach ($courseTopics as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>">
                                                Week <?php echo (int)$t['week_number']; ?> — <?php echo h($t['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Video Title
                                    <input type="text" name="video_title" required placeholder="e.g. Lecture Recording — Week 3">
                                </label>
                                <label>YouTube Link (unlisted OK)
                                    <input type="url" name="youtube_url" required placeholder="https://youtu.be/...">
                                </label>
                                <button class="btn primary" type="submit">Add Video</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Add Document -->
                    <div class="panel">
                        <h2>Upload Document to Topic</h2>
                        <?php if (!$courseTopics): ?>
                            <p class="muted">Add a topic first before uploading documents.</p>
                        <?php else: ?>
                            <form method="post" class="form-stack" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save_document">
                                <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                <label>Topic
                                    <select name="topic_id" required>
                                        <option value="">Select topic…</option>
                                        <?php foreach ($courseTopics as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>">
                                                Week <?php echo (int)$t['week_number']; ?> — <?php echo h($t['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Document Title
                                    <input type="text" name="document_title" required>
                                </label>
                                <label>File (PDF, DOCX, PPTX, ZIP — max 20 MB)
                                    <input type="file" name="document_file" required>
                                </label>
                                <button class="btn primary" type="submit">Upload Document</button>
                            </form>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Topic list with inline content -->
                <div class="panel" style="margin-top:18px;">
                    <h2>All Topics — <?php echo h($activeCourse['code']); ?></h2>
                    <?php if (!$courseTopics): ?>
                        <p class="muted">No topics yet. Add one using the form above.</p>
                    <?php endif; ?>

                    <?php foreach ($courseTopics as $topic):
                        $tvids = $topicVideos[(int)$topic['id']] ?? [];
                        $tdocs = $topicDocs[(int)$topic['id']] ?? [];
                    ?>
                        <div class="topic-manage-card">
                            <div class="topic-manage-head">
                                <div>
                                    <span class="eyebrow">Week <?php echo (int)$topic['week_number']; ?></span>
                                    <h3 style="margin:2px 0;"><?php echo h($topic['title']); ?></h3>
                                </div>
                                <form method="post" onsubmit="return confirm('Delete this topic and all its content?');">
                                    <input type="hidden" name="action" value="delete_topic">
                                    <input type="hidden" name="topic_id" value="<?php echo (int)$topic['id']; ?>">
                                    <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                    <button class="btn tiny danger" type="submit">Delete Topic</button>
                                </form>
                            </div>

                            <div class="resource-columns">
                                <!-- Videos -->
                                <div>
                                    <h4>Videos (<?php echo count($tvids); ?>)</h4>
                                    <?php if (!$tvids): ?><p class="muted" style="font-size:13px;">None yet.</p><?php endif; ?>
                                    <?php foreach ($tvids as $v): ?>
                                        <div class="resource-manage-item">
                                            <div>
                                                <strong><?php echo h($v['title']); ?></strong>
                                                <div class="muted" style="font-size:12px;">
                                                    <a href="<?php echo h($v['original_url']); ?>" target="_blank">▶ YouTube</a>
                                                </div>
                                            </div>
                                            <form method="post" onsubmit="return confirm('Delete video?');" style="flex-shrink:0;">
                                                <input type="hidden" name="action" value="delete_video">
                                                <input type="hidden" name="video_id" value="<?php echo (int)$v['id']; ?>">
                                                <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                                <button class="btn tiny danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Documents -->
                                <div>
                                    <h4>Documents (<?php echo count($tdocs); ?>)</h4>
                                    <?php if (!$tdocs): ?><p class="muted" style="font-size:13px;">None yet.</p><?php endif; ?>
                                    <?php foreach ($tdocs as $d): ?>
                                        <div class="resource-manage-item">
                                            <div>
                                                <strong><?php echo h($d['title']); ?></strong>
                                                <div class="muted" style="font-size:12px;">
                                                    <?php echo strtoupper(h($d['file_type'])); ?>
                                                    · <a href="download.php?type=document&id=<?php echo (int)$d['id']; ?>&view=1" target="_blank">View</a>
                                                </div>
                                            </div>
                                            <form method="post" onsubmit="return confirm('Delete document?');" style="flex-shrink:0;">
                                                <input type="hidden" name="action" value="delete_document">
                                                <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                                                <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                                <button class="btn tiny danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- ── SECTIONS TAB ───────────────────────────────────────────────────────── -->

                <div class="grid-2" style="margin-top:0;">

                    <div class="panel">
                        <h2>Add Tutorial / Exam Section</h2>
                        <form method="post" class="form-stack">
                            <input type="hidden" name="action" value="save_section">
                            <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                            <label>Type
                                <select name="section_type" required>
                                    <option value="tutorial_update">Tutorial Update</option>
                                    <option value="exam_update">Exam Update</option>
                                </select>
                            </label>
                            <label>Title
                                <input type="text" name="section_title" required placeholder="e.g. Mid-Semester Tutorial 1">
                            </label>
                            <button class="btn primary" type="submit">Add Section</button>
                        </form>
                    </div>

                    <?php if ($courseSections): ?>
                        <div class="panel">
                            <h2>Add Video to Section</h2>
                            <form method="post" class="form-stack">
                                <input type="hidden" name="action" value="save_section_video">
                                <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                <label>Section
                                    <select name="section_id" required>
                                        <option value="">Select section…</option>
                                        <?php foreach ($courseSections as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>">
                                                <?php echo h(section_type_label($s['section_type']) . ' — ' . $s['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Video Title
                                    <input type="text" name="section_video_title" required>
                                </label>
                                <label>YouTube Link
                                    <input type="url" name="section_video_url" required>
                                </label>
                                <button class="btn primary" type="submit">Add Video</button>
                            </form>
                        </div>

                        <div class="panel">
                            <h2>Upload Document to Section</h2>
                            <form method="post" class="form-stack" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="save_section_document">
                                <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                <label>Section
                                    <select name="section_id" required>
                                        <option value="">Select section…</option>
                                        <?php foreach ($courseSections as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>">
                                                <?php echo h(section_type_label($s['section_type']) . ' — ' . $s['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Document Title
                                    <input type="text" name="section_document_title" required>
                                </label>
                                <label>File (max 20 MB)
                                    <input type="file" name="section_document_file" required>
                                </label>
                                <button class="btn primary" type="submit">Upload Document</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section list -->
                <div class="panel" style="margin-top:18px;">
                    <h2>All Sections — <?php echo h($activeCourse['code']); ?></h2>
                    <?php if (!$courseSections): ?>
                        <p class="muted">No sections yet. Add one above.</p>
                    <?php endif; ?>
                    <?php foreach ($courseSections as $s):
                        $sResources = $sectionResources[(int)$s['id']] ?? [];
                    ?>
                        <div class="topic-manage-card">
                            <div class="topic-manage-head">
                                <div>
                                    <span class="eyebrow"><?php echo h(section_type_label($s['section_type'])); ?></span>
                                    <h3 style="margin:2px 0;"><?php echo h($s['title']); ?></h3>
                                </div>
                                <form method="post" onsubmit="return confirm('Delete this section?');">
                                    <input type="hidden" name="action" value="delete_section">
                                    <input type="hidden" name="section_id" value="<?php echo (int)$s['id']; ?>">
                                    <input type="hidden" name="active_course_id" value="<?php echo $activeCourseId; ?>">
                                    <button class="btn tiny danger" type="submit">Delete Section</button>
                                </form>
                            </div>
                            <?php if (!$sResources): ?>
                                <p class="muted" style="font-size:13px;">No resources yet.</p>
                            <?php endif; ?>
                            <?php foreach ($sResources as $r): ?>
                                <div class="resource-manage-item">
                                    <div>
                                        <strong><?php echo h($r['title']); ?></strong>
                                        <div class="muted" style="font-size:12px;">
                                            <?php if ($r['resource_type'] === 'video' && $r['embed_url']): ?>
                                                Video · <a href="<?php echo h($r['original_url']); ?>" target="_blank">▶ YouTube</a>
                                            <?php else: ?>
                                                <?php echo strtoupper(h($r['file_type'] ?? '')); ?>
                                                · <a href="download.php?type=section&id=<?php echo (int)$r['id']; ?>&view=1" target="_blank">View</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; // tab 
            ?>
        <?php endif; // activeCourseId 
        ?>

    </main>
</body>

</html>