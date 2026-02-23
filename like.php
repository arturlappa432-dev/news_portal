<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error']); exit;
}

$pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "");
$user_id = $_SESSION['user_id'];
$news_id = (int)$_POST['news_id'];

// Проверка существования лайка
$stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND news_id = ?");
$stmt->execute([$user_id, $news_id]);

if ($stmt->fetch()) {
    $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND news_id = ?");
    $stmt->execute([$user_id, $news_id]);
    echo json_encode(['status' => 'removed']);
} else {
    $stmt = $pdo->prepare("INSERT INTO likes (user_id, news_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $news_id]);
    echo json_encode(['status' => 'added']);
}