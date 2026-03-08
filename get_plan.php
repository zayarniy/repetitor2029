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

$planId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$planId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID плана']);
    exit;
}

// Получаем план и проверяем принадлежность
$stmt = $pdo->prepare("
    SELECT id, name, student_id, description, is_active
    FROM lesson_plans
    WHERE id = ? AND teacher_id = ?
");
$stmt->execute([$planId, $_SESSION['user_id']]);
$plan = $stmt->fetch();

if (!$plan) {
    http_response_code(404);
    echo json_encode(['error' => 'План не найден']);
    exit;
}

echo json_encode($plan);