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

$planId = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
if (!$planId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID плана']);
    exit;
}

// Проверяем принадлежность плана
$stmt = $pdo->prepare("SELECT id FROM lesson_plans WHERE id = ? AND teacher_id = ?");
$stmt->execute([$planId, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'План не найден']);
    exit;
}

// Получаем максимальный sort_order для нового урока
$stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM lessons WHERE lesson_plan_id = ?");
$stmt->execute([$planId]);
$maxOrder = $stmt->fetch()['max_order'] ?? 0;
$newOrder = $maxOrder + 1;

// Вставляем новый урок (с сегодняшней датой)
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    INSERT INTO lessons (lesson_plan_id, date, sort_order)
    VALUES (?, ?, ?)
");
$stmt->execute([$planId, $today, $newOrder]);
$newLessonId = $pdo->lastInsertId();

echo json_encode(['success' => true, 'lesson_id' => $newLessonId]);