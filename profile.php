<?php
// profile.php
require_once 'config.php';

// Проверка авторизации
if (!isAuthenticated()) {
    redirect('index.php');
}

$currentUser = getCurrentUser($pdo);
$message = '';
$error = '';

// Обработка обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_profile':
                $firstName = $_POST['first_name'] ?? '';
                $lastName = $_POST['last_name'] ?? '';
                $middleName = $_POST['middle_name'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $messenger1 = $_POST['messenger_1'] ?? '';
                $messenger2 = $_POST['messenger_2'] ?? '';
                $messenger3 = $_POST['messenger_3'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET first_name = ?, last_name = ?, middle_name = ?,
                            phone = ?, messenger_1 = ?, messenger_2 = ?, messenger_3 = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$firstName, $lastName, $middleName, $phone, $messenger1, $messenger2, $messenger3, $_SESSION['user_id']]);
                    $message = 'Профиль успешно обновлен';
                    $currentUser = getCurrentUser($pdo); // Обновляем данные
                } catch (Exception $e) {
                    $error = 'Ошибка при обновлении профиля';
                }
                break;
                
            case 'change_password':
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                // Проверяем текущий пароль
                if (!password_verify($currentPassword, $currentUser['password_hash'])) {
                    $error = 'Неверный текущий пароль';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Новый пароль и подтверждение не совпадают';
                } elseif (strlen($newPassword) < 6) {
                    $error = 'Пароль должен содержать минимум 6 символов';
                } else {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$passwordHash, $_SESSION['user_id']]);
                    $message = 'Пароль успешно изменен';
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль пользователя - Репетитор 2029</title>
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
        .profile-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .profile-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px;
            text-align: center;
            color: white;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: bold;
            margin: 0 auto 20px;
            border: 4px solid white;
        }
        .profile-header h2 {
            margin: 0;
            font-size: 28px;
        }
        .profile-header p {
            margin: 5px 0 0;
            opacity: 0.9;
        }
        .profile-body {
            padding: 30px;
        }
        .nav-tabs {
            border: none;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 30px;
            transition: all 0.3s;
        }
        .nav-tabs .nav-link:hover {
            background: #f8f9fa;
        }
        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: none;
        }
        .btn-save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            color: white;
            font-weight: 600;
            width: 100%;
            transition: transform 0.3s;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .info-row {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .info-label {
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .info-value {
            font-weight: 600;
            color: #333;
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
                </ul>
            </div>
        </div>
    </nav>

    <div class="profile-container">
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

        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1) . substr($currentUser['last_name'] ?? 'U', 0, 1)); ?>
                </div>
                <h2><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></h2>
                <p><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($currentUser['email']); ?></p>
                <p><span class="badge bg-light text-dark"><?php echo htmlspecialchars($currentUser['role_name']); ?></span></p>
            </div>
            
            <div class="profile-body">
                <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                            <i class="bi bi-person"></i> Личная информация
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="edit-tab" data-bs-toggle="tab" data-bs-target="#edit" type="button" role="tab">
                            <i class="bi bi-pencil"></i> Редактировать
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                            <i class="bi bi-key"></i> Сменить пароль
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="profileTabsContent">
                    <!-- Информация о пользователе -->
                    <div class="tab-pane fade show active" id="info" role="tabpanel">
                        <div class="info-row">
                            <div class="info-label">ФИО</div>
                            <div class="info-value">
                                <?php 
                                    $fullName = trim(
                                        ($currentUser['last_name'] ?? '') . ' ' . 
                                        ($currentUser['first_name'] ?? '') . ' ' . 
                                        ($currentUser['middle_name'] ?? '')
                                    );
                                    echo htmlspecialchars($fullName ?: 'Не указано');
                                ?>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Телефон</div>
                            <div class="info-value"><?php echo htmlspecialchars($currentUser['phone'] ?: 'Не указан'); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Мессенджеры</div>
                            <div class="info-value">
                                <?php if ($currentUser['messenger_1']): ?>
                                    <div>1: <?php echo htmlspecialchars($currentUser['messenger_1']); ?></div>
                                <?php endif; ?>
                                <?php if ($currentUser['messenger_2']): ?>
                                    <div>2: <?php echo htmlspecialchars($currentUser['messenger_2']); ?></div>
                                <?php endif; ?>
                                <?php if ($currentUser['messenger_3']): ?>
                                    <div>3: <?php echo htmlspecialchars($currentUser['messenger_3']); ?></div>
                                <?php endif; ?>
                                <?php if (!$currentUser['messenger_1'] && !$currentUser['messenger_2'] && !$currentUser['messenger_3']): ?>
                                    Не указаны
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Дата регистрации</div>
                            <div class="info-value"><?php echo date('d.m.Y H:i', strtotime($currentUser['created_at'])); ?></div>
                        </div>
                        
                        <div class="info-row">
                            <div class="info-label">Последний вход</div>
                            <div class="info-value">
                                <?php echo $currentUser['last_login'] ? date('d.m.Y H:i', strtotime($currentUser['last_login'])) : 'Первый вход'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Редактирование профиля -->
                    <div class="tab-pane fade" id="edit" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="mb-3">
                                <label class="form-label">Фамилия</label>
                                <input type="text" class="form-control" name="last_name" 
                                       value="<?php echo htmlspecialchars($currentUser['last_name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Имя</label>
                                <input type="text" class="form-control" name="first_name" 
                                       value="<?php echo htmlspecialchars($currentUser['first_name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Отчество</label>
                                <input type="text" class="form-control" name="middle_name" 
                                       value="<?php echo htmlspecialchars($currentUser['middle_name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Телефон</label>
                                <input type="tel" class="form-control" name="phone" 
                                       value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Мессенджер 1</label>
                                <input type="text" class="form-control" name="messenger_1" 
                                       value="<?php echo htmlspecialchars($currentUser['messenger_1'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Мессенджер 2</label>
                                <input type="text" class="form-control" name="messenger_2" 
                                       value="<?php echo htmlspecialchars($currentUser['messenger_2'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Мессенджер 3</label>
                                <input type="text" class="form-control" name="messenger_3" 
                                       value="<?php echo htmlspecialchars($currentUser['messenger_3'] ?? ''); ?>">
                            </div>
                            
                            <button type="submit" class="btn-save">Сохранить изменения</button>
                        </form>
                    </div>
                    
                    <!-- Смена пароля -->
                    <div class="tab-pane fade" id="password" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-3">
                                <label class="form-label">Текущий пароль</label>
                                <input type="password" class="form-control" name="current_password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Новый пароль</label>
                                <input type="password" class="form-control" name="new_password" required>
                                <small class="text-muted">Минимум 6 символов</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Подтверждение нового пароля</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                            
                            <button type="submit" class="btn-save">Сменить пароль</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>