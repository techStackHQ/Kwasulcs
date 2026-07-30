<?php

/**
 * KWASU LCS — Notification cron job
 *
 * ON A REAL SERVER — add to crontab (runs every 5 min automatically):
 *   crontab -e
 *   *\/5 * * * * php /var/www/html/LMS-portal/notify_cron.php >> /tmp/lcs_cron.log 2>&1
 *
 * ON XAMPP (local) — trigger it manually by visiting:
 *   http://localhost/LMS-portal/notify_cron.php?run=1
 *
 * The ?run=1 parameter is required from the browser to prevent accidental runs.
 * From CLI it always runs: php notify_cron.php
 */

define('RUNNING_CRON', true);
require_once __DIR__ . '/config.php';

$isCLI = PHP_SAPI === 'cli';

// Browser must pass ?run=1 as a safety gate
if (!$isCLI && ($_GET['run'] ?? '') !== '1') {
    // Show a friendly trigger page instead
    require_login();
    $user = current_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Run Notifications — KWASU LCS</title>
        <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
        <link rel="stylesheet" href="assets/style.css">
        <script src="assets/theme.js" defer></script>
    </head>

    <body class="app-body">
        <header class="topbar">
            <div>
                <div class="eyebrow">KWASU LCS</div>
                <h1>🔔 Notification Cron</h1>
            </div>
            <div class="topbar-actions">
                <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
                <a class="btn secondary btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
            </div>
        </header>
        <main class="page">
            <div class="panel" style="padding:32px;max-width:560px;">
                <h2>Manual Notification Trigger</h2>
                <p class="muted">On XAMPP, cron doesn't run automatically. Click below to fire all pending notifications now.</p>
                <p class="muted">On a real server, set up a cron job instead:<br>
                    <code style="background:#f1f5f9;padding:4px 8px;border-radius:6px;font-size:13px;">
                        */5 * * * * php <?php echo __FILE__; ?>
                    </code>
                </p>
                <a class="btn primary" href="notify_cron.php?run=1" style="margin-top:16px;">
                    ▶ Run Notifications Now
                </a>
            </div>
        </main>
    </body>

    </html>
<?php
    exit();
}

// ── Output helpers ────────────────────────────────────────────────────────────
$lines = [];

function out(string $msg): void
{
    global $lines, $isCLI;
    $lines[] = $msg;
    if ($isCLI) echo $msg . "\n";
}

function render_output(array $lines, bool $isCLI): void
{
    if ($isCLI) {
        echo implode("\n", $lines) . "\n";
        return;
    }
    require_login();
    $output = htmlspecialchars(implode("\n", $lines));
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\">
          <title>Cron Result — KWASU LCS</title>
           <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();<\/script>
           <link rel=\"stylesheet\" href=\"assets/style.css\">
           <script src=\"assets/theme.js\" defer><\/script></head>
          <body class=\"app-body\">
          <header class=\"topbar\">
            <div><div class=\"eyebrow\">KWASU LCS</div><h1>🔔 Notification Run</h1></div>
            <div class=\"topbar-actions\">
              <button class=\"theme-btn\" onclick=\"toggleTheme()\" title=\"Dark mode\">🌙</button>
              <a class=\"btn secondary\" href=\"notifications.php\">View Notifications</a>
              <a class=\"btn secondary btn-go-dashboard\" href=\"dashboard.php\"><i class=\"bi bi-grid-1x2-fill\"></i> Go to Dashboard</a>
            </div>
          </header>
          <main class=\"page\">
            <div class=\"panel\" style=\"padding:24px;\">
              <h2>Result</h2>
              <pre style=\"background:#0f172a;color:#e2e8f0;padding:16px;border-radius:12px;
                           font-size:13px;line-height:1.6;overflow-x:auto;\">{$output}</pre>
              <div style=\"margin-top:16px;display:flex;gap:10px;\">
                <a class=\"btn primary\" href=\"notify_cron.php?run=1\">Run Again</a>
                <a class=\"btn secondary\" href=\"notifications.php\">View Notifications</a>
                <a class=\"btn secondary\" href=\"calendar.php\">Calendar</a>
              </div>
            </div>
          </main></body></html>";
}

$now = date('Y-m-d H:i:s');
out("[" . $now . "] Checking notifications…");

