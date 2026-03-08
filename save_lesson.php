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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не разрешен']);
    exit;
}

$lessonId = isset($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : 0;
if (!$lessonId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID урока']);
    exit;
}

// Проверяем принадлежность урока через план
$stmt = $pdo->prepare("
    SELECT l.id, lp.teacher_id, lp.id as plan_id
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

$planId = $lesson['plan_id'];

// Обновляем основные поля
$date = $_POST['date'] ?? '';
$time = !empty($_POST['time']) ? $_POST['time'] : null;
$title = !empty(trim($_POST['title'] ?? '')) ? trim($_POST['title']) : null;
$gradeLesson = isset($_POST['grade_for_lesson']) && $_POST['grade_for_lesson'] !== '' ? (float)$_POST['grade_for_lesson'] : null;
$gradeHomework = isset($_POST['grade_for_homework']) && $_POST['grade_for_homework'] !== '' ? (float)$_POST['grade_for_homework'] : null;
$homeworkComment = !empty(trim($_POST['homework_comment'] ?? '')) ? trim($_POST['homework_comment']) : null;
$lessonComment = !empty(trim($_POST['lesson_comment'] ?? '')) ? trim($_POST['lesson_comment']) : null;
$externalLink = !empty(trim($_POST['external_link'] ?? '')) ? trim($_POST['external_link']) : null;
$linkComment = !empty(trim($_POST['link_comment'] ?? '')) ? trim($_POST['link_comment']) : null;
$isCompleted = isset($_POST['is_completed']) ? 1 : 0;

$stmt = $pdo->prepare("
    UPDATE lessons
    SET date = ?, time = ?, title = ?, grade_for_lesson = ?, grade_for_homework = ?,
        homework_comment = ?, lesson_comment = ?, external_link = ?, link_comment = ?, is_completed = ?
    WHERE id = ?
");
$stmt->execute([
    $date,
    $time,
    $title,
    $gradeLesson,
    $gradeHomework,
    $homeworkComment,
    $lessonComment,
    $externalLink,
    $linkComment,
    $isCompleted,
    $lessonId
]);

// Обновляем темы
$stmt = $pdo->prepare("DELETE FROM lesson_topics WHERE lesson_id = ?");
$stmt->execute([$lessonId]);

if (!empty($_POST['topics']) && is_array($_POST['topics'])) {
    $topicIds = array_filter($_POST['topics']);
    if (!empty($topicIds)) {
        $stmt = $pdo->prepare("INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?, ?)");
        foreach ($topicIds as $topicId) {
            // Проверяем, что тема принадлежит пользователю
            $check = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ?");
            $check->execute([$topicId, $_SESSION['user_id']]);
            if ($check->fetch()) {
                $stmt->execute([$lessonId, $topicId]);
            }
        }
    }
}

// Обновляем метки
$stmt = $pdo->prepare("DELETE FROM lesson_tags WHERE lesson_id = ?");
$stmt->execute([$lessonId]);

if (!empty($_POST['tags']) && is_array($_POST['tags'])) {
    $tagIds = array_filter($_POST['tags']);
    if (!empty($tagIds)) {
        $stmt = $pdo->prepare("INSERT INTO lesson_tags (lesson_id, tag_id) VALUES (?, ?)");
        foreach ($tagIds as $tagId) {
            $check = $pdo->prepare("SELECT id FROM tags WHERE id = ? AND teacher_id = ?");
            $check->execute([$tagId, $_SESSION['user_id']]);
            if ($check->fetch()) {
                $stmt->execute([$lessonId, $tagId]);
            }
        }
    }
}

// Обновляем домашнее задание
$stmt = $pdo->prepare("DELETE FROM lesson_homework WHERE lesson_id = ?");
$stmt->execute([$lessonId]);

$taskId = isset($_POST['homework_task_id']) && $_POST['homework_task_id'] !== '' ? (int)$_POST['homework_task_id'] : null;
$customDescription = !empty(trim($_POST['homework_custom'] ?? '')) ? trim($_POST['homework_custom']) : null;

if ($taskId || $customDescription) {
    $stmt = $pdo->prepare("
        INSERT INTO lesson_homework (lesson_id, task_id, custom_description)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$lessonId, $taskId, $customDescription]);
}

// Перенаправляем обратно на страницу плана
header("Location: plans.php?view=" . $planId);
exit;