<?php
session_start();

// Подключение к БД
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

$is_logged_in = isset($_SESSION['user_id']);
$current_user_id = $_SESSION['user_id'] ?? 0;
$user = null;
$user_avatar = "";
$drafts_count = 0;

$view_mode = $_GET['view'] ?? 'published';

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT username, avatar FROM users WHERE id=?");
    $stmt->execute([$current_user_id]);
    $user = $stmt->fetch();
    $user_avatar = ($user && $user['avatar']) ? "uploads/" . $user['avatar'] : "https://ui-avatars.com/api/?background=6366f1&color=fff&bold=true&name=" . urlencode($user['username'] ?? 'User');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM news WHERE user_id = ? AND status = 'draft'");
    $stmt->execute([$current_user_id]);
    $drafts_count = $stmt->fetchColumn();
}

$status_filter = ($view_mode === 'drafts' && $is_logged_in) ? 'draft' : 'published';
$user_filter = ($view_mode === 'drafts') ? "AND news.user_id = $current_user_id" : "";

$sql = "
    SELECT news.*, users.username, users.avatar as author_avatar,
    categories.name as cat_name,
    (SELECT COUNT(*) FROM likes WHERE news_id = news.id) as likes_count,
    (SELECT COUNT(*) FROM likes WHERE news_id = news.id AND user_id = ?) as is_liked
    FROM news
    JOIN users ON news.user_id = users.id
    LEFT JOIN categories ON news.category_id = categories.id
    WHERE news.status = '$status_filter' $user_filter
    ORDER BY news.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$current_user_id]);
