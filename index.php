<?php
session_start();

header('Content-Type: text/html; charset=UTF-8');

// --------------------
// ПОДКЛЮЧЕНИЕ К БД
// --------------------

$db_user = 'u82464';
$db_pass = '8104996';
$db_name = 'u82464';
$db_host = 'localhost';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch(PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

// --------------------
// ФУНКЦИИ
// --------------------

function generateLogin() {
    return 'user_' . bin2hex(random_bytes(4));
}

function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// --------------------
// ЯЗЫКИ
// --------------------

$languagesList = $pdo->query("
    SELECT id, name
    FROM programming_languages
    ORDER BY name
")->fetchAll();

$allowedLanguageIds = array_column($languagesList, 'id');

// --------------------
// ВЫХОД
// --------------------

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// --------------------
// АВТОРИЗАЦИЯ
// --------------------

$loginError = '';

if (isset($_POST['login_submit'])) {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($login)) {
        $loginError = 'Введите логин';
    }
    elseif (empty($password)) {
        $loginError = 'Введите пароль';
    }
    else {
        $stmt = $pdo->prepare("SELECT * FROM applications WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            
            // Очищаем flash-сообщения при входе
            unset($_SESSION['flash_message']);
            
            header('Location: index.php');
            exit();
        } else {
            $loginError = 'Неверный логин или пароль';
        }
    }
}

// --------------------
// ОБРАБОТКА ОСНОВНОЙ ФОРМЫ (POST/REDIRECT/GET)
// --------------------

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['login_submit'])) {
    $errors = false;
    $errorMessages = [];
    
    // ВАЛИДАЦИЯ
    
    // ФИО
    if (
        empty($_POST['full_name']) ||
        !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $_POST['full_name'])
    ) {
        $errorMessages['full_name'] = 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.';
        $errors = true;
    }
    
    // ТЕЛЕФОН
    if (
        empty($_POST['phone']) ||
        !preg_match(
            '/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/',
            $_POST['phone']
        )
    ) {
        $errorMessages['phone'] = 'Введите корректный номер телефона.';
        $errors = true;
    }
    
    // EMAIL
    if (
        empty($_POST['email']) ||
        !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errorMessages['email'] = 'Введите корректный e-mail.';
        $errors = true;
    }
    
    // ДАТА
    if (empty($_POST['birth_date'])) {
        $errorMessages['birth_date'] = 'Выберите дату рождения.';
        $errors = true;
    }
    
    // ПОЛ
    if (
        empty($_POST['gender']) ||
        !in_array($_POST['gender'], ['male', 'female', 'other'])
    ) {
        $errorMessages['gender'] = 'Выберите пол.';
        $errors = true;
    }
    
    // ЯЗЫКИ
    $selectedLangs = $_POST['languages'] ?? [];
    if (empty($selectedLangs)) {
        $errorMessages['languages'] = 'Выберите хотя бы один язык.';
        $errors = true;
    }
    foreach ($selectedLangs as $langId) {
        if (!in_array($langId, $allowedLanguageIds)) {
            $errorMessages['languages'] = 'Выбран недопустимый язык.';
            $errors = true;
        }
    }
    
    // CONTRACT
    if (!isset($_POST['contract'])) {
        $errorMessages['contract'] = 'Необходимо принять условия.';
        $errors = true;
    }
    
    // ЕСЛИ НЕТ ОШИБОК - СОХРАНЯЕМ
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            
            // UPDATE (если пользователь авторизован)
            if (isset($_SESSION['user_id'])) {
                $appId = $_SESSION['user_id'];
                $stmt = $pdo->prepare("
                    UPDATE applications
                    SET full_name=?, phone=?, email=?, birth_date=?, gender=?, bio=?, contract_accepted=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['full_name'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['birth_date'],
                    $_POST['gender'],
                    $_POST['bio'],
                    1,
                    $appId
                ]);
                
                // Обновляем языки
                $pdo->prepare("DELETE FROM application_languages WHERE application_id=?")->execute([$appId]);
                
                // Flash-сообщение об успешном обновлении
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'text' => '✅ Данные успешно обновлены!'
                ];
            } 
            // INSERT (новая анкета)
            else {
                // Проверяем, не существует ли уже email
                $stmt = $pdo->prepare("SELECT id FROM applications WHERE email = ?");
                $stmt->execute([$_POST['email']]);
                if ($stmt->fetch()) {
                    throw new Exception('Пользователь с таким email уже зарегистрирован');
                }
                
                $login = generateLogin();
                $plainPassword = generatePassword();
                $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO applications (full_name, phone, email, birth_date, gender, bio, contract_accepted, login, password_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['full_name'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['birth_date'],
                    $_POST['gender'],
                    $_POST['bio'],
                    1,
                    $login,
                    $passwordHash
                ]);
                $appId = $pdo->lastInsertId();
                
                // Flash-сообщение с логином и паролем
                $_SESSION['flash_message'] = [
                    'type' => 'success_with_credentials',
                    'login' => $login,
                    'password' => $plainPassword,
                    'text' => '✅ Данные успешно сохранены!'
                ];
            }
            
            // Сохраняем языки (для обоих случаев)
            $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($selectedLangs as $langId) {
                $stmtLang->execute([$appId, $langId]);
            }
            
            $pdo->commit();
            
            // РЕДИРЕКТ - предотвращает повторную отправку формы при обновлении
            header('Location: index.php');
            exit();
            
        } catch(Exception $e) {
            $pdo->rollBack();
            // Сохраняем ошибку в сессию для отображения после редиректа
            $_SESSION['flash_message'] = [
                'type' => 'error',
                'text' => 'Ошибка: ' . $e->getMessage()
            ];
            
            // Сохраняем введённые данные в сессию, чтобы восстановить форму
            $_SESSION['form_data'] = [
                'full_name' => $_POST['full_name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'email' => $_POST['email'] ?? '',
                'birth_date' => $_POST['birth_date'] ?? '',
                'gender' => $_POST['gender'] ?? '',
                'bio' => $_POST['bio'] ?? '',
                'contract' => isset($_POST['contract']),
                'languages' => $selectedLangs
            ];
            $_SESSION['form_errors'] = $errorMessages;
            
            header('Location: index.php');
            exit();
        }
    } 
    // Если есть ошибки валидации - сохраняем в сессию и редиректим
    else {
        $_SESSION['form_data'] = [
            'full_name' => $_POST['full_name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'birth_date' => $_POST['birth_date'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'bio' => $_POST['bio'] ?? '',
            'contract' => isset($_POST['contract']),
            'languages' => $selectedLangs
        ];
        $_SESSION['form_errors'] = $errorMessages;
        
        header('Location: index.php');
        exit();
    }
}

