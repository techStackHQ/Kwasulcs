<?php
/**
 * chat_delete.php — soft-deletes a message you sent yourself: content and
 * attachments stay in the database (nothing is destroyed on disk), but
 * deleted_at is set so every renderer shows a "message was deleted"
 * tombstone in its place instead. Only the message's own sender may delete it.
 */
require_once __DIR__ . '/config.php';
require_login();
// ensure_chat_tables() removed here — schema already established in
// production. See config.php's ensure_chat_tables().

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$user      = current_user();
$type      = (string) ($_POST['type'] ?? '');
$messageId = (int) ($_POST['message_id'] ?? 0);

if ($type === 'group') {
    $stmt = db()->prepare('SELECT * FROM chat_messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg || (int) $msg['sender_id'] !== (int) $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete your own messages.']);
        exit();
    }
    db()->prepare('UPDATE chat_messages SET deleted_at = NOW() WHERE id = ?')->execute([$messageId]);
    echo json_encode(['ok' => true]);
    exit();
}

if ($type === 'dm') {
    $stmt = db()->prepare('SELECT * FROM chat_dm_messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg || (int) $msg['sender_id'] !== (int) $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete your own messages.']);
        exit();
    }
    db()->prepare('UPDATE chat_dm_messages SET deleted_at = NOW() WHERE id = ?')->execute([$messageId]);
    echo json_encode(['ok' => true]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unknown type.']);
