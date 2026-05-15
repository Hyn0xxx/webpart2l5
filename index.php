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
    
    // Таблица связи заявка-язык (один ко многим)
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
    
    // Таблица для хранения учетных записей пользователей
    $sql_users = "
        CREATE TABLE IF NOT EXISTS application_users (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT(10) UNSIGNED NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            UNIQUE KEY unique_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql_users);
}

// Функция для генерации случайного логина
function generateUsername($fullName, $pdo) {
    // Очищаем ФИО от специальных символов
    $cleanName = preg_replace('/[^a-zA-Zа-яА-Я]/u', '', $fullName);
    $cleanName = mb_substr($cleanName, 0, 20);
    
    // Транслитерация для русского текста (упрощенная)
    $translit = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
        'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
        'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
        'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '',
        'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
    ];
    
    $transliterated = strtr($cleanName, $translit);
    if (empty($transliterated)) {
        $transliterated = 'user';
    }
    
    $baseUsername = strtolower($transliterated);
    $username = $baseUsername;
    $counter = 1;
    
    // Проверяем уникальность логина
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM application_users WHERE username = ?");
    while (true) {
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() == 0) {
            break;
        }
        $username = $baseUsername . $counter;
        $counter++;
    }
    
    return $username;
}

// Функция для генерации случайного пароля
function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Получение списка языков для формы
$languagesList = $pdo->query("SELECT id, name FROM programming_languages ORDER BY name")->fetchAll();

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

// Обработка POST-запроса
$errors = [];
$success = false;
$formData = [];
$generatedCredentials = null;
$message = '';

// Проверка аутентификации для редактирования
$isAuthenticated = isset($_SESSION['user_id']) && isset($_SESSION['application_id']);
$editingApplicationId = null;
$editingData = null;
$displayFormData = [];

// Если есть ID заявки в GET и пользователь аутентифицирован
if ($isAuthenticated && isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editingApplicationId = (int)$_GET['edit'];
    // Проверяем, что пользователь имеет право редактировать эту заявку
    if ($_SESSION['application_id'] == $editingApplicationId) {
        try {
            // Получаем данные заявки
            $stmt = $pdo->prepare("
                SELECT a.*, GROUP_CONCAT(al.language_id) as language_ids
                FROM applications a
                LEFT JOIN application_languages al ON a.id = al.application_id
                WHERE a.id = ?
                GROUP BY a.id
            ");
            $stmt->execute([$editingApplicationId]);
            $editingData = $stmt->fetch();
            
            if ($editingData) {
                // Преобразуем строку с ID языков в массив
                $editingData['languages'] = $editingData['language_ids'] ? explode(',', $editingData['language_ids']) : [];
                $displayFormData = $editingData;
            }
        } catch(PDOException $e) {
            $errors['database'] = 'Ошибка загрузки данных для редактирования';
        }
    } else {
        $errors['auth'] = 'У вас нет прав для редактирования этой заявки';
    }
}

// Обработка входа
if (isset($_POST['login_action']) && $_POST['login_action'] == '1') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $errors['login'] = 'Пожалуйста, введите логин и пароль';
    } else {
        $stmt = $pdo->prepare("
            SELECT u.*, a.full_name 
            FROM application_users u
            JOIN applications a ON u.application_id = a.id
            WHERE u.username = ?
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['application_id'] = $user['application_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            
            // Перенаправляем на форму редактирования
            header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?') . '?edit=' . $user['application_id']);
            exit;
        } else {
            $errors['login'] = 'Неверный логин или пароль';
        }
    }
}

