<?php
// students.php
require_once 'config.php';

// Проверка авторизации
if (!isAuthenticated()) {
    redirect('index.php');
}

$currentUser = getCurrentUser($pdo);

// Проверка доступа (только admin и teacher)
if (!in_array($currentUser['role_name'], ['admin', 'teacher'])) {
    redirect('dashboard.php');
}

// Обработка действий
$message = '';
$error = '';

// Добавление ученика
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_student':
            try {
                $pdo->beginTransaction();
                
                // Создаем пользователя
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $middleName = trim($_POST['middle_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $messenger1 = trim($_POST['messenger_1'] ?? '');
                $messenger2 = trim($_POST['messenger_2'] ?? '');
                $messenger3 = trim($_POST['messenger_3'] ?? '');
                
                // Генерируем username из email или создаем на основе имени
                $username = $email ?: strtolower($firstName . '.' . $lastName);
                
                // Получаем ID роли ученика
                $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'student'");
                $stmt->execute();
                $studentRole = $stmt->fetch();
                
                // Временный пароль (в реальном проекте нужно отправлять по email)
                $tempPassword = bin2hex(random_bytes(4));
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, first_name, last_name, middle_name, 
                                      phone, messenger_1, messenger_2, messenger_3, role_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $username,
                    $email ?: null,
                    $passwordHash,
                    $firstName,
                    $lastName,
                    $middleName ?: null,
                    $phone ?: null,
                    $messenger1 ?: null,
                    $messenger2 ?: null,
                    $messenger3 ?: null,
                    $studentRole['id']
                ]);
                
                $userId = $pdo->lastInsertId();
                
                // Создаем запись ученика
                $city = trim($_POST['city'] ?? '');
                $startDate = $_POST['start_date'] ?: null;
                $plannedEndDate = $_POST['planned_end_date'] ?: null;
                $goals = trim($_POST['goals'] ?? '');
                $additionalInfo = trim($_POST['additional_info'] ?? '');
                $lessonCost = $_POST['lesson_cost'] ?: null;
                $lessonDuration = $_POST['lesson_duration'] ?: 60;
                $lessonsPerWeek = $_POST['lessons_per_week'] ?: 1;
                
                $stmt = $pdo->prepare("
                    INSERT INTO students (user_id, city, start_date, planned_end_date, goals, additional_info,
                                        lesson_cost, lesson_duration, lessons_per_week, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $userId,
                    $city ?: null,
                    $startDate,
                    $plannedEndDate,
                    $goals ?: null,
                    $additionalInfo ?: null,
                    $lessonCost ?: null,
                    $lessonDuration,
                    $lessonsPerWeek
                ]);
                
                $studentId = $pdo->lastInsertId();
                
                // Связываем с учителем (если не админ)
                if ($currentUser['role_name'] !== 'admin') {
                    $stmt = $pdo->prepare("INSERT INTO teacher_student (teacher_id, student_id) VALUES (?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $studentId]);
                }
                
                // Добавляем представителей
                if (isset($_POST['representatives']) && is_array($_POST['representatives'])) {
                    foreach ($_POST['representatives'] as $rep) {
                        if (!empty($rep['full_name'])) {
                            $stmt = $pdo->prepare("
                                INSERT INTO representatives (student_id, full_name, relationship, phone, email, messenger_contact, is_primary)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $studentId,
                                $rep['full_name'],
                                $rep['relationship'] ?: null,
                                $rep['phone'] ?: null,
                                $rep['email'] ?: null,
                                $rep['messenger'] ?: null,
                                isset($rep['is_primary']) ? 1 : 0
                            ]);
                        }
                    }
                }
                
                // Добавляем комментарий о создании
                $stmt = $pdo->prepare("
                    INSERT INTO student_comments (student_id, user_id, comment, is_completed)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([
                    $studentId,
                    $_SESSION['user_id'],
                    'Ученик добавлен в систему'
                ]);
                
                $pdo->commit();
                $message = 'Ученик успешно добавлен';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при добавлении ученика: ' . $e->getMessage();
            }
            break;
            
        case 'edit_student':
            try {
                $studentId = $_POST['student_id'] ?? 0;
                
                $pdo->beginTransaction();
                
                // Получаем user_id ученика
                $stmt = $pdo->prepare("SELECT user_id FROM students WHERE id = ?");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch();
                $userId = $student['user_id'];
                
                // Обновляем пользователя
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $middleName = trim($_POST['middle_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $messenger1 = trim($_POST['messenger_1'] ?? '');
                $messenger2 = trim($_POST['messenger_2'] ?? '');
                $messenger3 = trim($_POST['messenger_3'] ?? '');
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, middle_name = ?, email = ?,
                        phone = ?, messenger_1 = ?, messenger_2 = ?, messenger_3 = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $firstName,
                    $lastName,
                    $middleName ?: null,
                    $email ?: null,
                    $phone ?: null,
                    $messenger1 ?: null,
                    $messenger2 ?: null,
                    $messenger3 ?: null,
                    $userId
                ]);
                
                // Обновляем ученика
                $city = trim($_POST['city'] ?? '');
                $startDate = $_POST['start_date'] ?: null;
                $plannedEndDate = $_POST['planned_end_date'] ?: null;
                $goals = trim($_POST['goals'] ?? '');
                $additionalInfo = trim($_POST['additional_info'] ?? '');
                $lessonCost = $_POST['lesson_cost'] ?: null;
                $lessonDuration = $_POST['lesson_duration'] ?: 60;
                $lessonsPerWeek = $_POST['lessons_per_week'] ?: 1;
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                // Сохраняем старые значения для истории
                $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                $stmt->execute([$studentId]);
                $oldData = $stmt->fetch();
                
                $stmt = $pdo->prepare("
                    UPDATE students 
                    SET city = ?, start_date = ?, planned_end_date = ?, goals = ?,
                        additional_info = ?, lesson_cost = ?, lesson_duration = ?,
                        lessons_per_week = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $city ?: null,
                    $startDate,
                    $plannedEndDate,
                    $goals ?: null,
                    $additionalInfo ?: null,
                    $lessonCost ?: null,
                    $lessonDuration,
                    $lessonsPerWeek,
                    $isActive,
                    $studentId
                ]);
                
                // Обновляем представителей (удаляем старых и добавляем новых)
                $stmt = $pdo->prepare("DELETE FROM representatives WHERE student_id = ?");
                $stmt->execute([$studentId]);
                
                if (isset($_POST['representatives']) && is_array($_POST['representatives'])) {
                    foreach ($_POST['representatives'] as $rep) {
                        if (!empty($rep['full_name'])) {
                            $stmt = $pdo->prepare("
                                INSERT INTO representatives (student_id, full_name, relationship, phone, email, messenger_contact, is_primary)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $studentId,
                                $rep['full_name'],
                                $rep['relationship'] ?: null,
                                $rep['phone'] ?: null,
                                $rep['email'] ?: null,
                                $rep['messenger'] ?: null,
                                isset($rep['is_primary']) ? 1 : 0
                            ]);
                        }
                    }
                }
                
                // Добавляем запись в историю изменений
                $stmt = $pdo->prepare("
                    INSERT INTO student_history (student_id, changed_by, field_name, old_value, new_value)
                    VALUES (?, ?, 'profile_update', 'Изменение профиля', 'Данные обновлены')
                ");
                $stmt->execute([$studentId, $_SESSION['user_id']]);
                
                $pdo->commit();
                $message = 'Данные ученика успешно обновлены';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при обновлении данных: ' . $e->getMessage();
            }
            break;
            
        case 'add_comment':
            $studentId = $_POST['student_id'] ?? 0;
            $comment = trim($_POST['comment'] ?? '');
            
            if ($studentId && $comment) {
                $stmt = $pdo->prepare("
                    INSERT INTO student_comments (student_id, user_id, comment)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$studentId, $_SESSION['user_id'], $comment]);
                $message = 'Комментарий добавлен';
            }
            break;
            
        case 'toggle_comment':
            $commentId = $_POST['comment_id'] ?? 0;
            $isCompleted = $_POST['is_completed'] ?? 0;
            
            $stmt = $pdo->prepare("UPDATE student_comments SET is_completed = ? WHERE id = ?");
            $stmt->execute([$isCompleted, $commentId]);
            break;
            
        case 'delete_comment':
            $commentId = $_POST['comment_id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM student_comments WHERE id = ?");
            $stmt->execute([$commentId]);
            break;
            
        case 'toggle_student':
            $studentId = $_POST['student_id'] ?? 0;
            $isActive = $_POST['is_active'] ?? 0;
            
            $stmt = $pdo->prepare("UPDATE students SET is_active = ? WHERE id = ?");
            $stmt->execute([$isActive, $studentId]);
            break;
    }
}

