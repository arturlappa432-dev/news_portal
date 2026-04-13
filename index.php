<?php
$error_test = "No semicolon here" // Специально не ставим ;
session_start();

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

$is_logged_in = isset($_SESSION['user_id']);
$current_user_id = $_SESSION['user_id'] ?? 0;
$user = null;
$user_avatar = "";
$is_admin = false;
$drafts_count = 0;
$favs_count = 0;

$view_mode = $_GET['view'] ?? 'published';

if (!function_exists('getAvatar')) {
    function getAvatar($filename, $username) {
        if (!empty($filename) && file_exists("uploads/" . $filename))
            return "uploads/" . $filename;
        return "https://ui-avatars.com/api/?background=6366f1&color=fff&bold=true&name=" . urlencode($username ?? 'User');
    }
}

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT username, avatar, role FROM users WHERE id = ?");
    $stmt->execute([$current_user_id]);
    $user = $stmt->fetch();
    if ($user) {
        $user_avatar = getAvatar($user['avatar'], $user['username']);
        if ($user['role'] === 'admin') $is_admin = true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM news WHERE user_id = ? AND status = 'draft'");
    $stmt->execute([$current_user_id]);
    $drafts_count = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $favs_count = $stmt->fetchColumn();
}

$status_filter = ($view_mode === 'drafts') ? 'draft' : 'published';
$params = ['curr_user' => $current_user_id];
$where_clauses = ["news.status = :status"];
$params['status'] = $status_filter;

if ($view_mode === 'drafts') {
    $where_clauses[] = "news.user_id = :filter_user";
    $params['filter_user'] = $current_user_id;
} elseif ($view_mode === 'favorites') {
    $where_clauses[] = "news.id IN (SELECT news_id FROM favorites WHERE user_id = :filter_user)";
    $params['filter_user'] = $current_user_id;
    $params['status'] = 'published';
}

// ── VISIBILITY FILTER ─────────────────────────────────────────────────────
// Public posts — видят все. Посты "для друзей" — только автор и его друзья.
if ($is_logged_in) {
    $where_clauses[] = "(
        news.visibility = 'public'
        OR news.user_id = :vis_me
        OR (
            news.visibility = 'friends'
            AND news.user_id IN (
                SELECT friend_id FROM friendships WHERE user_id = :fme1 AND status = 'accepted'
                UNION
                SELECT user_id   FROM friendships WHERE friend_id = :fme2 AND status = 'accepted'
            )
        )
    )";
    $params['vis_me'] = $current_user_id;
    $params['fme1']   = $current_user_id;
    $params['fme2']   = $current_user_id;
} else {
    $where_clauses[] = "news.visibility = 'public'";
}

$where_sql = implode(" AND ", $where_clauses);
$sql = "
    SELECT news.*, users.username, users.avatar as author_avatar, categories.name as cat_name,
    (SELECT COUNT(*) FROM likes WHERE news_id = news.id) as likes_count,
    (SELECT COUNT(*) FROM likes WHERE news_id = news.id AND user_id = :curr_user) as is_liked,
    (SELECT COUNT(*) FROM favorites WHERE news_id = news.id AND user_id = :curr_user) as is_fav,
    (SELECT COUNT(*) FROM comments WHERE news_id = news.id) as comm_count
    FROM news
    JOIN users ON news.user_id = users.id
    LEFT JOIN categories ON news.category_id = categories.id
    WHERE $where_sql
    ORDER BY news.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

