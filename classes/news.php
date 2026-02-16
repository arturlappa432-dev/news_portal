<?php
class News {
    private $db;
    public $id;
    public $user_id;
    public $category_id;
    public $title;
    public $content;
    public $visibility;
    public $status;
    public $created_at;

    public function __construct($db) {
        $this->db = $db;
    }

    public function all() {
        return $this->db->query("SELECT * FROM news")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id) {
        $stmt = $this->db->prepare("SELECT * FROM news WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($user_id, $category_id, $title, $content, $visibility, $status) {
        $stmt = $this->db->prepare(
            "INSERT INTO news (user_id, category_id, title, content, visibility, status)
             VALUES (:user_id, :category_id, :title, :content, :visibility, :status)"
        );
        $stmt->execute([
            'user_id' => $user_id,
            'category_id' => $category_id,
            'title' => $title,
            'content' => $content,
            'visibility' => $visibility,
            'status' => $status
        ]);
        return $this->db->lastInsertId();
    }

    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM news WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}
