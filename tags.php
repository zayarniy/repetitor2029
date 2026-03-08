<?php
// tags.php
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

// Добавление метки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_tag':
            try {
                $name = trim($_POST['name'] ?? '');
                $parentId = $_POST['parent_id'] ?: null;
                $color = trim($_POST['color'] ?? '#808080');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    throw new Exception('Название метки обязательно');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO tags (teacher_id, name, parent_id, color, description)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $name,
                    $parentId,
                    $color,
                    $description ?: null
                ]);
                
                $message = 'Метка успешно добавлена';
            } catch (Exception $e) {
                $error = 'Ошибка при добавлении метки: ' . $e->getMessage();
            }
            break;
            
        case 'edit_tag':
            try {
                $tagId = $_POST['tag_id'] ?? 0;
                $name = trim($_POST['name'] ?? '');
                $parentId = $_POST['parent_id'] ?: null;
                $color = trim($_POST['color'] ?? '#808080');
                $description = trim($_POST['description'] ?? '');
                
                // Проверяем, что метка принадлежит текущему пользователю
                $stmt = $pdo->prepare("SELECT id FROM tags WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$tagId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Метка не найдена');
                }
                
                // Проверяем, не пытаемся ли сделать метку родителем самой себя
                if ($parentId == $tagId) {
                    throw new Exception('Метка не может быть родителем самой себя');
                }
                
                // Проверяем, не создает ли это циклическую зависимость
                if ($parentId) {
                    $current = $parentId;
                    $visited = [$tagId];
                    while ($current) {
                        if (in_array($current, $visited)) {
                            throw new Exception('Обнаружена циклическая зависимость в иерархии');
                        }
                        $visited[] = $current;
                        
                        $stmt = $pdo->prepare("SELECT parent_id FROM tags WHERE id = ? AND teacher_id = ?");
                        $stmt->execute([$current, $_SESSION['user_id']]);
                        $parent = $stmt->fetch();
                        $current = $parent ? $parent['parent_id'] : null;
                    }
                }
                
                $stmt = $pdo->prepare("
                    UPDATE tags 
                    SET name = ?, parent_id = ?, color = ?, description = ?
                    WHERE id = ? AND teacher_id = ?
                ");
                $stmt->execute([
                    $name,
                    $parentId,
                    $color,
                    $description ?: null,
                    $tagId,
                    $_SESSION['user_id']
                ]);
                
                $message = 'Метка успешно обновлена';
            } catch (Exception $e) {
                $error = 'Ошибка при обновлении метки: ' . $e->getMessage();
            }
            break;
            
        case 'delete_tag':
            try {
                $tagId = $_POST['tag_id'] ?? 0;
                
                // Проверяем, есть ли дочерние метки
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tags WHERE parent_id = ? AND teacher_id = ?");
                $stmt->execute([$tagId, $_SESSION['user_id']]);
                $children = $stmt->fetch();
                
                if ($children['count'] > 0) {
                    throw new Exception('Нельзя удалить метку, у которой есть дочерние метки');
                }
                
                // Проверяем, используется ли метка в заданиях
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM task_tags tt
                    JOIN tasks t ON tt.task_id = t.id
                    WHERE tt.tag_id = ? AND t.teacher_id = ?
                ");
                $stmt->execute([$tagId, $_SESSION['user_id']]);
                $tasks = $stmt->fetch();
                
                if ($tasks['count'] > 0) {
                    throw new Exception('Нельзя удалить метку, которая используется в заданиях');
                }
                
                // Проверяем, используется ли метка в занятиях
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM lesson_tags lt
                    JOIN lessons l ON lt.lesson_id = l.id
                    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
                    WHERE lt.tag_id = ? AND lp.teacher_id = ?
                ");
                $stmt->execute([$tagId, $_SESSION['user_id']]);
                $lessons = $stmt->fetch();
                
                if ($lessons['count'] > 0) {
                    throw new Exception('Нельзя удалить метку, которая используется в занятиях');
                }
                
                $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$tagId, $_SESSION['user_id']]);
                
                $message = 'Метка успешно удалена';
            } catch (Exception $e) {
                $error = 'Ошибка при удалении метки: ' . $e->getMessage();
            }
            break;
            
        case 'clear_all':
            try {
                // Проверяем, используются ли метки
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM task_tags tt
                    JOIN tasks t ON tt.task_id = t.id
                    WHERE t.teacher_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $tasks = $stmt->fetch();
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM lesson_tags lt
                    JOIN lessons l ON lt.lesson_id = l.id
                    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
                    WHERE lp.teacher_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $lessons = $stmt->fetch();
                
                if ($tasks['count'] > 0 || $lessons['count'] > 0) {
                    throw new Exception('Нельзя очистить все метки, так как они используются в заданиях или занятиях');
                }
                
                $pdo->beginTransaction();
                
                // Удаляем все метки пользователя (каскадно удалятся дочерние)
                $stmt = $pdo->prepare("DELETE FROM tags WHERE teacher_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                
                $pdo->commit();
                $message = 'Все метки успешно удалены';
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
                
                // Функция для рекурсивного импорта меток
                function importTags($pdo, $tags, $parentId = null, $teacherId) {
                    foreach ($tags as $tag) {
                        if (!isset($tag['name'])) {
                            continue;
                        }
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO tags (teacher_id, name, parent_id, color, description)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $teacherId,
                            $tag['name'],
                            $parentId,
                            $tag['color'] ?? '#808080',
                            $tag['description'] ?? null
                        ]);
                        
                        $newId = $pdo->lastInsertId();
                        
                        if (isset($tag['children']) && is_array($tag['children'])) {
                            importTags($pdo, $tag['children'], $newId, $teacherId);
                        }
                    }
                }
                
                importTags($pdo, $data, null, $_SESSION['user_id']);
                
                $pdo->commit();
                $message = 'Метки успешно импортированы';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте: ' . $e->getMessage();
            }
            break;
            
        case 'export_json':
            try {
                // Получаем все метки пользователя
                $stmt = $pdo->prepare("
                    SELECT id, name, parent_id, color, description 
                    FROM tags 
                    WHERE teacher_id = ?
                    ORDER BY parent_id IS NULL DESC, name
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $allTags = $stmt->fetchAll();
                
                // Строим дерево
                $tagsById = [];
                foreach ($allTags as $tag) {
                    $tagsById[$tag['id']] = [
                        'name' => $tag['name'],
                        'color' => $tag['color'],
                        'description' => $tag['description'],
                        'children' => []
                    ];
                }
                
                $rootTags = [];
                foreach ($allTags as $tag) {
                    if ($tag['parent_id'] && isset($tagsById[$tag['parent_id']])) {
                        $tagsById[$tag['parent_id']]['children'][] = &$tagsById[$tag['id']];
                    } else {
                        $rootTags[] = &$tagsById[$tag['id']];
                    }
                }
                
                // Очищаем от id
                $cleanTags = array_values($rootTags);
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="tags_export_' . date('Y-m-d') . '.json"');
                echo json_encode($cleanTags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            } catch (Exception $e) {
                $error = 'Ошибка при экспорте: ' . $e->getMessage();
            }
            break;
    }
}

