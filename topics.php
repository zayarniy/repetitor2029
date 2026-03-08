<?php
// topics.php
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

// Добавление темы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_topic':
            try {
                $name = trim($_POST['name'] ?? '');
                $parentId = $_POST['parent_id'] ?: null;
                $color = trim($_POST['color'] ?? '#808080');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    throw new Exception('Название темы обязательно');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO topics (teacher_id, name, parent_id, color, description)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $name,
                    $parentId,
                    $color,
                    $description ?: null
                ]);
                
                $message = 'Тема успешно добавлена';
            } catch (Exception $e) {
                $error = 'Ошибка при добавлении темы: ' . $e->getMessage();
            }
            break;
            
        case 'edit_topic':
            try {
                $topicId = $_POST['topic_id'] ?? 0;
                $name = trim($_POST['name'] ?? '');
                $parentId = $_POST['parent_id'] ?: null;
                $color = trim($_POST['color'] ?? '#808080');
                $description = trim($_POST['description'] ?? '');
                
                // Проверяем, что тема принадлежит текущему пользователю
                $stmt = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$topicId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Тема не найдена');
                }
                
                // Проверяем, не пытаемся ли сделать тему родителем самой себя
                if ($parentId == $topicId) {
                    throw new Exception('Тема не может быть родителем самой себя');
                }
                
                // Проверяем, не создает ли это циклическую зависимость
                if ($parentId) {
                    $current = $parentId;
                    $visited = [$topicId];
                    while ($current) {
                        if (in_array($current, $visited)) {
                            throw new Exception('Обнаружена циклическая зависимость в иерархии');
                        }
                        $visited[] = $current;
                        
                        $stmt = $pdo->prepare("SELECT parent_id FROM topics WHERE id = ? AND teacher_id = ?");
                        $stmt->execute([$current, $_SESSION['user_id']]);
                        $parent = $stmt->fetch();
                        $current = $parent ? $parent['parent_id'] : null;
                    }
                }
                
                $stmt = $pdo->prepare("
                    UPDATE topics 
                    SET name = ?, parent_id = ?, color = ?, description = ?
                    WHERE id = ? AND teacher_id = ?
                ");
                $stmt->execute([
                    $name,
                    $parentId,
                    $color,
                    $description ?: null,
                    $topicId,
                    $_SESSION['user_id']
                ]);
                
                $message = 'Тема успешно обновлена';
            } catch (Exception $e) {
                $error = 'Ошибка при обновлении темы: ' . $e->getMessage();
            }
            break;
            
        case 'delete_topic':
            try {
                $topicId = $_POST['topic_id'] ?? 0;
                
                // Проверяем, есть ли дочерние темы
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM topics WHERE parent_id = ? AND teacher_id = ?");
                $stmt->execute([$topicId, $_SESSION['user_id']]);
                $children = $stmt->fetch();
                
                if ($children['count'] > 0) {
                    throw new Exception('Нельзя удалить тему, у которой есть дочерние темы');
                }
                
                // Проверяем, используется ли тема в заданиях
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE topic_id = ? AND teacher_id = ?");
                $stmt->execute([$topicId, $_SESSION['user_id']]);
                $tasks = $stmt->fetch();
                
                if ($tasks['count'] > 0) {
                    throw new Exception('Нельзя удалить тему, которая используется в заданиях');
                }
                
                // Проверяем, используется ли тема в занятиях
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM lesson_topics lt
                    JOIN lessons l ON lt.lesson_id = l.id
                    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
                    WHERE lt.topic_id = ? AND lp.teacher_id = ?
                ");
                $stmt->execute([$topicId, $_SESSION['user_id']]);
                $lessons = $stmt->fetch();
                
                if ($lessons['count'] > 0) {
                    throw new Exception('Нельзя удалить тему, которая используется в занятиях');
                }
                
                // Удаляем ссылки темы
                $stmt = $pdo->prepare("DELETE FROM topic_links WHERE topic_id = ?");
                $stmt->execute([$topicId]);
                
                // Удаляем тему
                $stmt = $pdo->prepare("DELETE FROM topics WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$topicId, $_SESSION['user_id']]);
                
                $message = 'Тема успешно удалена';
            } catch (Exception $e) {
                $error = 'Ошибка при удалении темы: ' . $e->getMessage();
            }
            break;
            
        case 'add_link':
            try {
                $topicId = $_POST['topic_id'] ?? 0;
                $url = trim($_POST['url'] ?? '');
                $title = trim($_POST['title'] ?? '');
                $comment = trim($_POST['comment'] ?? '');
                
                if (empty($url)) {
                    throw new Exception('URL ссылки обязателен');
                }
                
                // Проверяем, что тема принадлежит пользователю
                $stmt = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$topicId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('Тема не найдена');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO topic_links (topic_id, url, title, comment)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $topicId,
                    $url,
                    $title ?: null,
                    $comment ?: null
                ]);
                
                $message = 'Ссылка успешно добавлена';
            } catch (Exception $e) {
                $error = 'Ошибка при добавлении ссылки: ' . $e->getMessage();
            }
            break;
            
        case 'delete_link':
            try {
                $linkId = $_POST['link_id'] ?? 0;
                
                // Проверяем, что ссылка принадлежит теме пользователя
                $stmt = $pdo->prepare("
                    DELETE tl FROM topic_links tl
                    JOIN topics t ON tl.topic_id = t.id
                    WHERE tl.id = ? AND t.teacher_id = ?
                ");
                $stmt->execute([$linkId, $_SESSION['user_id']]);
                
                $message = 'Ссылка успешно удалена';
            } catch (Exception $e) {
                $error = 'Ошибка при удалении ссылки: ' . $e->getMessage();
            }
            break;
            
        case 'clear_all':
            try {
                // Проверяем, используются ли темы
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM tasks 
                    WHERE topic_id IS NOT NULL AND teacher_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $tasks = $stmt->fetch();
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM lesson_topics lt
                    JOIN lessons l ON lt.lesson_id = l.id
                    JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
                    WHERE lp.teacher_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $lessons = $stmt->fetch();
                
                if ($tasks['count'] > 0 || $lessons['count'] > 0) {
                    throw new Exception('Нельзя очистить все темы, так как они используются в заданиях или занятиях');
                }
                
                $pdo->beginTransaction();
                
                // Удаляем все ссылки тем пользователя
                $stmt = $pdo->prepare("
                    DELETE tl FROM topic_links tl
                    JOIN topics t ON tl.topic_id = t.id
                    WHERE t.teacher_id = ?
                ");
                $stmt->execute([$_SESSION['user_id']]);
                
                // Удаляем все темы пользователя
                $stmt = $pdo->prepare("DELETE FROM topics WHERE teacher_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                
                $pdo->commit();
                $message = 'Все темы успешно удалены';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при очистке: ' . $e->getMessage();
            }
            break;
            
        case 'import_csv':
            try {
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла');
                }
                
                $parentTopicId = $_POST['parent_topic_id'] ?: null;
                
                // Проверяем существование родительской темы, если выбрана
                if ($parentTopicId) {
                    $stmt = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([$parentTopicId, $_SESSION['user_id']]);
                    if (!$stmt->fetch()) {
                        throw new Exception('Выбранная родительская тема не найдена');
                    }
                }
                
                $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
                if (!$file) {
                    throw new Exception('Не удалось открыть файл');
                }
                
                // Определяем разделитель
                $firstLine = fgets($file);
                rewind($file);
                
                $delimiter = ',';
                if (strpos($firstLine, ';') !== false) {
                    $delimiter = ';';
                } elseif (strpos($firstLine, "\t") !== false) {
                    $delimiter = "\t";
                }
                
                $headers = fgetcsv($file, 0, $delimiter);
                if (!$headers) {
                    throw new Exception('Не удалось прочитать заголовки CSV');
                }
                
                // Нормализуем заголовки
                $headers = array_map('trim', array_map('strtolower', $headers));
                
                // Определяем индексы колонок
                $nameIndex = array_search('название', $headers);
                if ($nameIndex === false) $nameIndex = array_search('name', $headers);
                if ($nameIndex === false) $nameIndex = array_search('тема', $headers);
                if ($nameIndex === false) $nameIndex = array_search('topic', $headers);
                
                $colorIndex = array_search('цвет', $headers);
                if ($colorIndex === false) $colorIndex = array_search('color', $headers);
                
                $descriptionIndex = array_search('описание', $headers);
                if ($descriptionIndex === false) $descriptionIndex = array_search('description', $headers);
                
                $parentIndex = array_search('родитель', $headers);
                if ($parentIndex === false) $parentIndex = array_search('parent', $headers);
                
                if ($nameIndex === false) {
                    throw new Exception('В CSV файле не найдена колонка с названием темы');
                }
                
                $pdo->beginTransaction();
                
                // Создаем временное отображение названий на ID
                $topicMap = [];
                $importedTopics = [];
                $row = 1;
                
                while (($data = fgetcsv($file, 0, $delimiter)) !== false) {
                    $row++;
                    
                    if (count($data) < count($headers)) {
                        continue; // Пропускаем некорректные строки
                    }
                    
                    $name = trim($data[$nameIndex] ?? '');
                    if (empty($name)) {
                        continue; // Пропускаем строки без названия
                    }
                    
                    $color = '#808080';
                    if ($colorIndex !== false && !empty($data[$colorIndex])) {
                        $color = trim($data[$colorIndex]);
                        // Проверяем, что цвет в правильном формате
                        if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
                            $color = '#808080';
                        }
                    }
                    
                    $description = $descriptionIndex !== false ? trim($data[$descriptionIndex] ?? '') : '';
                    
                    // Определяем родителя
                    $parentName = null;
                    if ($parentIndex !== false && !empty($data[$parentIndex])) {
                        $parentName = trim($data[$parentIndex]);
                    }
                    
                    $importedTopics[] = [
                        'name' => $name,
                        'color' => $color,
                        'description' => $description,
                        'parent_name' => $parentName
                    ];
                }
                
                fclose($file);
                
                // Функция для рекурсивного импорта
                function importTopicWithChildren($pdo, $teacherId, $topics, $parentId = null, &$map) {
                    foreach ($topics as $topic) {
                        // Проверяем, не импортировали ли уже такую тему
                        $key = $topic['name'] . '|' . ($parentId ?: 'root');
                        if (isset($map[$key])) {
                            continue;
                        }
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO topics (teacher_id, name, parent_id, color, description)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $teacherId,
                            $topic['name'],
                            $parentId,
                            $topic['color'],
                            $topic['description'] ?: null
                        ]);
                        
                        $newId = $pdo->lastInsertId();
                        $map[$key] = $newId;
                        
                        // Ищем дочерние темы для этого родителя
                        $children = array_filter($topics, function($t) use ($topic) {
                            return isset($t['parent_name']) && $t['parent_name'] === $topic['name'];
                        });
                        
                        if (!empty($children)) {
                            importTopicWithChildren($pdo, $teacherId, $children, $newId, $map);
                        }
                    }
                }
                
                // Начинаем импорт с указанной родительской темы
                $rootTopics = $parentTopicId ? 
                    array_filter($importedTopics, function($t) { return empty($t['parent_name']); }) :
                    $importedTopics;
                
                importTopicWithChildren($pdo, $_SESSION['user_id'], $rootTopics, $parentTopicId, $topicMap);
                
                $pdo->commit();
                $message = 'Темы успешно импортированы из CSV';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте CSV: ' . $e->getMessage();
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
                
                // Функция для рекурсивного импорта тем
                function importTopics($pdo, $topics, $parentId = null, $teacherId) {
                    foreach ($topics as $topic) {
                        if (!isset($topic['name'])) {
                            continue;
                        }
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO topics (teacher_id, name, parent_id, color, description)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $teacherId,
                            $topic['name'],
                            $parentId,
                            $topic['color'] ?? '#808080',
                            $topic['description'] ?? null
                        ]);
                        
                        $newId = $pdo->lastInsertId();
                        
                        // Импортируем ссылки
                        if (isset($topic['links']) && is_array($topic['links'])) {
                            $stmt2 = $pdo->prepare("
                                INSERT INTO topic_links (topic_id, url, title, comment)
                                VALUES (?, ?, ?, ?)
                            ");
                            foreach ($topic['links'] as $link) {
                                if (isset($link['url'])) {
                                    $stmt2->execute([
                                        $newId,
                                        $link['url'],
                                        $link['title'] ?? null,
                                        $link['comment'] ?? null
                                    ]);
                                }
                            }
                        }
                        
                        if (isset($topic['children']) && is_array($topic['children'])) {
                            importTopics($pdo, $topic['children'], $newId, $teacherId);
                        }
                    }
                }
                
                importTopics($pdo, $data, null, $_SESSION['user_id']);
                
                $pdo->commit();
                $message = 'Темы успешно импортированы из JSON';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте JSON: ' . $e->getMessage();
            }
            break;
            
        case 'export_json':
            try {
                // Получаем все темы пользователя
                $stmt = $pdo->prepare("
                    SELECT id, name, parent_id, color, description 
                    FROM topics 
                    WHERE teacher_id = ?
                    ORDER BY parent_id IS NULL DESC, name
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $allTopics = $stmt->fetchAll();
                
                // Получаем ссылки для тем
                $links = [];
                if (!empty($allTopics)) {
                    $topicIds = array_column($allTopics, 'id');
                    $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
                    
                    $stmt = $pdo->prepare("
                        SELECT * FROM topic_links 
                        WHERE topic_id IN ($placeholders)
                        ORDER BY topic_id, created_at
                    ");
                    $stmt->execute($topicIds);
                    
                    while ($link = $stmt->fetch()) {
                        if (!isset($links[$link['topic_id']])) {
                            $links[$link['topic_id']] = [];
                        }
                        $links[$link['topic_id']][] = [
                            'url' => $link['url'],
                            'title' => $link['title'],
                            'comment' => $link['comment']
                        ];
                    }
                }
                
                // Строим дерево
                $topicsById = [];
                foreach ($allTopics as $topic) {
                    $topicsById[$topic['id']] = [
                        'name' => $topic['name'],
                        'color' => $topic['color'],
                        'description' => $topic['description'],
                        'links' => $links[$topic['id']] ?? [],
                        'children' => []
                    ];
                }
                
                $rootTopics = [];
                foreach ($allTopics as $topic) {
                    if ($topic['parent_id'] && isset($topicsById[$topic['parent_id']])) {
                        $topicsById[$topic['parent_id']]['children'][] = &$topicsById[$topic['id']];
                    } else {
                        $rootTopics[] = &$topicsById[$topic['id']];
                    }
                }
                
                // Очищаем от id
                $cleanTopics = array_values($rootTopics);
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="topics_export_' . date('Y-m-d') . '.json"');
                echo json_encode($cleanTopics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            } catch (Exception $e) {
                $error = 'Ошибка при экспорте: ' . $e->getMessage();
            }
            break;
    }
}

// Получение всех тем для дерева
$stmt = $pdo->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM topics WHERE parent_id = t.id) as children_count,
           (SELECT COUNT(*) FROM tasks WHERE topic_id = t.id) as tasks_count,
           (SELECT COUNT(*) FROM lesson_topics lt 
            JOIN lessons l ON lt.lesson_id = l.id 
            JOIN lesson_plans lp ON l.lesson_plan_id = lp.id 
            WHERE lt.topic_id = t.id AND lp.teacher_id = ?) as lessons_count,
           (SELECT COUNT(*) FROM topic_links WHERE topic_id = t.id) as links_count
    FROM topics t
    WHERE t.teacher_id = ?
    ORDER BY t.parent_id IS NULL DESC, t.name
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$allTopics = $stmt->fetchAll();

