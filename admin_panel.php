<?php
session_start();

// --- ПРОВЕРКА ДОСТУПА ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo "<div style='background:#fee2e2; padding:20px; border-radius:10px; font-family:sans-serif; max-width:500px; margin:20px auto; border:1px solid #ef4444;'>";
    echo "<h2 style='color:#b91c1c; margin-top:0;'>Доступ закрыт</h2>";
    echo "<p>Ваша роль: <b>" . ($_SESSION['role'] ?? 'Не установлена') . "</b></p>";
    echo "<hr><p style='font-size:13px;'>Пожалуйста, перезайдите в аккаунт, чтобы права администратора вступили в силу.</p>";
    echo "</div>";
    die();
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// 1. ПОЛУЧАЕМ СПИСОК ПОЛЬЗОВАТЕЛЕЙ
$user_list = $pdo->prepare("
    SELECT u.id, u.username, u.role, 
    (SELECT COUNT(*) FROM blocked_users WHERE user_id = u.id) as is_blocked 
    FROM users u WHERE u.id != ?
");
$user_list->execute([$_SESSION['user_id']]);
$users = $user_list->fetchAll();

// 2. ПОЛУЧАЕМ ЛОГИ С ПРИЧИНАМИ
$logs = $pdo->query("
    SELECT l.*, u.username as admin_name, b.reason as block_reason
    FROM admin_logs l 
    LEFT JOIN users u ON l.admin_id = u.id 
    LEFT JOIN blocked_users b ON l.target_id = b.user_id AND l.action_type = 'block_user'
    ORDER BY l.created_at DESC 
    LIMIT 40
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #6366f1; --red: #ef4444; --green: #22c55e; --bg: #f8fafc; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #1e293b; margin: 0; padding: 40px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; }
        .header h1 { font-weight: 800; margin: 0; font-size: 28px; }
        .btn-back { text-decoration: none; color: var(--primary); font-weight: 600; display: flex; align-items: center; gap: 8px; }

        .tabs { display: flex; gap: 10px; margin-bottom: 30px; background: #e2e8f0; padding: 5px; border-radius: 12px; width: fit-content; }
        .tab { padding: 10px 25px; cursor: pointer; border-radius: 8px; font-weight: 600; color: #64748b; transition: 0.2s; }
        .tab.active { background: white; color: var(--primary); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }

        .card { background: white; border-radius: 20px; padding: 30px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .section { display: none; }
        .section.active { display: block; animation: fadeIn 0.3s ease; }

        .block-form { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; margin-bottom: 30px; padding: 20px; background: #f1f5f9; border-radius: 15px; }
        .block-form input { padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid var(--border); }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border); font-size: 14px; }
        
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .btn-action { padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px; transition: 0.2s; }
        .btn-red { background: #fee2e2; color: var(--red); }
        .btn-green { background: #dcfce7; color: var(--green); }

        /* Черный цвет причины */
        .reason-text { color: #000000; font-weight: 500; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fa-solid fa-screwdriver-wrench"></i> Админ-панель</h1>
        <a href="index.php" class="btn-back"><i class="fa-solid fa-house"></i> На сайт</a>
    </div>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('users-tab', this)">Пользователи</div>
        <div class="tab" onclick="switchTab('logs-tab', this)">Логи действий</div>
    </div>

    <div id="users-tab" class="section active">
        <div class="card">
            <h3 style="margin-top:0; margin-bottom:15px;">Быстрое управление</h3>
            <form id="quickBlockForm" class="block-form">
                <input type="hidden" name="action" value="toggle_block">
                <input type="text" name="username" placeholder="Точный никнейм" required>
                <input type="text" name="reason" placeholder="Причина">
                <button type="submit" class="btn-action" style="background:var(--primary); color:white;">Забанить</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Роль</th>
                        <th>Статус</th>
                        <th>Управление</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                        <td><span style="color:#64748b"><?= $u['role'] ?></span></td>
                        <td>
                            <span class="badge" style="<?= $u['is_blocked'] ? 'background:#fee2e2;color:var(--red)' : 'background:#dcfce7;color:var(--green)' ?>">
                                <?= $u['is_blocked'] ? 'Заблокирован' : 'Активен' ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-action <?= $u['is_blocked'] ? 'btn-green' : 'btn-red' ?>" onclick="toggleBlock(<?= $u['id'] ?>)">
                                <?= $u['is_blocked'] ? 'Разблокировать' : 'Заблокировать' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="logs-tab" class="section">
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th style="width: 15%;">Время</th>
                        <th style="width: 15%;">Админ</th>
                        <th style="width: 15%;">Действие</th>
                        <th style="width: 25%;">Объект / Цель</th>
                        <th style="width: 30%;">Причина</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($logs as $l): ?>
                    <tr>
                        <td style="color:#64748b; font-size:12px;"><?= date('d.m H:i:s', strtotime($l['created_at'])) ?></td>
                        <td><b><?= htmlspecialchars($l['admin_name'] ?? 'Система') ?></b></td>
                        <td>
                            <?php if ($l['action_type'] === 'block_user'): ?>
                                <span class="badge" style="background:#fee2e2; color:var(--red);">Заблокировать</span>
                            <?php elseif ($l['action_type'] === 'unblock_user'): ?>
                                <span class="badge" style="background:#dcfce7; color:#166534;">Разблокировать</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9; color:#475569;"><?= $l['action_type'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><b><?= htmlspecialchars($l['target_title'] ?? 'ID: '.$l['target_id']) ?></b></td>
                        <td class="reason-text">
                            <?php if ($l['action_type'] === 'block_user'): ?>
                                <?= htmlspecialchars($l['block_reason'] ?? 'Причина не указана') ?>
                            <?php elseif ($l['action_type'] === 'unblock_user'): ?>
                                <span style="color:var(--green)">Доступ восстановлен</span>
                            <?php else: ?>
                                <span style="color:#cbd5e1">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function switchTab(id, el) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    el.classList.add('active');
}

// Отправка формы (через Никнейм)
document.getElementById('quickBlockForm').onsubmit = async function(e) {
    e.preventDefault();
    const res = await fetch('admin_actions.php', { method: 'POST', body: new FormData(this) });
    const data = await res.json();
    if (data.success) {
        location.reload();
    } else {
        alert('Ошибка: ' + data.error);
    }
};

// Кнопка переключения статуса
async function toggleBlock(id) {
    if(!confirm('Изменить статус доступа?')) return;
    const res = await fetch(`admin_actions.php?action=toggle_block&id=${id}`);
    const data = await res.json();
    if (data.success) {
        location.reload(); // Перезагружаем для обновления истории логов
    }
}
</script>

</body>
</html>