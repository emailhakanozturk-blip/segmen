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

function revizeBirimFiyat(float $baslangicBirim, float $baslangicMotorin, float $hedefMotorin): float
{
    if($baslangicBirim <= 0 || $baslangicMotorin <= 0 || $hedefMotorin <= 0){
        return 0;
    }

    $oran = (($hedefMotorin - $baslangicMotorin) / $baslangicMotorin) * 100;

    if(abs($oran) < 7){
        return round($baslangicBirim, 2);
    }

    return round($baslangicBirim + (($baslangicBirim * 0.40) * ($oran / 100)), 2);
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

    $oran = farkYuzde($baslangicMotorin, $ayMotorin);
    $revize = abs($oran) >= 7;
    $zam = $revize ? (($baslangicBirim * 0.40) * ($oran / 100)) : 0;

    return [
        'oran' => $oran,
        'zam' => $zam,
        'birim' => $baslangicBirim + $zam,
        'revize' => $revize,
    ];
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

        if(abs(farkYuzde($baslangicMotorin, $motorin)) >= 7){
            return $row;
        }
    }

    return null;
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

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        $id = (int)($_POST['id'] ?? 0);
        $cariIdPost = (int)($_POST['cari_id'] ?? 0);
        $sozlesmeIdPost = (int)($_POST['sozlesme_id'] ?? 0);
        $cikis = temizNokta($_POST['cikis_noktasi'] ?? '');
        $varis = temizNokta($_POST['varis_noktasi'] ?? '');
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

        if($baslangicBirim <= 0 && $km > 0){
            $baslangicBirim = round($km * $baslangicMotorin, 4);
        }

        if($km <= 0 && $baslangicBirim > 0){
            $km = round($baslangicBirim / $baslangicMotorin, 2);
        }

        if($km <= 0 || $baslangicBirim <= 0){
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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NORMAL', 1, 1, 1)
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
                $baslangicTarihi
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
$ayMotorinleri = ayMotorinFiyatlari($db, $revizyonYili);
$aylar = range(1, 12);

if(isset($_GET['revize']) && $message === ''){
    $message = $revizyonYili . ' yılı için motorin fiyatları sayfasındaki aylık değerlere göre revizyon hesaplandı.';
}

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
    WHERE t.aktif = 1
    AND t.cikis_noktasi IS NOT NULL
    AND t.varis_noktasi IS NOT NULL
    ORDER BY firma_goster ASC, sozlesme_goster ASC, t.cikis_noktasi ASC, t.varis_noktasi ASC, t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$guzergahlar = [];

foreach($rows as $row){
    $firmaUygun = $cariId <= 0 || (int)($row['cari_id'] ?? 0) === $cariId;
    $sozlesmeUygun = $sozlesmeId <= 0 || (int)($row['sozlesme_id'] ?? 0) === $sozlesmeId;
    $aramaMetni = mb_strtolower(($row['firma_goster'] ?? '') . ' ' . ($row['sozlesme_goster'] ?? '') . ' ' . ($row['cikis_noktasi'] ?? '') . ' ' . ($row['varis_noktasi'] ?? ''), 'UTF-8');
    $aramaUygun = $arama === '' || mb_strpos($aramaMetni, mb_strtolower($arama, 'UTF-8')) !== false;

    if(!$firmaUygun || !$sozlesmeUygun || !$aramaUygun){
        continue;
    }

    $baslangicBirim = (float)($row['birim_fiyat'] ?? 0);
    $baslangicMotorin = (float)($row['motorin_baz_fiyati'] ?? 0);
    $km = (float)($row['sevkiyat_km'] ?? 0);

    if($km <= 0 && $baslangicBirim > 0 && $baslangicMotorin > 0){
        $km = round($baslangicBirim / $baslangicMotorin, 2);
    }

    if($baslangicBirim <= 0 && $km > 0 && $baslangicMotorin > 0){
        $baslangicBirim = round($km * $baslangicMotorin, 2);
    }

    $ayHesaplari = [];
    foreach($aylar as $ay){
        if(!isset($ayMotorinleri[$ay])){
            $ayHesaplari[$ay] = null;
            continue;
        }

        $ayMotorin = (float)$ayMotorinleri[$ay]['motorin'];
        $hesap = revizyonHesapla($baslangicBirim, $baslangicMotorin, $ayMotorin);
        $hesap['motorin'] = $ayMotorin;
        $hesap['tarih'] = $ayMotorinleri[$ay]['tarih'];
        $ayHesaplari[$ay] = $hesap;
    }

    $row['_km'] = $km;
    $row['_baslangic_motorin'] = $baslangicMotorin;
    $row['_baslangic_birim'] = $baslangicBirim;
    $row['_ay_hesaplari'] = $ayHesaplari;

    $guzergahlar[] = $row;
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Nokta Yönetimi</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.panel{
    background:white;
    padding:16px;
    border-radius:10px;
    border:1px solid #e7eaf0;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
    margin-bottom:14px;
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
    font-size:11px;
    white-space:nowrap;
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
    grid-template-columns:minmax(220px,1fr) minmax(150px,.75fr) minmax(150px,.75fr) 110px auto;
    gap:8px;
    align-items:end;
    margin-bottom:12px;
}
.table-wrap{
    width:100%;
    overflow:auto;
    border:1px solid #e7eaf0;
    border-radius:10px;
}
table{
    width:100%;
    min-width:2380px;
    border-collapse:collapse;
}
table th{
    background:#f9fafb;
    color:#4b5563;
    padding:8px 9px;
    font-size:10px;
    text-align:left;
    white-space:nowrap;
    text-transform:uppercase;
    border-bottom:1px solid #e5e7eb;
}
table td{
    padding:7px 8px;
    border-bottom:1px solid #e5e7eb;
    font-size:10px;
    vertical-align:top;
}
.sticky-col{
    position:sticky;
    left:0;
    z-index:2;
    background:white;
    box-shadow:1px 0 0 #e5e7eb;
}
thead .sticky-col{
    z-index:4;
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
    white-space:nowrap;
}
.route-title{
    display:block;
    color:#111827;
    font-weight:800;
}
.muted{
    display:block;
    color:#6b7280;
    font-size:10px;
    margin-top:2px;
}
.formula{
    color:#0f766e;
    font-weight:800;
    white-space:nowrap;
}
.rev-empty{
    color:#9ca3af;
}
.actions{
    display:flex;
    gap:6px;
}
.note{
    color:#6b7280;
    font-size:12px;
    margin:0 0 12px;
}
@media(max-width:1100px){
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
        <div class="section-title"><?php echo $editing ? 'Güzergah Düzelt' : 'Güzergah Ekle'; ?></div>
        <p class="note">Revize hesabı hakedişten değil, motorin fiyatlarından yapılır. %7 eşiği aşılırsa başlangıç birim fiyatın %40’lık kısmına motorin artış/indirim oranı uygulanır.</p>

        <form method="POST">
            <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
            <div class="form-grid">
                <div>
                    <label>Firma</label>
                    <select name="cari_id" id="form_cari" required>
                        <option value="">Seçiniz</option>
                        <?php foreach($cariler as $cari): ?>
                            <option value="<?php echo (int)$cari['id']; ?>" <?php echo (int)($editing['cari_id'] ?? 0) === (int)$cari['id'] ? 'selected' : ''; ?>>
                                <?php echo e($cari['firma_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Sözleşme</label>
                    <select name="sozlesme_id" id="form_sozlesme" required>
                        <option value="">Seçiniz</option>
                        <?php foreach($sozlesmeler as $sozlesme): ?>
                            <option
                                value="<?php echo (int)$sozlesme['id']; ?>"
                                data-cari="<?php echo (int)$sozlesme['cari_id']; ?>"
                                <?php echo (int)($editing['sozlesme_id'] ?? 0) === (int)$sozlesme['id'] ? 'selected' : ''; ?>>
                                <?php echo e($sozlesme['sozlesme_no']); ?>
                            </option>
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

                <div>
                    <label>Başlangıç Motorin</label>
                    <input type="text" name="motorin_baz_fiyati" id="motorin_baz_fiyati" value="<?php echo $editing ? e(sayiGoster($editing['motorin_baz_fiyati'], 3)) : ''; ?>" placeholder="54,766" required>
                </div>

                <div>
                    <label>Başlangıç Birim Fiyat</label>
                    <input type="text" name="birim_fiyat" id="birim_fiyat" value="<?php echo $editing ? e(sayiGoster($editing['birim_fiyat'], 2)) : ''; ?>" placeholder="7.920,00">
                </div>

                <div>
                    <label>Başlangıç Tarihi</label>
                    <input type="date" name="baslangic_tarihi" value="<?php echo e($editing['baslangic_tarihi'] ?? date('Y-m-d')); ?>">
                </div>
            </div>

            <div style="margin-top:12px;display:flex;gap:8px;">
                <button type="submit"><?php echo $editing ? 'Güncelle' : 'Ekle'; ?></button>
                <?php if($editing): ?>
                    <a href="nokta-yonetimi.php" class="btn btn-muted">Vazgeç</a>
                <?php endif; ?>
            </div>
        </form>

        <datalist id="nokta_listesi">
            <?php foreach($noktalar as $nokta): ?>
                <option value="<?php echo e($nokta); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>

    <div class="panel">
        <div class="section-title">Noktalar</div>

        <form method="GET" class="filters">
            <div>
                <label>Arama</label>
                <input type="text" name="arama" value="<?php echo e($arama); ?>" placeholder="GÜZELCEKALE, ÇİFTLİK, firma veya sözleşme">
            </div>

            <div>
                <label>Firma</label>
                <select name="cari_id" id="filter_cari">
                    <option value="0">Tüm firmalar</option>
                    <?php foreach($cariler as $cari): ?>
                        <option value="<?php echo (int)$cari['id']; ?>" <?php echo (int)$cari['id'] === $cariId ? 'selected' : ''; ?>>
                            <?php echo e($cari['firma_adi']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Sözleşme</label>
                <select name="sozlesme_id" id="filter_sozlesme">
                    <option value="0">Tüm sözleşmeler</option>
                    <?php foreach($sozlesmeler as $sozlesme): ?>
                        <option
                            value="<?php echo (int)$sozlesme['id']; ?>"
                            data-cari="<?php echo (int)$sozlesme['cari_id']; ?>"
                            <?php echo (int)$sozlesme['id'] === $sozlesmeId ? 'selected' : ''; ?>>
                            <?php echo e($sozlesme['sozlesme_no']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                            <th class="right">KM</th>
                            <th class="right">Ocak Baz Motorin</th>
                            <th class="right">Ocak Baz Birim</th>
                            <?php foreach($aylar as $ay): ?>
                                <th class="month-head"><?php echo e(ayAdi($ay)); ?></th>
                            <?php endforeach; ?>
                            <th>Hesaplama</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($guzergahlar as $row): ?>
                        <tr>
                            <td class="sticky-col">
                                <span class="route-title"><?php echo e($row['cikis_noktasi']); ?> → <?php echo e($row['varis_noktasi']); ?></span>
                                <span class="muted">Başlangıç: <?php echo e($row['baslangic_tarihi'] ?: '-'); ?></span>
                            </td>
                            <td><?php echo e($row['firma_goster'] ?: '-'); ?></td>
                            <td><?php echo e($row['sozlesme_goster'] ?: '-'); ?></td>
                            <td class="right"><?php echo sayiGoster($row['_km'], 2); ?></td>
                            <td class="right"><?php echo paraGoster($row['_baslangic_motorin'], 3); ?></td>
                            <td class="right"><?php echo paraGoster($row['_baslangic_birim'], 2); ?></td>
                            <?php foreach($aylar as $ay): ?>
                                <?php $ayHesap = $row['_ay_hesaplari'][$ay] ?? null; ?>
                                <?php if(!$ayHesap): ?>
                                    <td class="month-cell empty">-</td>
                                <?php else: ?>
                                    <?php $class = $ayHesap['revize'] ? ($ayHesap['zam'] >= 0 ? 'rev-up' : 'rev-down') : ''; ?>
                                    <td class="month-cell <?php echo $class; ?>">
                                        <span class="month-price"><?php echo paraGoster($ayHesap['birim'], 2); ?></span>
                                        <span class="month-meta">Motorin: <?php echo paraGoster($ayHesap['motorin'], 3); ?></span>
                                        <span class="month-meta">Fark: %<?php echo sayiGoster($ayHesap['oran'], 2); ?></span>
                                        <span class="month-meta">Zam/İnd.: <?php echo paraGoster($ayHesap['zam'], 2); ?></span>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <td class="calc-cell">
                                <span class="calc-line formula">Eşik: %7</span>
                                <span class="calc-line">Formül: Ocak birim + (Ocak birim x %40 x motorin farkı)</span>
                                <span class="calc-line muted">Motorin kaydı olmayan ay boş kalır.</span>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-muted" href="nokta-yonetimi.php?edit=<?php echo (int)$row['id']; ?>">Düzelt</a>
                                    <a class="btn btn-red" href="nokta-yonetimi.php?sil=<?php echo (int)$row['id']; ?>" onclick="return confirm('Bu güzergah silinsin mi?');">Sil</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const allContractOptions = Array.from(document.querySelectorAll('#form_sozlesme option[data-cari]')).map(option => option.cloneNode(true));
const allFilterContractOptions = Array.from(document.querySelectorAll('#filter_sozlesme option[data-cari]')).map(option => option.cloneNode(true));
const selectedFormContract = '<?php echo (int)($editing['sozlesme_id'] ?? 0); ?>';
const selectedFilterContract = '<?php echo $sozlesmeId; ?>';

function filterSelect(cariSelectId, contractSelectId, sourceOptions, selectedValue, allLabel){
    const cariSelect = document.getElementById(cariSelectId);
    const contractSelect = document.getElementById(contractSelectId);
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

document.getElementById('form_cari').addEventListener('change', () => filterSelect('form_cari', 'form_sozlesme', allContractOptions, selectedFormContract, 'Seçiniz'));
document.getElementById('filter_cari').addEventListener('change', () => filterSelect('filter_cari', 'filter_sozlesme', allFilterContractOptions, selectedFilterContract, 'Tüm sözleşmeler'));
document.getElementById('sevkiyat_km').addEventListener('input', () => { lastEdited = 'km'; calculateInputs(); });
document.getElementById('birim_fiyat').addEventListener('input', () => { lastEdited = 'birim'; calculateInputs(); });
document.getElementById('motorin_baz_fiyati').addEventListener('input', calculateInputs);

filterSelect('form_cari', 'form_sozlesme', allContractOptions, selectedFormContract, 'Seçiniz');
filterSelect('filter_cari', 'filter_sozlesme', allFilterContractOptions, selectedFilterContract, 'Tüm sözleşmeler');
</script>

</body>
</html>
