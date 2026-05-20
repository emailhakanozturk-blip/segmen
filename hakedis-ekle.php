<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$sozlesmeler = $db->query("
    SELECT
        sozlesmeler.*,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler
        ON cariler.id = sozlesmeler.cari_id
    WHERE sozlesmeler.durum = 1
    ORDER BY cariler.firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$message = '';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $sozlesme_id =
        $_POST['sozlesme_id'];

    $donem =
        trim($_POST['donem']);

    $baslangic_tarihi =
        $_POST['baslangic_tarihi'];

    $bitis_tarihi =
        $_POST['bitis_tarihi'];

    $sozlesmeQuery = $db->prepare("
        SELECT * FROM sozlesmeler WHERE id = ?
    ");

    $sozlesmeQuery->execute([
        $sozlesme_id
    ]);

    $sozlesme =
        $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

    $hakedis_no =
        'HKD-' .
        date('Y') .
        '-' .
        rand(1000,9999);

    $query = $db->prepare("
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
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $query->execute([

        $hakedis_no,

        $sozlesme['cari_id'],

        $sozlesme_id,

        $donem,

        $baslangic_tarihi,

        $bitis_tarihi,

        0,

        0,

        0,

        0,

        'bekliyor'

    ]);

    $hakedis_id =
        $db->lastInsertId();

    header(
        "Location: excel-eslestir.php?hakedis_id="
        . $hakedis_id
    );

    exit;
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Yeni Hakediş</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.form-area{

    background:white;

    padding:30px;

    border-radius:15px;

    box-shadow:0 5px 15px rgba(0,0,0,.05);

    max-width:1100px;

}

.form-grid{

    display:grid;

    grid-template-columns:1fr 1fr;

    gap:20px;

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

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Yeni Hakediş Oluştur</h2>

        <p>
            Firma, sözleşme ve dönem seçerek hakediş dosyası oluşturun
        </p>

    </div>

    <div class="form-area">

        <?php if($message): ?>

            <div class="alert">
                <?php echo $message; ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group">

                    <label>Sözleşme Seç</label>

                    <select
                        name="sozlesme_id"
                        required
                    >

                        <option value="">
                            Sözleşme Seçiniz
                        </option>

                        <?php foreach($sozlesmeler as $sozlesme): ?>

                            <option
                                value="<?php echo $sozlesme['id']; ?>"
                            >

                                <?php echo $sozlesme['firma_adi']; ?>

                                -

                                <?php echo $sozlesme['sozlesme_no']; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Dönem</label>

                    <input
                        type="month"
                        name="donem"
                        id="donem"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Başlangıç Tarihi</label>

                    <input
                        type="date"
                        name="baslangic_tarihi"
                        id="baslangic_tarihi"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Bitiş Tarihi</label>

                    <input
                        type="date"
                        name="bitis_tarihi"
                        id="bitis_tarihi"
                        required
                    >

                </div>

            </div>

            <button
                type="submit"
                class="btn"
            >
                Hakediş Oluştur ve Excel Aktarıma Geç
            </button>

        </form>

    </div>

</div>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function(){

        const donemInput =
            document.querySelector(
                'input[name="donem"]'
            );

        const baslangicInput =
            document.querySelector(
                'input[name="baslangic_tarihi"]'
            );

        const bitisInput =
            document.querySelector(
                'input[name="bitis_tarihi"]'
            );

        function tarihleriDoldur(){

            const value =
                donemInput.value;

            if(!value){
                return;
            }

            const parca =
                value.split('-');

            const yil =
                parseInt(parca[0]);

            const ay =
                parseInt(parca[1]);

            // ilk gün

            const ilkGun =
                yil + '-'
                + String(ay).padStart(2,'0')
                + '-01';

            // son gün

            const sonGunDate =
                new Date(yil, ay, 0);

            const sonGun =
                sonGunDate.getFullYear()
                + '-'
                + String(
                    sonGunDate.getMonth() + 1
                  ).padStart(2,'0')
                + '-'
                + String(
                    sonGunDate.getDate()
                  ).padStart(2,'0');

            baslangicInput.value =
                ilkGun;

            bitisInput.value =
                sonGun;
        }

        donemInput.addEventListener(
            'change',
            tarihleriDoldur
        );

        donemInput.addEventListener(
            'input',
            tarihleriDoldur
        );

    }
);

</script>

</body>
</html>