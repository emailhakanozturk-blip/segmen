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

function tarihGoster($tarih){

    if(
        empty($tarih) ||
        $tarih == '0000-00-00'
    ){
        return '-';
    }

    return date('d.m.Y', strtotime($tarih));
}

function tarihAraligi($baslangic, $bitis){

    return tarihGoster($baslangic)
    . ' / '
    . tarihGoster($bitis);
}

/*
MOTORİN GÜNCELLE
*/
if(isset($_POST['motorin_guncelle'])){

    $db->beginTransaction();

    try {

        $motorinQuery = $db->query("
            SELECT tarih, motorin_fiyati
            FROM motorin_fiyatlari
            ORDER BY tarih DESC
            LIMIT 1
        ");

        $sonMotorin = $motorinQuery->fetch(PDO::FETCH_ASSOC);

        if(!$sonMotorin){
            throw new Exception('Motorin fiyatı bulunamadı.');
        }

        $motorinTarih = $sonMotorin['tarih'];
        $gunlukMotorin = (float)$sonMotorin['motorin_fiyati'];

        $tarifeQuery = $db->query("
            SELECT *
            FROM tarifeler
            WHERE
            (
                bitis_tarihi IS NULL
                OR bitis_tarihi = '0000-00-00'
            )
            ORDER BY firma_adi, cikis_noktasi, varis_noktasi
        ");

        $tarifeler = $tarifeQuery->fetchAll(PDO::FETCH_ASSOC);

        $guncellenen = 0;
        $degismeyen = 0;

        foreach($tarifeler as $tarife){

            $tarifeId = (int)$tarife['id'];

            $bazTarife =
                (float)$tarife['birim_fiyat'];

            $motorinBaz =
                (float)$tarife['motorin_baz_fiyati'];

            if($motorinBaz <= 0){
                $degismeyen++;
                continue;
            }

            $farkTutar =
                $gunlukMotorin - $motorinBaz;

            $farkYuzde =
                ($farkTutar / $motorinBaz) * 100;

            if(abs($farkYuzde) < 7){
                $degismeyen++;
                continue;
            }

            $zamIndirim =
                (($bazTarife * 40) / 100)
                * ($farkYuzde / 100);

            $yeniFiyat =
                $bazTarife + $zamIndirim;

            $kontrol = $db->prepare("
                SELECT id
                FROM tarifeler
                WHERE firma_adi = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND baslangic_tarihi = ?
                LIMIT 1
            ");

            $kontrol->execute([
                $tarife['firma_adi'],
                $tarife['cikis_noktasi'],
                $tarife['varis_noktasi'],
                $motorinTarih
            ]);

            if($kontrol->fetch()){
                $degismeyen++;
                continue;
            }

            $oncekiGun =
                date('Y-m-d', strtotime($motorinTarih . ' -1 day'));

            /*
            ESKİ TARİFEYİ KAPAT
            */
            $kapat = $db->prepare("
                UPDATE tarifeler
                SET bitis_tarihi = ?
                WHERE id = ?
            ");

            $kapat->execute([
                $oncekiGun,
                $tarifeId
            ]);

            /*
            YENİ TARİFE
            */
            $yeniRevizyonNo =
                ((int)($tarife['revizyon_no'] ?? 0)) + 1;

            $ekle = $db->prepare("
                INSERT INTO tarifeler
                (
                    firma_adi,
                    cikis_noktasi,
                    varis_noktasi,
                    birim_fiyat,
                    motorin_baz_fiyati,
                    baslangic_tarihi,
                    bitis_tarihi,
                    revizyon_no
                )
                VALUES
                (?, ?, ?, ?, ?, ?, NULL, ?)
            ");

            $ekle->execute([
                $tarife['firma_adi'],
                $tarife['cikis_noktasi'],
                $tarife['varis_noktasi'],
                $yeniFiyat,
                $gunlukMotorin,
                $motorinTarih,
                $yeniRevizyonNo
            ]);

            $guncellenen++;
        }

        $db->commit();

        $message =
            $guncellenen
            . ' tarife güncellendi. '
            . $degismeyen
            . ' tarifede değişiklik yok.';

    } catch(Exception $e){

        $db->rollBack();

        $message = 'Hata: ' . $e->getMessage();
    }
}

/*
TARİFELER
*/
$query = $db->query("

SELECT
    t.*,
    m.motorin_fiyati
FROM tarifeler t

LEFT JOIN motorin_fiyatlari m
ON m.tarih = (
    SELECT MAX(tarih)
    FROM motorin_fiyatlari
)

ORDER BY
    t.firma_adi ASC,
    t.cikis_noktasi ASC,
    t.varis_noktasi ASC,
    t.baslangic_tarihi ASC,
    t.revizyon_no ASC,
    t.id ASC

");

$rows = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Tarife Yönetimi</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

body{
    background:#f3f4f6;
    font-family:Arial, Helvetica, sans-serif;
    margin:0;
    padding:0;
}

.main{
    padding:20px;
}

.topbar{
    margin-bottom:20px;
}

.topbar h2{
    margin:0;
    font-size:28px;
    color:#1d3557;
}

.topbar p{
    margin-top:6px;
    color:#6b7280;
    font-size:14px;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:14px 18px;
    border-radius:10px;
    margin-bottom:20px;
    font-size:16px;
    font-weight:bold;
}

.box{
    background:white;
    padding:10px;
    border-radius:12px;
    overflow-x:auto;
    overflow-y:hidden;
    box-shadow:0 4px 15px rgba(0,0,0,.05);
}

.top-actions{
    margin-top:15px;
    margin-bottom:20px;
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
}

.btn{
    background:#16a34a;
    color:white;
    padding:12px 20px;
    border-radius:10px;
    text-decoration:none;
    display:inline-block;
    font-size:16px;
    font-weight:bold;
    border:none;
    cursor:pointer;
    white-space:nowrap;
}

.btn-orange{
    background:#ea580c;
}

.btn-blue{
    background:#2563eb;
}

.info{
    background:#eff6ff;
    color:#1d4ed8;
    padding:14px 18px;
    border-radius:10px;
    margin-bottom:20px;
    font-size:14px;
    font-weight:bold;
}

table{
    width:100%;
    min-width:2200px;
    border-collapse:collapse;
    table-layout:auto;
}

table th{
    background:#1d3557;
    color:white;
    padding:12px 10px;
    font-size:12px;
    border-right:1px solid #35507c;
    text-align:center;
    white-space:nowrap;
}

table td{
    padding:12px 10px;
    border-bottom:1px solid #e5e7eb;
    border-right:1px solid #e5e7eb;
    font-size:12px;
    white-space:nowrap;
    background:white;
}

table tr:hover td{
    background:#f9fafb;
}

.pasif-row td{
    background:#f8fafc;
    color:#64748b;
}

.center{
    text-align:center;
}

.right{
    text-align:right;
    font-variant-numeric:tabular-nums;
}

.green{
    color:#16a34a;
    font-weight:bold;
}

.red{
    color:#dc2626;
    font-weight:bold;
}

.blue{
    color:#2563eb;
    font-weight:bold;
}

.orange{
    color:#ea580c;
    font-weight:bold;
}

.badge{
    display:inline-block;
    padding:6px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
    white-space:nowrap;
}

.badge-green{
    background:#dcfce7;
    color:#166534;
}

.badge-red{
    background:#fee2e2;
    color:#991b1b;
}

.badge-gray{
    background:#e5e7eb;
    color:#374151;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Canlı Tarife Yönetimi</h2>

        <p>
            Aktif tarifeler, geçmiş revizyonlar ve motorin baz fiyat sistemi
        </p>

    </div>

    <?php if($message): ?>

        <div class="alert">
            <?php echo $message; ?>
        </div>

    <?php endif; ?>

    <div class="top-actions">

        <a href="tarife-ekle.php" class="btn">
            + Yeni Tarife
        </a>

        <a href="tarife-yukle.php" class="btn btn-orange">
            Excelden Tarife Yükle
        </a>

        <form method="POST" style="margin:0;">

            <button
            type="submit"
            name="motorin_guncelle"
            class="btn btn-blue">

                Motorin Fiyatlarından Güncelle

            </button>

        </form>

    </div>

    <div class="info">

        Sistem tüm revizyonları saklar.
        Bitiş tarihi dolu olan kayıtlar geçmiş tarifelerdir.

    </div>

    <div class="box">

        <table>

            <thead>

                <tr>

                    <th>ID</th>
                    <th>Firma</th>
                    <th>Çıkış</th>
                    <th>Varış</th>
                    <th>Başlangıç</th>
                    <th>Bitiş</th>
                    <th>Revizyon Aralığı</th>
                    <th>Rev.</th>
                    <th>Durum</th>
                    <th>Baz Tarife</th>
                    <th>Motorin Baz</th>
                    <th>Güncel Motorin</th>
                    <th>Fark</th>
                    <th>%</th>
                    <th>Eşik</th>
                    <th>Zam / İndirim</th>
                    <th>Yeni Fiyat</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($rows as $row): ?>

            <?php

            $aktifMi =
                empty($row['bitis_tarihi']) ||
                $row['bitis_tarihi'] == '0000-00-00';

            $bazTarife =
                (float)$row['birim_fiyat'];

            $motorinBaz =
                (float)$row['motorin_baz_fiyati'];

            $gunlukMotorin =
                (float)($row['motorin_fiyati'] ?? 0);

            $farkTutar =
                $gunlukMotorin - $motorinBaz;

            $farkYuzde = 0;

            if($motorinBaz > 0){

                $farkYuzde =
                    ($farkTutar / $motorinBaz)
                    * 100;
            }

            $zamIndirim = 0;

            $esikDurum = 'YOK';

            if($aktifMi && abs($farkYuzde) >= 7){

                $zamIndirim =
                    (($bazTarife * 40) / 100)
                    * ($farkYuzde / 100);

                $esikDurum = 'GÜNCELLENECEK';
            }

            $yeniFiyat =
                $bazTarife + $zamIndirim;

            ?>

            <tr class="<?php echo $aktifMi ? '' : 'pasif-row'; ?>">

                <td class="center">
                    <?php echo $row['id']; ?>
                </td>

                <td>
                    <?php echo $row['firma_adi']; ?>
                </td>

                <td class="center">
                    <?php echo $row['cikis_noktasi']; ?>
                </td>

                <td class="center">
                    <?php echo $row['varis_noktasi']; ?>
                </td>

                <td class="center">
                    <?php echo tarihGoster($row['baslangic_tarihi']); ?>
                </td>

                <td class="center">
                    <?php echo tarihGoster($row['bitis_tarihi']); ?>
                </td>

                <td class="center blue">
                    <?php echo tarihAraligi($row['baslangic_tarihi'], $row['bitis_tarihi']); ?>
                </td>

                <td class="center blue">
                    <?php echo $row['revizyon_no']; ?>
                </td>

                <td class="center">

                    <?php if($aktifMi): ?>

                        <span class="badge badge-green">
                            AKTİF
                        </span>

                    <?php else: ?>

                        <span class="badge badge-gray">
                            GEÇMİŞ
                        </span>

                    <?php endif; ?>

                </td>

                <td class="right blue">
                    ₺<?php echo number_format($bazTarife,2,',','.'); ?>
                </td>

                <td class="right">
                    ₺<?php echo number_format($motorinBaz,3,',','.'); ?>
                </td>

                <td class="right">
                    ₺<?php echo number_format($gunlukMotorin,3,',','.'); ?>
                </td>

                <td class="right orange">
                    ₺<?php echo number_format($farkTutar,2,',','.'); ?>
                </td>

                <td class="right">
                    %<?php echo number_format($farkYuzde,2,',','.'); ?>
                </td>

                <td class="center">

                    <?php if($esikDurum == 'GÜNCELLENECEK'): ?>

                        <span class="badge badge-green">
                            GÜNCELLENECEK
                        </span>

                    <?php else: ?>

                        <span class="badge badge-red">
                            DEĞİŞİKLİK YOK
                        </span>

                    <?php endif; ?>

                </td>

                <td class="right green">
                    ₺<?php echo number_format($zamIndirim,2,',','.'); ?>
                </td>

                <td class="right blue">
                    ₺<?php echo number_format($yeniFiyat,2,',','.'); ?>
                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>