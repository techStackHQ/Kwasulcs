<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$id   = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM chat_attachments WHERE id = ?');
$stmt->execute([$id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('File not found');
}

$allowed = false;

if ($attachment['message_id']) {
    $stmt = db()->prepare('SELECT g.course_id FROM chat_messages m JOIN chat_groups g ON g.id = m.group_id WHERE m.id = ?');
    $stmt->execute([$attachment['message_id']]);
    $courseId = (int) $stmt->fetchColumn();
    $allowed = $courseId > 0 && enrolled_or_staff_access($courseId, $user);
} elseif ($attachment['dm_message_id']) {
    $stmt = db()->prepare('SELECT t.user_a_id, t.user_b_id FROM chat_dm_messages dm JOIN dm_threads t ON t.id = dm.thread_id WHERE dm.id = ?');
    $stmt->execute([$attachment['dm_message_id']]);
    $thread = $stmt->fetch();
    $allowed = $thread && ((int) $thread['user_a_id'] === (int) $user['id'] || (int) $thread['user_b_id'] === (int) $user['id']);
}

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($attachment['file_path'], '/');
if (!is_file($abs)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower($attachment['file_type']);
$inlineMimes = [
    'pdf' => 'application/pdf', 'txt' => 'text/plain',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4',
    'webm' => 'audio/webm', 'ogg' => 'audio/ogg',
];
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment['original_name']);
$fileSize = filesize($abs);

header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

if (isset($inlineMimes[$ext])) {
    header('Content-Type: ' . $inlineMimes[$ext]);
    header('Content-Disposition: inline; filename="' . $safeName . '"');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
}

readfile($abs);
