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

$hakedis_id = $_GET['hakedis_id'] ?? ($_POST['hakedis_id'] ?? 0);

if(!$hakedis_id){
    die("Hakediş ID bulunamadı.");
}

$message = '';

$hakedisQuery = $db->prepare("
    SELECT 
        hakedisler.*,
        sozlesmeler.sozlesme_no,
        cariler.firma_adi
    FROM hakedisler
    LEFT JOIN sozlesmeler 
        ON sozlesmeler.id = hakedisler.sozlesme_id
    LEFT JOIN cariler 
        ON cariler.id = sozlesmeler.cari_id
    WHERE hakedisler.id = ?
    LIMIT 1
");

$hakedisQuery->execute([$hakedis_id]);
$hakedisData = $hakedisQuery->fetch(PDO::FETCH_ASSOC);

if(!$hakedisData){
    die("Hakediş kaydı bulunamadı.");
}

$sozlesmeNo = trim($hakedisData['sozlesme_no'] ?? '');
$firmaAdi   = trim($hakedisData['firma_adi'] ?? '');

function tarihCevir($value){

    $value = trim((string)$value);

    if(empty($value)){
        return null;
    }

    $value = str_replace('/', '.', $value);
    $parca = explode('.', $value);

    if(count($parca) == 3){

        return $parca[2] . '-' .
               str_pad($parca[1],2,'0',STR_PAD_LEFT) . '-' .
               str_pad($parca[0],2,'0',STR_PAD_LEFT);
    }

    return null;
}

function temizMetin($value){

    $value = trim((string)$value);
    $value = str_replace(["\xc2\xa0"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = mb_strtoupper($value, 'UTF-8');

    return $value;
}

function sayiCevir($value){

    $value = trim((string)$value);

    if($value === ''){
        return 0;
    }

    $value = str_replace(['₺', 'TL', ' ', "\xc2\xa0"], '', $value);

    if(strpos($value, ',') !== false){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

/*
CSV OKUMA
*/
if(isset($_FILES['csv'])){

    $tmp = $_FILES['csv']['tmp_name'];

    if(($file = fopen($tmp, 'r')) !== FALSE){

        $rows = [];

        while(($data = fgetcsv($file, 5000, ";", '"', "\\")) !== FALSE){
            $rows[] = $data;
        }

        fclose($file);

        $_SESSION['csv_rows'] = $rows;

        $message = 'CSV başarıyla okundu.';
    }
}

/*
OTOMATİK HAKEDİŞ OLUŞTURMA
*/
if(isset($_POST['aktar'])){

    if(isset($_SESSION['csv_rows'])){

        $db->beginTransaction();

        try {

            $csvRows = $_SESSION['csv_rows'];

            /*
            Aynı hakediş tekrar oluşturulursa eski satırlar silinsin.
            */
            $deleteOld = $db->prepare("
                DELETE FROM hakedis_satirlari
                WHERE hakedis_id = ?
            ");
            $deleteOld->execute([$hakedis_id]);

            /*
            CSV satırlarını tarihe göre sırala.
            Böylece revizyon oluşursa sonraki günler yeni tarifeden devam eder.
            */
            $dataRows = [];

            foreach($csvRows as $index => $row){

                if($index == 0){
                    continue;
                }

                $tarih = tarihCevir($row[2] ?? '');

                if(!$tarih){
                    continue;
                }

                $dataRows[] = [
                    'row' => $row,
                    'tarih' => $tarih
                ];
            }

            usort($dataRows, function($a, $b){
                return strcmp($a['tarih'], $b['tarih']);
            });

            foreach($dataRows as $item){

                $row = $item['row'];
                $tarih = $item['tarih'];

                $irsaliyeNo = trim($row[1] ?? '');

                if(empty($irsaliyeNo)){
                    continue;
                }

                $cikis = temizMetin($row[3] ?? '');
                $varis = temizMetin($row[4] ?? '');

                /*
                1- Sefer tarihindeki motorin fiyatını çek
                */
                $motorinQuery = $db->prepare("
                    SELECT motorin_fiyati
                    FROM motorin_fiyatlari
                    WHERE tarih = ?
                    LIMIT 1
                ");

                $motorinQuery->execute([$tarih]);
                $motorinData = $motorinQuery->fetch(PDO::FETCH_ASSOC);

                $gunlukMotorin = sayiCevir($motorinData['motorin_fiyati'] ?? 0);

                if($gunlukMotorin <= 0){
                    continue;
                }

                /*
                2- Sefer tarihine göre geçerli tarifeyi bul
                */
                $tarifeQuery = $db->prepare("
                    SELECT *
                    FROM tarifeler
                    WHERE TRIM(UPPER(firma_adi)) = ?
                    AND TRIM(UPPER(cikis_noktasi)) = ?
                    AND TRIM(UPPER(varis_noktasi)) = ?
                    AND (
                        baslangic_tarihi IS NULL
                        OR baslangic_tarihi = '0000-00-00'
                        OR baslangic_tarihi <= ?
                    )
                    AND (
                        bitis_tarihi IS NULL
                        OR bitis_tarihi = '0000-00-00'
                        OR bitis_tarihi >= ?
                    )
                    ORDER BY 
                        baslangic_tarihi DESC,
                        revizyon_no DESC,
                        id DESC
                    LIMIT 1
                ");

                $tarifeQuery->execute([
                    temizMetin($firmaAdi),
                    $cikis,
                    $varis,
                    $tarih,
                    $tarih
                ]);

                $tarife = $tarifeQuery->fetch(PDO::FETCH_ASSOC);

                if(!$tarife){
                    continue;
                }

                $tarifeId   = (int)$tarife['id'];
                $birimFiyat = sayiCevir($tarife['birim_fiyat']);
                $motorinBaz = sayiCevir($tarife['motorin_baz_fiyati']);

                if($motorinBaz <= 0){
                    continue;
                }

                /*
                3- Motorin farkı ve yüzdesi
                Doğru formül:
                (Günlük Motorin - Baz Motorin) / Baz Motorin x 100
                */
                $motorinFarkTutari = $gunlukMotorin - $motorinBaz;

                $motorinFarkYuzde = 0;

                if($motorinBaz > 0){
                    $motorinFarkYuzde = ($motorinFarkTutari / $motorinBaz) * 100;
                }

                $motorinFarkTutari = round($motorinFarkTutari, 3);
                $motorinFarkYuzde  = round($motorinFarkYuzde, 2);

                $zamIndirimTutari = 0;
                $guncelBirimFiyat = $birimFiyat;

                /*
                4- %7 ve üzerindeyse revizyon aç
                Sadece birim fiyatı 0'dan büyük olan tarifelerde revizyon yapılır.
                */
                if($birimFiyat > 0 && abs($motorinFarkYuzde) >= 7){

                    $zamIndirimTutari =
                        (($birimFiyat * 40) / 100)
                        * ($motorinFarkYuzde / 100);

                    $guncelBirimFiyat =
                        $birimFiyat + $zamIndirimTutari;

                    $oncekiGun = date('Y-m-d', strtotime($tarih . ' -1 day'));

                    /*
                    Aynı firma / güzergah / başlangıç tarihi için
                    daha önce revizyon var mı?
                    */
                    $kontrolQuery = $db->prepare("
                        SELECT *
                        FROM tarifeler
                        WHERE TRIM(UPPER(firma_adi)) = ?
                        AND TRIM(UPPER(cikis_noktasi)) = ?
                        AND TRIM(UPPER(varis_noktasi)) = ?
                        AND baslangic_tarihi = ?
                        LIMIT 1
                    ");

                    $kontrolQuery->execute([
                        temizMetin($firmaAdi),
                        $cikis,
                        $varis,
                        $tarih
                    ]);

                    $mevcutYeniTarife = $kontrolQuery->fetch(PDO::FETCH_ASSOC);

                    if(!$mevcutYeniTarife){

                        /*
                        Eski açık tarifeyi kapat
                        */
                        $updateOldTarife = $db->prepare("
                            UPDATE tarifeler
                            SET bitis_tarihi = ?
                            WHERE id = ?
                            AND (
                                bitis_tarihi IS NULL
                                OR bitis_tarihi = '0000-00-00'
                            )
                        ");

                        $updateOldTarife->execute([
                            $oncekiGun,
                            $tarifeId
                        ]);

                        $yeniRevizyonNo = ((int)($tarife['revizyon_no'] ?? 0)) + 1;

                        /*
                        Yeni tarife aç:
                        - Başlangıç tarihi: eşik aşılan gün
                        - Yeni motorin baz fiyatı: o günkü motorin
                        - Yeni birim fiyat: güncel hesaplanan fiyat
                        */
                        $insertTarife = $db->prepare("
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

                        $insertTarife->execute([
                            $tarife['firma_adi'],
                            $tarife['cikis_noktasi'],
                            $tarife['varis_noktasi'],
                            $guncelBirimFiyat,
                            $gunlukMotorin,
                            $tarih,
                            $yeniRevizyonNo
                        ]);

                    } else {

                        /*
                        Aynı revizyon daha önce oluşmuşsa
                        o tarifenin fiyatı kullanılacak.
                        */
                        $guncelBirimFiyat = sayiCevir($mevcutYeniTarife['birim_fiyat']);
                    }
                }

                /*
                5- Hakediş satırını oluştur
                */
                $kdvTutari = $guncelBirimFiyat * 0.20;
                $tevkifatTutari = $kdvTutari * 0.20;

                $netTutar =
                    ($guncelBirimFiyat + $kdvTutari) - $tevkifatTutari;

                $insert = $db->prepare("
                    INSERT INTO hakedis_satirlari
                    (
                        hakedis_id,
                        irsaliye_no,
                        tasima_tarihi,
                        cikis_noktasi,
                        varis_noktasi,
                        birim_fiyat,
                        satir_toplam,
                        motorin_baz_fiyati,
                        gunluk_motorin_fiyati,
                        motorin_fark_tutari,
                        motorin_fark_yuzde,
                        zam_indirim_tutari,
                        guncel_birim_fiyat,
                        kdv_tutari,
                        tevkifat_tutari,
                        net_tutar
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $hakedis_id,
                    $irsaliyeNo,
                    $tarih,
                    $cikis,
                    $varis,
                    $birimFiyat,
                    $guncelBirimFiyat,
                    $motorinBaz,
                    $gunlukMotorin,
                    $motorinFarkTutari,
                    $motorinFarkYuzde,
                    $zamIndirimTutari,
                    $guncelBirimFiyat,
                    $kdvTutari,
                    $tevkifatTutari,
                    $netTutar
                ]);
            }

            /*
            Hakediş toplamlarını güncelle
            */
            $toplamQuery = $db->prepare("
                SELECT
                    SUM(guncel_birim_fiyat) AS toplam,
                    SUM(kdv_tutari) AS kdv,
                    SUM(tevkifat_tutari) AS tevkifat,
                    SUM(net_tutar) AS net
                FROM hakedis_satirlari
                WHERE hakedis_id = ?
            ");

            $toplamQuery->execute([$hakedis_id]);
            $toplamlar = $toplamQuery->fetch(PDO::FETCH_ASSOC);

            $updateHakedis = $db->prepare("
                UPDATE hakedisler
                SET
                    toplam_tutar = ?,
                    kdv_tutar = ?,
                    tevkifat_tutar = ?,
                    net_tutar = ?
                WHERE id = ?
            ");

            $updateHakedis->execute([
                $toplamlar['toplam'] ?? 0,
                $toplamlar['kdv'] ?? 0,
                $toplamlar['tevkifat'] ?? 0,
                $toplamlar['net'] ?? 0,
                $hakedis_id
            ]);

            $db->commit();

            $message = 'Hakediş yeniden oluşturuldu. Motorin %7 eşik aşımı varsa tarife revizyonları işlendi.';

        } catch(Exception $e){

            $db->rollBack();

            $message = 'Hata oluştu: ' . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Excel Aktarım Sistemi</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.box{
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

button{
    background:#16a34a;
    color:white;
    border:none;
    padding:14px 20px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
    font-weight:bold;
}

.info{
    background:#eff6ff;
    color:#1d4ed8;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
    font-weight:bold;
}

.warning{
    background:#fff7ed;
    color:#9a3412;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
    font-weight:bold;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Excel Aktarım Sistemi</h2>

        <p>
            CSV bazlı otomatik hakediş oluşturma ve motorin revizyon kontrol ekranı
        </p>

    </div>

    <div class="box">

        <div class="info">

            Hakediş:
            <?php echo $hakedis_id; ?>

            |

            Firma:
            <?php echo htmlspecialchars($firmaAdi); ?>

            |

            Sözleşme:
            <?php echo htmlspecialchars($sozlesmeNo); ?>

        </div>

        <div class="warning">
            Motorin farkı, baz motorin fiyatına göre hesaplanır. %7 ve üzeri artış/azalış varsa yeni tarife revizyonu açılır.
        </div>

        <?php if($message): ?>

            <div class="alert">
                <?php echo $message; ?>
            </div>

        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <input
            type="hidden"
            name="hakedis_id"
            value="<?php echo $hakedis_id; ?>">

            <input
            type="file"
            name="csv"
            required>

            <br><br>

            <button type="submit">
                CSV Oku
            </button>

        </form>

        <br>

        <form method="POST">

            <input
            type="hidden"
            name="hakedis_id"
            value="<?php echo $hakedis_id; ?>">

            <input
            type="hidden"
            name="aktar"
            value="1">

            <button type="submit">
                Otomatik Hakediş Oluştur
            </button>

        </form>

    </div>

</div>

</body>
</html>