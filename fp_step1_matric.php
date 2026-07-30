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

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$matric = trim((string) ($body['matric_no'] ?? ''));

if ($matric === '') {
    echo json_encode(['ok' => false, 'message' => 'Enter your matric number.']);
    exit();
}

$_SESSION['pwreset'] = $_SESSION['pwreset'] ?? [];
$attempts = (int) ($_SESSION['pwreset']['matric_attempts'] ?? 0);

if ($attempts >= PR_MAX_MATRIC_ATTEMPTS) {
    echo json_encode([
        'ok'      => false,
        'locked'  => true,
        'message' => 'Still having trouble? Contact Technical Support for assistance.',
    ]);
    exit();
}

$user = pr_find_user_by_matric($matric);

if (!$user) {
    $attempts++;
    $_SESSION['pwreset']['matric_attempts'] = $attempts;
    $locked = $attempts >= PR_MAX_MATRIC_ATTEMPTS;
    echo json_encode([
        'ok'      => false,
        'locked'  => $locked,
        'message' => $locked
            ? 'Still having trouble? Contact Technical Support for assistance.'
            : "We couldn't verify your account information. Please check your matric number and try again.",
    ]);
    exit();
}

// Fresh, correctly-matched matric number — start a clean wizard session.
$_SESSION['pwreset'] = [
    'user_id'   => (int) $user['id'],
    'matric_no' => $user['matric_no'],
];

echo json_encode(['ok' => true]);
