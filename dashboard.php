<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$role = $user['role'];

// ── Auto-fire pending WEB notifications on page load (no cron needed) ────────
// Email sending was removed from this page-render path — it used to call
// send_mail() synchronously here, which could block dashboard rendering on
// a slow/blocked SMTP connection. Web notifications stay here (fast,
// DB-only, no network call). Email firing now happens via notify_poll.php's
// background poll (async, doesn't block page paint) and notify_cron.php
// (a real cron job, if configured on the host) — both cover the exact same
// due-reminder rows via the same atomic-claim pattern, so nothing is lost.
try {
    $dueChk = db()->prepare("SELECT COUNT(*) FROM calendar_notifications WHERE scheduled_at <= NOW() AND sent_web=0");
    $dueChk->execute();
    if ((int)$dueChk->fetchColumn() > 0) {
        $cronNow2 = date('Y-m-d H:i:s');
        $cronStmt2 = db()->prepare("
            SELECT cn.id AS notif_id, cn.event_id, cn.user_id, cn.sent_web,
                   ce.title AS event_title, ce.event_type, ce.start_datetime, ce.location, ce.description,
                   ce.notify_web AS ev_notify_web,
                   c.code AS course_code,
                   u.pref_web_notifications
            FROM calendar_notifications cn
            JOIN calendar_events ce ON ce.id = cn.event_id
            JOIN users u ON u.id = cn.user_id
            LEFT JOIN courses c ON c.id = ce.course_id
            WHERE cn.scheduled_at <= ? AND cn.sent_web = 0
            LIMIT 50
        ");
        $cronStmt2->execute([$cronNow2]);
        foreach ($cronStmt2->fetchAll() as $cr) {
            // Claim the row atomically before acting — prevents the same
            // notification firing more than once if two requests (open tabs,
            // overlapping page loads, the background poll, etc.) hit this
            // block within the same brief window.
            if (!$cr['sent_web'] && $cr['ev_notify_web'] && $cr['pref_web_notifications']) {
                $claimWeb2 = db()->prepare('UPDATE calendar_notifications SET sent_web=1 WHERE id=? AND sent_web=0');
                $claimWeb2->execute([$cr['notif_id']]);
                if ($claimWeb2->rowCount() > 0) {
                    $msg2 = reminder_short_message($cr);
                    db()->prepare('INSERT IGNORE INTO web_notifications (user_id, event_id, message) VALUES (?,?,?)')->execute([$cr['user_id'], $cr['event_id'], $msg2]);
                }
            }
        }
    }
} catch (\Throwable $e) { /* silent fail */
}


$allCourses = courses_for_user($user);

$enrolled = [];
if ($role === 'student') {
    $enrolled = enrolled_course_ids((int) $user['id']);
}

$enrolledCount = count($enrolled);

// Homepage course slider: students see only their registered courses (quick
// access, takes less space); lecturer/admin already see just their relevant
// set from courses_for_user(), so no further filtering needed.
$courses = ($role === 'student')
    ? array_values(array_filter($allCourses, fn($c) => isset($enrolled[(int) $c['id']])))
    : $allCourses;

// Computed against the FULL list (before the student filter above) so a
// course keeps the same pattern/color here and on courses.php regardless of
// which subset actually renders — nth-of-type alone can't guarantee that
// once filtering skips courses ahead of it in the canonical order.
$coursePatterns = course_pattern_map($allCourses);

// ── Unread notification count (bell badge) ────────────────────────────────────
$unreadCount = 0;
try {
    $ns = db()->prepare('SELECT COUNT(*) FROM web_notifications WHERE user_id = ? AND is_read = 0');
    $ns->execute([$user['id']]);
    $unreadCount = (int) $ns->fetchColumn();
} catch (\Throwable $e) { /* table may not exist yet */
}

// ── Upcoming events for this user (next 5) ────────────────────────────────────
$upcomingEvents = upcoming_events_for($user, 5);

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
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Dashboard';
    $extraCss = ['assets/calendar.css'];
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar">
        <div>
            <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
            <h1>Welcome, <?php echo h($user['full_name']); ?></h1>
            <p class="muted"><?php echo h(ucfirst($role)); ?> portal</p>
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
                                Your running subjects.
                            <?php elseif ($role === 'lecturer'): ?>
                                Courses assigned to you.
                            <?php else: ?>
                                All courses in the system.
                            <?php endif; ?>
                        </p>
                        <a class="btn tiny secondary" href="courses.php">View all</a>
                    </div>
                    <div class="course-slider-wrap">
                        <?php if (count($courses) > 2): ?>
                            <button type="button" class="slider-arrow slider-arrow--left" onclick="document.getElementById('courseSlider').scrollBy({left:-260,behavior:'smooth'})" aria-label="Scroll left">‹</button>
                        <?php endif; ?>
                        <section class="course-slider" id="courseSlider">
                            <?php foreach ($courses as $course): ?>
                                <a class="course-card" href="course.php?id=<?php echo (int)$course['id']; ?>" data-pattern="<?php echo $coursePatterns[(int) $course['id']] ?? 1; ?>" style="--course-color:<?php echo h($course['color'] ?: '#2563eb'); ?>;">
                                    <span class="course-code"><?php echo h($course['code']); ?></span>
                                    <h3><?php echo h($course['title']); ?></h3>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$courses): ?>
                                <p class="muted" style="padding:16px 0;">
                                    <?php echo $role === 'student' ? 'No registered courses yet — ' : 'No courses yet.'; ?>
                                    <?php if ($role === 'student'): ?><a href="courses.php" style="color:var(--primary-dark);">browse courses →</a><?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </section>
                        <?php if (count($courses) > 2): ?>
                            <button type="button" class="slider-arrow slider-arrow--right" onclick="document.getElementById('courseSlider').scrollBy({left:260,behavior:'smooth'})" aria-label="Scroll right">›</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Right: Upcoming events + Notifications ─────────────────────────── -->
            <div class="dash-side">

                <!-- Upcoming events -->
                <div class="panel">
                    <div class="panel-head panel-head--dash">
                        <div class="panel-head-icon">
                            <span class="panel-icon"><i class="bi bi-calendar3"></i></span>
                            <div>
                                <h2>Upcoming Events</h2>
                                <p class="muted panel-subtitle">Don't miss what's coming up.</p>
                            </div>
                        </div>
                        <a class="btn tiny secondary pill-cta" href="calendar.php">View calendar <i class="bi bi-chevron-right"></i></a>
                    </div>
                    <?php if (!$upcomingEvents): ?>
                        <p class="muted" style="padding:12px 0;font-size:14px;">
                            No upcoming events.
                            <a href="calendar.php" style="color:var(--primary-dark);">Add one →</a>
                        </p>
                    <?php endif; ?>
                    <?php foreach ($upcomingEvents as $ev):
                        $color = $typeColors[$ev['event_type']] ?? '#64748b';
                    ?>
                        <a class="dash-event-row" href="calendar.php" style="border-left-color:<?php echo $color; ?>;">
                            <div class="dash-event-date" style="background:<?php echo $color; ?>18;">
                                <span class="udate-day" style="color:<?php echo $color; ?>;"><?php echo date('d', strtotime($ev['start_datetime'])); ?></span>
                                <span class="udate-mon" style="color:<?php echo $color; ?>;"><?php echo date('M', strtotime($ev['start_datetime'])); ?></span>
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
                    <div class="panel-head panel-head--dash">
                        <div class="panel-head-icon">
                            <span class="panel-icon"><i class="bi bi-bell-fill"></i></span>
                            <div>
                                <h2>
                                    Notifications
                                    <?php if ($unreadCount > 0): ?>
                                        <span class="notif-badge" style="margin-left:6px;"><?php echo $unreadCount; ?></span>
                                    <?php endif; ?>
                                </h2>
                                <p class="muted panel-subtitle">Stay updated with important activities.</p>
                            </div>
                        </div>
                        <a class="btn tiny secondary pill-cta" href="notifications.php">View all <i class="bi bi-chevron-right"></i></a>
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
                            <span class="dash-notif-icon" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>;">
                                <i class="bi <?php echo $isNew ? 'bi-bell-fill' : 'bi-bell-slash'; ?>"></i>
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