// Строим дерево для отображения
$topicsTree = [];
$topicsById = [];

foreach ($allTopics as $topic) {
    $topicsById[$topic['id']] = $topic;
}

foreach ($allTopics as $topic) {
    if ($topic['parent_id'] && isset($topicsById[$topic['parent_id']])) {
        if (!isset($topicsById[$topic['parent_id']]['children'])) {
            $topicsById[$topic['parent_id']]['children'] = [];
        }
        $topicsById[$topic['parent_id']]['children'][] = $topic;
    } else {
        $topicsTree[] = $topic;
    }
}

// Получаем темы для выпадающих списков
$stmt = $pdo->prepare("
    SELECT id, name, color 
    FROM topics 
    WHERE teacher_id = ?
    ORDER BY name
");
$stmt->execute([$_SESSION['user_id']]);
$parentTopics = $stmt->fetchAll();

// Получаем тему для редактирования
$editTopic = null;
$editLinks = [];

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM topics WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$_GET['edit'], $_SESSION['user_id']]);
    $editTopic = $stmt->fetch();
    
    if ($editTopic) {
        $stmt = $pdo->prepare("SELECT * FROM topic_links WHERE topic_id = ? ORDER BY created_at");
        $stmt->execute([$editTopic['id']]);
        $editLinks = $stmt->fetchAll();
    }
}

