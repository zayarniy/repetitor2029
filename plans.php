<?php
// plans.php
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

// Создание нового плана
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create_plan':
            try {
                $name = trim($_POST['name'] ?? '');
                $studentId = $_POST['student_id'] ?: null;
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    throw new Exception('Название плана обязательно');
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO lesson_plans (teacher_id, student_id, name, description)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $studentId,
                    $name,
                    $description ?: null
                ]);
                
                $planId = $pdo->lastInsertId();
                
                // Добавляем комментарий о создании
                if (!empty($_POST['initial_comment'])) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lesson_plan_comments (lesson_plan_id, user_id, comment)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$planId, $_SESSION['user_id'], trim($_POST['initial_comment'])]);
                }
                
                $message = 'План обучения успешно создан';
                
            } catch (Exception $e) {
                $error = 'Ошибка при создании плана: ' . $e->getMessage();
            }
            break;
            
        case 'edit_plan':
            try {
                $planId = $_POST['plan_id'] ?? 0;
                $name = trim($_POST['name'] ?? '');
                $studentId = $_POST['student_id'] ?: null;
                $description = trim($_POST['description'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                // Проверяем принадлежность плана
                $stmt = $pdo->prepare("SELECT id FROM lesson_plans WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$planId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('План не найден');
                }
                
                $stmt = $pdo->prepare("
                    UPDATE lesson_plans 
                    SET name = ?, student_id = ?, description = ?, is_active = ?
                    WHERE id = ? AND teacher_id = ?
                ");
                $stmt->execute([
                    $name,
                    $studentId,
                    $description ?: null,
                    $isActive,
                    $planId,
                    $_SESSION['user_id']
                ]);
                
                $message = 'План успешно обновлен';
                
            } catch (Exception $e) {
                $error = 'Ошибка при обновлении плана: ' . $e->getMessage();
            }
            break;
            
        case 'delete_plan':
            try {
                $planId = $_POST['plan_id'] ?? 0;
                
                // Проверяем, есть ли уроки в плане
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lessons WHERE lesson_plan_id = ?");
                $stmt->execute([$planId]);
                $lessons = $stmt->fetch();
                
                if ($lessons['count'] > 0) {
                    throw new Exception('Нельзя удалить план, в котором есть уроки');
                }
                
                $stmt = $pdo->prepare("DELETE FROM lesson_plans WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$planId, $_SESSION['user_id']]);
                
                $message = 'План успешно удален';
                
            } catch (Exception $e) {
                $error = 'Ошибка при удалении плана: ' . $e->getMessage();
            }
            break;
            
        case 'copy_plan':
            try {
                $planId = $_POST['plan_id'] ?? 0;
                $newName = trim($_POST['new_name'] ?? '');
                
                if (empty($newName)) {
                    throw new Exception('Название для копии обязательно');
                }
                
                $pdo->beginTransaction();
                
                // Копируем план
                $stmt = $pdo->prepare("
                    INSERT INTO lesson_plans (teacher_id, student_id, name, description, is_active)
                    SELECT teacher_id, student_id, ?, description, is_active
                    FROM lesson_plans
                    WHERE id = ? AND teacher_id = ?
                ");
                $stmt->execute([$newName, $planId, $_SESSION['user_id']]);
                $newPlanId = $pdo->lastInsertId();
                
                // Копируем уроки
                $stmt = $pdo->prepare("
                    SELECT * FROM lessons 
                    WHERE lesson_plan_id = ?
                    ORDER BY date, time
                ");
                $stmt->execute([$planId]);
                $lessons = $stmt->fetchAll();
                
                foreach ($lessons as $lesson) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lessons (
                            lesson_plan_id, date, time, title, grade_for_lesson,
                            grade_for_homework, homework_comment, lesson_comment,
                            external_link, link_comment, is_completed, sort_order
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newPlanId,
                        $lesson['date'],
                        $lesson['time'],
                        $lesson['title'],
                        $lesson['grade_for_lesson'],
                        $lesson['grade_for_homework'],
                        $lesson['homework_comment'],
                        $lesson['lesson_comment'],
                        $lesson['external_link'],
                        $lesson['link_comment'],
                        0, // Новые уроки не завершены
                        $lesson['sort_order']
                    ]);
                    
                    $newLessonId = $pdo->lastInsertId();
                    
                    // Копируем темы урока
                    $stmt2 = $pdo->prepare("
                        INSERT INTO lesson_topics (lesson_id, topic_id)
                        SELECT ?, topic_id FROM lesson_topics WHERE lesson_id = ?
                    ");
                    $stmt2->execute([$newLessonId, $lesson['id']]);
                    
                    // Копируем домашние задания
                    $stmt2 = $pdo->prepare("
                        INSERT INTO lesson_homework (lesson_id, task_id, custom_description)
                        SELECT ?, task_id, custom_description FROM lesson_homework WHERE lesson_id = ?
                    ");
                    $stmt2->execute([$newLessonId, $lesson['id']]);
                    
                    // Копируем метки
                    $stmt2 = $pdo->prepare("
                        INSERT INTO lesson_tags (lesson_id, tag_id)
                        SELECT ?, tag_id FROM lesson_tags WHERE lesson_id = ?
                    ");
                    $stmt2->execute([$newLessonId, $lesson['id']]);
                }
                
                $pdo->commit();
                $message = 'План успешно скопирован';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при копировании плана: ' . $e->getMessage();
            }
            break;
            
        case 'import_csv_dates':
            try {
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла');
                }
                
                $planId = $_POST['plan_id'] ?? 0;
                
                // Проверяем план
                $stmt = $pdo->prepare("SELECT id FROM lesson_plans WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$planId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    throw new Exception('План не найден');
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
                $dateIndex = array_search('дата', $headers);
                if ($dateIndex === false) $dateIndex = array_search('date', $headers);
                if ($dateIndex === false) $dateIndex = array_search('день', $headers);
                if ($dateIndex === false) $dateIndex = array_search('day', $headers);
                
                $timeIndex = array_search('время', $headers);
                if ($timeIndex === false) $timeIndex = array_search('time', $headers);
                
                $titleIndex = array_search('название', $headers);
                if ($titleIndex === false) $titleIndex = array_search('title', $headers);
                
                if ($dateIndex === false) {
                    throw new Exception('В CSV файле не найдена колонка с датой');
                }
                
                $pdo->beginTransaction();
                
                // Получаем текущий максимальный sort_order
                $stmt = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM lessons WHERE lesson_plan_id = ?");
                $stmt->execute([$planId]);
                $maxOrder = $stmt->fetch()['max_order'] ?? 0;
                
                $row = 1;
                $imported = 0;
                
                while (($data = fgetcsv($file, 0, $delimiter)) !== false) {
                    $row++;
                    
                    if (count($data) < count($headers)) {
                        continue;
                    }
                    
                    $dateStr = trim($data[$dateIndex] ?? '');
                    if (empty($dateStr)) {
                        continue;
                    }
                    
                    // Парсим дату (поддерживаем разные форматы)
                    $date = null;
                    $formats = ['d.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.y', 'd/m/y'];
                    foreach ($formats as $format) {
                        $d = DateTime::createFromFormat($format, $dateStr);
                        if ($d) {
                            $date = $d->format('Y-m-d');
                            break;
                        }
                    }
                    
                    if (!$date) {
                        continue; // Пропускаем некорректные даты
                    }
                    
                    $time = null;
                    if ($timeIndex !== false && !empty($data[$timeIndex])) {
                        $timeStr = trim($data[$timeIndex]);
                        $timeFormats = ['H:i', 'H:i:s', 'G:i', 'G:i:s'];
                        foreach ($timeFormats as $format) {
                            $t = DateTime::createFromFormat($format, $timeStr);
                            if ($t) {
                                $time = $t->format('H:i:s');
                                break;
                            }
                        }
                    }
                    
                    $title = $titleIndex !== false ? trim($data[$titleIndex] ?? '') : '';
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO lessons (lesson_plan_id, date, time, title, sort_order)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $planId,
                        $date,
                        $time,
                        $title ?: null,
                        ++$maxOrder
                    ]);
                    
                    $imported++;
                }
                
                fclose($file);
                
                $pdo->commit();
                $message = "Импортировано $imported дат";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте дат: ' . $e->getMessage();
            }
            break;
            
        case 'import_plan_csv':
            try {
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла');
                }
                
                $planName = trim($_POST['plan_name'] ?? '');
                $studentId = $_POST['student_id'] ?: null;
                
                if (empty($planName)) {
                    throw new Exception('Название плана обязательно');
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
                $dateIndex = array_search('дата', $headers);
                if ($dateIndex === false) $dateIndex = array_search('date', $headers);
                
                $timeIndex = array_search('время', $headers);
                if ($timeIndex === false) $timeIndex = array_search('time', $headers);
                
                $titleIndex = array_search('название', $headers);
                if ($titleIndex === false) $titleIndex = array_search('title', $headers);
                
                $topicIndex = array_search('тема', $headers);
                if ($topicIndex === false) $topicIndex = array_search('topic', $headers);
                
                $homeworkIndex = array_search('домашнее задание', $headers);
                if ($homeworkIndex === false) $homeworkIndex = array_search('homework', $headers);
                
                $linkIndex = array_search('ссылка', $headers);
                if ($linkIndex === false) $linkIndex = array_search('link', $headers);
                
                $commentIndex = array_search('комментарий', $headers);
                if ($commentIndex === false) $commentIndex = array_search('comment', $headers);
                
                $pdo->beginTransaction();
                
                // Создаем план
                $stmt = $pdo->prepare("
                    INSERT INTO lesson_plans (teacher_id, student_id, name)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $studentId, $planName]);
                $planId = $pdo->lastInsertId();
                
                // Получаем темы для сопоставления
                $stmt = $pdo->prepare("SELECT id, name FROM topics WHERE teacher_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $topics = [];
                while ($row = $stmt->fetch()) {
                    $topics[strtolower(trim($row['name']))] = $row['id'];
                }
                
                $sortOrder = 0;
                $imported = 0;
                
                while (($data = fgetcsv($file, 0, $delimiter)) !== false) {
                    if (count($data) < count($headers)) {
                        continue;
                    }
                    
                    $dateStr = trim($data[$dateIndex] ?? '');
                    if (empty($dateStr)) {
                        continue;
                    }
                    
                    // Парсим дату
                    $date = null;
                    $formats = ['d.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.y', 'd/m/y'];
                    foreach ($formats as $format) {
                        $d = DateTime::createFromFormat($format, $dateStr);
                        if ($d) {
                            $date = $d->format('Y-m-d');
                            break;
                        }
                    }
                    
                    if (!$date) {
                        continue;
                    }
                    
                    $time = null;
                    if ($timeIndex !== false && !empty($data[$timeIndex])) {
                        $timeStr = trim($data[$timeIndex]);
                        $timeFormats = ['H:i', 'H:i:s', 'G:i', 'G:i:s'];
                        foreach ($timeFormats as $format) {
                            $t = DateTime::createFromFormat($format, $timeStr);
                            if ($t) {
                                $time = $t->format('H:i:s');
                                break;
                            }
                        }
                    }
                    
                    $title = $titleIndex !== false ? trim($data[$titleIndex] ?? '') : '';
                    $homework = $homeworkIndex !== false ? trim($data[$homeworkIndex] ?? '') : '';
                    $link = $linkIndex !== false ? trim($data[$linkIndex] ?? '') : '';
                    $comment = $commentIndex !== false ? trim($data[$commentIndex] ?? '') : '';
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO lessons (
                            lesson_plan_id, date, time, title, lesson_comment,
                            external_link, homework_comment, sort_order
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $planId,
                        $date,
                        $time,
                        $title ?: null,
                        $comment ?: null,
                        $link ?: null,
                        $homework ?: null,
                        $sortOrder++
                    ]);
                    
                    $lessonId = $pdo->lastInsertId();
                    
                    // Добавляем тему, если указана
                    if ($topicIndex !== false && !empty($data[$topicIndex])) {
                        $topicName = strtolower(trim($data[$topicIndex]));
                        if (isset($topics[$topicName])) {
                            $stmt2 = $pdo->prepare("INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?, ?)");
                            $stmt2->execute([$lessonId, $topics[$topicName]]);
                        }
                    }
                    
                    $imported++;
                }
                
                fclose($file);
                
                $pdo->commit();
                $message = "План успешно импортирован. Добавлено $imported уроков";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте плана: ' . $e->getMessage();
            }
            break;
            
        case 'export_plan_csv':
            try {
                $planId = $_POST['plan_id'] ?? 0;
                
                // Получаем информацию о плане
                $stmt = $pdo->prepare("
                    SELECT lp.*, 
                           CONCAT(u.first_name, ' ', u.last_name) as student_name
                    FROM lesson_plans lp
                    LEFT JOIN students s ON lp.student_id = s.id
                    LEFT JOIN users u ON s.user_id = u.id
                    WHERE lp.id = ? AND lp.teacher_id = ?
                ");
                $stmt->execute([$planId, $_SESSION['user_id']]);
                $plan = $stmt->fetch();
                
                if (!$plan) {
                    throw new Exception('План не найден');
                }
                
                // Получаем уроки
                $stmt = $pdo->prepare("
                    SELECT l.*,
                           GROUP_CONCAT(DISTINCT t.name SEPARATOR '; ') as topics,
                           GROUP_CONCAT(DISTINCT tg.name SEPARATOR '; ') as tags,
                           lh.task_id,
                           lh.custom_description,
                           tk.title as task_title
                    FROM lessons l
                    LEFT JOIN lesson_topics lt ON l.id = lt.lesson_id
                    LEFT JOIN topics t ON lt.topic_id = t.id
                    LEFT JOIN lesson_tags ltg ON l.id = ltg.lesson_id
                    LEFT JOIN tags tg ON ltg.tag_id = tg.id
                    LEFT JOIN lesson_homework lh ON l.id = lh.lesson_id
                    LEFT JOIN tasks tk ON lh.task_id = tk.id
                    WHERE l.lesson_plan_id = ?
                    GROUP BY l.id
                    ORDER BY l.date, l.time
                ");
                $stmt->execute([$planId]);
                $lessons = $stmt->fetchAll();
                
                // Формируем CSV
                $filename = 'plan_' . preg_replace('/[^a-z0-9]/i', '_', $plan['name']) . '_' . date('Y-m-d') . '.csv';
                
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                
                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8
                
                // Заголовки
                fputcsv($output, [
                    'Дата',
                    'Время',
                    'Название',
                    'Темы',
                    'Домашнее задание',
                    'Ссылка',
                    'Комментарий',
                    'Оценка за урок',
                    'Оценка за ДЗ',
                    'Статус',
                    'Метки'
                ], ';');
                
                foreach ($lessons as $lesson) {
                    $homework = $lesson['task_title'] 
                        ? 'Задание: ' . $lesson['task_title']
                        : $lesson['custom_description'];
                    
                    fputcsv($output, [
                        date('d.m.Y', strtotime($lesson['date'])),
                        $lesson['time'] ? substr($lesson['time'], 0, 5) : '',
                        $lesson['title'],
                        $lesson['topics'],
                        $homework,
                        $lesson['external_link'],
                        $lesson['lesson_comment'],
                        $lesson['grade_for_lesson'] !== null ? $lesson['grade_for_lesson'] : '',
                        $lesson['grade_for_homework'] !== null ? $lesson['grade_for_homework'] : '',
                        $lesson['is_completed'] ? 'Проведен' : 'Запланирован',
                        $lesson['tags']
                    ], ';');
                }
                
                fclose($output);
                exit;
                
            } catch (Exception $e) {
                $error = 'Ошибка при экспорте: ' . $e->getMessage();
            }
            break;
            
        case 'export_plan_json':
            try {
                $planId = $_POST['plan_id'] ?? 0;
                
                // Получаем информацию о плане
                $stmt = $pdo->prepare("
                    SELECT lp.*, 
                           CONCAT(u.first_name, ' ', u.last_name) as student_name
                    FROM lesson_plans lp
                    LEFT JOIN students s ON lp.student_id = s.id
                    LEFT JOIN users u ON s.user_id = u.id
                    WHERE lp.id = ? AND lp.teacher_id = ?
                ");
                $stmt->execute([$planId, $_SESSION['user_id']]);
                $plan = $stmt->fetch();
                
                if (!$plan) {
                    throw new Exception('План не найден');
                }
                
                // Получаем уроки
                $stmt = $pdo->prepare("
                    SELECT l.*,
                           GROUP_CONCAT(DISTINCT t.id) as topic_ids,
                           GROUP_CONCAT(DISTINCT tg.id) as tag_ids,
                           lh.task_id,
                           lh.custom_description
                    FROM lessons l
                    LEFT JOIN lesson_topics lt ON l.id = lt.lesson_id
                    LEFT JOIN topics t ON lt.topic_id = t.id
                    LEFT JOIN lesson_tags ltg ON l.id = ltg.lesson_id
                    LEFT JOIN tags tg ON ltg.tag_id = tg.id
                    LEFT JOIN lesson_homework lh ON l.id = lh.lesson_id
                    WHERE l.lesson_plan_id = ?
                    GROUP BY l.id
                    ORDER BY l.date, l.time
                ");
                $stmt->execute([$planId]);
                $lessons = $stmt->fetchAll();
                
                $exportData = [
                    'plan' => [
                        'name' => $plan['name'],
                        'description' => $plan['description'],
                        'student_name' => $plan['student_name'],
                        'created_at' => $plan['created_at'],
                        'is_active' => (bool)$plan['is_active']
                    ],
                    'lessons' => []
                ];
                
                foreach ($lessons as $lesson) {
                    $lessonData = [
                        'date' => $lesson['date'],
                        'time' => $lesson['time'],
                        'title' => $lesson['title'],
                        'grade_for_lesson' => $lesson['grade_for_lesson'],
                        'grade_for_homework' => $lesson['grade_for_homework'],
                        'homework_comment' => $lesson['homework_comment'],
                        'lesson_comment' => $lesson['lesson_comment'],
                        'external_link' => $lesson['external_link'],
                        'link_comment' => $lesson['link_comment'],
                        'is_completed' => (bool)$lesson['is_completed'],
                        'topic_ids' => $lesson['topic_ids'] ? explode(',', $lesson['topic_ids']) : [],
                        'tag_ids' => $lesson['tag_ids'] ? explode(',', $lesson['tag_ids']) : [],
                        'homework' => [
                            'task_id' => $lesson['task_id'],
                            'custom_description' => $lesson['custom_description']
                        ]
                    ];
                    $exportData['lessons'][] = $lessonData;
                }
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="plan_' . preg_replace('/[^a-z0-9]/i', '_', $plan['name']) . '_' . date('Y-m-d') . '.json"');
                echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
                
            } catch (Exception $e) {
                $error = 'Ошибка при экспорте: ' . $e->getMessage();
            }
            break;
            
        case 'import_plan_json':
            try {
                if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла');
                }
                
                $json = file_get_contents($_FILES['json_file']['tmp_name']);
                $data = json_decode($json, true);
                
                if (!is_array($data) || !isset($data['plan']) || !isset($data['lessons'])) {
                    throw new Exception('Неверный формат JSON файла');
                }
                
                $studentId = $_POST['student_id'] ?: null;
                
                $pdo->beginTransaction();
                
                // Создаем план
                $stmt = $pdo->prepare("
                    INSERT INTO lesson_plans (teacher_id, student_id, name, description, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $studentId,
                    $data['plan']['name'],
                    $data['plan']['description'] ?? null,
                    $data['plan']['is_active'] ?? 1
                ]);
                
                $planId = $pdo->lastInsertId();
                
                // Добавляем уроки
                $sortOrder = 0;
                foreach ($data['lessons'] as $lessonData) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lessons (
                            lesson_plan_id, date, time, title, grade_for_lesson,
                            grade_for_homework, homework_comment, lesson_comment,
                            external_link, link_comment, is_completed, sort_order
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $planId,
                        $lessonData['date'],
                        $lessonData['time'] ?? null,
                        $lessonData['title'] ?? null,
                        $lessonData['grade_for_lesson'] ?? null,
                        $lessonData['grade_for_homework'] ?? null,
                        $lessonData['homework_comment'] ?? null,
                        $lessonData['lesson_comment'] ?? null,
                        $lessonData['external_link'] ?? null,
                        $lessonData['link_comment'] ?? null,
                        $lessonData['is_completed'] ?? 0,
                        $sortOrder++
                    ]);
                    
                    $lessonId = $pdo->lastInsertId();
                    
                    // Добавляем темы
                    if (!empty($lessonData['topic_ids'])) {
                        $stmt2 = $pdo->prepare("INSERT INTO lesson_topics (lesson_id, topic_id) VALUES (?, ?)");
                        foreach ($lessonData['topic_ids'] as $topicId) {
                            $stmt2->execute([$lessonId, $topicId]);
                        }
                    }
                    
                    // Добавляем метки
                    if (!empty($lessonData['tag_ids'])) {
                        $stmt2 = $pdo->prepare("INSERT INTO lesson_tags (lesson_id, tag_id) VALUES (?, ?)");
                        foreach ($lessonData['tag_ids'] as $tagId) {
                            $stmt2->execute([$lessonId, $tagId]);
                        }
                    }
                    
                    // Добавляем домашнее задание
                    if (!empty($lessonData['homework'])) {
                        $stmt2 = $pdo->prepare("
                            INSERT INTO lesson_homework (lesson_id, task_id, custom_description)
                            VALUES (?, ?, ?)
                        ");
                        $stmt2->execute([
                            $lessonId,
                            $lessonData['homework']['task_id'] ?? null,
                            $lessonData['homework']['custom_description'] ?? null
                        ]);
                    }
                }
                
                $pdo->commit();
                $message = 'План успешно импортирован из JSON';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при импорте JSON: ' . $e->getMessage();
            }
            break;
    }
}

// Получение списка планов
$stmt = $pdo->prepare("
    SELECT lp.*, 
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           (SELECT COUNT(*) FROM lessons WHERE lesson_plan_id = lp.id) as lessons_count,
           (SELECT COUNT(*) FROM lessons WHERE lesson_plan_id = lp.id AND is_completed = 1) as completed_count,
           (SELECT MIN(date) FROM lessons WHERE lesson_plan_id = lp.id AND date >= CURDATE()) as next_lesson_date
    FROM lesson_plans lp
    LEFT JOIN students s ON lp.student_id = s.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE lp.teacher_id = ?
    ORDER BY lp.is_active DESC, lp.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$plans = $stmt->fetchAll();

// Получение списка учеников
$stmt = $pdo->prepare("
    SELECT s.id, u.first_name, u.last_name
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN teacher_student ts ON s.id = ts.student_id
    WHERE ts.teacher_id = ? AND s.is_active = 1
    ORDER BY u.last_name, u.first_name
");
$stmt->execute([$_SESSION['user_id']]);
$students = $stmt->fetchAll();

// Получение тем и меток для модального окна
$stmt = $pdo->prepare("SELECT id, name, color FROM topics WHERE teacher_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$allTopics = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, color FROM tags WHERE teacher_id = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$allTags = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, title, difficulty FROM tasks WHERE teacher_id = ? ORDER BY title");
$stmt->execute([$_SESSION['user_id']]);
$allTasks = $stmt->fetchAll();

// Получение данных для просмотра плана
$viewPlan = null;
$viewLessons = [];
$viewComments = [];

if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT lp.*, 
               CONCAT(u.first_name, ' ', u.last_name) as student_name,
               u.email as student_email,
               u.phone as student_phone
        FROM lesson_plans lp
        LEFT JOIN students s ON lp.student_id = s.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE lp.id = ? AND lp.teacher_id = ?
    ");
    $stmt->execute([$_GET['view'], $_SESSION['user_id']]);
    $viewPlan = $stmt->fetch();
    
    if ($viewPlan) {
        // Получаем уроки
        $stmt = $pdo->prepare("
            SELECT l.*,
                   GROUP_CONCAT(DISTINCT t.name SEPARATOR ', ') as topics,
                   GROUP_CONCAT(DISTINCT t.id) as topic_ids,
                   GROUP_CONCAT(DISTINCT tg.name SEPARATOR ', ') as tags,
                   lh.task_id,
                   lh.custom_description,
                   tk.title as task_title
            FROM lessons l
            LEFT JOIN lesson_topics lt ON l.id = lt.lesson_id
            LEFT JOIN topics t ON lt.topic_id = t.id
            LEFT JOIN lesson_tags ltg ON l.id = ltg.lesson_id
            LEFT JOIN tags tg ON ltg.tag_id = tg.id
            LEFT JOIN lesson_homework lh ON l.id = lh.lesson_id
            LEFT JOIN tasks tk ON lh.task_id = tk.id
            WHERE l.lesson_plan_id = ?
            GROUP BY l.id
            ORDER BY l.date, l.time
        ");
        $stmt->execute([$viewPlan['id']]);
        $viewLessons = $stmt->fetchAll();
        
        // Получаем комментарии к плану
        $stmt = $pdo->prepare("
            SELECT c.*, u.first_name, u.last_name
            FROM lesson_plan_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.lesson_plan_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$viewPlan['id']]);
        $viewComments = $stmt->fetchAll();
    }
}

// Получение данных для редактирования урока
$editLesson = null;

if (isset($_GET['edit_lesson'])) {
    $stmt = $pdo->prepare("
        SELECT l.*, lp.teacher_id
        FROM lessons l
        JOIN lesson_plans lp ON l.lesson_plan_id = lp.id
        WHERE l.id = ? AND lp.teacher_id = ?
    ");
    $stmt->execute([$_GET['edit_lesson'], $_SESSION['user_id']]);
    $editLesson = $stmt->fetch();
}

// Построение дерева тем для выбора
function buildTopicTree($topics, $parentId = null, $level = 0) {
    $result = [];
    foreach ($topics as $topic) {
        if (isset($topic['parent_id']) && $topic['parent_id'] == $parentId) {
            $topic['level'] = $level;
            $result[] = $topic;
            $children = buildTopicTree($topics, $topic['id'], $level + 1);
            $result = array_merge($result, $children);
        }
    }
    return $result;
}

$topicTree = buildTopicTree($allTopics);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Планы обучения - Репетитор 2029</title>
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
        .plan-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
            margin-bottom: 20px;
            border-left: 4px solid transparent;
        }
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .plan-card.active {
            border-left-color: #28a745;
        }
        .plan-card.inactive {
            border-left-color: #dc3545;
            opacity: 0.8;
        }
        .progress-custom {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
        }
        .progress-bar-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 4px;
        }
        .lesson-table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        .lesson-table th {
            background: #f8f9fa;
            font-weight: 600;
            padding: 15px;
        }
        .lesson-table td {
            padding: 12px 15px;
            vertical-align: middle;
        }
        .lesson-row {
            transition: background 0.2s;
        }
        .lesson-row:hover {
            background: #f8f9fa;
        }
        .lesson-row.completed {
            background: #f0f9f0;
        }
        .lesson-row.completed:hover {
            background: #e8f5e8;
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
        .topic-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin: 2px;
            color: white;
        }
        .tag-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            margin: 2px;
            color: white;
        }
        .selection-list {
            max-height: 300px;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 10px;
        }
        .selection-item {
            padding: 8px;
            margin: 2px 0;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .selection-item:hover {
            background: #f8f9fa;
        }
        .selection-item.selected {
            background: #e3f2fd;
            border-left: 3px solid #667eea;
        }
        .topic-level {
            display: inline-block;
            width: 20px;
            height: 20px;
            margin-right: 5px;
            border-radius: 4px;
        }
        .comment-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
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
                <h2 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Планы обучения и дневник</h2>
                <p class="text-muted mb-0">Создание и управление планами занятий</p>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="openCreatePlanModal()">
                    <i class="bi bi-plus-circle"></i> Создать план
                </button>
                <button class="btn btn-outline-success" onclick="openImportPlanModal()">
                    <i class="bi bi-upload"></i> Импорт
                </button>
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

        <?php if ($viewPlan): ?>
        <!-- Просмотр плана -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="plan-card active">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h3><?php echo htmlspecialchars($viewPlan['name']); ?></h3>
                            <?php if ($viewPlan['student_name']): ?>
                                <p class="mb-1">
                                    <i class="bi bi-person"></i> Ученик: 
                                    <strong><?php echo htmlspecialchars($viewPlan['student_name']); ?></strong>
                                </p>
                                <?php if ($viewPlan['student_email']): ?>
                                    <p class="mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($viewPlan['student_email']); ?></p>
                                <?php endif; ?>
                                <?php if ($viewPlan['student_phone']): ?>
                                    <p class="mb-1"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($viewPlan['student_phone']); ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted">План без ученика</p>
                            <?php endif; ?>
                            <?php if ($viewPlan['description']): ?>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($viewPlan['description'])); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="badge bg-<?php echo $viewPlan['is_active'] ? 'success' : 'secondary'; ?> me-2">
                                <?php echo $viewPlan['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                            <a href="plans.php" class="btn btn-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> Назад
                            </a>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <small class="text-muted">Всего уроков</small>
                            <h5><?php echo count($viewLessons); ?></h5>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Проведено</small>
                            <h5><?php echo array_sum(array_column($viewLessons, 'is_completed')); ?></h5>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Прогресс</small>
                            <?php 
                                $total = count($viewLessons);
                                $completed = array_sum(array_column($viewLessons, 'is_completed'));
                                $progress = $total > 0 ? round($completed / $total * 100) : 0;
                            ?>
                            <div class="progress-custom mt-2">
                                <div class="progress-bar-custom" style="width: <?php echo $progress; ?>%; height: 8px;"></div>
                            </div>
                            <small class="text-muted"><?php echo $progress; ?>% завершено</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="plan-card">
                    <h5 class="mb-3">
                        <i class="bi bi-chat"></i> Комментарии к плану
                    </h5>
                    
                    <form method="POST" action="" class="mb-3">
                        <input type="hidden" name="action" value="add_plan_comment">
                        <input type="hidden" name="plan_id" value="<?php echo $viewPlan['id']; ?>">
                        <textarea class="form-control mb-2" name="comment" rows="2" placeholder="Добавить комментарий..."></textarea>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-send"></i> Добавить
                        </button>
                    </form>
                    
                    <?php if (empty($viewComments)): ?>
                        <p class="text-muted text-center py-3">Нет комментариев</p>
                    <?php else: ?>
                        <?php foreach ($viewComments as $comment): ?>
                            <div class="comment-item">
                                <div class="d-flex justify-content-between mb-2">
                                    <strong><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></strong>
                                    <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($comment['created_at'])); ?></small>
                                </div>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Таблица уроков -->
        <div class="lesson-table">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Время</th>
                        <th>Название</th>
                        <th>Темы</th>
                        <th>Домашнее задание</th>
                        <th>Оценки</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewLessons as $index => $lesson): ?>
                        <tr class="lesson-row <?php echo $lesson['is_completed'] ? 'completed' : ''; ?>">
                            <td>
                                <strong><?php echo date('d.m.Y', strtotime($lesson['date'])); ?></strong>
                                <?php 
                                    $lessonDate = new DateTime($lesson['date']);
                                    $today = new DateTime();
                                    if (!$lesson['is_completed'] && $lessonDate < $today) {
                                        echo '<span class="badge bg-danger ms-2">Просрочен</span>';
                                    } elseif (!$lesson['is_completed'] && $lessonDate == $today) {
                                        echo '<span class="badge bg-warning ms-2">Сегодня</span>';
                                    }
                                ?>
                            </td>
                            <td><?php echo $lesson['time'] ? substr($lesson['time'], 0, 5) : '—'; ?></td>
                            <td>
                                <?php if ($lesson['title']): ?>
                                    <strong><?php echo htmlspecialchars($lesson['title']); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">Без названия</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lesson['topics']): ?>
                                    <?php foreach (explode(', ', $lesson['topics']) as $topic): ?>
                                        <span class="topic-badge" style="background: #667eea;">
                                            <?php echo htmlspecialchars($topic); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lesson['task_title']): ?>
                                    <small><i class="bi bi-journal"></i> <?php echo htmlspecialchars($lesson['task_title']); ?></small>
                                <?php elseif ($lesson['custom_description']): ?>
                                    <small><?php echo htmlspecialchars(substr($lesson['custom_description'], 0, 30)) . '...'; ?></small>
                                <?php elseif ($lesson['homework_comment']): ?>
                                    <small><?php echo htmlspecialchars(substr($lesson['homework_comment'], 0, 30)) . '...'; ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lesson['grade_for_lesson'] !== null): ?>
                                    <span class="badge bg-info me-1">У: <?php echo $lesson['grade_for_lesson']; ?></span>
                                <?php endif; ?>
                                <?php if ($lesson['grade_for_homework'] !== null): ?>
                                    <span class="badge bg-success">ДЗ: <?php echo $lesson['grade_for_homework']; ?></span>
                                <?php endif; ?>
                                <?php if ($lesson['grade_for_lesson'] === null && $lesson['grade_for_homework'] === null): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lesson['is_completed']): ?>
                                    <span class="badge bg-success">Проведен</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Запланирован</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary btn-action" onclick="openLessonModal(<?php echo $lesson['id']; ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteLesson(<?php echo $lesson['id']; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($viewLessons)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <p class="text-muted mb-2">В этом плане пока нет уроков</p>
                                <button class="btn btn-primary btn-sm" onclick="addLessonRow()">
                                    <i class="bi bi-plus"></i> Добавить урок
                                </button>
                                <button class="btn btn-outline-success btn-sm" onclick="openImportDatesModal(<?php echo $viewPlan['id']; ?>)">
                                    <i class="bi bi-calendar-plus"></i> Импортировать даты
                                </button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if (!empty($viewLessons)): ?>
            <div class="p-3 bg-light d-flex justify-content-between">
                <div>
                    <button class="btn btn-primary btn-sm" onclick="addLessonRow()">
                        <i class="bi bi-plus"></i> Добавить урок
                    </button>
                    <button class="btn btn-outline-success btn-sm" onclick="openImportDatesModal(<?php echo $viewPlan['id']; ?>)">
                        <i class="bi bi-calendar-plus"></i> Импортировать даты
                    </button>
                </div>
                <div>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="export_plan_csv">
                        <input type="hidden" name="plan_id" value="<?php echo $viewPlan['id']; ?>">
                        <button type="submit" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-filetype-csv"></i> Экспорт CSV
                        </button>
                    </form>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="export_plan_json">
                        <input type="hidden" name="plan_id" value="<?php echo $viewPlan['id']; ?>">
                        <button type="submit" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-filetype-json"></i> Экспорт JSON
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        
        <!-- Список планов -->
        <div class="row">
            <?php foreach ($plans as $plan): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="plan-card <?php echo $plan['is_active'] ? 'active' : 'inactive'; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0"><?php echo htmlspecialchars($plan['name']); ?></h5>
                            <div class="dropdown">
                                <button class="btn btn-link text-dark" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="?view=<?php echo $plan['id']; ?>">
                                            <i class="bi bi-eye"></i> Просмотр
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="openCopyPlanModal(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['name']); ?>')">
                                            <i class="bi bi-copy"></i> Создать копию
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="openEditPlanModal(<?php echo $plan['id']; ?>)">
                                            <i class="bi bi-pencil"></i> Редактировать
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item text-danger" href="#" onclick="deletePlan(<?php echo $plan['id']; ?>)">
                                            <i class="bi bi-trash"></i> Удалить
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        
                        <?php if ($plan['student_name']): ?>
                            <p class="mb-1">
                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($plan['student_name']); ?>
                            </p>
                        <?php else: ?>
                            <p class="text-muted mb-1">
                                <i class="bi bi-person"></i> Без ученика
                            </p>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">
                                <i class="bi bi-calendar"></i> <?php echo $plan['lessons_count']; ?> уроков
                            </small>
                            <?php if ($plan['next_lesson_date']): ?>
                                <small class="text-primary">
                                    <i class="bi bi-clock"></i> <?php echo date('d.m', strtotime($plan['next_lesson_date'])); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="progress-custom">
                            <?php 
                                $progress = $plan['lessons_count'] > 0 
                                    ? round($plan['completed_count'] / $plan['lessons_count'] * 100) 
                                    : 0;
                            ?>
                            <div class="progress-bar-custom" style="width: <?php echo $progress; ?>%; height: 8px;"></div>
                        </div>
                        <small class="text-muted"><?php echo $progress; ?>% завершено</small>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($plans)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-calendar-range display-1 text-muted"></i>
                        <h4 class="mt-3">Нет планов обучения</h4>
                        <p class="text-muted">Нажмите "Создать план", чтобы создать первый план</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно создания/редактирования плана -->
    <div class="modal fade" id="planModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="planModalTitle">Создание плана</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="planForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="planAction" value="create_plan">
                        <input type="hidden" name="plan_id" id="planId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Название плана <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="planName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ученик</label>
                            <select class="form-select" name="student_id" id="planStudent">
                                <option value="">-- Без ученика --</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" id="planDescription" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3" id="initialCommentField">
                            <label class="form-label">Начальный комментарий</label>
                            <textarea class="form-control" name="initial_comment" rows="2" placeholder="Добавить первый комментарий к плану..."></textarea>
                        </div>
                        
                        <div class="mb-3" id="statusField" style="display: none;">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="planActive" checked>
                                <label class="form-check-label" for="planActive">Активный план</label>
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

    <!-- Модальное окно копирования плана -->
    <div class="modal fade" id="copyPlanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Копирование плана</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="copy_plan">
                    <input type="hidden" name="plan_id" id="copyPlanId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Название для копии <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="new_name" id="copyPlanName" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Создать копию</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно импорта -->
    <div class="modal fade" id="importPlanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Импорт плана</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#importCsv">CSV</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#importJson">JSON</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <!-- CSV импорт -->
                        <div class="tab-pane fade show active" id="importCsv">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="import_plan_csv">
                                
                                <div class="mb-3">
                                    <label class="form-label">Название плана <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="plan_name" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Ученик</label>
                                    <select class="form-select" name="student_id">
                                        <option value="">-- Без ученика --</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">CSV файл</label>
                                    <input type="file" class="form-control" name="csv_file" accept=".csv,.txt" required>
                                </div>
                                
                                <div class="alert alert-info">
                                    <h6>Формат CSV:</h6>
                                    <p class="small mb-0">дата, время, название, тема, домашнее задание, ссылка, комментарий</p>
                                    <p class="small mb-0">Дата в формате ДД.ММ.ГГГГ</p>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-upload"></i> Импортировать
                                </button>
                            </form>
                        </div>
                        
                        <!-- JSON импорт -->
                        <div class="tab-pane fade" id="importJson">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="import_plan_json">
                                
                                <div class="mb-3">
                                    <label class="form-label">Ученик</label>
                                    <select class="form-select" name="student_id">
                                        <option value="">-- Без ученика --</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">JSON файл</label>
                                    <input type="file" class="form-control" name="json_file" accept=".json" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-upload"></i> Импортировать
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно импорта дат -->
    <div class="modal fade" id="importDatesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Импорт дат</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_csv_dates">
                    <input type="hidden" name="plan_id" id="importDatesPlanId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">CSV файл с датами</label>
                            <input type="file" class="form-control" name="csv_file" accept=".csv,.txt" required>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6>Формат CSV:</h6>
                            <p class="small mb-0">дата, время, название</p>
                            <p class="small mb-0">Дата в формате ДД.ММ.ГГГГ</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Импортировать</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно редактирования урока -->
    <div class="modal fade" id="lessonModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Редактирование урока</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="lessonForm" method="POST" action="save_lesson.php">
                        <input type="hidden" name="lesson_id" id="lessonId">
                        <input type="hidden" name="plan_id" value="<?php echo $viewPlan['id'] ?? 0; ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Дата</label>
                                <input type="date" class="form-control" name="date" id="lessonDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Время</label>
                                <input type="time" class="form-control" name="time" id="lessonTime">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Название урока</label>
                            <input type="text" class="form-control" name="title" id="lessonTitle">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Темы</label>
                            <div class="selection-list" id="topicsList">
                                <?php foreach ($topicTree as $topic): ?>
                                    <div class="selection-item d-flex align-items-center" onclick="toggleTopic(this, <?php echo $topic['id']; ?>)">
                                        <div class="topic-level" style="background: <?php echo htmlspecialchars($topic['color']); ?>; margin-left: <?php echo $topic['level'] * 20; ?>px;"></div>
                                        <span><?php echo htmlspecialchars($topic['name']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="topics[]" id="selectedTopics">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Домашнее задание</label>
                            <div class="mb-2">
                                <select class="form-select" name="homework_task_id" id="homeworkTask">
                                    <option value="">-- Выбрать из банка заданий --</option>
                                    <?php foreach ($allTasks as $task): ?>
                                        <option value="<?php echo $task['id']; ?>">
                                            <?php echo htmlspecialchars($task['title']); ?> 
                                            <?php if ($task['difficulty'] !== null): ?>[<?php echo $task['difficulty']; ?>/10]<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <textarea class="form-control" name="homework_custom" id="homeworkCustom" rows="2" placeholder="Или введите описание вручную"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ссылка на материал</label>
                            <input type="url" class="form-control" name="external_link" id="externalLink">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Комментарий к ссылке</label>
                            <input type="text" class="form-control" name="link_comment" id="linkComment">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Комментарий к уроку</label>
                            <textarea class="form-control" name="lesson_comment" id="lessonComment" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Метки</label>
                            <div class="selection-list" id="tagsList" style="max-height: 150px;">
                                <?php foreach ($allTags as $tag): ?>
                                    <div class="selection-item d-flex align-items-center" onclick="toggleTag(this, <?php echo $tag['id']; ?>)">
                                        <div style="width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($tag['color']); ?>; margin-right: 10px;"></div>
                                        <span><?php echo htmlspecialchars($tag['name']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="tags[]" id="selectedTags">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Оценка за урок (0-5)</label>
                                <input type="number" class="form-control" name="grade_for_lesson" id="gradeLesson" min="0" max="5" step="0.5">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Оценка за ДЗ (0-5)</label>
                                <input type="number" class="form-control" name="grade_for_homework" id="gradeHomework" min="0" max="5" step="0.5">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_completed" id="isCompleted">
                                <label class="form-check-label" for="isCompleted">
                                    Урок проведен
                                </label>
                            </div>
                        </div>
                        
                        <div class="modal-footer px-0 pb-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                            <button type="submit" class="btn btn-primary">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Модальные окна
        let planModal = null;
        let copyPlanModal = null;
        let importPlanModal = null;
        let importDatesModal = null;
        let lessonModal = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            planModal = new bootstrap.Modal(document.getElementById('planModal'));
            copyPlanModal = new bootstrap.Modal(document.getElementById('copyPlanModal'));
            importPlanModal = new bootstrap.Modal(document.getElementById('importPlanModal'));
            importDatesModal = new bootstrap.Modal(document.getElementById('importDatesModal'));
            lessonModal = new bootstrap.Modal(document.getElementById('lessonModal'));
        });
        
        // Планы
        function openCreatePlanModal() {
            document.getElementById('planModalTitle').textContent = 'Создание плана';
            document.getElementById('planAction').value = 'create_plan';
            document.getElementById('planId').value = '';
            document.getElementById('planName').value = '';
            document.getElementById('planStudent').value = '';
            document.getElementById('planDescription').value = '';
            document.getElementById('initialCommentField').style.display = 'block';
            document.getElementById('statusField').style.display = 'none';
            planModal.show();
        }
        
        function openEditPlanModal(planId) {
            // Загружаем данные плана через AJAX
            fetch('get_plan.php?id=' + planId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('planModalTitle').textContent = 'Редактирование плана';
                    document.getElementById('planAction').value = 'edit_plan';
                    document.getElementById('planId').value = data.id;
                    document.getElementById('planName').value = data.name;
                    document.getElementById('planStudent').value = data.student_id || '';
                    document.getElementById('planDescription').value = data.description || '';
                    document.getElementById('initialCommentField').style.display = 'none';
                    document.getElementById('statusField').style.display = 'block';
                    document.getElementById('planActive').checked = data.is_active == 1;
                    planModal.show();
                });
        }
        
        function openCopyPlanModal(planId, planName) {
            document.getElementById('copyPlanId').value = planId;
            document.getElementById('copyPlanName').value = 'Копия ' + planName;
            copyPlanModal.show();
        }
        
        function deletePlan(planId) {
            if (confirm('Вы уверены, что хотите удалить этот план? Все уроки также будут удалены.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_plan">
                    <input type="hidden" name="plan_id" value="${planId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Импорт
        function openImportPlanModal() {
            importPlanModal.show();
        }
        
        function openImportDatesModal(planId) {
            document.getElementById('importDatesPlanId').value = planId;
            importDatesModal.show();
        }
        
        // Уроки
        function addLessonRow() {
            // Создаем пустой урок через AJAX
            fetch('add_lesson.php?plan_id=<?php echo $viewPlan['id'] ?? 0; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
        }
        
        function openLessonModal(lessonId) {
            // Загружаем данные урока через AJAX
            fetch('get_lesson.php?id=' + lessonId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('lessonId').value = data.id;
                    document.getElementById('lessonDate').value = data.date;
                    document.getElementById('lessonTime').value = data.time || '';
                    document.getElementById('lessonTitle').value = data.title || '';
                    document.getElementById('homeworkTask').value = data.homework_task_id || '';
                    document.getElementById('homeworkCustom').value = data.custom_description || '';
                    document.getElementById('externalLink').value = data.external_link || '';
                    document.getElementById('linkComment').value = data.link_comment || '';
                    document.getElementById('lessonComment').value = data.lesson_comment || '';
                    document.getElementById('gradeLesson').value = data.grade_for_lesson || '';
                    document.getElementById('gradeHomework').value = data.grade_for_homework || '';
                    document.getElementById('isCompleted').checked = data.is_completed == 1;
                    
                    // Отмечаем выбранные темы
                    document.querySelectorAll('#topicsList .selection-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                    if (data.topics) {
                        data.topics.forEach(topicId => {
                            const items = document.querySelectorAll('#topicsList .selection-item');
                            items.forEach(item => {
                                if (item.getAttribute('onclick').includes(topicId)) {
                                    item.classList.add('selected');
                                }
                            });
                        });
                    }
                    
                    // Отмечаем выбранные метки
                    document.querySelectorAll('#tagsList .selection-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                    if (data.tags) {
                        data.tags.forEach(tagId => {
                            const items = document.querySelectorAll('#tagsList .selection-item');
                            items.forEach(item => {
                                if (item.getAttribute('onclick').includes(tagId)) {
                                    item.classList.add('selected');
                                }
                            });
                        });
                    }
                    
                    lessonModal.show();
                });
        }
        
        function deleteLesson(lessonId) {
            if (confirm('Удалить урок?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_lesson">
                    <input type="hidden" name="lesson_id" value="${lessonId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Выбор тем и меток
        function toggleTopic(element, topicId) {
            element.classList.toggle('selected');
            updateSelectedTopics();
        }
        
        function toggleTag(element, tagId) {
            element.classList.toggle('selected');
            updateSelectedTags();
        }
        
        function updateSelectedTopics() {
            const selected = [];
            document.querySelectorAll('#topicsList .selection-item.selected').forEach(item => {
                const onclick = item.getAttribute('onclick');
                const match = onclick.match(/toggleTopic.*?(\d+)/);
                if (match) {
                    selected.push(match[1]);
                }
            });
            document.getElementById('selectedTopics').value = selected.join(',');
        }
        
        function updateSelectedTags() {
            const selected = [];
            document.querySelectorAll('#tagsList .selection-item.selected').forEach(item => {
                const onclick = item.getAttribute('onclick');
                const match = onclick.match(/toggleTag.*?(\d+)/);
                if (match) {
                    selected.push(match[1]);
                }
            });
            document.getElementById('selectedTags').value = selected.join(',');
        }
    </script>
</body>
</html>