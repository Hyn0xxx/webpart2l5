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

        $stmt = $pdo->prepare("
            SELECT *
            FROM applications
            WHERE login = ?
        ");

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

        setcookie(
            'full_name_error',
            'ФИО обязательно и может содержать только буквы, пробелы и дефисы.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    setcookie(
        'full_name_value',
        $_POST['full_name'],
        time() + 30 * 24 * 3600
    );

    // ТЕЛЕФОН

    if (
        empty($_POST['phone']) ||
        !preg_match(
            '/^(\+7|8)?[\s\-]?\(?[0-9]{3}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/',
            $_POST['phone']
        )
    ) {

        setcookie(
            'phone_error',
            'Введите корректный номер телефона.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    setcookie(
        'phone_value',
        $_POST['phone'],
        time() + 30 * 24 * 3600
    );

    // EMAIL

    if (
        empty($_POST['email']) ||
        !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)
    ) {

        setcookie(
            'email_error',
            'Введите корректный e-mail.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    setcookie(
        'email_value',
        $_POST['email'],
        time() + 30 * 24 * 3600
    );

    // ДАТА

    if (empty($_POST['birth_date'])) {

        setcookie(
            'birth_date_error',
            'Выберите дату рождения.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    setcookie(
        'birth_date_value',
        $_POST['birth_date'],
        time() + 30 * 24 * 3600
    );

    // ПОЛ

    if (
        empty($_POST['gender']) ||
        !in_array($_POST['gender'], ['male', 'female', 'other'])
    ) {

        setcookie(
            'gender_error',
            'Выберите пол.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    setcookie(
        'gender_value',
        $_POST['gender'],
        time() + 30 * 24 * 3600
    );

    // ЯЗЫКИ

    $selectedLangs = $_POST['languages'] ?? [];

    if (empty($selectedLangs)) {

        setcookie(
            'languages_error',
            'Выберите хотя бы один язык.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    foreach ($selectedLangs as $langId) {

        if (!in_array($langId, $allowedLanguageIds)) {

            setcookie(
                'languages_error',
                'Выбран недопустимый язык.',
                time() + 24 * 3600
            );

            $errors = true;
        }
    }

    setcookie(
        'languages_value',
        serialize($selectedLangs),
        time() + 30 * 24 * 3600
    );

    // BIO

    setcookie(
        'bio_value',
        $_POST['bio'],
        time() + 30 * 24 * 3600
    );

    // CONTRACT

    if (!isset($_POST['contract'])) {

        setcookie(
            'contract_error',
            'Необходимо принять условия.',
            time() + 24 * 3600
        );

        $errors = true;
    }

    setcookie(
        'contract_value',
        isset($_POST['contract']) ? '1' : '',
        time() + 30 * 24 * 3600
    );

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
                SET
                    full_name=?,
                    phone=?,
                    email=?,
                    birth_date=?,
                    gender=?,
                    bio=?,
                    contract_accepted=?
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

            $pdo->prepare("
                DELETE FROM application_languages
                WHERE application_id=?
            ")->execute([$appId]);

        } else {

            // INSERT

            $login = generateLogin();

            $plainPassword = generatePassword();

            $passwordHash = password_hash(
                $plainPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                INSERT INTO applications
                (
                    full_name,
                    phone,
                    email,
                    birth_date,
                    gender,
                    bio,
                    contract_accepted,
                    login,
                    password_hash
                )
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

        $stmtLang = $pdo->prepare("
            INSERT INTO application_languages
            (application_id, language_id)
            VALUES (?, ?)
        ");

        foreach ($selectedLangs as $langId) {

            $stmtLang->execute([
                $appId,
                $langId
            ]);
        }

        $pdo->commit();

        setcookie(
            'save_success',
            '1',
            time() + 24 * 3600
        );

    } catch(PDOException $e) {

        $pdo->rollBack();

        setcookie(
            'db_error',
            'Ошибка БД: ' . $e->getMessage(),
            time() + 24 * 3600
        );
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

    $messages[] =
        "✅ Ваши данные для входа:<br><br>
        Логин: <b>" . htmlspecialchars($_SESSION['generated_login']) . "</b><br>
        Пароль: <b>" . htmlspecialchars($_SESSION['generated_password']) . "</b>";

    unset($_SESSION['generated_login']);
    unset($_SESSION['generated_password']);
}

// ОШИБКИ

$fields = [
    'full_name',
    'phone',
    'email',
    'birth_date',
    'gender',
    'languages',
    'contract',
    'db_error'
];

foreach ($fields as $f) {

    if (!empty($_COOKIE[$f . '_error'])) {

        $errors[$f] = $_COOKIE[$f . '_error'];

        setcookie($f . '_error', '', 100);
    }
}

// COOKIE VALUES

$values = [];

foreach (
    [
        'full_name',
        'phone',
        'email',
        'birth_date',
        'gender',
        'bio',
        'contract',
        'languages'
    ] as $f
) {

    $values[$f] = $_COOKIE[$f . '_value'] ?? '';
}

$values['languages'] =
    !empty($values['languages'])
    ? unserialize($values['languages'])
    : [];

// ДАННЫЕ АВТОРИЗОВАННОГО

if (isset($_SESSION['user_id'])) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM applications
        WHERE id=?
    ");

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

        $stmt = $pdo->prepare("
            SELECT language_id
            FROM application_languages
            WHERE application_id=?
        ");

        $stmt->execute([$_SESSION['user_id']]);

        $values['languages'] =
            array_column(
                $stmt->fetchAll(),
                'language_id'
            );
    }
}

?>

<!DOCTYPE html>
<html lang="ru">

<head>

<meta charset="UTF-8">

<title>Анкета (Lab 5)</title>

<style>

.form-error {
    border: 2px solid #e74c3c !important;
    background-color: #fff6f6 !important;
}

.error-message {
    color: #e74c3c;
    font-size: 0.85em;
    display: block;
    margin-top: 5px;
}

.success-banner {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

</style>

</head>

<body>

<div class="container">

<div class="header">
    <h1>📝 Анкета разработчика</h1>
</div>

<div class="form-content">

<?php
foreach($messages as $m) {
    echo "<div class='success-banner'>$m</div>";
}

if (!empty($errors['db_error'])) {
    echo "<div class='error-summary'>{$errors['db_error']}</div>";
}
?>

<!-- АВТОРИЗАЦИЯ -->

<?php if (!isset($_SESSION['user_id'])): ?>

<div class="form-group">

<h2 style="margin-bottom:20px;">Авторизация</h2>

<?php if (!empty($loginError)): ?>

<span class="error-message">
    <?= $loginError ?>
</span>

<br>

<?php endif; ?>

<form method="POST">

<div class="form-group">

<label>Логин</label>

<input
    type="text"
    name="login"
    class="<?= !empty($loginError) ? 'form-error' : '' ?>"
>

</div>

<div class="form-group">

<label>Пароль</label>

<input
    type="password"
    name="password"
    class="<?= !empty($loginError) ? 'form-error' : '' ?>"
>

</div>

<button
    type="submit"
    name="login_submit"
    class="btn-submit"
>
    Войти
</button>

</form>

</div>

<hr style="margin: 30px 0;">

<?php else: ?>

<div class="success-banner">
    ✅ Вы авторизованы.
    <a href="?logout=1">Выйти</a>
</div>

<?php endif; ?>

<!-- ОСНОВНАЯ ФОРМА -->

<form action="" method="POST">

<div class="form-group">

<label>ФИО *</label>

<input
    type="text"
    name="full_name"
    value="<?= htmlspecialchars($values['full_name']) ?>"
    class="<?= isset($errors['full_name']) ? 'form-error' : '' ?>"
>

<?php if(isset($errors['full_name'])): ?>

<span class="error-message">
    <?= $errors['full_name'] ?>
</span>

<?php endif; ?>

</div>

<div class="form-group">

<label>Телефон *</label>

<input
    type="tel"
    name="phone"
    value="<?= htmlspecialchars($values['phone']) ?>"
    class="<?= isset($errors['phone']) ? 'form-error' : '' ?>"
>

<?php if(isset($errors['phone'])): ?>

<span class="error-message">
    <?= $errors['phone'] ?>
</span>

<?php endif; ?>

</div>

<div class="form-group">

<label>E-mail *</label>

<input
    type="email"
    name="email"
    value="<?= htmlspecialchars($values['email']) ?>"
    class="<?= isset($errors['email']) ? 'form-error' : '' ?>"
>

<?php if(isset($errors['email'])): ?>

<span class="error-message">
    <?= $errors['email'] ?>
</span>

<?php endif; ?>

</div>

<div class="form-group">

<label>Дата рождения *</label>

<input
    type="date"
    name="birth_date"
    value="<?= htmlspecialchars($values['birth_date']) ?>"
    class="<?= isset($errors['birth_date']) ? 'form-error' : '' ?>"
>

<?php if(isset($errors['birth_date'])): ?>

<span class="error-message">
    <?= $errors['birth_date'] ?>
</span>

<?php endif; ?>

</div>

<div class="form-group <?= isset($errors['gender']) ? 'form-error' : '' ?>">

<label>Пол *</label>

<input
    type="radio"
    name="gender"
    value="male"
    <?= ($values['gender'] == 'male') ? 'checked' : '' ?>
> Мужской

<input
    type="radio"
    name="gender"
    value="female"
    <?= ($values['gender'] == 'female') ? 'checked' : '' ?>
> Женский

<input
    type="radio"
    name="gender"
    value="other"
    <?= ($values['gender'] == 'other') ? 'checked' : '' ?>
> Другой

<?php if(isset($errors['gender'])): ?>

<span class="error-message">
    <?= $errors['gender'] ?>
</span>

<?php endif; ?>

</div>

<div class="form-group">

<label>Любимые языки *</label>

<select
    name="languages[]"
    multiple
    class="<?= isset($errors['languages']) ? 'form-error' : '' ?>"
>

<?php foreach ($languagesList as $lang): ?>

<option
    value="<?= $lang['id'] ?>"
    <?= in_array($lang['id'], $values['languages']) ? 'selected' : '' ?>
>
    <?= htmlspecialchars($lang['name']) ?>
</option>

<?php endforeach; ?>

</select>

<?php if(isset($errors['languages'])): ?>

<span class="error-message">
    <?= $errors['languages'] ?>
</span>

<?php endif; ?>

</div>

<div class="form-group">

<label>Биография</label>

<textarea name="bio"><?= htmlspecialchars($values['bio']) ?></textarea>

</div>

<div class="form-group">

<input
    type="checkbox"
    name="contract"
    value="1"
    <?= !empty($values['contract']) ? 'checked' : '' ?>
>

Согласен с условиями *

<?php if(isset($errors['contract'])): ?>

<span class="error-message">
    <?= $errors['contract'] ?>
</span>

<?php endif; ?>

</div>

<button type="submit" class="btn-submit">
    Отправить
</button>

</form>

</div>
</div>

</body>
</html>
