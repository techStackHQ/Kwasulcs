<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_login();

$user = current_user();

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $emailPref = isset($_POST['pref_email_notifications']) ? 1 : 0;
    $webPref   = isset($_POST['pref_web_notifications']) ? 1 : 0;
    $chatPref  = isset($_POST['pref_chat_notifications']) ? 1 : 0;

    db()->prepare('UPDATE users SET pref_email_notifications = ?, pref_web_notifications = ?, pref_chat_notifications = ? WHERE id = ?')
        ->execute([$emailPref, $webPref, $chatPref, $user['id']]);
    $success = 'Settings saved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_google'])) {
    google_oauth_disconnect((int) $user['id']);
    header('Location: settings.php?google=disconnected');
    exit();
}

// current_user() only selects a fixed column list and caches it, so the
// pref_* and google_* columns are read here directly instead — and re-read
// after a save rather than trusting that cache, so the page reflects the
// update immediately.
ensure_google_oauth_columns();
$prefStmt = db()->prepare('SELECT pref_email_notifications, pref_web_notifications, pref_chat_notifications, email, google_id, google_picture, google_connected_at FROM users WHERE id = ?');
$prefStmt->execute([$user['id']]);
$prefs = $prefStmt->fetch();

$googleFlash  = $_GET['google'] ?? '';
$googleReason = $_GET['reason'] ?? '';
$googleMessages = [
    'connected'      => ['type' => 'success', 'text' => 'Google account connected.'],
    'disconnected'   => ['type' => 'success', 'text' => 'Google account disconnected.'],
    'not_configured' => ['type' => 'error', 'text' => 'Google sign-in is not set up yet — contact an administrator.'],
    'error'          => ['type' => 'error', 'text' => "We couldn't connect your Google account. Please try again." . ($googleReason ? ' (' . $googleReason . ')' : '')],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $pageTitle = 'Settings'; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar">
        <div>
            <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
            <h1><i class="bi bi-gear-fill icon"></i> Settings</h1>
            <p class="muted">Notification preferences.</p>
        </div>
        <div class="topbar-actions">
            <a class="btn glass btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
        </div>
    </header>

    <main class="page">
        <div class="panel">
            <div class="panel-head">
                <h2>Notifications</h2>
            </div>

            <?php if ($success): ?>
                <div class="alert success"><?php echo h($success); ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack">
                <label class="checkbox-label">
                    <input type="checkbox" name="pref_email_notifications" value="1" <?php echo $prefs['pref_email_notifications'] ? 'checked' : ''; ?>>
                    Email reminders for upcoming events
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="pref_web_notifications" value="1" <?php echo $prefs['pref_web_notifications'] ? 'checked' : ''; ?>>
                    Web notifications
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="pref_chat_notifications" value="1" <?php echo $prefs['pref_chat_notifications'] ? 'checked' : ''; ?>>
                    Chat message notifications
                </label>

                <button type="submit" class="btn primary" name="save_settings" value="1" style="margin-top:8px;">Save Settings</button>
            </form>
        </div>

        <?php if (google_oauth_configured()): ?>
            <div class="panel connected-accounts-panel">
                <div class="panel-head">
                    <h2>Connected Accounts</h2>
                </div>

                <?php if ($googleFlash && isset($googleMessages[$googleFlash])): ?>
                    <div class="alert <?php echo h($googleMessages[$googleFlash]['type']); ?>"><?php echo h($googleMessages[$googleFlash]['text']); ?></div>
                <?php endif; ?>

                <?php if (!empty($prefs['google_id'])): ?>
                    <div class="google-connected-row">
                        <?php if (!empty($prefs['google_picture'])): ?>
                            <img src="<?php echo h($prefs['google_picture']); ?>" alt="" class="google-connected-avatar">
                        <?php else: ?>
                            <span class="google-connected-avatar google-connected-avatar--fallback"><i class="bi bi-google"></i></span>
                        <?php endif; ?>
                        <div class="google-connected-info">
                            <strong>Google account connected</strong>
                            <span class="muted"><?php echo h($prefs['email'] ?: 'No email on file'); ?></span>
                            <?php if (!empty($prefs['google_connected_at'])): ?>
                                <span class="muted google-connected-since">Connected <?php echo h(date('M j, Y', strtotime($prefs['google_connected_at']))); ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="post" onsubmit="return confirm('Disconnect your Google account?');">
                            <button type="submit" class="btn secondary" name="disconnect_google" value="1">Disconnect</button>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="muted">Connect your Google account to verify your email address and pull your name and profile photo automatically. This does not change how you sign in — your matric number and password still work as usual.</p>
                    <a class="btn primary" href="google_oauth_start.php"><i class="bi bi-google"></i> Connect Google Account</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</body>

</html>