// Обработка выхода
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Обработка сохранения/обновления данных
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
        $errors['full_name'] = 'ФИО может содержать только буквы, пробелы и дефисы.';
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
    
    // Если есть ошибки - сохраняем в Cookies
    if (!empty($errors)) {
        saveErrorsToCookie($errors);
        setcookie('temp_form_data', json_encode($formData), 0, '/');
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }
    
    // Сохраняем или обновляем данные
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            if ($isAuthenticated && $editingApplicationId) {
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
                    ':id' => $editingApplicationId
                ]);
                
                // Удаляем старые связи с языками
                $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$editingApplicationId]);
                
                // Вставляем новые связи
                $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
                foreach ($formData['languages'] as $langId) {
                    $stmtLang->execute([$editingApplicationId, $langId]);
                }
                
                $pdo->commit();
                $success = true;
                $message = 'Данные успешно обновлены!';
                
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
                
                // Генерация учетных данных
                $username = generateUsername($formData['full_name'], $pdo);
                $plainPassword = generatePassword();
                $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
                
                // Сохраняем учетные данные
                $stmtUser = $pdo->prepare("
                    INSERT INTO application_users (application_id, username, password_hash)
                    VALUES (?, ?, ?)
                ");
                $stmtUser->execute([$applicationId, $username, $passwordHash]);
                
                $pdo->commit();
                $success = true;
                $generatedCredentials = [
                    'username' => $username,
                    'password' => $plainPassword
                ];
                $message = 'Заявка успешно сохранена!';
                
                // Автоматически авторизуем пользователя после регистрации
                $_SESSION['user_id'] = $stmtUser->lastInsertId();
                $_SESSION['application_id'] = $applicationId;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $formData['full_name'];
            }
            
            // Сохраняем данные в Cookies
            saveFormDataToCookie($formData);
            setcookie('temp_form_data', '', time() - 3600, '/');
            
            if ($isAuthenticated && $editingApplicationId) {
                header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?') . '?edit=' . $editingApplicationId . '&updated=1');
            } else {
                header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?') . '?success=1');
            }
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
}

// Получаем данные для отображения формы
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = true;
    $message = 'Заявка успешно сохранена!';
}
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $success = true;
    $message = 'Данные успешно обновлены!';
}

$errors = getErrorsFromCookie();

// Получаем временные данные
$tempFormData = [];
if (isset($_COOKIE['temp_form_data'])) {
    $tempFormData = json_decode($_COOKIE['temp_form_data'], true);
    setcookie('temp_form_data', '', time() - 3600, '/');
}