// Получение всех меток для дерева
$stmt = $pdo->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM tags WHERE parent_id = t.id) as children_count,
           (SELECT COUNT(*) FROM task_tags tt JOIN tasks ta ON tt.task_id = ta.id WHERE tt.tag_id = t.id AND ta.teacher_id = ?) as tasks_count,
           (SELECT COUNT(*) FROM lesson_tags lt JOIN lessons l ON lt.lesson_id = l.id JOIN lesson_plans lp ON l.lesson_plan_id = lp.id WHERE lt.tag_id = t.id AND lp.teacher_id = ?) as lessons_count
    FROM tags t
    WHERE t.teacher_id = ?
    ORDER BY t.parent_id IS NULL DESC, t.name
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$allTags = $stmt->fetchAll();

// Строим дерево для отображения
$tagsTree = [];
$tagsById = [];

foreach ($allTags as $tag) {
    $tagsById[$tag['id']] = $tag;
}

foreach ($allTags as $tag) {
    if ($tag['parent_id'] && isset($tagsById[$tag['parent_id']])) {
        if (!isset($tagsById[$tag['parent_id']]['children'])) {
            $tagsById[$tag['parent_id']]['children'] = [];
        }
        $tagsById[$tag['parent_id']]['children'][] = $tag;
    } else {
        $tagsTree[] = $tag;
    }
}

// Получаем метки для выпадающего списка (родительские)
$stmt = $pdo->prepare("
    SELECT id, name, color 
    FROM tags 
    WHERE teacher_id = ?
    ORDER BY name
");
$stmt->execute([$_SESSION['user_id']]);
$parentTags = $stmt->fetchAll();

// Получаем метку для редактирования
$editTag = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$_GET['edit'], $_SESSION['user_id']]);
    $editTag = $stmt->fetch();
}

// Получаем метку для просмотра
$viewTag = null;
$viewChildren = [];
$viewTasks = [];
$viewLessons = [];

