<?php
// Отправляем браузеру правильную кодировку
header('Content-Type: text/html; charset=UTF-8');

session_start();

// Параметры подключения к БД
$db_user = 'u82464';     
$db_pass = '8104996';     
$db_name = 'u82464';      
$db_host = 'localhost';

try {
    // Подключение к БД
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Создание таблиц, если их нет
    createTables($pdo);
    
} catch(PDOException $e) {
    die('Ошибка подключения к базе данных: ' . $e->getMessage());
}

// Функция создания таблиц
function createTables($pdo) {
    // Таблица пользователей (для аутентификации)
    $sql_users = "
        CREATE TABLE IF NOT EXISTS users (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            login VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            application_id INT(10) UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
            INDEX idx_login (login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql_users);
    
    // Таблица заявок
    $sql_applications = "
        CREATE TABLE IF NOT EXISTS applications (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(150) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100) NOT NULL,
            birth_date DATE NOT NULL,
            gender ENUM('male', 'female', 'other') NOT NULL,
            bio TEXT,
            contract_accepted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql_applications);
    
    // Таблица языков программирования
    $sql_languages = "
        CREATE TABLE IF NOT EXISTS programming_languages (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL UNIQUE,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql_languages);
    
    // Заполнение таблицы языков начальными данными
    $languages = ['Pascal', 'C', 'C++', 'JavaScript', 'PHP', 'Python', 'Java', 'Haskell', 'Clojure', 'Prolog', 'Scala', 'Go'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO programming_languages (name) VALUES (?)");
    foreach ($languages as $lang) {
        $stmt->execute([$lang]);
    }
    
    // Таблица связи заявка-язык
    $sql_app_languages = "
        CREATE TABLE IF NOT EXISTS application_languages (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT(10) UNSIGNED NOT NULL,
            language_id INT(10) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            FOREIGN KEY (language_id) REFERENCES programming_languages(id) ON DELETE CASCADE,
            UNIQUE KEY unique_app_lang (application_id, language_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql_app_languages);
}

// Получение списка языков для формы
$languagesList = $pdo->query("SELECT id, name FROM programming_languages ORDER BY name")->fetchAll();

// Функция генерации уникального логина
function generateLogin($fullName, $pdo) {
    // Очищаем ФИО от спецсимволов
    $cleanName = preg_replace('/[^a-zA-Zа-яА-Я]/u', '', $fullName);
    $cleanName = mb_substr($cleanName, 0, 15);
    
    // Транслитерация русских букв
    $translit = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
    ];
    
    $login = strtr(mb_strtolower($cleanName), $translit);
    $login = preg_replace('/[^a-z0-9]/', '', $login);
    
    if (empty($login)) {
        $login = 'user';
    }
    
    // Проверка уникальности
    $baseLogin = $login;
    $counter = 1;
    while (true) {
        $checkLogin = $counter > 1 ? $baseLogin . $counter : $baseLogin;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->execute([$checkLogin]);
        if (!$stmt->fetch()) {
            return $checkLogin;
        }
        $counter++;
    }
}

// Функция генерации случайного пароля
function generatePassword($length = 10) {
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    return substr(str_shuffle($chars), 0, $length);
}

// Функция для сохранения ошибок в Cookies
function saveErrorsToCookie($errors) {
    setcookie('form_errors', json_encode($errors), 0, '/');
}

// Функция для получения ошибок из Cookies
function getErrorsFromCookie() {
    if (isset($_COOKIE['form_errors'])) {
        $errors = json_decode($_COOKIE['form_errors'], true);
        setcookie('form_errors', '', time() - 3600, '/');
        return $errors;
    }
    return [];
}

// Функция для сохранения данных формы в Cookies (на год)
function saveFormDataToCookie($formData) {
    $expire = time() + 365 * 24 * 3600;
    setcookie('saved_form_data', json_encode($formData), $expire, '/');
}

// Функция для получения сохраненных данных из Cookies
function getSavedFormDataFromCookie() {
    if (isset($_COOKIE['saved_form_data'])) {
        return json_decode($_COOKIE['saved_form_data'], true);
    }
    return [];
}

// Обработка входа
$loginError = '';
$generatedCredentials = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_action'])) {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login) || empty($password)) {
        $loginError = 'Введите логин и пароль';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_login'] = $user['login'];
            $_SESSION['application_id'] = $user['application_id'];
            
            header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
            exit;
        } else {
            $loginError = 'Неверный логин или пароль';
        }
    }
}

