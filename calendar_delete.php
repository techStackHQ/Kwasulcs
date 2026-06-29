<?php
require_once __DIR__ . '/config.php';
require_login();

$user    = current_user();
$isStaff = in_array($user['role'], ['admin', 'lecturer'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: calendar.php');
    exit();
}

$eventId     = (int) ($_POST['event_id'] ?? 0);
$rawRedirect = $_POST['redirect'] ?? '';
$redirect    = html_entity_decode($rawRedirect, ENT_QUOTES, 'UTF-8');
$redirect    = urldecode($redirect);
if (!$redirect || !preg_match('#^calendar\.php#', $redirect)) {
    $redirect = 'calendar.php';
}

try {
    if ($eventId <= 0) {
        throw new RuntimeException('Invalid event.');
    }

    $stmt = db()->prepare('SELECT * FROM calendar_events WHERE id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    // Permission: staff can delete course/global events; users can delete their own
    $canDelete = $isStaff || (int) $event['created_by'] === (int) $user['id'];
    if (!$canDelete) {
        throw new RuntimeException('You do not have permission to delete this event.');
    }

    // Cascading delete (notifications etc. handled by FK ON DELETE CASCADE in DB)
    db()->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([$eventId]);

    $_SESSION['cal_flash'] = 'Event deleted.';
} catch (Throwable $e) {
    $_SESSION['cal_error'] = $e->getMessage();
}

header('Location: ' . $redirect);
exit();