// Получаем тему для просмотра
$viewTopic = null;
$viewChildren = [];
$viewLinks = [];
$viewTasks = [];
$viewLessons = [];

if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM topics WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$_GET['view'], $_SESSION['user_id']]);
    $viewTopic = $stmt->fetch();
    
    if ($viewTopic) {
        // Получаем дочерние темы
        $stmt = $pdo->prepare("
            SELECT * FROM topics 
            WHERE parent_id = ? AND teacher_id = ?
            ORDER BY name
        ");
        $stmt->execute([$viewTopic['id'], $_SESSION['user_id']]);
        $viewChildren = $stmt->fetchAll();
        
        // Получаем ссылки
        $stmt = $pdo->prepare("SELECT * FROM topic_links WHERE topic_id = ? ORDER BY created_at");
        $stmt->execute([$viewTopic['id']]);
        $viewLinks = $stmt->fetchAll();
        
        // Получаем задания с этой темой
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM task_tags WHERE task_id = t.id) as tags_count
            FROM tasks t
            WHERE t.topic_id = ? AND t.teacher_id = ?
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$viewTopic['id'], $_SESSION['user_id']]);
        $viewTasks = $stmt->fetchAll();
        
        // Получаем занятия с этой темой
        $stmt = $pdo->prepare("
            SELECT l.*, lp.name as plan_name, 
                   CONCAT(u.first_name, ' ', u.last_name) as student_name
            FROM lessons l
            JOIN lesson_topics lt ON l.id = lt.lesson_id
            JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
            LEFT JOIN students s ON lp.student_id = s.id
            LEFT JOIN users u ON s.user_id = u.id
            WHERE lt.topic_id = ? AND lp.teacher_id = ?
            ORDER BY l.date DESC
            LIMIT 10
        ");
        $stmt->execute([$viewTopic['id'], $_SESSION['user_id']]);
        $viewLessons = $stmt->fetchAll();
    }
}

