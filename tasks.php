<?php
// tasks.php
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

// Добавление задания
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_task':
            try {
                $pdo->beginTransaction();
                
                $title = trim($_POST['title'] ?? '');
                $topicId = $_POST['topic_id'] ?: null;
                $description = trim($_POST['description'] ?? '');
                $difficulty = $_POST['difficulty'] ?? null;
                $externalLink = trim($_POST['external_link'] ?? '');
                
                if (empty($title)) {
                    throw new Exception('Название задания обязательно');
                }
                
                // Добавляем задание
                $stmt = $pdo->prepare("
                    INSERT INTO tasks (teacher_id, title, topic_id, description, difficulty, external_link)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $topicId,
                    $description ?: null,
                    $difficulty !== '' ? $difficulty : null,
                    $externalLink ?: null
                ]);
                
                $taskId = $pdo->lastInsertId();
                
                // Добавляем метки
                if (isset($_POST['tags']) && is_array($_POST['tags'])) {
                    $stmt = $pdo->prepare("INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)");
                    foreach ($_POST['tags'] as $tagId) {
                        if (!empty($tagId)) {
                            $stmt->execute([$taskId, $tagId]);
                        }
                    }
                }
                
                // Добавляем начальный комментарий
                if (!empty($_POST['initial_comment'])) {
                    $stmt = $pdo->prepare("
                        INSERT INTO task_comments (task_id, user_id, comment)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$taskId, $_SESSION['user_id'], trim($_POST['initial_comment'])]);
                }
                
                $pdo->commit();
                $message = 'Задание успешно добавлено';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при добавлении задания: ' . $e->getMessage();
            }
            break;
            
        case 'edit_task':
            try {
                $taskId = $_POST['task_id'] ?? 0;
                
                // Проверяем принадлежность задания
                $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$taskId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Задание не найдено');
                }
                
                $pdo->beginTransaction();
                
                $title = trim($_POST['title'] ?? '');
                $topicId = $_POST['topic_id'] ?: null;
                $description = trim($_POST['description'] ?? '');
                $difficulty = $_POST['difficulty'] ?? null;
                $externalLink = trim($_POST['external_link'] ?? '');
                
                if (empty($title)) {
                    throw new Exception('Название задания обязательно');
                }
                
                // Обновляем задание
                $stmt = $pdo->prepare("
                    UPDATE tasks 
                    SET title = ?, topic_id = ?, description = ?, difficulty = ?, external_link = ?
                    WHERE id = ? AND teacher_id = ?
                ");
                $stmt->execute([
                    $title,
                    $topicId,
                    $description ?: null,
                    $difficulty !== '' ? $difficulty : null,
                    $externalLink ?: null,
                    $taskId,
                    $_SESSION['user_id']
                ]);
                
                // Обновляем метки (удаляем старые и добавляем новые)
                $stmt = $pdo->prepare("DELETE FROM task_tags WHERE task_id = ?");
                $stmt->execute([$taskId]);
                
                if (isset($_POST['tags']) && is_array($_POST['tags'])) {
                    $stmt = $pdo->prepare("INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)");
                    foreach ($_POST['tags'] as $tagId) {
                        if (!empty($tagId)) {
                            $stmt->execute([$taskId, $tagId]);
                        }
                    }
                }
                
                $pdo->commit();
                $message = 'Задание успешно обновлено';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при обновлении задания: ' . $e->getMessage();
            }
            break;
            
        case 'delete_task':
            try {
                $taskId = $_POST['task_id'] ?? 0;
                
                // Проверяем, используется ли задание в домашних заданиях
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM lesson_homework 
                    WHERE task_id = ?
                ");
                $stmt->execute([$taskId]);
                $usage = $stmt->fetch();
                
                if ($usage['count'] > 0) {
                    throw new Exception('Нельзя удалить задание, которое используется в планах обучения');
                }
                
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$taskId, $_SESSION['user_id']]);
                
                $message = 'Задание успешно удалено';
                
            } catch (Exception $e) {
                $error = 'Ошибка при удалении задания: ' . $e->getMessage();
            }
            break;
            
        case 'add_comment':
            try {
                $taskId = $_POST['task_id'] ?? 0;
                $comment = trim($_POST['comment'] ?? '');
                
                if (empty($comment)) {
                    throw new Exception('Комментарий не может быть пустым');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO task_comments (task_id, user_id, comment)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$taskId, $_SESSION['user_id'], $comment]);
                
                $message = 'Комментарий добавлен';
                
            } catch (Exception $e) {
                $error = 'Ошибка при добавлении комментария: ' . $e->getMessage();
            }
            break;
            
        case 'clear_all':
            try {
                // Проверяем, используются ли задания
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM lesson_homework lh
                    JOIN tasks t ON lh.task_id = t.id
                    WHERE t.teacher_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $usage = $stmt->fetch();
                
                if ($usage['count'] > 0) {
                    throw new Exception('Нельзя очистить все задания, так как они используются в планах обучения');
                }
                
                $pdo->beginTransaction();
                
                // Удаляем все задания пользователя (каскадно удалятся комментарии и теги)
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE teacher_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                
                $pdo->commit();
                $message = 'Все задания успешно удалены';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при очистке: ' . $e->getMessage();
            }
            break;
            
        case 'import_json':
            try {
                if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла');
                }
                
                $json = file_get_contents($_FILES['json_file']['tmp_name']);
                $data = json_decode($json, true);
                
                if (!is_array($data)) {
                    throw new Exception('Неверный формат JSON файла');
                }
                
                $pdo->beginTransaction();
                
                // Получаем соответствие названий тем и ID
                $stmt = $pdo->prepare("SELECT id, name FROM topics WHERE teacher_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $topics = [];
                while ($row = $stmt->fetch()) {
                    $topics[$row['name']] = $row['id'];
                }
                
                // Получаем соответствие названий меток и ID
                $stmt = $pdo->prepare("SELECT id, name FROM tags WHERE teacher_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $tags = [];
                while ($row = $stmt->fetch()) {
                    $tags[$row['name']] = $row['id'];
                }
                
                foreach ($data as $item) {
                    if (!isset($item['title'])) {
                        continue;
                    }
                    
                    $topicId = null;
                    if (isset($item['topic']) && isset($topics[$item['topic']])) {
                        $topicId = $topics[$item['topic']];
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO tasks (teacher_id, title, topic_id, description, difficulty, external_link)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $item['title'],
                        $topicId,
                        $item['description'] ?? null,
                        $item['difficulty'] ?? null,
                        $item['external_link'] ?? null
                    ]);
                    
                    $taskId = $pdo->lastInsertId();
                    
                    // Добавляем комментарии
                    if (isset($item['comments']) && is_array($item['comments'])) {
                        $stmt2 = $pdo->prepare("
                            INSERT INTO task_comments (task_id, user_id, comment, created_at)
                            VALUES (?, ?, ?, ?)
                        ");
                        foreach ($item['comments'] as $comment) {
                            $stmt2->execute([
                                $taskId,
                                $_SESSION['user_id'],
                                $comment['text'] ?? '',
                                $comment['created_at'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    
                    // Добавляем метки
                    if (isset($item['tags']) && is_array($item['tags'])) {
                        $stmt2 = $pdo->prepare("INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)");
                        foreach ($item['tags'] as $tagName) {
                            if (isset($tags[$tagName])) {
                                $stmt2->execute([$taskId, $tags[$tagName]]);
                            }
                        }
                    }
                }
                
                $pdo->commit();
                $message = 'Задания успешно импортированы';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте: ' . $e->getMessage();
            }
            break;
            
        case 'export_json':
            try {
                // Получаем все задания пользователя
                $stmt = $pdo->prepare("
                    SELECT t.*, 
                           tp.name as topic_name,
                           (SELECT GROUP_CONCAT(tg.name SEPARATOR '||') 
                            FROM task_tags tt 
                            JOIN tags tg ON tt.tag_id = tg.id 
                            WHERE tt.task_id = t.id) as tags
                    FROM tasks t
                    LEFT JOIN topics tp ON t.topic_id = tp.id
                    WHERE t.teacher_id = ?
                    ORDER BY t.created_at DESC
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $tasks = $stmt->fetchAll();
                
                $exportData = [];
                foreach ($tasks as $task) {
                    // Получаем комментарии
                    $stmt2 = $pdo->prepare("
                        SELECT comment, created_at 
                        FROM task_comments 
                        WHERE task_id = ? 
                        ORDER BY created_at ASC
                    ");
                    $stmt2->execute([$task['id']]);
                    $comments = $stmt2->fetchAll();
                    
                    $exportData[] = [
                        'title' => $task['title'],
                        'topic' => $task['topic_name'],
                        'description' => $task['description'],
                        'difficulty' => $task['difficulty'],
                        'external_link' => $task['external_link'],
                        'tags' => $task['tags'] ? explode('||', $task['tags']) : [],
                        'comments' => array_map(function($c) {
                            return [
                                'text' => $c['comment'],
                                'created_at' => $c['created_at']
                            ];
                        }, $comments),
                        'created_at' => $task['created_at']
                    ];
                }
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="tasks_export_' . date('Y-m-d') . '.json"');
                echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
                
            } catch (Exception $e) {
                $error = 'Ошибка при экспорте: ' . $e->getMessage();
            }
            break;
    }
}

// Получение списка заданий
$search = $_GET['search'] ?? '';
$topic = $_GET['topic'] ?? '';
$tag = $_GET['tag'] ?? '';
$difficulty = $_GET['difficulty'] ?? '';

$sql = "
    SELECT t.*, 
           tp.name as topic_name,
           tp.color as topic_color,
           (SELECT COUNT(*) FROM task_comments WHERE task_id = t.id) as comments_count,
           (SELECT GROUP_CONCAT(tg.name SEPARATOR ', ') 
            FROM task_tags tt 
            JOIN tags tg ON tt.tag_id = tg.id 
            WHERE tt.task_id = t.id) as tags_list,
           (SELECT COUNT(*) FROM lesson_homework WHERE task_id = t.id) as usage_count
    FROM tasks t
    LEFT JOIN topics tp ON t.topic_id = tp.id
    WHERE t.teacher_id = ?
";

$params = [$_SESSION['user_id']];

if ($search) {
    $sql .= " AND (t.title LIKE ? OR t.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($topic) {
    $sql .= " AND t.topic_id = ?";
    $params[] = $topic;
}

if ($difficulty !== '') {
    $sql .= " AND t.difficulty = ?";
    $params[] = $difficulty;
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Получение списка тем для фильтра
$stmt = $pdo->prepare("SELECT id, name, color FROM topics WHERE teacher_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$topics = $stmt->fetchAll();

// Получение списка меток для фильтра
$stmt = $pdo->prepare("SELECT id, name, color FROM tags WHERE teacher_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$allTags = $stmt->fetchAll();

// Получение данных для просмотра
$viewTask = null;
$viewComments = [];
$viewTags = [];

if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT t.*, tp.name as topic_name, tp.color as topic_color
        FROM tasks t
        LEFT JOIN topics tp ON t.topic_id = tp.id
        WHERE t.id = ? AND t.teacher_id = ?
    ");
    $stmt->execute([$_GET['view'], $_SESSION['user_id']]);
    $viewTask = $stmt->fetch();
    
    if ($viewTask) {
        // Получаем комментарии
        $stmt = $pdo->prepare("
            SELECT c.*, u.first_name, u.last_name
            FROM task_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$viewTask['id']]);
        $viewComments = $stmt->fetchAll();
        
        // Получаем метки
        $stmt = $pdo->prepare("
            SELECT tg.*
            FROM task_tags tt
            JOIN tags tg ON tt.tag_id = tg.id
            WHERE tt.task_id = ?
        ");
        $stmt->execute([$viewTask['id']]);
        $viewTags = $stmt->fetchAll();
    }
}

// Получение данных для редактирования
$editTask = null;
$editTags = [];

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$_GET['edit'], $_SESSION['user_id']]);
    $editTask = $stmt->fetch();
    
    if ($editTask) {
        // Получаем метки задания
        $stmt = $pdo->prepare("SELECT tag_id FROM task_tags WHERE task_id = ?");
        $stmt->execute([$editTask['id']]);
        $editTags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Получение автодополнения для названий
$stmt = $pdo->prepare("
    SELECT DISTINCT title 
    FROM tasks 
    WHERE teacher_id = ? 
    ORDER BY title
    LIMIT 50
");
$stmt->execute([$_SESSION['user_id']]);
$existingTitles = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Банк заданий - Репетитор 2029</title>
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
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .task-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
            margin-bottom: 20px;
            border-left: 4px solid transparent;
        }
        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .task-card.has-topic {
            border-left-color: v-bind(topic_color);
        }
        .difficulty-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        .difficulty-0 { background: #e0e0e0; color: #666; }
        .difficulty-1 { background: #c8e6c9; color: #2e7d32; }
        .difficulty-2 { background: #a5d6a7; color: #1b5e20; }
        .difficulty-3 { background: #fff9c4; color: #f57f17; }
        .difficulty-4 { background: #ffe082; color: #ff6f00; }
        .difficulty-5 { background: #ffcc80; color: #e65100; }
        .difficulty-6 { background: #ffb74d; color: #bf360c; }
        .difficulty-7 { background: #ff8a65; color: #871400; }
        .difficulty-8 { background: #e57373; color: #b71c1c; }
        .difficulty-9 { background: #ef5350; color: #710000; }
        .difficulty-10 { background: #f44336; color: white; }
        
        .tag-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin: 2px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
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
        .comment-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid #667eea;
        }
        .autocomplete-list {
            position: absolute;
            z-index: 1000;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 2px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            display: none;
        }
        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
        }
        .autocomplete-item:hover {
            background: #f8f9fa;
        }
        .topic-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            color: white;
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
                <h2 class="mb-0"><i class="bi bi-journal-bookmark-fill me-2"></i>Банк заданий</h2>
                <p class="text-muted mb-0">Управление заданиями и связанными с ними материалами</p>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="bi bi-plus-circle"></i> Добавить задание
                </button>
                <button class="btn btn-outline-danger" onclick="clearAllTasks()">
                    <i class="bi bi-trash"></i> Очистить все
                </button>
                <button class="btn btn-outline-success" onclick="document.getElementById('importFile').click()">
                    <i class="bi bi-upload"></i> Импорт JSON
                </button>
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="export_json">
                    <button type="submit" class="btn btn-outline-info">
                        <i class="bi bi-download"></i> Экспорт JSON
                    </button>
                </form>
            </div>
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

        <!-- Скрытый input для импорта -->
        <form method="POST" action="" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="action" value="import_json">
            <input type="file" name="json_file" id="importFile" accept=".json" style="display: none;" onchange="document.getElementById('importForm').submit()">
        </form>

        <?php if ($viewTask): ?>
        <!-- Просмотр задания -->
        <div class="row">
            <div class="col-md-8">
                <div class="task-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h3><?php echo htmlspecialchars($viewTask['title']); ?></h3>
                            <?php if ($viewTask['topic_name']): ?>
                                <span class="topic-badge" style="background: <?php echo htmlspecialchars($viewTask['topic_color'] ?? '#6c757d'); ?>">
                                    <i class="bi bi-bookmark"></i> <?php echo htmlspecialchars($viewTask['topic_name']); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Без темы</span>
                            <?php endif; ?>
                            
                            <?php if ($viewTask['difficulty'] !== null): ?>
                                <span class="difficulty-badge difficulty-<?php echo $viewTask['difficulty']; ?> ms-2" 
                                      style="display: inline-flex; width: 30px; height: 30px; font-size: 12px;">
                                    <?php echo $viewTask['difficulty']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="?edit=<?php echo $viewTask['id']; ?>" class="btn btn-primary btn-sm">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>
                            <a href="tasks.php" class="btn btn-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> Назад
                            </a>
                        </div>
                    </div>
                    
                    <?php if ($viewTask['description']): ?>
                        <div class="mb-4">
                            <h6>Описание:</h6>
                            <div class="p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($viewTask['description'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($viewTask['external_link']): ?>
                        <div class="mb-4">
                            <h6>Ссылка на материал:</h6>
                            <a href="<?php echo htmlspecialchars($viewTask['external_link']); ?>" target="_blank" class="btn btn-outline-primary">
                                <i class="bi bi-link"></i> Перейти по ссылке
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($viewTags)): ?>
                        <div class="mb-4">
                            <h6>Метки:</h6>
                            <div>
                                <?php foreach ($viewTags as $tag): ?>
                                    <span class="tag-badge" style="background: <?php echo htmlspecialchars($tag['color']); ?>;">
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-muted small">
                        <i class="bi bi-clock"></i> Создано: <?php echo date('d.m.Y H:i', strtotime($viewTask['created_at'])); ?>
                        <?php if ($viewTask['updated_at'] != $viewTask['created_at']): ?>
                            <br><i class="bi bi-pencil"></i> Обновлено: <?php echo date('d.m.Y H:i', strtotime($viewTask['updated_at'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="task-card">
                    <h5 class="mb-3">
                        <i class="bi bi-chat"></i> Комментарии
                        <span class="badge bg-secondary ms-2"><?php echo count($viewComments); ?></span>
                    </h5>
                    
                    <form method="POST" action="" class="mb-3">
                        <input type="hidden" name="action" value="add_comment">
                        <input type="hidden" name="task_id" value="<?php echo $viewTask['id']; ?>">
                        <textarea class="form-control mb-2" name="comment" rows="2" placeholder="Добавить комментарий..." required></textarea>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-send"></i> Добавить комментарий
                        </button>
                    </form>
                    
                    <?php if (empty($viewComments)): ?>
                        <p class="text-muted text-center py-3">Нет комментариев</p>
                    <?php else: ?>
                        <?php foreach ($viewComments as $comment): ?>
                            <div class="comment-item">
                                <div class="d-flex justify-content-between mb-2">
                                    <strong>
                                        <?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                    </strong>
                                    <small class="text-muted">
                                        <?php echo date('d.m.Y H:i', strtotime($comment['created_at'])); ?>
                                    </small>
                                </div>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        
        <!-- Фильтры -->
        <div class="filters-card">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Поиск</label>
                    <input type="text" class="form-control" name="search" placeholder="Название или описание..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Тема</label>
                    <select class="form-select" name="topic">
                        <option value="">Все темы</option>
                        <?php foreach ($topics as $topic): ?>
                            <option value="<?php echo $topic['id']; ?>" <?php echo $_GET['topic'] == $topic['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($topic['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Сложность</label>
                    <select class="form-select" name="difficulty">
                        <option value="">Любая</option>
                        <?php for ($i = 0; $i <= 10; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $_GET['difficulty'] == $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> / 10
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Применить
                    </button>
                    <a href="tasks.php" class="btn btn-secondary">
                        <i class="bi bi-eraser"></i> Сбросить
                    </a>
                </div>
            </form>
        </div>

        <!-- Список заданий -->
        <?php if (empty($tasks)): ?>
            <div class="text-center py-5">
                <i class="bi bi-journal-bookmark-fill display-1 text-muted"></i>
                <h4 class="mt-3">Нет заданий</h4>
                <p class="text-muted">Нажмите "Добавить задание", чтобы создать первое задание</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($tasks as $task): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="task-card" style="<?php echo $task['topic_color'] ? 'border-left-color: ' . $task['topic_color'] : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="mb-0"><?php echo htmlspecialchars($task['title']); ?></h5>
                                <?php if ($task['difficulty'] !== null): ?>
                                    <span class="difficulty-badge difficulty-<?php echo $task['difficulty']; ?>">
                                        <?php echo $task['difficulty']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($task['topic_name']): ?>
                                <div class="mb-2">
                                    <span class="topic-badge" style="background: <?php echo htmlspecialchars($task['topic_color'] ?? '#6c757d'); ?>">
                                        <i class="bi bi-bookmark"></i> <?php echo htmlspecialchars($task['topic_name']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($task['description']): ?>
                                <p class="text-muted small mb-2">
                                    <?php echo htmlspecialchars(substr($task['description'], 0, 100)) . (strlen($task['description']) > 100 ? '...' : ''); ?>
                                </p>
                            <?php endif; ?>
                            
                            <?php if ($task['tags_list']): ?>
                                <div class="mb-2">
                                    <small class="text-muted">
                                        <i class="bi bi-tags"></i> <?php echo htmlspecialchars(substr($task['tags_list'], 0, 50)) . (strlen($task['tags_list']) > 50 ? '...' : ''); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div>
                                    <span class="badge bg-light text-dark me-1" title="Комментарии">
                                        <i class="bi bi-chat"></i> <?php echo $task['comments_count']; ?>
                                    </span>
                                    <?php if ($task['usage_count'] > 0): ?>
                                        <span class="badge bg-success" title="Используется в планах">
                                            <i class="bi bi-calendar-check"></i> <?php echo $task['usage_count']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <a href="?view=<?php echo $task['id']; ?>" class="btn btn-sm btn-outline-info btn-action" title="Просмотр">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="?edit=<?php echo $task['id']; ?>" class="btn btn-sm btn-outline-primary btn-action" title="Редактировать">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteTask(<?php echo $task['id']; ?>)" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="text-muted small mt-2">
                                <i class="bi bi-clock"></i> <?php echo date('d.m.Y', strtotime($task['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Модальное окно добавления/редактирования -->
    <div class="modal fade" id="taskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Добавление задания</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="taskForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add_task">
                        <input type="hidden" name="task_id" id="taskId" value="">
                        
                        <div class="mb-3 position-relative">
                            <label class="form-label">Название задания <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="taskTitle" required 
                                   autocomplete="off" oninput="showAutocomplete(this.value)">
                            <div class="autocomplete-list" id="autocompleteList">
                                <?php foreach ($existingTitles as $title): ?>
                                    <div class="autocomplete-item" onclick="selectTitle('<?php echo htmlspecialchars($title); ?>')">
                                        <?php echo htmlspecialchars($title); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Тема</label>
                            <select class="form-select" name="topic_id" id="topicId">
                                <option value="">-- Без темы --</option>
                                <?php foreach ($topics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>" style="color: <?php echo htmlspecialchars($topic['color']); ?>;">
                                        <?php echo htmlspecialchars($topic['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" id="taskDescription" rows="3"></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Сложность (0-10)</label>
                                <select class="form-select" name="difficulty" id="difficulty">
                                    <option value="">-- Не указана --</option>
                                    <?php for ($i = 0; $i <= 10; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> / 10</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ссылка на материал</label>
                                <input type="url" class="form-control" name="external_link" id="externalLink" 
                                       placeholder="https://...">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Метки</label>
                            <select class="form-select" name="tags[]" id="tags" multiple size="5">
                                <?php foreach ($allTags as $tag): ?>
                                    <option value="<?php echo $tag['id']; ?>" style="color: <?php echo htmlspecialchars($tag['color']); ?>; background: <?php echo htmlspecialchars($tag['color']); ?>20;">
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Удерживайте Ctrl для выбора нескольких меток</small>
                        </div>
                        
                        <div class="mb-3" id="initialCommentField">
                            <label class="form-label">Начальный комментарий</label>
                            <textarea class="form-control" name="initial_comment" rows="2" placeholder="Добавить первый комментарий к заданию..."></textarea>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Инициализация
        let taskModal = null;
        let autocompleteTimeout;
        
        document.addEventListener('DOMContentLoaded', function() {
            taskModal = new bootstrap.Modal(document.getElementById('taskModal'));
            
            <?php if ($editTask): ?>
            openEditModal(<?php echo json_encode([
                'id' => $editTask['id'],
                'title' => $editTask['title'],
                'topic_id' => $editTask['topic_id'],
                'description' => $editTask['description'],
                'difficulty' => $editTask['difficulty'],
                'external_link' => $editTask['external_link'],
                'tags' => $editTags
            ]); ?>);
            <?php endif; ?>
        });
        
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Добавление задания';
            document.getElementById('formAction').value = 'add_task';
            document.getElementById('taskId').value = '';
            document.getElementById('taskTitle').value = '';
            document.getElementById('topicId').value = '';
            document.getElementById('taskDescription').value = '';
            document.getElementById('difficulty').value = '';
            document.getElementById('externalLink').value = '';
            document.getElementById('tags').value = '';
            document.getElementById('initialCommentField').style.display = 'block';
            taskModal.show();
        }
        
        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Редактирование задания';
            document.getElementById('formAction').value = 'edit_task';
            document.getElementById('taskId').value = data.id;
            document.getElementById('taskTitle').value = data.title || '';
            document.getElementById('topicId').value = data.topic_id || '';
            document.getElementById('taskDescription').value = data.description || '';
            document.getElementById('difficulty').value = data.difficulty || '';
            document.getElementById('externalLink').value = data.external_link || '';
            
            // Выбираем метки
            const select = document.getElementById('tags');
            if (data.tags) {
                for (let option of select.options) {
                    option.selected = data.tags.includes(parseInt(option.value));
                }
            } else {
                select.value = '';
            }
            
            document.getElementById('initialCommentField').style.display = 'none';
            taskModal.show();
        }
        
        function deleteTask(taskId) {
            if (confirm('Вы уверены, что хотите удалить это задание?\n\nВнимание: задание не должно использоваться в планах обучения.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_task">
                    <input type="hidden" name="task_id" value="${taskId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function clearAllTasks() {
            if (confirm('ВНИМАНИЕ! Вы уверены, что хотите удалить ВСЕ задания?\n\nЭто действие нельзя отменить. Задания не должны использоваться в планах обучения.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="clear_all">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Автодополнение для названий
        function showAutocomplete(value) {
            clearTimeout(autocompleteTimeout);
            
            const list = document.getElementById('autocompleteList');
            if (value.length < 2) {
                list.style.display = 'none';
                return;
            }
            
            autocompleteTimeout = setTimeout(() => {
                const items = list.getElementsByClassName('autocomplete-item');
                let visibleCount = 0;
                
                for (let item of items) {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(value.toLowerCase())) {
                        item.style.display = 'block';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                }
                
                list.style.display = visibleCount > 0 ? 'block' : 'none';
            }, 300);
        }
        
        function selectTitle(title) {
            document.getElementById('taskTitle').value = title;
            document.getElementById('autocompleteList').style.display = 'none';
        }
        
        // Закрывать автодополнение при клике вне
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#taskTitle') && !e.target.closest('#autocompleteList')) {
                document.getElementById('autocompleteList').style.display = 'none';
            }
        });
    </script>
</body>
</html>