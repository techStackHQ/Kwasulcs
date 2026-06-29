<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
if ($user['role'] !== 'student') {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit();
}

$courseId = (int)($_POST['course_id'] ?? 0);
if ($courseId <= 0) {
    header('Location: dashboard.php');
    exit();
}

$stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: course.php?id=' . $courseId . '&error=course_unavailable');
    exit();
}

if (!is_enrolled((int)$user['id'], $courseId)) {
    $stmt = db()->prepare('INSERT INTO enrollments (student_id, course_id) VALUES (?, ?)');
    $stmt->execute([$user['id'], $courseId]);
}

header('Location: course.php?id=' . $courseId . '&registered=1');
exit();
