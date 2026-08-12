<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit();
}

// ── Branded per-department entry point (Task 14) ─────────────────────────────
// ?dept=<slug> is COSMETIC ONLY — it picks which department's name/color/logo
// this pre-login page displays, nothing more. It is NEVER read anywhere in
// the login handler below, and never touches $_SESSION. Which department a
// logged-in user actually belongs to comes exclusively from that user's own
// department_id column (see current_user() in config.php) — a Mass Comm
// student who lands here via a CS-branded link, an old bookmark, or a typo
// still gets their own correct Mass Comm dashboard after logging in, because
// the auth path below doesn't know or care which branded page they arrived
// through. Trusting the URL for anything beyond the paint job here would be
// exactly the kind of access-control-by-URL-parameter bug that's trivial to
// spoof.
$deptSlug = trim((string) ($_GET['dept'] ?? ''));
$dept     = $deptSlug !== '' ? department_by_slug($deptSlug) : null;
$brandName  = $dept ? $dept['name'] : 'KWASU LCS';
$brandColor = $dept ? $dept['primary_color'] : '#07a701';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matric = trim((string)($_POST['matric_no'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($matric === '' || $password === '') {
        $error = 'Enter your matric number and password.';
    } else {
        ensure_login_lockout_columns();
        $stmt = db()->prepare('SELECT id, full_name, matric_no, password, role, lockout_until FROM users WHERE matric_no = ? LIMIT 1');
        $stmt->execute([$matric]);
        $user = $stmt->fetch();

        // Checked BEFORE password_verify() runs at all — a locked account
        // gets rejected without the password ever being tested, so timing
        // and response shape can't leak whether the password would have
        // been correct.
        $lockedSeconds = $user ? login_lockout_remaining($user) : 0;

        if ($lockedSeconds > 0) {
            $mins = (int) ceil($lockedSeconds / 60);
            $error = "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.';
        } elseif ($user && password_verify($password, $user['password'])) {
            // Session fixation defense: rotate the session id at the exact
            // moment of authentication, before any authenticated state is
            // attached to it. Without this, a session id an attacker set on
            // the victim's browser BEFORE login (e.g. via a crafted link on
            // a related subdomain) would silently become a valid
            // authenticated session the instant the victim logs in — the id
            // itself never changed, so the attacker's copy of it still
            // works. The `true` argument deletes the old session's data so
            // the pre-login id can't be replayed either.
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            clear_login_lockout((int)$user['id']);
            // "Remember this device" — a separate long-lived selector/
            // validator token (see issue_remember_token() in config.php),
            // NOT the session id itself. Reusing the session id here used
            // to mean a stolen "remember me" cookie WAS a live, un-
            // revocable session hijack with no separate expiry/audit trail
            // from the session system.
            if (!empty($_POST['remember'])) {
                issue_remember_token((int)$user['id']);
            }
            header('Location: dashboard.php');
            exit();
        } else {
            // Only a real, found account can be rate-limited this way —
            // there's no row to attach a counter to for a matric number
            // that doesn't exist, and incrementing nothing here is
            // intentional (this endpoint's response is already identical
            // for "wrong password" and "no such account" below, so this
            // doesn't create a new enumeration signal).
            if ($user) {
                record_failed_login((int)$user['id']);
            }
            $error = 'Invalid matric number or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = $dept ? ($dept['name'] . ' — Login') : 'Login';
    include __DIR__ . '/partials/head.php'; ?>
    <?php if ($dept): ?>
        <style>
            /* Cosmetic-only department accent (see the comment above $deptSlug
               in the PHP block) — swaps the brand color used by buttons/links
               on THIS pre-login page only, nothing auth-related. */
            :root {
                --primary: <?php echo h($brandColor); ?>;
                --primary-dark: <?php echo h($brandColor); ?>;
            }
        </style>
    <?php endif; ?>
</head>

<body class="auth-body">
    <div class="auth-theme-toggle">
        <button class="theme-toggle" role="switch" onclick="toggleTheme()" title="Dark mode"></button>
    </div>
    <div class="auth-shell">
        <div class="auth-shell-top">
            <div class="auth-panel auth-panel-left">
                <div class="brand-badge">
                    <?php if ($dept && $dept['logo_path']): ?>
                        <img src="<?php echo h($dept['logo_path']); ?>" class="brand-logo" alt="">
                    <?php else: ?>
                        <?php echo brand_logo(); ?>
                    <?php endif; ?>
                    <?php echo $dept ? h('KWASU LCS — ' . $dept['name']) : 'KWASU LCS'; ?>
                </div>
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
                    <label for="matric_no">Matric Number/ID</label>
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