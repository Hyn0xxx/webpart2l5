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

$messages = [];
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
            header('Location: index.php');
            exit();
        } else {
            $loginError = 'Неверный логин или пароль';
        }
    }
}

// --------------------
// ОБРАБОТКА ОСНОВНОЙ ФОРМЫ
// --------------------

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['login_submit'])) {
    $errors = false;

    // ФИО
    if (
        empty($_POST['full_name']) ||
        !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $_POST['full_name'])
    ) {
        setcookie('full_name_error', 'ФИО обязательно и может содержать только буквы, пробелы и дефисы.', time() + 24 * 3600);
        $errors = true;
    }
    setcookie('full_name_value', $_POST['full_name'], time() + 30 * 24 * 3600);

    // ТЕЛЕФОН
    if (
        empty($_POST['phone']) ||
        !preg_match(
            '/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/',
            $_POST['phone']
        )
    ) {
        setcookie('phone_error', 'Введите корректный номер телефона.', time() + 24 * 3600);
        $errors = true;
    }
    setcookie('phone_value', $_POST['phone'], time() + 30 * 24 * 3600);

    // EMAIL
    if (
        empty($_POST['email']) ||
        !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)
    ) {
        setcookie('email_error', 'Введите корректный e-mail.', time() + 24 * 3600);
        $errors = true;
    }
    setcookie('email_value', $_POST['email'], time() + 30 * 24 * 3600);

    // ДАТА
    if (empty($_POST['birth_date'])) {
        setcookie('birth_date_error', 'Выберите дату рождения.', time() + 24 * 3600);
        $errors = true;
    }
    setcookie('birth_date_value', $_POST['birth_date'], time() + 30 * 24 * 3600);

    // ПОЛ
    if (
        empty($_POST['gender']) ||
        !in_array($_POST['gender'], ['male', 'female', 'other'])
    ) {
        setcookie('gender_error', 'Выберите пол.', time() + 24 * 3600);
        $errors = true;
    }
    setcookie('gender_value', $_POST['gender'], time() + 30 * 24 * 3600);

    // ЯЗЫКИ
    $selectedLangs = $_POST['languages'] ?? [];
    if (empty($selectedLangs)) {
        setcookie('languages_error', 'Выберите хотя бы один язык.', time() + 24 * 3600);
        $errors = true;
    }
    foreach ($selectedLangs as $langId) {
        if (!in_array($langId, $allowedLanguageIds)) {
            setcookie('languages_error', 'Выбран недопустимый язык.', time() + 24 * 3600);
            $errors = true;
        }
    }
    setcookie('languages_value', serialize($selectedLangs), time() + 30 * 24 * 3600);

    // BIO
    setcookie('bio_value', $_POST['bio'], time() + 30 * 24 * 3600);

    // CONTRACT
    if (!isset($_POST['contract'])) {
        setcookie('contract_error', 'Необходимо принять условия.', time() + 24 * 3600);
        $errors = true;
    }
    setcookie('contract_value', isset($_POST['contract']) ? '1' : '', time() + 30 * 24 * 3600);

    // ЕСЛИ ОШИБКИ
    if ($errors) {
        header('Location: index.php');
        exit();
    }

    // --------------------
    // СОХРАНЕНИЕ В БД
    // --------------------

    try {
        $pdo->beginTransaction();

        // UPDATE
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
            $pdo->prepare("DELETE FROM application_languages WHERE application_id=?")->execute([$appId]);
        } else {
            // INSERT
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
            $_SESSION['generated_login'] = $login;
            $_SESSION['generated_password'] = $plainPassword;
        }

        // ЯЗЫКИ
        $stmtLang = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($selectedLangs as $langId) {
            $stmtLang->execute([$appId, $langId]);
        }

        $pdo->commit();
        setcookie('save_success', '1', time() + 24 * 3600);
    } catch(PDOException $e) {
        $pdo->rollBack();
        setcookie('db_error', 'Ошибка БД: ' . $e->getMessage(), time() + 24 * 3600);
    }

    header('Location: index.php');
    exit();
}

// --------------------
// GET ДАННЫЕ
// --------------------

$errors = [];

if (!empty($_COOKIE['save_success'])) {
    setcookie('save_success', '', 100);
    $messages[] = '✅ Данные успешно сохранены!';
}

// ЛОГИН И ПАРОЛЬ ОДИН РАЗ
if (!empty($_SESSION['generated_login'])) {
    $messages[] = "✅ Ваши данные для входа:<br><br>Логин: <b>" . htmlspecialchars($_SESSION['generated_login']) . "</b><br>Пароль: <b>" . htmlspecialchars($_SESSION['generated_password']) . "</b>";
    unset($_SESSION['generated_login']);
    unset($_SESSION['generated_password']);
}

// ОШИБКИ
$fields = ['full_name', 'phone', 'email', 'birth_date', 'gender', 'languages', 'contract', 'db_error'];
foreach ($fields as $f) {
    if (!empty($_COOKIE[$f . '_error'])) {
        $errors[$f] = $_COOKIE[$f . '_error'];
        setcookie($f . '_error', '', 100);
    }
}

