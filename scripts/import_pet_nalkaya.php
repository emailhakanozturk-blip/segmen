<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';

$python = 'C:\\Users\\asus\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
$reader = __DIR__ . '\\xlsx_to_rows.py';

$files = [
    '2026-01' => 'C:\\Users\\asus\\OneDrive\\Desktop\\seğmen su\\Yeni klasör\\2026 HAKEDİŞLER\\nalkaya\\pet nalkaya\\NALKAYA 2026 OCAK ÇİFTLİK DEPO PET GRUBU.xlsx',
    '2026-02' => 'C:\\Users\\asus\\AppData\\Local\\Temp\\NALKAYA 2026 ŞUBAT ÇİFTLİK DEPO PET GRUBU - Kopya.xlsx',
    '2026-03' => 'C:\\Users\\asus\\AppData\\Local\\Temp\\NALKAYA 2026 MART ÇİFTLİK DEPO PET GRUBU.xlsx',
    '2026-04' => 'C:\\Users\\asus\\AppData\\Local\\Temp\\NALKAYA 2026 NİSAN ÇİFTLİK DEPO PET GRUBU.xlsx',
];

function money_value($value): float
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace(["₺", "TL", " ", "\xc2\xa0"], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

function date_value($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('/', '.', $value);
    $parts = explode('.', $value);
    if (count($parts) !== 3) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
}

function read_rows(string $python, string $reader, string $file): array
{
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($reader) . ' ' . escapeshellarg($file);
    $json = shell_exec($cmd);
    $rows = json_decode((string)$json, true);
    if (!is_array($rows)) {
        throw new RuntimeException('Excel okunamadı: ' . $file);
    }
    return $rows;
}

function route_key(string $cikis, string $varis): string
{
    return mb_strtoupper(trim($cikis), 'UTF-8') . '|' . mb_strtoupper(trim($varis), 'UTF-8');
}

$cari = $db->query("SELECT * FROM cariler WHERE id = 2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sozlesme = $db->query("SELECT * FROM sozlesmeler WHERE sozlesme_no = 'PET-0001' AND cari_id = 2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$cari || !$sozlesme) {
    throw new RuntimeException('Nalkaya / PET-0001 bulunamadı.');
}

$firmaAdi = 'Nalkaya Limited Şirketi';
$db->prepare("UPDATE cariler SET firma_adi = ?, cari_unvan = ? WHERE id = ?")->execute([$firmaAdi, $firmaAdi, (int)$cari['id']]);

$tarifeSelect = $db->prepare("
    SELECT id FROM tarifeler
    WHERE sozlesme_id = ? AND cikis_noktasi = ? AND varis_noktasi = ?
    AND COALESCE(arac_tipi, '') = 'PET' AND baslangic_tarihi = ?
    LIMIT 1
");
$tarifeInsert = $db->prepare("
    INSERT INTO tarifeler
    (cari_id, sozlesme_id, firma_adi, sozlesme_no, cikis_noktasi, varis_noktasi, arac_tipi, sevkiyat_km, birim_fiyat, motorin_baz_fiyati, baslangic_tarihi, tarife_tipi, aciklama, motorin_revize, revizyon_no, aktif)
    VALUES (?, ?, ?, ?, ?, ?, 'PET', ?, ?, ?, ?, 'PET', 'PET otomatik aktarım', 1, 1, 1)
");
$tarifeUpdate = $db->prepare("
    UPDATE tarifeler
    SET cari_id = ?, firma_adi = ?, sozlesme_no = ?, sevkiyat_km = ?, birim_fiyat = ?, motorin_baz_fiyati = ?, aktif = 1
    WHERE id = ?
");

$hakedisSelect = $db->prepare("SELECT id FROM hakedisler WHERE sozlesme_id = ? AND donem = ? LIMIT 1");
$hakedisInsert = $db->prepare("
    INSERT INTO hakedisler
    (hakedis_no, cari_id, sozlesme_id, baslangic_tarihi, bitis_tarihi, donem, durum, aciklama)
    VALUES (?, ?, ?, ?, ?, ?, 'taslak', 'PET otomatik aktarım')
");
$hakedisUpdate = $db->prepare("UPDATE hakedisler SET cari_id = ?, baslangic_tarihi = ?, bitis_tarihi = ?, hakedis_no = ? WHERE id = ?");
$deleteLines = $db->prepare("DELETE FROM hakedis_satirlari WHERE hakedis_id = ?");

$lineInsert = $db->prepare("
    INSERT INTO hakedis_satirlari
    (hakedis_id, irsaliye_no, tasima_tarihi, cikis_noktasi, varis_noktasi, birim_fiyat, satir_toplam, motorin_baz_fiyati, gunluk_motorin_fiyati, motorin_fark_tutari, motorin_fark_yuzde, zam_indirim_tutari, guncel_birim_fiyat, kdv_tutari, tevkifat_tutari, net_tutar)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
");
$motorinQuery = $db->prepare("SELECT motorin_fiyati FROM motorin_fiyatlari WHERE tarih <= ? AND motorin_fiyati > 0 ORDER BY tarih DESC LIMIT 1");
$totalQuery = $db->prepare("SELECT SUM(guncel_birim_fiyat) toplam, SUM(kdv_tutari) kdv, SUM(tevkifat_tutari) tevkifat, SUM(net_tutar) net FROM hakedis_satirlari WHERE hakedis_id = ?");
$hakedisTotalUpdate = $db->prepare("UPDATE hakedisler SET toplam_tutar = ?, kdv_tutar = ?, tevkifat_tutar = ?, genel_toplam = ?, net_tutar = ? WHERE id = ?");

$report = [];

$db->beginTransaction();
try {
    foreach ($files as $period => $file) {
        if (!is_file($file)) {
            throw new RuntimeException('Dosya yok: ' . $file);
        }

        $rows = read_rows($python, $reader, $file);
        $tarifeMap = [];
        $tarifeCount = 0;

        foreach ($rows as $row) {
            if (($row[0] ?? '') !== '__TARIFE__') {
                continue;
            }

            $cikis = trim((string)($row[1] ?? ''));
            $varis = trim((string)($row[2] ?? ''));
            $birim = money_value($row[3] ?? 0);
            $motorin = money_value($row[4] ?? 0);
            $baslangic = date_value($row[5] ?? '') ?: ($period . '-01');

            if ($cikis === '' || $varis === '' || $birim <= 0 || $motorin <= 0) {
                continue;
            }

            $km = round($birim / $motorin, 2);
            $tarifeMap[route_key($cikis, $varis)] = ['birim' => $birim, 'motorin' => $motorin];

            $tarifeSelect->execute([(int)$sozlesme['id'], $cikis, $varis, $baslangic]);
            $existingId = (int)($tarifeSelect->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $tarifeUpdate->execute([(int)$cari['id'], $firmaAdi, $sozlesme['sozlesme_no'], $km, $birim, $motorin, $existingId]);
            } else {
                $tarifeInsert->execute([(int)$cari['id'], (int)$sozlesme['id'], $firmaAdi, $sozlesme['sozlesme_no'], $cikis, $varis, $km, $birim, $motorin, $baslangic]);
            }
            $tarifeCount++;
        }

        $start = $period . '-01';
        $end = date('Y-m-t', strtotime($start));
        $hakedisNo = 'PET-0001-' . $period;
        $hakedisSelect->execute([(int)$sozlesme['id'], $period]);
        $hakedisId = (int)($hakedisSelect->fetchColumn() ?: 0);

        if ($hakedisId > 0) {
            $hakedisUpdate->execute([(int)$cari['id'], $start, $end, $hakedisNo, $hakedisId]);
        } else {
            $hakedisInsert->execute([$hakedisNo, (int)$cari['id'], (int)$sozlesme['id'], $start, $end, $period]);
            $hakedisId = (int)$db->lastInsertId();
        }
        $deleteLines->execute([$hakedisId]);

        $lineCount = 0;
        foreach ($rows as $row) {
            if (($row[0] ?? '') === '__TARIFE__') {
                continue;
            }

            $date = date_value($row[2] ?? '');
            if (!$date || substr($date, 0, 7) !== $period) {
                continue;
            }

            $irsaliye = trim((string)($row[1] ?? ''));
            $cikis = trim((string)($row[3] ?? ''));
            $varis = trim((string)($row[4] ?? ''));
            $base = money_value($row[5] ?? 0);
            $kdv = money_value($row[6] ?? 0);
            $tevkifat = money_value($row[7] ?? 0);
            $net = money_value($row[8] ?? 0);

            if ($irsaliye === '' || $cikis === '' || $varis === '' || $base <= 0) {
                continue;
            }

            $tarife = $tarifeMap[route_key($cikis, $varis)] ?? null;
            $bazMotorin = (float)($tarife['motorin'] ?? 0);
            $motorinQuery->execute([$date]);
            $gunlukMotorin = money_value($motorinQuery->fetchColumn() ?: 0);
            $fark = ($bazMotorin > 0 && $gunlukMotorin > 0) ? round($gunlukMotorin - $bazMotorin, 3) : 0;
            $farkYuzde = ($bazMotorin > 0) ? round(($fark / $bazMotorin) * 100, 2) : 0;

            $lineInsert->execute([$hakedisId, $irsaliye, $date, $cikis, $varis, $base, $base, $bazMotorin, $gunlukMotorin, $fark, $farkYuzde, $base, $kdv, $tevkifat, $net]);
            $lineCount++;
        }

        $totalQuery->execute([$hakedisId]);
        $totals = $totalQuery->fetch(PDO::FETCH_ASSOC) ?: [];
        $toplam = (float)($totals['toplam'] ?? 0);
        $kdvToplam = (float)($totals['kdv'] ?? 0);
        $tevkifatToplam = (float)($totals['tevkifat'] ?? 0);
        $netToplam = (float)($totals['net'] ?? 0);
        $hakedisTotalUpdate->execute([$toplam, $kdvToplam, $tevkifatToplam, $toplam + $kdvToplam, $netToplam, $hakedisId]);

        $report[] = [$period, $hakedisId, $tarifeCount, $lineCount, $toplam, $netToplam];
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

foreach ($report as $row) {
    echo implode("\t", $row) . PHP_EOL;
}
