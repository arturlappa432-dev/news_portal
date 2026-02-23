<?php
// 1. Подключаем автозагрузчик Composer (он сам найдет все библиотеки)
require_once __DIR__ . '/../vendor/autoload.php';
// 2. Загружаем настройки из .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();
try {
    // 3. Берем данные из переменных окружения
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $db   = $_ENV['DB_NAME'];
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
        $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, $user, $pass, $opt);
    
} catch (\PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}
?>
