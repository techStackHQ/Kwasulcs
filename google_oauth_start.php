<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_login();

if (!google_oauth_configured()) {
    header('Location: settings.php?google=not_configured');
    exit();
}

// CSRF protection: the state Google echoes back on the callback must match
// what we generated here, tied to this logged-in session.
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . google_oauth_auth_url($state));
exit();
