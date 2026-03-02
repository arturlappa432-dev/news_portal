<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Авторизуйтесь']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? 'create';
    $news_id = $_POST['news_id'] ?? null;

    // --- ЛОГИКА ЗАГРУЗКИ КАРТИНКИ ---
    $image_name = null;
    if (!empty($_FILES['image']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($_FILES['image']['type'], $allowed_types)) {
            throw new Exception("Недопустимый формат изображения");
        }

        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time() . '_' . uniqid() . '.' . $ext;
        
        if (!is_dir('uploads')) {
            mkdir('uploads', 0777, true);
        }
        
        move_uploaded_file($_FILES['image']['tmp_name'], 'uploads/' . $image_name);
    }

    if ($action === 'create') {
        // Добавляем колонку image в запрос
        $sql = "INSERT INTO news (user_id, title, content, category_id, status, image, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id, 
            $_POST['title'], 
            $_POST['content'], 
            $_POST['category_id'], 
            $_POST['status'],
            $image_name // сохраняем имя файла
        ]);
    } 
    elseif ($action === 'publish_existing') {
        $stmt = $pdo->prepare("UPDATE news SET status = 'published' WHERE id = ? AND user_id = ?");
        $stmt->execute([$news_id, $user_id]);
    } 
    elseif ($action === 'update') {
        // Если при редактировании загружено новое фото, обновляем и его
        if ($image_name) {
            $stmt = $pdo->prepare("UPDATE news SET title = ?, content = ?, category_id = ?, image = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['title'], $_POST['content'], $_POST['category_id'], $image_name, $news_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE news SET title = ?, content = ?, category_id = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['title'], $_POST['content'], $_POST['category_id'], $news_id, $user_id]);
        }
    } 
    elseif ($action === 'delete') {
        // (Опционально) Можно добавить удаление файла с диска перед удалением строки из БД
        $stmt = $pdo->prepare("DELETE FROM news WHERE id = ? AND user_id = ?");
        $stmt->execute([$news_id, $user_id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}