// COOKIE VALUES
$values = [];
foreach (['full_name', 'phone', 'email', 'birth_date', 'gender', 'bio', 'contract', 'languages'] as $f) {
    $values[$f] = $_COOKIE[$f . '_value'] ?? '';
}
$values['languages'] = !empty($values['languages']) ? unserialize($values['languages']) : [];

// ДАННЫЕ АВТОРИЗОВАННОГО
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch();

    if ($userData) {
        $values['full_name'] = $userData['full_name'];
        $values['phone'] = $userData['phone'];
        $values['email'] = $userData['email'];
        $values['birth_date'] = $userData['birth_date'];
        $values['gender'] = $userData['gender'];
        $values['bio'] = $userData['bio'];
        $values['contract'] = $userData['contract_accepted'];

        $stmt = $pdo->prepare("SELECT language_id FROM application_languages WHERE application_id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $values['languages'] = array_column($stmt->fetchAll(), 'language_id');
    }
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
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
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
            color: #155724;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-out;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            font-size: 1em;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            width: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .auth-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .auth-section h2 {
            margin-bottom: 20px;
            color: #333;
            font-size: 1.5em;
        }

        .logout-link {
            display: inline-block;
            margin-top: 10px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .logout-link:hover {
            text-decoration: underline;
        }

        hr {
            margin: 30px 0;
            border: none;
            height: 1px;
            background: linear-gradient(to right, transparent, #ccc, transparent);
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
            <?php 
            foreach($messages as $m) {
                echo "<div class='success-banner'>$m</div>";
            }
            if (!empty($errors['db_error'])) {
                echo "<div class='success-banner' style='background:#f8d7da; color:#721c24;'>{$errors['db_error']}</div>";
            }
            ?>

            <!-- АВТОРИЗАЦИЯ -->
            <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="auth-section">
                    <h2>🔐 Авторизация</h2>
                    <?php if (!empty($loginError)): ?>
                        <span class="error-message"><?= $loginError ?></span><br>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label>Логин</label>
                            <input type="text" name="login" class="<?= !empty($loginError) ? 'form-error' : '' ?>">
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
                    ✅ Вы авторизованы.
                    <a href="?logout=1" class="logout-link">Выйти</a>
                </div>
            <?php endif; ?>

            <!-- ОСНОВНАЯ ФОРМА -->
            <form action="" method="POST">
                <div class="form-group">
                    <label>ФИО *</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($values['full_name']) ?>" class="<?= isset($errors['full_name']) ? 'form-error' : '' ?>">
                    <?php if(isset($errors['full_name'])): ?>
                        <span class="error-message"><?= $errors['full_name'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Телефон *</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($values['phone']) ?>" class="<?= isset($errors['phone']) ? 'form-error' : '' ?>">
                    <?php if(isset($errors['phone'])): ?>
                        <span class="error-message"><?= $errors['phone'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>E-mail *</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" class="<?= isset($errors['email']) ? 'form-error' : '' ?>">
                    <?php if(isset($errors['email'])): ?>
                        <span class="error-message"><?= $errors['email'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Дата рождения *</label>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($values['birth_date']) ?>" class="<?= isset($errors['birth_date']) ? 'form-error' : '' ?>">
                    <?php if(isset($errors['birth_date'])): ?>
                        <span class="error-message"><?= $errors['birth_date'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Пол *</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="gender" value="male" <?= ($values['gender'] == 'male') ? 'checked' : '' ?>> Мужской
                        </label>
                        <label>
                            <input type="radio" name="gender" value="female" <?= ($values['gender'] == 'female') ? 'checked' : '' ?>> Женский
                        </label>
                        <label>
                            <input type="radio" name="gender" value="other" <?= ($values['gender'] == 'other') ? 'checked' : '' ?>> Другой
                        </label>
                    </div>
                    <?php if(isset($errors['gender'])): ?>
                        <span class="error-message"><?= $errors['gender'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Любимые языки программирования *</label>
                    <select name="languages[]" multiple class="<?= isset($errors['languages']) ? 'form-error' : '' ?>">
                        <?php foreach ($languagesList as $lang): ?>
                            <option value="<?= $lang['id'] ?>" <?= in_array($lang['id'], $values['languages']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lang['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if(isset($errors['languages'])): ?>
                        <span class="error-message"><?= $errors['languages'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Биография</label>
                    <textarea name="bio"><?= htmlspecialchars($values['bio']) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="contract" value="1" <?= !empty($values['contract']) ? 'checked' : '' ?>>
                        Я согласен с условиями обработки данных *
                    </label>
                    <?php if(isset($errors['contract'])): ?>
                        <span class="error-message"><?= $errors['contract'] ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit">✉️ Отправить анкету</button>
            </form>
        </div>
    </div>
</body>
</html>
