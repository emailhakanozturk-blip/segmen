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
$aktarTamamlandi = false;
$errorMessage = false;

$hakedisQuery = $db->prepare("
    SELECT
        hakedisler.*,
        sozlesmeler.sozlesme_no,
        cariler.firma_adi
    FROM hakedisler
    LEFT JOIN sozlesmeler ON sozlesmeler.id = hakedisler.sozlesme_id
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    WHERE hakedisler.id = ?
    LIMIT 1
");

$hakedisQuery->execute([$hakedis_id]);
$hakedisData = $hakedisQuery->fetch(PDO::FETCH_ASSOC);

if(!$hakedisData){
    die("Hakediş kaydı bulunamadı.");
}

$sozlesmeNo = trim($hakedisData['sozlesme_no'] ?? '');
$firmaAdi = trim($hakedisData['firma_adi'] ?? '');

function tarihCevir($value){
    $value = trim((string)$value);

    if($value === ''){
        return null;
    }

    $value = str_replace('/', '.', $value);
    $parca = explode('.', $value);

    if(count($parca) === 3){
        return $parca[2] . '-' . str_pad($parca[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parca[0], 2, '0', STR_PAD_LEFT);
    }

    return null;
}

function temizMetin($value){
    $value = trim((string)$value);
    $value = str_replace(["\xc2\xa0"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_strtoupper($value, 'UTF-8');
}

function sayiCevir($value){
    $value = trim((string)$value);

    if($value === ''){
        return 0;
    }

    $value = str_replace(['₺', 'â‚º', 'TL', ' ', "\xc2\xa0"], '', $value);

    if(strpos($value, ',') !== false){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function aramaAnahtari($value){
    $value = trim((string)$value);
    $value = preg_replace('/\p{Mn}/u', '', $value);
    $value = mb_strtoupper($value, 'UTF-8');

    $value = str_replace(
        ['Ç','Ğ','İ','İ','Ö','Ş','Ü','Â','Î','Û','Ã‡','Ä','Ä°','Ã–','Å','Ãœ'],
        ['C','G','I','I','O','S','U','A','I','U','C','G','I','O','S','U'],
        $value
    );

    return preg_replace('/[^A-Z0-9]/', '', $value);
}

function metinEslesir($kaynak, $aranan){
    $kaynakKey = aramaAnahtari($kaynak);
    $arananKey = aramaAnahtari($aranan);

    if($arananKey === ''){
        return true;
    }

    if($kaynakKey === $arananKey){
        return true;
    }

    if(strpos($kaynakKey, $arananKey) !== false || strpos($arananKey, $kaynakKey) !== false){
        return true;
    }

    $kaynakSessiz = str_replace(['A','E','I','O','U'], '', $kaynakKey);
    $arananSessiz = str_replace(['A','E','I','O','U'], '', $arananKey);

    return $kaynakSessiz !== '' && $arananSessiz !== '' && (
        strpos($kaynakSessiz, $arananSessiz) !== false ||
        strpos($arananSessiz, $kaynakSessiz) !== false
    );
}

function motorinCsvMi($rows){
    foreach($rows as $row){
        $satir = aramaAnahtari(implode(' ', $row));

        if(strpos($satir, 'TARIH') !== false && strpos($satir, 'MOTORIN') !== false){
            return true;
        }
    }

    return false;
}

function irsaliyeCsvMi($rows){
    foreach($rows as $row){
        foreach($row as $cell){
            if(preg_match('/\bSEI\d+/i', (string)$cell)){
                return true;
            }
        }
    }

    return false;
}

function xlsxSatirlariOku($filePath){
    $python = 'C:\\Users\\asus\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'xlsx_to_rows.py';

    if(!is_file($python) || !is_file($script)){
        throw new Exception('Excel okuyucu bulunamadı. Lütfen dosyayı CSV olarak yükleyin.');
    }

    $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($filePath) . ' 2>&1';
    $output = shell_exec($command);

    if($output === null || trim($output) === ''){
        throw new Exception('Excel dosyası okunamadı.');
    }

    $rows = json_decode($output, true);

    if(!is_array($rows)){
        throw new Exception('Excel dosyası okunamadı: ' . trim($output));
    }

    return $rows;
}

function gorunenMetin($value){
    return str_replace(
        ['?ahin', '?irketi', 'BÜYÜKYA?CI', 'Į?FTL?K', 'Ç?FTL?K', 'BA??Į?', 'ESENBO?A', 'GÖLBA?I', 'G?MAT', 'S?NCAN', 'YEN?KENT'],
        ['Şahin', 'Şirketi', 'BÜYÜKYAĞCI', 'ÇİFTLİK', 'ÇİFTLİK', 'BAŞİSKELE', 'ESENBOĞA', 'GÖLBAŞI', 'GİMAT', 'SİNCAN', 'YENİKENT'],
        (string)$value
    );
}

if(isset($_FILES['csv'])){
    $tmp = $_FILES['csv']['tmp_name'];
    $fileName = $_FILES['csv']['name'] ?? '';
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if(in_array($extension, ['xlsx', 'xlsm'], true)){
        try {
            $rows = xlsxSatirlariOku($tmp);

            $_SESSION['csv_rows'] = $rows;
            $_SESSION['csv_file_name'] = $fileName;

            $message = 'Excel dosyası başarıyla okundu. Şimdi otomatik hakediş oluşturabilirsiniz.';
        } catch(Exception $e){
            $errorMessage = true;
            $message = 'Hata oluştu: ' . $e->getMessage();
        }
    } elseif(($file = fopen($tmp, 'r')) !== false){
        $rows = [];

        while(($data = fgetcsv($file, 5000, ';', '"', '\\')) !== false){
            $rows[] = $data;
        }

        fclose($file);

        $_SESSION['csv_rows'] = $rows;
        $_SESSION['csv_file_name'] = $fileName;

        $message = 'CSV başarıyla okundu. Şimdi otomatik hakediş oluşturabilirsiniz.';
    }
}

if(isset($_POST['aktar'])){
    if(isset($_SESSION['csv_rows'])){
        $db->beginTransaction();

        try {
            $csvRows = $_SESSION['csv_rows'];

            $deleteOld = $db->prepare("DELETE FROM hakedis_satirlari WHERE hakedis_id = ?");
            $deleteOld->execute([$hakedis_id]);

            $motorinFormat = motorinCsvMi($csvRows) && !irsaliyeCsvMi($csvRows);
            $dataRows = [];

            foreach($csvRows as $index => $row){
                if($motorinFormat){
                    continue;
                }

                if($index === 0){
                    continue;
                }

                $tarih = tarihCevir($row[2] ?? '');

                if(!$tarih){
                    continue;
                }

                $irsaliyeNo = trim($row[1] ?? '');

                if($irsaliyeNo === ''){
                    continue;
                }

                $dataRows[] = [
                    'tarih' => $tarih,
                    'irsaliye' => $irsaliyeNo,
                    'cikis' => temizMetin($row[3] ?? ''),
                    'varis' => temizMetin($row[4] ?? ''),
                    'gunluk_motorin' => sayiCevir($row[8] ?? 0),
                    'dosya_birim_fiyat' => sayiCevir($row[5] ?? 0),
                    'dosya_motorin_baz' => sayiCevir($row[7] ?? 0),
                    'dosya_motorin_fark' => sayiCevir($row[9] ?? 0),
                    'dosya_motorin_yuzde' => sayiCevir($row[10] ?? 0),
                    'dosya_zam' => sayiCevir($row[11] ?? 0),
                    'dosya_guncel' => sayiCevir($row[12] ?? 0),
                    'dosya_kdv' => sayiCevir($row[13] ?? 0),
                    'dosya_tevkifat' => sayiCevir($row[14] ?? 0),
                    'dosya_net' => sayiCevir($row[15] ?? 0)
                ];
            }

            usort($dataRows, function($a, $b){
                return strcmp($a['tarih'], $b['tarih']);
            });

            if(empty($dataRows)){
                throw new Exception('Bu dosyada irsaliye satırı bulunamadı. Hakediş için SEI ile başlayan irsaliye listesini içeren CSV dosyasını yükleyin.');
            }

            $tarifeQuery = $db->prepare("
                SELECT *
                FROM tarifeler
                WHERE TRIM(UPPER(firma_adi)) = ?
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
                ORDER BY cikis_noktasi ASC, varis_noktasi ASC, baslangic_tarihi DESC, revizyon_no DESC, id DESC
            ");

            $motorinQuery = $db->prepare("
                SELECT motorin_fiyati
                FROM motorin_fiyatlari
                WHERE tarih = ?
                LIMIT 1
            ");

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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $eklenen = 0;
            $atlan = 0;

            foreach($dataRows as $item){
                $tarih = $item['tarih'];

                $tarifeQuery->execute([
                    temizMetin($firmaAdi),
                    $tarih,
                    $tarih
                ]);

                $adayTarifeler = $tarifeQuery->fetchAll(PDO::FETCH_ASSOC);
                $eslesenTarifeler = [];

                foreach($adayTarifeler as $aday){
                    $cikisUygun = $item['cikis'] === '' || metinEslesir($aday['cikis_noktasi'], $item['cikis']);
                    $varisUygun = $item['varis'] === '' || metinEslesir($aday['varis_noktasi'], $item['varis']);

                    if($cikisUygun && $varisUygun){
                        $eslesenTarifeler[] = $aday;
                    }
                }

                if(count($eslesenTarifeler) > 1){
                    $enGuncelTarife = null;

                    foreach($eslesenTarifeler as $tarifeAdayi){
                        if($enGuncelTarife === null){
                            $enGuncelTarife = $tarifeAdayi;
                            continue;
                        }

                        $adayBaslangic = $tarifeAdayi['baslangic_tarihi'] ?: '0000-00-00';
                        $guncelBaslangic = $enGuncelTarife['baslangic_tarihi'] ?: '0000-00-00';

                        if($adayBaslangic > $guncelBaslangic){
                            $enGuncelTarife = $tarifeAdayi;
                            continue;
                        }

                        if(
                            $adayBaslangic === $guncelBaslangic &&
                            (int)($tarifeAdayi['revizyon_no'] ?? 0) > (int)($enGuncelTarife['revizyon_no'] ?? 0)
                        ){
                            $enGuncelTarife = $tarifeAdayi;
                        }
                    }

                    $eslesenTarifeler = [$enGuncelTarife];
                }

                if(empty($eslesenTarifeler)){
                    $atlan++;
                    continue;
                }

                $gunlukMotorin = (float)$item['gunluk_motorin'];

                if($gunlukMotorin <= 0){
                    $motorinQuery->execute([$tarih]);
                    $motorinData = $motorinQuery->fetch(PDO::FETCH_ASSOC);
                    $gunlukMotorin = sayiCevir($motorinData['motorin_fiyati'] ?? 0);
                }

                if($gunlukMotorin <= 0){
                    $atlan += count($eslesenTarifeler);
                    continue;
                }

                foreach($eslesenTarifeler as $tarife){
                    $dosyaTutarlariVar =
                        ($item['dosya_birim_fiyat'] ?? 0) > 0 &&
                        ($item['dosya_guncel'] ?? 0) > 0 &&
                        ($item['dosya_kdv'] ?? 0) > 0 &&
                        ($item['dosya_net'] ?? 0) > 0;

                    $birimFiyat = $dosyaTutarlariVar ? $item['dosya_birim_fiyat'] : sayiCevir($tarife['birim_fiyat']);
                    $motorinBaz = ($item['dosya_motorin_baz'] ?? 0) > 0 ? $item['dosya_motorin_baz'] : sayiCevir($tarife['motorin_baz_fiyati']);

                    if($birimFiyat <= 0 || $motorinBaz <= 0){
                        $atlan++;
                        continue;
                    }

                    if($dosyaTutarlariVar){
                        $motorinFarkTutari = $item['dosya_motorin_fark'] ?? round($gunlukMotorin - $motorinBaz, 3);
                        $motorinFarkYuzde = $item['dosya_motorin_yuzde'] ?? round(($motorinFarkTutari / $motorinBaz) * 100, 2);
                        $zamIndirimTutari = $item['dosya_zam'] ?? 0;
                        $guncelBirimFiyat = $item['dosya_guncel'];
                        $kdvTutari = $item['dosya_kdv'];
                        $tevkifatTutari = $item['dosya_tevkifat'];
                        $netTutar = $item['dosya_net'];
                    } else {
                        $motorinFarkTutari = round($gunlukMotorin - $motorinBaz, 3);
                        $motorinFarkYuzde = round(($motorinFarkTutari / $motorinBaz) * 100, 2);

                        $zamIndirimTutari = 0;
                        $guncelBirimFiyat = $birimFiyat;

                        if(abs($motorinFarkYuzde) >= 7){
                            $zamIndirimTutari = (($birimFiyat * 40) / 100) * ($motorinFarkYuzde / 100);
                            $guncelBirimFiyat = $birimFiyat + $zamIndirimTutari;
                        }

                        $kdvTutari = $guncelBirimFiyat * 0.20;
                        $tevkifatTutari = $kdvTutari * 0.20;
                        $netTutar = ($guncelBirimFiyat + $kdvTutari) - $tevkifatTutari;
                    }

                    $insert->execute([
                        $hakedis_id,
                        $item['irsaliye'] . '-' . $tarife['id'],
                        $tarih,
                        gorunenMetin($tarife['cikis_noktasi']),
                        gorunenMetin($tarife['varis_noktasi']),
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

                    $eklenen++;
                }
            }

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
                SET toplam_tutar = ?, kdv_tutar = ?, tevkifat_tutar = ?, net_tutar = ?
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

            $aktarTamamlandi = true;
            $message = $eklenen . ' hakediş satırı başarıyla aktarıldı. ' . $atlan . ' satır atlandı. Detay ekranından toplamı kontrol edebilirsiniz.';
        } catch(Exception $e){
            $db->rollBack();
            $errorMessage = true;
            $message = 'Hata oluştu: ' . $e->getMessage();
        }
    } else {
        $errorMessage = true;
        $message = 'Önce CSV dosyasını okuyun, ardından otomatik hakediş oluşturun.';
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

.button-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:#0f3e68;
    color:white;
    text-decoration:none;
    padding:12px 16px;
    border-radius:8px;
    font-weight:bold;
    margin-top:12px;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
    font-weight:bold;
}

.alert.error{
    background:#fee2e2;
    color:#991b1b;
}

.alert small{
    display:block;
    margin-top:6px;
    font-weight:500;
    color:inherit;
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

.form-block{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:22px;
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Excel Aktarım Sistemi</h2>
        <p>CSV bazlı otomatik hakediş oluşturma ve motorin revizyon kontrol ekranı</p>
    </div>

    <div class="box">
        <div class="info">
            Hakediş: <?php echo $hakedis_id; ?>
            |
            Firma: <?php echo htmlspecialchars(gorunenMetin($firmaAdi)); ?>
            |
            Sözleşme: <?php echo htmlspecialchars($sozlesmeNo); ?>
        </div>

        <div class="warning">
            Motorin farkı, baz motorin fiyatına göre hesaplanır. %7 ve üzeri artış/azalış varsa satırda zam/indirim oluşur.
        </div>

        <?php if($message): ?>
            <div class="alert <?php echo $errorMessage ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
                <?php if($aktarTamamlandi): ?>
                    <small>Bu ekran aktarım sonucunu gösterir; doğru tablo ve toplam kontrolü detay sayfasındadır.</small>
                    <a class="button-link" href="hakedis-detay.php?hakedis_id=<?php echo $hakedis_id; ?>">Detay Sayfasını Aç</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form class="form-block" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="hakedis_id" value="<?php echo $hakedis_id; ?>">
            <input type="file" name="csv" accept=".csv,.xlsx,.xlsm" required>
            <button type="submit">Dosyayı Oku</button>
        </form>

        <form method="POST">
            <input type="hidden" name="hakedis_id" value="<?php echo $hakedis_id; ?>">
            <input type="hidden" name="aktar" value="1">
            <button type="submit">Otomatik Hakediş Oluştur</button>
        </form>
    </div>
</div>

</body>
</html>
