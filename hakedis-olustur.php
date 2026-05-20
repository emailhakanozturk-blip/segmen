<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

$sozlesmeler = $db->query("
    SELECT 
        sozlesmeler.id,
        sozlesmeler.sozlesme_no,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    WHERE sozlesmeler.durum = 1
    ORDER BY cariler.firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

if($_POST){

    $sozlesme_id = $_POST['sozlesme_id'];
    $donem = $_POST['donem'];
    $baslangic_tarihi = $_POST['baslangic_tarihi'];
    $bitis_tarihi = $_POST['bitis_tarihi'];

    $sozlesmeQuery = $db->prepare("
        SELECT *
        FROM sozlesmeler
        WHERE id = ?
    ");

    $sozlesmeQuery->execute([$sozlesme_id]);
    $sozlesme = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

    $cari_id = $sozlesme['cari_id'];

    $hakedis_no = 'HKD-' . date('Y') . '-' . rand(1000,9999);

    $insert = $db->prepare("
        INSERT INTO hakedisler
        (
            hakedis_no,
            cari_id,
            sozlesme_id,
            donem,
            baslangic_tarihi,
            bitis_tarihi,
            toplam_tutar,
            kdv_tutar,
            tevkifat_tutar,
            net_tutar,
            durum
        )
        VALUES
        (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 'bekliyor')
    ");

    $insert->execute([
        $hakedis_no,
        $cari_id,
        $sozlesme_id,
        $donem,
        $baslangic_tarihi,
        $bitis_tarihi
    ]);

    $hakedis_id = $db->lastInsertId();

    header("Location: excel-eslestir.php?hakedis_id=" . $hakedis_id);
    exit;
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Hakediş Oluştur</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.form-area{
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
    max-width:800px;
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
    font-size:15px;
}

.btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:14px 22px;
    border-radius:8px;
    cursor:pointer;
    font-size:15px;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Yeni Hakediş Oluştur</h2>

        <p>
            Firma, sözleşme ve dönem seçerek hakediş dosyası oluşturun
        </p>

    </div>

    <div class="form-area">

        <form method="POST">

            <div class="form-group">

                <label>Sözleşme Seç</label>

                <select name="sozlesme_id" required>

                    <option value="">Sözleşme Seçiniz</option>

                    <?php foreach($sozlesmeler as $sozlesme): ?>

                        <option value="<?php echo $sozlesme['id']; ?>">

                            <?php echo $sozlesme['firma_adi']; ?> - <?php echo $sozlesme['sozlesme_no']; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-group">

                <label>Dönem</label>

                <input 
                    type="text" 
                    name="donem" 
                    placeholder="2026-02"
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

            <button type="submit" class="btn">

                Hakediş Oluştur ve Excel Aktarıma Geç

            </button>

        </form>

    </div>

</div>

</body>
</html>