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
$error = false;
$editId = (int)($_GET['edit'] ?? 0);

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

function paraGoster($value, int $decimals = 2): string
{
    return '₺' . number_format((float)$value, $decimals, ',', '.');
}

function sayiGoster($value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals, ',', '.');
}

function temizNokta($value): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value);
    return mb_strtoupper($value, 'UTF-8');
}

function gorunenNokta($value): string
{
    return strtr((string)$value, [
        '??FTL?K' => 'ÇİFTLİK',
        'B?Y?KYA?CI' => 'BÜYÜKYAĞCI',
        'G?ZELCEKALE' => 'GÜZELCEKALE',
        'BA??Ç?' => 'BAĞİÇİ',
        'S?NCAN' => 'SİNCAN',
    ]);
}

function revizeBirimFiyat(float $baslangicBirim, float $baslangicMotorin, float $hedefMotorin): float
{
    if($baslangicBirim <= 0 || $baslangicMotorin <= 0 || $hedefMotorin <= 0){
        return 0;
    }

    $farkOrani = ($hedefMotorin - $baslangicMotorin) / $baslangicMotorin;

    if(abs($farkOrani) < 0.07){
        return round($baslangicBirim, 2);
    }

    return round($baslangicBirim + (($baslangicBirim * 0.40) * $farkOrani), 2);
}

function farkYuzde(float $baslangicMotorin, float $hedefMotorin): float
{
    return $baslangicMotorin > 0 ? (($hedefMotorin - $baslangicMotorin) / $baslangicMotorin) * 100 : 0;
}

function ayAdi(int $ay): string
{
    $aylar = [
        1 => 'Ocak',
        2 => 'Şubat',
        3 => 'Mart',
        4 => 'Nisan',
        5 => 'Mayıs',
        6 => 'Haziran',
        7 => 'Temmuz',
        8 => 'Ağustos',
        9 => 'Eylül',
        10 => 'Ekim',
        11 => 'Kasım',
        12 => 'Aralık',
    ];

    return $aylar[$ay] ?? (string)$ay;
}

function ayMotorinFiyatlari(PDO $db, int $yil): array
{
    $query = $db->prepare("
        SELECT tarih, motorin_fiyati
        FROM motorin_fiyatlari
        WHERE YEAR(tarih) = ?
        AND motorin_fiyati > 0
        ORDER BY tarih ASC, id ASC
    ");
    $query->execute([$yil]);

    $aylar = [];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $ay = (int)date('n', strtotime($row['tarih']));
        $aylar[$ay] = [
            'tarih' => $row['tarih'],
            'motorin' => (float)$row['motorin_fiyati'],
        ];
    }

    return $aylar;
}

