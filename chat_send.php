<?php
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

$user     = current_user();
$type     = (string) ($_POST['type'] ?? '');
$targetId = (int) ($_POST['target_id'] ?? 0);
$message  = trim((string) ($_POST['message'] ?? ''));
$hasFile  = isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$isVoiceNote = !empty($_POST['voice_note']);
$uploadMimeOverrides = $isVoiceNote ? voice_note_extra_mime_types() : [];

if ($message === '' && !$hasFile) {
    http_response_code(400);
    echo json_encode(['error' => 'Message or attachment required.']);
    exit();
}

if ($type === 'group') {
    $courseId = $targetId;
    if (!enrolled_or_staff_access($courseId, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — you are not a member of this course.']);
        exit();
    }
    $courseStmt = db()->prepare('SELECT lecturer_id FROM courses WHERE id = ?');
    $courseStmt->execute([$courseId]);
    $course = $courseStmt->fetch();
    if (!$course) {
        http_response_code(404);
        echo json_encode(['error' => 'Course not found.']);
        exit();
    }
    $groupId = get_or_create_chat_group($courseId);

    db()->prepare('INSERT INTO chat_messages (group_id, sender_id, content) VALUES (?, ?, ?)')
        ->execute([$groupId, $user['id'], $message !== '' ? $message : null]);
    $messageId = (int) db()->lastInsertId();

    $attachment = null;
    if ($hasFile) {
        try {
            $saved = save_upload($_FILES['attachment'], 'chat/group_' . $groupId, $uploadMimeOverrides);
            db()->prepare('INSERT INTO chat_attachments (message_id, file_path, original_name, file_type) VALUES (?, ?, ?, ?)')
                ->execute([$messageId, $saved['path'], $_FILES['attachment']['name'], $saved['ext']]);
            $attachment = [
                'id'            => (int) db()->lastInsertId(),
                'original_name' => $_FILES['attachment']['name'],
                'file_type'     => $saved['ext'],
                'size'          => (int) ($_FILES['attachment']['size'] ?? 0),
            ];
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit();
        }
    }

    $createdStmt = db()->prepare('SELECT created_at FROM chat_messages WHERE id = ?');
    $createdStmt->execute([$messageId]);

    echo json_encode(['ok' => true, 'message' => [
        'id'              => $messageId,
        'sender_id'       => (int) $user['id'],
        'sender_name'     => $user['full_name'],
        'sender_role'     => $user['role'],
        'sender_color'    => user_color((int) $user['id']),
        'sender_initials' => initials($user['full_name']),
        'sender_picture'  => $user['google_picture'] ?? null,
        'is_lecturer'     => (int) $course['lecturer_id'] === (int) $user['id'],
        'content'         => $message,
        'created_at'      => $createdStmt->fetchColumn(),
        'attachments'     => $attachment ? [$attachment] : [],
    ]]);
    exit();
}

if ($type === 'dm') {
    $threadId = $targetId;
    $threadStmt = db()->prepare('SELECT * FROM dm_threads WHERE id = ?');
    $threadStmt->execute([$threadId]);
    $thread = $threadStmt->fetch();
    if (!$thread || ((int) $thread['user_a_id'] !== (int) $user['id'] && (int) $thread['user_b_id'] !== (int) $user['id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden.']);
        exit();
    }
    // Defense in depth: re-check the pair still shares a course (e.g. in case
    // an enrollment was later removed).
    if (!share_a_course((int) $thread['user_a_id'], (int) $thread['user_b_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'You no longer share a course with this user.']);
        exit();
    }

    db()->prepare('INSERT INTO chat_dm_messages (thread_id, sender_id, content) VALUES (?, ?, ?)')
        ->execute([$threadId, $user['id'], $message !== '' ? $message : null]);
    $messageId = (int) db()->lastInsertId();

    $attachment = null;
    if ($hasFile) {
        try {
            $saved = save_upload($_FILES['attachment'], 'chat/dm_' . $threadId, $uploadMimeOverrides);
            db()->prepare('INSERT INTO chat_attachments (dm_message_id, file_path, original_name, file_type) VALUES (?, ?, ?, ?)')
                ->execute([$messageId, $saved['path'], $_FILES['attachment']['name'], $saved['ext']]);
            $attachment = [
                'id'            => (int) db()->lastInsertId(),
                'original_name' => $_FILES['attachment']['name'],
                'file_type'     => $saved['ext'],
                'size'          => (int) ($_FILES['attachment']['size'] ?? 0),
            ];
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit();
        }
    }

    $createdStmt = db()->prepare('SELECT created_at FROM chat_dm_messages WHERE id = ?');
    $createdStmt->execute([$messageId]);

    echo json_encode(['ok' => true, 'message' => [
        'id'          => $messageId,
        'sender_id'   => (int) $user['id'],
        'content'     => $message,
        'created_at'  => $createdStmt->fetchColumn(),
        'attachments' => $attachment ? [$attachment] : [],
    ]]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unknown type.']);