// ── Fetch due notifications ───────────────────────────────────────────────────
try {
    $stmt = db()->prepare("
        SELECT
            cn.id          AS notif_id,
            cn.event_id,
            cn.user_id,
            cn.sent_email,
            cn.sent_web,
            ce.title       AS event_title,
            ce.event_type,
            ce.start_datetime,
            ce.end_datetime,
            ce.location,
            ce.description,
            ce.notify_email AS ev_notify_email,
            ce.notify_web   AS ev_notify_web,
            u.email         AS user_email,
            u.full_name     AS user_name,
            u.pref_email_notifications,
            u.pref_web_notifications
        FROM calendar_notifications cn
        JOIN calendar_events ce ON ce.id = cn.event_id
        JOIN users u            ON u.id  = cn.user_id
        WHERE cn.scheduled_at <= ?
          AND (cn.sent_email = 0 OR cn.sent_web = 0)
        ORDER BY cn.scheduled_at ASC
        LIMIT 200
    ");
    $stmt->execute([$now]);
    $due = $stmt->fetchAll();
} catch (\Throwable $e) {
    out("DB ERROR: " . $e->getMessage());
    render_output($lines, $isCLI);
    exit();
}

out("Found " . count($due) . " pending notification(s).");

$emailsSent = 0;
$webCreated = 0;
$errors     = 0;

foreach ($due as $row) {
    $startFmt = date('D, d M Y \a\t g:i A', strtotime($row['start_datetime']));

    // ── Web notification ──────────────────────────────────────────────────────
    if (!$row['sent_web'] && $row['ev_notify_web'] && $row['pref_web_notifications']) {
        $msg = "Reminder: \"{$row['event_title']}\" starts {$startFmt}";
        if ($row['location']) $msg .= " @ {$row['location']}";

        try {
            // Atomic claim before acting — re-checks the database right now
            // instead of trusting $row['sent_web'], which was read by the
            // SELECT at the top of the script and could be stale if
            // dashboard.php's auto-fire or notify_poll.php already claimed
            // and processed this same row in the meantime.
            $claimWeb = db()->prepare('UPDATE calendar_notifications SET sent_web = 1 WHERE id = ? AND sent_web = 0');
            $claimWeb->execute([$row['notif_id']]);

            if ($claimWeb->rowCount() === 0) {
                out("  [WEB SKIP] notif #{$row['notif_id']} already created by another process — skipping duplicate.");
            } else {
                db()->prepare('
                    INSERT IGNORE INTO web_notifications (user_id, event_id, message)
                    VALUES (?, ?, ?)
                ')->execute([$row['user_id'], $row['event_id'], $msg]);

                $webCreated++;
                out("  [WEB] Created for user #{$row['user_id']}: {$row['event_title']}");
            }
        } catch (\Throwable $e) {
            out("  [WEB ERROR] #{$row['notif_id']}: " . $e->getMessage());
            $errors++;
        }
    }

    // ── Device push notification ─────────────────────────────────────────────
    try {
        $subStmt = db()->prepare('SELECT endpoint FROM push_subscriptions WHERE user_id = ? LIMIT 1');
        $subStmt->execute([$row['user_id']]);
        $pushSub = $subStmt->fetch();
        if ($pushSub && $pushSub['endpoint']) {
            $payload = json_encode([
                'title' => 'KWASU LCS Reminder',
                'body'  => '"' . $row['event_title'] . '" starts ' . date('D d M \\at g:i A', strtotime($row['start_datetime'])),
                'url'   => '/LMS-portal/notifications.php',
                'tag'   => 'lcs-event-' . $row['event_id'],
            ]);
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nTTL: 86400\r\n",
                'content' => $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => false]]);
            @file_get_contents($pushSub['endpoint'], false, $ctx);
            out("  [PUSH] Sent to user #{$row['user_id']}");
        }
    } catch (\Throwable $e) {
        out("  [PUSH SKIP] " . $e->getMessage());
    }

    // ── Email notification ────────────────────────────────────────────────────
    if (!$row['sent_email'] && $row['ev_notify_email'] && $row['user_email'] && $row['pref_email_notifications']) {
        $subject = "[KWASU LCS] Reminder: {$row['event_title']}";

        $typeIcon = match ($row['event_type']) {
            'exam'     => '📋',
            'test'     => '✏️',
            'lecture'  => '🎓',
            'tutorial' => '📚',
            default    => '📅',
        };

        $html = "
        <div style='font-family:Inter,Segoe UI,sans-serif;max-width:560px;margin:0 auto;'>
            <div style='background:linear-gradient(135deg,#07a701,#c08810);padding:24px 28px;border-radius:16px 16px 0 0;'>
                <h1 style='color:#fff;margin:0;font-size:20px;'>$typeIcon Event Reminder</h1>
                <p style='color:rgba(255,255,255,.85);margin:4px 0 0;font-size:13px;'>KWASU Lecture Capture System</p>
            </div>
            <div style='background:#fff;padding:24px 28px;border-radius:0 0 16px 16px;border:1px solid #e2e8f0;border-top:0;'>
                <p style='margin:0 0 4px;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700;'>
                    " . strtoupper(str_replace('_', ' ', $row['event_type'])) . "
                </p>
                <h2 style='margin:0 0 20px;font-size:22px;color:#0f172a;'>" . htmlspecialchars($row['event_title']) . "</h2>
                <table style='width:100%;border-collapse:collapse;font-size:14px;color:#0f172a;'>
                    <tr>
                        <td style='padding:8px 0;width:80px;color:#64748b;vertical-align:top;'>🕐 When</td>
                        <td style='padding:8px 0;font-weight:600;'>$startFmt</td>
                    </tr>";

        if ($row['location']) {
            $html .= "<tr>
                        <td style='padding:8px 0;color:#64748b;'>📍 Where</td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($row['location']) . "</td>
                      </tr>";
        }
        if ($row['description']) {
            $html .= "<tr>
                        <td style='padding:8px 0;color:#64748b;vertical-align:top;'>📝 Note</td>
                        <td style='padding:8px 0;'>" . htmlspecialchars($row['description']) . "</td>
                      </tr>";
        }

        $html .= "</table>
                <div style='margin-top:20px;padding:14px;background:#f0fdf4;border-radius:10px;border-left:4px solid #07a701;'>
                    <p style='margin:0;font-size:13px;color:#166534;'>
                        This is an automated reminder from KWASU LCS.
                    </p>
                </div>
            </div>
        </div>";

        try {
            // Atomic claim before sending — same fix as the web block above.
            // Re-checks the database right now instead of trusting
            // $row['sent_email'], which was read at the top of the script
            // and could be stale by the time we reach this point.
            $claim = db()->prepare('UPDATE calendar_notifications SET sent_email = 1 WHERE id = ? AND sent_email = 0');
            $claim->execute([$row['notif_id']]);

            if ($claim->rowCount() === 0) {
                out("  [EMAIL SKIP] notif #{$row['notif_id']} already sent by another process — skipping duplicate.");
            } else {
                $sent = send_mail($row['user_email'], $row['user_name'], $subject, $html);
                if ($sent) {
                    $emailsSent++;
                    out("  [EMAIL ✅] Sent to {$row['user_email']} — {$row['event_title']}");
                } else {
                    // Sending genuinely failed — release the claim so a future
                    // run can retry instead of leaving it wrongly marked sent.
                    db()->prepare('UPDATE calendar_notifications SET sent_email = 0 WHERE id = ?')
                        ->execute([$row['notif_id']]);
                    out("  [EMAIL ⚠️] send_mail() returned false for notif #{$row['notif_id']} (check PHPMailer config)");
                }
            }
        } catch (\Throwable $e) {
            out("  [EMAIL ❌] #{$row['notif_id']}: " . $e->getMessage());
            $errors++;
        }
    }
}

