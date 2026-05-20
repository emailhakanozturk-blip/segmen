<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? 0;

if($id){

    $delete = $db->prepare("
        DELETE FROM sozlesmeler
        WHERE id = ?
    ");

    $delete->execute([$id]);
}

header("Location: sozlesmeler.php");
exit;