// --------------------
// ЗАГРУЗКА ДАННЫХ ДЛЯ ФОРМЫ
// --------------------

// Очищаем старые flash-данные после их отображения
$flashMessage = null;
if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

$formErrors = [];
if (isset($_SESSION['form_errors'])) {
    $formErrors = $_SESSION['form_errors'];
    unset($_SESSION['form_errors']);
}

$formData = [];
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Если пользователь авторизован, загружаем его данные из БД
if (isset($_SESSION['user_id']) && empty($formData)) {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch();
    
    if ($userData) {
        $formData['full_name'] = $userData['full_name'];
        $formData['phone'] = $userData['phone'];
        $formData['email'] = $userData['email'];
        $formData['birth_date'] = $userData['birth_date'];
        $formData['gender'] = $userData['gender'];
        $formData['bio'] = $userData['bio'];
        $formData['contract'] = $userData['contract_accepted'];
        
        $stmt = $pdo->prepare("SELECT language_id FROM application_languages WHERE application_id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $formData['languages'] = array_column($stmt->fetchAll(), 'language_id');
    }
} 
// Пустая форма для нового пользователя
elseif (empty($formData)) {
    $formData = [
        'full_name' => '',
        'phone' => '',
        'email' => '',
        'birth_date' => '',
        'gender' => '',
        'bio' => '',
        'contract' => false,
        'languages' => []
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анкета разработчика</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #800020;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: #9E9E9E;
            color: #800020;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 0.95em;
            color: #800020;
            font-weight: 500;
        }

        .form-content {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.95em;
        }

        input[type="text"],
        input[type="tel"],
        input[type="email"],
        input[type="date"],
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1em;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #9E9E9E;
            box-shadow: 0 0 0 3px rgba(158, 158, 158, 0.3);
        }

        .form-error {
            border-color: #e74c3c !important;
            background-color: #fff6f6 !important;
        }

        .error-message {
            color: #e74c3c;
            font-size: 0.85em;
            margin-top: 5px;
            display: block;
        }

        .success-banner {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-out;
            font-weight: 500;
            border-left: 4px solid #28a745;
        }

        .error-banner {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-out;
            font-weight: 500;
            border-left: 4px solid #e74c3c;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .radio-group {
            display: flex;
            gap: 20px;
            padding: 10px 0;
        }

        .radio-group label {
            display: inline-flex;
            align-items: center;
            font-weight: normal;
            margin-bottom: 0;
            cursor: pointer;
        }

        .radio-group input[type="radio"] {
            width: auto;
            margin-right: 8px;
            cursor: pointer;
        }

        select[multiple] {
            height: 120px;
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .checkbox-label input {
            width: auto;
            margin-right: 10px;
            cursor: pointer;
        }

        .btn-submit {
            background: #9E9E9E;
            color: #800020;
            border: none;
            padding: 14px 30px;
            font-size: 1em;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-submit:hover {
            background: #757575;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(128, 0, 32, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .auth-section {
            background: #f5f5f5;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
        }

        .auth-section h2 {
            margin-bottom: 20px;
            color: #800020;
            font-size: 1.5em;
        }

        .auth-section label {
            color: #333;
            font-weight: 600;
        }

        .logout-link {
            display: inline-block;
            margin-top: 10px;
            color: #800020;
            text-decoration: none;
            font-weight: 600;
            margin-left: 15px;
        }

        .logout-link:hover {
            text-decoration: underline;
        }

        hr {
            margin: 30px 0;
            border: none;
            height: 1px;
            background: linear-gradient(to right, transparent, #800020, transparent);
        }

        .credentials-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 10px;
        }

        .credentials-box b {
            color: #856404;
        }

        @media (max-width: 600px) {
            .form-content {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📝 Анкета разработчика</h1>
            <p>Заполните форму, чтобы стать частью нашей команды</p>
        </div>
        
        <div class="form-content">
            <!-- Вывод flash-сообщений -->
            <?php if ($flashMessage): ?>
                <?php if ($flashMessage['type'] == 'success_with_credentials'): ?>
                    <div class="success-banner credentials-box">
                        <strong>✅ <?= htmlspecialchars($flashMessage['text']) ?></strong><br><br>
                        <strong>Ваши данные для входа:</strong><br>
                        Логин: <b><?= htmlspecialchars($flashMessage['login']) ?></b><br>
                        Пароль: <b><?= htmlspecialchars($flashMessage['password']) ?></b><br><br>
                        ⚠️ Сохраните их! Теперь вы можете авторизоваться и редактировать свои данные.
                    </div>
                <?php elseif ($flashMessage['type'] == 'success'): ?>
                    <div class="success-banner">
                        <?= htmlspecialchars($flashMessage['text']) ?>
                    </div>
                <?php elseif ($flashMessage['type'] == 'error'): ?>
                    <div class="error-banner">
                        ❌ <?= htmlspecialchars($flashMessage['text']) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- АВТОРИЗАЦИЯ (только если пользователь не авторизован) -->
            <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="auth-section">
                    <h2>🔐 Авторизация для редактирования</h2>
                    <?php if (!empty($loginError)): ?>
                        <span class="error-message"><?= htmlspecialchars($loginError) ?></span><br><br>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label>Логин</label>
                            <input type="text" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" class="<?= !empty($loginError) ? 'form-error' : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Пароль</label>
                            <input type="password" name="password" class="<?= !empty($loginError) ? 'form-error' : '' ?>">
                        </div>
                        
                        <button type="submit" name="login_submit" class="btn-submit">Войти</button>
                    </form>
                </div>
                <hr>
            <?php else: ?>
                <div class="success-banner">
                    ✅ Вы авторизованы как <?= htmlspecialchars($formData['full_name']) ?>
                    <a href="?logout=1" class="logout-link">Выйти</a>
                </div>
            <?php endif; ?>

            <!-- ОСНОВНАЯ ФОРМА -->
            <form method="POST">
                <div class="form-group">
                    <label>ФИО *</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>" class="<?= isset($formErrors['full_name']) ? 'form-error' : '' ?>">
                    <?php if(isset($formErrors['full_name'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['full_name']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Телефон *</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone'] ?? '') ?>" class="<?= isset($formErrors['phone']) ? 'form-error' : '' ?>">
                    <?php if(isset($formErrors['phone'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['phone']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>E-mail *</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($formData['email'] ?? '') ?>" class="<?= isset($formErrors['email']) ? 'form-error' : '' ?>">
                    <?php if(isset($formErrors['email'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['email']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Дата рождения *</label>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($formData['birth_date'] ?? '') ?>" class="<?= isset($formErrors['birth_date']) ? 'form-error' : '' ?>">
                    <?php if(isset($formErrors['birth_date'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['birth_date']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Пол *</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="gender" value="male" <?= (($formData['gender'] ?? '') == 'male') ? 'checked' : '' ?>> Мужской
                        </label>
                        <label>
                            <input type="radio" name="gender" value="female" <?= (($formData['gender'] ?? '') == 'female') ? 'checked' : '' ?>> Женский
                        </label>
                        <label>
                            <input type="radio" name="gender" value="other" <?= (($formData['gender'] ?? '') == 'other') ? 'checked' : '' ?>> Другой
                        </label>
                    </div>
                    <?php if(isset($formErrors['gender'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['gender']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Любимые языки программирования *</label>
                    <select name="languages[]" multiple class="<?= isset($formErrors['languages']) ? 'form-error' : '' ?>">
                        <?php foreach ($languagesList as $lang): ?>
                            <option value="<?= $lang['id'] ?>" <?= in_array($lang['id'], $formData['languages'] ?? []) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lang['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if(isset($formErrors['languages'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['languages']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Биография</label>
                    <textarea name="bio"><?= htmlspecialchars($formData['bio'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="contract" value="1" <?= !empty($formData['contract']) ? 'checked' : '' ?>>
                        Я согласен с условиями обработки данных *
                    </label>
                    <?php if(isset($formErrors['contract'])): ?>
                        <span class="error-message"><?= htmlspecialchars($formErrors['contract']) ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit">
                    <?= isset($_SESSION['user_id']) ? '✏️ Обновить анкету' : '✉️ Отправить анкету' ?>
                </button>
            </form>
            
            <?php if (!isset($_SESSION['user_id'])): ?>
                <p style="margin-top: 20px; text-align: center; color: #666; font-size: 0.85em;">
                    * После отправки анкеты вы получите логин и пароль для редактирования данных
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
