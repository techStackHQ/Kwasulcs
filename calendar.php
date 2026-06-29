<?php
require_once __DIR__ . '/config.php';
require_login();

$user    = current_user();
$role    = $user['role'];
$isStaff = in_array($role, ['admin', 'lecturer'], true);

// ── Month navigation ──────────────────────────────────────────────────────────
$year  = (int) ($_GET['y'] ?? date('Y'));
$month = (int) ($_GET['m'] ?? date('n'));
if ($month < 1) {
    $month = 12;
    $year--;
}
if ($month > 12) {
    $month = 1;
    $year++;
}

$monthStart   = mktime(0, 0, 0, $month, 1, $year);
$monthEnd     = mktime(23, 59, 59, $month, (int) date('t', $monthStart), $year);
$monthLabel   = date('F Y', $monthStart);
$firstWeekday = (int) date('w', $monthStart);
$daysInMonth  = (int) date('t', $monthStart);

// ── FIX: today only highlights when viewing the actual current month/year ────
$realToday      = (int)  date('j');
$realTodayMonth = (int)  date('n');
$realTodayYear  = (int)  date('Y');
$todayFull      = date('Y-m-d');

// ── Course filter ─────────────────────────────────────────────────────────────
$filterCourseId = (int) ($_GET['course'] ?? 0);

