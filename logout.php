<?php
require_once __DIR__ . '/config.php';

// Revoke the remember-me token too, not just the session — otherwise a
// "logged out" browser would silently log itself back in on the next visit
// via attempt_remember_login().
$cookie = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
if ($cookie !== '' && str_contains($cookie, ':')) {
    [$selector] = explode(':', $cookie, 2);
    ensure_remember_tokens_table();
    db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
}
clear_remember_cookie();

session_destroy();
header('Location: index.php');
exit();
