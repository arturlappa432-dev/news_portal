![Version](https://img.shields.io/badge/version-1.0.0-green) 
Последний релиз: [v1.0.0](https://github.com/ВАШ_ЛОГИН/ВАШ_РЕПО/releases/tag/v1.0.0) 

Описание

Веб-приложение для публикации новостей и интересных фактов.
Пользователи могут просматривать ленту новостей, оставлять комментарии и управлять своим профилем.
Администратор имеет доступ к управлению новостями, категориями и пользователями через админ-панель.

Технологии
PHP 8.1
MySQL
Bootstrap

Установка и запуск

Клонируйте репозиторий:
git clone https://github.com/artur-lappa432-dev/news_portal.git
Скопируйте папку проекта в:
OpenServer/domains/
Создайте базу данных:
CREATE DATABASE news_portal;
Импортируйте файл:
sql/news_portal.sql
Настройте подключение к БД в файле:
config/db.php

Укажите:

host: localhost
dbname: news_portal
user: root
password: (root)
Откройте в браузере:
http://localhost/news_portal/
Роли пользователей
Роль	Возможности
admin	Полный доступ (управление новостями, пользователями, комментариями)
user	Просмотр новостей, комментарии, управление профилем
API

Документация API:
[Knowledge Base](https://icespike636.youtrack.cloud/articles/LAP-A-14/API-dokumentaciya)

Автор
Студент группы ПВТ-9-23 Лаппа Артур
СВГТК им. Абая Кунанбаева
