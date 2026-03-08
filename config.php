<?php
// config.php
session_start();

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'repetitor2029');

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Функция для проверки авторизации
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

// Функция для проверки роли
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Функция для редиректа
function redirect($url) {
    header("Location: $url");
    exit();
}

// Функция для получения текущего пользователя
function getCurrentUser($pdo) {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// Создание администратора по умолчанию при первом запуске
try {
    // Проверяем, существует ли администратор
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@mail.com'");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        // Получаем ID роли администратора
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin'");
        $stmt->execute();
        $adminRole = $stmt->fetch();
        
        if ($adminRole) {
            // Создаем администратора
            $passwordHash = password_hash('123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, first_name, last_name, role_id, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                'admin',
                'admin@mail.com',
                $passwordHash,
                'Администратор',
                'Системы',
                $adminRole['id'],
                1
            ]);
        }
    }
} catch (Exception $e) {
    // Игнорируем ошибки при создании администратора
}
?>