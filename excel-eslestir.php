<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

require_once __DIR__ . '/config/database.php';

function ekranMetniDuzelt($html)
{
    return $html;
}

ob_start('ekranMetniDuzelt');

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
$skipDiagnostics = [];

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
$hakedisCariId = (int)($hakedisData['cari_id'] ?? 0);
$hakedisSozlesmeId = (int)($hakedisData['sozlesme_id'] ?? 0);
$aktarimUrunGrubu = str_contains(mb_strtoupper($sozlesmeNo, 'UTF-8'), 'PET') ? 'PET' : 'DAMACANA';

if(isset($_POST['nokta_ekle'])){
    $cikis = temizMetin($_POST['cikis'] ?? '');
    $varis = temizMetin($_POST['varis'] ?? '');
    $tarih = tarihCevir($_POST['tarih'] ?? '') ?: trim((string)($_POST['tarih'] ?? date('Y-m-d')));
    $birimFiyat = sayiCevir($_POST['birim_fiyat'] ?? 0);
    $motorinBaz = sayiCevir($_POST['motorin_baz'] ?? 0);

    if($cikis !== '' && $varis !== '' && $birimFiyat > 0){
        if($motorinBaz <= 0){
            $motorinQuery = $db->prepare("SELECT motorin_fiyati FROM motorin_fiyatlari WHERE tarih <= ? AND motorin_fiyati > 0 ORDER BY tarih DESC LIMIT 1");
            $motorinQuery->execute([$tarih]);
            $motorinBaz = sayiCevir($motorinQuery->fetchColumn() ?: 0);
        }

        $km = $motorinBaz > 0 ? round($birimFiyat / $motorinBaz, 2) : 0;
        $insertTarife = $db->prepare("
            INSERT INTO tarifeler
            (cari_id, sozlesme_id, firma_adi, sozlesme_no, cikis_noktasi, varis_noktasi, sevkiyat_km, birim_fiyat, motorin_baz_fiyati, baslangic_tarihi, tarife_tipi, aciklama, motorin_revize, revizyon_no, aktif)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Atlanan satırdan eklendi', 1, 1, 1)
        ");
        $insertTarife->execute([$hakedisCariId, $hakedisSozlesmeId, $firmaAdi, $sozlesmeNo, gorunenMetin($cikis), gorunenMetin($varis), $km, $birimFiyat, $motorinBaz, $tarih, $aktarimUrunGrubu]);
        $message = 'Nokta tarifesi eklendi. Dosyayı tekrar aktarabilirsiniz.';
    } else {
        $errorMessage = true;
        $message = 'Nokta eklemek için güzergah ve tarife fiyatı zorunludur.';
    }
}

if(isset($_POST['toplu_nokta_ekle'])){
    $cikisler = $_POST['cikis'] ?? [];
    $varislar = $_POST['varis'] ?? [];
    $tarihler = $_POST['tarih'] ?? [];
    $irsaliyeler = $_POST['irsaliye'] ?? [];
    $birimFiyatlar = $_POST['birim_fiyat'] ?? [];
    $motorinBazlar = $_POST['motorin_baz'] ?? [];
    $kdvler = $_POST['kdv_tutari'] ?? [];
    $tevkifatlar = $_POST['tevkifat_tutari'] ?? [];
    $netler = $_POST['net_tutar'] ?? [];
    $seciliSatirlar = array_flip($_POST['secili_satir'] ?? []);

    $insertTarife = $db->prepare("
        INSERT INTO tarifeler
        (cari_id, sozlesme_id, firma_adi, sozlesme_no, cikis_noktasi, varis_noktasi, sevkiyat_km, birim_fiyat, motorin_baz_fiyati, baslangic_tarihi, tarife_tipi, aciklama, motorin_revize, revizyon_no, aktif)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Atlanan satırdan eklendi', 1, 1, 1)
    ");

    $deleteSatir = $db->prepare("DELETE FROM hakedis_satirlari WHERE hakedis_id = ? AND irsaliye_no = ?");
    $insertSatir = $db->prepare("
        INSERT INTO hakedis_satirlari
        (hakedis_id, irsaliye_no, tasima_tarihi, cikis_noktasi, varis_noktasi, birim_fiyat, satir_toplam, motorin_baz_fiyati, gunluk_motorin_fiyati, motorin_fark_tutari, motorin_fark_yuzde, zam_indirim_tutari, guncel_birim_fiyat, kdv_tutari, tevkifat_tutari, net_tutar)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)
    ");
    $toplamQuery = $db->prepare("SELECT SUM(guncel_birim_fiyat) toplam, SUM(kdv_tutari) kdv, SUM(tevkifat_tutari) tevkifat, SUM(net_tutar) net FROM hakedis_satirlari WHERE hakedis_id = ?");
    $hakedisUpdate = $db->prepare("UPDATE hakedisler SET toplam_tutar = ?, kdv_tutar = ?, tevkifat_tutar = ?, net_tutar = ? WHERE id = ?");

    $eklenenNokta = 0;
    $aktarilanSatir = 0;
    foreach($cikisler as $idx => $cikisRaw){
        if(!isset($seciliSatirlar[(string)$idx])){
            continue;
        }

        $cikis = temizMetin($cikisRaw ?? '');
        $varis = temizMetin($varislar[$idx] ?? '');
        $tarih = tarihCevir($tarihler[$idx] ?? '') ?: trim((string)($tarihler[$idx] ?? date('Y-m-d')));
        $birimFiyat = sayiCevir($birimFiyatlar[$idx] ?? 0);
        $motorinBaz = sayiCevir($motorinBazlar[$idx] ?? 0);

        if($cikis === '' || $varis === '' || $birimFiyat <= 0){
            continue;
        }

        if($motorinBaz <= 0){
            $motorinQuery = $db->prepare("SELECT motorin_fiyati FROM motorin_fiyatlari WHERE tarih <= ? AND motorin_fiyati > 0 ORDER BY tarih DESC LIMIT 1");
            $motorinQuery->execute([$tarih]);
            $motorinBaz = sayiCevir($motorinQuery->fetchColumn() ?: 0);
        }

        if($motorinBaz <= 0){
            $motorinBaz = 1;
        }

        $km = round($birimFiyat / $motorinBaz, 2);
        $insertTarife->execute([$hakedisCariId, $hakedisSozlesmeId, $firmaAdi, $sozlesmeNo, gorunenMetin($cikis), gorunenMetin($varis), $km, $birimFiyat, $motorinBaz, $tarih, $aktarimUrunGrubu]);
        $eklenenNokta++;

        $irsaliye = trim((string)($irsaliyeler[$idx] ?? ''));
        if($irsaliye !== ''){
            $kdv = sayiCevir($kdvler[$idx] ?? 0);
            $tevkifat = sayiCevir($tevkifatlar[$idx] ?? 0);
            $net = sayiCevir($netler[$idx] ?? 0);
            if($kdv <= 0){ $kdv = round($birimFiyat * 0.20, 4); }
            if($tevkifat <= 0){ $tevkifat = round($kdv * 0.20, 4); }
            if($net <= 0){ $net = round(($birimFiyat + $kdv) - $tevkifat, 4); }
            $deleteSatir->execute([$hakedis_id, $irsaliye]);
            $insertSatir->execute([$hakedis_id, $irsaliye, $tarih, gorunenMetin($cikis), gorunenMetin($varis), $birimFiyat, $birimFiyat, $motorinBaz, $motorinBaz, $birimFiyat, $kdv, $tevkifat, $net]);
            $aktarilanSatir++;
        }
    }

    $message = $eklenenNokta . ' nokta tarifesi toplu eklendi. Dosyayı tekrar aktarabilirsiniz.';
    $errorMessage = $eklenenNokta === 0;
}

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

    $value = str_replace(['Ã¢â€šÂº', 'ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Âº', 'TL', ' ', "\xc2\xa0"], '', $value);

    if(strpos($value, ',') !== false){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function aramaAnahtari($value){
    $value = trim((string)$value);
    $value = str_replace(["\xc2\xa0"], ' ', $value);
    $value = mb_strtoupper($value, 'UTF-8');
    $value = strtr($value, [
        'Ç' => 'C',
        'Ã„Â' => 'G',
        'İ' => 'I',
        'I' => 'I',
        'Ö' => 'O',
        'Ş' => 'S',
        'Ü' => 'U',
        'Ãƒâ€š' => 'A',
        'ÃƒÂ' => 'I',
        'Ãƒâ€º' => 'U',
        'ÃƒÆ’Ã¢â‚¬Â¡' => 'C',
        'Ãƒâ€Ã…Â¾' => 'G',
        'Ãƒâ€Ã„Â' => 'G',
        'Ãƒâ€Ã‚Â°' => 'I',
        'ÃƒÆ’-' => 'O',
        'Ãƒâ€¦Ã…Â¾' => 'S',
        'Ãƒâ€¦Ş' => 'S',
        'ÃƒÆ’Ã…â€œ' => 'U'
    ]);

    return preg_replace('/[^A-Z0-9]/', '', $value);
}

function metinEslesir($kaynak, $aranan){
    $kaynakKey = aramaAnahtari($kaynak);
    $arananKey = aramaAnahtari($aranan);

    if($kaynakKey === '' || $arananKey === ''){
        return true;
    }

    if($kaynakKey === $arananKey){
        return true;
    }

    if(strlen($kaynakKey) < 4 || strlen($arananKey) < 4){
        return false;
    }

    $minUzunluk = min(strlen($kaynakKey), strlen($arananKey));
    $maxUzunluk = max(strlen($kaynakKey), strlen($arananKey));
    if(
        $minUzunluk >= 6 &&
        ($minUzunluk / max(1, $maxUzunluk)) >= 0.70 &&
        (strpos($kaynakKey, $arananKey) !== false || strpos($arananKey, $kaynakKey) !== false)
    ){
        return true;
    }

    if($maxUzunluk >= 6 && levenshtein($kaynakKey, $arananKey) <= max(1, (int)floor($maxUzunluk * 0.18))){
        return true;
    }

    $kaynakSessiz = str_replace(['A','E','I','O','U'], '', $kaynakKey);
    $arananSessiz = str_replace(['A','E','I','O','U'], '', $arananKey);

    $minSessizUzunluk = min(strlen($kaynakSessiz), strlen($arananSessiz));
    $maxSessizUzunluk = max(strlen($kaynakSessiz), strlen($arananSessiz));
    if(
        $minSessizUzunluk >= 5 &&
        ($minSessizUzunluk / max(1, $maxSessizUzunluk)) >= 0.70 && (
        strpos($kaynakSessiz, $arananSessiz) !== false ||
        strpos($arananSessiz, $kaynakSessiz) !== false
    )){
        return true;
    }

    return $maxSessizUzunluk >= 5 && levenshtein($kaynakSessiz, $arananSessiz) <= max(1, (int)floor($maxSessizUzunluk * 0.18));
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
        ['?ahin', '?irketi', 'BÜYÜKYA?CI', 'Ã„Â®?FTL?K', 'Ç?FTL?K', 'BA??Ã„Â®?', 'ESENBO?A', 'GÖLBA?I', 'G?MAT', 'S?NCAN', 'YEN?KENT'],
        ['Şahin', 'Şirketi', 'BÜYÜKYAÃ„ÂCI', 'ÇİFTLİK', 'ÇİFTLİK', 'BAŞİSKELE', 'ESENBOÃ„ÂA', 'GÖLBAŞI', 'GİMAT', 'SİNCAN', 'YENİKENT'],
        (string)$value
    );
}

function routeMapKey($cikis, $varis): string
{
    return temizMetin($cikis) . '|' . temizMetin($varis);
}

function motorinRevizyonTutari($bazFiyat, $motorinFarkYuzde)
{
    if(abs((float)$motorinFarkYuzde) < 7){
        return 0;
    }

    return round((((float)$bazFiyat * 40) / 100) * ((float)$motorinFarkYuzde / 100), 4);
}

if(isset($_FILES['csv'])){
    unset($_SESSION['excel_skip_diagnostics']);

    $tmp = $_FILES['csv']['tmp_name'];
    $fileName = $_FILES['csv']['name'] ?? '';
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if(in_array($extension, ['xlsx', 'xlsm'], true)){
        try {
            $rows = xlsxSatirlariOku($tmp);

            $_SESSION['csv_rows'] = $rows;
            $_SESSION['csv_file_name'] = $fileName;
            $_SESSION['csv_hakedis_id'] = (int)$hakedis_id;

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
        $_SESSION['csv_hakedis_id'] = (int)$hakedis_id;

        $message = 'CSV başarıyla okundu. Şimdi otomatik hakediş oluşturabilirsiniz.';
    }
}

if(isset($_POST['aktar'])){
    if(isset($_SESSION['csv_rows'])){
        if((int)($_SESSION['csv_hakedis_id'] ?? 0) !== (int)$hakedis_id){
            $errorMessage = true;
            $message = 'Okunan dosya bu hakedişe ait değil. Lütfen dosyayı bu hakediş ekranında tekrar seçip okuyun.';
        } else {
        $db->beginTransaction();

        try {
            $csvRows = $_SESSION['csv_rows'];

            $deleteOld = $db->prepare("DELETE FROM hakedis_satirlari WHERE hakedis_id = ?");
            $deleteOld->execute([$hakedis_id]);

            $motorinFormat = motorinCsvMi($csvRows) && !irsaliyeCsvMi($csvRows);
            $dataRows = [];
            $xlsxTarifeMap = [];
            $skipReasons = [
                'tarih' => 0,
                'irsaliye' => 0,
                'tarife' => 0,
                'motorin' => 0,
                'fiyat' => 0
            ];
            $skipSamples = [];

            $addSkipSample = function($reason, $row) use (&$skipSamples){
                if(count($skipSamples) >= 8){
                    return;
                }

                $skipSamples[] = [
                    'reason' => $reason,
                    'tarih' => $row['tarih'] ?? '-',
                    'irsaliye' => $row['irsaliye'] ?? '-',
                    'cikis' => gorunenMetin($row['cikis'] ?? '-'),
                    'varis' => gorunenMetin($row['varis'] ?? '-'),
                    'birim_fiyat' => ($row['dosya_birim_fiyat'] ?? 0) > 0 ? $row['dosya_birim_fiyat'] : ($row['dosya_guncel'] ?? 0),
                    'motorin_baz' => ($row['dosya_motorin_baz'] ?? 0) > 0 ? $row['dosya_motorin_baz'] : ($row['gunluk_motorin'] ?? 0),
                    'kdv_tutari' => $row['dosya_kdv'] ?? 0,
                    'tevkifat_tutari' => $row['dosya_tevkifat'] ?? 0,
                    'net_tutar' => $row['dosya_net'] ?? 0
                ];
            };

            foreach($csvRows as $index => $row){
                if($motorinFormat){
                    continue;
                }

                if(($row[0] ?? '') === '__TARIFE__'){
                    $tarifeCikis = temizMetin($row[1] ?? '');
                    $tarifeVaris = temizMetin($row[2] ?? '');
                    $tarifeBirim = sayiCevir($row[3] ?? 0);
                    $tarifeMotorin = sayiCevir($row[4] ?? 0);
                    $tarifeTarih = tarihCevir($row[5] ?? '') ?: null;

                    if($tarifeCikis !== '' && $tarifeVaris !== '' && $tarifeBirim > 0 && $tarifeMotorin > 0){
                        $xlsxTarifeMap[routeMapKey($tarifeCikis, $tarifeVaris)] = [
                            'cikis' => gorunenMetin($tarifeCikis),
                            'varis' => gorunenMetin($tarifeVaris),
                            'birim' => $tarifeBirim,
                            'motorin' => $tarifeMotorin,
                            'tarih' => $tarifeTarih
                        ];
                    }

                    continue;
                }

                if($index === 0){
                    continue;
                }

                $tarih = tarihCevir($row[2] ?? '');

                if(!$tarih){
                    if($index > 0){
                        $skipReasons['tarih']++;
                    }
                    continue;
                }

                $hakedisYil = substr((string)($hakedisData['baslangic_tarihi'] ?? ''), 0, 4);
                if($hakedisYil !== '' && substr($tarih, 0, 4) !== $hakedisYil){
                    $tarih = $hakedisYil . substr($tarih, 4);
                }

                $irsaliyeNo = trim($row[1] ?? '');

                if($irsaliyeNo === ''){
                    $skipReasons['irsaliye']++;
                    continue;
                }

                $kisaHakEdisFormati = count($row) <= 10 && isset($row[8]) && !isset($row[12]);
                $cikis = temizMetin($row[3] ?? '');
                $varis = temizMetin($row[4] ?? '');
                $tarifeKaynak = $xlsxTarifeMap[routeMapKey($cikis, $varis)] ?? null;

                if($kisaHakEdisFormati){
                    $dataRows[] = [
                        'tarih' => $tarih,
                        'irsaliye' => $irsaliyeNo,
                        'cikis' => $cikis,
                        'varis' => $varis,
                        'gunluk_motorin' => 0,
                        'dosya_birim_fiyat' => sayiCevir($tarifeKaynak['birim'] ?? $row[5] ?? 0),
                        'dosya_motorin_baz' => sayiCevir($tarifeKaynak['motorin'] ?? 0),
                        'dosya_motorin_fark' => 0,
                        'dosya_motorin_yuzde' => 0,
                        'dosya_zam' => 0,
                        'dosya_guncel' => sayiCevir($row[5] ?? 0),
                        'dosya_kdv' => sayiCevir($row[6] ?? 0),
                        'dosya_tevkifat' => sayiCevir($row[7] ?? 0),
                        'dosya_net' => sayiCevir($row[8] ?? 0),
                        'tarife_baslangic_tarihi' => $tarifeKaynak['tarih'] ?? null
                    ];
                } else {
                    $dataRows[] = [
                        'tarih' => $tarih,
                        'irsaliye' => $irsaliyeNo,
                        'cikis' => $cikis,
                        'varis' => $varis,
                        'gunluk_motorin' => sayiCevir($row[8] ?? 0),
                        'dosya_birim_fiyat' => sayiCevir($row[5] ?? 0),
                        'dosya_motorin_baz' => sayiCevir($row[7] ?? 0),
                        'dosya_motorin_fark' => sayiCevir($row[9] ?? 0),
                        'dosya_motorin_yuzde' => sayiCevir($row[10] ?? 0),
                        'dosya_zam' => sayiCevir($row[11] ?? 0),
                        'dosya_guncel' => sayiCevir($row[12] ?? 0),
                        'dosya_kdv' => sayiCevir($row[13] ?? 0),
                        'dosya_tevkifat' => sayiCevir($row[14] ?? 0),
                        'dosya_net' => sayiCevir($row[15] ?? 0),
                        'tarife_baslangic_tarihi' => null
                    ];
                }
            }

            usort($dataRows, function($a, $b){
                return strcmp($a['tarih'], $b['tarih']);
            });

            if(empty($dataRows)){
                throw new Exception('Bu dosyada irsaliye satırı bulunamadı. Hakediş için SEI ile başlayan irsaliye listesini içeren CSV dosyasını yükleyin.');
            }

            $beklenenDonem = trim((string)($hakedisData['donem'] ?? ''));
            if(preg_match('/^\d{4}-\d{2}$/', $beklenenDonem)){
                $donemDisi = array_filter($dataRows, function($r) use ($beklenenDonem){
                    return substr((string)$r['tarih'], 0, 7) !== $beklenenDonem;
                });
                if(count($donemDisi) === count($dataRows)){
                    throw new Exception('Yüklenen dosyanın tarihleri hakediş dönemiyle uyuşmuyor. Hakediş dönemi: ' . $beklenenDonem . ', dosya aralığı: ' . $dataRows[0]['tarih'] . ' - ' . $dataRows[count($dataRows)-1]['tarih']);
                }
            }

            $tarifeQuery = $db->prepare("
                SELECT *
                FROM tarifeler
                WHERE TRIM(UPPER(firma_adi)) = ?
                AND (
                    sozlesme_id = ?
                    OR sozlesme_no = ?
                    OR (
                        (sozlesme_id IS NULL OR sozlesme_id = 0)
                        AND (sozlesme_no IS NULL OR sozlesme_no = '')
                    )
                )
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

            $autoTarifeInsert = $db->prepare("
                INSERT INTO tarifeler
                (
                    cari_id,
                    sozlesme_id,
                    firma_adi,
                    sozlesme_no,
                    cikis_noktasi,
                    varis_noktasi,
                    sevkiyat_km,
                    birim_fiyat,
                    motorin_baz_fiyati,
                    baslangic_tarihi,
                    tarife_tipi,
                    aciklama,
                    motorin_revize,
                    revizyon_no,
                    aktif
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OTOMATIK', 'OTOMATIK_EKLENDI', 1, 1, 1)
            ");

            $eklenen = 0;
            $atlan = 0;
            $otomatikTarifeSayisi = 0;

            foreach($dataRows as $item){
                $tarih = $item['tarih'];

                $tarifeQuery->execute([
                    temizMetin($firmaAdi),
                    $hakedisSozlesmeId,
                    $sozlesmeNo,
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

                $gunlukMotorin = (float)$item['gunluk_motorin'];

                if($gunlukMotorin <= 0){
                    $motorinQuery->execute([$tarih]);
                    $motorinData = $motorinQuery->fetch(PDO::FETCH_ASSOC);
                    $gunlukMotorin = sayiCevir($motorinData['motorin_fiyati'] ?? 0);
                }

                if(empty($eslesenTarifeler)){
                        $autoBirimFiyat = ($item['dosya_birim_fiyat'] ?? 0) > 0
                            ? (float)$item['dosya_birim_fiyat']
                            : (float)($item['dosya_guncel'] ?? 0);
                        $autoMotorinBaz = ($item['dosya_motorin_baz'] ?? 0) > 0
                            ? (float)$item['dosya_motorin_baz']
                            : $gunlukMotorin;
                    $autoKm = ($autoBirimFiyat > 0 && $autoMotorinBaz > 0)
                        ? round($autoBirimFiyat / $autoMotorinBaz, 2)
                        : 0;

                    if($item['cikis'] !== '' && $item['varis'] !== '' && $autoBirimFiyat > 0 && $autoMotorinBaz > 0){
                        $autoTarifeInsert->execute([
                            $hakedisCariId,
                            $hakedisSozlesmeId,
                            $firmaAdi,
                            $sozlesmeNo,
                            gorunenMetin($item['cikis']),
                            gorunenMetin($item['varis']),
                            $autoKm,
                            $autoBirimFiyat,
                            $autoMotorinBaz,
                            $item['tarife_baslangic_tarihi'] ?: $tarih
                        ]);

                        $autoTarifeId = (int)$db->lastInsertId();
                        $otomatikTarifeSayisi++;
                        $eslesenTarifeler[] = [
                            'id' => $autoTarifeId,
                            'cikis_noktasi' => gorunenMetin($item['cikis']),
                            'varis_noktasi' => gorunenMetin($item['varis']),
                            'sevkiyat_km' => $autoKm,
                            'birim_fiyat' => $autoBirimFiyat,
                            'motorin_baz_fiyati' => $autoMotorinBaz
                        ];
                    } else {
                        $atlan++;
                        $skipReasons['tarife']++;
                        $addSkipSample('Seçili firma/sözleşmede bu tarih ve güzergah için tarife yok', $item);
                        continue;
                    }
                }

                if($gunlukMotorin <= 0){
                    $atlan += count($eslesenTarifeler);
                    $skipReasons['motorin'] += count($eslesenTarifeler);
                    $addSkipSample('Bu tarih için motorin fiyatı bulunamadı', $item);
                    continue;
                }

                foreach($eslesenTarifeler as $tarife){
                    $dosyaTutarlariVar =
                        ($item['dosya_birim_fiyat'] ?? 0) > 0 &&
                        ($item['dosya_guncel'] ?? 0) > 0 &&
                        ($item['dosya_kdv'] ?? 0) > 0 &&
                        ($item['dosya_net'] ?? 0) > 0;

                    $sevkiyatKm = sayiCevir($tarife['sevkiyat_km'] ?? 0);
                    $tarifeBirimFiyat = sayiCevir($tarife['birim_fiyat']);
                    $motorinBaz = ($item['dosya_motorin_baz'] ?? 0) > 0 ? $item['dosya_motorin_baz'] : sayiCevir($tarife['motorin_baz_fiyati']);
                    $motorinRevizeAktif = (int)($tarife['motorin_revize'] ?? 1) === 1;

                    if($sevkiyatKm <= 0 && $motorinBaz > 0 && $tarifeBirimFiyat > 0){
                        $sevkiyatKm = $tarifeBirimFiyat / $motorinBaz;
                    }

                    $birimFiyat = $dosyaTutarlariVar
                        ? $item['dosya_birim_fiyat']
                        : $tarifeBirimFiyat;

                    if($birimFiyat <= 0 || $motorinBaz <= 0){
                        $atlan++;
                        $skipReasons['fiyat']++;
                        $addSkipSample('Birim fiyat veya baz motorin sıfır/boş geldi', $item);
                        continue;
                    }

                    if($dosyaTutarlariVar){
                        $motorinFarkTutari = $item['dosya_motorin_fark'] ?? round($gunlukMotorin - $motorinBaz, 3);
                        $motorinFarkYuzde = $item['dosya_motorin_yuzde'] ?? round(($motorinFarkTutari / $motorinBaz) * 100, 2);
                        $guncelBirimFiyat = $item['dosya_guncel'];
                    } else {
                        $motorinFarkTutari = round($gunlukMotorin - $motorinBaz, 3);
                        $motorinFarkYuzde = round(($motorinFarkTutari / $motorinBaz) * 100, 2);
                        $guncelBirimFiyat = $birimFiyat + ($motorinRevizeAktif ? motorinRevizyonTutari($birimFiyat, $motorinFarkYuzde) : 0);
                    }

                    $zamIndirimTutari = round($guncelBirimFiyat - $birimFiyat, 4);
                    $kdvTutari = round($guncelBirimFiyat * 0.20, 4);
                    $tevkifatTutari = round($kdvTutari * 0.20, 4);
                    $netTutar = round(($guncelBirimFiyat + $kdvTutari) - $tevkifatTutari, 4);

                    $insert->execute([
                        $hakedis_id,
                        $item['irsaliye'] . '-' . $tarife['id'],
                        $tarih,
                        gorunenMetin($item['cikis']),
                        gorunenMetin($item['varis']),
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
            unset($_SESSION['csv_rows'], $_SESSION['csv_file_name'], $_SESSION['csv_hakedis_id']);

            $aktarTamamlandi = true;
            $reasonLabels = [
                'tarih' => 'tarih okunamayan',
                'irsaliye' => 'irsaliye no boş olan',
                'tarife' => 'tarife/firma/sözleşme eşleşmeyen',
                'motorin' => 'motorin fiyatı bulunmayan',
                'fiyat' => 'fiyatı eksik olan'
            ];

            $reasonText = [];

            foreach($skipReasons as $key => $count){
                if($count > 0){
                    $reasonText[] = $count . ' ' . $reasonLabels[$key];
                }
            }
            $message = $eklenen . ' hakediş satırı başarıyla aktarıldı. ' . $atlan . ' satır atlandı. Detay ekranından toplamı kontrol edebilirsiniz.';
            if($otomatikTarifeSayisi > 0){
                $message .= " Nokta Yönetimi'ne " . $otomatikTarifeSayisi . ' yeni otomatik güzergah eklendi; turuncu görünenleri düzelttiğinizde normale döner.';
            }
            if(!empty($reasonText)){
                $message .= ' Atlanma nedeni: ' . implode(', ', $reasonText) . '.';
            }

            if(!empty($skipSamples)){
                $skipDiagnostics = $skipSamples;
                $_SESSION['excel_skip_diagnostics'] = $skipSamples;
            } else {
                unset($_SESSION['excel_skip_diagnostics']);
            }
        } catch(Exception $e){
            $db->rollBack();
            $errorMessage = true;
            $message = 'Hata oluştu: ' . $e->getMessage();
        }
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
<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

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

.skip-diagnostics{
    background:#fff7ed;
    border:1px solid #fed7aa;
    border-radius:10px;
    color:#7c2d12;
    margin:12px 0 0;
    padding:12px;
}

.skip-diagnostics strong{
    display:block;
    margin-bottom:8px;
}

.skip-diagnostics table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}

.skip-diagnostics th,
.skip-diagnostics td{
    border-bottom:1px solid #fed7aa;
    padding:7px 6px;
    text-align:left;
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
                    <?php if(!empty($skipDiagnostics)): ?>
                        <div class="skip-diagnostics">
                            <strong>Atlanan satır örnekleri</strong>
                            <form method="POST">
                                <input type="hidden" name="hakedis_id" value="<?php echo $hakedis_id; ?>">
                                <input type="hidden" name="toplu_nokta_ekle" value="1">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Neden</th>
                                            <th>Seç</th>
                                            <th>Tarih</th>
                                            <th>İrsaliye</th>
                                            <th>Güzergah</th>
                                            <th>Tarife Fiyatı</th>
                                            <th>Motorin</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($skipDiagnostics as $diagIndex => $diag): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($diag['reason']); ?></td>
                                                <td><input type="checkbox" name="secili_satir[]" value="<?php echo $diagIndex; ?>" checked></td>
                                                <td><?php echo htmlspecialchars($diag['tarih']); ?></td>
                                                <td><?php echo htmlspecialchars($diag['irsaliye']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($diag['cikis'] . ' → ' . $diag['varis']); ?>
                                                    <input type="hidden" name="tarih[]" value="<?php echo htmlspecialchars($diag['tarih']); ?>">
                                                    <input type="hidden" name="irsaliye[]" value="<?php echo htmlspecialchars($diag['irsaliye']); ?>">
                                                    <input type="hidden" name="cikis[]" value="<?php echo htmlspecialchars($diag['cikis']); ?>">
                                                    <input type="hidden" name="varis[]" value="<?php echo htmlspecialchars($diag['varis']); ?>">
                                                    <input type="hidden" name="kdv_tutari[]" value="<?php echo htmlspecialchars((string)($diag['kdv_tutari'] ?? 0)); ?>">
                                                    <input type="hidden" name="tevkifat_tutari[]" value="<?php echo htmlspecialchars((string)($diag['tevkifat_tutari'] ?? 0)); ?>">
                                                    <input type="hidden" name="net_tutar[]" value="<?php echo htmlspecialchars((string)($diag['net_tutar'] ?? 0)); ?>">
                                                </td>
                                                <td><input type="text" name="birim_fiyat[]" value="<?php echo htmlspecialchars(number_format((float)($diag['birim_fiyat'] ?? 0), 2, ',', '.')); ?>" style="width:105px;padding:7px;"></td>
                                                <td><input type="text" name="motorin_baz[]" value="<?php echo htmlspecialchars(($diag['motorin_baz'] ?? 0) > 0 ? number_format((float)$diag['motorin_baz'], 3, ',', '.') : ''); ?>" placeholder="Motorin" style="width:88px;padding:7px;"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <button type="submit" style="margin-top:10px;padding:9px 14px;">Toplu Noktaya Ekle</button>
                            </form>
                        </div>
                    <?php endif; ?>
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