// Функция для рекурсивного отображения дерева тем
function renderTopicTree($topics, $level = 0) {
    foreach ($topics as $topic) {
        $hasChildren = isset($topic['children']) && !empty($topic['children']);
        $padding = $level * 30;
        ?>
        <div class="topic-tree-item" style="margin-left: <?php echo $padding; ?>px;" data-id="<?php echo $topic['id']; ?>">
            <div class="topic-item d-flex align-items-center p-2 <?php echo $hasChildren ? 'has-children' : ''; ?>" 
                 style="border-left: 4px solid <?php echo htmlspecialchars($topic['color']); ?>; background: #f8f9fa; margin-bottom: 2px; border-radius: 0 8px 8px 0;">
                
                <div class="topic-expand me-2" style="width: 20px;">
                    <?php if ($hasChildren): ?>
                        <i class="bi bi-chevron-down toggle-children" style="cursor: pointer;"></i>
                    <?php endif; ?>
                </div>
                
                <div class="topic-color-indicator me-2" style="width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($topic['color']); ?>;"></div>
                
                <div class="topic-name flex-grow-1">
                    <strong><?php echo htmlspecialchars($topic['name']); ?></strong>
                    <?php if ($topic['description']): ?>
                        <small class="text-muted ms-2"><?php echo htmlspecialchars(substr($topic['description'], 0, 50)) . (strlen($topic['description']) > 50 ? '...' : ''); ?></small>
                    <?php endif; ?>
                </div>
                
                <div class="topic-stats me-3">
                    <?php if ($topic['links_count'] > 0): ?>
                        <span class="badge bg-info me-1" title="Ссылок">
                            <i class="bi bi-link"></i> <?php echo $topic['links_count']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($topic['tasks_count'] > 0): ?>
                        <span class="badge bg-warning me-1" title="Используется в заданиях">
                            <i class="bi bi-journal"></i> <?php echo $topic['tasks_count']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($topic['lessons_count'] > 0): ?>
                        <span class="badge bg-success me-1" title="Используется в занятиях">
                            <i class="bi bi-calendar"></i> <?php echo $topic['lessons_count']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($topic['children_count'] > 0): ?>
                        <span class="badge bg-secondary" title="Дочерних тем">
                            <i class="bi bi-diagram-2"></i> <?php echo $topic['children_count']; ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="topic-actions">
                    <a href="?view=<?php echo $topic['id']; ?>" class="btn btn-sm btn-outline-info btn-action" title="Просмотр">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="?edit=<?php echo $topic['id']; ?>" class="btn btn-sm btn-outline-primary btn-action" title="Редактировать">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteTopic(<?php echo $topic['id']; ?>)" title="Удалить">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            
            <?php if ($hasChildren): ?>
                <div class="topic-children">
                    <?php renderTopicTree($topic['children'], $level + 1); ?>
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
    <title>Банк тем - Репетитор 2029</title>
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
        .topics-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        .topic-tree-item {
            position: relative;
        }
        .topic-item {
            transition: all 0.2s;
            cursor: pointer;
        }
        .topic-item:hover {
            background: #e9ecef !important;
        }
        .btn-action {
            padding: 2px 8px;
            margin: 0 2px;
            font-size: 12px;
        }
        .topic-stats .badge {
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
        .link-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid #17a2b8;
        }
        .usage-item {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        .usage-item:last-child {
            border-bottom: none;
        }
        .csv-options {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
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
                <h2 class="mb-0"><i class="bi bi-bookmarks me-2"></i>Банк тем</h2>
                <p class="text-muted mb-0">Управление иерархическими темами и ссылками на ресурсы</p>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="bi bi-plus-circle"></i> Добавить тему
                </button>
                <button class="btn btn-outline-danger" onclick="clearAllTopics()">
                    <i class="bi bi-trash"></i> Очистить все
                </button>
                <button class="btn btn-outline-success" onclick="openImportModal()">
                    <i class="bi bi-upload"></i> Импорт
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

        <?php if ($viewTopic): ?>
        <!-- Просмотр темы -->
        <div class="row">
            <div class="col-md-4">
                <div class="view-card">
                    <div class="d-flex align-items-center mb-3">
                        <div style="width: 40px; height: 40px; border-radius: 8px; background: <?php echo htmlspecialchars($viewTopic['color']); ?>; margin-right: 15px;"></div>
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($viewTopic['name']); ?></h4>
                            <p class="text-muted mb-0">
                                Создана: <?php echo date('d.m.Y H:i', strtotime($viewTopic['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if ($viewTopic['description']): ?>
                        <div class="mb-3">
                            <strong>Описание:</strong>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($viewTopic['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($viewChildren)): ?>
                        <div class="mb-3">
                            <strong>Дочерние темы:</strong>
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
                        <a href="?edit=<?php echo $viewTopic['id']; ?>" class="btn btn-primary">
                            <i class="bi bi-pencil"></i> Редактировать
                        </a>
                        <a href="topics.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Назад
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="view-card">
                    <ul class="nav nav-tabs mb-3" id="usageTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="links-tab" data-bs-toggle="tab" data-bs-target="#links" type="button" role="tab">
                                <i class="bi bi-link"></i> Ссылки (<?php echo count($viewLinks); ?>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tasks-tab" data-bs-toggle="tab" data-bs-target="#tasks" type="button" role="tab">
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
                        <!-- Ссылки -->
                        <div class="tab-pane fade show active" id="links" role="tabpanel">
                            <form method="POST" action="" class="mb-3">
                                <input type="hidden" name="action" value="add_link">
                                <input type="hidden" name="topic_id" value="<?php echo $viewTopic['id']; ?>">
                                <div class="input-group mb-2">
                                    <input type="url" class="form-control" name="url" placeholder="https://..." required>
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-plus"></i> Добавить
                                    </button>
                                </div>
                                <input type="text" class="form-control mb-2" name="title" placeholder="Название ссылки (необязательно)">
                                <textarea class="form-control" name="comment" rows="2" placeholder="Комментарий к ссылке (необязательно)"></textarea>
                            </form>
                            
                            <?php if (empty($viewLinks)): ?>
                                <p class="text-muted text-center py-4">Нет ссылок</p>
                            <?php else: ?>
                                <?php foreach ($viewLinks as $link): ?>
                                    <div class="link-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" class="fw-bold">
                                                    <?php echo htmlspecialchars($link['title'] ?: $link['url']); ?>
                                                </a>
                                                <?php if ($link['title']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($link['url']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($link['comment']): ?>
                                                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($link['comment'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" action="" onsubmit="return confirm('Удалить ссылку?')">
                                                <input type="hidden" name="action" value="delete_link">
                                                <input type="hidden" name="link_id" value="<?php echo $link['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Задания -->
                        <div class="tab-pane fade" id="tasks" role="tabpanel">
                            <?php if (empty($viewTasks)): ?>
                                <p class="text-muted text-center py-4">Тема не используется в заданиях</p>
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
                                        <?php if ($task['difficulty'] !== null): ?>
                                            <small class="text-muted d-block mt-1">
                                                Сложность: <?php echo $task['difficulty']; ?>/10
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Занятия -->
                        <div class="tab-pane fade" id="lessons" role="tabpanel">
                            <?php if (empty($viewLessons)): ?>
                                <p class="text-muted text-center py-4">Тема не используется в занятиях</p>
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
        
        <!-- Дерево тем -->
        <div class="topics-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Иерархия тем</h5>
                <div class="text-muted">
                    <small><i class="bi bi-info-circle"></i> Всего тем: <?php echo count($allTopics); ?></small>
                </div>
            </div>
            
            <?php if (empty($topicsTree)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-bookmarks display-1 text-muted"></i>
                    <h4 class="mt-3">Нет тем</h4>
                    <p class="text-muted">Нажмите "Добавить тему", чтобы создать первую тему</p>
                </div>
            <?php else: ?>
                <div class="topic-tree">
                    <?php renderTopicTree($topicsTree); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно добавления/редактирования темы -->
    <div class="modal fade" id="topicModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Добавление темы</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="topicForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add_topic">
                        <input type="hidden" name="topic_id" id="topicId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Название темы <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="topicName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Родительская тема</label>
                            <select class="form-select" name="parent_id" id="parentTopic">
                                <option value="">-- Нет родительской темы --</option>
                                <?php foreach ($parentTopics as $topic): ?>
                                    <option value="<?php echo $topic['id']; ?>" style="color: <?php echo htmlspecialchars($topic['color']); ?>;">
                                        <?php echo htmlspecialchars($topic['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Цвет темы</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color me-2" name="color" id="topicColor" value="#808080" style="width: 60px; height: 40px;">
                                <input type="text" class="form-control" id="colorHex" value="#808080" maxlength="7">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" id="topicDescription" rows="3"></textarea>
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

    <!-- Модальное окно импорта -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Импорт тем</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" id="importTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="csv-tab" data-bs-toggle="tab" data-bs-target="#csv" type="button" role="tab">
                                CSV
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="json-tab" data-bs-toggle="tab" data-bs-target="#json" type="button" role="tab">
                                JSON
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="importTabsContent">
                        <!-- CSV импорт -->
                        <div class="tab-pane fade show active" id="csv" role="tabpanel">
                            <form method="POST" action="" enctype="multipart/form-data" id="csvImportForm">
                                <input type="hidden" name="action" value="import_csv">
                                
                                <div class="mb-3">
                                    <label class="form-label">Родительская тема для импорта</label>
                                    <select class="form-select" name="parent_topic_id">
                                        <option value="">-- Корневой уровень --</option>
                                        <?php foreach ($parentTopics as $topic): ?>
                                            <option value="<?php echo $topic['id']; ?>">
                                                <?php echo htmlspecialchars($topic['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Все импортированные темы будут добавлены как дочерние к выбранной теме</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">CSV файл</label>
                                    <input type="file" class="form-control" name="csv_file" accept=".csv,.txt" required>
                                </div>
                                
                                <div class="csv-options">
                                    <h6>Формат CSV файла:</h6>
                                    <p class="small mb-2">Поддерживаемые колонки (названия на русском или английском):</p>
                                    <ul class="small">
                                        <li><strong>Название</strong> (обязательно) - название темы</li>
                                        <li><strong>Цвет</strong> (опционально) - HEX код цвета (например, #FF0000)</li>
                                        <li><strong>Описание</strong> (опционально) - описание темы</li>
                                        <li><strong>Родитель</strong> (опционально) - название родительской темы</li>
                                    </ul>
                                    <p class="small mb-0">
                                        <i class="bi bi-info-circle"></i> Разделитель определяется автоматически (запятая, точка с запятой или табуляция)
                                    </p>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100 mt-3">
                                    <i class="bi bi-upload"></i> Импортировать CSV
                                </button>
                            </form>
                        </div>
                        
                        <!-- JSON импорт -->
                        <div class="tab-pane fade" id="json" role="tabpanel">
                            <form method="POST" action="" enctype="multipart/form-data" id="jsonImportForm">
                                <input type="hidden" name="action" value="import_json">
                                
                                <div class="mb-3">
                                    <label class="form-label">JSON файл</label>
                                    <input type="file" class="form-control" name="json_file" accept=".json" required>
                                </div>
                                
                                <div class="csv-options">
                                    <h6>Формат JSON файла:</h6>
                                    <p class="small mb-2">Ожидается массив объектов с полями:</p>
                                    <pre class="small bg-light p-2 rounded">
[
  {
    "name": "Тема 1",
    "color": "#FF0000",
    "description": "Описание",
    "links": [
      {
        "url": "https://...",
        "title": "Название",
        "comment": "Комментарий"
      }
    ],
    "children": [...]
  }
]</pre>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100 mt-3">
                                    <i class="bi bi-upload"></i> Импортировать JSON
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Инициализация
        let topicModal = null;
        let importModal = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            topicModal = new bootstrap.Modal(document.getElementById('topicModal'));
            importModal = new bootstrap.Modal(document.getElementById('importModal'));
            
            // Синхронизация color picker и текстового поля
            document.getElementById('topicColor').addEventListener('input', function(e) {
                document.getElementById('colorHex').value = e.target.value;
            });
            
            document.getElementById('colorHex').addEventListener('input', function(e) {
                let value = e.target.value;
                if (/^#[0-9A-F]{6}$/i.test(value)) {
                    document.getElementById('topicColor').value = value;
                }
            });
            
            // Обработка сворачивания/разворачивания дерева
            document.querySelectorAll('.toggle-children').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const item = this.closest('.topic-tree-item');
                    const children = item.querySelector('.topic-children');
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
            
            <?php if ($editTopic): ?>
            openEditModal(<?php echo json_encode([
                'id' => $editTopic['id'],
                'name' => $editTopic['name'],
                'parent_id' => $editTopic['parent_id'],
                'color' => $editTopic['color'],
                'description' => $editTopic['description']
            ]); ?>);
            <?php endif; ?>
        });
        
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Добавление темы';
            document.getElementById('formAction').value = 'add_topic';
            document.getElementById('topicId').value = '';
            document.getElementById('topicName').value = '';
            document.getElementById('parentTopic').value = '';
            document.getElementById('topicColor').value = '#808080';
            document.getElementById('colorHex').value = '#808080';
            document.getElementById('topicDescription').value = '';
            topicModal.show();
        }
        
        function openEditModal(data) {
            document.getElementById('modalTitle').textContent = 'Редактирование темы';
            document.getElementById('formAction').value = 'edit_topic';
            document.getElementById('topicId').value = data.id;
            document.getElementById('topicName').value = data.name || '';
            document.getElementById('parentTopic').value = data.parent_id || '';
            document.getElementById('topicColor').value = data.color || '#808080';
            document.getElementById('colorHex').value = data.color || '#808080';
            document.getElementById('topicDescription').value = data.description || '';
            topicModal.show();
        }
        
        function openImportModal() {
            importModal.show();
        }
        
        function deleteTopic(topicId) {
            if (confirm('Вы уверены, что хотите удалить эту тему?\n\nВнимание: тема не должна иметь дочерних тем и использоваться в заданиях или занятиях.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_topic">
                    <input type="hidden" name="topic_id" value="${topicId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function clearAllTopics() {
            if (confirm('ВНИМАНИЕ! Вы уверены, что хотите удалить ВСЕ темы?\n\nЭто действие нельзя отменить. Темы не должны использоваться в заданиях или занятиях.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="clear_all">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>