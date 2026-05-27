<?php
header('Location: nokta-yonetimi.php?revizyon_yili=' . date('Y') . '&revize=1');
exit;

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';
$error = false;

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sayiCevir($value): float
{
    $value = trim((string)$value);
    $value = str_replace(['₺', 'TL', ' ', "\xc2\xa0"], '', $value);

    if(strpos($value, ',') !== false){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function tarihCevir($value): ?string
{
    $value = trim((string)$value);

    if($value === ''){
        return null;
    }

    $value = str_replace('/', '.', $value);
    $parca = explode('.', $value);

    if(count($parca) === 3){
        return $parca[2] . '-' . str_pad($parca[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parca[0], 2, '0', STR_PAD_LEFT);
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function temizMetin($value): string
{
    return trim(preg_replace('/\s+/', ' ', (string)$value));
}

if(isset($_FILES['excel'])){
    try {
        $tmp = $_FILES['excel']['tmp_name'];

        if(($file = fopen($tmp, 'r')) === false){
            throw new Exception('Dosya açılamadı.');
        }

        $eklenen = 0;
        $atlan = 0;
        $satirNo = 0;

        while(($data = fgetcsv($file, 6000, ';')) !== false){
            $satirNo++;

            if($satirNo === 1){
                continue;
            }

            $firma = temizMetin($data[0] ?? '');
            $sozlesmeNo = temizMetin($data[1] ?? '');
            $cikis = temizMetin($data[2] ?? '');
            $varis = temizMetin($data[3] ?? '');
            $arac = temizMetin($data[4] ?? '');
            $km = sayiCevir($data[5] ?? 0);
            $motorin = sayiCevir($data[6] ?? 0);
            $birimGirdi = sayiCevir($data[7] ?? 0);
            $birimKolonuVar = $birimGirdi > 0;
            $tarifeTipi = temizMetin($data[$birimKolonuVar ? 8 : 7] ?? 'NORMAL') ?: 'NORMAL';
            $baslangic = tarihCevir($data[$birimKolonuVar ? 9 : 8] ?? '');
            $bitis = tarihCevir($data[$birimKolonuVar ? 10 : 9] ?? '');
            $aciklama = temizMetin($data[$birimKolonuVar ? 11 : 10] ?? '');

            if($motorin > 0 && $km <= 0 && $birimGirdi > 0){
                $km = round($birimGirdi / $motorin, 2);
            }

            if($firma === '' || $sozlesmeNo === '' || $cikis === '' || $varis === '' || $km <= 0 || $motorin <= 0){
                $atlan++;
                continue;
            }

            $sozlesmeQuery = $db->prepare("
                SELECT
                    sozlesmeler.id AS sozlesme_id,
                    sozlesmeler.cari_id,
                    sozlesmeler.sozlesme_no,
                    cariler.firma_adi
                FROM sozlesmeler
                LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
                WHERE cariler.firma_adi = ?
                AND sozlesmeler.sozlesme_no = ?
                LIMIT 1
            ");
            $sozlesmeQuery->execute([$firma, $sozlesmeNo]);
            $sozlesme = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

            if(!$sozlesme){
                $atlan++;
                continue;
            }

            $oncekiGun = $baslangic ? date('Y-m-d', strtotime($baslangic . ' -1 day')) : null;

            $pasifSql = "
                UPDATE tarifeler
                SET aktif = 0" . ($oncekiGun ? ", bitis_tarihi = ?" : "") . "
                WHERE sozlesme_id = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
                AND (
                    bitis_tarihi IS NULL
                    OR bitis_tarihi = '0000-00-00'
                )
            ";

            $pasifParams = $oncekiGun
                ? [$oncekiGun, $sozlesme['sozlesme_id'], $cikis, $varis, $tarifeTipi]
                : [$sozlesme['sozlesme_id'], $cikis, $varis, $tarifeTipi];

            $pasif = $db->prepare($pasifSql);
            $pasif->execute($pasifParams);

            $revizyonQuery = $db->prepare("
                SELECT MAX(revizyon_no) AS max_rev
                FROM tarifeler
                WHERE sozlesme_id = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
            ");
            $revizyonQuery->execute([$sozlesme['sozlesme_id'], $cikis, $varis, $tarifeTipi]);
            $revizyonNo = ((int)($revizyonQuery->fetch(PDO::FETCH_ASSOC)['max_rev'] ?? 0)) + 1;

            $birim = $birimGirdi > 0 ? round($birimGirdi, 4) : round($km * $motorin, 4);

            $insert = $db->prepare("
                INSERT INTO tarifeler
                (
                    cari_id,
                    sozlesme_id,
                    firma_adi,
                    sozlesme_no,
                    cikis_noktasi,
                    varis_noktasi,
                    arac_tipi,
                    sevkiyat_km,
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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 1)
            ");

            $insert->execute([
                $sozlesme['cari_id'],
                $sozlesme['sozlesme_id'],
                $sozlesme['firma_adi'],
                $sozlesme['sozlesme_no'],
                $cikis,
                $varis,
                $arac,
                $km,
                $tarifeTipi,
                $birim,
                $motorin,
                $baslangic,
                $bitis,
                $revizyonNo,
                $aciklama
            ]);

            $eklenen++;
        }

        fclose($file);
        $message = $eklenen . ' tarife aktarıldı. ' . $atlan . ' satır atlandı.';
    } catch(Throwable $exception){
        $error = true;
        $message = 'Hata: ' . $exception->getMessage();
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
    padding:26px;
    border-radius:12px;
    border:1px solid #e7eaf0;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
    max-width:900px;
}
.alert{
    background:#dcfce7;
    color:#166534;
    padding:14px;
    border-radius:8px;
    margin-bottom:18px;
    font-weight:bold;
}
.alert.error{
    background:#fee2e2;
    color:#991b1b;
}
.info{
    background:#eff6ff;
    color:#1d4ed8;
    padding:14px;
    border-radius:8px;
    margin-bottom:18px;
    line-height:1.6;
}
code{
    background:#eef2ff;
    padding:2px 5px;
    border-radius:5px;
}
input[type=file]{
    width:100%;
    padding:14px;
    border:1px solid #d8dde6;
    border-radius:8px;
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
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Excel Tarife Aktarım</h2>
        <p>Firma ve sözleşme bazında km x baz motorin hesabıyla toplu tarife yükleyin.</p>
    </div>

    <div class="upload-box">
        <div class="info">
            CSV kolon sırası:
            <br>
            <code>Firma;Sözleşme No;Çıkış;Varış;Araç;Sevkiyat KM;Baz Motorin;Birim Fiyat;Tarife Tipi;Başlangıç;Bitiş;Açıklama</code>
            <br>
            KM boşsa ve birim fiyat doluysa sistem KM’yi <strong>Birim Fiyat / Baz Motorin</strong> hesabıyla çıkarır. Birim fiyat boşsa <strong>Sevkiyat KM x Baz Motorin</strong> hesabıyla oluşturur.
        </div>

        <?php if($message): ?>
            <div class="alert <?php echo $error ? 'error' : ''; ?>">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="excel" accept=".csv" required>
            <button type="submit">Tarifeleri Yükle</button>
        </form>
    </div>
</div>

</body>
</html>