// Если не в режиме редактирования, берем сохраненные данные
if (!$editingData) {
    $savedFormData = getSavedFormDataFromCookie();
    if (!empty($tempFormData)) {
        $displayFormData = $tempFormData;
    } elseif (!empty($savedFormData) && $_SERVER['REQUEST_METHOD'] != 'POST') {
        $displayFormData = $savedFormData;
    } else {
        $displayFormData = [];
    }
}

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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #e8ecf2;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: #5a6e7c;
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .auth-buttons {
            position: absolute;
            top: 20px;
            right: 20px;
        }
        
        .auth-buttons a {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .auth-buttons a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.85;
            font-size: 14px;
        }
        
        .form-content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #e74c3c;
            margin-left: 5px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="tel"],
        .form-group input[type="email"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
            background: #fafafa;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #5a6e7c;
            box-shadow: 0 0 0 3px rgba(90, 110, 124, 0.1);
            background: white;
        }
        
        .radio-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            font-weight: normal;
            cursor: pointer;
        }
        
        .radio-group input[type="radio"] {
            margin-right: 8px;
            cursor: pointer;
        }
        
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            font-weight: normal;
            cursor: pointer;
            background: #f0f2f5;
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }
        
        .checkbox-group label:hover {
            background: #e0e4e8;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }
        
        select[multiple] {
            height: auto;
            min-height: 150px;
        }
        
        select[multiple] option {
            padding: 8px;
            cursor: pointer;
        }
        
        select[multiple] option:checked {
            background: #5a6e7c linear-gradient(0deg, #5a6e7c 0%, #5a6e7c 100%);
            color: white;
        }
        
        .error-message {
            color: #e74c3c;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }
        
        .form-error {
            border-color: #e74c3c !important;
            background-color: #fff5f5 !important;
        }
        
        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #4caf50;
        }
        
        .credentials-box {
            background: #fff3e0;
            color: #e65100;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #ff9800;
        }
        
        .credentials-box strong {
            display: block;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .credentials-box code {
            background: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
            margin-top: 5px;
            font-size: 14px;
        }
        
        .error-summary {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #f44336;
        }
        
        .error-summary ul {
            margin-left: 20px;
            margin-top: 10px;
        }
        
        .btn-submit {
            background: #5a6e7c;
            color: white;
            border: none;
            padding: 14px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s ease;
        }
        
        .btn-submit:hover {
            background: #4a5c68;
            transform: translateY(-1px);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .login-form {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        
        .login-form h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .login-form .form-group {
            margin-bottom: 15px;
        }
        
        .login-form button {
            background: #4caf50;
            width: auto;
            padding: 10px 20px;
        }
        
        .user-info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-info span {
            color: #1976d2;
            font-weight: 600;
        }
        
        .user-info a {
            color: #f44336;
            text-decoration: none;
        }
        
        hr {
            margin: 20px 0;
            border: none;
            height: 1px;
            background: #e0e0e0;
        }
        
        .info-text {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        
        @media (max-width: 600px) {
            .form-content {
                padding: 20px;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
    <script>
        function showLoginForm() {
            var form = document.getElementById('loginForm');
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }
    </script>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="auth-buttons">
                <?php if ($isAuthenticated): ?>
                    <span style="margin-right: 10px;">👋 <?= htmlspecialchars($_SESSION['full_name']) ?></span>
                    <a href="?logout=1">🚪 Выйти</a>
                <?php else: ?>
                    <a href="#" onclick="showLoginForm(); return false;">🔑 Войти</a>
                <?php endif; ?>
            </div>
            <h1>📝 Анкета разработчика</h1>
            <p>Заполните форму, чтобы стать частью нашего сообщества</p>
        </div>
        
        <div class="form-content">
            <?php if ($success && !empty($message)): ?>
                <div class="success-message">
                    ✅ <?= htmlspecialchars($message) ?>
                </div>
            <?php elseif ($success): ?>
                <div class="success-message">
                    ✅ Спасибо! Ваши данные успешно сохранены.
                </div>
            <?php endif; ?>
            
            <?php if ($generatedCredentials): ?>
                <div class="credentials-box">
                    <strong>🔐 Ваши учетные данные для входа:</strong>
                    <div>Логин: <code><?= htmlspecialchars($generatedCredentials['username']) ?></code></div>
                    <div>Пароль: <code><?= htmlspecialchars($generatedCredentials['password']) ?></code></div>
                    <div class="info-text" style="margin-top: 10px;">⚠️ Сохраните эти данные! Они понадобятся вам для редактирования заявки.</div>
                </div>
            <?php endif; ?>
            
            <?php if (!$isAuthenticated && !$editingData): ?>
                <div class="login-form" id="loginForm" style="display: none;">
                    <h3>🔐 Вход для редактирования заявки</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="login_action" value="1">
                        <div class="form-group">
                            <label>Логин</label>
                            <input type="text" name="username" required>
                        </div>
                        <div class="form-group">
                            <label>Пароль</label>
                            <input type="password" name="password" required>
                        </div>
                        <?php if (isset($errors['login'])): ?>
                            <span class="error-message"><?= $errors['login'] ?></span>
                        <?php endif; ?>
                        <button type="submit" class="btn-submit">Войти</button>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if ($isAuthenticated && $editingData): ?>
                <div class="user-info">
                    <span>✏️ Режим редактирования заявки #<?= $editingApplicationId ?></span>
                    <a href="?logout=1">Выйти</a>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors) && !isset($errors['login'])): ?>
                <div class="error-summary">
                    <strong>❌ Пожалуйста, исправьте следующие ошибки:</
