<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

function sayiCevir($value){

    $value = trim((string)$value);
    $value = str_replace(['₺', 'TL', ' ', "\xc2\xa0"], '', $value);

    if(strpos($value, ',') !== false){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

/*
CARİLER
*/
$carilerQuery = $db->query("
    SELECT *
    FROM cariler
    ORDER BY firma_adi ASC
");

$cariler = $carilerQuery->fetchAll(PDO::FETCH_ASSOC);

/*
SÖZLEŞMELER
*/
$sozlesmelerQuery = $db->query("
    SELECT
        sozlesmeler.*,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler
        ON cariler.id = sozlesmeler.cari_id
    WHERE sozlesmeler.durum = 1
    ORDER BY cariler.firma_adi ASC, sozlesmeler.sozlesme_no ASC
");

$sozlesmeler = $sozlesmelerQuery->fetchAll(PDO::FETCH_ASSOC);

/*
NOKTALAR TABLO KONTROLÜ
Bazı sistemlerde alan adı tip, bazılarında nokta_tipi olabilir.
Bu kod ikisine de uyumlu çalışır.
*/
$columnsQuery = $db->query("SHOW COLUMNS FROM noktalar");
$columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);

$tipKolonu = in_array('tip', $columns) ? 'tip' : 'nokta_tipi';
$kodKolonu = in_array('nokta_kodu', $columns) ? 'nokta_kodu' : 'id';

/*
ÇIKIŞ NOKTALARI
*/
$cikisQuery = $db->query("
    SELECT *
    FROM noktalar
    WHERE 
    (
        $tipKolonu IN ('cikis','CIKIS','ikisi','IKISI')
        OR $tipKolonu IS NULL
        OR $tipKolonu = ''
    )
    ORDER BY nokta_adi ASC
");

$cikisNoktalari = $cikisQuery->fetchAll(PDO::FETCH_ASSOC);

/*
VARIŞ NOKTALARI
*/
$varisQuery = $db->query("
    SELECT *
    FROM noktalar
    WHERE 
    (
        $tipKolonu IN ('varis','VARIS','ikisi','IKISI')
        OR $tipKolonu IS NULL
        OR $tipKolonu = ''
    )
    ORDER BY nokta_adi ASC
");

$varisNoktalari = $varisQuery->fetchAll(PDO::FETCH_ASSOC);

/*
KAYIT
*/
if($_POST){

    $cari_id = $_POST['cari_id'] ?? '';
    $sozlesme_id = $_POST['sozlesme_id'] ?? '';

    $cikis = trim($_POST['cikis_noktasi'] ?? '');
    $varis = trim($_POST['varis_noktasi'] ?? '');

    $arac = trim($_POST['arac_tipi'] ?? '');

    $tarifeTipi = $_POST['tarife_tipi'] ?? 'NORMAL';
    $motorinRevize = isset($_POST['motorin_revize']) ? 1 : 0;

    $birim = sayiCevir($_POST['birim_fiyat'] ?? 0);
    $motorin = sayiCevir($_POST['motorin_baz_fiyati'] ?? 0);

    $baslangic = $_POST['baslangic_tarihi'] ?? null;
    $bitis = $_POST['bitis_tarihi'] ?? null;

    if($baslangic == ''){
        $baslangic = null;
    }

    if($bitis == ''){
        $bitis = null;
    }

    $aciklama = trim($_POST['aciklama'] ?? '');

    /*
    FİRMA VE SÖZLEŞME BİLGİSİNİ ÇEK
    */
    $sozlesmeQuery = $db->prepare("
        SELECT
            sozlesmeler.*,
            cariler.firma_adi
        FROM sozlesmeler
        LEFT JOIN cariler
            ON cariler.id = sozlesmeler.cari_id
        WHERE sozlesmeler.id = ?
        LIMIT 1
    ");

    $sozlesmeQuery->execute([
        $sozlesme_id
    ]);

    $sozlesmeData = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

    if(!$sozlesmeData){

        $message = 'Seçilen sözleşme bulunamadı.';

    } else {

        $firma = $sozlesmeData['firma_adi'];
        $sozlesme = $sozlesmeData['sozlesme_no'];

        /*
        ESKİ AKTİF TARİFELERİ KAPAT
        Aynı firma, sözleşme, çıkış, varış ve tarife tipi için açık kayıt varsa kapanır.
        */
        $oncekiGun = null;

        if($baslangic){
            $oncekiGun = date('Y-m-d', strtotime($baslangic . ' -1 day'));
        }

        if($oncekiGun){

            $pasif = $db->prepare("
                UPDATE tarifeler
                SET 
                    bitis_tarihi = ?,
                    aktif = 0
                WHERE firma_adi = ?
                AND sozlesme_no = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
                AND (
                    bitis_tarihi IS NULL
                    OR bitis_tarihi = '0000-00-00'
                )
            ");

            $pasif->execute([
                $oncekiGun,
                $firma,
                $sozlesme,
                $cikis,
                $varis,
                $tarifeTipi
            ]);

        } else {

            $pasif = $db->prepare("
                UPDATE tarifeler
                SET aktif = 0
                WHERE firma_adi = ?
                AND sozlesme_no = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
            ");

            $pasif->execute([
                $firma,
                $sozlesme,
                $cikis,
                $varis,
                $tarifeTipi
            ]);
        }

        /*
        REVİZYON BUL
        */
        $revizyonQuery = $db->prepare("
            SELECT MAX(revizyon_no) AS max_rev
            FROM tarifeler
            WHERE firma_adi = ?
            AND sozlesme_no = ?
            AND cikis_noktasi = ?
            AND varis_noktasi = ?
            AND tarife_tipi = ?
        ");

        $revizyonQuery->execute([
            $firma,
            $sozlesme,
            $cikis,
            $varis,
            $tarifeTipi
        ]);

        $revizyonData = $revizyonQuery->fetch(PDO::FETCH_ASSOC);

        $revizyonNo = ((int)($revizyonData['max_rev'] ?? 0)) + 1;

        /*
        YENİ TARİFE EKLE
        */
        $insert = $db->prepare("
            INSERT INTO tarifeler
            (
                firma_adi,
                sozlesme_no,
                cikis_noktasi,
                varis_noktasi,
                arac_tipi,
                tarife_tipi,
                birim_fiyat,
                motorin_baz_fiyati,
                motorin_revize,
                baslangic_tarihi,
                bitis_tarihi,
                revizyon_no,
                aciklama,
                aktif
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");

        $insert->execute([
            $firma,
            $sozlesme,
            $cikis,
            $varis,
            $arac,
            $tarifeTipi,
            $birim,
            $motorin,
            $motorinRevize,
            $baslangic,
            $bitis,
            $revizyonNo,
            $aciklama
        ]);

        $message = 'Yeni tarife başarıyla oluşturuldu.';
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Yeni Tarife</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.box{
    background:white;
    padding:25px;
    border-radius:12px;
    max-width:1050px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
}

.form-group{
    display:flex;
    flex-direction:column;
}

label{
    margin-bottom:5px;
    font-size:13px;
    font-weight:bold;
}

input,
select,
textarea{
    padding:12px;
    border:1px solid #ddd;
    border-radius:8px;
    box-sizing:border-box;
}

textarea{
    min-height:80px;
}

button{
    background:#16a34a;
    color:white;
    border:none;
    padding:12px 18px;
    border-radius:8px;
    cursor:pointer;
    margin-top:15px;
    font-weight:bold;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
    font-weight:bold;
}

.info{
    background:#eff6ff;
    color:#1d4ed8;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
    font-weight:bold;
}

.check-row{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px;
    border:1px solid #ddd;
    border-radius:8px;
    background:#f9fafb;
}

.check-row input{
    width:auto;
}

@media(max-width:900px){
    .grid{
        grid-template-columns:1fr;
    }
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Yeni Tarife Ekle</h2>

        <p>
            Normal, palet ve damacana nakliye tarifelerini ayrı ayrı oluşturun
        </p>

    </div>

    <div class="box">

        <div class="info">
            Palet ve damacana için ayrı tarife tipi seçebilirsiniz. Motorin revizyonu işaretli ise %7 eşik aşımında bu tarife de otomatik güncellenir.
        </div>

        <?php if($message): ?>

        <div class="alert">
            <?php echo $message; ?>
        </div>

        <?php endif; ?>

        <form method="POST">

            <div class="grid">

                <div class="form-group">

                    <label>Firma</label>

                    <select name="cari_id" required>

                        <option value="">
                            Firma Seçiniz
                        </option>

                        <?php foreach($cariler as $cari): ?>

                            <option value="<?php echo $cari['id']; ?>">
                                <?php echo htmlspecialchars($cari['firma_adi']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Sözleşme</label>

                    <select name="sozlesme_id" required>

                        <option value="">
                            Sözleşme Seçiniz
                        </option>

                        <?php foreach($sozlesmeler as $sozlesme): ?>

                            <option value="<?php echo $sozlesme['id']; ?>">
                                <?php echo htmlspecialchars($sozlesme['firma_adi']); ?>
                                -
                                <?php echo htmlspecialchars($sozlesme['sozlesme_no']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Tarife Tipi</label>

                    <select name="tarife_tipi" required>

                        <option value="NORMAL">
                            Normal Nakliye
                        </option>

                        <option value="PALET">
                            Palet Nakliyesi
                        </option>

                        <option value="DAMACANA">
                            Damacana Nakliyesi
                        </option>

                    </select>

                </div>

                <div class="form-group">

                    <label>Araç Tipi</label>

                    <input
                        type="text"
                        name="arac_tipi"
                        placeholder="Kamyon / Tır / Panelvan"
                    >

                </div>

                <div class="form-group">

                    <label>Çıkış Noktası</label>

                    <select name="cikis_noktasi" required>

                        <option value="">
                            Çıkış Noktası Seçiniz
                        </option>

                        <?php foreach($cikisNoktalari as $nokta): ?>

                            <option value="<?php echo htmlspecialchars($nokta['nokta_adi']); ?>">
                                <?php echo htmlspecialchars($nokta[$kodKolonu] ?? ''); ?>
                                -
                                <?php echo htmlspecialchars($nokta['nokta_adi']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Varış Noktası</label>

                    <select name="varis_noktasi" required>

                        <option value="">
                            Varış Noktası Seçiniz
                        </option>

                        <?php foreach($varisNoktalari as $nokta): ?>

                            <option value="<?php echo htmlspecialchars($nokta['nokta_adi']); ?>">
                                <?php echo htmlspecialchars($nokta[$kodKolonu] ?? ''); ?>
                                -
                                <?php echo htmlspecialchars($nokta['nokta_adi']); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Birim Fiyat</label>

                    <input
                        type="text"
                        name="birim_fiyat"
                        placeholder="7.920,00"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Motorin Baz Fiyatı</label>

                    <input
                        type="text"
                        name="motorin_baz_fiyati"
                        placeholder="54,766"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Başlangıç Tarihi</label>

                    <input
                        type="date"
                        name="baslangic_tarihi"
                    >

                </div>

                <div class="form-group">

                    <label>Bitiş Tarihi</label>

                    <input
                        type="date"
                        name="bitis_tarihi"
                    >

                </div>

                <div class="form-group">

                    <label>Motorin Revizyonu</label>

                    <div class="check-row">

                        <input
                            type="checkbox"
                            name="motorin_revize"
                            value="1"
                            checked
                        >

                        <span>
                            Bu tarife motorin fiyatına göre otomatik revize edilsin
                        </span>

                    </div>

                </div>

            </div>

            <div class="form-group" style="margin-top:15px;">

                <label>Açıklama</label>

                <textarea
                    name="aciklama"
                    placeholder="Örn: Palet taşıması, damacana sevkiyatı veya özel dönem tarifesi"
                ></textarea>

            </div>

            <button type="submit">
                Tarife Kaydet
            </button>

        </form>

    </div>

</div>

</body>
</html>