<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $user['role'] !== 'student') {
    header('Location: dashboard.php');
    exit();
}

$videoId = (int)($_POST['video_id'] ?? 0);
$courseId = (int)($_POST['course_id'] ?? 0);
$watched = isset($_POST['watched']) ? 1 : 0;

if ($videoId > 0 && $courseId > 0 && is_enrolled((int)$user['id'], $courseId)) {
    $stmt = db()->prepare('SELECT id FROM video_progress WHERE student_id = ? AND video_id = ?');
    $stmt->execute([$user['id'], $videoId]);
    if ($stmt->fetchColumn()) {
        $upd = db()->prepare('UPDATE video_progress SET watched = ?, updated_at = CURRENT_TIMESTAMP WHERE student_id = ? AND video_id = ?');
        $upd->execute([$watched, $user['id'], $videoId]);
    } else {
        $ins = db()->prepare('INSERT INTO video_progress (student_id, video_id, watched) VALUES (?, ?, ?)');
        $ins->execute([$user['id'], $videoId, $watched]);
    }
}

header('Location: course.php?id=' . $courseId);
exit();
