<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/password_reset.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit();
}

$userId = (int) ($_SESSION['pwreset']['user_id'] ?? 0);
if ($userId === 0 || empty($_SESSION['pwreset']['email_verified'])) {
    echo json_encode(['ok' => false, 'restart' => true, 'message' => 'Your session has expired. Please start over.']);
    exit();
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$answer  = trim((string) ($body['captcha'] ?? ''));

if ($answer === '' || !pr_captcha_verify($answer)) {
    echo json_encode(['ok' => false, 'message' => 'Incorrect CAPTCHA. Please try again.']);
    exit();
}

// Passing CAPTCHA clears any earlier code-attempt lockout — this is exactly
// the re-verification gate step 5 sends users back to after 5 wrong codes.
unset($_SESSION['pwreset']['captcha_required']);

// Avoid firing a duplicate email if the user double-submits or navigates
// back into this step while a code they already have is still live.
$remaining = pr_active_code_remaining($userId);
if ($remaining > 0) {
    $_SESSION['pwreset']['code_sent'] = true;
    echo json_encode(['ok' => true, 'expires_in' => $remaining]);
    exit();
}

$stmt = db()->prepare('SELECT email, full_name FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || empty($user['email'])) {
    echo json_encode(['ok' => false, 'message' => 'We could not send a verification email right now. Please contact Technical Support.']);
    exit();
}

$code = pr_generate_code();
pr_store_code($userId, $code);
$sent = pr_send_code_email($user['email'], $user['full_name'], $code);

if (!$sent) {
    pr_invalidate_code($userId);
    echo json_encode(['ok' => false, 'message' => 'We could not send a verification email right now. Please try again in a moment.']);
    exit();
}

$_SESSION['pwreset']['code_sent']     = true;
$_SESSION['pwreset']['code_attempts'] = 0;

echo json_encode(['ok' => true, 'expires_in' => PR_CODE_TTL_SECONDS]);
