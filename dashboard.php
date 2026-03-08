<?php
// dashboard.php
require_once 'config.php';

// Проверка авторизации
if (!isAuthenticated()) {
    redirect('index.php');
}

$currentUser = getCurrentUser($pdo);
$selectedStudentId = $_GET['student_id'] ?? null;

// Получаем общую статистику
$stats = [];

// Количество учеников (для учителя - только своих, для админа - всех)
if (hasRole('admin')) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM students WHERE is_active = 1");
    $stats['total_students'] = $stmt->fetch()['count'];
} else {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) as count 
        FROM students s
        JOIN teacher_student ts ON s.id = ts.student_id
        WHERE ts.teacher_id = ? AND s.is_active = 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $stats['total_students'] = $stmt->fetch()['count'];
}

// Количество проведенных уроков
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM lessons l
    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
    WHERE lp.teacher_id = ? AND l.is_completed = 1 AND l.date <= CURDATE()
");
$stmt->execute([$_SESSION['user_id']]);
$stats['completed_lessons'] = $stmt->fetch()['count'];

// Количество часов в неделю
$stmt = $pdo->prepare("
    SELECT SUM(s.lesson_duration) / 60 as total_hours
    FROM students s
    JOIN teacher_student ts ON s.id = ts.student_id
    WHERE ts.teacher_id = ? AND s.is_active = 1
");
$stmt->execute([$_SESSION['user_id']]);
$stats['weekly_hours'] = $stmt->fetch()['total_hours'] ?? 0;

// Количество уроков сегодня
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM lessons l
    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
    WHERE lp.teacher_id = ? AND l.date = CURDATE()
");
$stmt->execute([$_SESSION['user_id']]);
$stats['today_lessons'] = $stmt->fetch()['count'];

// Ближайший урок
$stmt = $pdo->prepare("
    SELECT l.*, lp.name as plan_name, s.id as student_id, 
           u.first_name, u.last_name,
           GROUP_CONCAT(t.name SEPARATOR ', ') as topics
    FROM lessons l
    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
    LEFT JOIN students s ON lp.student_id = s.id
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN lesson_topics lt ON l.id = lt.lesson_id
    LEFT JOIN topics t ON lt.topic_id = t.id
    WHERE lp.teacher_id = ? AND l.date >= CURDATE()
    GROUP BY l.id
    ORDER BY l.date ASC, l.time ASC
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$nextLesson = $stmt->fetch();

// Получаем список учеников для выпадающего списка
if (hasRole('admin')) {
    $stmt = $pdo->query("
        SELECT s.*, u.first_name, u.last_name, u.email, u.phone
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.is_active = 1
        ORDER BY u.last_name, u.first_name
    ");
    $students = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.email, u.phone
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN teacher_student ts ON s.id = ts.student_id
        WHERE ts.teacher_id = ? AND s.is_active = 1
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $students = $stmt->fetchAll();
}

// Получаем статистику по выбранному ученику
$studentStats = null;
$representatives = [];
$lastHomework = null;
$nextStudentLesson = null;

if ($selectedStudentId) {
    // Информация об ученике
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
               u.messenger_1, u.messenger_2, u.messenger_3
        FROM students s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$selectedStudentId]);
    $studentStats = $stmt->fetch();
    
    // Представители
    $stmt = $pdo->prepare("
        SELECT * FROM representatives 
        WHERE student_id = ?
        ORDER BY is_primary DESC
    ");
    $stmt->execute([$selectedStudentId]);
    $representatives = $stmt->fetchAll();
    
    // Последнее домашнее задание
    $stmt = $pdo->prepare("
        SELECT l.date, l.homework_comment, t.title as task_title
        FROM lessons l
        JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
        LEFT JOIN lesson_homework lh ON l.id = lh.lesson_id
        LEFT JOIN tasks t ON lh.task_id = t.id
        WHERE lp.student_id = ? AND l.homework_comment IS NOT NULL
        ORDER BY l.date DESC
        LIMIT 1
    ");
    $stmt->execute([$selectedStudentId]);
    $lastHomework = $stmt->fetch();
    
    // Ближайший урок ученика
    $stmt = $pdo->prepare("
        SELECT l.*, lp.name as plan_name,
               GROUP_CONCAT(t.name SEPARATOR ', ') as topics
        FROM lessons l
        JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
        LEFT JOIN lesson_topics lt ON l.id = lt.lesson_id
        LEFT JOIN topics t ON lt.topic_id = t.id
        WHERE lp.student_id = ? AND l.date >= CURDATE()
        GROUP BY l.id
        ORDER BY l.date ASC, l.time ASC
        LIMIT 1
    ");
    $stmt->execute([$selectedStudentId]);
    $nextStudentLesson = $stmt->fetch();
}

// Модули для отображения в зависимости от роли
$modules = [
    'students' => ['name' => 'Ученики', 'icon' => '👥', 'file' => 'students.php', 'roles' => ['admin', 'teacher']],
    'topics' => ['name' => 'Банк тем', 'icon' => '📚', 'file' => 'topics.php', 'roles' => ['admin', 'teacher']],
    'tags' => ['name' => 'Банк меток', 'icon' => '🏷️', 'file' => 'tags.php', 'roles' => ['admin', 'teacher']],
    'tasks' => ['name' => 'Банк заданий', 'icon' => '📝', 'file' => 'tasks.php', 'roles' => ['admin', 'teacher']],
    'plans' => ['name' => 'Планы обучения', 'icon' => '📅', 'file' => 'plans.php', 'roles' => ['admin', 'teacher']],
    'diary' => ['name' => 'Дневник ученика', 'icon' => '📓', 'file' => 'diary.php', 'roles' => ['admin', 'teacher', 'student']],
    'admin' => ['name' => 'Администрирование', 'icon' => '⚙️', 'file' => 'admin.php', 'roles' => ['admin']]
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд - Репетитор 2029</title>
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
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        .module-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .module-icon {
            font-size: 40px;
            margin-bottom: 15px;
        }
        .module-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        .student-info-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        .info-label {
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-weight: 600;
            color: #333;
        }
        .next-lesson-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 12px;
        }
        .student-selector {
            background: white;
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            padding: 10px 15px;
            width: 100%;
            margin-bottom: 20px;
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
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="user-avatar d-inline-block me-2">
                                <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1) . substr($currentUser['last_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Профиль</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Выход</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Основной контент -->
    <div class="container-fluid p-4">
        <!-- Статистика -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e3f2fd; color: #1976d2;">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Активных учеников</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e8f5e9; color: #388e3c;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['completed_lessons']; ?></div>
                    <div class="stat-label">Проведено уроков</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff3e0; color: #f57c00;">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['weekly_hours'], 1); ?></div>
                    <div class="stat-label">Часов в неделю</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fce4ec; color: #c2185b;">
                        <i class="bi bi-calendar-day"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['today_lessons']; ?></div>
                    <div class="stat-label">Уроков сегодня</div>
                </div>
            </div>
        </div>

        <!-- Ближайший урок -->
        <?php if ($nextLesson): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="next-lesson-badge">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-star-fill me-3" style="font-size: 24px;"></i>
                        <div>
                            <small>БЛИЖАЙШИЙ УРОК</small>
                            <h5 class="mb-1">
                                <?php echo date('d.m.Y', strtotime($nextLesson['date'])); ?> 
                                <?php if ($nextLesson['time']): ?>в <?php echo substr($nextLesson['time'], 0, 5); ?><?php endif; ?>
                            </h5>
                            <p class="mb-0">
                                <?php echo htmlspecialchars($nextLesson['first_name'] . ' ' . $nextLesson['last_name']); ?> - 
                                <?php echo htmlspecialchars($nextLesson['topics'] ?: 'Без темы'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Выбор ученика -->
        <div class="row mb-4">
            <div class="col-md-6">
                <select class="student-selector" onchange="window.location.href='?student_id='+this.value">
                    <option value="">Выберите ученика для просмотра детальной статистики</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>" <?php echo $selectedStudentId == $student['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Детальная информация об ученике -->
        <?php if ($selectedStudentId && $studentStats): ?>
        <div class="row">
            <div class="col-md-4">
                <div class="student-info-card">
                    <h5 class="mb-3">
                        <i class="bi bi-person-circle me-2"></i>
                        <?php echo htmlspecialchars($studentStats['last_name'] . ' ' . $studentStats['first_name'] . ' ' . ($studentStats['middle_name'] ?? '')); ?>
                    </h5>
                    
                    <div class="mb-3">
                        <div class="info-label">Контактная информация</div>
                        <div class="info-value">
                            <?php if ($studentStats['phone']): ?>
                                <div><i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($studentStats['phone']); ?></div>
                            <?php endif; ?>
                            <?php if ($studentStats['email']): ?>
                                <div><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($studentStats['email']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="info-label">Мессенджеры</div>
                        <div class="info-value">
                            <?php if ($studentStats['messenger_1']): ?>
                                <div><?php echo htmlspecialchars($studentStats['messenger_1']); ?></div>
                            <?php endif; ?>
                            <?php if ($studentStats['messenger_2']): ?>
                                <div><?php echo htmlspecialchars($studentStats['messenger_2']); ?></div>
                            <?php endif; ?>
                            <?php if ($studentStats['messenger_3']): ?>
                                <div><?php echo htmlspecialchars($studentStats['messenger_3']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="info-label">Занятия</div>
                        <div class="info-value">
                            <div>💰 <?php echo number_format($studentStats['lesson_cost'], 0, '', ' '); ?> ₽ / занятие</div>
                            <div>⏱️ <?php echo $studentStats['lesson_duration']; ?> минут</div>
                            <div>📅 <?php echo $studentStats['lessons_per_week']; ?> раз(а) в неделю</div>
                        </div>
                    </div>
                    
                    <?php if ($studentStats['goals']): ?>
                    <div class="mb-3">
                        <div class="info-label">Цели обучения</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($studentStats['goals'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="student-info-card">
                    <h5 class="mb-3">
                        <i class="bi bi-people me-2"></i>
                        Представители
                    </h5>
                    
                    <?php if (empty($representatives)): ?>
                        <p class="text-muted">Нет представителей</p>
                    <?php else: ?>
                        <?php foreach ($representatives as $rep): ?>
                            <div class="mb-3 <?php echo $rep['is_primary'] ? 'border-start border-primary ps-2' : ''; ?>">
                                <div class="fw-bold"><?php echo htmlspecialchars($rep['full_name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($rep['relationship']); ?></div>
                                <?php if ($rep['phone']): ?>
                                    <div><i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($rep['phone']); ?></div>
                                <?php endif; ?>
                                <?php if ($rep['email']): ?>
                                    <div><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($rep['email']); ?></div>
                                <?php endif; ?>
                                <?php if ($rep['messenger_contact']): ?>
                                    <div><i class="bi bi-chat me-1"></i> <?php echo htmlspecialchars($rep['messenger_contact']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="student-info-card">
                    <h5 class="mb-3">
                        <i class="bi bi-calendar-check me-2"></i>
                        Ближайшие события
                    </h5>
                    
                    <?php if ($nextStudentLesson): ?>
                        <div class="mb-3">
                            <div class="info-label">Ближайший урок</div>
                            <div class="info-value">
                                <div class="fw-bold"><?php echo date('d.m.Y', strtotime($nextStudentLesson['date'])); ?></div>
                                <div><?php echo htmlspecialchars($nextStudentLesson['plan_name']); ?></div>
                                <div class="text-primary"><?php echo htmlspecialchars($nextStudentLesson['topics'] ?: 'Без темы'); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($lastHomework): ?>
                        <div class="mb-3">
                            <div class="info-label">Последнее домашнее задание</div>
                            <div class="info-value">
                                <div class="small text-muted"><?php echo date('d.m.Y', strtotime($lastHomework['date'])); ?></div>
                                <?php if ($lastHomework['task_title']): ?>
                                    <div class="fw-bold"><?php echo htmlspecialchars($lastHomework['task_title']); ?></div>
                                <?php endif; ?>
                                <?php if ($lastHomework['homework_comment']): ?>
                                    <div><?php echo nl2br(htmlspecialchars($lastHomework['homework_comment'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <a href="plans.php?student_id=<?php echo $selectedStudentId; ?>" class="btn btn-primary w-100">
                        <i class="bi bi-pencil-square me-2"></i>Перейти к планированию
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Модули -->
        <div class="row mt-4">
            <div class="col-12">
                <h4 class="mb-3">Модули системы</h4>
            </div>
            <?php foreach ($modules as $key => $module): ?>
                <?php if (in_array($currentUser['role_name'], $module['roles'])): ?>
                    <div class="col-md-3 mb-3">
                        <a href="<?php echo $module['file']; ?>" class="module-card">
                            <div class="module-icon"><?php echo $module['icon']; ?></div>
                            <div class="module-name"><?php echo $module['name']; ?></div>
                            <small class="text-muted">Перейти к модулю</small>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>