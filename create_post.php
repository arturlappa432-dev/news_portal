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

    if ($action === 'create') {
        // Создание нового поста (черновика или публикации)
        $stmt = $pdo->prepare("INSERT INTO news (user_id, title, content, category_id, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $_POST['title'], $_POST['content'], $_POST['category_id'], $_POST['status']]);
    } 
    elseif ($action === 'publish_existing') {
        // Быстрая публикация уже существующего черновика
        $stmt = $pdo->prepare("UPDATE news SET status = 'published' WHERE id = ? AND user_id = ?");
        $stmt->execute([$news_id, $user_id]);
    } 
    elseif ($action === 'update') {
        // Редактирование черновика: заголовок, текст и КАТЕГОРИЯ
        $stmt = $pdo->prepare("UPDATE news SET title = ?, content = ?, category_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([
            $_POST['title'], 
            $_POST['content'], 
            $_POST['category_id'], 
            $news_id, 
            $user_id
        ]);
    } 
    elseif ($action === 'delete') {
        // Удаление черновика
        $stmt = $pdo->prepare("DELETE FROM news WHERE id = ? AND user_id = ?");
        $stmt->execute([$news_id, $user_id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}