<?php
session_start();
// Устанавливаем заголовок JSON
header('Content-Type: application/json; charset=utf-8');

// Отключаем вывод лишних ошибок, чтобы не ломать JSON ответ
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 1. Проверка прав
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    // 2. Подключение к БД
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $action   = $_REQUEST['action'] ?? '';
    $id       = $_REQUEST['id'] ?? null;
    $username = $_POST['username'] ?? $_GET['username'] ?? null;
    $reason   = $_POST['reason'] ?? $_GET['reason'] ?? 'Нарушение правил';
    $admin_id = $_SESSION['user_id']; // ID того, кто совершает действие

    if ($action === 'toggle_block') {
        
        // Поиск ID по никнейму, если ID не пришел напрямую
        if (!$id && $username) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $id = $stmt->fetchColumn();
            
            if (!$id) {
                echo json_encode(['success' => false, 'error' => "Юзер '$username' не найден"]);
                exit;
            }
        }

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Не указан ID или Ник']);
            exit;
        }

        // Получаем имя цели для логов
        $stmt_name = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt_name->execute([$id]);
        $target_name = $stmt_name->fetchColumn();

        // Проверяем, забанен ли уже
        $check = $pdo->prepare("SELECT COUNT(*) FROM blocked_users WHERE user_id = ?");
        $check->execute([$id]);
        $is_blocked = $check->fetchColumn();

        if ($is_blocked) {
            // --- РАЗБЛОКИРОВКА ---
            $pdo->prepare("DELETE FROM blocked_users WHERE user_id = ?")->execute([$id]);
            
            $log = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_id, target_title) VALUES (?, 'unblock_user', ?, ?)");
            $log->execute([$admin_id, $id, "Пользователь: $target_name"]);
            
            echo json_encode(['success' => true, 'new_status' => 0]);
        } else {
            // --- БЛОКИРОВКА ---
            // Исправлено: теперь передаем blocked_by (ID админа)
            $stmt_block = $pdo->prepare("INSERT INTO blocked_users (user_id, reason, blocked_by) VALUES (?, ?, ?)");
            $stmt_block->execute([$id, $reason, $admin_id]);
            
            $log = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_id, target_title) VALUES (?, 'block_user', ?, ?)");
            $log->execute([$admin_id, $id, "Пользователь: $target_name"]);
            
            echo json_encode(['success' => true, 'new_status' => 1]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Неизвестная команда']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}