// ── Load courses the user can see ─────────────────────────────────────────────
if ($role === 'admin') {
    $myCourses = db()->query('SELECT id, code, title FROM courses ORDER BY code')->fetchAll();
} elseif ($role === 'lecturer') {
    $s = db()->prepare('SELECT id, code, title FROM courses WHERE lecturer_id = ? ORDER BY code');
    $s->execute([$user['id']]);
    $myCourses = $s->fetchAll();
} else {
    $s = db()->prepare('
        SELECT c.id, c.code, c.title FROM courses c
        JOIN enrollments e ON e.course_id = c.id
        WHERE e.student_id = ?
        ORDER BY c.code
    ');
    $s->execute([$user['id']]);
    $myCourses = $s->fetchAll();
}
$myCourseIds = array_column($myCourses, 'id');

// ── Load events for the visible month ────────────────────────────────────────
$startStr     = date('Y-m-d 00:00:00', $monthStart);
$endStr       = date('Y-m-d 23:59:59', $monthEnd);
$whereClauses = [];
$params       = [];

$whereClauses[] = "(e.scope = 'global')";

if ($myCourseIds) {
    $inPH = implode(',', array_fill(0, count($myCourseIds), '?'));
    if ($filterCourseId > 0 && in_array($filterCourseId, $myCourseIds, true)) {
        $whereClauses[] = "(e.scope = 'course' AND e.course_id = ?)";
        $params[]       = $filterCourseId;
    } else {
        $whereClauses[] = "(e.scope = 'course' AND e.course_id IN ($inPH))";
        $params         = array_merge($params, $myCourseIds);
    }
}

$whereClauses[] = "(e.scope = 'personal' AND e.created_by = ?)";
$params[]       = $user['id'];

$whereSQL = implode(' OR ', $whereClauses);

// For recurring events: the original start may be months in the past, but
// occurrences still fall in this month. We fetch them with a looser date
// filter — expand_recurring_events() will then generate the correct dates.
// Non-recurring events still use the tight window for performance.
$evtStmt = db()->prepare("
    SELECT e.*, c.code AS course_code
    FROM calendar_events e
    LEFT JOIN courses c ON c.id = e.course_id
    WHERE ($whereSQL)
      AND (
          -- Non-recurring: must overlap with this month
          (e.is_recurring = 0 AND e.start_datetime <= ? AND e.end_datetime >= ?)
          OR
          -- Recurring: started before month end AND recurrence hasn't ended before month start
          (e.is_recurring = 1 AND e.start_datetime <= ?
           AND (e.recurrence_end_date IS NULL OR e.recurrence_end_date >= ?))
      )
    ORDER BY e.start_datetime ASC
");
$evtStmt->execute(array_merge($params, [
    $endStr,
    $startStr,  // non-recurring window
    $endStr,
    $startStr,  // recurring window
]));
$allEvents = $evtStmt->fetchAll();

// ── Expand recurring events into occurrences within this month ────────────────
//
// KEY FIX: Instead of walking from the original start (which could be months
// ago, wasting iterations), we JUMP directly to the first occurrence that
// falls within or just before the target month window, then walk forward.
// This makes biweekly and monthly work correctly across any number of months.
//
function expand_recurring_events(array $events, int $monthStart, int $monthEnd): array
{
    $expanded = [];

    foreach ($events as $ev) {
        // Non-recurring: pass through as-is
        if (!$ev['is_recurring'] || !$ev['recurrence_rule']) {
            $expanded[] = $ev;
            continue;
        }

        $origStart = strtotime($ev['start_datetime']);
        $origEnd   = strtotime($ev['end_datetime']);
        $duration  = $origEnd - $origStart;

        // Hard limit: don't show occurrences past recurrence_end_date
        $recurrEnd = $ev['recurrence_end_date']
            ? strtotime($ev['recurrence_end_date'] . ' 23:59:59')
            : PHP_INT_MAX;

        // ── Compute first cursor position at or before monthStart ─────────────
        // Rather than walking all the way from origStart, we calculate how many
        // intervals fit between origStart and monthStart and jump there.
        if ($ev['recurrence_rule'] === 'monthly') {
            // Monthly: same day-of-month, advance month-by-month
            // Find the month/year of the first occurrence that could hit our window
            $origDay  = (int) date('j', $origStart);
            $origH    = (int) date('H', $origStart);
            $origI    = (int) date('i', $origStart);

            $viewYear  = (int) date('Y', $monthStart);
            $viewMonth = (int) date('n', $monthStart);
            $origYear  = (int) date('Y', $origStart);
            $origMonth = (int) date('n', $origStart);

            // How many months from origin to the start of the view window?
            $monthsElapsed = ($viewYear - $origYear) * 12 + ($viewMonth - $origMonth);
            // Jump to that month (or the one before to be safe)
            $jumpMonths = max(0, $monthsElapsed - 1);
            $startYear  = $origYear  + intdiv($origMonth - 1 + $jumpMonths, 12);
            $startMonth = (($origMonth - 1 + $jumpMonths) % 12) + 1;
            $cursor = mktime($origH, $origI, 0, $startMonth, $origDay, $startYear);
        } elseif (in_array($ev['recurrence_rule'], ['daily', 'weekly', 'biweekly'])) {
            $stepSecs = match ($ev['recurrence_rule']) {
                'daily'    => 86400,
                'weekly'   => 604800,
                'biweekly' => 1209600,
            };
            if ($origStart >= $monthStart) {
                // Origin is this month or future — start from origin
                $cursor = $origStart;
            } else {
                // Jump: how many full intervals fit between origStart and monthStart?
                $elapsed  = $monthStart - $origStart;
                $steps    = (int) floor($elapsed / $stepSecs);
                // Step back one to make sure we don't miss a boundary occurrence
                $cursor   = $origStart + max(0, $steps - 1) * $stepSecs;
            }
        } else {
            $cursor = $origStart;
        }

        // ── Walk forward generating occurrences that overlap the month ────────
        $safety = 0;
        while ($cursor <= $monthEnd && $cursor <= $recurrEnd && $safety++ < 500) {
            $occEnd = $cursor + $duration;

            // Include if this occurrence overlaps the visible month window
            if ($occEnd >= $monthStart && $cursor <= $monthEnd && $cursor >= $origStart) {
                $occ = $ev;
                $occ['start_datetime'] = date('Y-m-d H:i:s', $cursor);
                $occ['end_datetime']   = date('Y-m-d H:i:s', $occEnd);
                $occ['_is_occurrence'] = true;
                $expanded[] = $occ;
            }

            // Advance to next occurrence
            if ($ev['recurrence_rule'] === 'monthly') {
                $nextM = (int)date('n', $cursor) + 1;
                $nextY = (int)date('Y', $cursor);
                if ($nextM > 12) {
                    $nextM = 1;
                    $nextY++;
                }
                $cursor = mktime(
                    (int)date('H', $origStart),
                    (int)date('i', $origStart),
                    0,
                    $nextM,
                    (int)date('j', $origStart),
                    $nextY
                );
            } else {
                $stepSecs = match ($ev['recurrence_rule']) {
                    'daily'    => 86400,
                    'weekly'   => 604800,
                    'biweekly' => 1209600,
                    default    => 604800,
                };
                $cursor += $stepSecs;
            }
        }
    }

    usort($expanded, fn($a, $b) => strtotime($a['start_datetime']) <=> strtotime($b['start_datetime']));
    return $expanded;
}

$allEvents = expand_recurring_events($allEvents, $monthStart, $monthEnd);

// Index events by day for grid rendering
$eventsByDay = [];
foreach ($allEvents as $ev) {
    $evStart = max(strtotime($ev['start_datetime']), $monthStart);
    $evEnd   = min(strtotime($ev['end_datetime']),   $monthEnd);
    for ($d = (int) date('j', $evStart); $d <= (int) date('j', $evEnd); $d++) {
        $eventsByDay[$d][] = $ev;
    }
}

// ── Auto-fire pending notifications on page load (XAMPP-friendly) ────────────
// On a real server use a cron job instead. This runs silently in the background.
try {
    $dueCount = db()->prepare("
        SELECT COUNT(*) FROM calendar_notifications
        WHERE scheduled_at <= NOW() AND (sent_web = 0 OR sent_email = 0)
    ");
    $dueCount->execute();
    if ((int)$dueCount->fetchColumn() > 0) {
        // Fire notifications inline — suppresses output
        ob_start();
        $cronNow = date('Y-m-d H:i:s');
        $cronStmt = db()->prepare("
            SELECT cn.id AS notif_id, cn.event_id, cn.user_id, cn.sent_email, cn.sent_web,
                   ce.title AS event_title, ce.event_type, ce.start_datetime, ce.location,
                   ce.notify_email AS ev_notify_email, ce.notify_web AS ev_notify_web,
                   u.email AS user_email, u.full_name AS user_name
            FROM calendar_notifications cn
            JOIN calendar_events ce ON ce.id = cn.event_id
            JOIN users u ON u.id = cn.user_id
            WHERE cn.scheduled_at <= ? AND (cn.sent_email = 0 OR cn.sent_web = 0)
            ORDER BY cn.scheduled_at ASC LIMIT 50
        ");
        $cronStmt->execute([$cronNow]);
        foreach ($cronStmt->fetchAll() as $cronRow) {
            $cronFmt = date('D, d M Y \\at g:i A', strtotime($cronRow['start_datetime']));

            // ── Web notification: claim FIRST, then act ───────────────────────
            // CRITICAL FIX: previously this inserted the web notification and
            // sent the email BEFORE marking sent_web/sent_email = 1. If two
            // requests ran this block within the same brief window (two tabs,
            // a page load racing a background fetch, etc.) — before either had
            // finished updating the row — both would see sent_email = 0 and
            // both would send. Claiming the row atomically first (an UPDATE
            // that only succeeds if sent_web/sent_email is still 0) means only
            // ONE concurrent request can ever win; the other's UPDATE affects
            // 0 rows and it correctly skips sending.
            if (!$cronRow['sent_web'] && $cronRow['ev_notify_web']) {
                $claimWeb = db()->prepare('UPDATE calendar_notifications SET sent_web = 1 WHERE id = ? AND sent_web = 0');
                $claimWeb->execute([$cronRow['notif_id']]);
                if ($claimWeb->rowCount() > 0) {
                    $cronMsg = "Reminder: \"{$cronRow['event_title']}\" starts {$cronFmt}";
                    if ($cronRow['location']) $cronMsg .= " @ {$cronRow['location']}";
                    db()->prepare('INSERT IGNORE INTO web_notifications (user_id, event_id, message) VALUES (?,?,?)')
                        ->execute([$cronRow['user_id'], $cronRow['event_id'], $cronMsg]);
                }
            }

            // ── Email notification: claim FIRST, then act ─────────────────────
            if (!$cronRow['sent_email'] && $cronRow['ev_notify_email'] && $cronRow['user_email']) {
                $claimEmail = db()->prepare('UPDATE calendar_notifications SET sent_email = 1 WHERE id = ? AND sent_email = 0');
                $claimEmail->execute([$cronRow['notif_id']]);
                if ($claimEmail->rowCount() > 0) {
                    $cronSubject = "[KWASU LCS] Reminder: {$cronRow['event_title']}";
                    $cronHtml = "<div style='font-family:sans-serif;'><h2>📅 {$cronRow['event_title']}</h2>
                        <p><strong>When:</strong> {$cronFmt}</p>" .
                        ($cronRow['location'] ? "<p><strong>Where:</strong> {$cronRow['location']}</p>" : "") .
                        "<p style='color:#64748b;font-size:13px;'>KWASU LCS automated reminder.</p></div>";
                    $sendOk = send_mail($cronRow['user_email'], $cronRow['user_name'], $cronSubject, $cronHtml);
                    if (!$sendOk) {
                        // Sending genuinely failed — release the claim so it
                        // can be retried on the next page load instead of
                        // being permanently (and incorrectly) marked as sent.
                        db()->prepare('UPDATE calendar_notifications SET sent_email = 0 WHERE id = ?')
                            ->execute([$cronRow['notif_id']]);
                    }
                }
            }
        }
        ob_end_clean();
    }
} catch (\Throwable $e) { /* silent fail — don't break the page */
}

// ── Unread notifications ──────────────────────────────────────────────────────
$notifCount = 0;
try {
    $ns = db()->prepare('SELECT COUNT(*) FROM web_notifications WHERE user_id = ? AND is_read = 0');
    $ns->execute([$user['id']]);
    $notifCount = (int) $ns->fetchColumn();
} catch (\Throwable $e) { /* table may not exist yet */
}

// ── Prev/Next month links ─────────────────────────────────────────────────────
$prevM = $month === 1  ? 12 : $month - 1;
$prevY = $month === 1  ? $year - 1 : $year;
$nextM = $month === 12 ? 1  : $month + 1;
$nextY = $month === 12 ? $year + 1 : $year;
$courseParam = $filterCourseId ? '&course=' . $filterCourseId : '';

$typeColors = [
    'lecture'  => '#2563eb',
    'tutorial' => '#7c3aed',
    'exam'     => '#dc2626',
    'test'     => '#ea580c',
    'personal' => '#059669',
    'general'  => '#0891b2',
];

// ── Flash messages from redirects ─────────────────────────────────────────────
$flashMsg = $_SESSION['cal_flash'] ?? '';
$flashErr = $_SESSION['cal_error'] ?? '';
unset($_SESSION['cal_flash'], $_SESSION['cal_error']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar — KWASU LCS</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/calendar.css">
    <script src="assets/theme.js" defer></script>
    <style>
        /* ── Custom time inputs ─────────────────────────────────────────── */
        .time-input-wrap {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 10px 14px;
            transition: border-color .15s;
        }

        .time-input-wrap:focus-within {
            border-color: #07a701;
            outline: 2px solid rgba(7, 167, 1, .15);
        }

        .time-part {
            width: 44px;
            border: none !important;
            outline: none !important;
            padding: 0 !important;
            border-radius: 0 !important;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            text-align: center;
            background: transparent;
        }

        .time-part::-webkit-inner-spin-button,
        .time-part::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .time-colon {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1;
            padding: 0 2px;
        }

        .time-ampm {
            font-size: 12px;
            font-weight: 800;
            color: #07a701;
            min-width: 26px;
            text-align: right;
        }
    </style>
</head>

<body class="app-body">

    <header class="topbar">
        <div>
            <div class="eyebrow">KWASU LCS</div>
            <h1>📅 Academic Calendar</h1>
            <p class="muted">
                <?php if ($filterCourseId > 0): ?>
                    <?php foreach ($myCourses as $mc): if ((int)$mc['id'] === $filterCourseId): ?>
                            Showing: <strong><?php echo h($mc['code'] . ' — ' . $mc['title']); ?></strong>
                    <?php endif;
                    endforeach; ?>
                <?php else: ?>
                    All courses &amp; personal schedule
                <?php endif; ?>
            </p>
        </div>
        <div class="topbar-actions">
            <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
            <?php if ($notifCount > 0): ?>
                <a class="btn secondary" href="notifications.php" style="position:relative;">
                    🔔 <span class="notif-badge"><?php echo $notifCount; ?></span>
                </a>
            <?php endif; ?>
            <button class="btn primary" id="btnNewEvent">+ <?php echo $isStaff ? 'New Event' : 'Personal Event'; ?></button>
            <a class="btn secondary" href="dashboard.php">Dashboard</a>
            <a class="btn danger" href="logout.php">Logout</a>
        </div>
    </header>

    <main class="page">

        <?php if ($flashMsg): ?>
            <div class="alert success"><?php echo h($flashMsg); ?></div>
        <?php endif; ?>
        <?php if ($flashErr): ?>
            <div class="alert error"><?php echo h($flashErr); ?></div>
        <?php endif; ?>

        <!-- ── Controls ──────────────────────────────────────────────────────────── -->
        <div class="cal-controls">
            <div class="cal-nav">
                <a class="btn secondary" href="calendar.php?y=<?php echo $prevY; ?>&m=<?php echo $prevM . $courseParam; ?>">‹ Prev</a>
                <span class="cal-month-label"><?php echo $monthLabel; ?></span>
                <a class="btn secondary" href="calendar.php?y=<?php echo $nextY; ?>&m=<?php echo $nextM . $courseParam; ?>">Next ›</a>
                <a class="btn secondary" href="calendar.php<?php echo $courseParam ? '?' . ltrim($courseParam, '&') : ''; ?>">Today</a>
            </div>

            <form class="cal-filter-form" method="get">
                <input type="hidden" name="y" value="<?php echo $year; ?>">
                <input type="hidden" name="m" value="<?php echo $month; ?>">
                <select name="course" onchange="this.form.submit()">
                    <option value="0" <?php echo $filterCourseId === 0 ? 'selected' : ''; ?>>All Courses</option>
                    <?php foreach ($myCourses as $mc): ?>
                        <option value="<?php echo (int)$mc['id']; ?>" <?php echo (int)$mc['id'] === $filterCourseId ? 'selected' : ''; ?>>
                            <?php echo h($mc['code'] . ' — ' . $mc['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="cal-legend">
                <?php foreach ($typeColors as $type => $color): ?>
                    <span class="legend-dot" style="background:<?php echo $color; ?>"></span>
                    <span class="legend-label"><?php echo ucfirst($type); ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Month grid ────────────────────────────────────────────────────────── -->
        <div class="cal-grid-wrap panel">
            <div class="cal-dow-row">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                    <div class="cal-dow"><?php echo $dow; ?></div>
                <?php endforeach; ?>
            </div>

            <div class="cal-days">
                <?php for ($b = 0; $b < $firstWeekday; $b++): ?>
                    <div class="cal-day cal-day--blank"></div>
                <?php endfor; ?>

                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    // FIX: only highlight if we're actually viewing the current real month+year
                    $isToday   = ($d === $realToday && $month === $realTodayMonth && $year === $realTodayYear);
                    $dayEvents = $eventsByDay[$d] ?? [];
                    $dateStr   = sprintf('%04d-%02d-%02d', $year, $month, $d);
                ?>
                    <div class="cal-day <?php echo $isToday ? 'cal-day--today' : ''; ?>"
                        data-date="<?php echo $dateStr; ?>">
                        <span class="cal-day-num"><?php echo $d; ?></span>

                        <?php foreach (array_slice($dayEvents, 0, 3) as $ev):
                            $color = $typeColors[$ev['event_type']] ?? '#64748b';
                        ?>
                            <button class="cal-event-chip"
                                style="border-left-color:<?php echo $color; ?>;background:<?php echo $color; ?>18;"
                                data-evid="<?php echo (int)$ev['id']; ?>"
                                title="<?php echo h($ev['title']); ?>">
                                <span class="chip-dot" style="background:<?php echo $color; ?>"></span>
                                <span class="chip-time"><?php echo date('g:ia', strtotime($ev['start_datetime'])); ?></span>
                                <span class="chip-title"><?php echo h($ev['title']); ?></span>
                            </button>
                        <?php endforeach; ?>

                        <?php if (count($dayEvents) > 3): ?>
                            <button class="cal-more-chip" data-date="<?php echo $dateStr; ?>">
                                +<?php echo count($dayEvents) - 3; ?> more
                            </button>
                        <?php endif; ?>

                        <button class="cal-add-btn" data-date="<?php echo $dateStr; ?>" title="Add event">+</button>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ── Upcoming list ─────────────────────────────────────────────────────── -->
        <div class="cal-upcoming panel">
            <h2>Upcoming Events — <?php echo $monthLabel; ?></h2>
            <?php
            $upcoming = array_values(array_filter(
                $allEvents,
                fn($ev) =>
                strtotime($ev['start_datetime']) >= strtotime($todayFull)
            ));
            ?>
            <?php if (!$upcoming): ?>
                <p class="muted cal-empty">No upcoming events this month. Click <strong>+ New Event</strong> to add one.</p>
            <?php endif; ?>
            <?php foreach (array_slice($upcoming, 0, 15) as $ev):
                $color = $typeColors[$ev['event_type']] ?? '#64748b';
            ?>
                <div class="upcoming-item" style="border-left-color:<?php echo $color; ?>;" data-evid="<?php echo (int)$ev['id']; ?>">
                    <div class="upcoming-date">
                        <span class="udate-day"><?php echo date('d', strtotime($ev['start_datetime'])); ?></span>
                        <span class="udate-mon"><?php echo date('M', strtotime($ev['start_datetime'])); ?></span>
                    </div>
                    <div class="upcoming-info">
                        <strong><?php echo h($ev['title']); ?></strong>
                        <div class="muted">
                            <?php echo date('g:i A', strtotime($ev['start_datetime'])); ?>
                            –
                            <?php echo date('g:i A', strtotime($ev['end_datetime'])); ?>
                            <?php if ($ev['course_code']): ?>
                                &nbsp;·&nbsp; <?php echo h($ev['course_code']); ?>
                            <?php endif; ?>
                            <?php if ($ev['is_recurring']): ?>
                                &nbsp;·&nbsp; 🔁 <?php echo h(recurrence_label($ev['recurrence_rule'] ?? '')); ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($ev['description']): ?>
                            <div class="upcoming-desc"><?php echo h($ev['description']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="upcoming-actions">
                        <span class="badge ev-badge" style="background:<?php echo $color; ?>22;color:<?php echo $color; ?>;border:1px solid <?php echo $color; ?>55;">
                            <?php echo ucfirst(str_replace('_', ' ', $ev['event_type'])); ?>
                        </span>
                        <?php if ($isStaff || (int)$ev['created_by'] === (int)$user['id']): ?>
                            <button class="btn tiny secondary btn-edit-ev" data-evid="<?php echo (int)$ev['id']; ?>">Edit</button>
                            <form method="post" action="calendar_delete.php"
                                onsubmit="return confirm('Delete this event?');"
                                style="display:inline;">
                                <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                                <input type="hidden" name="redirect" value="calendar.php?y=<?php echo $year; ?>&amp;m=<?php echo $month; ?><?php echo $filterCourseId ? '&amp;course=' . $filterCourseId : ''; ?>">
                                <button class="btn tiny danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </main>

    <!-- ══ DETAIL MODAL ══════════════════════════════════════════════════════════ -->
    <div class="modal-overlay" id="detailOverlay" hidden>
        <div class="modal-box">
            <div class="modal-header">
                <h2 id="detailTitle"></h2>
                <button class="modal-close" id="closeDetail">✕</button>
            </div>
            <div class="modal-body" id="detailBody"></div>
            <div class="modal-footer" id="detailFooter"></div>
        </div>
    </div>

    <!-- ══ CREATE/EDIT MODAL ═════════════════════════════════════════════════════ -->
    <div class="modal-overlay" id="formOverlay" hidden>
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h2 id="formTitle">New Event</h2>
                <button class="modal-close" id="closeForm">✕</button>
            </div>
            <div class="modal-body">
                <form id="eventForm" method="post" action="calendar_save.php" class="form-stack">
                    <input type="hidden" name="event_id" id="fEventId" value="0">
                    <input type="hidden" name="redirect"
                        value="calendar.php?y=<?php echo $year; ?>&amp;m=<?php echo $month; ?><?php echo $filterCourseId ? '&amp;course=' . $filterCourseId : ''; ?>">

                    <div class="form-grid-cal">
                        <label>Title <span class="req">*</span>
                            <input type="text" name="title" id="fTitle" required placeholder="e.g. CSC401 Lecture">
                        </label>
                        <label>Event Type <span class="req">*</span>
                            <select name="event_type" id="fType" required>
                                <option value="lecture">Lecture</option>
                                <option value="tutorial">Tutorial</option>
                                <option value="exam">Exam</option>
                                <option value="test">Test</option>
                                <option value="general">General</option>
                                <option value="personal">Personal</option>
                            </select>
                        </label>
                        <label>Start date <span class="req">*</span>
                            <input type="date" name="start_date" id="fStartDate" required>
                        </label>
                        <label>Start time <span class="req">*</span>
                            <div class="time-input-wrap">
                                <input type="number" id="fStartH" min="0" max="23" placeholder="HH"
                                    class="time-part" aria-label="Start hour">
                                <span class="time-colon">:</span>
                                <input type="number" id="fStartM" min="0" max="59" placeholder="MM"
                                    class="time-part" aria-label="Start minute">
                                <span class="time-ampm" id="fStartAMPM">AM</span>
                            </div>
                        </label>
                        <label>End date <span class="req">*</span>
                            <input type="date" name="end_date" id="fEndDate" required>
                        </label>
                        <label>End time <span class="req">*</span>
                            <div class="time-input-wrap">
                                <input type="number" id="fEndH" min="0" max="23" placeholder="HH"
                                    class="time-part" aria-label="End hour">
                                <span class="time-colon">:</span>
                                <input type="number" id="fEndM" min="0" max="59" placeholder="MM"
                                    class="time-part" aria-label="End minute">
                                <span class="time-ampm" id="fEndAMPM">AM</span>
                            </div>
                        </label>
                        <!-- Hidden combined fields sent to calendar_save.php -->
                        <input type="hidden" name="start_datetime" id="fStart">
                        <input type="hidden" name="end_datetime" id="fEnd">
                    </div>

                    <label>Description
                        <textarea name="description" id="fDesc" rows="2" placeholder="Optional…"></textarea>
                    </label>
                    <label>Location
                        <input type="text" name="location" id="fLocation" placeholder="e.g. Room 204, Senate Building">
                    </label>

                    <?php if ($isStaff): ?>
                        <div class="form-grid-cal">
                            <label>Visibility <span class="req">*</span>
                                <select name="scope" id="fScope" required>
                                    <option value="personal">Personal only</option>
                                    <option value="course">Course (enrolled students see this)</option>
                                    <?php if ($role === 'admin'): ?>
                                        <option value="global">Global (all users)</option>
                                    <?php endif; ?>
                                </select>
                            </label>
                            <label id="fCourseWrap" style="display:none;">Course <span class="req">*</span>
                                <select name="course_id" id="fCourse">
                                    <option value="">Select course…</option>
                                    <?php foreach ($myCourses as $mc): ?>
                                        <option value="<?php echo (int)$mc['id']; ?>"
                                            <?php echo $filterCourseId === (int)$mc['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($mc['code'] . ' — ' . $mc['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="scope" value="personal">
                    <?php endif; ?>

                    <div class="recurrence-row">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_recurring" id="fRecurring" value="1">
                            🔁 Repeat this event
                        </label>
                    </div>
                    <div id="recurrenceFields" class="form-grid-cal" style="display:none;">
                        <label>Repeat every
                            <select name="recurrence_rule" id="fRule">
                                <option value="daily">Day</option>
                                <option value="weekly" selected>Week</option>
                                <option value="biweekly">2 Weeks</option>
                                <option value="monthly">Month</option>
                            </select>
                        </label>
                        <label>Until (leave blank = no end)
                            <input type="date" name="recurrence_end_date" id="fRecEnd">
                        </label>
                    </div>

                    <div class="notif-row">
                        <span class="notif-label">Remind via</span>
                        <label class="checkbox-label">
                            <input type="checkbox" name="notify_email" id="fEmail" value="1" checked> Email
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="notify_web" id="fWeb" value="1" checked> Notification
                        </label>
                        <select name="remind_minutes_before" id="fRemind">
                            <option value="15">15 min before</option>
                            <option value="30" selected>30 min before</option>
                            <option value="60">1 hour before</option>
                            <option value="120">2 hours before</option>
                            <option value="1440">1 day before</option>
                        </select>
                    </div>

                    <div class="modal-footer" style="padding:0;border:0;margin-top:4px;">
                        <button type="submit" class="btn primary" id="fSubmitBtn">Create Event</button>
                        <button type="button" class="btn secondary" id="cancelForm">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ DAY-VIEW MODAL ════════════════════════════════════════════════════════ -->
    <div class="modal-overlay" id="dayOverlay" hidden>
        <div class="modal-box">
            <div class="modal-header">
                <h2 id="dayTitle">Events</h2>
                <button class="modal-close" id="closeDay">✕</button>
            </div>
            <div class="modal-body" id="dayBody"></div>
        </div>
    </div>

    <!-- ══ DATA + JS ═════════════════════════════════════════════════════════════ -->
    <script>
        'use strict';

        // ── Data injected from PHP ──────────────────────────────────────────────────
        const ALL_EVENTS = <?php echo json_encode(array_values($allEvents), JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        const TYPE_COLORS = <?php echo json_encode($typeColors); ?>;
        const IS_STAFF = <?php echo $isStaff ? 'true' : 'false'; ?>;
        const USER_ID = <?php echo (int)$user['id']; ?>;
        const FILTER_COURSE = <?php echo $filterCourseId; ?>;

        // ── Time input helpers — global scope so all modal functions can access them ──
        function getTimePart(id, min, max, def) {
            const el = document.getElementById(id);
            if (!el) return String(def).padStart(2, '0');
            let v = parseInt(el.value, 10);
            if (isNaN(v)) v = def;
            v = Math.max(min, Math.min(max, v));
            el.value = v;
            return String(v).padStart(2, '0');
        }

        function updateAMPM(hourId, ampmId) {
            const h = parseInt(document.getElementById(hourId)?.value, 10);
            const el = document.getElementById(ampmId);
            if (el && !isNaN(h)) el.textContent = h < 12 ? 'AM' : 'PM';
        }

        function setTimeInputs(prefix, hhmm) {
            if (!hhmm) return;
            const [h, m] = hhmm.split(':').map(Number);
            const hEl = document.getElementById(prefix + 'H');
            const mEl = document.getElementById(prefix + 'M');
            if (hEl) hEl.value = h;
            if (mEl) mEl.value = String(m).padStart(2, '0');
            updateAMPM(prefix + 'H', prefix + 'AMPM');
        }

        function getTimeString(prefix) {
            const h = getTimePart(prefix + 'H', 0, 23, 8);
            const m = getTimePart(prefix + 'M', 0, 59, 0);
            return h + ':' + m;
        }

        // ── Modal helpers ──────────────────────────────────────────────────────────
        function closeModals() {
            document.querySelectorAll('.modal-overlay').forEach(el => el.hidden = true);
            // Always clear the event form's hidden event_id when any modal closes.
            // Without this, closing an Edit form via X/outside-click/Escape (instead
            // of Cancel or Submit) left fEventId stuck on the edited event's id —
            // so the NEXT "New Event" click could silently resubmit as an edit of
            // that old event instead of creating a new one.
            resetForm();
        }

        // ── Wire close buttons (safe — runs after DOM is ready) ──────────────────
        document.addEventListener('DOMContentLoaded', function() {

            // Close buttons
            document.getElementById('closeDetail').addEventListener('click', closeModals);
            document.getElementById('closeForm').addEventListener('click', closeModals);
            document.getElementById('closeDay').addEventListener('click', closeModals);
            document.getElementById('cancelForm').addEventListener('click', closeModals);

            // Click outside modal box to close
            document.querySelectorAll('.modal-overlay').forEach(ov => {
                ov.addEventListener('click', e => {
                    if (e.target === ov) closeModals();
                });
            });

            // Escape key
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') closeModals();
            });

            // ── "+ New Event" header button ────────────────────────────────────────
            document.getElementById('btnNewEvent').addEventListener('click', () => openNewEvent(''));

            // ── Day cell: "+" add button ───────────────────────────────────────────
            document.querySelectorAll('.cal-add-btn').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    openNewEvent(btn.dataset.date);
                });
            });

            // ── Day cell: event chip click ─────────────────────────────────────────
            document.querySelectorAll('.cal-event-chip').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    openEventDetail(Number(btn.dataset.evid));
                });
            });

            // ── Day cell: "+N more" chip ───────────────────────────────────────────
            document.querySelectorAll('.cal-more-chip').forEach(btn => {
                btn.addEventListener('click', () => openDayView(btn.dataset.date));
            });

            // ── Upcoming list: row click for detail ───────────────────────────────
            document.querySelectorAll('.upcoming-item[data-evid]').forEach(row => {
                row.style.cursor = 'pointer';
                row.addEventListener('click', e => {
                    // Don't trigger if clicking edit/delete buttons
                    if (e.target.closest('button, form')) return;
                    openEventDetail(Number(row.dataset.evid));
                });
            });

            // ── Upcoming list: edit buttons ───────────────────────────────────────
            document.querySelectorAll('.btn-edit-ev').forEach(btn => {
                btn.addEventListener('click', () => openEditEvent(Number(btn.dataset.evid)));
            });

            // ── Scope dropdown → show/hide course picker ──────────────────────────
            const scopeEl = document.getElementById('fScope');
            if (scopeEl) {
                scopeEl.addEventListener('change', () => toggleScopeFields(scopeEl.value));
            }

            // ── Recurrence checkbox ───────────────────────────────────────────────
            const recurChk = document.getElementById('fRecurring');
            if (recurChk) {
                recurChk.addEventListener('change', () => toggleRecurrence(recurChk.checked));
            }

            // Default times when form opens fresh
            setTimeInputs('fStart', '08:00');
            setTimeInputs('fEnd', '09:00');

            // Live AM/PM update
            ['fStartH', 'fStartM', 'fEndH', 'fEndM'].forEach(id => {
                document.getElementById(id)?.addEventListener('input', () => {
                    if (id.startsWith('fStart')) updateAMPM('fStartH', 'fStartAMPM');
                    else updateAMPM('fEndH', 'fEndAMPM');
                });
            });

            // ── Sync hidden datetime fields before form submit ─────────────────
            document.getElementById('eventForm').addEventListener('submit', function(e) {
                const sd = document.getElementById('fStartDate').value;
                const ed = document.getElementById('fEndDate').value;
                const st = getTimeString('fStart');
                const et = getTimeString('fEnd');

                if (!sd || !ed) {
                    e.preventDefault();
                    alert('Please fill in start and end dates.');
                    return;
                }

                // Safety net: if the visible form title says "New Event" but
                // fEventId is somehow still set to an old id (stale state from
                // a previously-edited event), force it back to 0 right before
                // submit so we never silently overwrite the wrong event.
                const formTitleText = document.getElementById('formTitle').textContent.trim();
                const fEventIdEl = document.getElementById('fEventId');
                if (formTitleText === 'New Event' && fEventIdEl.value !== '0') {
                    fEventIdEl.value = '0';
                }

                document.getElementById('fStart').value = sd + ' ' + st + ':00';
                document.getElementById('fEnd').value = ed + ' ' + et + ':00';
            });

            // ── Auto-advance end 1h after start changes ────────────────────────
            function syncEndFromStart() {
                const sd = document.getElementById('fStartDate').value;
                if (!sd) return;
                document.getElementById('fEndDate').value = sd;
                const sh = parseInt(document.getElementById('fStartH')?.value, 10) || 8;
                const sm = parseInt(document.getElementById('fStartM')?.value, 10) || 0;
                const endH = (sh + 1) % 24;
                document.getElementById('fEndH').value = endH;
                document.getElementById('fEndM').value = String(sm).padStart(2, '0');
                updateAMPM('fEndH', 'fEndAMPM');
            }
            document.getElementById('fStartDate').addEventListener('change', syncEndFromStart);
            document.getElementById('fStartH').addEventListener('change', syncEndFromStart);
            document.getElementById('fStartM').addEventListener('change', syncEndFromStart);

        }); // end DOMContentLoaded

        // ── Open event detail modal ────────────────────────────────────────────────
        function openEventDetail(id) {
            const ev = ALL_EVENTS.find(e => Number(e.id) === id);
            if (!ev) return;
            const color = TYPE_COLORS[ev.event_type] || '#64748b';

            document.getElementById('detailTitle').textContent = ev.title;

            const fmtDT = str => new Date(str.replace(' ', 'T')).toLocaleString('en-NG', {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });

            let body = `
        <div class="detail-badge" style="background:${color}22;color:${color};border:1px solid ${color}55;">
            ${ucFirst(ev.event_type.replace('_',' '))}
            ${ev.scope !== 'personal' ? ' · ' + ev.scope : ''}
        </div>
        <div class="detail-row"><span class="detail-icon">🕐</span>
            <span>${fmtDT(ev.start_datetime)} – ${fmtDT(ev.end_datetime)}</span>
        </div>`;

            if (ev.course_code) body += row('📖', ev.course_code);
            if (ev.location) body += row('📍', ev.location);
            if (ev.description) body += row('📝', ev.description);
            if (Number(ev.is_recurring)) {
                const until = ev.recurrence_end_date ? ' until ' + ev.recurrence_end_date : ' (no end date)';
                body += row('🔁', 'Repeats: ' + ev.recurrence_rule + until);
            }

            document.getElementById('detailBody').innerHTML = body;

            let footer = '';
            if (IS_STAFF || Number(ev.created_by) === USER_ID) {
                footer = `
            <button class="btn secondary" id="detailEditBtn">Edit</button>
            <form method="post" action="calendar_delete.php"
                  onsubmit="return confirm('Delete this event?');" style="display:inline;">
                <input type="hidden" name="event_id" value="${ev.id}">
                <input type="hidden" name="redirect" value="<?php echo 'calendar.php?y=' . $year . '&amp;m=' . $month . ($filterCourseId ? '&amp;course=' . $filterCourseId : ''); ?>">
                <button class="btn danger" type="submit">Delete</button>
            </form>`;
            }
            document.getElementById('detailFooter').innerHTML = footer;

            const editBtn = document.getElementById('detailEditBtn');
            if (editBtn) editBtn.addEventListener('click', () => openEditEvent(id));

            document.getElementById('detailOverlay').hidden = false;
        }

        // ── Open new-event form ────────────────────────────────────────────────────
        function openNewEvent(dateStr) {
            resetForm();
            document.getElementById('formTitle').textContent = 'New Event';
            document.getElementById('fSubmitBtn').textContent = 'Create Event';

            if (dateStr) {
                document.getElementById('fStartDate').value = dateStr;
                document.getElementById('fEndDate').value = dateStr;
            }
            // Set default times (always reset to 08:00/09:00 for new events)
            setTimeInputs('fStart', '08:00');
            setTimeInputs('fEnd', '09:00');

            if (FILTER_COURSE > 0) {
                const scopeEl = document.getElementById('fScope');
                if (scopeEl) {
                    scopeEl.value = 'course';
                    toggleScopeFields('course');
                }
                const fc = document.getElementById('fCourse');
                if (fc) fc.value = FILTER_COURSE;
            }

            document.getElementById('formOverlay').hidden = false;
            document.getElementById('fTitle').focus();
        }

        // ── Open edit form ────────────────────────────────────────────────────────
        function openEditEvent(id) {
            const ev = ALL_EVENTS.find(e => Number(e.id) === id);
            if (!ev) return;
            closeModals();

            document.getElementById('formTitle').textContent = 'Edit Event';
            document.getElementById('fSubmitBtn').textContent = 'Save Changes';
            document.getElementById('fEventId').value = ev.id;
            document.getElementById('fTitle').value = ev.title;
            document.getElementById('fType').value = ev.event_type;
            document.getElementById('fDesc').value = ev.description || '';
            document.getElementById('fLocation').value = ev.location || '';
            // Populate date pickers
            const startParts = ev.start_datetime.slice(0, 16).split(/[T ]/);
            const endParts = ev.end_datetime.slice(0, 16).split(/[T ]/);
            document.getElementById('fStartDate').value = startParts[0];
            document.getElementById('fEndDate').value = endParts[0];
            // Set time inputs to the exact saved time (no rounding)
            setTimeInputs('fStart', startParts[1] || '08:00');
            setTimeInputs('fEnd', endParts[1] || '09:00');
            // Pre-populate hidden fields in case user submits without touching time
            document.getElementById('fStart').value = ev.start_datetime.slice(0, 19).replace('T', ' ');
            document.getElementById('fEnd').value = ev.end_datetime.slice(0, 19).replace('T', ' ');
            document.getElementById('fEmail').checked = Number(ev.notify_email) === 1;
            document.getElementById('fWeb').checked = Number(ev.notify_web) === 1;
            document.getElementById('fRemind').value = ev.remind_minutes_before || 30;

            const scopeEl = document.getElementById('fScope');
            if (scopeEl) {
                scopeEl.value = ev.scope;
                toggleScopeFields(ev.scope);
            }

            const fc = document.getElementById('fCourse');
            if (fc && ev.course_id) fc.value = ev.course_id;

            const recurChk = document.getElementById('fRecurring');
            if (recurChk) {
                recurChk.checked = Number(ev.is_recurring) === 1;
                toggleRecurrence(recurChk.checked);
            }
            const ruleEl = document.getElementById('fRule');
            if (ruleEl && ev.recurrence_rule) ruleEl.value = ev.recurrence_rule;
            const recEndEl = document.getElementById('fRecEnd');
            if (recEndEl && ev.recurrence_end_date) recEndEl.value = ev.recurrence_end_date;

            document.getElementById('formOverlay').hidden = false;
            document.getElementById('fTitle').focus();
        }

        // ── Day-view modal ─────────────────────────────────────────────────────────
        function openDayView(dateStr) {
            const dt = new Date(dateStr + 'T00:00');
            document.getElementById('dayTitle').textContent =
                dt.toLocaleDateString('en-NG', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });

            const dayEvs = ALL_EVENTS.filter(ev => ev.start_datetime.slice(0, 10) === dateStr);
            let html = '';
            dayEvs.forEach(ev => {
                const color = TYPE_COLORS[ev.event_type] || '#64748b';
                const timeStr = t => new Date(t.replace(' ', 'T')).toLocaleTimeString('en-NG', {
                    hour: 'numeric',
                    minute: '2-digit'
                });
                html += `
            <div class="upcoming-item" style="border-left-color:${color};cursor:pointer;"
                 onclick="openEventDetail(${ev.id})">
                <div class="upcoming-date">
                    <span class="udate-day">${dt.getDate()}</span>
                    <span class="udate-mon">${dt.toLocaleString('en-NG',{month:'short'})}</span>
                </div>
                <div class="upcoming-info">
                    <strong>${ev.title}</strong>
                    <div class="muted">${timeStr(ev.start_datetime)} – ${timeStr(ev.end_datetime)}</div>
                </div>
                <span class="badge" style="background:${color}22;color:${color};">${ucFirst(ev.event_type)}</span>
            </div>`;
            });
            document.getElementById('dayBody').innerHTML = html || '<p class="muted" style="padding:12px 0">No events on this day.</p>';
            document.getElementById('dayOverlay').hidden = false;
        }

        // ── Toggle helpers ─────────────────────────────────────────────────────────
        function toggleScopeFields(scope) {
            const wrap = document.getElementById('fCourseWrap');
            if (wrap) wrap.style.display = scope === 'course' ? '' : 'none';
        }

        function toggleRecurrence(show) {
            document.getElementById('recurrenceFields').style.display = show ? '' : 'none';
        }

        function resetForm() {
            document.getElementById('eventForm').reset();
            document.getElementById('fEventId').value = '0';
            document.getElementById('recurrenceFields').style.display = 'none';
            const wrap = document.getElementById('fCourseWrap');
            if (wrap) wrap.style.display = 'none';
            // Reset time inputs to defaults
            setTimeInputs('fStart', '08:00');
            setTimeInputs('fEnd', '09:00');
            document.getElementById('fStart').value = '';
            document.getElementById('fEnd').value = '';
            const sd = document.getElementById('fStartDate');
            const ed = document.getElementById('fEndDate');
            if (sd) sd.value = '';
            if (ed) ed.value = '';
        }

        // ── Utilities ──────────────────────────────────────────────────────────────
        function ucFirst(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }

        function row(icon, text) {
            return `<div class="detail-row"><span class="detail-icon">${icon}</span><span>${text}</span></div>`;
        }
    </script>
</body>

</html>