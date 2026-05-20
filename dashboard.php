<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$toplamQuery = $db->query("
    SELECT SUM(satir_toplam) AS toplam
    FROM hakedis_satirlari
");

$toplamData = $toplamQuery->fetch(PDO::FETCH_ASSOC);
$toplamHakedis = $toplamData['toplam'] ?? 0;

$sevkiyatQuery = $db->query("
    SELECT COUNT(*) AS adet
    FROM hakedis_satirlari
");

$sevkiyatData = $sevkiyatQuery->fetch(PDO::FETCH_ASSOC);
$toplamSevkiyat = $sevkiyatData['adet'] ?? 0;

$ortalama = 0;

if($toplamSevkiyat > 0){
    $ortalama = $toplamHakedis / $toplamSevkiyat;
}

$cikisQuery = $db->query("
    SELECT cikis_noktasi, COUNT(*) AS adet
    FROM hakedis_satirlari
    GROUP BY cikis_noktasi
    ORDER BY adet DESC
    LIMIT 1
");

$cikisData = $cikisQuery->fetch(PDO::FETCH_ASSOC);
$enCokCikis = $cikisData['cikis_noktasi'] ?? '-';

$guzelQuery = $db->query("
    SELECT SUM(satir_toplam) AS toplam
    FROM hakedis_satirlari
    WHERE cikis_noktasi = 'GÜZELCEKALE'
");

$guzelData = $guzelQuery->fetch(PDO::FETCH_ASSOC);
$guzelToplam = $guzelData['toplam'] ?? 0;

$buyukQuery = $db->query("
    SELECT SUM(satir_toplam) AS toplam
    FROM hakedis_satirlari
    WHERE cikis_noktasi = 'BÜYÜKYAĞCI'
");

$buyukData = $buyukQuery->fetch(PDO::FETCH_ASSOC);
$buyukToplam = $buyukData['toplam'] ?? 0;

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Dashboard</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.cards{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:20px;
}

.card{
    background:white;
    padding:25px;
    border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.card h3{
    font-size:15px;
    color:#666;
    margin-bottom:10px;
}

.card p{
    font-size:28px;
    font-weight:bold;
    color:#1d3557;
}

.green{
    color:#16a34a !important;
}

.blue{
    color:#2563eb !important;
}

.orange{
    color:#ea580c !important;
}

.purple{
    color:#9333ea !important;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>
            Hoş geldiniz,
            <?php echo $_SESSION['user_name']; ?>
        </h2>

        <p>
            Kurumsal Yönetim Dashboardu
        </p>

    </div>

    <div class="cards">

        <div class="card">
            <h3>Toplam Hakediş</h3>
            <p class="green">
                ₺<?php echo number_format($toplamHakedis,2,',','.'); ?>
            </p>
        </div>

        <div class="card">
            <h3>Toplam Sevkiyat</h3>
            <p class="blue">
                <?php echo number_format($toplamSevkiyat); ?>
            </p>
        </div>

        <div class="card">
            <h3>Ortalama Taşıma</h3>
            <p class="orange">
                ₺<?php echo number_format($ortalama,2,',','.'); ?>
            </p>
        </div>

        <div class="card">
            <h3>En Yoğun Çıkış</h3>
            <p class="purple">
                <?php echo $enCokCikis; ?>
            </p>
        </div>

        <div class="card">
            <h3>Büyükyağcı Toplam</h3>
            <p>
                ₺<?php echo number_format($buyukToplam,2,',','.'); ?>
            </p>
        </div>

        <div class="card">
            <h3>Güzelcekale Toplam</h3>
            <p>
                ₺<?php echo number_format($guzelToplam,2,',','.'); ?>
            </p>
        </div>

    </div>

</div>

</body>
</html>