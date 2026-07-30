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
if ($userId === 0) {
    echo json_encode(['ok' => false, 'restart' => true, 'message' => 'Your session has expired. Please start over.']);
    exit();
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim((string) ($body['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => "We couldn't verify your matric number and email combination. Please check your details and try again."]);
    exit();
}

$stmt = db()->prepare('SELECT id, matric_no, email, full_name FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !pr_email_matches($user, $email)) {
    // Deliberately identical message whether the matric lookup, the email, or
    // both are wrong — never reveal which part of a guess was correct.
    echo json_encode(['ok' => false, 'message' => "We couldn't verify your matric number and email combination. Please check your details and try again."]);
    exit();
}

$_SESSION['pwreset']['email_verified'] = true;
echo json_encode(['ok' => true]);
