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

// Проверяем принадлежность и получаем ID плана
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

// Удаляем связанные записи (если нет каскадного удаления в БД)
$stmt = $pdo->prepare("DELETE FROM lesson_topics WHERE lesson_id = ?");
$stmt->execute([$lessonId]);

$stmt = $pdo->prepare("DELETE FROM lesson_tags WHERE lesson_id = ?");
$stmt->execute([$lessonId]);

$stmt = $pdo->prepare("DELETE FROM lesson_homework WHERE lesson_id = ?");
$stmt->execute([$lessonId]);

$stmt = $pdo->prepare("DELETE FROM lessons WHERE id = ?");
$stmt->execute([$lessonId]);

// Перенаправляем обратно на страницу плана
header("Location: plans.php?view=" . $planId);
exit;