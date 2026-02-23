<?php
require 'config/database.php';
require 'vendor/autoload.php';

use Faker\Factory;

$faker = Factory::create('ru_RU');

echo "🚀 Начинаем наполнение базы данных...\n";


echo "👤 Создаем пользователей... ";

$userIds = [];

for ($i = 0; $i < 10; $i++) {

    $username = $faker->unique()->userName;
    $email = $faker->unique()->email;
    $passwordHash = password_hash('123456', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, created_at)
        VALUES (?, ?, ?, NOW())
    ");

    $stmt->execute([$username, $email, $passwordHash]);

    $userIds[] = $pdo->lastInsertId();
}

echo "Готово!\n";


echo "📂 Создаем категории... ";

$categoryNames = [
    'Политика',
    'Экономика',
    'Спорт',
    'Технологии',
    'Культура',
    'Происшествия'
];

$categoryIds = [];

foreach ($categoryNames as $name) {

    $stmt = $pdo->prepare("
        INSERT INTO categories (name, created_at)
        VALUES (?, NOW())
    ");

    $stmt->execute([$name]);
    $categoryIds[] = $pdo->lastInsertId();
}

echo "Готово!\n";



echo "📰 Создаем новости... ";

for ($i = 0; $i < 30; $i++) {

    $title = $faker->sentence(6);
    $content = $faker->realText(800);

    $userId = $userIds[array_rand($userIds)];
    $categoryId = $categoryIds[array_rand($categoryIds)];

    // Дата за последние 30 дней
    $createdAt = $faker->dateTimeBetween('-30 days', 'now')
                        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO news 
        (user_id, category_id, title, content, visibility, status, created_at)
        VALUES (?, ?, ?, ?, 'public', 'published', ?)
    ");

    $stmt->execute([
        $userId,
        $categoryId,
        $title,
        $content,
        $createdAt
    ]);
}

echo "Готово! (30 новостей создано)\n";



echo "💬 Создаем комментарии... ";

$newsIds = $pdo->query("SELECT id FROM news")
               ->fetchAll(PDO::FETCH_COLUMN);

for ($i = 0; $i < 100; $i++) {

    $newsId = $newsIds[array_rand($newsIds)];
    $userId = $userIds[array_rand($userIds)];
    $content = $faker->realText(150);

    $stmt = $pdo->prepare("
        INSERT INTO comments 
        (news_id, user_id, content, created_at)
        VALUES (?, ?, ?, NOW())
    ");

    $stmt->execute([
        $newsId,
        $userId,
        $content
    ]);
}

echo "Готово! (100 комментариев создано)\n";

echo "✅ База данных успешно заполнена!\n";