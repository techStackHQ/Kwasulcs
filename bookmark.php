<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if ($user['role'] !== 'student' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit();
}

$topicId = (int)($_POST['topic_id'] ?? 0);
$courseId = (int)($_POST['course_id'] ?? 0);

if ($topicId > 0 && $courseId > 0 && is_enrolled((int)$user['id'], $courseId)) {
    $check = db()->prepare('SELECT id FROM bookmarks WHERE student_id = ? AND topic_id = ?');
    $check->execute([$user['id'], $topicId]);
    if ($check->fetchColumn()) {
        $del = db()->prepare('DELETE FROM bookmarks WHERE student_id = ? AND topic_id = ?');
        $del->execute([$user['id'], $topicId]);
    } else {
        $ins = db()->prepare('INSERT INTO bookmarks (student_id, topic_id) VALUES (?, ?)');
        $ins->execute([$user['id'], $topicId]);
    }
}

header('Location: course.php?id=' . $courseId);
exit();