// Обработка выхода
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Проверка авторизации
$isAuthorized = isset($_SESSION['user_id']);
$currentApplicationId = $isAuthorized ? $_SESSION['application_id'] : null;
$currentUserLogin = $isAuthorized ? $_SESSION['user_login'] : null;

// Загрузка данных авторизованного пользователя
$userApplicationData = null;
if ($isAuthorized && $currentApplicationId) {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->execute([$currentApplicationId]);
    $userApplicationData = $stmt->fetch();
    
    if ($userApplicationData) {
        $stmtLang = $pdo->prepare("SELECT language_id FROM application_languages WHERE application_id = ?");
        $stmtLang->execute([$currentApplicationId]);
        $userLanguages = $stmtLang->fetchAll(PDO::FETCH_COLUMN);
        $userApplicationData['languages'] = $userLanguages;
    }
}

// Обработка POST-запроса (сохранение/обновление данных)
$errors = [];
$success = false;
$formData = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['login_action'])) {
    // Получаем данные из формы
    $formData = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'birth_date' => $_POST['birth_date'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'languages' => $_POST['languages'] ?? [],
        'bio' => trim($_POST['bio'] ?? ''),
        'contract' => isset($_POST['contract']) ? 1 : 0
    ];
    
    // Валидация полей
    if (empty($formData['full_name'])) {
        $errors['full_name'] = 'Поле "ФИО" обязательно для заполнения.';
    } elseif (strlen($formData['full_name']) > 150) {
        $errors['full_name'] = 'ФИО не должно превышать 150 символов.';
    } elseif (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $formData['full_name'])) {
        $errors['full_name'] = 'ФИО может содержать только буквы (русские или латинские), пробелы и дефисы.';
    }
    
    if (empty($formData['phone'])) {
        $errors['phone'] = 'Поле "Телефон" обязательно для заполнения.';
    } elseif (!preg_match('/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/', $formData['phone'])) {
        $errors['phone'] = 'Введите корректный номер телефона.';
    }
    
    if (empty($formData['email'])) {
        $errors['email'] = 'Поле "E-mail" обязательно для заполнения.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный E-mail адрес.';
    }
    
    if (empty($formData['birth_date'])) {
        $errors['birth_date'] = 'Поле "Дата рождения" обязательно для заполнения.';
    } else {
        $birthDate = DateTime::createFromFormat('Y-m-d', $formData['birth_date']);
        $today = new DateTime();
        $minDate = (new DateTime())->modify('-120 years');
        
        if (!$birthDate || $birthDate > $today) {
            $errors['birth_date'] = 'Дата рождения не может быть в будущем.';
        } elseif ($birthDate < $minDate) {
            $errors['birth_date'] = 'Укажите реальную дату рождения (не старше 120 лет).';
        }
    }
    
    $allowedGenders = ['male', 'female', 'other'];
    if (empty($formData['gender'])) {
        $errors['gender'] = 'Выберите пол.';
    } elseif (!in_array($formData['gender'], $allowedGenders)) {
        $errors['gender'] = 'Недопустимое значение поля "Пол".';
    }
    
    $allowedLanguageIds = array_column($languagesList, 'id');
    if (empty($formData['languages'])) {
        $errors['languages'] = 'Выберите хотя бы один язык программирования.';
    } else {
        foreach ($formData['languages'] as $langId) {
            if (!in_array($langId, $allowedLanguageIds)) {
                $errors['languages'] = 'Выбран недопустимый язык программирования.';
                break;
            }
        }
    }
    
    if (strlen($formData['bio']) > 5000) {
        $errors['bio'] = 'Биография не должна превышать 5000 символов.';
    }
    
    if (!$formData['contract']) {
        $errors['contract'] = 'Вы должны ознакомиться с контрактом и принять его условия.';
    }
    
    // Если есть ошибки - сохраняем в Cookies и перенаправляем
    if (!empty($errors)) {
        saveErrorsToCookie($errors);
        setcookie('temp_form_data', json_encode($formData), 0, '/');
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }
    
    // Если нет ошибок - сохраняем/обновляем в БД
    try {
        $pdo->beginTransaction();
        
        if ($isAuthorized && $currentApplicationId) {
            // ОБНОВЛЕНИЕ существующей заявки
            $stmt = $pdo->prepare("
                UPDATE applications 
                SET full_name = :full_name, phone = :phone, email = :email, 
                    birth_date = :birth_date, gender = :gender, bio = :bio, 
                    contract_accepted = :contract_accepted
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':full_name' => $formData['full_name'],
                ':phone' => $formData['phone'],
                ':email' => $formData['email'],
                ':birth_date' => $formData['birth_date'],
                ':gender' => $formData['gender'],
                ':bio' => $formData['bio'],
                ':contract_accepted' => $formData['contract'],
                ':id' => $currentApplicationId
            ]);
            
            // Обновляем языки
            $stmtDel = $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?");
            $stmtDel->execute([$currentApplicationId]);
            
            $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($formData['languages'] as $langId) {
                $stmtLang->execute([$currentApplicationId, $langId]);
            }
            
            $success = true;
        } else {
            // НОВАЯ заявка
            $stmt = $pdo->prepare("
                INSERT INTO applications (full_name, phone, email, birth_date, gender, bio, contract_accepted)
                VALUES (:full_name, :phone, :email, :birth_date, :gender, :bio, :contract_accepted)
            ");
            
            $stmt->execute([
                ':full_name' => $formData['full_name'],
                ':phone' => $formData['phone'],
                ':email' => $formData['email'],
                ':birth_date' => $formData['birth_date'],
                ':gender' => $formData['gender'],
                ':bio' => $formData['bio'],
                ':contract_accepted' => $formData['contract']
            ]);
            
            $applicationId = $pdo->lastInsertId();
            
            // Вставка языков
            $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($formData['languages'] as $langId) {
                $stmtLang->execute([$applicationId, $langId]);
            }
            
            // ГЕНЕРАЦИЯ логина и пароля
            $login = generateLogin($formData['full_name'], $pdo);
            $password = generatePassword();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Создание пользователя
            $stmtUser = $pdo->prepare("INSERT INTO users (login, password_hash, application_id) VALUES (?, ?, ?)");
            $stmtUser->execute([$login, $passwordHash, $applicationId]);
            
            // Сохраняем сгенерированные учетные данные
            $generatedCredentials = [
                'login' => $login,
                'password' => $password
            ];
            
            // Автоматически авторизуем пользователя
            $userId = $pdo->lastInsertId();
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_login'] = $login;
            $_SESSION['application_id'] = $applicationId;
            
            $success = true;
        }
        
        $pdo->commit();
        
        // Сохраняем данные в Cookies
        saveFormDataToCookie($formData);
        $formData = [];
        setcookie('temp_form_data', '', time() - 3600, '/');
        
        // Перенаправляем
        $redirectUrl = strtok($_SERVER["REQUEST_URI"], '?') . '?success=1';
        if ($generatedCredentials) {
            $_SESSION['generated_credentials'] = $generatedCredentials;
        }
        header('Location: ' . $redirectUrl);
        exit;
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        $errors['database'] = 'Ошибка при сохранении данных: ' . $e->getMessage();
        saveErrorsToCookie($errors);
        setcookie('temp_form_data', json_encode($formData), 0, '/');
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }
}

