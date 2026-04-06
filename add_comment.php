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
    echo json_encode(['success' => false, 'error' => 'Нужна авторизация']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? 'add';

// Получаем роль пользователя
$stmt_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt_role->execute([$user_id]);
$user_role = $stmt_role->fetchColumn();


// --- УДАЛЕНИЕ КОММЕНТАРИЯ ---
if ($action === 'delete') {
    $comment_id = (int)$_POST['comment_id'];

    if ($user_role === 'admin') {
        // Сначала удаляем все вложенные ответы
        $pdo->prepare("DELETE FROM comments WHERE parent_id = ?")->execute([$comment_id]);
        // Затем сам комментарий
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$comment_id]);
    } else {
        // Проверяем, что это комментарий пользователя
        $stmt_own = $pdo->prepare("SELECT id FROM comments WHERE id = ? AND user_id = ?");
        $stmt_own->execute([$comment_id, $user_id]);
        if (!$stmt_own->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Нет прав на удаление']);
            exit;
        }
        // Удаляем вложенные ответы
        $pdo->prepare("DELETE FROM comments WHERE parent_id = ?")->execute([$comment_id]);
        // Удаляем сам комментарий
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?");
        $stmt->execute([$comment_id, $user_id]);
    }

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ошибка удаления или нет прав']);
    }
    exit;
}


// --- РЕДАКТИРОВАНИЕ КОММЕНТАРИЯ ---
if ($action === 'edit') {
    $comment_id = (int)$_POST['comment_id'];
    $content = trim($_POST['content']);

    if (empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Пустой текст']);
        exit;
    }

    // Проверяем, что комментарий существует, принадлежит пользователю и не старше 5 минут
    $stmt_check = $pdo->prepare("SELECT id FROM comments WHERE id = ? AND user_id = ? AND created_at > (NOW() - INTERVAL 5 MINUTE)");
    $stmt_check->execute([$comment_id, $user_id]);
    if (!$stmt_check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Время редактирования истекло (5 минут)']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE comments SET content = ? WHERE id = ? AND user_id = ?");

    if ($stmt->execute([$content, $comment_id, $user_id]) && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Нет прав или ошибка']);
    }
    exit;
}


// --- ДОБАВЛЕНИЕ КОММЕНТАРИЯ ---
if ($action === 'add') {
    $news_id = (int)$_POST['news_id'];
    $content = trim($_POST['content']);
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if (empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Пустой текст']);
        exit;
    }

    // Проверка лимита спама (5 в минуту)
    $stmt_limit = $pdo->prepare("
        SELECT COUNT(*) FROM comments 
        WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)
    ");
    $stmt_limit->execute([$user_id]);
    if ($stmt_limit->fetchColumn() >= 5) {
        echo json_encode(['success' => false, 'error' => 'Лимит: 5 комментариев в минуту.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO comments (news_id, user_id, parent_id, content, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");

    if ($stmt->execute([$news_id, $user_id, $parent_id, $content])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ошибка сохранения']);
    }
    exit;
}