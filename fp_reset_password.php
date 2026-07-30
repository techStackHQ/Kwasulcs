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
if ($userId === 0 || empty($_SESSION['pwreset']['code_verified'])) {
    echo json_encode(['ok' => false, 'restart' => true, 'message' => 'Your session has expired. Please start over.']);
    exit();
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$password = (string) ($body['password'] ?? '');
$confirm  = (string) ($body['confirm_password'] ?? '');

if ($password !== $confirm) {
    echo json_encode(['ok' => false, 'message' => 'Passwords do not match.']);
    exit();
}

if (!pr_password_meets_requirements($password)) {
    echo json_encode(['ok' => false, 'message' => 'Password does not meet the requirements below.']);
    exit();
}

pr_update_password($userId, $password);
pr_invalidate_code($userId);

// Full teardown of reset state — nothing about this flow survives past a
// successful reset, and the session id is rotated as a defense-in-depth
// measure since this session just handled a sensitive credential change.
unset($_SESSION['pwreset']);
session_regenerate_id(true);

echo json_encode(['ok' => true]);
