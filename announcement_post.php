<?php
/**
 * announcement_post.php — lecturer/admin posts a course announcement, shown
 * in course.php's Announcements & Tutorials panel. Students cannot post.
 */
require_once __DIR__ . '/config.php';
require_login();
ensure_announcements_table();

$user     = current_user();
$courseId = (int) ($_POST['course_id'] ?? 0);
$title    = trim((string) ($_POST['title'] ?? ''));
$body     = trim((string) ($_POST['body'] ?? ''));

if (!enrolled_or_staff_access($courseId, $user) || $user['role'] === 'student') {
    http_response_code(403);
    exit('Forbidden');
}

if ($title === '' || $body === '') {
    header('Location: course.php?id=' . $courseId . '&error=announcement_incomplete');
    exit();
}

db()->prepare('INSERT INTO course_announcements (course_id, title, body, created_by) VALUES (?, ?, ?, ?)')
    ->execute([$courseId, $title, $body, $user['id']]);

header('Location: course.php?id=' . $courseId . '&announced=1');