// Получение списка учеников
if ($currentUser['role_name'] === 'admin') {
    $stmt = $pdo->query("
        SELECT s.*, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
               u.messenger_1, u.messenger_2, u.messenger_3,
               (SELECT COUNT(*) FROM lessons l 
                JOIN lesson_plans lp ON l.lesson_plan_id = lp.id 
                WHERE lp.student_id = s.id AND l.is_completed = 1) as lessons_count,
               (SELECT COUNT(*) FROM student_comments WHERE student_id = s.id) as comments_count
        FROM students s
        JOIN users u ON s.user_id = u.id
        ORDER BY s.is_active DESC, u.last_name, u.first_name
    ");
    $students = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
               u.messenger_1, u.messenger_2, u.messenger_3,
               (SELECT COUNT(*) FROM lessons l 
                JOIN lesson_plans lp ON l.lesson_plan_id = lp.id 
                WHERE lp.student_id = s.id AND l.is_completed = 1) as lessons_count,
               (SELECT COUNT(*) FROM student_comments WHERE student_id = s.id) as comments_count
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN teacher_student ts ON s.id = ts.student_id
        WHERE ts.teacher_id = ?
        ORDER BY s.is_active DESC, u.last_name, u.first_name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $students = $stmt->fetchAll();
}

// Получение данных для редактирования
$editStudent = null;
$editRepresentatives = [];
$editComments = [];

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
               u.messenger_1, u.messenger_2, u.messenger_3
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$_GET['edit']]);
    $editStudent = $stmt->fetch();
    
    if ($editStudent) {
        // Получаем представителей
        $stmt = $pdo->prepare("SELECT * FROM representatives WHERE student_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$_GET['edit']]);
        $editRepresentatives = $stmt->fetchAll();
        
        // Получаем комментарии
        $stmt = $pdo->prepare("
            SELECT c.*, u.first_name, u.last_name 
            FROM student_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.student_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$_GET['edit']]);
        $editComments = $stmt->fetchAll();
    }
}

