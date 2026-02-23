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

// Режим просмотра (обычная лента или черновики)
$view_mode = $_GET['view'] ?? 'published';

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT username, avatar FROM users WHERE id=?");
    $stmt->execute([$current_user_id]);
    $user = $stmt->fetch();
    $user_avatar = ($user && $user['avatar']) ? "uploads/" . $user['avatar'] : "https://ui-avatars.com/api/?background=0866ff&color=fff&name=" . urlencode($user['username'] ?? 'User');

    // Считаем черновики текущего пользователя
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM news WHERE user_id = ? AND status = 'draft'");
    $stmt->execute([$current_user_id]);
    $drafts_count = $stmt->fetchColumn();
}

/* SQL-запрос подстраивается под режим просмотра */
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
    <title>Социальная Лента</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg: #f0f2f5;
            --white: #ffffff;
            --primary: #0866ff;
            --primary-hover: #075ce4;
            --text-dark: #1c1e21;
            --text-gray: #65676b;
            --border: #dddfe2;
            --like-red: #f02849;
            --danger: #dc3545;
        }

        body { font-family: -apple-system, sans-serif; margin: 0; background: var(--bg); color: var(--text-dark); }
        header { background: var(--white); height: 60px; display: flex; justify-content: space-between; align-items: center; padding: 0 5%; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .logo { font-size: 22px; font-weight: 800; color: var(--primary); text-decoration: none; }
        .user-pill { display: flex; align-items: center; gap: 8px; background: #f0f2f5; padding: 4px 12px 4px 4px; border-radius: 20px; font-weight: 600; font-size: 14px; }
        .user-pill img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        
        .btn { text-decoration: none; padding: 8px 18px; border-radius: 6px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-login { color: var(--primary); background: transparent; }
        .btn-register { background: var(--primary); color: white; }
        .btn-register:hover { background: var(--primary-hover); }
        .btn-draft { background: #e4e6eb; color: #050505; }
        .btn-draft:hover { background: #d8dadf; }
        .btn-danger { background: #fce8e8; color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }
        
        .draft-badge-count { background: #f02849; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; margin-left: 4px; }

        .feed-container { max-width: 580px; margin: 20px auto; padding: 0 10px; }
        
        .create-post-card { background: var(--white); border-radius: 12px; padding: 16px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); border: 1px solid var(--border); }
        .create-post-card h4 { margin: 0 0 12px 0; font-size: 16px; color: var(--text-gray); }
        .input-field { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; font-size: 14px; outline: none; }
        .input-field:focus { border-color: var(--primary); }
        .textarea-field { height: 80px; resize: none; font-family: inherit; }
        .form-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
        .select-cat { padding: 8px; border-radius: 6px; border: 1px solid var(--border); outline: none; font-size: 14px; background: #f8f9fa; }

        .post-card { background: var(--white); border-radius: 12px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); border: 1px solid var(--border); overflow: hidden; position: relative; }
        .post-header { padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; }
        .author-box { display: flex; align-items: center; gap: 10px; }
        .author-box img { width: 40px; height: 40px; border-radius: 50%; }
        .post-meta b { display: block; font-size: 15px; }
        .post-meta span { font-size: 12px; color: var(--text-gray); }

        .badge { font-size: 10px; padding: 3px 8px; border-radius: 5px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; }
        .badge-news { background: #fef2f2; color: #dc2626; } 
        .badge-opinion { background: #e7f3ff; color: var(--primary); } 
        .badge-default { background: #f0f2f5; color: var(--text-gray); } 

        .post-body { padding: 4px 16px 16px; }
        .post-title { margin: 0 0 8px 0; font-size: 18px; font-weight: 700; }
        .post-text { font-size: 15px; line-height: 1.5; }

        .post-footer { padding: 4px; display: flex; border-top: 1px solid #f0f2f5; }
        .action-item { flex: 1; padding: 10px; text-align: center; color: var(--text-gray); font-weight: 600; font-size: 14px; cursor: pointer; border-radius: 4px; }
        .action-item:hover { background: #f2f2f2; }
        .action-item.liked { color: var(--like-red); }
        
        .view-title { margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; }

        /* Модальное окно редактирования */
        #editModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; }
        .modal-content { background: var(--white); padding: 20px; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

<header>
    <a href="index.php" class="logo">SocialApp</a>
    <div class="nav-right" style="display:flex; align-items:center; gap:12px;">
        <?php if ($is_logged_in): ?>
            <a href="?view=drafts" class="btn btn-draft">
                <i class="fa-regular fa-file-lines"></i> Черновики
                <?php if ($drafts_count > 0): ?>
                    <span class="draft-badge-count"><?= $drafts_count ?></span>
                <?php endif; ?>
            </a>

            <div class="user-pill">
                <img src="<?= $user_avatar ?>" alt="Avatar">
                <?= htmlspecialchars($user['username']) ?>
            </div>
            <a href="logout.php" class="btn btn-login" style="font-size: 12px;">Выйти</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-login">Вход</a>
            <a href="register.php" class="btn btn-register">Регистрация</a>
        <?php endif; ?>
    </div>
</header>

<div class="feed-container">
    
    <div class="view-title">
        <h2 style="font-size: 20px;"><?= ($view_mode === 'drafts') ? 'Мои черновики' : 'Общая лента' ?></h2>
        <?php if ($view_mode === 'drafts'): ?>
            <a href="index.php" style="font-size: 14px; color: var(--primary); text-decoration: none;">&larr; Назад в ленту</a>
        <?php endif; ?>
    </div>

    <?php if ($is_logged_in && $view_mode !== 'drafts'): ?>
        <div class="create-post-card">
            <h4>Создать публикацию</h4>
            <form id="createPostForm">
                <input type="text" name="title" class="input-field" placeholder="Заголовок новости" required>
                <textarea name="content" class="input-field textarea-field" placeholder="О чем вы думаете, <?= htmlspecialchars($user['username']) ?>?" required></textarea>
                
                <div class="form-footer">
                    <select name="category_id" class="select-cat">
                        <option value="1">Новость</option>
                        <option value="2">Общее</option>
                    </select>
                    
                    <div style="display: flex; gap: 8px;">
                        <button type="button" onclick="submitPost('draft')" class="btn btn-draft">В черновик</button>
                        <button type="button" onclick="submitPost('published')" class="btn btn-register">Опубликовать</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div id="news-feed">
        <?php if (empty($posts)): ?>
            <p style="text-align: center; color: var(--text-gray); margin-top: 40px;">Здесь пока ничего нет...</p>
        <?php endif; ?>

        <?php foreach($posts as $post): 
            $author_avatar = $post['author_avatar'] ? "uploads/" . $post['author_avatar'] : "https://ui-avatars.com/api/?name=" . urlencode($post['username']);
            $badge_class = 'badge-default';
            if ($post['cat_name'] == 'Новость') $badge_class = 'badge-news';
            if ($post['cat_name'] == 'Общее') $badge_class = 'badge-opinion';
        ?>
            <div class="post-card" id="post-<?= $post['id'] ?>">
                <div class="post-header">
                    <div class="author-box">
                        <img src="<?= $author_avatar ?>" alt="Avatar">
                        <div class="post-meta">
                            <b><?= htmlspecialchars($post['username']) ?></b>
                            <span><?= date("j F в H:i", strtotime($post['created_at'])) ?></span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="badge <?= $badge_class ?>">
                            <?= htmlspecialchars($post['cat_name'] ?? 'Пост') ?>
                        </div>
                    </div>
                </div>

                <div class="post-body">
                    <h3 class="post-title"><?= htmlspecialchars($post['title'] ?? '') ?></h3>
                    <div class="post-text"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
                    
                    <?php if ($post['status'] === 'draft'): ?>
                        <div style="margin-top: 15px; display: flex; gap: 8px; border-top: 1px solid #f0f2f5; padding-top: 12px;">
                            <button onclick="publishDraft(<?= $post['id'] ?>)" class="btn btn-register" style="padding: 6px 12px; font-size: 12px;">Опубликовать</button>
                            <button onclick="openEditModal(<?= $post['id'] ?>, '<?= addslashes($post['title']) ?>', '<?= addslashes($post['content']) ?>', '<?= $post['category_id'] ?>')" class="btn btn-draft" style="padding: 6px 12px; font-size: 12px;">Изменить</button>
                            <button onclick="deletePost(<?= $post['id'] ?>)" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="post-footer">
                    <div class="action-item like-btn <?= $post['is_liked'] ? 'liked' : '' ?>" onclick="toggleLike(<?= $post['id'] ?>)">
                        <i class="<?= $post['is_liked'] ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i> 
                        <span class="count"><?= $post['likes_count'] ?></span>
                    </div>
                    <div class="action-item"><i class="fa-regular fa-comment"></i></div>
                    <div class="action-item"><i class="fa-solid fa-share-nodes"></i></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="editModal">
    <div class="modal-content">
        <h4 style="margin-top: 0;">Редактировать черновик</h4>
        <form id="editPostForm">
            <input type="hidden" name="news_id" id="edit_id">
            
            <label style="font-size: 12px; color: var(--text-gray);">Заголовок:</label>
            <input type="text" name="title" id="edit_title" class="input-field" required>
            
            <label style="font-size: 12px; color: var(--text-gray);">Текст поста:</label>
            <textarea name="content" id="edit_content" class="input-field textarea-field" style="height: 120px;" required></textarea>
            
            <label style="font-size: 12px; color: var(--text-gray);">Категория:</label>
            <select name="category_id" id="edit_category" class="input-field" style="width: 100%; cursor: pointer;">
                <option value="1">Новость</option>
                <option value="2">Общее</option>
            </select>

            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px;">
                <button type="button" onclick="closeModal()" class="btn btn-draft">Отмена</button>
                <button type="button" onclick="saveEdit()" class="btn btn-register">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
// --- УПРАВЛЕНИЕ ЧЕРНОВИКАМИ ---

function openEditModal(id, title, content, catId) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_content').value = content;
    document.getElementById('edit_category').value = catId; // Установка текущей категории
    document.getElementById('editModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function saveEdit() {
    const formData = new FormData(document.getElementById('editPostForm'));
    formData.append('action', 'update');

    fetch('create_post.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Ошибка при сохранении: ' + data.error);
    });
}

function deletePost(newsId) {
    if (!confirm('Вы уверены, что хотите удалить этот черновик?')) return;
    
    const formData = new FormData();
    formData.append('news_id', newsId);
    formData.append('action', 'delete');

    fetch('create_post.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Ошибка при удалении');
    });
}

function publishDraft(newsId) {
    const formData = new FormData();
    formData.append('news_id', newsId);
    formData.append('action', 'publish_existing');

    fetch('create_post.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) location.href = 'index.php';
        else alert('Ошибка при публикации');
    });
}

// --- БАЗОВЫЕ ФУНКЦИИ ---

function submitPost(status) {
    const form = document.getElementById('createPostForm');
    if (!form.title.value || !form.content.value) {
        alert("Заполните заголовок и текст!");
        return;
    }
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
    if (!<?= json_encode($is_logged_in) ?>) { alert("Войдите в аккаунт!"); return; }
    const btn = document.querySelector(`#post-${newsId} .like-btn`);
    const countElement = btn.querySelector('.count');
    const icon = btn.querySelector('i');
    let count = parseInt(countElement.innerText);

    const formData = new FormData();
    formData.append('news_id', newsId);

    fetch('like.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'added') {
            btn.classList.add('liked');
            icon.classList.replace('fa-regular', 'fa-solid');
            countElement.innerText = count + 1;
        } else {
            btn.classList.remove('liked');
            icon.classList.replace('fa-solid', 'fa-regular');
            countElement.innerText = count - 1;
        }
    });
}
</script>
</body>
</html>