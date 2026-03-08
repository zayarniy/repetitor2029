<?php
// index.php
require_once 'config.php';

// Если уже авторизован, перенаправляем на дашборд
if (isAuthenticated()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? '';
                
                $stmt = $pdo->prepare("
                    SELECT u.*, r.name as role_name 
                    FROM users u 
                    JOIN roles r ON u.role_id = r.id 
                    WHERE u.email = ? AND u.is_active = 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role_name'];
                    
                    // Обновляем время последнего входа
                    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    redirect('dashboard.php');
                } else {
                    $error = 'Неверный email или пароль';
                }
                break;
                
            case 'register':
                // Заглушка для регистрации
                $error = 'Регистрация временно недоступна';
                break;
                
            case 'recover':
                // Заглушка для восстановления пароля
                $error = 'Восстановление пароля временно недоступно';
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
    <title>Вход в систему - Репетитор 2029</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .card-header {
            background: transparent;
            border-bottom: 2px solid #f0f0f0;
            text-align: center;
            padding: 30px 20px 20px;
        }
        .card-header h3 {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .card-header p {
            color: #666;
            font-size: 14px;
        }
        .nav-tabs {
            border: none;
            justify-content: center;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 500;
            padding: 10px 20px;
            margin: 0 5px;
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
        .tab-content {
            padding: 20px;
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
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            color: white;
            font-weight: 600;
            width: 100%;
            transition: transform 0.3s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
        }
        .demo-credentials strong {
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-header">
                <h3>Добро пожаловать!</h3>
                <p>Система управления занятиями репетитора</p>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
                    </div>
                <?php endif; ?>
                
                <ul class="nav nav-tabs" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button" role="tab">Вход</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab">Регистрация</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="recover-tab" data-bs-toggle="tab" data-bs-target="#recover" type="button" role="tab">Восстановление</button>
                    </li>
                </ul>
                
                <div class="tab-content" id="authTabsContent">
                    <!-- Вкладка входа -->
                    <div class="tab-pane fade show active" id="login" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="login">
                            
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" required 
                                       placeholder="Введите email" value="admin@mail.com">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Пароль</label>
                                <input type="password" class="form-control" name="password" required 
                                       placeholder="Введите пароль" value="123">
                            </div>
                            
                            <button type="submit" class="btn-login">Войти</button>
                        </form>
                    </div>
                    
                    <!-- Вкладка регистрации (заглушка) -->
                    <div class="tab-pane fade" id="register" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="register">
                            
                            <div class="mb-3">
                                <label class="form-label">Имя</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Фамилия</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Пароль</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Подтверждение пароля</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Роль</label>
                                <select class="form-select" name="role">
                                    <option value="student">Ученик</option>
                                    <option value="teacher">Учитель</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn-login">Зарегистрироваться</button>
                        </form>
                    </div>
                    
                    <!-- Вкладка восстановления пароля (заглушка) -->
                    <div class="tab-pane fade" id="recover" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="recover">
                            
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            
                            <button type="submit" class="btn-login">Отправить ссылку для восстановления</button>
                        </form>
                    </div>
                </div>
                
                <div class="demo-credentials">
                    <p class="mb-0"><strong>Демо-доступ:</strong> admin@mail.com / 123</p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>