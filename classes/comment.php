<?php
class Comment {
    private $db;
    public $id;
    public $news_id;
    public $user_id;
    public $parent_id;
    public $content;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
    }

    public function all() {
        return $this->db->query("SELECT * FROM comments")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id) {
        $stmt = $this->db->prepare("SELECT * FROM comments WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($news_id, $user_id, $content, $parent_id = null) {
        $stmt = $this->db->prepare(
            "INSERT INTO comments (news_id, user_id, content, parent_id) VALUES (:news_id, :user_id, :content, :parent_id)"
        );
        $stmt->execute([
            'news_id' => $news_id,
            'user_id' => $user_id,
            'content' => $content,
            'parent_id' => $parent_id
        ]);
        return $this->db->lastInsertId();
    }

    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM comments WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}
