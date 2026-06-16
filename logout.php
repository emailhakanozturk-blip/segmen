<?php
session_start();
require_once __DIR__ . '/config/database.php';

if(function_exists('segmen_kullanici_logla') && isset($_SESSION['user_id'])){
    segmen_kullanici_logla($db, 'Çıkış yaptı', 'Sistemden çıkış yaptı');
}

session_unset();
session_destroy();
header("Location: login.php");
exit;