// Получаем данные для отображения формы
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = true;
    if (isset($_SESSION['generated_credentials'])) {
        $generatedCredentials = $_SESSION['generated_credentials'];
        unset($_SESSION['generated_credentials']);
    }
}

$errors = getErrorsFromCookie();

$tempFormData = [];
if (isset($_COOKIE['temp_form_data'])) {
    $tempFormData = json_decode($_COOKIE['temp_form_data'], true);
    setcookie('temp_form_data', '', time() - 3600, '/');
}

$savedFormData = getSavedFormDataFromCookie();

// Формируем данные для отображения
if (!empty($tempFormData)) {
    $displayFormData = $tempFormData;
} elseif ($isAuthorized && $userApplicationData) {
    $displayFormData = $userApplicationData;
} elseif (!empty($savedFormData) && $_SERVER['REQUEST_METHOD'] != 'POST') {
    $displayFormData = $savedFormData;
} else {
    $displayFormData = [];
}

// Функции для отображения полей
function getValue($fieldName, $formData, $default = '') {
    if (isset($formData[$fieldName])) {
        return htmlspecialchars($formData[$fieldName]);
    }
    return $default;
}

function isChecked($fieldName, $value, $formData) {
    if (isset($formData[$fieldName])) {
        if (is_array($formData[$fieldName])) {
            return in_array($value, $formData[$fieldName]) ? 'checked' : '';
        }
        return $formData[$fieldName] == $value ? 'checked' : '';
    }
    return '';
}