if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$_GET['view'], $_SESSION['user_id']]);
    $viewTag = $stmt->fetch();
    
    if ($viewTag) {
        // Получаем дочерние метки
        $stmt = $pdo->prepare("
            SELECT * FROM tags 
            WHERE parent_id = ? AND teacher_id = ?
            ORDER BY name
        ");
        $stmt->execute([$viewTag['id'], $_SESSION['user_id']]);
        $viewChildren = $stmt->fetchAll();
        
        // Получаем задания с этой меткой
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM task_tags WHERE task_id = t.id) as tags_count
            FROM tasks t
            JOIN task_tags tt ON t.id = tt.task_id
            WHERE tt.tag_id = ? AND t.teacher_id = ?
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$viewTag['id'], $_SESSION['user_id']]);
        $viewTasks = $stmt->fetchAll();
        
        // Получаем занятия с этой меткой
        $stmt = $pdo->prepare("
            SELECT l.*, lp.name as plan_name, 
                   CONCAT(u.first_name, ' ', u.last_name) as student_name
            FROM lessons l
            JOIN lesson_tags lt ON l.id = lt.lesson_id
            JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
            LEFT JOIN students s ON lp.student_id = s.id
            LEFT JOIN users u ON s.user_id = u.id
            WHERE lt.tag_id = ? AND lp.teacher_id = ?
            ORDER BY l.date DESC
            LIMIT 10
        ");
        $stmt->execute([$viewTag['id'], $_SESSION['user_id']]);
        $viewLessons = $stmt->fetchAll();
    }
}

