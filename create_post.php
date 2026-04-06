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

    // Получаем роль пользователя для проверки прав
    $stmt_user = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user_role = $stmt_user->fetchColumn();

    // --- ЛОГИКА ЗАГРУЗКИ КАРТИНКИ ---
    $image_name = null;
    if (!empty($_FILES['image']['name']) && $action !== 'delete') {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($_FILES['image']['type'], $allowed_types)) {
            throw new Exception("Недопустимый формат изображения");
        }
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time() . '_' . uniqid() . '.' . $ext;
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        move_uploaded_file($_FILES['image']['tmp_name'], 'uploads/' . $image_name);
    }

    if ($action === 'create') {

    $title = $_POST['title'];
    $content = $_POST['content'];
    $category_id = $_POST['category_id'];
    $status = $_POST['status'] ?? 'published';
    $visibility = $_POST['visibility'] ?? 'public';

    $sql = "INSERT INTO news 
        (user_id, title, content, category_id, status, visibility, image, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $pdo->prepare($sql)->execute([
        $user_id,
        $title,
        $content,
        $category_id,
        $status,
        $visibility,
        $image_name
    ]);
}
    elseif ($action === 'delete') {
        // Проверяем, существует ли пост и кто его автор
        $stmt_check = $pdo->prepare("SELECT user_id, image FROM news WHERE id = ?");
        $stmt_check->execute([$news_id]);
        $post_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$post_data) throw new Exception("Пост не найден");

        // Удалять может либо автор, либо админ
        if ($user_role === 'admin' || $post_data['user_id'] == $user_id) {
            // Удаляем картинку с сервера
            if ($post_data['image'] && file_exists('uploads/' . $post_data['image'])) {
                unlink('uploads/' . $post_data['image']);
            }
            // Удаляем запись из БД
            $stmt_del = $pdo->prepare("DELETE FROM news WHERE id = ?");
            $stmt_del->execute([$news_id]);
        } else {
            throw new Exception("У вас нет прав на удаление этого поста");
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}