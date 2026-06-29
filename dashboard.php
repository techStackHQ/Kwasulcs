<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$role = $user['role'];

// ── Auto-fire pending notifications on page load (no cron needed on XAMPP) ───
try {
    $dueChk = db()->prepare("SELECT COUNT(*) FROM calendar_notifications WHERE scheduled_at <= NOW() AND (sent_web=0 OR sent_email=0)");
    $dueChk->execute();
    if ((int)$dueChk->fetchColumn() > 0) {
        ob_start();
        $cronNow2 = date('Y-m-d H:i:s');
        $cronStmt2 = db()->prepare("
            SELECT cn.id AS notif_id, cn.event_id, cn.user_id, cn.sent_email, cn.sent_web,
                   ce.title AS event_title, ce.start_datetime, ce.location,
                   ce.notify_email AS ev_notify_email, ce.notify_web AS ev_notify_web,
                   u.email AS user_email, u.full_name AS user_name
            FROM calendar_notifications cn
            JOIN calendar_events ce ON ce.id = cn.event_id
            JOIN users u ON u.id = cn.user_id
            WHERE cn.scheduled_at <= ? AND (cn.sent_email = 0 OR cn.sent_web = 0)
            LIMIT 50
        ");
        $cronStmt2->execute([$cronNow2]);
        foreach ($cronStmt2->fetchAll() as $cr) {
            $fmt2 = date('D, d M Y \at g:i A', strtotime($cr['start_datetime']));

            // Claim the row atomically before acting — prevents the same
            // notification firing more than once if two requests (open tabs,
            // overlapping page loads, the background poll, etc.) hit this
            // block within the same brief window.
            if (!$cr['sent_web'] && $cr['ev_notify_web']) {
                $claimWeb2 = db()->prepare('UPDATE calendar_notifications SET sent_web=1 WHERE id=? AND sent_web=0');
                $claimWeb2->execute([$cr['notif_id']]);
                if ($claimWeb2->rowCount() > 0) {
                    $msg2 = "Reminder: \"{$cr['event_title']}\" starts {$fmt2}" . ($cr['location'] ? " @ {$cr['location']}" : "");
                    db()->prepare('INSERT IGNORE INTO web_notifications (user_id, event_id, message) VALUES (?,?,?)')->execute([$cr['user_id'], $cr['event_id'], $msg2]);
                }
            }
            if (!$cr['sent_email'] && $cr['ev_notify_email'] && $cr['user_email']) {
                $claimEmail2 = db()->prepare('UPDATE calendar_notifications SET sent_email=1 WHERE id=? AND sent_email=0');
                $claimEmail2->execute([$cr['notif_id']]);
                if ($claimEmail2->rowCount() > 0) {
                    $html2 = "<div style='font-family:sans-serif;'><h2>📅 {$cr['event_title']}</h2><p><strong>When:</strong> {$fmt2}</p>" . ($cr['location'] ? "<p><strong>Where:</strong> {$cr['location']}</p>" : "") . "</div>";
                    $sendOk2 = send_mail($cr['user_email'], $cr['user_name'], "[KWASU LCS] Reminder: {$cr['event_title']}", $html2);
                    if (!$sendOk2) {
                        db()->prepare('UPDATE calendar_notifications SET sent_email=0 WHERE id=?')->execute([$cr['notif_id']]);
                    }
                }
            }
        }
        ob_end_clean();
    }
} catch (\Throwable $e) { /* silent fail */
}


if ($role === 'student') {
    $coursesStmt = db()->query('
        SELECT c.*, u.full_name AS lecturer_name
        FROM courses c
        JOIN users u ON u.id = c.lecturer_id
        ORDER BY c.semester, c.code
    ');
    $courses = $coursesStmt->fetchAll();
} elseif ($role === 'lecturer') {
    $stmt = db()->prepare('
        SELECT c.*, u.full_name AS lecturer_name
        FROM courses c
        JOIN users u ON u.id = c.lecturer_id
        WHERE c.lecturer_id = ?
        ORDER BY c.semester, c.code
    ');
    $stmt->execute([$user['id']]);
    $courses = $stmt->fetchAll();
} else {
    $courses = db()->query('
        SELECT c.*, u.full_name AS lecturer_name
        FROM courses c
        JOIN users u ON u.id = c.lecturer_id
        ORDER BY c.semester, c.code
    ')->fetchAll();
}

$enrolled = [];
if ($role === 'student') {
    $stmt = db()->prepare('SELECT course_id FROM enrollments WHERE student_id = ?');
    $stmt->execute([$user['id']]);
    $enrolled = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

$enrolledCount = count($enrolled);

// ── Unread notification count (bell badge) ────────────────────────────────────
$unreadCount = 0;
try {
    $ns = db()->prepare('SELECT COUNT(*) FROM web_notifications WHERE user_id = ? AND is_read = 0');
    $ns->execute([$user['id']]);
    $unreadCount = (int) $ns->fetchColumn();
} catch (\Throwable $e) { /* table may not exist yet */
}

// ── Upcoming events for this user (next 5) ────────────────────────────────────
$upcomingEvents = [];
try {
    if ($role === 'admin') {
        $evStmt = db()->prepare("
            SELECT e.*, c.code AS course_code
            FROM calendar_events e
            LEFT JOIN courses c ON c.id = e.course_id
            WHERE e.start_datetime >= NOW()
            ORDER BY e.start_datetime ASC LIMIT 5
        ");
        $evStmt->execute();
    } elseif ($role === 'lecturer') {
        $evStmt = db()->prepare("
            SELECT e.*, c.code AS course_code
            FROM calendar_events e
            LEFT JOIN courses c ON c.id = e.course_id
            WHERE e.start_datetime >= NOW()
              AND (e.scope = 'global'
                OR (e.scope = 'course' AND c.lecturer_id = ?)
                OR (e.scope = 'personal' AND e.created_by = ?))
            ORDER BY e.start_datetime ASC LIMIT 5
        ");
        $evStmt->execute([$user['id'], $user['id']]);
    } else {
        $evStmt = db()->prepare("
            SELECT e.*, c.code AS course_code
            FROM calendar_events e
            LEFT JOIN courses c ON c.id = e.course_id
            LEFT JOIN enrollments en ON en.course_id = e.course_id AND en.student_id = ?
            WHERE e.start_datetime >= NOW()
              AND (e.scope = 'global'
                OR (e.scope = 'course' AND en.student_id IS NOT NULL)
                OR (e.scope = 'personal' AND e.created_by = ?))
            ORDER BY e.start_datetime ASC LIMIT 5
        ");
        $evStmt->execute([$user['id'], $user['id']]);
    }
    $upcomingEvents = $evStmt->fetchAll();
} catch (\Throwable $e) { /* calendar tables may not exist yet */
}

function course_status(array $course, array $enrolled): string
{
    if (isset($enrolled[(int)$course['id']])) return 'Registered';
    return 'Open';
}

$typeColors = [
    'lecture'  => '#2563eb',
    'tutorial' => '#7c3aed',
    'exam'     => '#dc2626',
    'test'     => '#ea580c',
    'personal' => '#059669',
    'general'  => '#0891b2',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KWASU LCS — Dashboard</title>
    <script>
        (function() {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme:dark)').matches)) document.documentElement.setAttribute('data-theme', 'dark')
        })();
    </script>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/calendar.css">
    <script src="assets/theme.js" defer></script>
</head>

<body class="app-body">

    <header class="topbar">
        <div>
            <div class="eyebrow">KWASU LCS</div>
            <h1>Welcome, <?php echo h($user['full_name']); ?></h1>
            <p class="muted"><?php echo h(ucfirst($role)); ?> portal</p>
        </div>
        <div class="topbar-actions">
            <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
            <!-- 🔔 Bell with unread badge -->
            <a class="btn secondary notif-bell-btn" href="notifications.php"
                title="<?php echo $unreadCount > 0 ? $unreadCount . ' unread notifications' : 'Notifications'; ?>">
                🔔
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            <a class="btn secondary" href="calendar.php">📅 Calendar</a>
            <?php if ($role !== 'student'): ?>
                <a class="btn secondary" href="admin.php">Manage Content</a>
            <?php endif; ?>
            <a class="btn danger" href="logout.php">Logout</a>
        </div>
    </header>

    <main class="page">
        <!-- ── Stats row ─────────────────────────────────────────────────────────── 
        <div class="dash-stats">
            <?php if ($role === 'student'): ?>
                <div class="stat-card">
                    <div class="stat-num"><?php echo $enrolledCount; ?></div>
                    <div class="stat-label">Courses Enrolled</div>
                </div>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($upcomingEvents); ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card <?php echo $unreadCount > 0 ? 'stat-card--alert' : ''; ?>">
                    <div class="stat-num"><?php echo $unreadCount; ?></div>
                    <div class="stat-label">Unread Notifications</div>
                </div>
            <?php elseif ($role === 'lecturer'): ?>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($courses); ?></div>
                    <div class="stat-label">Your Courses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($upcomingEvents); ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card <?php echo $unreadCount > 0 ? 'stat-card--alert' : ''; ?>">
                    <div class="stat-num"><?php echo $unreadCount; ?></div>
                    <div class="stat-label">Unread Notifications</div>
                </div>
            <?php else: ?>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($courses); ?></div>
                    <div class="stat-label">Total Courses</div>
                </div>
            <?php endif; ?>
        </div>
            -->

        <div class="dash-layout">

            <!-- ── Left: Courses ──────────────────────────────────────────────────── -->
            <div class="dash-main">
                <div class="panel">
                    <div class="panel-head">
                        <h2>Courses</h2>
                        <p class="muted">
                            <?php if ($role === 'student'): ?>
                                Click a course to register and access its content.
                            <?php elseif ($role === 'lecturer'): ?>
                                Courses assigned to you.
                            <?php else: ?>
                                All courses in the system.
                            <?php endif; ?>
                        </p>
                    </div>
                    <section class="course-grid" style="margin-top:14px;">
                        <?php foreach ($courses as $course):
                            $status = $role === 'student'
                                ? course_status($course, $enrolled)
                                : 'Registered';
                            $meta = strtoupper($course['semester']) . ' • ' . h($course['code']) . ' • ' . h($course['lecturer_name']);
                        ?>
                            <a class="course-card" href="course.php?id=<?php echo (int)$course['id']; ?>">
                                <div class="course-head">
                                    <span class="course-code"><?php echo h($course['code']); ?></span>
                                    <span class="badge">
                                        <?php echo h($status); ?>
                                    </span>
                                </div>
                                <h3><?php echo h($course['title']); ?></h3>
                                <p class="muted"><?php echo $meta; ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if (!$courses): ?>
                            <p class="muted" style="padding:16px 0;">No courses yet.</p>
                        <?php endif; ?>
                    </section>
                </div>
            </div>

            <!-- ── Right: Upcoming events + Notifications ─────────────────────────── -->
            <div class="dash-side">

                <!-- Upcoming events -->
                <div class="panel">
                    <div class="panel-head">
                        <h2>Upcoming Events</h2>
                        <a class="btn tiny secondary" href="calendar.php">View all</a>
                    </div>
                    <?php if (!$upcomingEvents): ?>
                        <p class="muted" style="padding:12px 0;font-size:14px;">
                            No upcoming events.
                            <a href="calendar.php" style="color:#07a701;">Add one →</a>
                        </p>
                    <?php endif; ?>
                    <?php foreach ($upcomingEvents as $ev):
                        $color = $typeColors[$ev['event_type']] ?? '#64748b';
                    ?>
                        <a class="dash-event-row" href="calendar.php" style="border-left-color:<?php echo $color; ?>;">
                            <div class="dash-event-date">
                                <span class="udate-day"><?php echo date('d', strtotime($ev['start_datetime'])); ?></span>
                                <span class="udate-mon"><?php echo date('M', strtotime($ev['start_datetime'])); ?></span>
                            </div>
                            <div class="dash-event-info">
                                <strong><?php echo h($ev['title']); ?></strong>
                                <div class="muted">
                                    <?php echo date('g:i A', strtotime($ev['start_datetime'])); ?>
                                    <?php if ($ev['course_code']): ?>
                                        · <?php echo h($ev['course_code']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="dash-event-type" style="background:<?php echo $color; ?>22;color:<?php echo $color; ?>;">
                                <?php echo ucfirst($ev['event_type']); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Recent notifications -->
                <?php
                $recentNotifs = [];
                try {
                    $rn = db()->prepare('
                    SELECT wn.*, ce.event_type
                    FROM web_notifications wn
                    LEFT JOIN calendar_events ce ON ce.id = wn.event_id
                    WHERE wn.user_id = ?
                    ORDER BY wn.created_at DESC LIMIT 4
                ');
                    $rn->execute([$user['id']]);
                    $recentNotifs = $rn->fetchAll();
                } catch (\Throwable $e) {
                }
                ?>
                <div class="panel">
                    <div class="panel-head">
                        <h2>
                            Notifications
                            <?php if ($unreadCount > 0): ?>
                                <span class="notif-badge" style="margin-left:6px;"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </h2>
                        <a class="btn tiny secondary" href="notifications.php">View all</a>
                    </div>
                    <?php if (!$recentNotifs): ?>
                        <p class="muted" style="padding:12px 0;font-size:14px;">No notifications yet.</p>
                    <?php endif; ?>
                    <?php foreach ($recentNotifs as $n):
                        $color = $typeColors[$n['event_type'] ?? 'general'] ?? '#64748b';
                        $isNew = !$n['is_read'];
                    ?>
                        <div class="dash-notif-row <?php echo $isNew ? 'dash-notif-row--new' : ''; ?>"
                            style="border-left-color:<?php echo $color; ?>;">
                            <span style="font-size:18px;">
                                <?php echo $isNew ? '🔔' : '🔕'; ?>
                            </span>
                            <div class="dash-event-info">
                                <div style="font-size:13px;<?php echo $isNew ? 'font-weight:600;' : ''; ?>">
                                    <?php echo h($n['message']); ?>
                                </div>
                                <div class="muted" style="font-size:11px;">
                                    <?php
                                    $diff = time() - strtotime($n['created_at']);
                                    if ($diff < 60)        echo 'Just now';
                                    elseif ($diff < 3600)  echo (int)($diff / 60) . ' min ago';
                                    elseif ($diff < 86400) echo (int)($diff / 3600) . ' hr ago';
                                    else                   echo (int)($diff / 86400) . ' day(s) ago';
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <?php if ($role === 'admin'): ?>
            <section class="note">
                Admin can create, edit, delete, and upload everything from the management page.
            </section>
        <?php endif; ?>

    </main>
    <script>
        // ── Background poll for due notifications ────────────────────────────────────
        // Runs silently every 60s so reminders fire close to their scheduled time
        // even if you just leave this tab open, instead of needing a manual refresh.
        setInterval(() => {
            fetch('notify_poll.php', {
                method: 'GET',
                cache: 'no-store'
            }).catch(() => {});
        }, 60000);
    </script>
</body>

</html>