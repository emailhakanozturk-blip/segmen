<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'segmen');
define('DB_USER', 'root');
define('DB_PASS', '');

try {

    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET CHARACTER SET utf8mb4");

} catch (PDOException $e) {

    die("Veritabanı bağlantı hatası: " . $e->getMessage());

}