<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$cariler = $db->query("
    SELECT * FROM cariler
    ORDER BY firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$message = '';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $cari_id = $_POST['cari_id'];

    $sozlesme_no = trim($_POST['sozlesme_no']);

    $baslangic_tarihi = $_POST['baslangic_tarihi'];

    $bitis_tarihi = $_POST['bitis_tarihi'];

    $sozlesme_tutari = $_POST['sozlesme_tutari'];

    $durum = $_POST['durum'];

    $query = $db->prepare("
        INSERT INTO sozlesmeler
        (
            cari_id,
            sozlesme_no,
            baslangic_tarihi,
            bitis_tarihi,
            sozlesme_tutari,
            durum
        )
        VALUES
        (?, ?, ?, ?, ?, ?)
    ");

    $query->execute([

        $cari_id,
        $sozlesme_no,
        $baslangic_tarihi,
        $bitis_tarihi,
        $sozlesme_tutari,
        $durum

    ]);

    $message = 'Sözleşme başarıyla kaydedildi';

}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Yeni Sözleşme</title>

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
        K-SYS
    </div>

    <div class="menu">

        <a href="dashboard.php">Dashboard</a>

        <a href="cariler.php">Cariler</a>

        <a href="sozlesmeler.php">Sözleşmeler</a>

    </div>

</div>

<div class="main">

    <div class="topbar">

        <h2>Yeni Sözleşme</h2>

        <p>
            Cari bazlı sözleşme kayıt ekranı
        </p>

    </div>

    <div class="form-area">

        <?php if($message): ?>

            <div class="alert">
                <?php echo $message; ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>Cari Seç</label>

                <select name="cari_id" required>

                    <option value="">
                        Cari Seçiniz
                    </option>

                    <?php foreach($cariler as $cari): ?>

                        <option value="<?php echo $cari['id']; ?>">

                            <?php echo $cari['firma_adi']; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-group">

                <label>Sözleşme No</label>

                <input 
                    type="text"
                    name="sozlesme_no"
                    value="SZL-<?php echo rand(1000,9999); ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Başlangıç Tarihi</label>

                <input 
                    type="date"
                    name="baslangic_tarihi"
                    required
                >

            </div>

            <div class="form-group">

                <label>Bitiş Tarihi</label>

                <input 
                    type="date"
                    name="bitis_tarihi"
                    required
                >

            </div>

            <div class="form-group">

                <label>Sözleşme Tutarı</label>

                <input 
                    type="number"
                    step="0.01"
                    name="sozlesme_tutari"
                    required
                >

            </div>

            <div class="form-group">

                <label>Durum</label>

                <select name="durum">

                    <option value="1">
                        Aktif
                    </option>

                    <option value="0">
                        Pasif
                    </option>

                </select>

            </div>

            <button type="submit" class="btn">
                Sözleşmeyi Kaydet
            </button>

        </form>

    </div>

</div>

</body>
</html>