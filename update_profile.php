<?php
session_start();
header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$user_id = $_SESSION['user_id'];
$response = ['success' => true];

// ОБНОВЛЕНИЕ ТЕКСТОВЫХ ДАННЫХ (Ник и О себе)
if (isset($_POST['username']) || isset($_POST['bio'])) {
    $username = trim($_POST['username']);
    $bio = trim($_POST['bio']);

    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => 'Имя не может быть пустым']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE users SET username = ?, bio = ? WHERE id = ?");
    $stmt->execute([$username, $bio, $user_id]);
    $_SESSION['username'] = $username; // Обновляем в сессии
}

// ОБНОВЛЕНИЕ АВАТАРА
if (!empty($_FILES['avatar']['name'])) {
    $file = $_FILES['avatar'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (in_array(strtolower($ext), $allowed)) {
        $newName = "ava_" . $user_id . "_" . time() . "." . $ext;
        $path = "uploads/" . $newName;

        if (move_uploaded_file($file['tmp_name'], $path)) {
            // Удаляем старый файл, если он есть
            $old = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $old->execute([$user_id]);
            $oldFile = $old->fetchColumn();
            if ($oldFile && file_exists("uploads/" . $oldFile)) unlink("uploads/" . $oldFile);

            // Пишем новый в базу
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute([$newName, $user_id]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Неверный формат изображения']);
        exit;
    }
}

echo json_encode($response);