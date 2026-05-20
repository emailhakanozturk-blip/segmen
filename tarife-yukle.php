<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

function temizFiyat($value){

    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return floatval($value);
}

if(isset($_FILES['excel'])){

    $tmp =
        $_FILES['excel']['tmp_name'];

    if(($file = fopen($tmp, 'r')) !== FALSE){

        $i = 0;

        while(($data = fgetcsv($file, 4000, ";")) !== FALSE){

            /* BAŞLIK SATIRI */

            if($i == 0){
                $i++;
                continue;
            }

            $firma =
                trim($data[0] ?? '');

            $sozlesme =
                trim($data[1] ?? '');

            $cikis =
                trim($data[2] ?? '');

            $varis =
                trim($data[3] ?? '');

            $arac =
                trim($data[4] ?? '');

            $birim =
                temizFiyat($data[5] ?? 0);

            $motorin =
                temizFiyat($data[6] ?? 0);

            $baslangic =
                trim($data[7] ?? '');

            $bitis =
                trim($data[8] ?? '');

            $aciklama =
                trim($data[9] ?? '');

            if(
                empty($firma) ||
                empty($cikis) ||
                empty($varis)
            ){
                continue;
            }

            /* AKTİF PASİF */

            $pasif = $db->prepare("
                UPDATE tarifeler
                SET aktif = 0
                WHERE firma_adi = ?
                AND sozlesme_no = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
            ");

            $pasif->execute([
                $firma,
                $sozlesme,
                $cikis,
                $varis
            ]);

            /* REVİZYON */

            $revizyonQuery = $db->prepare("
                SELECT MAX(revizyon_no) AS max_rev
                FROM tarifeler
                WHERE firma_adi = ?
                AND sozlesme_no = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
            ");

            $revizyonQuery->execute([
                $firma,
                $sozlesme,
                $cikis,
                $varis
            ]);

            $revizyonData =
                $revizyonQuery->fetch(PDO::FETCH_ASSOC);

            $revizyonNo =
                ($revizyonData['max_rev'] ?? 0) + 1;

            /* EKLE */

            $insert = $db->prepare("
                INSERT INTO tarifeler
                (
                    firma_adi,
                    sozlesme_no,
                    cikis_noktasi,
                    varis_noktasi,
                    arac_tipi,
                    birim_fiyat,
                    motorin_baz_fiyati,
                    baslangic_tarihi,
                    bitis_tarihi,
                    revizyon_no,
                    aciklama,
                    aktif
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");

            $insert->execute([

                $firma,
                $sozlesme,
                $cikis,
                $varis,
                $arac,
                $birim,
                $motorin,
                $baslangic,
                $bitis,
                $revizyonNo,
                $aciklama

            ]);

            $i++;
        }

        fclose($file);

        $message =
            'Tarifeler başarıyla aktarıldı.';
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Excel Tarife Aktarım</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.upload-box{
    background:white;
    padding:30px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:14px;
    border-radius:8px;
    margin-bottom:20px;
}

input[type=file]{
    width:100%;
    padding:14px;
    border:1px solid #ddd;
    border-radius:8px;
}

button{
    background:#16a34a;
    color:white;
    border:none;
    padding:14px 20px;
    border-radius:8px;
    cursor:pointer;
    margin-top:15px;
    font-weight:bold;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Excel Tarife Aktarım</h2>

        <p>
            Toplu tarife yükleme ekranı
        </p>

    </div>

    <div class="upload-box">

        <?php if($message): ?>

            <div class="alert">

                <?php echo $message; ?>

            </div>

        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <input
                type="file"
                name="excel"
                required
            >

            <button type="submit">

                Tarifeleri Yükle

            </button>

        </form>

    </div>

</div>

</body>
</html>