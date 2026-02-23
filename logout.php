<?php
session_start();
session_unset();
session_destroy();

// Возвращаем на главную, а не на страницу входа
header("Location: index.php"); 
exit();