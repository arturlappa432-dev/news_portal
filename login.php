<?php
session_start();

$pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$error = '';
$success = false; // Флаг успешного входа

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Заполните все поля!";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $success = true;
        } else {
            $error = "Неверный логин или пароль!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Вход</title>

<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Google Fonts (Segoe UI) -->
<link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600&display=swap" rel="stylesheet">

<style>
body {
    margin: 0;
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #667eea, #764ba2);
    height: 100vh;
    overflow: hidden;
}

/* Успешная плашка */
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
    padding: 40px;
    border-radius: 12px;
    width: 340px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}

h2 {
    text-align: center;
    color: #333;
    margin-bottom: 20px;
}

.error {
    color: #ff4d4f;
    background: #fff2f0;
    border: 1px solid #ffccc7;
    padding: 10px;
    border-radius: 6px;
    text-align: center;
    margin-bottom: 15px;
    font-size: 13px;
}

.link {
    display: block;
    text-align: center;
    margin-top: 15px;
    color: #777;
    font-size: 13px;
}

.link a {
    color: #667eea;
    text-decoration: none;
    font-weight: bold;
}
</style>
</head>
<body>

<!-- Плашка успешного входа -->
<div id="successBanner" class="success-banner">
    🎉 Вход выполнен успешно! Сейчас вы будете перенаправлены...
</div>

<div class="d-flex vh-100 justify-content-center align-items-center">
    <div class="form-container">
        <h2>Вход</h2>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <input type="text" name="username" class="form-control rounded-3" placeholder="Логин (Имя пользователя)" required>
            </div>
            <div class="mb-3">
                <input type="password" name="password" class="form-control rounded-3" placeholder="Пароль" required>
            </div>
            <button type="submit" class="btn w-100 rounded-3" style="background: #667eea; color: white; font-weight: bold;">Войти</button>
        </form>

        <div class="link">
            <a href="register.php">Создать аккаунт</a>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
<?php if ($success): ?>
    const banner = document.getElementById('successBanner');
    setTimeout(() => { banner.classList.add('show'); }, 100);
    setTimeout(() => { window.location.href = 'index.php'; }, 3000);
<?php endif; ?>
</script>

</body>
</html>