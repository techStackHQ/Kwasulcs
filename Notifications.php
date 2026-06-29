<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();

// Handle mark-single-as-read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $nid = (int)$_POST['notif_id'];
    db()->prepare('UPDATE web_notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
        ->execute([$nid, $user['id']]);
    header('Location: notifications.php');
    exit();
}

// Handle mark-all-as-read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    db()->prepare('UPDATE web_notifications SET is_read = 1 WHERE user_id = ?')
        ->execute([$user['id']]);
    header('Location: notifications.php');
    exit();
}

// Load all notifications
$stmt = db()->prepare('
    SELECT wn.*, ce.title AS event_title, ce.start_datetime, ce.end_datetime,
           ce.event_type, ce.location, ce.id AS cal_event_id
    FROM web_notifications wn
    LEFT JOIN calendar_events ce ON ce.id = wn.event_id
    WHERE wn.user_id = ?
    ORDER BY wn.is_read ASC, wn.created_at DESC
    LIMIT 100
');
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

$unread = array_filter($notifications, fn($n) => !$n['is_read']);
$read   = array_filter($notifications, fn($n) =>  $n['is_read']);

$typeColors = [
    'lecture'  => '#2563eb',
    'tutorial' => '#7c3aed',
    'exam'     => '#dc2626',
    'test'     => '#ea580c',
    'personal' => '#059669',
    'general'  => '#0891b2',
];

function time_ago(string $ts): string
{
    $diff = time() - strtotime($ts);
    if ($diff < 60)        return 'Just now';
    if ($diff < 3600)      return (int)($diff / 60) . ' min ago';
    if ($diff < 86400)     return (int)($diff / 3600) . ' hr ago';
    if ($diff < 86400 * 7) return (int)($diff / 86400) . ' day(s) ago';
    return date('d M Y', strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — KWASU LCS</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/calendar.css">
    <script src="assets/theme.js" defer></script>
</head>

<body class="app-body">

    <header class="topbar">
        <div>
            <div class="eyebrow">KWASU LCS</div>
            <h1>🔔 Notifications</h1>
            <p class="muted">
                <?php echo count($unread); ?> unread
                · <?php echo count($notifications); ?> total
            </p>
        </div>
        <div class="topbar-actions">
            <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
            <?php if ($unread): ?>
                <form method="post" style="display:inline;">
                    <button class="btn secondary" name="mark_all_read" value="1">
                        ✓ Mark all read
                    </button>
                </form>
            <?php endif; ?>
            <a class="btn secondary" href="calendar.php">📅 Calendar</a>
            <a class="btn secondary" href="dashboard.php">Dashboard</a>
            <a class="btn danger" href="logout.php">Logout</a>
        </div>
    </header>

    <main class="page">

        <?php if (!$notifications): ?>
            <div class="panel" style="padding:40px;text-align:center;">
                <div style="font-size:48px;margin-bottom:12px;">🔕</div>
                <h2 style="color:#64748b;font-weight:600;">No notifications yet</h2>
                <p class="muted">When calendar event reminders fire, they'll appear here.</p>
                <a class="btn primary" href="calendar.php" style="margin-top:12px;display:inline-flex;">
                    📅 Go to Calendar
                </a>
            </div>
        <?php endif; ?>

        <!-- ── Unread ─────────────────────────────────────────────────────────────── -->
        <?php if ($unread): ?>
            <div class="panel notif-panel">
                <h2 class="notif-section-head">
                    <span class="notif-badge" style="background:#dc2626;"><?php echo count($unread); ?></span>
                    Unread
                </h2>
                <?php foreach ($unread as $n):
                    $color = $typeColors[$n['event_type'] ?? 'general'] ?? '#64748b';
                ?>
                    <div class="notif-row notif-row--unread" style="border-left-color:<?php echo $color; ?>;">
                        <div class="notif-icon" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>;">
                            <?php echo match ($n['event_type'] ?? '') {
                                'exam'     => '📋',
                                'test'     => '✏️',
                                'lecture'  => '🎓',
                                'tutorial' => '📚',
                                'personal' => '👤',
                                default    => '📅',
                            }; ?>
                        </div>
                        <div class="notif-content">
                            <div class="notif-message"><?php echo h($n['message']); ?></div>
                            <?php if ($n['event_title'] && $n['start_datetime']): ?>
                                <div class="notif-meta">
                                    <strong><?php echo h($n['event_title']); ?></strong>
                                    · <?php echo date('D d M, g:i A', strtotime($n['start_datetime'])); ?>
                                    <?php if ($n['location']): ?>
                                        · 📍 <?php echo h($n['location']); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="notif-time"><?php echo time_ago($n['created_at']); ?></div>
                        </div>
                        <div class="notif-actions">
                            <?php if ($n['event_type']): ?>
                                <span class="badge ev-badge"
                                    style="background:<?php echo $color; ?>22;color:<?php echo $color; ?>;border:1px solid <?php echo $color; ?>55;">
                                    <?php echo ucfirst($n['event_type']); ?>
                                </span>
                            <?php endif; ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="notif_id" value="<?php echo (int)$n['id']; ?>">
                                <button class="btn tiny secondary" name="mark_read" value="1">
                                    Mark read
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ── Read / older ──────────────────────────────────────────────────────── -->
        <?php if ($read): ?>
            <div class="panel notif-panel" style="margin-top:16px;">
                <h2 class="notif-section-head" style="color:#94a3b8;">Earlier</h2>
                <?php foreach ($read as $n):
                    $color = $typeColors[$n['event_type'] ?? 'general'] ?? '#64748b';
                ?>
                    <div class="notif-row" style="border-left-color:<?php echo $color; ?>55;opacity:.75;">
                        <div class="notif-icon" style="background:#f1f5f9;color:#94a3b8;">
                            🔕
                        </div>
                        <div class="notif-content">
                            <div class="notif-message" style="color:#64748b;"><?php echo h($n['message']); ?></div>
                            <?php if ($n['event_title'] && $n['start_datetime']): ?>
                                <div class="notif-meta">
                                    <?php echo h($n['event_title']); ?>
                                    · <?php echo date('D d M, g:i A', strtotime($n['start_datetime'])); ?>
                                </div>
                            <?php endif; ?>
                            <div class="notif-time"><?php echo time_ago($n['created_at']); ?></div>
                        </div>
                        <?php if ($n['event_type']): ?>
                            <span class="badge ev-badge"
                                style="background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;">
                                <?php echo ucfirst($n['event_type']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
</body>

</html>