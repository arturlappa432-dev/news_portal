<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_POST['news_id'])) exit;

$pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "");
$user_id = $_SESSION['user_id'];
$news_id = $_POST['news_id'];

// Проверяем, есть ли уже лайк
$stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND news_id = ?");
$stmt->execute([$user_id, $news_id]);

if ($stmt->fetch()) {
    $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND news_id = ?")->execute([$user_id, $news_id]);
    echo json_encode(['status' => 'removed']);
} else {
    $pdo->prepare("INSERT INTO likes (user_id, news_id) VALUES (?, ?)")->execute([$user_id, $news_id]);
    echo json_encode(['status' => 'added']);
}