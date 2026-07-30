<?php
require_once __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$user = current_user();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$message = trim((string) ($body['message'] ?? ''));

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required.']);
    exit();
}

try {
    db()->exec("CREATE TABLE IF NOT EXISTS feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_feedback_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) { /* table may already exist */
}

db()->prepare('INSERT INTO feedback (user_id, message) VALUES (?, ?)')
    ->execute([$user['id'], $message]);

// Best-effort notify admins by email — feedback is still saved even if mail fails.
try {
    $admins = db()->query("SELECT email, full_name FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''")->fetchAll();
    $html = '<div style="font-family:sans-serif;"><h2>📢 New Feedback</h2>'
        . '<p><strong>From:</strong> ' . h($user['full_name']) . ' (' . h($user['matric_no']) . ')</p>'
        . '<p>' . nl2br(h($message)) . '</p></div>';
    foreach ($admins as $admin) {
        send_mail($admin['email'], $admin['full_name'], '[KWASU LCS] New Feedback Submitted', $html);
    }
} catch (\Throwable $e) { /* email is best-effort */
}

echo json_encode(['ok' => true]);
