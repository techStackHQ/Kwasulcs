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

if (!empty($_SESSION['pwreset']['captcha_required'])) {
    echo json_encode(['ok' => false, 'need_captcha' => true, 'message' => 'Please verify the CAPTCHA again before requesting a new code.']);
    exit();
}

// Mirrors the UI rule (Resend stays disabled until the timer hits zero) —
// enforced here too so the endpoint can't be called early via devtools.
if (pr_has_active_code($userId)) {
    echo json_encode(['ok' => false, 'message' => 'Your current code is still active. Please wait for it to expire before requesting a new one.']);
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
