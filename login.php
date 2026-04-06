<?php
session_start();

$pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$error = '';
$success = false; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Заполните все поля!";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            
            // --- НОВЫЙ БЛОК: ПРОВЕРКА БЛОКИРОВКИ ---
            $stmt_ban = $pdo->prepare("
                SELECT b.reason, u.username as admin_name 
                FROM blocked_users b
                LEFT JOIN users u ON b.blocked_by = u.id
                WHERE b.user_id = ?
            ");
            $stmt_ban->execute([$user['id']]);
            $ban = $stmt_ban->fetch(PDO::FETCH_ASSOC);

            if ($ban) {
                $admin = htmlspecialchars($ban['admin_name'] ?? 'Система');
                $reason = htmlspecialchars($ban['reason'] ?? 'Не указана');
                $error = "Доступ заблокирован! Админ: $admin. Причина: $reason";
            } else {
                // Если не забанен — пускаем
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $success = true;
            }
            // --- КОНЕЦ БЛОКА ПРОВЕРКИ ---

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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600&display=swap" rel="stylesheet">
<style>
body { margin: 0; font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea, #764ba2); height: 100vh; overflow: hidden; }
.success-banner { position: fixed; top: -100px; left: 0; width: 100%; background: #4caf50; color: white; text-align: center; padding: 20px; font-size: 18px; font-weight: bold; box-shadow: 0 4px 15px rgba(0,0,0,0.2); z-index: 9999; transition: transform 0.5s ease-in-out; }
.success-banner.show { transform: translateY(100px); }
.form-container { background: white; padding: 40px; border-radius: 12px; width: 360px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
h2 { text-align: center; color: #333; margin-bottom: 20px; }
.error { color: #ff4d4f; background: #fff2f0; border: 1px solid #ffccc7; padding: 12px; border-radius: 8px; text-align: center; margin-bottom: 15px; font-size: 13px; line-height: 1.4; }
.link { display: block; text-align: center; margin-top: 15px; color: #777; font-size: 13px; }
.link a { color: #667eea; text-decoration: none; font-weight: bold; }
</style>
</head>
<body>

<div id="successBanner" class="success-banner">
    🎉 Вход выполнен успешно! Сейчас вы будете перенаправлены...
</div>

<div class="d-flex vh-100 justify-content-center align-items-center">
    <div class="form-container">
        <h2>Вход</h2>
        <?php if ($error): ?>
            <div class="error">
                <i class="bi bi-exclamation-triangle"></i> <?= $error ?>
            </div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <input type="text" name="username" class="form-control rounded-3" placeholder="Логин" required>
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

<script>
<?php if ($success): ?>
    const banner = document.getElementById('successBanner');
    setTimeout(() => { banner.classList.add('show'); }, 100);
    setTimeout(() => { window.location.href = 'index.php'; }, 2000);
<?php endif; ?>
</script>

</body>
</html>