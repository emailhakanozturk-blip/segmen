<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? 0;

if($id){

    /* ÖNCE SATIRLARI SİL */

    $deleteSatir = $db->prepare("
        DELETE FROM hakedis_satirlari
        WHERE hakedis_id = ?
    ");

    $deleteSatir->execute([$id]);

    /* SONRA HAKEDİŞİ SİL */

    $deleteHakedis = $db->prepare("
        DELETE FROM hakedisler
        WHERE id = ?
    ");

    $deleteHakedis->execute([$id]);
}

header("Location: hakedisler.php");
exit;