function getCommentsForPost($news_id, $pdo, $current_user_id = 0) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username, u.avatar,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as is_liked
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.news_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$current_user_id, $news_id]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новостник | Лента</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f4f6; --white: #ffffff; --primary: #6366f1; --primary-hover: #4f46e5;
            --text-dark: #1e293b; --text-muted: #64748b; --border: #e2e8f0;
            --like-red: #ef4444; --fav-orange: #f59e0b;
            --card-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            --panel-shadow: 0 20px 60px -10px rgba(0,0,0,0.18);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg); color: var(--text-dark); line-height: 1.6; }

        /* ══ HEADER ══════════════════════════════════ */
        header {
            background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
            height: 72px; display: flex; justify-content: space-between; align-items: center;
            padding: 0 8%; position: sticky; top: 0; z-index: 1000; border-bottom: 1px solid var(--border);
        }
        .logo { font-size: 22px; font-weight: 800; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .nav-right { display: flex; align-items: center; gap: 10px; }

        .user-pill { display: flex; align-items: center; gap: 10px; background: var(--white); padding: 4px 12px 4px 4px; border-radius: 40px; font-weight: 600; font-size: 14px; border: 1px solid var(--border); height: 40px; color: var(--text-dark); text-decoration: none; }
        .user-pill img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }

        .btn { text-decoration: none; padding: 10px 18px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; border: none; background: transparent; }
        .btn-register { background: var(--primary); color: white; }
        .btn-admin { background: #1e293b; color: #fff; }
        .btn-draft { background: #f1f5f9; color: #475569; border: 1px solid var(--border); }
        .btn-fav   { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .btn-back  { background: transparent; color: var(--text-muted); border: 1px solid var(--border); padding: 8px 14px; border-radius: 10px; position: absolute; left: 0; }
        .btn-back:hover { background: #fff; color: var(--primary); border-color: var(--primary); }

        /* Иконка-кнопка (колокол) */
        .icon-btn {
            position: relative; width: 40px; height: 40px; border-radius: 50%;
            background: #f1f5f9; border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s; color: var(--text-muted); font-size: 16px;
        }
        .icon-btn:hover, .icon-btn.active { background: #eef2ff; color: var(--primary); border-color: #c7d2fe; }

        .notif-badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--like-red); color: #fff; font-size: 10px; font-weight: 800;
            min-width: 18px; height: 18px; border-radius: 9px; padding: 0 4px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #fff;
        }

        /* Кнопка Друзья в шапке */
        .friends-header-btn {
            display: flex; align-items: center; gap: 7px;
            background: #f1f5f9; border: 1px solid var(--border);
            border-radius: 12px; padding: 0 16px; height: 40px;
            font-size: 14px; font-weight: 600; color: var(--text-muted);
            cursor: pointer; transition: 0.2s;
        }
        .friends-header-btn:hover, .friends-header-btn.active { background: #eef2ff; color: var(--primary); border-color: #c7d2fe; }

        /* ══ БОКОВЫЕ ПАНЕЛИ ══════════════════════════ */
        .side-panel {
            position: fixed; top: 72px; right: 0;
            width: 380px; max-height: calc(100vh - 72px);
            background: var(--white); border-left: 1px solid var(--border);
            border-bottom-left-radius: 20px;
            box-shadow: var(--panel-shadow);
            display: flex; flex-direction: column;
            transform: translateX(110%); opacity: 0;
            transition: transform 0.32s cubic-bezier(0.4,0,0.2,1), opacity 0.25s ease;
            z-index: 999; overflow: hidden;
        }
        .side-panel.open { transform: translateX(0); opacity: 1; }

        .panel-header {
            padding: 20px 24px 16px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }
        .panel-title { font-size: 18px; font-weight: 800; margin: 0; }
        .panel-close {
            width: 32px; height: 32px; border-radius: 50%; background: #f1f5f9;
            border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
            color: var(--text-muted); font-size: 14px; transition: 0.2s;
        }
        .panel-close:hover { background: #fee2e2; color: var(--like-red); }

        .panel-body { flex: 1; overflow-y: auto; padding: 20px 24px; }
        .panel-body::-webkit-scrollbar { width: 4px; }
        .panel-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

        /* Поиск */
        .search-row { display: flex; gap: 8px; margin-bottom: 20px; }
        .search-input {
            flex: 1; padding: 11px 14px; border: 1px solid var(--border);
            border-radius: 12px; font-family: inherit; font-size: 14px;
            background: #f8fafc; outline: none; transition: border-color 0.2s;
        }
        .search-input:focus { border-color: var(--primary); }
        .search-btn {
            padding: 11px 14px; border-radius: 12px; background: var(--primary);
            color: #fff; font-size: 14px; font-weight: 600; border: none;
            cursor: pointer; transition: 0.2s; white-space: nowrap; display: flex; align-items: center; gap: 6px;
        }
        .search-btn:hover { background: var(--primary-hover); }

        /* Результаты поиска */
        .search-results { margin-bottom: 24px; }
        .search-result-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 14px; background: #f8fafc;
            border: 1px solid var(--border); margin-bottom: 8px;
        }
        .search-result-item img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .sri-name { font-weight: 700; font-size: 14px; }
        .send-invite-btn {
            padding: 7px 12px; border-radius: 10px; background: var(--primary);
            color: #fff; font-size: 12px; font-weight: 600; border: none;
            cursor: pointer; transition: 0.2s; white-space: nowrap; flex-shrink: 0;
        }
        .send-invite-btn:hover { background: var(--primary-hover); }
        .send-invite-btn:disabled { background: #c7d2fe; cursor: default; }

        /* Список друзей */
        .section-label {
            font-size: 11px; font-weight: 800; text-transform: uppercase;
            color: var(--text-muted); letter-spacing: 0.07em; margin-bottom: 12px;
        }
        .friend-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 8px; border-radius: 14px; transition: background 0.15s;
            text-decoration: none; color: inherit;
        }
        .friend-item:hover { background: #f1f5f9; }
        .friend-item img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .friend-name { font-weight: 700; font-size: 14px; }
        .friend-sub  { font-size: 12px; color: var(--text-muted); }

        /* Уведомления */
        .notif-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 12px; border-radius: 16px; background: #f8fafc;
            border: 1px solid var(--border); margin-bottom: 10px;
            transition: opacity 0.3s, transform 0.3s;
        }
        .notif-item img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .notif-info { flex: 1; min-width: 0; }
        .notif-info strong { font-size: 14px; font-weight: 700; display: block; }
        .notif-info .notif-sub { font-size: 12px; color: var(--text-muted); }
        .notif-actions { display: flex; gap: 6px; margin-top: 8px; }
        .accept-btn {
            padding: 6px 14px; border-radius: 9px; background: var(--primary);
            color: #fff; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s;
        }
        .accept-btn:hover { background: var(--primary-hover); }
        .decline-btn {
            padding: 6px 14px; border-radius: 9px; background: #f1f5f9;
            color: var(--text-muted); font-size: 13px; font-weight: 600;
            border: 1px solid var(--border); cursor: pointer; transition: 0.2s;
        }
        .decline-btn:hover { background: #fee2e2; color: var(--like-red); border-color: #fca5a5; }

        .empty-state { text-align: center; padding: 32px 0; color: var(--text-muted); font-size: 14px; }
        .empty-state i { font-size: 36px; display: block; margin-bottom: 10px; opacity: 0.3; }

        /* Toast */
        #toast {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(20px);
            background: #1e293b; color: #fff; padding: 12px 24px; border-radius: 14px;
            font-size: 14px; font-weight: 600; z-index: 9999;
            opacity: 0; transition: opacity 0.3s, transform 0.3s; pointer-events: none;
        }
        #toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        /* Overlay */
        #panelOverlay {
            position: fixed; inset: 0; background: rgba(15,23,42,0.2);
            z-index: 998; display: none; backdrop-filter: blur(1px);
        }

        /* ══ ОСНОВНОЙ КОНТЕНТ ════════════════════════ */
        .feed-container { max-width: 650px; margin: 32px auto; padding: 0 20px; }
        .header-box { position: relative; display: flex; align-items: center; justify-content: center; margin-bottom: 32px; min-height: 45px; }

        .create-post-card { background: var(--white); border-radius: 24px; padding: 24px; margin-bottom: 32px; border: 1px solid var(--border); box-shadow: var(--card-shadow); }

        .toggle-selector { display: flex; background: #f1f5f9; padding: 4px; border-radius: 14px; position: relative; width: fit-content; }
        .toggle-option { padding: 8px 20px; font-size: 14px; font-weight: 600; color: var(--text-muted); cursor: pointer; position: relative; z-index: 2; transition: color 0.3s; white-space: nowrap; }
        .toggle-option.active { color: var(--primary); }
        .toggle-slider { position: absolute; height: calc(100% - 8px); top: 4px; left: 4px; background: var(--white); border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1), width 0.3s cubic-bezier(0.4,0,0.2,1); z-index: 1; }

        .selectors-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .selectors-divider { width: 1px; height: 28px; background: var(--border); }

        .field-wrap { position: relative; margin-bottom: 12px; }
        .char-counter { position: absolute; right: 12px; bottom: 10px; font-size: 11px; font-weight: 600; color: var(--text-muted); pointer-events: none; }
        .char-counter.warn { color: #f59e0b; }
        .char-counter.over { color: var(--like-red); }
        .input-styled { width: 100%; padding: 14px; border: 1px solid var(--border); border-radius: 14px; background: #f8fafc; font-family: inherit; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .input-styled:focus { border-color: var(--primary); }
        .input-title { font-weight: 700; padding-right: 70px; }
        .input-content { height: 120px; resize: none; padding-right: 70px; padding-bottom: 28px; }

        .post-card { background: var(--white); border-radius: 24px; margin-bottom: 32px; border: 1px solid var(--border); box-shadow: var(--card-shadow); overflow: hidden; }
        .post-header { padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; }
        .author-link { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; }
        .author-info img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
        .author-name { display: block; font-weight: 700; }
        .post-date { font-size: 12px; color: var(--text-muted); }
        .cat-badge { background: #eef2ff; color: var(--primary); padding: 4px 12px; border-radius: 10px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .visibility-badge { background: #f0fdf4; color: #16a34a; padding: 4px 10px; border-radius: 10px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .visibility-badge.friends-vis { background: #eff6ff; color: #2563eb; }

        .post-content { padding: 0 24px 20px 24px; }
        .post-title { font-size: 24px; font-weight: 800; line-height: 1.2; margin: 0 0 16px 0; word-wrap: break-word; overflow-wrap: break-word; }
        .post-text { font-size: 16px; color: #334155; margin-bottom: 20px; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; }
        .post-img { width: calc(100% + 48px); margin: 0 -24px 20px -24px; max-height: 420px; object-fit: cover; display: block; }

        .post-footer { padding: 14px 24px; display: flex; gap: 24px; border-top: 1px solid #f8fafc; background: #fcfdfe; }
        .action-btn { display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-weight: 600; font-size: 14px; cursor: pointer; transition: 0.2s; user-select: none; }
        .action-btn i { transition: transform 0.2s cubic-bezier(0.175,0.885,0.32,1.275); }
        .action-btn:active i { transform: scale(0.7); }
        .action-btn.liked { color: var(--like-red); }
        .action-btn.faved { color: var(--fav-orange); }

        .comments-area { background: #fcfdfe; padding: 20px 24px; border-top: 1px solid #f8fafc; display: none; }
        .comment-item { margin-bottom: 16px; transition: opacity 0.3s; }
        .comm-user { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: inherit; }
        .comm-user img { width: 24px; height: 24px; border-radius: 50%; }
        .comm-time { font-size: 11px; font-weight: 500; color: var(--text-muted); margin-left: 2px; }
        .comm-txt { font-size: 14px; color: #475569; margin-left: 32px; word-wrap: break-word; overflow-wrap: break-word; margin-top: 3px; }
        .reply-item {
            margin-left: 32px; margin-top: 10px; padding-left: 14px;
            border-left: 2px solid #e2e8f0;
        }
        /* Comment action bar */
        .comm-actions {
            display: flex; align-items: center; gap: 4px;
            margin-left: 32px; margin-top: 6px; flex-wrap: wrap;
        }
        .comm-like-btn, .comm-reply-btn, .comm-edit-btn, .comm-del-btn {
            display: inline-flex; align-items: center; gap: 5px;
            background: none; border: none; cursor: pointer;
            font-size: 12px; font-weight: 600; color: var(--text-muted);
            padding: 3px 8px; border-radius: 7px; transition: 0.18s; font-family: inherit;
        }
        .comm-like-btn:hover  { color: var(--like-red); background: #fee2e2; }
        .comm-reply-btn:hover { color: var(--primary);  background: #eef2ff; }
        .comm-edit-btn:hover  { color: #0284c7; background: #e0f2fe; }
        .comm-del-btn:hover   { color: var(--like-red); background: #fee2e2; }
        .comm-like-btn.liked  { color: var(--like-red); }
        .comm-like-btn i { transition: transform 0.2s cubic-bezier(0.175,0.885,0.32,1.275); }
        .comm-sep { color: #e2e8f0; font-size: 11px; user-select: none; }
        /* Edit timer badge */
        .comm-edit-timer {
            font-size: 10px; font-weight: 700; color: #0284c7;
            background: #e0f2fe; padding: 2px 6px; border-radius: 5px; margin-left: 2px;
        }
        /* Inline edit textarea */
        .comm-edit-area {
            width: 100%; min-height: 60px; resize: vertical;
            padding: 9px 12px; font-size: 13px; margin-top: 4px;
        }
        /* Inline reply form */
        .reply-form {
            margin-left: 32px; margin-top: 8px;
            display: none; gap: 8px; align-items: center;
        }
        .reply-form .input-styled { flex: 1; padding: 9px 12px; font-size: 13px; }
        .reply-form .btn { padding: 9px 14px; font-size: 13px; white-space: nowrap; }

        @media (max-width: 480px) {
            .side-panel { width: 100%; border-radius: 0; }
            header { padding: 0 4%; }
        }
    </style>
</head>
<body>

<!-- ══ ПАНЕЛЬ ДРУЗЕЙ ═══════════════════════════════ -->
<div id="friendsPanel" class="side-panel">
    <div class="panel-header">
        <h3 class="panel-title">👥 Друзья</h3>
        <button class="panel-close" onclick="closeAllPanels()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="panel-body">
        <div class="search-row">
            <input type="text" id="friendSearchInput" class="search-input"
                   placeholder="Введите ник пользователя..."
                   oninput="liveSearch(this.value)">
            <button class="search-btn" onclick="sendInviteFromInput()">
                <i class="fa-solid fa-user-plus"></i> Пригласить
            </button>
        </div>

        <div id="searchResultsArea" class="search-results" style="display:none;"></div>

        <div class="section-label">Мои друзья</div>
        <div id="friendsList">
            <div class="empty-state"><i class="fa-solid fa-user-group"></i>Загрузка...</div>
        </div>
    </div>
</div>

<!-- ══ ПАНЕЛЬ УВЕДОМЛЕНИЙ ══════════════════════════ -->
<div id="notifPanel" class="side-panel">
    <div class="panel-header">
        <h3 class="panel-title">🔔 Уведомления</h3>
        <button class="panel-close" onclick="closeAllPanels()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="panel-body">
        <div class="section-label" style="margin-bottom:16px;">Запросы в друзья</div>
        <div id="notifList">
            <div class="empty-state"><i class="fa-regular fa-bell"></i>Загрузка...</div>
        </div>
    </div>
</div>

<div id="panelOverlay" onclick="closeAllPanels()"></div>
<div id="toast"></div>

<!-- ══ HEADER ══════════════════════════════════════ -->
<header>
    <a href="index.php" class="logo"><i class="fa-solid fa-bolt-lightning"></i> <span>Новостник</span></a>
    <div class="nav-right">
        <?php if ($is_logged_in): ?>
            <?php if ($is_admin): ?>
                <a href="admin_panel.php" class="btn btn-admin"><i class="fa-solid fa-shield-halved"></i> Админка</a>
            <?php endif; ?>

            <button class="friends-header-btn" id="friendsBtn" onclick="togglePanel('friends')">
                <i class="fa-solid fa-user-group"></i> Друзья
            </button>

            <div class="icon-btn" id="bellBtn" onclick="togglePanel('notif')" title="Уведомления">
                <i class="fa-regular fa-bell"></i>
                <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
            </div>

            <a href="profile.php" class="user-pill">
                <img src="<?= $user_avatar ?>" alt="A">
                <span><?= htmlspecialchars($user['username']) ?></span>
            </a>
            <a href="logout.php" style="color:var(--text-muted); font-size:18px;"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
        <?php else: ?>
            <a href="login.php" class="btn">Вход</a>
            <a href="register.php" class="btn btn-register">Регистрация</a>
        <?php endif; ?>
    </div>
</header>

<!-- ══ ЛЕНТА ════════════════════════════════════════ -->
<div class="feed-container">
    <div class="header-box">
        <?php if ($view_mode !== 'published'): ?>
            <a href="index.php" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Назад</a>
        <?php endif; ?>
        <h2 style="font-weight:800; font-size:28px; margin:0;">
            <?php
                if ($view_mode === 'drafts') echo 'Мои черновики';
                elseif ($view_mode === 'favorites') echo 'Избранное';
                else echo 'Лента';
            ?>
        </h2>
    </div>

    <?php if ($is_logged_in): ?>
        <?php if ($view_mode === 'published'): ?>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-bottom:12px;">
            <a href="index.php?view=favorites" class="btn btn-fav"><i class="fa-solid fa-bookmark"></i> Избранное (<?= $favs_count ?>)</a>
            <a href="index.php?view=drafts" class="btn btn-draft"><i class="fa-regular fa-file-lines"></i> Черновики (<?= $drafts_count ?>)</a>
        </div>

        <div class="create-post-card">
            <form id="createPostForm" enctype="multipart/form-data">
                <div class="selectors-row">
                    <div class="toggle-selector" id="catSelector">
                        <div class="toggle-slider" id="catSlider"></div>
                        <div class="toggle-option active" data-value="1" onclick="setToggle('cat',1,this)">🔥 Новость</div>
                        <div class="toggle-option" data-value="2" onclick="setToggle('cat',2,this)">💬 Общее</div>
                    </div>
                    <div class="selectors-divider"></div>
                    <div class="toggle-selector" id="visSelector">
                        <div class="toggle-slider" id="visSlider"></div>
                        <div class="toggle-option active" data-value="public" onclick="setToggle('vis','public',this)">🌍 Публичная</div>
                        <div class="toggle-option" data-value="friends" onclick="setToggle('vis','friends',this)">👥 Для друзей</div>
                    </div>
                </div>
                <input type="hidden" name="category_id" id="post_cat_id" value="1">
                <input type="hidden" name="visibility"  id="post_visibility" value="public">

                <div class="field-wrap">
                    <input type="text" name="title" id="titleInput" class="input-styled input-title"
                           placeholder="Заголовок новости" maxlength="200" required
                           oninput="updateCounter('titleInput','titleCounter',200)">
                    <span class="char-counter" id="titleCounter">0 / 200</span>
                </div>
                <div class="field-wrap">
                    <textarea name="content" id="contentInput" class="input-styled input-content"
                              placeholder="О чём новость?" maxlength="5000" required
                              oninput="updateCounter('contentInput','contentCounter',5000)"></textarea>
                    <span class="char-counter" id="contentCounter">0 / 5000</span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
                    <label style="cursor:pointer; color:var(--primary); font-weight:600; font-size:14px;">
                        <i class="fa-solid fa-camera"></i> Фото
                        <input type="file" name="image" id="imageInput" hidden accept="image/*" onchange="previewFile()">
                    </label>
                    <div style="display:flex; gap:10px;">
                        <button type="button" onclick="submitPost('draft')" class="btn btn-draft">В черновик</button>
                        <button type="button" onclick="submitPost('published')" class="btn btn-register">
                            <i class="fa-solid fa-bolt-lightning"></i> Создать новость
                        </button>
                    </div>
                </div>
                <img id="imagePreview" style="width:100%; border-radius:12px; margin-top:15px; display:none;">
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach($posts as $post): ?>
        <div class="post-card">
            <div class="post-header">
                <div class="author-info">
                    <a href="profile.php?id=<?= $post['user_id'] ?>" class="author-link">
                        <img src="<?= getAvatar($post['author_avatar'], $post['username']) ?>" alt="Ava">
                        <div>
                            <span class="author-name"><?= htmlspecialchars($post['username']) ?></span>
                            <span class="post-date"><?= date("d M в H:i", strtotime($post['created_at'])) ?></span>
                        </div>
                    </a>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                    <?php if($is_admin || ($is_logged_in && $current_user_id == $post['user_id'])): ?>
                        <i class="fa-solid fa-trash-can" style="color:var(--like-red); cursor:pointer;" onclick="deleteMyPost(<?= $post['id'] ?>)"></i>
                    <?php endif; ?>
                    <?php $vis = $post['visibility'] ?? 'public'; ?>
                    <span class="visibility-badge <?= $vis === 'friends' ? 'friends-vis' : '' ?>">
                        <i class="fa-solid <?= $vis === 'friends' ? 'fa-user-group' : 'fa-earth-europe' ?>"></i>
                        <?= $vis === 'friends' ? 'Друзья' : 'Публично' ?>
                    </span>
                    <span class="cat-badge"><?= htmlspecialchars($post['cat_name'] ?? 'Общее') ?></span>
                </div>
            </div>
            <div class="post-content">
                <h3 class="post-title"><?= htmlspecialchars($post['title']) ?></h3>
                <div class="post-text"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
                <?php if (!empty($post['image'])): ?>
                    <img src="uploads/<?= $post['image'] ?>" class="post-img">
                <?php endif; ?>
            </div>
            <div class="post-footer">
                <div class="action-btn <?= $post['is_liked'] ? 'liked' : '' ?>" onclick="toggleLike(<?= $post['id'] ?>,this)">
                    <i class="<?= $post['is_liked'] ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
                    <span><?= $post['likes_count'] ?></span>
                </div>
                <div class="action-btn" onclick="toggleComments(<?= $post['id'] ?>)">
                    <i class="fa-regular fa-comment"></i><span><?= $post['comm_count'] ?></span>
                </div>
                <div class="action-btn <?= $post['is_fav'] ? 'faved' : '' ?>" style="margin-left:auto;" onclick="toggleFavorite(<?= $post['id'] ?>,this)">
                    <i class="<?= $post['is_fav'] ? 'fa-solid' : 'fa-regular' ?> fa-bookmark"></i>
                </div>
            </div>
            <div class="comments-area" id="comments-<?= $post['id'] ?>">
                <?php
                $all_comments = getCommentsForPost($post['id'], $pdo, $current_user_id);
                foreach($all_comments as $c): if($c['parent_id']) continue;
                    $c_age      = time() - strtotime($c['created_at']);
                    $c_can_edit = $is_logged_in && $current_user_id == $c['user_id'] && $c_age < 300;
                    $c_can_del  = $is_logged_in && ($is_admin || $current_user_id == $c['user_id']);
                    $c_mins_left = max(0, ceil((300 - $c_age) / 60));
                ?>
                    <div class="comment-item" id="comm-<?= $c['id'] ?>" data-created="<?= strtotime($c['created_at']) ?>">
                        <div class="comm-user">
                            <img src="<?= getAvatar($c['avatar'], $c['username']) ?>" alt="">
                            <?= htmlspecialchars($c['username']) ?>
                            <span class="comm-time"><?= date('d.m в H:i', strtotime($c['created_at'])) ?></span>
                        </div>
                        <div class="comm-txt" id="comm-txt-<?= $c['id'] ?>" data-raw="<?= htmlspecialchars($c['content'], ENT_QUOTES) ?>"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
                        <div class="comm-actions">
                            <button class="comm-like-btn <?= $c['is_liked'] ? 'liked' : '' ?>" onclick="likeComment(<?= $c['id'] ?>, this)">
                                <i class="<?= $c['is_liked'] ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
                                <span><?= (int)$c['likes_count'] ?></span>
                            </button>
                            <?php if($is_logged_in): ?>
                                <span class="comm-sep">·</span>
                                <button class="comm-reply-btn" onclick="toggleReplyForm(<?= $c['id'] ?>)">
                                    <i class="fa-regular fa-comment"></i> Ответить
                                </button>
                            <?php endif; ?>
                            <?php if($c_can_edit): ?>
                                <span class="comm-sep">·</span>
                                <button class="comm-edit-btn" id="edit-btn-<?= $c['id'] ?>" onclick="startEdit(<?= $c['id'] ?>)">
                                    <i class="fa-regular fa-pen-to-square"></i> Изменить
                                </button>
                                <span class="comm-edit-timer" id="edit-timer-<?= $c['id'] ?>"><?= $c_mins_left ?> мин</span>
                            <?php endif; ?>
                            <?php if($c_can_del): ?>
                                <span class="comm-sep">·</span>
                                <button class="comm-del-btn" onclick="deleteComment(<?= $c['id'] ?>)" title="Удалить">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if($is_logged_in): ?>
                        <div class="reply-form" id="reply-form-<?= $c['id'] ?>">
                            <input type="text" class="input-styled" id="reply-input-<?= $c['id'] ?>" placeholder="Ответить <?= htmlspecialchars($c['username']) ?>...">
                            <button class="btn btn-register" onclick="submitReply(<?= $post['id'] ?>, <?= $c['id'] ?>)"><i class="fa-solid fa-paper-plane"></i></button>
                        </div>
                        <?php endif; ?>
                        <?php foreach($all_comments as $r): if($r['parent_id'] != $c['id']) continue;
                            $r_age      = time() - strtotime($r['created_at']);
                            $r_can_edit = $is_logged_in && $current_user_id == $r['user_id'] && $r_age < 300;
                            $r_can_del  = $is_logged_in && ($is_admin || $current_user_id == $r['user_id']);
                            $r_mins_left = max(0, ceil((300 - $r_age) / 60));
                        ?>
                            <div class="comment-item reply-item" id="comm-<?= $r['id'] ?>" data-created="<?= strtotime($r['created_at']) ?>">
                                <div class="comm-user">
                                    <img src="<?= getAvatar($r['avatar'], $r['username']) ?>" alt="">
                                    <?= htmlspecialchars($r['username']) ?>
                                    <span class="comm-time"><?= date('d.m в H:i', strtotime($r['created_at'])) ?></span>
                                </div>
                                <div class="comm-txt" id="comm-txt-<?= $r['id'] ?>" data-raw="<?= htmlspecialchars($r['content'], ENT_QUOTES) ?>"><?= nl2br(htmlspecialchars($r['content'])) ?></div>
                                <div class="comm-actions">
                                    <button class="comm-like-btn <?= $r['is_liked'] ? 'liked' : '' ?>" onclick="likeComment(<?= $r['id'] ?>, this)">
                                        <i class="<?= $r['is_liked'] ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
                                        <span><?= (int)$r['likes_count'] ?></span>
                                    </button>
                                    <?php if($r_can_edit): ?>
                                        <span class="comm-sep">·</span>
                                        <button class="comm-edit-btn" id="edit-btn-<?= $r['id'] ?>" onclick="startEdit(<?= $r['id'] ?>)">
                                            <i class="fa-regular fa-pen-to-square"></i> Изменить
                                        </button>
                                        <span class="comm-edit-timer" id="edit-timer-<?= $r['id'] ?>"><?= $r_mins_left ?> мин</span>
                                    <?php endif; ?>
                                    <?php if($r_can_del): ?>
                                        <span class="comm-sep">·</span>
                                        <button class="comm-del-btn" onclick="deleteComment(<?= $r['id'] ?>)" title="Удалить">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php if($is_logged_in): ?>
                    <div style="margin-top:15px; display:flex; gap:10px;">
                        <input type="text" class="input-styled" id="mt-<?= $post['id'] ?>" placeholder="Написать комментарий...">
                        <button class="btn btn-register" onclick="addComm(<?= $post['id'] ?>)">Отправить</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
// ══════════════════════════════════════
//  УТИЛИТЫ
// ══════════════════════════════════════
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, bg = '#1e293b') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.style.background = bg;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3200);
}

// ══════════════════════════════════════
//  УПРАВЛЕНИЕ ПАНЕЛЯМИ
// ══════════════════════════════════════
let activePanel = null;

function openPanel(id) {
    closeAllPanels(false);
    activePanel = id;
    document.getElementById(id + 'Panel').classList.add('open');
    document.getElementById('panelOverlay').style.display = 'block';
    document.getElementById('friendsBtn')?.classList.toggle('active', id === 'friends');
    document.getElementById('bellBtn')?.classList.toggle('active', id === 'notif');
    if (id === 'friends') loadFriends();
    if (id === 'notif')   loadNotifications();
}

function closeAllPanels(reset = true) {
    document.querySelectorAll('.side-panel').forEach(p => p.classList.remove('open'));
    document.getElementById('panelOverlay').style.display = 'none';
    document.getElementById('friendsBtn')?.classList.remove('active');
    document.getElementById('bellBtn')?.classList.remove('active');
    if (reset) activePanel = null;
}

function togglePanel(id) {
    activePanel === id ? closeAllPanels() : openPanel(id);
}

// ══════════════════════════════════════
//  ДРУЗЬЯ — загрузка списка
// ══════════════════════════════════════
async function loadFriends() {
    const list = document.getElementById('friendsList');
    list.innerHTML = '<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    try {
        const data = await (await fetch('friends_api.php?action=get_friends')).json();
        if (!data.friends?.length) {
            list.innerHTML = '<div class="empty-state"><i class="fa-solid fa-user-group"></i>Друзей пока нет.<br>Найдите кого-нибудь выше 👆</div>';
            return;
        }
        list.innerHTML = data.friends.map(f => `
            <a href="profile.php?id=${f.id}" class="friend-item">
                <img src="${f.avatar_url}" alt="${escHtml(f.username)}">
                <div>
                    <div class="friend-name">${escHtml(f.username)}</div>
                    <div class="friend-sub">Друг</div>
                </div>
            </a>`).join('');
    } catch { list.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
}

// ══════════════════════════════════════
//  ПОИСК ПОЛЬЗОВАТЕЛЕЙ
// ══════════════════════════════════════
let searchTimer = null;

function liveSearch(q) {
    clearTimeout(searchTimer);
    const area = document.getElementById('searchResultsArea');
    if (q.trim().length < 2) { area.style.display = 'none'; return; }

    searchTimer = setTimeout(async () => {
        try {
            const data = await (await fetch('friends_api.php?action=search&q=' + encodeURIComponent(q))).json();
            area.style.display = 'block';
            if (!data.users?.length) {
                area.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:6px 2px;">Пользователи не найдены</div>';
                return;
            }
            area.innerHTML = data.users.map(u => {
                let action = '';
                if (u.rel_status === 'accepted')
                    action = '<span style="font-size:12px;color:#22c55e;font-weight:700;white-space:nowrap;">✓ Друг</span>';
                else if (u.rel_status === 'pending')
                    action = '<span style="font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">Запрос отправлен</span>';
                else
                    action = `<button class="send-invite-btn" onclick="sendInviteTo('${escHtml(u.username)}',this)"><i class="fa-solid fa-user-plus"></i> Добавить</button>`;
                return `
                <div class="search-result-item">
                    <img src="${u.avatar_url}" alt="${escHtml(u.username)}">
                    <div style="flex:1;min-width:0;"><div class="sri-name">${escHtml(u.username)}</div></div>
                    ${action}
                </div>`;
            }).join('');
        } catch { area.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">Ошибка поиска</div>'; area.style.display='block'; }
    }, 350);
}

// ══════════════════════════════════════
//  ОТПРАВКА ПРИГЛАШЕНИЯ
// ══════════════════════════════════════
async function sendInviteTo(username, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }
    const fd = new FormData();
    fd.append('action', 'send_request');
    fd.append('username', username);
    try {
        const data = await (await fetch('friends_api.php', { method:'POST', body:fd })).json();
        if (data.success) {
            showToast('✅ ' + data.message, '#22c55e');
            if (btn) btn.closest('.search-result-item').querySelector('div:last-child').outerHTML =
                '<span style="font-size:12px;color:var(--text-muted);font-weight:600;">Запрос отправлен</span>';
        } else {
            showToast('⚠️ ' + (data.error || 'Ошибка'), '#f59e0b');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Добавить'; }
        }
    } catch {
        showToast('❌ Ошибка сети', '#ef4444');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Добавить'; }
    }
}

function sendInviteFromInput() {
    const val = document.getElementById('friendSearchInput').value.trim();
    if (!val) { showToast('Введите ник пользователя'); return; }
    sendInviteTo(val, null);
}

// ══════════════════════════════════════
//  УВЕДОМЛЕНИЯ
// ══════════════════════════════════════
async function loadNotifications() {
    const list = document.getElementById('notifList');
    list.innerHTML = '<div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    try {
        const data = await (await fetch('friends_api.php?action=get_notifications')).json();
        updateBadge(data.count || 0);
        if (!data.requests?.length) {
            list.innerHTML = '<div class="empty-state"><i class="fa-regular fa-bell"></i>Нет новых уведомлений</div>';
            return;
        }
        list.innerHTML = data.requests.map(r => `
            <div class="notif-item" id="notif-${r.fid}">
                <img src="${r.avatar_url}" alt="${escHtml(r.username)}">
                <div class="notif-info">
                    <strong>${escHtml(r.username)}</strong>
                    <div class="notif-sub">хочет добавить вас в друзья</div>
                    <div class="notif-actions">
                        <button class="accept-btn"  onclick="respondReq(${r.fid},'accept')"><i class="fa-solid fa-check"></i> Принять</button>
                        <button class="decline-btn" onclick="respondReq(${r.fid},'decline')">Отклонить</button>
                    </div>
                </div>
            </div>`).join('');
    } catch { list.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
}

async function respondReq(fid, response) {
    const fd = new FormData();
    fd.append('action','respond'); fd.append('friendship_id', fid); fd.append('response', response);
    try {
        const data = await (await fetch('friends_api.php', { method:'POST', body:fd })).json();
        if (data.success) {
            const el = document.getElementById('notif-' + fid);
            if (el) { el.style.opacity='0'; el.style.transform='translateX(20px)'; setTimeout(() => { el.remove(); checkEmptyNotif(); }, 300); }
            showToast(response === 'accept' ? '🎉 ' + data.message : '❌ Запрос отклонён',
                      response === 'accept' ? '#22c55e' : '#64748b');
            const rem = document.querySelectorAll('.notif-item').length - 1;
            updateBadge(Math.max(0, rem));
            if (response === 'accept') loadFriends();
        }
    } catch { showToast('❌ Ошибка', '#ef4444'); }
}

function checkEmptyNotif() {
    if (!document.querySelector('.notif-item'))
        document.getElementById('notifList').innerHTML = '<div class="empty-state"><i class="fa-regular fa-bell"></i>Нет новых уведомлений</div>';
}

function updateBadge(n) {
    const b = document.getElementById('notifBadge'); if (!b) return;
    b.textContent = n; b.style.display = n > 0 ? 'flex' : 'none';
}

async function fetchBadge() {
    try {
        const data = await (await fetch('friends_api.php?action=get_notifications')).json();
        updateBadge(data.count || 0);
    } catch {}
}

// ══════════════════════════════════════
//  ФОРМА СОЗДАНИЯ ПОСТА
// ══════════════════════════════════════
function setToggle(type, value, el) {
    const sid = type === 'cat' ? 'catSelector' : 'visSelector';
    const sld = type === 'cat' ? 'catSlider'   : 'visSlider';
    const hid = type === 'cat' ? 'post_cat_id' : 'post_visibility';
    const sel = document.getElementById(sid), sli = document.getElementById(sld);
    sel.querySelectorAll('.toggle-option').forEach(o => o.classList.remove('active'));
    el.classList.add('active');
    if (sli) { sli.style.width = el.offsetWidth + 'px'; sli.style.transform = `translateX(${el.offsetLeft - 4}px)`; }
    document.getElementById(hid).value = value;
}

function updateCounter(iid, cid, max) {
    const len = document.getElementById(iid).value.length;
    const c = document.getElementById(cid);
    c.textContent = len + ' / ' + max;
    c.classList.toggle('over',  len >= max);
    c.classList.toggle('warn',  len >= max * 0.85 && len < max);
}

function previewFile() {
    const f = document.getElementById('imageInput').files[0], p = document.getElementById('imagePreview');
    if (!f) return;
    const r = new FileReader(); r.onloadend = () => { p.src = r.result; p.style.display = 'block'; }; r.readAsDataURL(f);
}

function submitPost(status) {
    if (document.getElementById('titleInput').value.length > 200)   { showToast('⚠️ Заголовок слишком длинный','#f59e0b'); return; }
    if (document.getElementById('contentInput').value.length > 5000) { showToast('⚠️ Текст слишком длинный','#f59e0b'); return; }
    const fd = new FormData(document.getElementById('createPostForm'));
    fd.append('status', status); fd.append('action', 'create');
    fetch('create_post.php', { method:'POST', body:fd })
        .then(r => r.json()).then(d => d.success ? location.reload() : showToast('❌ ' + d.error, '#ef4444'));
}

async function deleteMyPost(id) {
    if (!confirm('Удалить этот пост?')) return;
    const fd = new FormData(); fd.append('action','delete'); fd.append('news_id', id);
    if ((await (await fetch('create_post.php', { method:'POST', body:fd })).json()).success) location.reload();
}

async function deleteComment(id) {
    if (!confirm('Удалить комментарий?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('comment_id', id);
    try {
        const data = await (await fetch('add_comment.php', { method:'POST', body:fd })).json();
        if (data.success) {
            const el = document.getElementById('comm-' + id);
            if (el) { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }
            showToast('✅ Комментарий удалён', '#22c55e');
        } else {
            showToast('❌ ' + (data.error || 'Ошибка'), '#ef4444');
        }
    } catch { showToast('❌ Ошибка сети', '#ef4444'); }
}

// ── РЕДАКТИРОВАНИЕ КОММЕНТАРИЯ ─────────────────────
function startEdit(id) {
    const txtEl = document.getElementById('comm-txt-' + id);
    const raw   = txtEl.dataset.raw || '';
    txtEl.innerHTML = `
        <textarea class="input-styled comm-edit-area" id="edit-area-${id}">${escHtml(raw)}</textarea>
        <div style="display:flex;gap:8px;margin-top:6px;">
            <button class="btn btn-register" style="padding:7px 14px;font-size:13px;" onclick="saveEdit(${id})">
                <i class="fa-solid fa-check"></i> Сохранить
            </button>
            <button class="btn btn-draft" style="padding:7px 14px;font-size:13px;" onclick="cancelEdit(${id})">
                Отмена
            </button>
        </div>`;
    document.getElementById('edit-area-' + id)?.focus();
}

function cancelEdit(id) {
    const txtEl = document.getElementById('comm-txt-' + id);
    const raw   = txtEl.dataset.raw || '';
    txtEl.innerHTML = raw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

async function saveEdit(id) {
    const area = document.getElementById('edit-area-' + id);
    const text = area?.value?.trim();
    if (!text) { showToast('⚠️ Текст не может быть пустым', '#f59e0b'); return; }
    const fd = new FormData();
    fd.append('action', 'edit'); fd.append('comment_id', id); fd.append('content', text);
    try {
        const d = await (await fetch('add_comment.php', {method:'POST', body:fd})).json();
        if (d.success) {
            const txtEl = document.getElementById('comm-txt-' + id);
            txtEl.dataset.raw = text;
            txtEl.innerHTML = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
            showToast('✅ Комментарий обновлён', '#22c55e');
        } else {
            showToast('❌ ' + (d.error || 'Ошибка'), '#ef4444');
        }
    } catch { showToast('❌ Ошибка сети', '#ef4444'); }
}

// Скрывает кнопку «Изменить» и таймер когда 5 минут истекли
function initEditTimers() {
    document.querySelectorAll('.comm-edit-btn').forEach(btn => {
        const id      = btn.id.replace('edit-btn-', '');
        const item    = document.getElementById('comm-' + id);
        const timerEl = document.getElementById('edit-timer-' + id);
        if (!item) return;
        const created = parseInt(item.dataset.created || 0) * 1000;
        const expiry  = created + 5 * 60 * 1000;

        const tick = () => {
            const left = Math.max(0, Math.ceil((expiry - Date.now()) / 60000));
            if (Date.now() >= expiry) {
                btn.style.display = 'none';
                if (timerEl) timerEl.style.display = 'none';
                // Скрываем разделитель перед кнопкой тоже
                const prev = btn.previousElementSibling;
                if (prev?.classList.contains('comm-sep')) prev.style.display = 'none';
            } else {
                if (timerEl) timerEl.textContent = left + ' мин';
            }
        };
        tick();
        const iv = setInterval(() => { tick(); if (Date.now() >= expiry) clearInterval(iv); }, 30000);
    });
}

function toggleComments(id) {
    const a = document.getElementById('comments-'+id);
    a.style.display = a.style.display === 'block' ? 'none' : 'block';
}

function addComm(newsId) {
    const text = document.getElementById('mt-'+newsId).value; if (!text.trim()) return;
    const fd = new FormData(); fd.append('action','add'); fd.append('news_id',newsId); fd.append('content',text);
    fetch('add_comment.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>d.success?location.reload():showToast('❌ '+d.error,'#ef4444'));
}

function toggleReplyForm(commentId) {
    const form = document.getElementById('reply-form-' + commentId);
    const isOpen = form.style.display === 'flex';
    form.style.display = isOpen ? 'none' : 'flex';
    if (!isOpen) document.getElementById('reply-input-' + commentId)?.focus();
}

function submitReply(newsId, parentId) {
    const input = document.getElementById('reply-input-' + parentId);
    const text = input?.value?.trim(); if (!text) return;
    const fd = new FormData();
    fd.append('action', 'add'); fd.append('news_id', newsId);
    fd.append('content', text); fd.append('parent_id', parentId);
    fetch('add_comment.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => d.success ? location.reload() : showToast('❌ ' + d.error, '#ef4444'));
}

async function likeComment(id, el) {
    const fd = new FormData(); fd.append('comment_id', id);
    try {
        const d = await (await fetch('comment_like.php', {method:'POST', body:fd})).json();
        const icon = el.querySelector('i'), span = el.querySelector('span');
        if (d.status === 'added') {
            el.classList.add('liked'); icon.classList.replace('fa-regular','fa-solid');
            span.innerText = +span.innerText + 1;
            icon.style.transform = 'scale(1.4)'; setTimeout(()=>icon.style.transform='scale(1)',200);
        } else {
            el.classList.remove('liked'); icon.classList.replace('fa-solid','fa-regular');
            span.innerText = Math.max(0, +span.innerText - 1);
        }
    } catch {}
}

async function toggleLike(id, el) {
    const fd = new FormData(); fd.append('news_id', id);
    try {
        const d = await (await fetch('like.php',{method:'POST',body:fd})).json();
        const icon = el.querySelector('i'), span = el.querySelector('span');
        if (d.status === 'added') {
            el.classList.add('liked'); icon.classList.replace('fa-regular','fa-solid');
            span.innerText = +span.innerText + 1;
            icon.style.transform = 'scale(1.4)'; setTimeout(()=>icon.style.transform='scale(1)',200);
        } else {
            el.classList.remove('liked'); icon.classList.replace('fa-solid','fa-regular');
            span.innerText = +span.innerText - 1;
        }
    } catch {}
}

async function toggleFavorite(id, el) {
    const fd = new FormData(); fd.append('news_id', id);
    try {
        const d = await (await fetch('favorite.php',{method:'POST',body:fd})).json();
        const icon = el.querySelector('i');
        if (d.status === 'added') {
            el.classList.add('faved'); icon.classList.replace('fa-regular','fa-solid');
            icon.style.transform = 'scale(1.3)'; setTimeout(()=>icon.style.transform='scale(1)',200);
        } else { el.classList.remove('faved'); icon.classList.replace('fa-solid','fa-regular'); }
    } catch {}
}

// ══════════════════════════════════════
//  ИНИЦИАЛИЗАЦИЯ
// ══════════════════════════════════════
window.addEventListener('DOMContentLoaded', () => {
    ['cat','vis'].forEach(type => {
        const sid = type==='cat'?'catSelector':'visSelector', sld = type==='cat'?'catSlider':'visSlider';
        const sel = document.getElementById(sid); if(!sel) return;
        const act = sel.querySelector('.toggle-option.active'), sli = document.getElementById(sld);
        if (act && sli) { sli.style.width = act.offsetWidth+'px'; sli.style.transform=`translateX(${act.offsetLeft-4}px)`; }
    });
    initEditTimers();
    <?php if ($is_logged_in): ?>
    fetchBadge();
    setInterval(fetchBadge, 30000);
    <?php endif; ?>
});
</script>
</body>
</html>