function motorinFiyatlariYil(PDO $db, int $yil): array
{
    $query = $db->prepare("
        SELECT tarih, motorin_fiyati
        FROM motorin_fiyatlari
        WHERE YEAR(tarih) = ?
        AND motorin_fiyati > 0
        ORDER BY tarih ASC, id ASC
    ");
    $query->execute([$yil]);

    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function tarihNorm($tarih, string $varsayilan): string
{
    $tarih = trim((string)$tarih);
    return $tarih === '' || $tarih === '0000-00-00' ? $varsayilan : $tarih;
}

function oncekiGun(string $tarih): string
{
    return date('Y-m-d', strtotime($tarih . ' -1 day'));
}

function routeKey(array $row): string
{
    return implode('|', [
        (int)($row['cari_id'] ?? 0),
        (int)($row['sozlesme_id'] ?? 0),
        temizNokta($row['cikis_noktasi'] ?? ''),
        temizNokta($row['varis_noktasi'] ?? ''),
        temizNokta($row['tarife_tipi'] ?? 'NORMAL'),
    ]);
}

function revizyonHesapla(float $baslangicBirim, float $baslangicMotorin, float $ayMotorin): array
{
    if($baslangicBirim <= 0 || $baslangicMotorin <= 0 || $ayMotorin <= 0){
        return [
            'oran' => 0,
            'zam' => 0,
            'birim' => 0,
            'revize' => false,
        ];
    }

    $oran = round(farkYuzde($baslangicMotorin, $ayMotorin), 2);
    $revize = abs($oran) >= 7;
    $zam = $revize ? (($baslangicBirim * 0.40) * ($oran / 100)) : 0;

    return [
        'oran' => $oran,
        'zam' => $zam,
        'birim' => $baslangicBirim + $zam,
        'revize' => $revize,
    ];
}

function fiyatDonemleriOlustur(array $baseRow, array $motorinRows, int $yil): array
{
    $baseStart = tarihNorm($baseRow['baslangic_tarihi'] ?? null, $yil . '-01-01');
    $yearStart = $yil . '-01-01';
    $yearEnd = $yil . '-12-31';
    $start = max($baseStart, $yearStart);
    $km = (float)($baseRow['_km'] ?? $baseRow['sevkiyat_km'] ?? 0);
    $currentBirim = (float)($baseRow['_baslangic_birim'] ?? $baseRow['birim_fiyat'] ?? 0);
    $currentMotorin = (float)($baseRow['_baslangic_motorin'] ?? $baseRow['motorin_baz_fiyati'] ?? 0);

    if($km <= 0 && $currentBirim > 0 && $currentMotorin > 0){
        $km = round($currentBirim / $currentMotorin, 2);
    }

    if($currentBirim <= 0 && $km > 0 && $currentMotorin > 0){
        $currentBirim = round($km * $currentMotorin, 2);
    }

    if($currentBirim <= 0 || $currentMotorin <= 0){
        return [];
    }

    $periods = [[
        'revizyon_no' => 1,
        'baslangic' => $start,
        'bitis' => null,
        'motorin' => $currentMotorin,
        'birim' => $currentBirim,
        'oran' => 0,
        'zam' => 0,
        'revize' => false,
        'label' => 'Baz fiyat',
    ]];

    foreach($motorinRows as $motorinRow){
        $tarih = (string)$motorinRow['tarih'];

        if($tarih < $start || $tarih > $yearEnd){
            continue;
        }

        $motorin = (float)$motorinRow['motorin_fiyati'];
        $farkOrani = $currentMotorin > 0 ? (($motorin - $currentMotorin) / $currentMotorin) : 0;
        $oran = round($farkOrani * 100, 2);

        if(abs($farkOrani) < 0.07){
            continue;
        }

        $sonIndex = count($periods) - 1;
        if($periods[$sonIndex]['baslangic'] === $tarih){
            continue;
        }

        $periods[$sonIndex]['bitis'] = oncekiGun($tarih);
        $zam = (($currentBirim * 0.40) * $farkOrani);
        $currentBirim = round($currentBirim + $zam, 2);
        $currentMotorin = $motorin;

        $periods[] = [
            'revizyon_no' => count($periods) + 1,
            'baslangic' => $tarih,
            'bitis' => null,
            'motorin' => $currentMotorin,
            'birim' => $currentBirim,
            'oran' => $oran,
            'zam' => $zam,
            'revize' => true,
            'label' => 'Revize ' . count($periods),
        ];
    }

    return $periods;
}

function kayitliTarifeDonemleri(array $rows, int $yil): array
{
    usort($rows, function($a, $b){
        return strcmp(tarihNorm($a['baslangic_tarihi'] ?? null, '0000-00-00'), tarihNorm($b['baslangic_tarihi'] ?? null, '0000-00-00'))
            ?: ((int)($a['revizyon_no'] ?? 1) <=> (int)($b['revizyon_no'] ?? 1))
            ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });

    $donemler = [];
    foreach($rows as $index => $row){
        $baslangic = tarihNorm($row['baslangic_tarihi'] ?? null, $yil . '-01-01');
        $bitis = tarihNorm($row['bitis_tarihi'] ?? null, '');
        if($bitis === '' && isset($rows[$index + 1])){
            $bitis = oncekiGun(tarihNorm($rows[$index + 1]['baslangic_tarihi'] ?? null, $baslangic));
        }

        $birim = (float)($row['birim_fiyat'] ?? 0);
        $motorin = (float)($row['motorin_baz_fiyati'] ?? 0);
        $oncekiBirim = $index > 0 ? (float)($rows[$index - 1]['birim_fiyat'] ?? 0) : $birim;

        $donemler[] = [
            'id' => (int)($row['id'] ?? 0),
            'baslangic' => $baslangic,
            'bitis' => $bitis ?: null,
            'motorin' => $motorin,
            'birim' => $birim,
            'oran' => $index > 0 && (float)($rows[$index - 1]['motorin_baz_fiyati'] ?? 0) > 0 ? farkYuzde((float)$rows[$index - 1]['motorin_baz_fiyati'], $motorin) : 0,
            'zam' => $birim - $oncekiBirim,
            'revize' => $index > 0,
        ];
    }

    return $donemler;
}

function ilkRevizeMotorin(PDO $db, float $baslangicMotorin, ?string $baslangicTarihi): ?array
{
    if($baslangicMotorin <= 0){
        return null;
    }

    $query = $db->prepare("
        SELECT tarih, motorin_fiyati
        FROM motorin_fiyatlari
        WHERE motorin_fiyati > 0
        AND (? IS NULL OR tarih >= ?)
        ORDER BY tarih ASC, id ASC
    ");
    $query->execute([$baslangicTarihi, $baslangicTarihi]);

    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $motorin = (float)$row['motorin_fiyati'];

        if(abs(round(farkYuzde($baslangicMotorin, $motorin), 2)) >= 7){
            return $row;
        }
    }

    return null;
}

function revizyonlariKaydet(PDO $db, array $groups, int $yil): int
{
    $motorinRows = motorinFiyatlariYil($db, $yil);

    if(empty($motorinRows)){
        return 0;
    }

    $inserted = 0;
    $db->beginTransaction();

    try {
        foreach($groups as $group){
            $base = $group['base'];
            $periods = fiyatDonemleriOlustur($base, $motorinRows, $yil);

            if(count($periods) <= 1){
                continue;
            }

            $delete = $db->prepare("
                DELETE FROM tarifeler
                WHERE id <> ?
                AND cari_id <=> ?
                AND sozlesme_id <=> ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
                AND baslangic_tarihi BETWEEN ? AND ?
            ");
            $delete->execute([
                (int)$base['id'],
                $base['cari_id'] ?? null,
                $base['sozlesme_id'] ?? null,
                $base['cikis_noktasi'],
                $base['varis_noktasi'],
                $base['tarife_tipi'] ?? 'NORMAL',
                $yil . '-01-01',
                $yil . '-12-31',
            ]);

            $baseBitis = $periods[0]['bitis'];
            $baseUpdate = $db->prepare("
                UPDATE tarifeler
                SET
                    bitis_tarihi = ?,
                    revizyon_no = 1,
                    aktif = ?
                WHERE id = ?
            ");
            $baseUpdate->execute([
                $baseBitis,
                $baseBitis ? 0 : 1,
                (int)$base['id'],
            ]);

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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            for($i = 1; $i < count($periods); $i++){
                $period = $periods[$i];
                $insert->execute([
                    $base['cari_id'] ?? null,
                    $base['sozlesme_id'] ?? null,
                    $base['firma_goster'] ?? $base['firma_adi'],
                    $base['sozlesme_goster'] ?? $base['sozlesme_no'],
                    $base['cikis_noktasi'],
                    $base['varis_noktasi'],
                    $base['arac_tipi'] ?? null,
                    $base['_km'] ?? $base['sevkiyat_km'] ?? 0,
                    $base['tarife_tipi'] ?? 'NORMAL',
                    $period['birim'],
                    $period['motorin'],
                    $base['motorin_revize'] ?? 1,
                    $period['baslangic'],
                    $period['bitis'],
                    $period['revizyon_no'],
                    'Nokta Yönetimi revizyonu',
                    $period['bitis'] ? 0 : 1,
                ]);
                $inserted++;
            }
        }

        $db->commit();
    } catch(Throwable $exception){
        $db->rollBack();
        throw $exception;
    }

    return $inserted;
}

$cariler = $db->query("
    SELECT id, firma_adi
    FROM cariler
    ORDER BY firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sozlesmeler = $db->query("
    SELECT id, cari_id, sozlesme_no, sozlesme_tutari
    FROM sozlesmeler
    ORDER BY sozlesme_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

$noktalar = $db->query("
    SELECT nokta_adi
    FROM noktalar
    WHERE COALESCE(durum, aktif, 1) = 1
    ORDER BY nokta_adi ASC
")->fetchAll(PDO::FETCH_COLUMN);

if(isset($_GET['sil'])){
    $silId = (int)$_GET['sil'];

    if($silId > 0){
        $delete = $db->prepare("DELETE FROM tarifeler WHERE id = ?");
        $delete->execute([$silId]);
    }

    header("Location: nokta-yonetimi.php");
    exit;
}

if(isset($_POST['mukerrer_toplu_sil']) || isset($_POST['mukerrer_birlestir'])){
    $silinecekler = array_map('intval', $_POST['sil_id'] ?? []);
    $silinecekler = array_values(array_filter($silinecekler, fn($id) => $id > 0));

    if(!empty($silinecekler)){
        $placeholders = implode(',', array_fill(0, count($silinecekler), '?'));
        $delete = $db->prepare("DELETE FROM tarifeler WHERE id IN ($placeholders)");
        $delete->execute($silinecekler);
    }

    $redirect = 'nokta-yonetimi.php?cari_id=' . (int)($_POST['current_cari_id'] ?? 0) . '&sozlesme_id=' . (int)($_POST['current_sozlesme_id'] ?? 0) . '&revizyon_yili=' . (int)($_POST['current_yil'] ?? date('Y'));
    header("Location: " . $redirect);
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        $id = (int)($_POST['id'] ?? 0);
        $cariIdPost = (int)($_POST['cari_id'] ?? 0);
        $sozlesmeIdPost = (int)($_POST['sozlesme_id'] ?? 0);
        $cikis = temizNokta($_POST['cikis_noktasi'] ?? '');
        $varis = temizNokta($_POST['varis_noktasi'] ?? '');
        $urunGrubu = temizNokta($_POST['tarife_tipi'] ?? 'DAMACANA');
        $km = sayiCevir($_POST['sevkiyat_km'] ?? 0);
        $baslangicMotorin = sayiCevir($_POST['motorin_baz_fiyati'] ?? 0);
        $baslangicBirim = sayiCevir($_POST['birim_fiyat'] ?? 0);
        $baslangicTarihi = trim((string)($_POST['baslangic_tarihi'] ?? '')) ?: date('Y-m-d');

        if($cariIdPost <= 0 || $sozlesmeIdPost <= 0){
            throw new Exception('Firma ve sözleşme seçimi zorunludur.');
        }

        if($cikis === '' || $varis === ''){
            throw new Exception('Çıkış ve varış noktası zorunludur.');
        }

        if($baslangicMotorin <= 0){
            throw new Exception('Başlangıç motorin fiyatı zorunludur.');
        }

        if($km <= 0 && $baslangicBirim > 0){
            $km = round($baslangicBirim / $baslangicMotorin, 2);
        }

        if($km <= 0 && $baslangicBirim <= 0){
            throw new Exception('KM veya başlangıç birim fiyat alanlarından en az biri doğru girilmelidir.');
        }

        $sozlesmeQuery = $db->prepare("
            SELECT s.sozlesme_no, c.firma_adi
            FROM sozlesmeler s
            LEFT JOIN cariler c ON c.id = s.cari_id
            WHERE s.id = ?
            AND s.cari_id = ?
            LIMIT 1
        ");
        $sozlesmeQuery->execute([$sozlesmeIdPost, $cariIdPost]);
        $sozlesmeData = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

        if(!$sozlesmeData){
            throw new Exception('Seçilen sözleşme firma ile eşleşmiyor.');
        }

        if($id <= 0){
            $duplicate = $db->prepare("
                SELECT id
                FROM tarifeler
                WHERE cari_id = ?
                AND sozlesme_id = ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
                AND COALESCE(revizyon_no, 1) = 1
                ORDER BY id ASC
                LIMIT 1
            ");
            $duplicate->execute([$cariIdPost, $sozlesmeIdPost, $cikis, $varis, $urunGrubu]);
            $id = (int)($duplicate->fetchColumn() ?: 0);
        }

        if($id > 0){
            $update = $db->prepare("
                UPDATE tarifeler
                SET
                    cari_id = ?,
                    sozlesme_id = ?,
                    firma_adi = ?,
                    sozlesme_no = ?,
                    cikis_noktasi = ?,
                    varis_noktasi = ?,
                    sevkiyat_km = ?,
                    birim_fiyat = ?,
                    motorin_baz_fiyati = ?,
                    baslangic_tarihi = ?,
                    tarife_tipi = ?,
                    aciklama = NULL,
                    aktif = 1
                WHERE id = ?
            ");
            $update->execute([
                $cariIdPost,
                $sozlesmeIdPost,
                $sozlesmeData['firma_adi'],
                $sozlesmeData['sozlesme_no'],
                $cikis,
                $varis,
                $km,
                $baslangicBirim,
                $baslangicMotorin,
                $baslangicTarihi,
                $urunGrubu,
                $id
            ]);
            $message = 'Güzergah güncellendi.';
        } else {
            $insert = $db->prepare("
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
                    motorin_revize,
                    revizyon_no,
                    aktif
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1)
            ");
            $insert->execute([
                $cariIdPost,
                $sozlesmeIdPost,
                $sozlesmeData['firma_adi'],
                $sozlesmeData['sozlesme_no'],
                $cikis,
                $varis,
                $km,
                $baslangicBirim,
                $baslangicMotorin,
                $baslangicTarihi,
                $urunGrubu
            ]);
            $message = 'Güzergah eklendi.';
        }
    } catch(Throwable $exception){
        $error = true;
        $message = $exception->getMessage();
    }
}

$editing = null;
if($editId > 0){
    $editQuery = $db->prepare("SELECT * FROM tarifeler WHERE id = ? LIMIT 1");
    $editQuery->execute([$editId]);
    $editing = $editQuery->fetch(PDO::FETCH_ASSOC) ?: null;
}

$cariId = (int)($_GET['cari_id'] ?? 0);
$sozlesmeId = (int)($_GET['sozlesme_id'] ?? 0);
$arama = trim((string)($_GET['arama'] ?? ''));
$revizyonYili = (int)($_GET['revizyon_yili'] ?? date('Y'));
if($revizyonYili < 2020 || $revizyonYili > 2035){
    $revizyonYili = (int)date('Y');
}

$cariAdlari = [];
foreach($cariler as $cari){
    $cariAdlari[(int)$cari['id']] = $cari['firma_adi'];
}

if($sozlesmeId <= 0 && !empty($sozlesmeler)){
    foreach($sozlesmeler as $sozlesme){
        if($cariId <= 0 || (int)$sozlesme['cari_id'] === $cariId){
            $sozlesmeId = (int)$sozlesme['id'];
            $cariId = (int)$sozlesme['cari_id'];
            break;
        }
    }
}

foreach($sozlesmeler as $sozlesme){
    if((int)$sozlesme['id'] === $sozlesmeId){
        $cariId = (int)$sozlesme['cari_id'];
        break;
    }
}
$motorinRows = motorinFiyatlariYil($db, $revizyonYili);

$sonMotorinRow = $db->query("
    SELECT tarih, motorin_fiyati
    FROM motorin_fiyatlari
    WHERE motorin_fiyati > 0
    ORDER BY tarih DESC, id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: ['tarih' => null, 'motorin_fiyati' => 0];
$sonMotorin = (float)$sonMotorinRow['motorin_fiyati'];

$rows = $db->query("
    SELECT
        t.*,
        COALESCE(c.firma_adi, t.firma_adi) AS firma_goster,
        COALESCE(s.sozlesme_no, t.sozlesme_no) AS sozlesme_goster
    FROM tarifeler t
    LEFT JOIN cariler c ON c.id = t.cari_id
    LEFT JOIN sozlesmeler s ON s.id = t.sozlesme_id
    WHERE t.cikis_noktasi IS NOT NULL
    AND t.varis_noktasi IS NOT NULL
    ORDER BY firma_goster ASC, sozlesme_goster ASC, t.cikis_noktasi ASC, t.varis_noktasi ASC, t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$groups = [];

foreach($rows as $row){
    $baslangicBirim = (float)($row['birim_fiyat'] ?? 0);
    $baslangicMotorin = (float)($row['motorin_baz_fiyati'] ?? 0);
    $km = (float)($row['sevkiyat_km'] ?? 0);

    if($km <= 0 && $baslangicBirim > 0 && $baslangicMotorin > 0){
        $km = round($baslangicBirim / $baslangicMotorin, 2);
    }

    $row['_km'] = $km;
    $row['_baslangic_motorin'] = $baslangicMotorin;
    $row['_baslangic_birim'] = $baslangicBirim;

    $key = routeKey($row);
    if(!isset($groups[$key])){
        $groups[$key] = [
            'base' => $row,
            'rows' => [],
        ];
    }

    $groups[$key]['rows'][] = $row;

    $currentBase = $groups[$key]['base'];
    $rowRev = (int)($row['revizyon_no'] ?? 1);
    $currentRev = (int)($currentBase['revizyon_no'] ?? 1);
    $rowStart = tarihNorm($row['baslangic_tarihi'] ?? null, '0000-01-01');
    $currentStart = tarihNorm($currentBase['baslangic_tarihi'] ?? null, '0000-01-01');

    if($rowRev < $currentRev || ($rowRev === $currentRev && $rowStart < $currentStart)){
        $groups[$key]['base'] = $row;
    }
}

$guzergahlar = [];

foreach($groups as $group){
    $base = $group['base'];
    $firmaUygun = $cariId <= 0 || (int)($base['cari_id'] ?? 0) === $cariId;
    $sozlesmeUygun = $sozlesmeId <= 0 || (int)($base['sozlesme_id'] ?? 0) === $sozlesmeId;
    $aramaMetni = mb_strtolower(($base['firma_goster'] ?? '') . ' ' . ($base['sozlesme_goster'] ?? '') . ' ' . ($base['cikis_noktasi'] ?? '') . ' ' . ($base['varis_noktasi'] ?? ''), 'UTF-8');
    $aramaUygun = $arama === '' || mb_strpos($aramaMetni, mb_strtolower($arama, 'UTF-8')) !== false;

    if(!$firmaUygun || !$sozlesmeUygun || !$aramaUygun){
        continue;
    }

    $base['_fiyat_donemleri'] = kayitliTarifeDonemleri($group['rows'], $revizyonYili);
    $guzergahlar[] = $base;
}

if(isset($_GET['revize']) && $message === ''){
    try {
        $eklenenRevizyon = revizyonlariKaydet($db, array_map(fn($row) => ['base' => $row], $guzergahlar), $revizyonYili);
        foreach($guzergahlar as &$guzergah){
            $guzergah['_fiyat_donemleri'] = fiyatDonemleriOlustur($guzergah, $motorinRows, $revizyonYili);
        }
        unset($guzergah);
        $message = $revizyonYili . ' yılı için ' . $eklenenRevizyon . ' revizyon dönemi oluşturuldu. Hakedişler artık taşıma tarihine göre bu dönem fiyatını çeker.';
    } catch(Throwable $exception){
        $error = true;
        $message = 'Revizyon hatası: ' . $exception->getMessage();
    }
}

$maxDonemSayisi = 1;
foreach($guzergahlar as $row){
    $maxDonemSayisi = max($maxDonemSayisi, count($row['_fiyat_donemleri'] ?? []));
}

$mukerrerler = $db->query("
    SELECT
        t.*,
        COALESCE(c.firma_adi, t.firma_adi) AS firma_goster,
        COALESCE(s.sozlesme_no, t.sozlesme_no) AS sozlesme_goster
    FROM tarifeler t
    LEFT JOIN cariler c ON c.id = t.cari_id
    LEFT JOIN sozlesmeler s ON s.id = t.sozlesme_id
    INNER JOIN (
        SELECT
            MIN(id) AS keep_id,
            cari_id,
            sozlesme_id,
            cikis_noktasi,
            varis_noktasi,
            tarife_tipi,
            COUNT(*) AS adet
        FROM tarifeler
        WHERE sozlesme_id IS NOT NULL
        AND COALESCE(revizyon_no, 1) = 1
        GROUP BY cari_id, sozlesme_id, cikis_noktasi, varis_noktasi, tarife_tipi
        HAVING adet > 1
    ) d ON d.cari_id <=> t.cari_id
        AND d.sozlesme_id <=> t.sozlesme_id
        AND d.cikis_noktasi <=> t.cikis_noktasi
        AND d.varis_noktasi <=> t.varis_noktasi
        AND d.tarife_tipi <=> t.tarife_tipi
    WHERE t.id <> d.keep_id
    ORDER BY firma_goster ASC, sozlesme_goster ASC, t.cikis_noktasi ASC, t.varis_noktasi ASC, t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$mukerrerler = array_values(array_filter($mukerrerler, function($row) use ($cariId, $sozlesmeId){
    if($cariId > 0 && (int)($row['cari_id'] ?? 0) !== $cariId){
        return false;
    }
    if($sozlesmeId > 0 && (int)($row['sozlesme_id'] ?? 0) !== $sozlesmeId){
        return false;
    }
    return true;
}));

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Nokta Yönetimi</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.main{
    max-width:calc(100vw - 260px);
    overflow-x:hidden;
    box-sizing:border-box;
}
.topbar{
    margin-bottom:16px;
}
.panel{
    background:white;
    padding:16px;
    border-radius:10px;
    border:1px solid #e7eaf0;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
    margin-bottom:14px;
    max-width:100%;
    overflow:hidden;
    box-sizing:border-box;
}
.section-title{
    margin:0 0 12px;
    font-size:16px;
    font-weight:800;
    color:#111827;
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(130px,1fr));
    gap:10px;
    align-items:end;
}
label{
    display:block;
    margin-bottom:5px;
    font-weight:bold;
    font-size:12px;
    color:#374151;
}
input,
select{
    width:100%;
    padding:9px 10px;
    border:1px solid #d1d5db;
    border-radius:8px;
    box-sizing:border-box;
    font-size:12px;
}
.btn,
button{
    background:#111827;
    color:white;
    border:none;
    padding:8px 10px;
    border-radius:7px;
    cursor:pointer;
    font-weight:bold;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:10px;
    white-space:normal;
}
button{
    background:#2563eb;
}
.btn-red{
    background:#dc2626;
}
.btn-muted{
    background:#64748b;
}
.alert{
    padding:12px 14px;
    border-radius:9px;
    margin-bottom:14px;
    font-weight:bold;
    background:#dcfce7;
    color:#166534;
}
.alert.error{
    background:#fee2e2;
    color:#991b1b;
}
.filters{
    display:grid;
    grid-template-columns:minmax(220px,1fr) 110px auto auto;
    gap:8px;
    align-items:end;
    margin-bottom:12px;
}
.table-wrap{
    width:100%;
    overflow:auto;
    border:1px solid #e7eaf0;
    border-radius:10px;
    box-sizing:border-box;
    background:#fff;
}
table{
    width:100%;
    min-width:0;
    table-layout:fixed;
    border-collapse:separate;
    border-spacing:0;
}
table th{
    background:#f8fafc;
    color:#475569;
    padding:7px 6px;
    font-size:8.5px;
    text-align:left;
    white-space:normal;
    text-transform:uppercase;
    border-bottom:1px solid #e5e7eb;
    line-height:1.15;
}
table td{
    padding:7px 6px;
    border-bottom:1px solid #e5e7eb;
    font-size:9px;
    vertical-align:middle;
    overflow-wrap:anywhere;
    line-height:1.22;
}
table th:nth-child(1),
table td:nth-child(1){width:18%;}
table th:nth-child(2),
table td:nth-child(2){width:12%;}
table th:nth-child(3),
table td:nth-child(3){width:10%;}
table th:nth-child(4),
table td:nth-child(4){width:8%;}
table th:nth-child(5),
table td:nth-child(5){width:7%;}
table th:nth-child(6),
table td:nth-child(6){width:11%;}
table th:nth-child(7),
table td:nth-child(7){width:12%;}
table th:nth-child(8),
table td:nth-child(8){width:12%;}
table th:nth-child(9),
table td:nth-child(9){width:10%;}
.sticky-col{
    position:static;
    background:white;
    box-shadow:none;
}
thead .sticky-col{
    background:#f9fafb;
}
.month-head{
    text-align:center;
    background:#eaf4ff;
    color:#0f3760;
    border-left:1px solid #bfdbfe;
}
.month-cell{
    min-width:126px;
    border-left:1px solid #e5e7eb;
    background:#f8fbff;
}
.month-cell.rev-up{
    background:#fff7cc;
}
.month-cell.rev-down{
    background:#e0f2fe;
}
.month-cell.empty{
    background:#f8fafc;
    color:#94a3b8;
    text-align:center;
    vertical-align:middle;
}
.contract-row td{
    position:static;
    background:#0f172a;
    color:white;
    font-weight:900;
    letter-spacing:.02em;
    text-transform:uppercase;
}
.auto-added-row td,
.auto-added-row .sticky-col{
    background:#fff7ed;
}
.auto-added-row .month-cell{
    background:#fffbeb;
}
.auto-tag{
    display:inline-flex;
    margin-top:5px;
    padding:3px 7px;
    border-radius:999px;
    background:#f97316;
    color:white;
    font-size:9px;
    font-weight:800;
    letter-spacing:0;
}
.month-price{
    display:block;
    font-weight:900;
    color:#111827;
}
.month-meta{
    display:block;
    color:#475569;
    font-size:9px;
    line-height:1.35;
    margin-top:2px;
}
.calc-cell{
    min-width:220px;
}
.calc-line{
    display:block;
    margin-bottom:3px;
}
.year-actions{
    display:flex;
    gap:8px;
    align-items:end;
    justify-content:flex-end;
}
.right{
    text-align:right;
    font-variant-numeric:tabular-nums;
    white-space:normal;
}
.route-title{
    display:block;
    color:#111827;
    font-weight:800;
    line-height:1.25;
    max-width:100%;
}
.revizyon-preview{
    display:none;
    margin-top:14px;
}
.revizyon-preview.active{
    display:block;
}
.revizyon-preview table{
    font-size:10px;
}
.revizyon-preview .rev-row{
    background:#fff7cc;
    font-weight:800;
}
.revize-edit{
    width:92px;
    border:1px solid #cbd5e1;
    border-radius:6px;
    padding:4px 5px;
    font-size:10px;
    font-weight:800;
}
.inline-save{
    margin-top:5px;
    min-height:24px;
    padding:4px 7px;
    font-size:9px;
}
.muted{
    display:block;
    color:#6b7280;
    font-size:9px;
    margin-top:2px;
}
.formula{
    color:#0f766e;
    font-weight:800;
    white-space:normal;
}
.rev-empty{
    color:#9ca3af;
}
.actions{
    display:grid;
    gap:4px;
    justify-items:stretch;
}
.actions .btn,
.detail-box summary.btn{
    min-height:26px;
    padding:5px 7px;
    border-radius:6px;
    font-size:9px;
    line-height:1.1;
}
.detail-box{
    padding:8px;
    background:#f8fafc;
    overflow:hidden;
}
.detail-box summary{
    width:max-content;
    cursor:pointer;
    list-style:none;
}
.detail-box summary::-webkit-details-marker{
    display:none;
}
.daily-table{
    margin-top:10px;
    min-width:0;
    width:100%;
    table-layout:fixed;
}
.daily-table th,
.daily-table td{
    font-size:8.5px;
    padding:6px 5px;
}
.daily-table th:nth-child(1),
.daily-table td:nth-child(1){width:13%;}
.daily-table th:nth-child(2),
.daily-table td:nth-child(2),
.daily-table th:nth-child(3),
.daily-table td:nth-child(3),
.daily-table th:nth-child(4),
.daily-table td:nth-child(4),
.daily-table th:nth-child(5),
.daily-table td:nth-child(5){width:14%;}
.daily-table th:nth-child(6),
.daily-table td:nth-child(6){width:13%;}
.daily-table th:nth-child(7),
.daily-table td:nth-child(7){width:18%;}
.daily-table .rev-row{
    background:#fff7cc;
    font-weight:800;
}
.note{
    color:#6b7280;
    font-size:12px;
    margin:0 0 12px;
}
.contract-tabs{
    display:flex;
    gap:8px;
    overflow:visible;
    padding-bottom:6px;
}
.contract-tab{
    min-width:180px;
    border:1px solid #e5e7eb;
    border-radius:8px;
    padding:9px 10px;
    color:#334155;
    background:#fff;
    text-decoration:none;
    font-size:10px;
}
.contract-tab strong{
    display:block;
    color:#111827;
    font-size:12px;
}
.contract-tab.active{
    border-color:#2563eb;
    background:#eff6ff;
}
@media(max-width:1100px){
    .main{
        max-width:100vw;
    }
    .form-grid,
    .filters{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Nokta Yönetimi</h2>
        <p>Güzergah ekle, düzelt, sil ve motorin fiyatlarına göre revizyonu takip et.</p>
    </div>

    <?php if($message): ?>
        <div class="alert <?php echo $error ? 'error' : ''; ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="panel">
        <div class="section-title">Sözleşme Sayfaları</div>
        <div class="contract-tabs">
            <?php foreach($sozlesmeler as $sozlesme): ?>
                <?php
                    $active = (int)$sozlesme['id'] === $sozlesmeId;
                    $href = 'nokta-yonetimi.php?cari_id=' . (int)$sozlesme['cari_id'] . '&sozlesme_id=' . (int)$sozlesme['id'] . '&revizyon_yili=' . (int)$revizyonYili;
                ?>
                <a class="contract-tab <?php echo $active ? 'active' : ''; ?>" href="<?php echo e($href); ?>">
                    <strong><?php echo e($sozlesme['sozlesme_no']); ?></strong>
                    <span><?php echo e($cariAdlari[(int)$sozlesme['cari_id']] ?? '-'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel">
        <div class="section-title"><?php echo $editing ? 'Güzergah Düzelt' : 'Güzergah Ekle'; ?></div>
        <p class="note">Revize hesabı hakedişten değil, motorin fiyatlarından yapılır. %7 eşiği aşılırsa başlangıç birim fiyatın %40'lık kısmına motorin artış/indirim oranı uygulanır.</p>

        <form method="POST">
            <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
            <?php if($editing): ?>
                <input type="hidden" name="cari_id" value="<?php echo (int)$editing['cari_id']; ?>">
                <input type="hidden" name="sozlesme_id" value="<?php echo (int)$editing['sozlesme_id']; ?>">
                <input type="hidden" name="cikis_noktasi" value="<?php echo e($editing['cikis_noktasi']); ?>">
                <input type="hidden" name="varis_noktasi" value="<?php echo e($editing['varis_noktasi']); ?>">
                <input type="hidden" name="sevkiyat_km" id="sevkiyat_km" value="<?php echo e(sayiGoster($editing['sevkiyat_km'], 2)); ?>">
                <input type="hidden" name="baslangic_tarihi" value="<?php echo e($editing['baslangic_tarihi'] ?: date('Y-m-d')); ?>">
            <?php endif; ?>
            <div class="form-grid">
                <?php if(!$editing): ?>
                <div>
                    <label>Firma</label>
                    <select name="cari_id" id="form_cari" required>
                        <option value="">Seçiniz</option>
                        <?php foreach($cariler as $cari): ?>
                            <option value="<?php echo (int)$cari['id']; ?>" <?php echo (int)($editing['cari_id'] ?? $cariId) === (int)$cari['id'] ? 'selected' : ''; ?>><?php echo e($cari['firma_adi']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Sözleşme</label>
                    <select name="sozlesme_id" id="form_sozlesme" required>
                        <option value="">Seçiniz</option>
                        <?php foreach($sozlesmeler as $sozlesme): ?>
                            <option value="<?php echo (int)$sozlesme['id']; ?>" data-cari="<?php echo (int)$sozlesme['cari_id']; ?>" <?php echo (int)($editing['sozlesme_id'] ?? $sozlesmeId) === (int)$sozlesme['id'] ? 'selected' : ''; ?>><?php echo e($sozlesme['sozlesme_no']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Çıkış Yeri</label>
                    <input list="nokta_listesi" name="cikis_noktasi" value="<?php echo e($editing['cikis_noktasi'] ?? ''); ?>" required>
                </div>
                <div>
                    <label>Sevk Yeri</label>
                    <input list="nokta_listesi" name="varis_noktasi" value="<?php echo e($editing['varis_noktasi'] ?? ''); ?>" required>
                </div>
                <div>
                    <label>KM</label>
                    <input type="text" name="sevkiyat_km" id="sevkiyat_km" value="<?php echo $editing ? e(sayiGoster($editing['sevkiyat_km'], 2)) : ''; ?>" placeholder="144,62">
                </div>
                <?php endif; ?>
                <div>
                    <label>Ürün Grubu</label>
                    <?php $seciliUrun = strtoupper((string)($editing['tarife_tipi'] ?? 'DAMACANA')); ?>
                    <select name="tarife_tipi" required>
                        <?php foreach(['DAMACANA','PET','MALZEME','PALET'] as $urun): ?>
                            <option value="<?php echo e($urun); ?>" <?php echo $seciliUrun === $urun ? 'selected' : ''; ?>><?php echo e($urun); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Başlangıç Motorin</label>
                    <input type="text" name="motorin_baz_fiyati" id="motorin_baz_fiyati" value="<?php echo $editing ? e(sayiGoster($editing['motorin_baz_fiyati'], 3)) : ''; ?>" placeholder="54,766" required>
                </div>

                <div>
                    <label>Başlangıç Birim Fiyat</label>
                    <input type="text" name="birim_fiyat" id="birim_fiyat" value="<?php echo $editing ? e(sayiGoster($editing['birim_fiyat'], 2)) : ''; ?>" placeholder="7.920,00">
                </div>

                <?php if(!$editing): ?>
                    <div>
                        <label>Başlangıç Tarihi</label>
                        <input type="date" name="baslangic_tarihi" value="<?php echo e($editing['baslangic_tarihi'] ?? date('Y-m-d')); ?>">
                    </div>
                <?php endif; ?>
            </div>

            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit"><?php echo $editing ? 'Güncelle' : 'Ekle'; ?></button>
                <button type="button" class="btn" id="revize_preview_btn">Revize Et</button>
                <?php if($editing): ?>
                    <?php $revizeHref = 'nokta-yonetimi.php?cari_id=' . (int)$editing['cari_id'] . '&sozlesme_id=' . (int)$editing['sozlesme_id'] . '&revizyon_yili=' . (int)$revizyonYili . '&revize=1'; ?>
                    <a href="<?php echo e($revizeHref); ?>" class="btn">Revize Et</a>
                    <a href="nokta-yonetimi.php" class="btn btn-muted">Vazgeç</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="revizyon-preview" id="revizyon_preview">
            <div class="section-title">Motorin Revizyon Tablosu</div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Baz Motorin</th>
                            <th>Güncel Motorin</th>
                            <th>Fark %</th>
                            <th>Baz Birim</th>
                            <th>Revize Fiyat</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody id="revizyon_preview_body"></tbody>
                </table>
            </div>
        </div>

        <datalist id="nokta_listesi">
            <?php foreach($noktalar as $nokta): ?>
                <option value="<?php echo e($nokta); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>

    <?php if(!empty($mukerrerler)): ?>
    <div class="panel">
        <div class="section-title">Mükerrer Noktalar</div>
        <form method="POST" onsubmit="return confirm('Seçili mükerrer kayıtlar silinsin mi?');">
            <input type="hidden" name="current_cari_id" value="<?php echo (int)$cariId; ?>">
            <input type="hidden" name="current_sozlesme_id" value="<?php echo (int)$sozlesmeId; ?>">
            <input type="hidden" name="current_yil" value="<?php echo (int)$revizyonYili; ?>">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:42px;">Seç</th>
                            <th>Güzergah</th>
                            <th>Firma</th>
                            <th>Sözleşme</th>
                            <th>Ürün</th>
                            <th class="right">Birim</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($mukerrerler as $mukerrer): ?>
                            <tr>
                                <td><input type="checkbox" name="sil_id[]" value="<?php echo (int)$mukerrer['id']; ?>" checked></td>
                                <td><?php echo e(gorunenNokta($mukerrer['cikis_noktasi'])); ?> → <?php echo e(gorunenNokta($mukerrer['varis_noktasi'])); ?></td>
                                <td><?php echo e($mukerrer['firma_goster'] ?: '-'); ?></td>
                                <td><?php echo e($mukerrer['sozlesme_goster'] ?: '-'); ?></td>
                                <td><?php echo e($mukerrer['tarife_tipi'] ?: 'DAMACANA'); ?></td>
                                <td class="right"><?php echo paraGoster($mukerrer['birim_fiyat'], 2); ?></td>
                                <td><a class="btn btn-red" href="nokta-yonetimi.php?sil=<?php echo (int)$mukerrer['id']; ?>" onclick="return confirm('Bu mükerrer kayıt silinsin mi?');">Sil</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit" name="mukerrer_birlestir" value="1" class="btn">Seçilenleri Birleştir</button>
                <button type="submit" name="mukerrer_toplu_sil" value="1" class="btn btn-red">Seçilenleri Sil</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="section-title">Noktalar</div>

        <form method="GET" class="filters">
            <input type="hidden" name="cari_id" value="<?php echo (int)$cariId; ?>">
            <input type="hidden" name="sozlesme_id" value="<?php echo (int)$sozlesmeId; ?>">
            <div>
                <label>Arama</label>
                <input type="text" name="arama" value="<?php echo e($arama); ?>" placeholder="Güzergah ara">
            </div>

            <div>
                <label>Yıl</label>
                <input type="number" name="revizyon_yili" value="<?php echo (int)$revizyonYili; ?>" min="2020" max="2035">
            </div>

            <button class="btn" type="submit">Filtrele</button>
            <button class="btn" type="submit" name="revize" value="1">Revize Et</button>
        </form>

        <?php if(empty($guzergahlar)): ?>
            <div class="note">Seçilen filtrelere uygun nokta/güzergah bulunamadı.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="sticky-col">Güzergah</th>
                            <th>Firma</th>
                            <th>Sözleşme</th>
                            <th>Ürün</th>
                            <th class="right">KM</th>
                            <th class="right">Başlangıç Dizel</th>
                            <th class="right">Başlangıç Birim</th>
                            <th class="right">Son Güncel Birim</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $currentContractGroup = ''; ?>
                    <?php foreach($guzergahlar as $row): ?>
                        <?php
                            $contractGroup = trim(($row['firma_goster'] ?: '-') . ' / ' . ($row['sozlesme_goster'] ?: 'Sözleşme yok'));
                            $donemler = $row['_fiyat_donemleri'] ?? [];
                            $sonDonem = !empty($donemler) ? end($donemler) : null;
                            $donemIdMap = [];
                            foreach($donemler as $d){
                                if(!empty($d['id'])){
                                    $donemIdMap[$d['baslangic']] = $d;
                                }
                            }
                            if($contractGroup !== $currentContractGroup):
                                $currentContractGroup = $contractGroup;
                        ?>
                            <tr class="contract-row">
                                <td colspan="9"><?php echo e($currentContractGroup); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php $otomatikEklendi = strtoupper((string)($row['tarife_tipi'] ?? '')) === 'OTOMATIK' || stripos((string)($row['aciklama'] ?? ''), 'OTOMATIK') !== false; ?>
                        <tr class="<?php echo $otomatikEklendi ? 'auto-added-row' : ''; ?>">
                            <td class="sticky-col">
                                <span class="route-title"><?php echo e(gorunenNokta($row['cikis_noktasi'])); ?> → <?php echo e(gorunenNokta($row['varis_noktasi'])); ?></span>
                                <span class="muted">Başlangıç: <?php echo e($row['baslangic_tarihi'] ?: '-'); ?></span>
                            </td>
                            <td><?php echo e($row['firma_goster'] ?: '-'); ?></td>
                            <td><?php echo e($row['sozlesme_goster'] ?: '-'); ?></td>
                            <td><?php echo e($row['tarife_tipi'] ?: 'DAMACANA'); ?></td>
                            <td class="right"><?php echo sayiGoster($row['_km'], 2); ?></td>
                            <td class="right"><?php echo paraGoster($row['_baslangic_motorin'], 3); ?></td>
                            <td class="right"><?php echo paraGoster($row['_baslangic_birim'], 2); ?></td>
                            <td class="right"><?php echo paraGoster($sonDonem['birim'] ?? $row['_baslangic_birim'], 2); ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-muted" href="nokta-yonetimi.php?edit=<?php echo (int)$row['id']; ?>">Düzelt</a>
                                    <a class="btn btn-red" href="nokta-yonetimi.php?sil=<?php echo (int)$row['id']; ?>" onclick="return confirm('Bu güzergah silinsin mi?');">Sil</a>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="9" class="detail-box">
                                <details>
                                    <summary class="btn">Detay</summary>
                                    <table class="daily-table">
                                        <thead>
                                            <tr>
                                                <th>Tarih</th>
                                                <th>Baz Motorin</th>
                                                <th>Güncel Motorin</th>
                                                <th>Fark %</th>
                                                <th>Birim Fiyat</th>
                                                <th>Durum</th>
                                                <th>Düzelt</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                $aktifMotorin = (float)$row['_baslangic_motorin'];
                                                $aktifBirim = (float)$row['_baslangic_birim'];
                                                foreach($motorinRows as $motorinRow):
                                                    $tarih = (string)$motorinRow['tarih'];
                                                    if($tarih < ($row['baslangic_tarihi'] ?: $revizyonYili . '-01-01') || $tarih > $revizyonYili . '-12-31'){ continue; }
                                                    $motorin = (float)$motorinRow['motorin_fiyati'];
                                                    $satirBazMotorin = $aktifMotorin;
                                                    $satirBazBirim = $aktifBirim;
                                                    $farkOrani = $aktifMotorin > 0 ? (($motorin - $aktifMotorin) / $aktifMotorin) : 0;
                                                    $revize = abs($farkOrani) >= 0.07;
                                                    if(isset($donemIdMap[$tarih])){
                                                        $aktifMotorin = (float)$donemIdMap[$tarih]['motorin'];
                                                        $aktifBirim = (float)$donemIdMap[$tarih]['birim'];
                                                        $revize = true;
                                                    } elseif($revize){
                                                        $aktifBirim = $aktifBirim + (($aktifBirim * 0.40) * $farkOrani);
                                                        $aktifMotorin = $motorin;
                                                    }
                                            ?>
                                                <tr class="<?php echo $revize ? 'rev-row' : ''; ?>">
                                                    <td><?php echo e($tarih); ?></td>
                                                    <td><?php echo paraGoster($satirBazMotorin, 3); ?></td>
                                                    <td><?php echo paraGoster($motorin, 3); ?></td>
                                                    <td>%<?php echo sayiGoster($farkOrani * 100, 2); ?></td>
                                                    <td><?php echo paraGoster($aktifBirim, 2); ?></td>
                                                    <td><?php echo $revize ? 'Revize fiyat' : 'Devam'; ?></td>
                                                    <td>
                                                        <?php if(isset($donemIdMap[$tarih])): ?>
                                                            <form method="POST">
                                                                <input type="hidden" name="id" value="<?php echo (int)$donemIdMap[$tarih]['id']; ?>">
                                                                <input type="hidden" name="cari_id" value="<?php echo (int)$row['cari_id']; ?>">
                                                                <input type="hidden" name="sozlesme_id" value="<?php echo (int)$row['sozlesme_id']; ?>">
                                                                <input type="hidden" name="cikis_noktasi" value="<?php echo e($row['cikis_noktasi']); ?>">
                                                                <input type="hidden" name="varis_noktasi" value="<?php echo e($row['varis_noktasi']); ?>">
                                                                <input type="hidden" name="tarife_tipi" value="<?php echo e($row['tarife_tipi'] ?: 'DAMACANA'); ?>">
                                                                <input type="hidden" name="sevkiyat_km" value="<?php echo e(sayiGoster($row['_km'], 2)); ?>">
                                                                <input type="hidden" name="motorin_baz_fiyati" value="<?php echo e(sayiGoster($aktifMotorin, 3)); ?>">
                                                                <input type="hidden" name="baslangic_tarihi" value="<?php echo e($tarih); ?>">
                                                                <input class="revize-edit" name="birim_fiyat" value="<?php echo e(sayiGoster($aktifBirim, 2)); ?>">
                                                                <button class="btn inline-save" type="submit">Kaydet</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const motorinRows = <?php echo json_encode($motorinRows, JSON_UNESCAPED_UNICODE); ?>;
const allContractOptions = Array.from(document.querySelectorAll('#form_sozlesme option[data-cari]')).map(option => option.cloneNode(true));
const allFilterContractOptions = Array.from(document.querySelectorAll('#filter_sozlesme option[data-cari]')).map(option => option.cloneNode(true));
const selectedFormContract = '<?php echo (int)($editing['sozlesme_id'] ?? 0); ?>';
const selectedFilterContract = '<?php echo $sozlesmeId; ?>';

function filterSelect(cariSelectId, contractSelectId, sourceOptions, selectedValue, allLabel){
    const cariSelect = document.getElementById(cariSelectId);
    const contractSelect = document.getElementById(contractSelectId);
    if(!cariSelect || !contractSelect){
        return;
    }
    const cariId = cariSelect.value;
    contractSelect.innerHTML = `<option value="${allLabel === 'Seçiniz' ? '' : '0'}">${allLabel}</option>`;

    sourceOptions
        .filter(option => !cariId || cariId === '0' || option.dataset.cari === cariId)
        .forEach(option => {
            const clone = option.cloneNode(true);
            if(String(clone.value) === String(selectedValue)){
                clone.selected = true;
            }
            contractSelect.appendChild(clone);
        });
}

function parseTrNumber(value){
    value = String(value || '').replace(/[₺TL\s]/g, '');
    if(value.includes(',')){
        value = value.replace(/\./g, '').replace(',', '.');
    }
    return Number(value) || 0;
}

function formatDecimal(value, digits){
    return new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits
    }).format(value || 0);
}

let lastEdited = 'km';
function calculateInputs(){
    const kmInput = document.getElementById('sevkiyat_km');
    const motorinInput = document.getElementById('motorin_baz_fiyati');
    const birimInput = document.getElementById('birim_fiyat');
    if(!kmInput || !motorinInput || !birimInput){
        return;
    }
    const motorin = parseTrNumber(motorinInput.value);

    if(motorin <= 0){
        return;
    }

    if(lastEdited === 'birim'){
        const birim = parseTrNumber(birimInput.value);
        if(birim > 0){
            kmInput.value = formatDecimal(birim / motorin, 2);
        }
        return;
    }

    const km = parseTrNumber(kmInput.value);
    if(km > 0){
        birimInput.value = formatDecimal(km * motorin, 2);
    }
}

document.getElementById('form_cari')?.addEventListener('change', () => filterSelect('form_cari', 'form_sozlesme', allContractOptions, selectedFormContract, 'Seçiniz'));
document.getElementById('filter_cari')?.addEventListener('change', () => filterSelect('filter_cari', 'filter_sozlesme', allFilterContractOptions, selectedFilterContract, 'Tüm sözleşmeler'));
document.getElementById('sevkiyat_km')?.addEventListener('input', () => { lastEdited = 'km'; calculateInputs(); });
document.getElementById('birim_fiyat')?.addEventListener('input', () => { lastEdited = 'birim'; calculateInputs(); });
document.getElementById('motorin_baz_fiyati')?.addEventListener('input', calculateInputs);

filterSelect('form_cari', 'form_sozlesme', allContractOptions, selectedFormContract, 'Seçiniz');
filterSelect('filter_cari', 'filter_sozlesme', allFilterContractOptions, selectedFilterContract, 'Tüm sözleşmeler');

document.getElementById('revize_preview_btn')?.addEventListener('click', () => {
    const bazMotorin = parseTrNumber(document.getElementById('motorin_baz_fiyati')?.value);
    const bazBirim = parseTrNumber(document.getElementById('birim_fiyat')?.value);
    const body = document.getElementById('revizyon_preview_body');
    const box = document.getElementById('revizyon_preview');
    if(!body || !box || bazMotorin <= 0 || bazBirim <= 0){
        return;
    }

    let aktifMotorin = bazMotorin;
    let aktifBirim = bazBirim;
    body.innerHTML = '';

    motorinRows.forEach(row => {
        const motorin = Number(row.motorin_fiyati || 0);
        if(motorin <= 0){
            return;
        }

        const satirBazMotorin = aktifMotorin;
        const satirBazBirim = aktifBirim;
        const farkOrani = aktifMotorin > 0 ? (motorin - aktifMotorin) / aktifMotorin : 0;
        const revize = Math.abs(farkOrani) >= 0.07;
        if(revize){
            aktifBirim = aktifBirim + ((aktifBirim * 0.40) * farkOrani);
            aktifMotorin = motorin;
        }

        const tr = document.createElement('tr');
        if(revize){
            tr.className = 'rev-row';
        }
        tr.innerHTML = `
            <td>${row.tarih}</td>
            <td>${formatDecimal(satirBazMotorin, 3)}</td>
            <td>${formatDecimal(motorin, 3)}</td>
            <td>%${formatDecimal(farkOrani * 100, 2)}</td>
            <td>₺${formatDecimal(satirBazBirim, 2)}</td>
            <td>₺${formatDecimal(aktifBirim, 2)}</td>
            <td>${revize ? 'Revize fiyat' : 'Devam'}</td>
        `;
        body.appendChild(tr);
    });

    box.classList.add('active');
});
</script>

</body>
</html>
