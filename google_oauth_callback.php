<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_login();

$user = current_user();

function google_oauth_fail(string $reason): void
{
    error_log('[LCS] Google OAuth callback failed: ' . $reason . ' GET=' . json_encode($_GET));
    unset($_SESSION['google_oauth_state']);
    header('Location: settings.php?google=error&reason=' . rawurlencode($reason));
    exit();
}

if (!google_oauth_configured()) {
    google_oauth_fail('not_configured');
}

// The user declined consent on Google's screen, or Google reported some
// other error — nothing to exchange, just bounce back.
if (!empty($_GET['error'])) {
    google_oauth_fail('denied');
}

$expectedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']);
$state = (string) ($_GET['state'] ?? '');

if ($expectedState === null || !hash_equals($expectedState, $state)) {
    google_oauth_fail('bad_state');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    google_oauth_fail('missing_code');
}

$tokens = google_oauth_exchange_code($code);
if (!$tokens || empty($tokens['access_token'])) {
    google_oauth_fail('token_exchange_failed');
}

$profile = google_oauth_fetch_userinfo($tokens['access_token']);
if (!$profile || empty($profile['sub']) || empty($profile['email'])) {
    google_oauth_fail('profile_fetch_failed');
}

// Only trust the email if Google itself has verified it — an unverified
// address on the Google side is no stronger a signal than a user just
// typing an email into a text box.
$emailVerified = $profile['email_verified'] ?? false;
if ($emailVerified === 'true') {
    $emailVerified = true;
}
if (!$emailVerified) {
    google_oauth_fail('email_not_verified');
}

ensure_google_oauth_columns();

$googleId      = (string) $profile['sub'];
$googleEmail   = (string) $profile['email'];
$googlePicture = (string) ($profile['picture'] ?? '');

// Prefill the account's email only if it doesn't have one yet — an existing
// email on file was set by an admin and isn't silently overwritten here.
if (empty($user['email'])) {
    db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$googleEmail, $user['id']]);
}

db()->prepare('UPDATE users SET google_id = ?, google_picture = ?, google_connected_at = NOW() WHERE id = ?')
    ->execute([$googleId, $googlePicture, $user['id']]);

header('Location: settings.php?google=connected');
exit();