$summary = "Done. Emails sent: {$emailsSent} | Web notifications: {$webCreated}" . ($errors ? " | Errors: {$errors}" : "");
out($summary);

// ── Browser output ────────────────────────────────────────────────────────────
if (!$isCLI) {
    require_login();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Cron Result — KWASU LCS</title>
        <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
        <link rel="stylesheet" href="assets/style.css">
        <script src="assets/theme.js" defer></script>
    </head>

    <body class="app-body">
        <header class="topbar">
            <div>
                <div class="eyebrow">KWASU LCS</div>
                <h1>🔔 Notification Run</h1>
            </div>
            <div class="topbar-actions">
                <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
                <a class="btn secondary" href="notifications.php">View Notifications</a>
                <a class="btn secondary btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
            </div>
        </header>
        <main class="page">
            <div class="panel" style="padding:24px;">
                <h2>Result</h2>
                <pre style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:12px;font-size:13px;line-height:1.6;overflow-x:auto;"><?php
                                                                                                                                                echo htmlspecialchars(implode("\n", $lines));
                                                                                                                                                ?></pre>
                <div style="margin-top:16px;display:flex;gap:10px;">
                    <a class="btn primary" href="notify_cron.php?run=1">Run Again</a>
                    <a class="btn secondary" href="notifications.php">View Notifications</a>
                    <a class="btn secondary" href="calendar.php">Calendar</a>
                </div>
            </div>
        </main>
    </body>

    </html>
<?php
}
