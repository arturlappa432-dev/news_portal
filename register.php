<?php
session_start();

$pdo = new PDO(
    "mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4",
    "root",
    "",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]
);

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // ── БАЗОВЫЕ ПРОВЕРКИ ─────────────────────
    if (empty($username) || empty($email) || empty($password)) {
        $error = "Заполните все поля!";
    }
    elseif (strlen($username) < 2) {
        $error = "Имя пользователя должно быть минимум 2 символа!";
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Некорректный email!";
    }
    elseif (strlen($password) < 6) {
        $error = "Пароль должен быть минимум 6 символов!";
    }

    // ── УЛУЧШЕННАЯ ПРОВЕРКА EMAIL ─────────────────────
    else {

        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            $error = "Email должен содержать @ и домен!";
        } else {

            $emailName = $parts[0];
            $emailDomain = $parts[1];

            if (strlen($emailName) < 2) {
                $error = "Имя до @ должно быть минимум 2 символа!";
            }
            elseif (strlen($emailDomain) < 3 || strpos($emailDomain, '.') === false) {
                $error = "Некорректный домен email!";
            }
        }
    }

    // ── ПРОВЕРКА В БД ─────────────────────────
    if (!$error) {

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Пользователь с таким email уже существует!";
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = "Имя пользователя уже занято!";
        }
    }

    // ── СОЗДАНИЕ АККАУНТА ─────────────────────
    if (!$error) {

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $default_avatar = null;

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, avatar)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $username,
            $email,
            $password_hash,
            $default_avatar
        ]);

        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['username'] = $username;

        $success = true;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Регистрация</title>
<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    overflow: hidden;
}

.success-banner {
    position: fixed;
    top: -100px;
    left: 0;
    width: 100%;
    background: #4caf50;
    color: white;
    text-align: center;
    padding: 20px;
    font-size: 18px;
    font-weight: bold;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    z-index: 9999;
    transition: transform 0.5s ease-in-out;
}

.success-banner.show {
    transform: translateY(100px);
}

.form-container {
    background: white;
    padding: 50px 35px;
    border-radius: 12px;
    width: 280px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    display: flex;
    flex-direction: column;
}

h2 {
    text-align: center;
    margin-bottom: 30px;
    color: #333;
}

.field {
    margin-bottom: 20px;
}

input {
    width: 100%;
    padding: 14px;
    border-radius: 8px;
    border: 1px solid #ddd;
    font-size: 14px;
    box-sizing: border-box;
}

input:focus {
    border-color: #667eea;
    outline: none;
}

button {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 8px;
    background: #667eea;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: 0.3s;
    margin-top: 10px;
}

button:hover {
    background: #5a67d8;
}

.error {
    color: #ff4d4f;
    background: #fff2f0;
    border: 1px solid #ffccc7;
    padding: 10px;
    border-radius: 6px;
    text-align: center;
    margin-bottom: 20px;
    font-size: 13px;
}

.link {
    text-align: center;
    margin-top: 25px;
    font-size: 13px;
    color: #777;
}

.link a {
    color: #667eea;
    text-decoration: none;
    font-weight: bold;
}
</style>
</head>
<body>

<div id="successBanner" class="success-banner">
    🎉 Регистрация прошла успешно! Сейчас вы будете перенаправлены...
</div>

<div class="form-container">
    <h2>Регистрация</h2>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="field">
            <input type="text" name="username" placeholder="Имя пользователя" required>
        </div>

        <div class="field">
            <input type="email" name="email" placeholder="Email" required>
        </div>

        <div class="field">
            <input type="password" name="password" placeholder="Пароль" required>
        </div>

        <button type="submit">Создать аккаунт</button>
    </form>

    <div class="link">
        Уже зарегистрированы? <a href="login.php">Войти</a>
    </div>
</div>

<script>
<?php if ($success): ?>
const banner = document.getElementById('successBanner');

setTimeout(() => {
    banner.classList.add('show');
}, 100);

setTimeout(() => {
    window.location.href = 'index.php';
}, 3000);
<?php endif; ?>
</script>

</body>
</html>