<?php
session_start();

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Ошибка БД: " . $e->getMessage());
}

// 1. ОПРЕДЕЛЯЕМ, ЧЕЙ ПРОФИЛЬ
$is_my_profile = true;
$view_id = $_SESSION['user_id'] ?? null;

// Если в URL передан id и он не совпадает с нашим, значит смотрим чужой профиль
if (isset($_GET['id']) && (!isset($_SESSION['user_id']) || $_GET['id'] != $_SESSION['user_id'])) {
    $view_id = (int)$_GET['id'];
    $is_my_profile = false;
}

// Если ID нет вообще (не залогинен и нет ID в URL) — на вход
if (!$view_id) {
    header("Location: login.php");
    exit;
}

// 2. ПОЛУЧАЕМ ДАННЫЕ
$stmt = $pdo->prepare("
    SELECT u.*, 
    (SELECT COUNT(*) FROM news WHERE user_id = u.id AND status = 'published') as posts_count,
    (SELECT COUNT(*) FROM likes WHERE user_id = u.id) as likes_given,
    (SELECT COUNT(*) FROM comments WHERE user_id = u.id) as comments_count
    FROM users u WHERE u.id = ?
");
$stmt->execute([$view_id]);
$profile = $stmt->fetch();

if (!$profile) die("Пользователь не найден.");

// 3. ЛОГИКА ЗВАНИЙ
$rank = "Новичок";
$rank_color = "#64748b";

if ($profile['role'] === 'admin') {
    $rank = "Администратор";
    $rank_color = "#ef4444";
} else {
    if ($profile['posts_count'] >= 10) {
        $rank = "Звезда портала";
        $rank_color = "#f59e0b";
    } elseif ($profile['posts_count'] >= 5) {
        $rank = "Активный автор";
        $rank_color = "#6366f1";
    }
}

// Функция аватара
function getAvatar($filename, $username) {
    if (!empty($filename) && file_exists("uploads/" . $filename)) {
        return "uploads/" . $filename;
    }
    return "https://ui-avatars.com/api/?background=6366f1&color=fff&bold=true&size=128&name=" . urlencode($username);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($profile['username']) ?> | Профиль</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f4f6; --white: #ffffff; --primary: #6366f1;
            --text-dark: #1e293b; --text-muted: #64748b; --border: #e2e8f0;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-dark); margin: 0; padding: 0; }
        .header-simple { height: 72px; display: flex; align-items: center; padding: 0 8%; background: var(--white); border-bottom: 1px solid var(--border); }
        .btn-back { text-decoration: none; color: var(--primary); font-weight: 600; display: flex; align-items: center; gap: 8px; }

        .profile-container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .profile-card { background: var(--white); border-radius: 24px; padding: 40px; text-align: center; border: 1px solid var(--border); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        
        .avatar-wrapper { position: relative; width: 130px; height: 130px; margin: 0 auto 20px; }
        .avatar-wrapper img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid var(--white); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        
        .edit-avatar-btn { 
            position: absolute; bottom: 5px; right: 5px; 
            background: var(--primary); color: white; width: 32px; height: 32px; 
            border-radius: 50%; display: flex; align-items: center; justify-content: center; 
            border: 3px solid var(--white); cursor: pointer; transition: 0.2s; font-size: 14px;
        }

        .rank-badge { display: inline-block; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; color: white; margin-bottom: 15px; letter-spacing: 0.5px; }
        .username-row { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 5px; }
        .username { font-size: 28px; font-weight: 800; margin: 0; }
        .edit-icon { color: var(--text-muted); cursor: pointer; font-size: 16px; transition: 0.2s; }
        .edit-icon:hover { color: var(--primary); }

        .reg-date { color: var(--text-muted); font-size: 14px; margin-bottom: 25px; }

        .bio-section { background: #f8fafc; border-radius: 16px; padding: 24px; text-align: left; margin-bottom: 30px; border: 1px dashed var(--border); }
        .bio-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .bio-title { font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .bio-text { font-size: 17px; color: #334155; line-height: 1.6; margin: 0; white-space: pre-wrap; }

        .stats-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; border-top: 1px solid var(--border); padding-top: 30px; }
        .stat-item { text-align: center; }
        .stat-value { display: block; font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .stat-label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; width: 90%; max-width: 400px; padding: 30px; border-radius: 24px; }
        .input-styled { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 20px; outline: none; }
    </style>
</head>
<body>

<div class="header-simple">
    <a href="index.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Назад в ленту</a>
</div>

<div class="profile-container">
    <div class="profile-card">
        <div class="avatar-wrapper">
            <img src="<?= getAvatar($profile['avatar'], $profile['username']) ?>" alt="Avatar">
            <?php if ($is_my_profile): ?>
                <div class="edit-avatar-btn" onclick="openModal()"><i class="fa-solid fa-camera"></i></div>
            <?php endif; ?>
        </div>

        <div class="rank-badge" style="background: <?= $rank_color ?>;">
            <i class="fa-solid fa-medal"></i> <?= $rank ?>
        </div>

        <div class="username-row">
            <h1 class="username"><?= htmlspecialchars($profile['username']) ?></h1>
            <?php if ($is_my_profile): ?>
                <i class="fa-solid fa-pen-to-square edit-icon" onclick="openModal()"></i>
            <?php endif; ?>
        </div>
        
        <div class="reg-date">На портале с <?= date('d.m.Y', strtotime($profile['created_at'])) ?></div>

        <div class="bio-section">
            <div class="bio-header">
                <span class="bio-title">О себе</span>
                <?php if ($is_my_profile): ?>
                    <i class="fa-solid fa-pencil edit-icon" style="font-size: 12px;" onclick="openModal()"></i>
                <?php endif; ?>
            </div>
            <p class="bio-text">
                <?= !empty($profile['bio']) ? nl2br(htmlspecialchars($profile['bio'])) : '<span style="color:#94a3b8; font-style:italic;">Информации пока нет...</span>' ?>
            </p>
        </div>

        <div class="stats-grid">
            <div class="stat-item"><span class="stat-value"><?= $profile['posts_count'] ?></span><span class="stat-label">Постов</span></div>
            <div class="stat-item"><span class="stat-value"><?= $profile['likes_given'] ?></span><span class="stat-label">Лайков</span></div>
            <div class="stat-item"><span class="stat-value"><?= $profile['comments_count'] ?></span><span class="stat-label">Комментов</span></div>
        </div>
    </div>
</div>

<?php if ($is_my_profile): ?>
<div id="editModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-top:0; font-weight:800;">Редактировать профиль</h2>
        <form id="profileForm">
            <label style="font-size:13px; font-weight:600;">Новый аватар</label>
            <input type="file" name="avatar" accept="image/*" style="display:block; margin: 10px 0 20px;">

            <label style="font-size:13px; font-weight:600;">Никнейм</label>
            <input type="text" name="username" class="input-styled" value="<?= htmlspecialchars($profile['username']) ?>">

            <label style="font-size:13px; font-weight:600;">О себе</label>
            <textarea name="bio" class="input-styled" style="height:100px; resize:none;"><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>

            <div style="display:flex; gap:10px;">
                <button type="button" onclick="closeModal()" style="flex:1; padding:12px; border-radius:12px; border:1px solid var(--border); cursor:pointer;">Отмена</button>
                <button type="submit" style="flex:2; padding:12px; border-radius:12px; border:none; background:var(--primary); color:white; font-weight:600; cursor:pointer;">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('editModal');
    function openModal() { modal.style.display = 'flex'; }
    function closeModal() { modal.style.display = 'none'; }
    window.onclick = (e) => { if(e.target == modal) closeModal(); }

    document.getElementById('profileForm').onsubmit = function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch('update_profile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => data.success ? location.reload() : alert(data.error));
    };
</script>
<?php endif; ?>

</body>
</html>