function isSelected($fieldName, $value, $formData) {
    if (isset($formData[$fieldName]) && is_array($formData[$fieldName])) {
        return in_array($value, $formData[$fieldName]) ? 'selected' : '';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анкета разработчика</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #e8ecf2; min-height: 100vh; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: #5a6e7c; color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .form-content { padding: 30px; }
        .auth-bar { background: #f0f2f5; padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .auth-bar .user-info { color: #5a6e7c; font-weight: 500; }
        .auth-bar .btn-logout { background: #e74c3c; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; }
        .auth-bar .btn-logout:hover { background: #c0392b; }
        .auth-bar .btn-login { background: #5a6e7c; color: white; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer; }
        .auth-bar .btn-login:hover { background: #4a5c68; }
        .login-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; }
        .login-modal-content { background: white; padding: 30px; border-radius: 15px; width: 350px; max-width: 90%; }
        .login-modal-content h3 { margin-bottom: 20px; color: #5a6e7c; }
        .login-modal-content input { width: 100%; padding: 10px; margin-bottom: 15px; border: 2px solid #e0e0e0; border-radius: 8px; }
        .login-modal-content button { width: 100%; padding: 10px; background: #5a6e7c; color: white; border: none; border-radius: 8px; cursor: pointer; }
        .login-modal-content button.close { margin-top: 10px; background: #95a5a6; }
        .login-error { color: #e74c3c; margin-bottom: 10px; font-size: 12px; text-align: center; }
        .credentials-box { background: #e8f5e9; border: 2px solid #4caf50; border-radius: 10px; padding: 20px; margin-bottom: 25px; text-align: center; }
        .credentials-box h4 { color: #2e7d32; margin-bottom: 15px; }
        .credentials-box p { margin: 5px 0; font-family: monospace; font-size: 16px; }
        .credentials-box .note { font-size: 12px; margin-top: 10px; color: #666; }
        .edit-notice { background: #e3f2fd; color: #1976d2; padding: 10px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; font-size: 14px; }
        .form-group label .required { color: #e74c3c; margin-left: 5px; }
        .form-group input[type="text"], .form-group input[type="tel"], .form-group input[type="email"], .form-group input[type="date"], .form-group select, .form-group textarea { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; font-family: inherit; background: #fafafa; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #5a6e7c; background: white; }
        .radio-group { display: flex; gap: 20px; flex-wrap: wrap; }
        .radio-group label { display: flex; align-items: center; font-weight: normal; cursor: pointer; }
        .radio-group input[type="radio"] { margin-right: 8px; }
        select[multiple] { min-height: 150px; }
        select[multiple] option:checked { background: #5a6e7c; color: white; }
        .error-message { color: #e74c3c; font-size: 12px; margin-top: 5px; display: block; }
        .form-error { border-color: #e74c3c !important; background-color: #fff5f5 !important; }
        .success-message { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 10px; margin-bottom: 25px; border-left: 4px solid #4caf50; }
        .error-summary { background: #ffebee; color: #c62828; padding: 15px; border-radius: 10px; margin-bottom: 25px; border-left: 4px solid #f44336; }
        .error-summary ul { margin-left: 20px; margin-top: 10px; }
        .btn-submit { background: #5a6e7c; color: white; border: none; padding: 14px 30px; font-size: 16px; font-weight: 600; border-radius: 10px; cursor: pointer; width: 100%; }
        .btn-submit:hover { background: #4a5c68; }
        hr { margin: 20px 0; border: none; height: 1px; background: #e0e0e0; }
        .info-text { color: #666; font-size: 12px; margin-top: 5px; }
        @media (max-width: 600px) { .form-content { padding: 20px; } .radio-group { flex-direction: column; gap: 10px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📝 Анкета разработчика</h1>
            <p>Заполните форму, чтобы стать частью нашего сообщества</p>
        </div>
        
        <div class="form-content">
            <!-- Панель авторизации -->
            <div class="auth-bar">
                <?php if ($isAuthorized): ?>
                    <span class="user-info">👤 Вы вошли как: <strong><?= htmlspecialchars($currentUserLogin) ?></strong></span>
                    <a href="?logout=1" class="btn-logout" onclick="return confirm('Выйти из аккаунта?')">🚪 Выйти</a>
                <?php else: ?>
                    <span class="user-info">🔒 Вы не авторизованы</span>
                    <button class="btn-login" onclick="document.getElementById('loginModal').style.display='flex'">🔑 Войти</button>
                <?php endif; ?>
            </div>
            
            <!-- Модальное окно входа -->
            <div id="loginModal" class="login-modal">
                <div class="login-modal-content">
                    <h3>Вход в аккаунт</h3>
                    <form method="POST" action="">
                        <input type="text" name="login" placeholder="Логин" required>
                        <input type="password" name="password" placeholder="Пароль" required>
                        <?php if ($loginError): ?>
                            <div class="login-error"><?= htmlspecialchars($loginError) ?></div>
                        <?php endif; ?>
                        <button type="submit" name="login_action" value="1">Войти</button>
                        <button type="button" class="close" onclick="document.getElementById('loginModal').style.display='none'">Закрыть</button>
                    </form>
                </div>
            </div>
            
            <!-- Отображение сгенерированных учетных данных -->
            <?php if ($success && $generatedCredentials): ?>
                <div class="credentials-box">
                    <h4>🎉 Регистрация успешно завершена!</h4>
                    <p><strong>Ваш логин:</strong> <?= htmlspecialchars($generatedCredentials['login']) ?></p>
                    <p><strong>Ваш пароль:</strong> <?= htmlspecialchars($generatedCredentials['password']) ?></p>
                    <p class="note">⚠️ Сохраните эти данные! Они понадобятся для входа и редактирования анкеты.</p>
                </div>
            <?php elseif ($success && !$generatedCredentials): ?>
                <div class="success-message">✅ Ваши данные успешно обновлены!</div>
            <?php endif; ?>
            
            <!-- Уведомление о режиме редактирования -->
            <?php if ($isAuthorized && $userApplicationData && !$success): ?>
                <div class="edit-notice">✏️ Вы авторизованы. Вы можете редактировать свои данные.</div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="error-summary">
                    <strong>❌ Пожалуйста, исправьте следующие ошибки:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>ФИО <span class="required">*</span></label>
                    <input type="text" name="full_name" value="<?= getValue('full_name', $displayFormData) ?>" class="<?= isset($errors['full_name']) ? 'form-error' : '' ?>" placeholder="Иванов Иван Иванович">
                    <?php if (isset($errors['full_name'])): ?>
                        <span class="error-message"><?= $errors['full_name'] ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Телефон <span class="required">*</span></label>
                    <input type="tel" name="phone" value="<?= getValue('phone', $displayFormData) ?>" class="<?= isset($errors['phone']) ? 'form-error' : '' ?>" placeholder="+7(123)456-78-90">
                    <?php if (isset($errors['phone'])): ?>
                        <span class="error-message"><?= $errors['phone'] ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>E-mail <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= getValue('email', $displayFormData) ?>" class="<?= isset($errors['email']) ? 'form-error' : '' ?>" placeholder="ivan@example.com">
                    <?php if (isset($errors['email'])): ?>
                        <span class="error-message"><?= $errors['email'] ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Дата рождения <span class="required">*</span></label>
                    <input type="date" name="birth_date" value="<?= getValue('birth_date', $displayFormData) ?>" class="<?= isset($errors['birth_date']) ? 'form-error' : '' ?>">
                    <?php if (isset($errors['birth_date'])): ?>
                        <span class="error-message"><?= $errors['birth_date'] ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Пол <span class="required">*</span></label>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="male" <?= isChecked('gender', 'male', $displayFormData) ?>> Мужской</label>
                        <label><input type="radio" name="gender" value="female" <?= isChecked('gender', 'female', $displayFormData) ?>> Женский</label>
                        <label><input type="radio" name="gender" value="other" <?= isChecked('gender', 'other', $displayFormData) ?>> Другой</label>
                    </div>
                    <?php if (isset($errors['gender'])): ?>
                        <span class="error-message"><?= $errors['gender'] ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Любимый язык программирования <span class="required">*</span></label>
                    <select name="languages[]" multiple size="6" class="<?= isset($errors['languages']) ? 'form-error' : '' ?>">
                        <?php foreach ($languagesList as $lang): ?>
                            <option value="<?= $lang['id'] ?>" <?= isSelected('languages', $lang['id'], $displayFormData) ?>><?= htmlspecialchars($lang['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="info-text">Удерживайте Ctrl (Cmd на Mac) для выбора нескольких языков</div>
                    <?php if (isset($errors['languages'])): ?>
                        <span class="error-message"><?= $errors['languages'] ?></span
