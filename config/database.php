<?php
class BlockedUser {
    private $db;
    public $id;
    public $user_id;
    public $blocked_by;
    public $reason;
    public $blocked_at;

    public function __construct($db) {
        $this->db = $db;
    }

    public function all() {
        return $this->db->query("SELECT * FROM blocked_users")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id) {
        $stmt = $this->db->prepare("SELECT * FROM blocked_users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function block($user_id, $blocked_by, $reason = null) {
        $stmt = $this->db->prepare(
            "INSERT INTO blocked_users (user_id, blocked_by, reason) VALUES (:user_id, :blocked_by, :reason)"
        );
        $stmt->execute([
            'user_id' => $user_id,
            'blocked_by' => $blocked_by,
            'reason' => $reason
        ]);
        return $this->db->lastInsertId();
    }

    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM blocked_users WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}
