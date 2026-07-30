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
            // "Remember this device" — PHP's session cookie has no expiry by
            // default (dies when the browser closes); re-issuing it with a
            // 30-day lifetime is what actually keeps the session alive
            // across visits, rather than just being a decorative checkbox.
            if (!empty($_POST['remember'])) {
                setcookie(session_name(), session_id(), time() + 30 * 24 * 60 * 60, '/');
            }
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
    <?php $pageTitle = 'Login'; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="auth-body">
    <div class="auth-theme-toggle">
        <button class="theme-toggle" role="switch" onclick="toggleTheme()" title="Dark mode"></button>
    </div>
    <div class="auth-shell">
        <div class="auth-shell-top">
            <div class="auth-panel auth-panel-left">
                <div class="brand-badge"><?php echo brand_logo(); ?> KWASU LCS</div>
                <h1>Lecture-focused Academic Resource Management</h1>
                <p>A centralized platform for lecture materials, course resources, tutorials, assessments, and academic collaboration.</p>

                <ul class="auth-feature-list">
                    <li><span class="auth-feature-icon"><i class="bi bi-shield-check"></i></span> Secure Login</li>
                    <li><span class="auth-feature-icon"><i class="bi bi-journal-bookmark-fill"></i></span> Course Resources</li>
                    <li><span class="auth-feature-icon"><i class="bi bi-stars"></i></span> AI Study Assistant</li>
                    <li><span class="auth-feature-icon"><i class="bi bi-calendar3"></i></span> Academic Calendar</li>
                </ul>
            </div>

            <div class="auth-panel auth-panel-right">
                <h2>Welcome Back</h2>
                <p class="muted">Sign in to continue to your learning portal.</p>

                <?php if ($error): ?>
                    <div class="alert error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" class="auth-form auth-form--login">
                    <label for="matric_no">Matric Number</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="bi bi-person-fill"></i></span>
                        <input type="text" name="matric_no" id="matric_no" required placeholder="Enter your matric number">
                    </div>

                    <label for="password">Password</label>
                    <div class="auth-input-wrap password-field">
                        <span class="auth-input-icon"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="password" id="password" required placeholder="Enter your password">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility()" aria-label="Show password"><i class="bi bi-eye-fill"></i></button>
                    </div>

                    <div class="auth-form-row">
                        <label class="checkbox-label">
                            <input type="checkbox" name="remember">
                            Remember this device
                        </label>
                        <a class="auth-forgot-link" href="forgot_password.php">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn primary auth-submit-btn">
                        <i class="bi bi-lock-fill"></i> Sign In <i class="bi bi-arrow-right"></i>
                    </button>
                </form>

                <div class="auth-divider"><span><i class="bi bi-shield-lock-fill"></i></span></div>
                <p class="auth-protected-note">Protected by <strong>KWASU LCS</strong> Authentication</p>
            </div>
        </div>
        <div class="auth-shell-footer">
            <span>© 2026 KWASU LCS</span>
            <span>Version 1.0</span>
        </div>
    </div>
    <script>
        function togglePasswordVisibility() {
            var input = document.getElementById('password');
            var btn = document.querySelector('.password-toggle');
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.innerHTML = isHidden ? '<i class="bi bi-eye-slash-fill"></i>' : '<i class="bi bi-eye-fill"></i>';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }
    </script>
</body>

</html>