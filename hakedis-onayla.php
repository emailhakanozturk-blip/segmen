<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? 0;

if($id){

    $update = $db->prepare("
        UPDATE hakedisler
        SET durum = 'onaylandi'
        WHERE id = ?
    ");

    $update->execute([$id]);
}

header("Location: hakedisler.php");
exit;