<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $firma_kodu = trim($_POST['firma_kodu']);
    $firma_adi = trim($_POST['firma_adi']);
    $yetkili = trim($_POST['yetkili']);
    $telefon = trim($_POST['telefon']);
    $tip = trim($_POST['tip']);

    $query = $db->prepare("
        INSERT INTO cariler
        (firma_kodu, firma_adi, yetkili, telefon, tip)
        VALUES
        (?, ?, ?, ?, ?)
    ");

    $query->execute([
        $firma_kodu,
        $firma_adi,
        $yetkili,
        $telefon,
        $tip
    ]);

    $message = 'Cari başarıyla kaydedildi';

}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">
<title>Yeni Cari</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.form-area{

    background:white;

    padding:30px;

    border-radius:15px;

    box-shadow:0 5px 15px rgba(0,0,0,.05);

}

.form-group{

    margin-bottom:20px;

}

.form-group label{

    display:block;

    margin-bottom:8px;

    font-weight:bold;

}

.form-group input,
.form-group select{

    width:100%;

    padding:14px;

    border:1px solid #ddd;

    border-radius:8px;

    font-size:16px;

}

.btn{

    background:#16a34a;

    color:white;

    border:none;

    padding:14px 22px;

    border-radius:8px;

    cursor:pointer;

    font-size:16px;

}

.alert{

    background:#dcfce7;

    color:#166534;

    padding:15px;

    border-radius:10px;

    margin-bottom:20px;

}

</style>

</head>
<body>

<div class="sidebar">

    <div class="logo">
        <img src="assets/img/segmen-su-logo.png" alt="Seğmen Su">
        <span>Hakediş Hesaplama Modülü</span>
    </div>

    <div class="menu">

        <a href="dashboard.php">Dashboard</a>
        <a href="cariler.php">Cariler</a>

    </div>

</div>

<div class="main">

    <div class="topbar">

        <h2>Yeni Cari Ekle</h2>
        <p>Firma ve cari kayıt ekranı</p>

    </div>

    <div class="form-area">

        <?php if($message): ?>

            <div class="alert">
                <?php echo $message; ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>Firma Kodu</label>

                <input 
                    type="text" 
                    name="firma_kodu"
                    value="CR-<?php echo rand(1000,9999); ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Firma Adı</label>

                <input type="text" name="firma_adi" required>

            </div>

            <div class="form-group">

                <label>Yetkili</label>

                <input type="text" name="yetkili">

            </div>

            <div class="form-group">

                <label>Telefon</label>

                <input type="text" name="telefon">

            </div>

            <div class="form-group">

                <label>Cari Tipi</label>

                <select name="tip">

                    <option value="alici">Alıcı</option>
                    <option value="satici">Satıcı</option>
                    <option value="nakliyeci">Nakliyeci</option>
                    <option value="sponsor">Sponsor</option>

                </select>

            </div>

            <button type="submit" class="btn">
                Cariyi Kaydet
            </button>

        </form>

    </div>

</div>

</body>
</html>
