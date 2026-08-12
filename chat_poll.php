<?php
/**
 * chat_poll.php — lightweight polling endpoint, same pattern as
 * notify_poll.php: called every few seconds from an open chat thread to
 * fetch any new messages since the last one the client already has.
 */
require_once __DIR__ . '/config.php';
require_login();
// ensure_chat_tables() removed here — this endpoint is polled every 4s
// from an open chat thread, so it was re-running ~11 CREATE/ALTER TABLE
// statements every 4 seconds. Schema already established in production.
// See config.php's ensure_chat_tables().

header('Content-Type: application/json');

$user      = current_user();
$type      = (string) ($_GET['type'] ?? '');
$id        = (int) ($_GET['id'] ?? 0);
$sinceId   = (int) ($_GET['since_id'] ?? 0);
$sinceTime = (string) ($_GET['since_time'] ?? '1970-01-01 00:00:00');

try {
    if ($type === 'group') {
        $courseId = $id;
        if (!enrolled_or_staff_access($courseId, $user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }
        $courseStmt = db()->prepare('SELECT lecturer_id FROM courses WHERE id = ?');
        $courseStmt->execute([$courseId]);
        $lecturerId = (int) $courseStmt->fetchColumn();

        $groupId = get_or_create_chat_group($courseId);

        $stmt = db()->prepare('
            SELECT m.*, u.full_name AS sender_name, u.role AS sender_role, u.google_picture AS sender_picture
            FROM chat_messages m JOIN users u ON u.id = m.sender_id
            WHERE m.group_id = ? AND m.id > ?
            ORDER BY m.id ASC LIMIT 100
        ');
        $stmt->execute([$groupId, $sinceId]);
        $messages = $stmt->fetchAll();

        $msgIds = array_column($messages, 'id');
        $attachmentsByMsg = [];
        if ($msgIds) {
            $in = implode(',', array_fill(0, count($msgIds), '?'));
            $atStmt = db()->prepare("SELECT * FROM chat_attachments WHERE message_id IN ($in)");
            $atStmt->execute($msgIds);
            foreach ($atStmt->fetchAll() as $a) {
                $attachmentsByMsg[$a['message_id']][] = [
                    'id'            => (int) $a['id'],
                    'original_name' => $a['original_name'],
                    'file_type'     => $a['file_type'],
                    'size'          => attachment_filesize($a['file_path']),
                ];
            }
        }

        $out = array_map(function ($m) use ($lecturerId, $attachmentsByMsg) {
            return [
                'id'              => (int) $m['id'],
                'sender_id'       => (int) $m['sender_id'],
                'sender_name'     => $m['sender_name'],
                'sender_role'     => $m['sender_role'],
                'sender_color'    => user_color((int) $m['sender_id']),
                'sender_initials' => initials($m['sender_name']),
                'sender_picture'  => $m['sender_picture'],
                'is_lecturer'     => (int) $m['sender_id'] === $lecturerId,
                'content'         => $m['content'],
                'created_at'      => $m['created_at'],
                'edited_at'       => $m['edited_at'],
                'deleted_at'      => $m['deleted_at'],
                'attachments'     => $attachmentsByMsg[$m['id']] ?? [],
            ];
        }, $messages);

        // Bump the read cursor since the user is actively viewing this thread.
        if ($out) {
            $lastId = end($out)['id'];
            db()->prepare('
                INSERT INTO chat_reads (user_id, group_id, last_read_message_id) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))
            ')->execute([$user['id'], $groupId, $lastId]);
        }

        $readUpto = chat_group_read_upto($courseId, $groupId, $user);

        // Messages the client already has that were edited/deleted since its
        // last check — polling only returns brand-new ids above, so without
        // this, an edit/delete by the sender would never reach anyone who
        // already had the page open.
        $updStmt = db()->prepare('
            SELECT id, content, edited_at, deleted_at FROM chat_messages
            WHERE group_id = ? AND id <= ? AND (edited_at > ? OR deleted_at > ?)
            ORDER BY id ASC LIMIT 100
        ');
        $updStmt->execute([$groupId, $sinceId, $sinceTime, $sinceTime]);
        $updates = array_map(fn($m) => [
            'id'         => (int) $m['id'],
            'content'    => $m['content'],
            'edited_at'  => $m['edited_at'],
            'deleted_at' => $m['deleted_at'],
        ], $updStmt->fetchAll());

        echo json_encode(['messages' => $out, 'updates' => $updates, 'read_upto' => $readUpto, 'server_time' => date('Y-m-d H:i:s')]);
        exit();
    }

    if ($type === 'dm') {
        $threadId = $id;
        $threadStmt = db()->prepare('SELECT * FROM dm_threads WHERE id = ?');
        $threadStmt->execute([$threadId]);
        $thread = $threadStmt->fetch();
        if (!$thread || ((int) $thread['user_a_id'] !== (int) $user['id'] && (int) $thread['user_b_id'] !== (int) $user['id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }

        $stmt = db()->prepare('SELECT * FROM chat_dm_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC LIMIT 100');
        $stmt->execute([$threadId, $sinceId]);
        $messages = $stmt->fetchAll();

        $msgIds = array_column($messages, 'id');
        $attachmentsByMsg = [];
        if ($msgIds) {
            $in = implode(',', array_fill(0, count($msgIds), '?'));
            $atStmt = db()->prepare("SELECT * FROM chat_attachments WHERE dm_message_id IN ($in)");
            $atStmt->execute($msgIds);
            foreach ($atStmt->fetchAll() as $a) {
                $attachmentsByMsg[$a['dm_message_id']][] = [
                    'id'            => (int) $a['id'],
                    'original_name' => $a['original_name'],
                    'file_type'     => $a['file_type'],
                    'size'          => attachment_filesize($a['file_path']),
                ];
            }
        }

        $out = array_map(function ($m) use ($attachmentsByMsg) {
            return [
                'id'          => (int) $m['id'],
                'sender_id'   => (int) $m['sender_id'],
                'content'     => $m['content'],
                'created_at'  => $m['created_at'],
                'edited_at'   => $m['edited_at'],
                'deleted_at'  => $m['deleted_at'],
                'attachments' => $attachmentsByMsg[$m['id']] ?? [],
            ];
        }, $messages);

        if ($out) {
            $lastId = end($out)['id'];
            db()->prepare('
                INSERT INTO dm_reads (user_id, thread_id, last_read_message_id) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))
            ')->execute([$user['id'], $threadId, $lastId]);
        }

        $readUpto = chat_dm_read_upto($threadId, (int) $thread['user_a_id'], (int) $thread['user_b_id'], (int) $user['id']);

        $updStmt = db()->prepare('
            SELECT id, content, edited_at, deleted_at FROM chat_dm_messages
            WHERE thread_id = ? AND id <= ? AND (edited_at > ? OR deleted_at > ?)
            ORDER BY id ASC LIMIT 100
        ');
        $updStmt->execute([$threadId, $sinceId, $sinceTime, $sinceTime]);
        $updates = array_map(fn($m) => [
            'id'         => (int) $m['id'],
            'content'    => $m['content'],
            'edited_at'  => $m['edited_at'],
            'deleted_at' => $m['deleted_at'],
        ], $updStmt->fetchAll());

        echo json_encode(['messages' => $out, 'updates' => $updates, 'read_upto' => $readUpto, 'server_time' => date('Y-m-d H:i:s')]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown type']);
} catch (\Throwable $e) {
    http_response_code(200); // don't surface errors to the polling fetch
    echo json_encode(['messages' => [], 'error' => $e->getMessage()]);
}
