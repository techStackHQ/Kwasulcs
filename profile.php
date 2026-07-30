<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $success = 'Password updated successfully.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $pageTitle = 'Profile'; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar">
        <div>
            <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
            <h1><i class="bi bi-person-circle icon"></i> Profile</h1>
            <p class="muted">Manage your account.</p>
        </div>
        <div class="topbar-actions">
            <a class="btn glass btn-go-dashboard" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
        </div>
    </header>

    <main class="page">
        <div class="stack">
            <div class="panel profile-card">
                <div class="profile-avatar"><?php echo avatar_inner_html($user['full_name'], $user['google_picture'] ?? null); ?></div>
                <h2 style="margin-top:14px;"><?php echo h($user['full_name']); ?></h2>
                <p class="muted"><?php echo h(ucfirst($user['role'])); ?></p>

                <div class="profile-info">
                    <div class="profile-info-row">
                        <span class="muted">Matric Number</span>
                        <strong><?php echo h($user['matric_no']); ?></strong>
                    </div>
                    <div class="profile-info-row">
                        <span class="muted">Email</span>
                        <strong><?php echo h($user['email'] ?: '—'); ?></strong>
                    </div>
                </div>

                <?php if (empty($user['google_picture'])): ?>
                    <p class="helper" style="margin-top:18px;">
                        <a href="settings.php">Connect a Google account</a> in Settings to add a profile photo.
                    </p>
                <?php endif; ?>

                <a class="btn danger" href="logout.php" style="margin-top:22px;width:100%;">Logout</a>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2>Change Password</h2>
                </div>

                <?php if ($error): ?>
                    <div class="alert error"><?php echo h($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <form method="post" class="form-stack">
                    <label for="current_password">Current Password</label>
                    <div class="password-field">
                        <input type="password" name="current_password" id="current_password" required>
                        <button type="button" class="password-toggle" onclick="togglePw('current_password', this)"><i class="bi bi-eye-fill"></i></button>
                    </div>

                    <label for="new_password">New Password</label>
                    <div class="password-field">
                        <input type="password" name="new_password" id="new_password" required minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePw('new_password', this)"><i class="bi bi-eye-fill"></i></button>
                    </div>

                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-field">
                        <input type="password" name="confirm_password" id="confirm_password" required minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePw('confirm_password', this)"><i class="bi bi-eye-fill"></i></button>
                    </div>

                    <button type="submit" class="btn primary" name="change_password" value="1">Update Password</button>
                </form>
            </div>
        </div>
    </main>

    <script>
        function togglePw(id, btn) {
            var input = document.getElementById(id);
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.innerHTML = isHidden ? '<i class="bi bi-eye-slash-fill"></i>' : '<i class="bi bi-eye-fill"></i>';
        }
    </script>
</body>

</html>
