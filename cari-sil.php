<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? 0;

if($id){

    $query = $db->prepare("DELETE FROM cariler WHERE id = ?");
    $query->execute([$id]);

}

header("Location: cariler.php");
exit;