<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matric = trim((string)($_POST['matric_no'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($matric === '' || $password === '') {
        $error = 'Enter your matric number and password.';
    } else {
        $stmt = db()->prepare('SELECT id, full_name, matric_no, password, role FROM users WHERE matric_no = ? LIMIT 1');
        $stmt->execute([$matric]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            header('Location: dashboard.php');
            exit();
        }

        $error = 'Invalid matric number or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KWASU LCS - Login</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/theme.js" defer></script>
</head>

<body class="auth-body">
    <div style="position:fixed;top:16px;right:24px;z-index:100;">
        <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
    </div>
    <div class="auth-shell">
        <div class="auth-panel auth-panel-left">
            <div class="brand-badge">KWASU LCS</div>
            <h1>Lecture-focused Academic Resource Management</h1>
            <p>Course registration, weekly lecture content, YouTube lecture embeds, protected documents, tutorials, and exam updates.</p>
        </div>

        <div class="auth-panel auth-panel-right">
            <h2>Login</h2>
            <p class="muted">Use your matric number and password.</p>

            <?php if ($error): ?>
                <div class="alert error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <label for="matric_no">Matric Number</label>
                <input type="text" name="matric_no" id="matric_no" required placeholder="23D/7CS/496">

                <label for="password">Password</label>
                <input type="password" name="password" id="password" required placeholder="••••••••">

                <button type="submit" class="btn primary">Login</button>
            </form>

            <p class="helper">
                Accounts are pre-created by the administrator.
            </p>
        </div>
    </div>
</body>

</html>