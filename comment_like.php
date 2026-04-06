<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['comment_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$comment_id = (int)$_POST['comment_id'];

// Проверяем, существует ли такой комментарий
$stmt = $pdo->prepare("SELECT id FROM comments WHERE id = ?");
$stmt->execute([$comment_id]);
if (!$stmt->fetch()) {
    echo json_encode(['error' => 'Comment not found']);
    exit;
}

// Проверяем, стоит ли уже лайк
$stmt = $pdo->prepare("SELECT id FROM comment_likes WHERE user_id = ? AND comment_id = ?");
$stmt->execute([$user_id, $comment_id]);

if ($stmt->fetch()) {
    $pdo->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?")
        ->execute([$user_id, $comment_id]);
    echo json_encode(['status' => 'removed']);
} else {
    $pdo->prepare("INSERT INTO comment_likes (user_id, comment_id, created_at) VALUES (?, ?, NOW())")
        ->execute([$user_id, $comment_id]);
    echo json_encode(['status' => 'added']);
}