// Получение данных для просмотра
$viewStudent = null;
$viewRepresentatives = [];
$viewComments = [];
$viewHistory = [];

if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
               u.messenger_1, u.messenger_2, u.messenger_3,
               u.created_at as user_created_at
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$_GET['view']]);
    $viewStudent = $stmt->fetch();
    
    if ($viewStudent) {
        // Получаем представителей
        $stmt = $pdo->prepare("SELECT * FROM representatives WHERE student_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$_GET['view']]);
        $viewRepresentatives = $stmt->fetchAll();
        
        // Получаем комментарии
        $stmt = $pdo->prepare("
            SELECT c.*, u.first_name, u.last_name 
            FROM student_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.student_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$_GET['view']]);
        $viewComments = $stmt->fetchAll();
        
        // Получаем историю изменений
        $stmt = $pdo->prepare("
            SELECT h.*, u.first_name, u.last_name 
            FROM student_history h
            JOIN users u ON h.changed_by = u.id
            WHERE h.student_id = ?
            ORDER BY h.changed_at DESC
            LIMIT 20
        ");
        $stmt->execute([$_GET['view']]);
        $viewHistory = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ученики и родители - Репетитор 2029</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar-brand, .nav-link {
            color: white !important;
        }
        .page-header {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .student-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
            margin-bottom: 20px;
            border-left: 4px solid transparent;
        }
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .student-card.active {
            border-left-color: #28a745;
        }
        .student-card.inactive {
            border-left-color: #dc3545;
            opacity: 0.8;
        }
        .student-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }
        .btn-action {
            padding: 5px 10px;
            margin: 0 2px;
            border-radius: 8px;
        }
        .modal-content {
            border-radius: 15px;
            border: none;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .stats-badge {
            background: #f8f9fa;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 14px;
        }
        .comment-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid #667eea;
        }
        .comment-item.completed {
            border-left-color: #28a745;
            opacity: 0.8;
        }
        .representative-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .history-item {
            font-size: 14px;
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
    </style>
</head>
<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-calendar-check"></i> Репетитор 2029
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="bi bi-person"></i> Профиль
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Основной контент -->
    <div class="container-fluid p-4">
        <!-- Заголовок -->
        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-0"><i class="bi bi-people me-2"></i>Ученики и родители</h2>
                <p class="text-muted mb-0">Управление учениками и их представителями</p>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="bi bi-plus-circle"></i> Добавить ученика
            </button>
        </div>

        <!-- Сообщения -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
            </div>
        <?php endif; ?>

        <!-- Список учеников -->
        <div class="row">
            <?php foreach ($students as $student): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="student-card <?php echo $student['is_active'] ? 'active' : 'inactive'; ?>">
                        <div class="d-flex align-items-center mb-3">
                            <div class="student-avatar me-3">
                                <?php echo strtoupper(substr($student['first_name'] ?? '?', 0, 1) . substr($student['last_name'] ?? '?', 0, 1)); ?>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-1">
                                    <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? '')); ?>
                                </h5>
                                <div class="stats-badge">
                                    <i class="bi bi-calendar-check me-1"></i> <?php echo $student['lessons_count']; ?> уроков
                                    <span class="mx-2">|</span>
                                    <i class="bi bi-chat me-1"></i> <?php echo $student['comments_count']; ?> комм.
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-link text-dark" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="?view=<?php echo $student['id']; ?>">
                                            <i class="bi bi-eye"></i> Просмотр
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="?edit=<?php echo $student['id']; ?>">
                                            <i class="bi bi-pencil"></i> Редактировать
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="toggleStudent(<?php echo $student['id']; ?>, <?php echo $student['is_active'] ? 0 : 1; ?>)">
                                            <i class="bi bi-power"></i> 
                                            <?php echo $student['is_active'] ? 'Деактивировать' : 'Активировать'; ?>
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item" href="plans.php?student_id=<?php echo $student['id']; ?>">
                                            <i class="bi bi-calendar-plus"></i> Планы обучения
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="row g-2 mb-2">
                            <?php if ($student['email']): ?>
                                <div class="col-12">
                                    <small><i class="bi bi-envelope me-1 text-muted"></i> <?php echo htmlspecialchars($student['email']); ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if ($student['phone']): ?>
                                <div class="col-12">
                                    <small><i class="bi bi-telephone me-1 text-muted"></i> <?php echo htmlspecialchars($student['phone']); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="badge bg-light text-dark">
                                <i class="bi bi-currency-ruble"></i> <?php echo number_format($student['lesson_cost'] ?? 0, 0, '', ' '); ?> ₽
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="bi bi-clock"></i> <?php echo $student['lesson_duration']; ?> мин
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="bi bi-calendar-week"></i> <?php echo $student['lessons_per_week']; ?>/нед
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($students)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-people display-1 text-muted"></i>
                        <h4 class="mt-3">Нет учеников</h4>
                        <p class="text-muted">Нажмите "Добавить ученика", чтобы создать первую запись</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно добавления/редактирования -->
    <div class="modal fade" id="studentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Добавление ученика</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="studentForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add_student">
                        <input type="hidden" name="student_id" id="studentId" value="">
                        
                        <!-- Основная информация -->
                        <h6 class="mb-3">Основная информация</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Фамилия <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="last_name" id="lastName" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Имя <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="first_name" id="firstName" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Отчество</label>
                                <input type="text" class="form-control" name="middle_name" id="middleName">
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Телефон</label>
                                <input type="tel" class="form-control" name="phone" id="phone">
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Мессенджер 1</label>
                                <input type="text" class="form-control" name="messenger_1" id="messenger1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Мессенджер 2</label>
                                <input type="text" class="form-control" name="messenger_2" id="messenger2">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Мессенджер 3</label>
                                <input type="text" class="form-control" name="messenger_3" id="messenger3">
                            </div>
                        </div>
                        
                        <!-- Информация о занятиях -->
                        <h6 class="mb-3 mt-4">Информация о занятиях</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Стоимость занятия (₽)</label>
                                <input type="number" class="form-control" name="lesson_cost" id="lessonCost" step="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Длительность (мин)</label>
                                <input type="number" class="form-control" name="lesson_duration" id="lessonDuration" value="60" min="15" step="15">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Занятий в неделю</label>
                                <input type="number" class="form-control" name="lessons_per_week" id="lessonsPerWeek" value="1" min="1" max="7">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Город</label>
                                <input type="text" class="form-control" name="city" id="city">
                            </div>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Дата начала занятий</label>
                                <input type="date" class="form-control" name="start_date" id="startDate">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Планируемая дата окончания</label>
                                <input type="date" class="form-control" name="planned_end_date" id="plannedEndDate">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Цели обучения</label>
                            <textarea class="form-control" name="goals" id="goals" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Дополнительная информация</label>
                            <textarea class="form-control" name="additional_info" id="additionalInfo" rows="2"></textarea>
                        </div>
                        
                        <!-- Представители -->
                        <h6 class="mb-3 mt-4">Представители (до 3-х)</h6>
                        <div id="representatives-container">
                            <!-- Шаблон будет заполняться JavaScript -->
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRepresentative()">
                            <i class="bi bi-plus"></i> Добавить представителя
                        </button>
                        
                        <!-- Статус (только для редактирования) -->
                        <div class="mt-4" id="statusField" style="display: none;">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" checked>
                                <label class="form-check-label" for="isActive">Активный ученик</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно просмотра -->
    <?php if ($viewStudent): ?>
    <div class="modal fade" id="viewModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-circle me-2"></i>
                        <?php echo htmlspecialchars($viewStudent['last_name'] . ' ' . $viewStudent['first_name'] . ' ' . ($viewStudent['middle_name'] ?? '')); ?>
                    </h5>
                    <a href="students.php" class="btn-close btn-close-white"></a>
                </div>
                <div class="modal-body">
                    <!-- Навигация по вкладкам -->
                    <ul class="nav nav-tabs mb-4" id="viewTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                                <i class="bi bi-info-circle"></i> Информация
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="representatives-tab" data-bs-toggle="tab" data-bs-target="#representatives" type="button" role="tab">
                                <i class="bi bi-people"></i> Представители
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments" type="button" role="tab">
                                <i class="bi bi-chat"></i> Комментарии
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                                <i class="bi bi-clock-history"></i> История изменений
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="viewTabsContent">
                        <!-- Информация об ученике -->
                        <div class="tab-pane fade show active" id="info" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Контактная информация</h6>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-sm">
                                                <tr>
                                                    <th>Email:</th>
                                                    <td><?php echo htmlspecialchars($viewStudent['email'] ?: 'Не указан'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Телефон:</th>
                                                    <td><?php echo htmlspecialchars($viewStudent['phone'] ?: 'Не указан'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Мессенджер 1:</th>
                                                    <td><?php echo htmlspecialchars($viewStudent['messenger_1'] ?: 'Не указан'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Мессенджер 2:</th>
                                                    <td><?php echo htmlspecialchars($viewStudent['messenger_2'] ?: 'Не указан'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Мессенджер 3:</th>
                                                    <td><?php echo htmlspecialchars($viewStudent['messenger_3'] ?: 'Не указан'); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Информация о занятиях</h6>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-sm">
                                                <tr>
                                                    <th>Стоимость занятия:</th>
                                                    <td><?php echo $viewStudent['lesson_cost'] ? number_format($viewStudent['lesson_cost'], 0, '', ' ') . ' ₽' : 'Не указана'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Длительность:</th>
                                                    <td><?php echo $viewStudent['lesson_duration']; ?> минут</td>
                                                </tr>
                                                <tr>
                                                    <th>Занятий в неделю:</th>
                                                    <td><?php echo $viewStudent['lessons_per_week']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Город:</th>
                                                    <td><?php echo htmlspecialchars($viewStudent['city'] ?: 'Не указан'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Дата начала:</th>
                                                    <td><?php echo $viewStudent['start_date'] ? date('d.m.Y', strtotime($viewStudent['start_date'])) : 'Не указана'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Планируемая дата окончания:</th>
                                                    <td><?php echo $viewStudent['planned_end_date'] ? date('d.m.Y', strtotime($viewStudent['planned_end_date'])) : 'Не указана'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Статус:</th>
                                                    <td>
                                                        <span class="badge bg-<?php echo $viewStudent['is_active'] ? 'success' : 'secondary'; ?>">
                                                            <?php echo $viewStudent['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <?php if ($viewStudent['goals']): ?>
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Цели обучения</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php echo nl2br(htmlspecialchars($viewStudent['goals'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($viewStudent['additional_info']): ?>
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Дополнительная информация</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php echo nl2br(htmlspecialchars($viewStudent['additional_info'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Представители -->
                        <div class="tab-pane fade" id="representatives" role="tabpanel">
                            <?php if (empty($viewRepresentatives)): ?>
                                <p class="text-muted text-center py-4">Нет представителей</p>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($viewRepresentatives as $rep): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <?php echo htmlspecialchars($rep['full_name']); ?>
                                                        <?php if ($rep['is_primary']): ?>
                                                            <span class="badge bg-primary ms-2">Основной</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <p class="card-text">
                                                        <?php if ($rep['relationship']): ?>
                                                            <small class="text-muted d-block"><?php echo htmlspecialchars($rep['relationship']); ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($rep['phone']): ?>
                                                            <small class="d-block"><i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($rep['phone']); ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($rep['email']): ?>
                                                            <small class="d-block"><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($rep['email']); ?></small>
                                                        <?php endif; ?>
                                                        <?php if ($rep['messenger_contact']): ?>
                                                            <small class="d-block"><i class="bi bi-chat me-1"></i> <?php echo htmlspecialchars($rep['messenger_contact']); ?></small>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Комментарии -->
                        <div class="tab-pane fade" id="comments" role="tabpanel">
                            <form method="POST" action="" class="mb-4">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="student_id" value="<?php echo $viewStudent['id']; ?>">
                                <div class="input-group">
                                    <textarea class="form-control" name="comment" rows="2" placeholder="Введите комментарий..." required></textarea>
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-send"></i> Добавить
                                    </button>
                                </div>
                            </form>
                            
                            <?php if (empty($viewComments)): ?>
                                <p class="text-muted text-center py-4">Нет комментариев</p>
                            <?php else: ?>
                                <?php foreach ($viewComments as $comment): ?>
                                    <div class="comment-item <?php echo $comment['is_completed'] ? 'completed' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></strong>
                                                <small class="text-muted ms-2"><?php echo date('d.m.Y H:i', strtotime($comment['created_at'])); ?></small>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-success btn-action" onclick="toggleComment(<?php echo $comment['id']; ?>, <?php echo $comment['is_completed'] ? 0 : 1; ?>)">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteComment(<?php echo $comment['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                        <?php if ($comment['is_completed']): ?>
                                            <small class="text-success mt-2 d-block">
                                                <i class="bi bi-check-circle"></i> Выполнено
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- История изменений -->
                        <div class="tab-pane fade" id="history" role="tabpanel">
                            <?php if (empty($viewHistory)): ?>
                                <p class="text-muted text-center py-4">Нет записей в истории</p>
                            <?php else: ?>
                                <?php foreach ($viewHistory as $history): ?>
                                    <div class="history-item">
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">
                                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($history['first_name'] . ' ' . $history['last_name']); ?>
                                            </small>
                                            <small class="text-muted">
                                                <i class="bi bi-clock"></i> <?php echo date('d.m.Y H:i', strtotime($history['changed_at'])); ?>
                                            </small>
                                        </div>
                                        <p class="mb-0 mt-1">
                                            <?php echo htmlspecialchars($history['field_name'] . ': ' . $history['new_value']); ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="?edit=<?php echo $viewStudent['id']; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Редактировать
                    </a>
                    <a href="plans.php?student_id=<?php echo $viewStudent['id']; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-calendar-plus"></i> Планы обучения
                    </a>
                    <a href="students.php" class="btn btn-secondary">Закрыть</a>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
            viewModal.show();
        });
    </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Глобальные переменные
        let representativeCount = 0;
        let studentModal = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            studentModal = new bootstrap.Modal(document.getElementById('studentModal'));
            
            <?php if ($editStudent): ?>
            openEditModal(<?php echo json_encode([
                'id' => $editStudent['id'],
                'first_name' => $editStudent['first_name'],
                'last_name' => $editStudent['last_name'],
                'middle_name' => $editStudent['middle_name'],
                'email' => $editStudent['email'],
                'phone' => $editStudent['phone'],
                'messenger_1' => $editStudent['messenger_1'],
                'messenger_2' => $editStudent['messenger_2'],
                'messenger_3' => $editStudent['messenger_3'],
                'city' => $editStudent['city'],
                'start_date' => $editStudent['start_date'],
                'planned_end_date' => $editStudent['planned_end_date'],
                'goals' => $editStudent['goals'],
                'additional_info' => $editStudent['additional_info'],
                'lesson_cost' => $editStudent['lesson_cost'],
                'lesson_duration' => $editStudent['lesson_duration'],
                'lessons_per_week' => $editStudent['lessons_per_week'],
                'is_active' => $editStudent['is_active']
            ]); ?>);
            <?php endif; ?>
        });
        
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Добавление ученика';
            document.getElementById('formAction').value = 'add_student';
            document.getElementById('studentId').value = '';
            
            // Очищаем форму
            document.getElementById('firstName').value = '';
            document.getElementById('lastName').value = '';
            document.getElementById('middleName').value = '';
            document.getElementById('email').value = '';
            document.getElementById('phone').value = '';
            document.getElementById('messenger1').value = '';
            document.getElementById('messenger2').value = '';
            document.getElementById('messenger3').value = '';
            document.getElementById('city').value = '';
            document.getElementById('startDate').value = '';
            document.getElementById('plannedEndDate').value = '';
            document.getElementById('goals').value = '';
            document.getElementById('additionalInfo').value = '';
            document.getElementById('lessonCost').value = '';
            document.getElementById('lessonDuration').value = '60';
            document.getElementById('lessonsPerWeek').value = '1';
            
            // Очищаем представителей
            document.getElementById('representatives-container').innerHTML = '';
            representativeCount = 0;
            
            // Скрываем поле статуса
            document.getElementById('statusField').style.display = 'none';
            
            studentModal.show();
        }
        
        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Редактирование ученика';
            document.getElementById('formAction').value = 'edit_student';
            document.getElementById('studentId').value = data.id;
            
            // Заполняем форму
            document.getElementById('firstName').value = data.first_name || '';
            document.getElementById('lastName').value = data.last_name || '';
            document.getElementById('middleName').value = data.middle_name || '';
            document.getElementById('email').value = data.email || '';
            document.getElementById('phone').value = data.phone || '';
            document.getElementById('messenger1').value = data.messenger_1 || '';
            document.getElementById('messenger2').value = data.messenger_2 || '';
            document.getElementById('messenger3').value = data.messenger_3 || '';
            document.getElementById('city').value = data.city || '';
            document.getElementById('startDate').value = data.start_date || '';
            document.getElementById('plannedEndDate').value = data.planned_end_date || '';
            document.getElementById('goals').value = data.goals || '';
            document.getElementById('additionalInfo').value = data.additional_info || '';
            document.getElementById('lessonCost').value = data.lesson_cost || '';
            document.getElementById('lessonDuration').value = data.lesson_duration || '60';
            document.getElementById('lessonsPerWeek').value = data.lessons_per_week || '1';
            
            // Загружаем представителей
            document.getElementById('representatives-container').innerHTML = '';
            representativeCount = 0;
            
            <?php foreach ($editRepresentatives as $rep): ?>
            addRepresentative(<?php echo json_encode($rep); ?>);
            <?php endforeach; ?>
            
            // Показываем и устанавливаем статус
            document.getElementById('statusField').style.display = 'block';
            document.getElementById('isActive').checked = data.is_active == 1;
            
            studentModal.show();
        }
        
        function addRepresentative(data = null) {
            if (representativeCount >= 3) {
                alert('Можно добавить не более 3 представителей');
                return;
            }
            
            representativeCount++;
            const container = document.getElementById('representatives-container');
            const div = document.createElement('div');
            div.className = 'representative-item mb-3 p-3 border rounded';
            div.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Представитель ${representativeCount}</h6>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.representative-item').remove(); representativeCount--;">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="representatives[${representativeCount}][full_name]" 
                               placeholder="ФИО" value="${data ? (data.full_name || '') : ''}">
                    </div>
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="representatives[${representativeCount}][relationship]" 
                               placeholder="Родство" value="${data ? (data.relationship || '') : ''}">
                    </div>
                    <div class="col-md-6">
                        <input type="tel" class="form-control" name="representatives[${representativeCount}][phone]" 
                               placeholder="Телефон" value="${data ? (data.phone || '') : ''}">
                    </div>
                    <div class="col-md-6">
                        <input type="email" class="form-control" name="representatives[${representativeCount}][email]" 
                               placeholder="Email" value="${data ? (data.email || '') : ''}">
                    </div>
                    <div class="col-md-8">
                        <input type="text" class="form-control" name="representatives[${representativeCount}][messenger]" 
                               placeholder="Контакт в мессенджере" value="${data ? (data.messenger_contact || '') : ''}">
                    </div>
                    <div class="col-md-4">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="representatives[${representativeCount}][is_primary]" 
                                   id="primary_${representativeCount}" ${data && data.is_primary ? 'checked' : ''}>
                            <label class="form-check-label" for="primary_${representativeCount}">
                                Основной представитель
                            </label>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }
        
        function toggleStudent(studentId, isActive) {
            if (confirm('Вы уверены?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_student">
                    <input type="hidden" name="student_id" value="${studentId}">
                    <input type="hidden" name="is_active" value="${isActive}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleComment(commentId, isCompleted) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="toggle_comment">
                <input type="hidden" name="comment_id" value="${commentId}">
                <input type="hidden" name="is_completed" value="${isCompleted}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        function deleteComment(commentId) {
            if (confirm('Удалить комментарий?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_comment">
                    <input type="hidden" name="comment_id" value="${commentId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>