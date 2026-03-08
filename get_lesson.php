<?php
require_once 'config.php';

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$currentUser = getCurrentUser($pdo);
if (!in_array($currentUser['role_name'], ['admin', 'teacher'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

$lessonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$lessonId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID урока']);
    exit;
}

// Получаем урок с проверкой принадлежности через план
$stmt = $pdo->prepare("
    SELECT l.*, lp.teacher_id
    FROM lessons l
    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
    WHERE l.id = ?
");
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch();

if (!$lesson || $lesson['teacher_id'] != $_SESSION['user_id']) {
    http_response_code(404);
    echo json_encode(['error' => 'Урок не найден']);
    exit;
}

// Получаем темы урока
$stmt = $pdo->prepare("SELECT topic_id FROM lesson_topics WHERE lesson_id = ?");
$stmt->execute([$lessonId]);
$topics = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем метки урока
$stmt = $pdo->prepare("SELECT tag_id FROM lesson_tags WHERE lesson_id = ?");
$stmt->execute([$lessonId]);
$tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем домашнее задание
$stmt = $pdo->prepare("SELECT task_id, custom_description FROM lesson_homework WHERE lesson_id = ?");
$stmt->execute([$lessonId]);
$homework = $stmt->fetch();

$response = [
    'id' => $lesson['id'],
    'date' => $lesson['date'],
    'time' => $lesson['time'],
    'title' => $lesson['title'],
    'grade_for_lesson' => $lesson['grade_for_lesson'],
    'grade_for_homework' => $lesson['grade_for_homework'],
    'homework_comment' => $lesson['homework_comment'],
    'lesson_comment' => $lesson['lesson_comment'],
    'external_link' => $lesson['external_link'],
    'link_comment' => $lesson['link_comment'],
    'is_completed' => $lesson['is_completed'],
    'topics' => $topics,
    'tags' => $tags,
    'homework_task_id' => $homework ? $homework['task_id'] : null,
    'custom_description' => $homework ? $homework['custom_description'] : null
];

echo json_encode($response);