// Функция для рекурсивного отображения дерева меток
function renderTagTree($tags, $level = 0) {
    foreach ($tags as $tag) {
        $hasChildren = isset($tag['children']) && !empty($tag['children']);
        $padding = $level * 30;
        ?>
        <div class="tag-tree-item" style="margin-left: <?php echo $padding; ?>px;" data-id="<?php echo $tag['id']; ?>">
            <div class="tag-item d-flex align-items-center p-2 <?php echo $hasChildren ? 'has-children' : ''; ?>" 
                 style="border-left: 4px solid <?php echo htmlspecialchars($tag['color']); ?>; background: #f8f9fa; margin-bottom: 2px; border-radius: 0 8px 8px 0;">
                
                <div class="tag-expand me-2" style="width: 20px;">
                    <?php if ($hasChildren): ?>
                        <i class="bi bi-chevron-down toggle-children" style="cursor: pointer;"></i>
                    <?php endif; ?>
                </div>
                
                <div class="tag-color-indicator me-2" style="width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($tag['color']); ?>;"></div>
                
                <div class="tag-name flex-grow-1">
                    <strong><?php echo htmlspecialchars($tag['name']); ?></strong>
                    <?php if ($tag['description']): ?>
                        <small class="text-muted ms-2"><?php echo htmlspecialchars(substr($tag['description'], 0, 50)) . (strlen($tag['description']) > 50 ? '...' : ''); ?></small>
                    <?php endif; ?>
                </div>
                
                <div class="tag-stats me-3">
                    <?php if ($tag['tasks_count'] > 0): ?>
                        <span class="badge bg-info me-1" title="Используется в заданиях">
                            <i class="bi bi-journal"></i> <?php echo $tag['tasks_count']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($tag['lessons_count'] > 0): ?>
                        <span class="badge bg-success me-1" title="Используется в занятиях">
                            <i class="bi bi-calendar"></i> <?php echo $tag['lessons_count']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($tag['children_count'] > 0): ?>
                        <span class="badge bg-secondary" title="Дочерних меток">
                            <i class="bi bi-diagram-2"></i> <?php echo $tag['children_count']; ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="tag-actions">
                    <a href="?view=<?php echo $tag['id']; ?>" class="btn btn-sm btn-outline-info btn-action" title="Просмотр">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="?edit=<?php echo $tag['id']; ?>" class="btn btn-sm btn-outline-primary btn-action" title="Редактировать">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteTag(<?php echo $tag['id']; ?>)" title="Удалить">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            
            <?php if ($hasChildren): ?>
                <div class="tag-children">
                    <?php renderTagTree($tag['children'], $level + 1); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Банк меток - Репетитор 2029</title>
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
        .tags-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        .tag-tree-item {
            position: relative;
        }
        .tag-item {
            transition: all 0.2s;
            cursor: pointer;
        }
        .tag-item:hover {
            background: #e9ecef !important;
        }
        .btn-action {
            padding: 2px 8px;
            margin: 0 2px;
            font-size: 12px;
        }
        .tag-stats .badge {
            font-size: 11px;
        }
        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 2px solid #e0e0e0;
        }
        .import-area {
            border: 2px dashed #e0e0e0;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .import-area:hover {
            border-color: #667eea;
            background: #f8f9fa;
        }
        .view-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            height: 100%;
        }
        .usage-item {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        .usage-item:last-child {
            border-bottom: none;
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
                <h2 class="mb-0"><i class="bi bi-tags me-2"></i>Банк меток</h2>
                <p class="text-muted mb-0">Управление иерархическими метками для заданий и занятий</p>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="bi bi-plus-circle"></i> Добавить метку
                </button>
                <button class="btn btn-outline-danger" onclick="clearAllTags()">
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

        <?php if ($viewTag): ?>
        <!-- Просмотр метки -->
        <div class="row">
            <div class="col-md-4">
                <div class="view-card">
                    <div class="d-flex align-items-center mb-3">
                        <div style="width: 40px; height: 40px; border-radius: 8px; background: <?php echo htmlspecialchars($viewTag['color']); ?>; margin-right: 15px;"></div>
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($viewTag['name']); ?></h4>
                            <p class="text-muted mb-0">
                                Создана: <?php echo date('d.m.Y H:i', strtotime($viewTag['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if ($viewTag['description']): ?>
                        <div class="mb-3">
                            <strong>Описание:</strong>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($viewTag['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($viewChildren)): ?>
                        <div class="mb-3">
                            <strong>Дочерние метки:</strong>
                            <div class="mt-2">
                                <?php foreach ($viewChildren as $child): ?>
                                    <div class="d-flex align-items-center mb-2">
                                        <div style="width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($child['color']); ?>; margin-right: 10px;"></div>
                                        <a href="?view=<?php echo $child['id']; ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($child['name']); ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <a href="?edit=<?php echo $viewTag['id']; ?>" class="btn btn-primary">
                            <i class="bi bi-pencil"></i> Редактировать
                        </a>
                        <a href="tags.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Назад
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="view-card">
                    <ul class="nav nav-tabs mb-3" id="usageTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tasks-tab" data-bs-toggle="tab" data-bs-target="#tasks" type="button" role="tab">
                                <i class="bi bi-journal"></i> Задания (<?php echo count($viewTasks); ?>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="lessons-tab" data-bs-toggle="tab" data-bs-target="#lessons" type="button" role="tab">
                                <i class="bi bi-calendar"></i> Занятия (<?php echo count($viewLessons); ?>)
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="usageTabsContent">
                        <div class="tab-pane fade show active" id="tasks" role="tabpanel">
                            <?php if (empty($viewTasks)): ?>
                                <p class="text-muted text-center py-4">Метка не используется в заданиях</p>
                            <?php else: ?>
                                <?php foreach ($viewTasks as $task): ?>
                                    <div class="usage-item">
                                        <div class="d-flex justify-content-between">
                                            <a href="tasks.php?view=<?php echo $task['id']; ?>" class="fw-bold text-decoration-none">
                                                <?php echo htmlspecialchars($task['title']); ?>
                                            </a>
                                            <small class="text-muted">
                                                <i class="bi bi-tags"></i> <?php echo $task['tags_count']; ?> меток
                                            </small>
                                        </div>
                                        <small class="text-muted d-block mt-1">
                                            Сложность: <?php echo $task['difficulty'] ?? 'не указана'; ?>/10
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="tab-pane fade" id="lessons" role="tabpanel">
                            <?php if (empty($viewLessons)): ?>
                                <p class="text-muted text-center py-4">Метка не используется в занятиях</p>
                            <?php else: ?>
                                <?php foreach ($viewLessons as $lesson): ?>
                                    <div class="usage-item">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <span class="fw-bold"><?php echo date('d.m.Y', strtotime($lesson['date'])); ?></span>
                                                <span class="mx-2">|</span>
                                                <span><?php echo htmlspecialchars($lesson['student_name'] ?? 'Без ученика'); ?></span>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($lesson['plan_name']); ?></small>
                                        </div>
                                        <?php if ($lesson['title']): ?>
                                            <small class="text-muted d-block mt-1">
                                                <i class="bi bi-card-heading"></i> <?php echo htmlspecialchars($lesson['title']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Дерево меток -->
        <div class="tags-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Иерархия меток</h5>
                <div class="text-muted">
                    <small><i class="bi bi-info-circle"></i> Всего меток: <?php echo count($allTags); ?></small>
                </div>
            </div>
            
            <?php if (empty($tagsTree)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-tags display-1 text-muted"></i>
                    <h4 class="mt-3">Нет меток</h4>
                    <p class="text-muted">Нажмите "Добавить метку", чтобы создать первую метку</p>
                </div>
            <?php else: ?>
                <div class="tag-tree">
                    <?php renderTagTree($tagsTree); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно добавления/редактирования -->
    <div class="modal fade" id="tagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Добавление метки</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="tagForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add_tag">
                        <input type="hidden" name="tag_id" id="tagId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Название метки <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="tagName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Родительская метка</label>
                            <select class="form-select" name="parent_id" id="parentTag">
                                <option value="">-- Нет родительской метки --</option>
                                <?php foreach ($parentTags as $tag): ?>
                                    <option value="<?php echo $tag['id']; ?>" style="color: <?php echo htmlspecialchars($tag['color']); ?>;">
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Цвет метки</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color me-2" name="color" id="tagColor" value="#808080" style="width: 60px; height: 40px;">
                                <input type="text" class="form-control" id="colorHex" value="#808080" maxlength="7">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" id="tagDescription" rows="3"></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <small>Цвет дочерней метки будет приоритетнее цвета родительской при отображении.</small>
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
        let tagModal = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            tagModal = new bootstrap.Modal(document.getElementById('tagModal'));
            
            // Синхронизация color picker и текстового поля
            document.getElementById('tagColor').addEventListener('input', function(e) {
                document.getElementById('colorHex').value = e.target.value;
            });
            
            document.getElementById('colorHex').addEventListener('input', function(e) {
                let value = e.target.value;
                if (/^#[0-9A-F]{6}$/i.test(value)) {
                    document.getElementById('tagColor').value = value;
                }
            });
            
            // Обработка сворачивания/разворачивания дерева
            document.querySelectorAll('.toggle-children').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const item = this.closest('.tag-tree-item');
                    const children = item.querySelector('.tag-children');
                    if (children) {
                        if (children.style.display === 'none') {
                            children.style.display = 'block';
                            this.classList.remove('bi-chevron-right');
                            this.classList.add('bi-chevron-down');
                        } else {
                            children.style.display = 'none';
                            this.classList.remove('bi-chevron-down');
                            this.classList.add('bi-chevron-right');
                        }
                    }
                });
            });
            
            <?php if ($editTag): ?>
            openEditModal(<?php echo json_encode([
                'id' => $editTag['id'],
                'name' => $editTag['name'],
                'parent_id' => $editTag['parent_id'],
                'color' => $editTag['color'],
                'description' => $editTag['description']
            ]); ?>);
            <?php endif; ?>
        });
        
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Добавление метки';
            document.getElementById('formAction').value = 'add_tag';
            document.getElementById('tagId').value = '';
            document.getElementById('tagName').value = '';
            document.getElementById('parentTag').value = '';
            document.getElementById('tagColor').value = '#808080';
            document.getElementById('colorHex').value = '#808080';
            document.getElementById('tagDescription').value = '';
            tagModal.show();
        }
        
        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Редактирование метки';
            document.getElementById('formAction').value = 'edit_tag';
            document.getElementById('tagId').value = data.id;
            document.getElementById('tagName').value = data.name || '';
            document.getElementById('parentTag').value = data.parent_id || '';
            document.getElementById('tagColor').value = data.color || '#808080';
            document.getElementById('colorHex').value = data.color || '#808080';
            document.getElementById('tagDescription').value = data.description || '';
            tagModal.show();
        }
        
        function deleteTag(tagId) {
            if (confirm('Вы уверены, что хотите удалить эту метку?\n\nВнимание: метка не должна иметь дочерних меток и использоваться в заданиях или занятиях.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_tag">
                    <input type="hidden" name="tag_id" value="${tagId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function clearAllTags() {
            if (confirm('ВНИМАНИЕ! Вы уверены, что хотите удалить ВСЕ метки?\n\nЭто действие нельзя отменить. Метки не должны использоваться в заданиях или занятиях.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="clear_all">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Перетаскивание для импорта
        document.querySelector('.import-area')?.addEventListener('click', function() {
            document.getElementById('importFile').click();
        });
        
        document.querySelector('.import-area')?.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = '#667eea';
            this.style.background = '#f8f9fa';
        });
        
        document.querySelector('.import-area')?.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '#e0e0e0';
            this.style.background = 'white';
        });
        
        document.querySelector('.import-area')?.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#e0e0e0';
            this.style.background = 'white';
            
            const file = e.dataTransfer.files[0];
            if (file && file.type === 'application/json') {
                const input = document.getElementById('importFile');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                input.files = dataTransfer.files;
                document.getElementById('importForm').submit();
            } else {
                alert('Пожалуйста, выберите JSON файл');
            }
        });
    </script>
</body>
</html>