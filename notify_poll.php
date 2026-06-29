<?php

/**
 * notify_poll.php
 *
 * A tiny, silent endpoint called every 60s by dashboard.php's background
 * poll (see the <script> at the bottom of dashboard.php). Runs the exact
 * same atomic-claim auto-fire logic as calendar.php/dashboard.php, so
 * reminders fire close to their scheduled time even if the user just
 * leaves a tab open and idle, instead of requiring a manual page refresh.
 *
 * Returns a tiny JSON status — not meant to be visited directly, though
 * it's safe to do so (requires login, does nothing destructive).
 */
require_once __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json');

$fired = 0;

try {
    $dueChk = db()->prepare("SELECT COUNT(*) FROM calendar_notifications WHERE scheduled_at <= NOW() AND (sent_web=0 OR sent_email=0)");
    $dueChk->execute();

    if ((int)$dueChk->fetchColumn() > 0) {
        $cronNow = date('Y-m-d H:i:s');
        $cronStmt = db()->prepare("
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
        $cronStmt->execute([$cronNow]);

        foreach ($cronStmt->fetchAll() as $cr) {
            $fmt = date('D, d M Y \at g:i A', strtotime($cr['start_datetime']));

            // Web notification — claim first, then act (prevents duplicates
            // if this poll overlaps with a page-load auto-fire happening at
            // the same moment in another tab).
            if (!$cr['sent_web'] && $cr['ev_notify_web']) {
                $claimWeb = db()->prepare('UPDATE calendar_notifications SET sent_web=1 WHERE id=? AND sent_web=0');
                $claimWeb->execute([$cr['notif_id']]);
                if ($claimWeb->rowCount() > 0) {
                    $msg = "Reminder: \"{$cr['event_title']}\" starts {$fmt}" . ($cr['location'] ? " @ {$cr['location']}" : "");
                    db()->prepare('INSERT IGNORE INTO web_notifications (user_id, event_id, message) VALUES (?,?,?)')
                        ->execute([$cr['user_id'], $cr['event_id'], $msg]);
                    $fired++;
                }
            }

            // Email notification — same atomic claim pattern.
            if (!$cr['sent_email'] && $cr['ev_notify_email'] && $cr['user_email']) {
                $claimEmail = db()->prepare('UPDATE calendar_notifications SET sent_email=1 WHERE id=? AND sent_email=0');
                $claimEmail->execute([$cr['notif_id']]);
                if ($claimEmail->rowCount() > 0) {
                    $html = "<div style='font-family:sans-serif;'><h2>📅 {$cr['event_title']}</h2><p><strong>When:</strong> {$fmt}</p>" .
                        ($cr['location'] ? "<p><strong>Where:</strong> {$cr['location']}</p>" : "") . "</div>";
                    $sendOk = send_mail($cr['user_email'], $cr['user_name'], "[KWASU LCS] Reminder: {$cr['event_title']}", $html);
                    if ($sendOk) {
                        $fired++;
                    } else {
                        // Release the claim so the next poll retries it.
                        db()->prepare('UPDATE calendar_notifications SET sent_email=0 WHERE id=?')->execute([$cr['notif_id']]);
                    }
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'fired' => $fired]);
} catch (\Throwable $e) {
    http_response_code(200); // don't surface errors to the polling fetch
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
