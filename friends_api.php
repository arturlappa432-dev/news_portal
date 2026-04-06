<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── DB CONNECTION ─────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=127.0.0.1;dbname=news_portal;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB error']);
    exit;
}

// ── AUTH ──────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

$me = (int)$_SESSION['user_id'];

// ── ACTION ────────────────────────────────────
$action = '';
if (isset($_POST['action'])) {
    $action = $_POST['action'];
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];
}

// ── AVATAR HELPER ─────────────────────────────
function avatarUrl($filename, $username)
{
    if (!empty($filename) && file_exists("uploads/" . $filename)) {
        return "uploads/" . $filename;
    }

    return "https://ui-avatars.com/api/?background=6366f1&color=fff&bold=true&name="
        . urlencode($username ?: 'U');
}

// ── ROUTER ────────────────────────────────────
switch ($action) {

    // ── SEARCH USERS ───────────────────────────
    case 'search':
        $q = trim(isset($_GET['q']) ? $_GET['q'] : '');

        if (strlen($q) < 2) {
            echo json_encode(['users' => []]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, username, avatar,
            (SELECT status FROM friendships
             WHERE (user_id = :me AND friend_id = u.id)
                OR (user_id = u.id AND friend_id = :me2)
             LIMIT 1) as rel_status
            FROM users u
            WHERE username LIKE :q AND id != :me3
            LIMIT 10
        ");

        $stmt->execute([
            ':me' => $me,
            ':me2' => $me,
            ':me3' => $me,
            ':q' => "%$q%"
        ]);

        $users = $stmt->fetchAll();

        foreach ($users as $key => $u) {
            $users[$key]['avatar_url'] = avatarUrl($u['avatar'], $u['username']);
        }

        echo json_encode(['users' => $users]);
        break;

    // ── SEND FRIEND REQUEST ────────────────────
    case 'send_request':
        $username = trim(isset($_POST['username']) ? $_POST['username'] : '');

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $target = $stmt->fetch();

        if (!$target) {
            echo json_encode(['error' => 'Пользователь не найден']);
            exit;
        }

        $tid = (int)$target['id'];

        if ($tid === $me) {
            echo json_encode(['error' => 'Нельзя добавить себя']);
            exit;
        }

        // check existing relation
        $stmt = $pdo->prepare("
            SELECT id, status FROM friendships
            WHERE (user_id=? AND friend_id=?)
               OR (user_id=? AND friend_id=?)
        ");

        $stmt->execute([$me, $tid, $tid, $me]);
        $existing = $stmt->fetch();

        if ($existing) {

            switch ($existing['status']) {
                case 'pending':
                    $msg = 'Запрос уже отправлен';
                    break;

                case 'accepted':
                    $msg = 'Вы уже друзья';
                    break;

                default:
                    $msg = 'Запрос существует';
                    break;
            }

            echo json_encode(['error' => $msg]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO friendships (user_id, friend_id, status, created_at)
            VALUES (?, ?, 'pending', NOW())
        ");

        $stmt->execute([$me, $tid]);

        echo json_encode([
            'success' => true,
            'message' => "Запрос отправлен пользователю $username"
        ]);
        break;

    // ── RESPOND (ACCEPT / DECLINE) ─────────────
    case 'respond':
        $fid = isset($_POST['friendship_id']) ? (int)$_POST['friendship_id'] : 0;
        $resp = isset($_POST['response']) ? $_POST['response'] : '';

        $stmt = $pdo->prepare("
            SELECT * FROM friendships
            WHERE id = ? AND friend_id = ?
        ");

        $stmt->execute([$fid, $me]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['error' => 'Запрос не найден']);
            exit;
        }

        if ($resp === 'accept') {
            $pdo->prepare("
                UPDATE friendships SET status='accepted' WHERE id=?
            ")->execute([$fid]);

            echo json_encode(['success' => true, 'message' => 'Теперь вы друзья!']);
        } else {
            $pdo->prepare("
                DELETE FROM friendships WHERE id=?
            ")->execute([$fid]);

            echo json_encode(['success' => true, 'message' => 'Запрос отклонён']);
        }
        break;

    // ── FRIENDS LIST ────────────────────────────
    case 'get_friends':
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.avatar, f.id as fid
            FROM friendships f
            JOIN users u ON (
                CASE
                    WHEN f.user_id = :me THEN f.friend_id
                    ELSE f.user_id
                END = u.id
            )
            WHERE (f.user_id = :me2 OR f.friend_id = :me3)
              AND f.status = 'accepted'
        ");

        $stmt->execute([
            ':me' => $me,
            ':me2' => $me,
            ':me3' => $me
        ]);

        $friends = $stmt->fetchAll();

        foreach ($friends as $key => $f) {
            $friends[$key]['avatar_url'] = avatarUrl($f['avatar'], $f['username']);
        }

        echo json_encode(['friends' => $friends]);
        break;

    // ── NOTIFICATIONS ───────────────────────────
    case 'get_notifications':
        $stmt = $pdo->prepare("
            SELECT f.id as fid, u.id as uid, u.username, u.avatar, f.created_at
            FROM friendships f
            JOIN users u ON f.user_id = u.id
            WHERE f.friend_id = ? AND f.status = 'pending'
            ORDER BY f.created_at DESC
        ");

        $stmt->execute([$me]);
        $reqs = $stmt->fetchAll();

        foreach ($reqs as $key => $r) {
            $reqs[$key]['avatar_url'] = avatarUrl($r['avatar'], $r['username']);
        }

        echo json_encode([
            'requests' => $reqs,
            'count' => count($reqs)
        ]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
        break;
}