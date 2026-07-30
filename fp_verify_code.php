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
if ($userId === 0 || empty($_SESSION['pwreset']['code_sent'])) {
    echo json_encode(['ok' => false, 'restart' => true, 'message' => 'Your session has expired. Please start over.']);
    exit();
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$code = trim((string) ($body['code'] ?? ''));

if ($code === '') {
    echo json_encode(['ok' => false, 'reason' => 'incorrect', 'message' => 'Incorrect verification code. Please try again.']);
    exit();
}

$result = pr_verify_code($userId, $code);

if ($result['ok']) {
    $_SESSION['pwreset']['code_verified'] = true;
    echo json_encode(['ok' => true]);
    exit();
}

switch ($result['reason']) {
    case 'locked':
        $_SESSION['pwreset']['captcha_required'] = true;
        $_SESSION['pwreset']['code_sent']         = false;
        echo json_encode([
            'ok'      => false,
            'reason'  => 'locked',
            'message' => 'Too many incorrect attempts. Please verify the CAPTCHA again before requesting a new code.',
        ]);
        break;
    case 'expired':
    case 'none':
        echo json_encode([
            'ok'      => false,
            'reason'  => 'expired',
            'message' => 'Verification code expired. Please request a new code.',
        ]);
        break;
    default:
        echo json_encode([
            'ok'      => false,
            'reason'  => 'incorrect',
            'message' => 'Incorrect verification code. Please try again.',
        ]);
}
