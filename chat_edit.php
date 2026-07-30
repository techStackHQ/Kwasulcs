<?php
/**
 * chat_edit.php — edits the text of a message you sent yourself. Only the
 * message's own sender may edit it; attachments are untouched (there's
 * nothing to "edit" about a file, only the caption-like text content).
 */
require_once __DIR__ . '/config.php';
require_login();
ensure_chat_tables();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$user      = current_user();
$type      = (string) ($_POST['type'] ?? '');
$messageId = (int) ($_POST['message_id'] ?? 0);
$content   = trim((string) ($_POST['message'] ?? ''));

if ($content === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit();
}

if ($type === 'group') {
    $stmt = db()->prepare('SELECT * FROM chat_messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg || (int) $msg['sender_id'] !== (int) $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only edit your own messages.']);
        exit();
    }
    if ($msg['deleted_at']) {
        http_response_code(400);
        echo json_encode(['error' => 'This message was deleted.']);
        exit();
    }
    db()->prepare('UPDATE chat_messages SET content = ?, edited_at = NOW() WHERE id = ?')
        ->execute([$content, $messageId]);
    $updated = db()->prepare('SELECT content, edited_at FROM chat_messages WHERE id = ?');
    $updated->execute([$messageId]);
    $row = $updated->fetch();
    echo json_encode(['ok' => true, 'content' => $row['content'], 'edited_at' => $row['edited_at']]);
    exit();
}

if ($type === 'dm') {
    $stmt = db()->prepare('SELECT * FROM chat_dm_messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg || (int) $msg['sender_id'] !== (int) $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only edit your own messages.']);
        exit();
    }
    if ($msg['deleted_at']) {
        http_response_code(400);
        echo json_encode(['error' => 'This message was deleted.']);
        exit();
    }
    db()->prepare('UPDATE chat_dm_messages SET content = ?, edited_at = NOW() WHERE id = ?')
        ->execute([$content, $messageId]);
    $updated = db()->prepare('SELECT content, edited_at FROM chat_dm_messages WHERE id = ?');
    $updated->execute([$messageId]);
    $row = $updated->fetch();
    echo json_encode(['ok' => true, 'content' => $row['content'], 'edited_at' => $row['edited_at']]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unknown type.']);
