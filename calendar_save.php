<?php
require_once __DIR__ . '/config.php';
require_login();

$user    = current_user();
$role    = $user['role'];
$isStaff = in_array($role, ['admin', 'lecturer'], true);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: calendar.php');
    exit();
}

// Where to redirect when done — decode any HTML entities or URL encoding first
$rawRedirect = $_POST['redirect'] ?? '';
$redirect    = html_entity_decode($rawRedirect, ENT_QUOTES, 'UTF-8');
$redirect    = urldecode($redirect);
// Only allow redirecting back to calendar pages for security
if (!$redirect || !preg_match('#^calendar\.php#', $redirect)) {
    $redirect = 'calendar.php';
}

function flash_and_redirect(string $key, string $msg, string $url): void
{
    $_SESSION[$key] = $msg;
    header('Location: ' . $url);
    exit();
}

try {
    // ── Collect & validate fields ─────────────────────────────────────────────
    $eventId    = (int) ($_POST['event_id'] ?? 0);
    $title      = trim((string) ($_POST['title'] ?? ''));
    $eventType  = (string) ($_POST['event_type'] ?? '');
    $scope      = (string) ($_POST['scope'] ?? 'personal');
    $startDT    = trim((string) ($_POST['start_datetime'] ?? ''));
    $endDT      = trim((string) ($_POST['end_datetime'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $location   = trim((string) ($_POST['location'] ?? ''));
    $courseId   = (int) ($_POST['course_id'] ?? 0);
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $recurrRule  = (string) ($_POST['recurrence_rule'] ?? 'weekly');
    $recurrEnd   = trim((string) ($_POST['recurrence_end_date'] ?? ''));
    $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;
    $notifyWeb   = isset($_POST['notify_web'])   ? 1 : 0;
    $remindMins  = (int) ($_POST['remind_minutes_before'] ?? 30);

    // ── Basic validation ──────────────────────────────────────────────────────
    if ($title === '') {
        throw new RuntimeException('Event title is required.');
    }

    $allowedTypes = ['lecture', 'tutorial', 'exam', 'test', 'personal', 'general'];
    if (!in_array($eventType, $allowedTypes, true)) {
        throw new RuntimeException('Invalid event type.');
    }

    $allowedScopes = ['personal', 'course', 'global'];
    if (!in_array($scope, $allowedScopes, true)) {
        throw new RuntimeException('Invalid scope.');
    }

    // Non-staff can only create personal events
    if (!$isStaff && $scope !== 'personal') {
        $scope = 'personal';
    }

    // Only admin can create global events
    if ($scope === 'global' && $role !== 'admin') {
        throw new RuntimeException('Only admins can create global events.');
    }

    // Course scope requires a course
    if ($scope === 'course') {
        if ($courseId <= 0) {
            throw new RuntimeException('Please select a course for this event.');
        }
        // Verify the user can manage this course
        if (!cal_can_create_course_event($user, $courseId)) {
            throw new RuntimeException('You do not have permission to create events for this course.');
        }
    } else {
        $courseId = null; // ensure NULL in DB for non-course events
    }

    // Validate datetime format
    $startTS = strtotime($startDT);
    $endTS   = strtotime($endDT);
    if (!$startTS || !$endTS) {
        throw new RuntimeException('Invalid start or end date/time.');
    }
    if ($endTS <= $startTS) {
        throw new RuntimeException('End time must be after start time.');
    }

    // Normalise to MySQL DATETIME format
    $startDT = date('Y-m-d H:i:s', $startTS);
    $endDT   = date('Y-m-d H:i:s', $endTS);

    // Recurrence
    $allowedRules = ['daily', 'weekly', 'biweekly', 'monthly'];
    if (!in_array($recurrRule, $allowedRules, true)) {
        $recurrRule = 'weekly';
    }
    $recurrEndSQL = ($isRecurring && $recurrEnd !== '') ? $recurrEnd : null;

    // ── Insert or update ──────────────────────────────────────────────────────
    if ($eventId > 0) {
        // Edit: check ownership / permission
        $chk = db()->prepare('SELECT * FROM calendar_events WHERE id = ? LIMIT 1');
        $chk->execute([$eventId]);
        $existing = $chk->fetch();

        if (!$existing) {
            throw new RuntimeException('Event not found.');
        }

        // Staff can edit any course/global event; users can only edit their own personal
        $canEdit = $isStaff || (int) $existing['created_by'] === (int) $user['id'];
        if (!$canEdit) {
            throw new RuntimeException('You do not have permission to edit this event.');
        }

        db()->prepare('
            UPDATE calendar_events SET
                title = ?, event_type = ?, scope = ?, course_id = ?,
                start_datetime = ?, end_datetime = ?,
                description = ?, location = ?,
                is_recurring = ?, recurrence_rule = ?, recurrence_end_date = ?,
                notify_email = ?, notify_web = ?, remind_minutes_before = ?,
                updated_at = NOW()
            WHERE id = ?
        ')->execute([
            $title,
            $eventType,
            $scope,
            $courseId,
            $startDT,
            $endDT,
            $description,
            $location,
            $isRecurring,
            $isRecurring ? $recurrRule : null,
            $recurrEndSQL,
            $notifyEmail,
            $notifyWeb,
            $remindMins,
            $eventId,
        ]);

        // Reschedule notification
        reschedule_notification($eventId, $startTS, $remindMins, (int) $user['id'], $notifyEmail, $notifyWeb);

        flash_and_redirect('cal_flash', 'Event updated.', $redirect);
    } else {
        // Create
        $stmt = db()->prepare('
            INSERT INTO calendar_events
                (title, event_type, scope, course_id,
                 start_datetime, end_datetime,
                 description, location, created_by,
                 is_recurring, recurrence_rule, recurrence_end_date,
                 notify_email, notify_web, remind_minutes_before)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $title,
            $eventType,
            $scope,
            $courseId,
            $startDT,
            $endDT,
            $description,
            $location,
            $user['id'],
            $isRecurring,
            $isRecurring ? $recurrRule : null,
            $recurrEndSQL,
            $notifyEmail,
            $notifyWeb,
            $remindMins,
        ]);
        $newId = (int) db()->lastInsertId();

        // Schedule web + email notification
        schedule_notification(
            $newId,
            $startTS,
            $remindMins,
            (int) $user['id'],
            $scope,
            $courseId,
            $notifyEmail,
            $notifyWeb
        );

        flash_and_redirect('cal_flash', 'Event created successfully.', $redirect);
    }
} catch (Throwable $e) {
    flash_and_redirect('cal_error', $e->getMessage(), $redirect);
}

// ── Notification helpers ──────────────────────────────────────────────────────

/**
 * Collect audience user IDs for an event scope.
 */
function collect_audience(int $creatorId, string $scope, ?int $courseId): array
{
    if ($scope === 'personal') {
        return [$creatorId];
    }
    if ($scope === 'course' && $courseId) {
        $s = db()->prepare('SELECT student_id FROM enrollments WHERE course_id = ?');
        $s->execute([$courseId]);
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        $l = db()->prepare('SELECT lecturer_id FROM courses WHERE id = ?');
        $l->execute([$courseId]);
        $lid = (int) $l->fetchColumn();
        if ($lid) $ids[] = $lid;
        $ids[] = $creatorId;
        return array_unique($ids);
    }
    if ($scope === 'global') {
        $rows = db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }
    return [$creatorId];
}

/**
 * Generate future occurrence timestamps for an event.
 *
 * For non-recurring events returns just the original start.
 * For recurring events, jumps to near-now and walks forward,
 * returning up to 104 occurrences (~2 yrs of weekly).
 * Only returns timestamps in the future (past ones can't be reminded).
 */
function get_future_occurrence_timestamps(array $ev): array
{
    $origStart = strtotime($ev['start_datetime']);
    $nowTS     = time();
    $recurrEnd = $ev['recurrence_end_date']
        ? strtotime($ev['recurrence_end_date'] . ' 23:59:59')
        : ($nowTS + (365 * 86400 * 2)); // default: 2 years ahead

    if (!$ev['is_recurring'] || !$ev['recurrence_rule']) {
        return [$origStart]; // single event
    }

    // ── Jump cursor close to now to avoid iterating from months ago ───────────
    if ($ev['recurrence_rule'] === 'monthly') {
        $origDay = (int) date('j', $origStart);
        $origH   = (int) date('H', $origStart);
        $origI   = (int) date('i', $origStart);
        $nowY    = (int) date('Y', $nowTS);
        $nowM    = (int) date('n', $nowTS);
        $origY   = (int) date('Y', $origStart);
        $origM   = (int) date('n', $origStart);
        $months  = max(0, ($nowY - $origY) * 12 + ($nowM - $origM) - 1);
        $sY      = $origY + intdiv($origM - 1 + $months, 12);
        $sM      = (($origM - 1 + $months) % 12) + 1;
        $cursor  = mktime($origH, $origI, 0, $sM, $origDay, $sY);
    } else {
        $stepSecs = match ($ev['recurrence_rule']) {
            'daily'    => 86400,
            'weekly'   => 604800,
            'biweekly' => 1209600,
            default    => 604800,
        };
        if ($origStart >= $nowTS) {
            $cursor = $origStart;
        } else {
            $steps  = (int) floor(($nowTS - $origStart) / $stepSecs);
            $cursor = $origStart + max(0, $steps - 1) * $stepSecs;
        }
    }

    // ── Walk forward collecting future occurrences ────────────────────────────
    $results = [];
    $safety  = 0;
    while ($cursor <= $recurrEnd && $safety++ < 104) {
        if ($cursor >= $origStart) {
            $results[] = $cursor;
        }
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
    return $results;
}

/**
 * Schedule calendar_notifications rows for an event.
 *
 * TIME TRACKING FIX:
 * Previously only one notification row was created (for the single start time).
 * Now for recurring events we create a row for EVERY future occurrence.
 * The cron job reads these rows WHERE scheduled_at <= NOW() — so the moment
 * the server clock passes the reminder time, the notification fires automatically.
 *
 * This is how the system tracks time: it pre-schedules all future reminders
 * in the DB and the cron just checks if any are due.
 */
function schedule_notification(
    int $eventId,
    int $startTS,
    int $remindMins,
    int $creatorId,
    string $scope,
    ?int $courseId,
    int $email,
    int $web
): void {
    // Re-fetch event to get recurrence data
    $evRow = db()->prepare('SELECT * FROM calendar_events WHERE id = ?');
    $evRow->execute([$eventId]);
    $ev = $evRow->fetch();
    if (!$ev) return;

    $userIds     = collect_audience($creatorId, $scope, $courseId);
    $occurrences = get_future_occurrence_timestamps($ev);

    $ins = db()->prepare('
        INSERT IGNORE INTO calendar_notifications
            (event_id, user_id, scheduled_at, sent_email, sent_web)
        VALUES (?, ?, ?, 0, 0)
    ');

    foreach ($occurrences as $occTS) {
        $fireAt = date('Y-m-d H:i:s', $occTS - ($remindMins * 60));
        // Skip only if the fire time is more than 60s in the past — a small
        // grace window so a reminder calculated to fire "now" (e.g. a test
        // event set only 1-2 minutes out) isn't dropped by request timing.
        if (strtotime($fireAt) < time() - 60) continue;
        foreach ($userIds as $uid) {
            $ins->execute([$eventId, $uid, $fireAt]);
        }
    }
}

function reschedule_notification(
    int $eventId,
    int $startTS,
    int $remindMins,
    int $creatorId,
    int $email,
    int $web
): void {
    // Delete all pending (unsent) notifications for this event
    db()->prepare('DELETE FROM calendar_notifications WHERE event_id = ? AND sent_email = 0 AND sent_web = 0')
        ->execute([$eventId]);

    // Fetch event and rebuild
    $evRow = db()->prepare('SELECT * FROM calendar_events WHERE id = ?');
    $evRow->execute([$eventId]);
    $ev = $evRow->fetch();
    if (!$ev) return;

    schedule_notification(
        $eventId,
        $startTS,
        $remindMins,
        (int) $ev['created_by'],
        $ev['scope'],
        $ev['course_id'] ? (int) $ev['course_id'] : null,
        $email,
        $web
    );
}