$posts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новостник</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --glass-bg: rgba(255, 255, 255, 0.9);
            --white: #ffffff;
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --text-dark: #0f172a;
            --text-gray: #64748b;
            --border: #e2e8f0;
            --like-red: #ef4444;
            --danger: #f87171;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0; background: var(--bg); color: var(--text-dark); 
            line-height: 1.6; -webkit-font-smoothing: antialiased;
        }

        header { 
            background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            height: 72px; display: flex; justify-content: space-between; align-items: center; 
            padding: 0 8%; position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid var(--border);
        }

        .logo { font-size: 26px; font-weight: 800; color: var(--primary); text-decoration: none; letter-spacing: -1px; display: flex; align-items: center; gap: 8px; }

        .user-pill { 
            display: flex; align-items: center; gap: 10px; background: var(--white); 
            padding: 6px 16px 6px 8px; border-radius: 40px; font-weight: 600; font-size: 14px;
            border: 1px solid var(--border);
        }
        .user-pill img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }

        .btn { 
            text-decoration: none; padding: 10px 20px; border-radius: 12px; font-size: 14px; 
            font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; 
        }
        .btn-register { background: var(--primary); color: white; }
        .btn-draft { background: #f1f5f9; color: #475569; border: 1px solid var(--border); }

        .feed-container { max-width: 680px; margin: 32px auto; padding: 0 20px; }

        /* --- Анимированный переключатель категорий --- */
        .category-selector {
            display: flex; background: #f1f5f9; padding: 4px; border-radius: 14px;
            position: relative; width: fit-content; margin-bottom: 16px;
        }
        .category-option {
            padding: 8px 20px; font-size: 14px; font-weight: 600; color: var(--text-gray);
            cursor: pointer; position: relative; z-index: 2; transition: color 0.3s ease;
        }
        .category-option.active { color: var(--primary); }
        .selection-slider {
            position: absolute; height: calc(100% - 8px); top: 4px; left: 4px;
            background: var(--white); border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1;
        }

        /* --- Поле загрузки фото --- */
        .file-upload-wrapper { margin-top: 12px; }
        .file-label {
            display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px;
            background: #f8fafc; border: 1px dashed var(--border); border-radius: 10px;
            cursor: pointer; color: var(--text-gray); font-size: 14px; transition: 0.2s;
        }
        .file-label:hover { border-color: var(--primary); color: var(--primary); }
        #imagePreview { 
            width: 100%; max-height: 300px; object-fit: cover; border-radius: 12px; 
            margin-top: 12px; display: none; border: 1px solid var(--border);
        }

        /* --- Карточки --- */
        .create-post-card, .post-card { 
            background: var(--white); border-radius: 20px; padding: 24px; 
            margin-bottom: 32px; border: 1px solid var(--border); box-shadow: var(--shadow);
        }
        .post-img { width: calc(100% + 48px); margin: 12px -24px; max-height: 450px; object-fit: cover; display: block; }

        .input-field { 
            width: 100%; padding: 14px; margin-bottom: 12px; border: 1px solid var(--border); 
            border-radius: 12px; font-size: 15px; outline: none; transition: 0.2s; background: #f8fafc;
        }
        .input-field:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 4px rgba(99,102, 241, 0.1); }
        .textarea-field { height: 100px; resize: none; font-family: inherit; }

        .post-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; }
        .author-box { display: flex; align-items: center; gap: 12px; }
        .author-box img { width: 44px; height: 44px; border-radius: 50%; }

        .post-footer { 
            padding: 8px 16px; display: flex; background: #fafafa;
            border-top: 1px solid #f1f5f9; gap: 8px; margin: 0 -24px -24px;
        }
        .action-item { flex: 1; padding: 12px; text-align: center; color: var(--text-gray); font-weight: 600; cursor: pointer; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .action-item:hover { background: #f1f5f9; color: var(--primary); }
        .action-item.liked { color: var(--like-red); }

        #editModal { 
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 2000; justify-content: center; align-items: center; 
        }
        .modal-content { background: var(--white); padding: 32px; border-radius: 24px; width: 90%; max-width: 500px; }
    </style>
</head>
<body>

<header>
    <a href="index.php" class="logo">
        <i class="fa-solid fa-bolt-lightning"></i>
        <span>Новостник</span>
    </a>
    <div class="nav-right">
        <?php if ($is_logged_in): ?>
            <a href="?view=drafts" class="btn btn-draft">
                <i class="fa-regular fa-file-lines"></i>
                <span>Черновики (<?= $drafts_count ?>)</span>
            </a>
            <div class="user-pill">
                <img src="<?= $user_avatar ?>" alt="Avatar">
                <?= htmlspecialchars($user['username']) ?>
            </div>
            <a href="logout.php" class="btn" title="Выйти"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
        <?php else: ?>
            <a href="login.php" class="btn">Вход</a>
            <a href="register.php" class="btn btn-register">Регистрация</a>
        <?php endif; ?>
    </div>
</header>

<div class="feed-container">
    <h2 style="font-weight: 800; margin-bottom: 24px;"><?= ($view_mode === 'drafts') ? 'Мои черновики' : 'Лента новостей' ?></h2>

    <?php if ($is_logged_in && $view_mode !== 'drafts'): ?>
        <div class="create-post-card">
            <form id="createPostForm" enctype="multipart/form-data">
                <div class="category-selector">
                    <div class="selection-slider" id="slider"></div>
                    <div class="category-option active" onclick="setCategory(1, this)">🔥 Новость</div>
                    <div class="category-option" onclick="setCategory(2, this)">💬 Общее</div>
                </div>
                <input type="hidden" name="category_id" id="post_cat_id" value="1">
                
                <input type="text" name="title" class="input-field" placeholder="Заголовок публикации" required>
                <textarea name="content" class="input-field textarea-field" placeholder="О чем задумались?" required></textarea>
                
                <img id="imagePreview">

                <div class="file-upload-wrapper">
                    <label class="file-label">
                        <i class="fa-solid fa-image"></i> Добавить фото
                        <input type="file" name="image" id="imageInput" hidden accept="image/*" onchange="previewFile()">
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="submitPost('draft')" class="btn btn-draft">В черновик</button>
                    <button type="button" onclick="submitPost('published')" class="btn btn-register">Опубликовать</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div id="news-feed">
        <?php foreach($posts as $post): ?>
            <div class="post-card" id="post-<?= $post['id'] ?>">
                <div class="post-header">
                    <div class="author-box">
                        <img src="<?= $post['author_avatar'] ? 'uploads/'.$post['author_avatar'] : 'https://ui-avatars.com/api/?name='.urlencode($post['username']) ?>" alt="A">
                        <div class="post-meta">
                            <b><?= htmlspecialchars($post['username']) ?></b>
                            <span><?= date("d.m.y H:i", strtotime($post['created_at'])) ?></span>
                        </div>
                    </div>
                    <span class="badge"><?= htmlspecialchars($post['cat_name'] ?? 'Пост') ?></span>
                </div>

                <div class="post-body">
                    <h3 style="margin: 0 0 10px;"><?= htmlspecialchars($post['title']) ?></h3>
                    <p class="post-text"><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                    
                    <?php if (!empty($post['image'])): ?>
                        <img src="uploads/<?= $post['image'] ?>" class="post-img" alt="Post photo">
                    <?php endif; ?>
                </div>

                <div class="post-footer">
                    <div class="action-item like-btn <?= $post['is_liked'] ? 'liked' : '' ?>" onclick="toggleLike(<?= $post['id'] ?>)">
                        <i class="<?= $post['is_liked'] ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i> <?= $post['likes_count'] ?>
                    </div>
                    <div class="action-item"><i class="fa-regular fa-comment"></i></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// --- Анимация выбора категорий ---
function setCategory(id, el) {
    document.getElementById('post_cat_id').value = id;
    const options = document.querySelectorAll('.category-option');
    options.forEach(opt => opt.classList.remove('active'));
    el.classList.add('active');
    
    const slider = document.getElementById('slider');
    slider.style.width = el.offsetWidth + 'px';
    slider.style.transform = `translateX(${el.offsetLeft - 4}px)`;
}
// Инициализация ширины слайдера при загрузке
window.onload = () => {
    const activeOpt = document.querySelector('.category-option.active');
    if(activeOpt) setCategory(1, activeOpt);
};

// --- Предпросмотр фото ---
function previewFile() {
    const preview = document.getElementById('imagePreview');
    const file = document.getElementById('imageInput').files[0];
    const reader = new FileReader();

    reader.onloadend = () => {
        preview.src = reader.result;
        preview.style.display = "block";
    }
    if (file) reader.readAsDataURL(file);
    else preview.style.display = "none";
}

// --- Отправка поста (с поддержкой файлов) ---
function submitPost(status) {
    const form = document.getElementById('createPostForm');
    const formData = new FormData(form);
    formData.append('status', status);

    fetch('create_post.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Ошибка: ' + data.error);
    });
}

function toggleLike(newsId) {
    const formData = new FormData();
    formData.append('news_id', newsId);
    fetch('like.php', { method: 'POST', body: formData }).then(() => location.reload());
}
</script>
</body>
</html>