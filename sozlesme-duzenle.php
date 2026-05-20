<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? 0;

$query = $db->prepare("
    SELECT *
    FROM sozlesmeler
    WHERE id = ?
");

$query->execute([$id]);

$sozlesme = $query->fetch(PDO::FETCH_ASSOC);

if(!$sozlesme){
    die('Sözleşme bulunamadı');
}

/* CARİLER */

$cariQuery = $db->query("
    SELECT *
    FROM cariler
    ORDER BY firma_adi ASC
");

$cariler = $cariQuery->fetchAll(PDO::FETCH_ASSOC);

$message = '';

if($_POST){

    $cari_id = $_POST['cari_id'];

    $sozlesme_no = $_POST['sozlesme_no'];

    $baslangic =
        $_POST['baslangic_tarihi'];

    $bitis =
        $_POST['bitis_tarihi'];

    $tutar =
        str_replace(
            ['₺','.'],
            '',
            $_POST['sozlesme_tutari']
        );

    $tutar =
        str_replace(',', '.', $tutar);

    $durum = $_POST['durum'];

    $update = $db->prepare("
        UPDATE sozlesmeler
        SET
            cari_id = ?,
            sozlesme_no = ?,
            baslangic_tarihi = ?,
            bitis_tarihi = ?,
            sozlesme_tutari = ?,
            durum = ?
        WHERE id = ?
    ");

    $update->execute([

        $cari_id,

        $sozlesme_no,

        $baslangic,

        $bitis,

        $tutar,

        $durum,

        $id

    ]);

    header("Location: sozlesmeler.php");
    exit;
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Sözleşme Düzenle</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.form-area{
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
    max-width:900px;
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
    font-size:14px;
}

.btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:14px 20px;
    border-radius:8px;
    cursor:pointer;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Sözleşme Düzenle</h2>

        <p>
            Mevcut sözleşme bilgilerini güncelleyin
        </p>

    </div>

    <div class="form-area">

        <form method="POST">

            <div class="form-group">

                <label>Firma</label>

                <select name="cari_id" required>

                    <?php foreach($cariler as $cari): ?>

                        <option
                            value="<?php echo $cari['id']; ?>"
                            <?php if($cari['id']==$sozlesme['cari_id']) echo 'selected'; ?>
                        >

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
                    value="<?php echo $sozlesme['sozlesme_no']; ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Başlangıç Tarihi</label>

                <input
                    type="date"
                    name="baslangic_tarihi"
                    value="<?php echo $sozlesme['baslangic_tarihi']; ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Bitiş Tarihi</label>

                <input
                    type="date"
                    name="bitis_tarihi"
                    value="<?php echo $sozlesme['bitis_tarihi']; ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Sözleşme Tutarı</label>

                <input
                    type="text"
                    name="sozlesme_tutari"
                    value="<?php echo number_format($sozlesme['sozlesme_tutari'],2,',','.'); ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>Durum</label>

                <select name="durum">

                    <option
                        value="1"
                        <?php if($sozlesme['durum']==1) echo 'selected'; ?>
                    >
                        Aktif
                    </option>

                    <option
                        value="0"
                        <?php if($sozlesme['durum']==0) echo 'selected'; ?>
                    >
                        Pasif
                    </option>

                </select>

            </div>

            <button type="submit" class="btn">

                Sözleşmeyi Güncelle

            </button>

        </form>

    </div>

</div>

</body>
</html>