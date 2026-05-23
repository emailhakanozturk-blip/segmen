<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: text/html; charset=UTF-8');
session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function paraGoster($value, int $decimals = 2): string
{
    return '₺' . number_format((float)$value, $decimals, ',', '.');
}

function sayiGoster($value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals, ',', '.');
}

function tarihGoster($tarih): string
{
    if(empty($tarih) || $tarih === '0000-00-00'){
        return '-';
    }

    return date('d.m.Y', strtotime($tarih));
}

function hesapKm(array $row): float
{
    $km = (float)($row['sevkiyat_km'] ?? 0);

    if($km <= 0 && (float)($row['motorin_baz_fiyati'] ?? 0) > 0){
        $km = (float)$row['birim_fiyat'] / (float)$row['motorin_baz_fiyati'];
    }

    return $km;
}

function tarifeHesapla(array $row): array
{
    $aktif = (int)($row['aktif'] ?? 1) === 1 && (empty($row['bitis_tarihi']) || $row['bitis_tarihi'] === '0000-00-00');
    $km = hesapKm($row);
    $bazMotorin = (float)($row['motorin_baz_fiyati'] ?? 0);
    $bazBirim = (float)($row['birim_fiyat'] ?? 0);
    if($bazBirim <= 0 && $km > 0 && $bazMotorin > 0){
        $bazBirim = $km * $bazMotorin;
    }
    $gunlukMotorin = (float)($row['motorin_fiyati'] ?? 0);
    $farkTutar = $gunlukMotorin - $bazMotorin;
    $farkYuzde = $bazMotorin > 0 ? ($farkTutar / $bazMotorin) * 100 : 0;
    $zamIndirim = abs($farkYuzde) >= 7 ? (($bazBirim * 40) / 100) * ($farkYuzde / 100) : 0;
    $onerilenBirim = $bazBirim + $zamIndirim;
    $guncellenecek = $aktif && (int)($row['motorin_revize'] ?? 1) === 1 && abs($farkYuzde) >= 7;

    return [
        'aktif' => $aktif,
        'km' => $km,
        'baz_motorin' => $bazMotorin,
        'baz_birim' => $bazBirim,
        'gunluk_motorin' => $gunlukMotorin,
        'fark_tutar' => $farkTutar,
        'fark_yuzde' => $farkYuzde,
        'onerilen_birim' => $onerilenBirim,
        'guncellenecek' => $guncellenecek,
    ];
}

if(isset($_POST['motorin_guncelle'])){
    $db->beginTransaction();

    try {
        $sonMotorin = $db->query("
            SELECT tarih, motorin_fiyati
            FROM motorin_fiyatlari
            ORDER BY tarih DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if(!$sonMotorin){
            throw new Exception('Motorin fiyatı bulunamadı.');
        }

        $motorinTarih = $sonMotorin['tarih'];
        $gunlukMotorin = (float)$sonMotorin['motorin_fiyati'];

        $tarifeler = $db->query("
            SELECT *
            FROM tarifeler
            WHERE aktif = 1
            AND motorin_revize = 1
            AND (
                bitis_tarihi IS NULL
                OR bitis_tarihi = '0000-00-00'
            )
            ORDER BY firma_adi, sozlesme_no, cikis_noktasi, varis_noktasi
        ")->fetchAll(PDO::FETCH_ASSOC);

        $guncellenen = 0;
        $degismeyen = 0;

        foreach($tarifeler as $tarife){
            $km = hesapKm($tarife);
            $motorinBaz = (float)($tarife['motorin_baz_fiyati'] ?? 0);
            $bazBirim = (float)($tarife['birim_fiyat'] ?? 0);

            if($bazBirim <= 0 && $km > 0 && $motorinBaz > 0){
                $bazBirim = $km * $motorinBaz;
            }

            if($bazBirim <= 0 || $motorinBaz <= 0){
                $degismeyen++;
                continue;
            }

            $farkYuzde = (($gunlukMotorin - $motorinBaz) / $motorinBaz) * 100;

            if(abs($farkYuzde) < 7){
                $degismeyen++;
                continue;
            }

            $kontrol = $db->prepare("
                SELECT id
                FROM tarifeler
                WHERE sozlesme_id <=> ?
                AND cikis_noktasi = ?
                AND varis_noktasi = ?
                AND tarife_tipi = ?
                AND baslangic_tarihi = ?
                LIMIT 1
            ");
            $kontrol->execute([
                $tarife['sozlesme_id'] ?? null,
                $tarife['cikis_noktasi'],
                $tarife['varis_noktasi'],
                $tarife['tarife_tipi'] ?? 'NORMAL',
                $motorinTarih
            ]);

            if($kontrol->fetch()){
                $degismeyen++;
                continue;
            }

            $oncekiGun = date('Y-m-d', strtotime($motorinTarih . ' -1 day'));

            $kapat = $db->prepare("
                UPDATE tarifeler
                SET bitis_tarihi = ?, aktif = 0
                WHERE id = ?
            ");
            $kapat->execute([$oncekiGun, (int)$tarife['id']]);

            $zamIndirim = (($bazBirim * 40) / 100) * ($farkYuzde / 100);
            $yeniBirim = round($bazBirim + $zamIndirim, 4);
            $yeniRevizyonNo = ((int)($tarife['revizyon_no'] ?? 0)) + 1;

            $ekle = $db->prepare("
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
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, 1)
            ");
            $ekle->execute([
                $tarife['cari_id'] ?? null,
                $tarife['sozlesme_id'] ?? null,
                $tarife['firma_adi'],
                $tarife['sozlesme_no'],
                $tarife['cikis_noktasi'],
                $tarife['varis_noktasi'],
                $tarife['arac_tipi'],
                $km,
                $tarife['tarife_tipi'] ?? 'NORMAL',
                $yeniBirim,
                $gunlukMotorin,
                $tarife['motorin_revize'] ?? 1,
                $motorinTarih,
                $yeniRevizyonNo,
                $tarife['aciklama']
            ]);

            $guncellenen++;
        }

        $db->commit();
        $message = $guncellenen . ' tarife %7 eşik ve birim fiyatın %40 motorin payı kuralına göre revize edildi. ' . $degismeyen . ' tarifede değişiklik yok.';
    } catch(Throwable $exception){
        $db->rollBack();
        $message = 'Hata: ' . $exception->getMessage();
    }
}

$durum = $_GET['durum'] ?? 'aktif';
$cariId = (int)($_GET['cari_id'] ?? 0);
$sozlesmeId = (int)($_GET['sozlesme_id'] ?? 0);
$arama = trim((string)($_GET['arama'] ?? ''));

$cariler = $db->query("
    SELECT id, firma_adi
    FROM cariler
    ORDER BY firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sozlesmeler = $db->query("
    SELECT id, cari_id, sozlesme_no
    FROM sozlesmeler
    ORDER BY sozlesme_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

$rows = $db->query("
    SELECT
        t.*,
        COALESCE(c.firma_adi, t.firma_adi) AS firma_goster,
        COALESCE(s.sozlesme_no, t.sozlesme_no) AS sozlesme_goster,
        m.motorin_fiyati
    FROM tarifeler t
    LEFT JOIN cariler c ON c.id = t.cari_id
    LEFT JOIN sozlesmeler s ON s.id = t.sozlesme_id
    LEFT JOIN motorin_fiyatlari m ON m.tarih = (
        SELECT MAX(tarih)
        FROM motorin_fiyatlari
    )
    ORDER BY
        firma_goster ASC,
        sozlesme_goster ASC,
        t.cikis_noktasi ASC,
        t.varis_noktasi ASC,
        t.baslangic_tarihi ASC,
        t.revizyon_no ASC,
        t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$aktifSayisi = 0;
$gecmisSayisi = 0;
$guncellenecekSayisi = 0;
$sonMotorin = 0;
$filteredRows = [];

foreach($rows as $row){
    $hesap = tarifeHesapla($row);

    if($hesap['aktif']){
        $aktifSayisi++;
    } else {
        $gecmisSayisi++;
    }

    if($hesap['guncellenecek']){
        $guncellenecekSayisi++;
    }

    if(!$sonMotorin && $hesap['gunluk_motorin'] > 0){
        $sonMotorin = $hesap['gunluk_motorin'];
    }

    $durumUygun =
        $durum === 'tum' ||
        ($durum === 'aktif' && $hesap['aktif']) ||
        ($durum === 'gecmis' && !$hesap['aktif']) ||
        ($durum === 'guncellenecek' && $hesap['guncellenecek']);

    $firmaUygun = $cariId <= 0 || (int)($row['cari_id'] ?? 0) === $cariId;
    $sozlesmeUygun = $sozlesmeId <= 0 || (int)($row['sozlesme_id'] ?? 0) === $sozlesmeId;
    $aramaMetni = mb_strtolower(($row['firma_goster'] ?? '') . ' ' . ($row['sozlesme_goster'] ?? '') . ' ' . ($row['cikis_noktasi'] ?? '') . ' ' . ($row['varis_noktasi'] ?? ''), 'UTF-8');
    $aramaUygun = $arama === '' || mb_strpos($aramaMetni, mb_strtolower($arama, 'UTF-8')) !== false;

    if($durumUygun && $firmaUygun && $sozlesmeUygun && $aramaUygun){
        $row['_hesap'] = $hesap;
        $filteredRows[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Tarife Yönetimi</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.topbar{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:flex-start;
}
.topbar h2{
    margin:0;
    font-size:24px;
    color:#111827;
}
.topbar p{
    margin-top:6px;
    color:#6b7280;
    font-size:14px;
}
.top-actions{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.btn{
    background:#111827;
    color:white;
    padding:10px 14px;
    border-radius:8px;
    text-decoration:none;
    display:inline-block;
    font-size:13px;
    font-weight:700;
    border:none;
    cursor:pointer;
    white-space:nowrap;
}
.btn-orange{
    background:#f3f4f6;
    color:#111827;
    border:1px solid #e5e7eb;
}
.btn-blue{
    background:#0f766e;
}
.btn-small{
    padding:7px 9px;
    font-size:11px;
}
.alert{
    background:#dcfce7;
    color:#166534;
    padding:14px 18px;
    border-radius:8px;
    margin-bottom:18px;
    font-weight:bold;
}
.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(150px,1fr));
    gap:14px;
    margin-bottom:18px;
}
.summary-card{
    background:#ffffff;
    border:1px solid #e7eaf0;
    border-radius:10px;
    padding:16px;
}
.summary-card span{
    display:block;
    color:#6b7280;
    font-size:12px;
    margin-bottom:8px;
}
.summary-card strong{
    color:#111827;
    font-size:24px;
}
.toolbar{
    background:#ffffff;
    border:1px solid #e7eaf0;
    border-radius:10px;
    padding:14px;
    margin-bottom:18px;
}
.filters{
    display:grid;
    grid-template-columns:1.2fr 1fr 1fr 1fr auto;
    gap:10px;
    align-items:end;
}
.field label{
    display:block;
    font-size:12px;
    color:#6b7280;
    margin-bottom:6px;
}
.field input,
.field select{
    width:100%;
    height:40px;
    border:1px solid #d8dde6;
    border-radius:8px;
    padding:0 10px;
    font-size:13px;
    color:#111827;
    background:#fff;
}
.table-card{
    background:white;
    border:1px solid #e7eaf0;
    border-radius:10px;
    overflow:hidden;
}
.table-head{
    padding:16px;
    border-bottom:1px solid #e7eaf0;
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
}
.table-head h3{
    margin:0;
    font-size:16px;
    color:#111827;
}
.table-head p{
    margin:4px 0 0;
    color:#6b7280;
    font-size:13px;
}
.table-wrap{
    overflow-x:auto;
}
table{
    width:100%;
    min-width:1220px;
    border-collapse:collapse;
}
table th{
    background:#f9fafb;
    color:#6b7280;
    padding:10px 12px;
    font-size:11px;
    border-bottom:1px solid #e5e7eb;
    text-align:left;
    white-space:nowrap;
    text-transform:uppercase;
}
table td{
    padding:12px;
    border-bottom:1px solid #e5e7eb;
    font-size:12px;
    background:white;
    vertical-align:top;
}
table tr:hover td{
    background:#f9fafb;
}
.pasif-row td{
    background:#f8fafc;
    color:#64748b;
}
.right{
    text-align:right;
    font-variant-numeric:tabular-nums;
}
.badge{
    display:inline-block;
    padding:5px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:bold;
    white-space:nowrap;
    margin:0 4px 4px 0;
}
.badge-green{background:#dcfce7;color:#166534;}
.badge-red{background:#fee2e2;color:#991b1b;}
.badge-gray{background:#e5e7eb;color:#374151;}
.badge-blue{background:#dbeafe;color:#1d4ed8;}
.route strong,
.price-main{
    display:block;
    color:#111827;
    font-weight:800;
    margin-bottom:3px;
}
.route span,
.price-sub{
    display:block;
    color:#6b7280;
    font-size:11px;
    line-height:1.4;
}
.formula{
    color:#0f766e;
    font-weight:800;
}
.empty-state{
    padding:32px;
    text-align:center;
    color:#6b7280;
}
@media(max-width:1100px){
    .filters,
    .summary-grid{
        grid-template-columns:1fr 1fr;
    }
}
@media(max-width:700px){
    .topbar{display:block;}
    .top-actions{justify-content:flex-start;margin-top:14px;}
    .filters,
    .summary-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <div>
            <h2>Tarife Yönetimi</h2>
            <p>Firma ve sözleşme bazında km x baz motorin hesabıyla çalışan tarife listesi.</p>
        </div>

        <div class="top-actions">
            <a href="tarife-ekle.php" class="btn">+ Yeni Tarife</a>
            <a href="tarife-yukle.php" class="btn btn-orange">Excelden Yükle</a>
            <form method="POST" style="margin:0;">
                <button type="submit" name="motorin_guncelle" class="btn btn-blue">Motorine Göre Güncelle</button>
            </form>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card"><span>Aktif tarife</span><strong><?php echo number_format($aktifSayisi); ?></strong></div>
        <div class="summary-card"><span>Geçmiş revizyon</span><strong><?php echo number_format($gecmisSayisi); ?></strong></div>
        <div class="summary-card"><span>Güncelleme eşiğinde</span><strong><?php echo number_format($guncellenecekSayisi); ?></strong></div>
        <div class="summary-card"><span>Güncel motorin</span><strong><?php echo paraGoster($sonMotorin, 3); ?></strong></div>
    </div>

    <div class="toolbar">
        <form method="GET" class="filters">
            <div class="field">
                <label>Arama</label>
                <input type="text" name="arama" value="<?php echo e($arama); ?>" placeholder="Firma, sözleşme, çıkış veya varış">
            </div>

            <div class="field">
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

            <div class="field">
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

            <div class="field">
                <label>Durum</label>
                <select name="durum">
                    <option value="aktif" <?php echo $durum === 'aktif' ? 'selected' : ''; ?>>Aktif tarifeler</option>
                    <option value="guncellenecek" <?php echo $durum === 'guncellenecek' ? 'selected' : ''; ?>>Güncellenecekler</option>
                    <option value="gecmis" <?php echo $durum === 'gecmis' ? 'selected' : ''; ?>>Geçmiş revizyonlar</option>
                    <option value="tum" <?php echo $durum === 'tum' ? 'selected' : ''; ?>>Tüm kayıtlar</option>
                </select>
            </div>

            <button type="submit" class="btn">Filtrele</button>
        </form>
    </div>

    <div class="table-card">
        <div class="table-head">
            <div>
                <h3>Tarife Listesi</h3>
                <p><?php echo number_format(count($filteredRows)); ?> kayıt gösteriliyor. Baz birim fiyat doğrudan km x baz motorin üzerinden hesaplanır.</p>
            </div>
        </div>

        <?php if(empty($filteredRows)): ?>
            <div class="empty-state">Seçilen filtrelere uygun tarife bulunamadı.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Firma / Sözleşme</th>
                            <th>Güzergah</th>
                            <th>Durum</th>
                            <th>Dönem</th>
                            <th class="right">KM</th>
                            <th class="right">Baz Motorin</th>
                            <th class="right">Birim Baz Fiyat</th>
                            <th class="right">Güncel Motorin</th>
                            <th class="right">Önerilen Fiyat</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($filteredRows as $row): ?>
                        <?php $hesap = $row['_hesap']; ?>
                        <tr class="<?php echo $hesap['aktif'] ? '' : 'pasif-row'; ?>">
                            <td class="route">
                                <strong><?php echo e($row['firma_goster']); ?></strong>
                                <span><?php echo e($row['sozlesme_goster'] ?: 'Sözleşme yok'); ?></span>
                            </td>
                            <td class="route">
                                <strong><?php echo e($row['cikis_noktasi']); ?> → <?php echo e($row['varis_noktasi']); ?></strong>
                                <span><?php echo e($row['tarife_tipi'] ?? 'NORMAL'); ?> <?php echo $row['arac_tipi'] ? ' / ' . e($row['arac_tipi']) : ''; ?></span>
                            </td>
                            <td>
                                <span class="badge <?php echo $hesap['aktif'] ? 'badge-green' : 'badge-gray'; ?>">
                                    <?php echo $hesap['aktif'] ? 'Aktif' : 'Geçmiş'; ?>
                                </span>
                                <span class="badge badge-blue">Rev. <?php echo e($row['revizyon_no']); ?></span>
                            </td>
                            <td>
                                <span class="price-main"><?php echo tarihGoster($row['baslangic_tarihi']); ?> / <?php echo tarihGoster($row['bitis_tarihi']); ?></span>
                                <span class="price-sub">Kayıt no: <?php echo (int)$row['id']; ?></span>
                            </td>
                            <td class="right"><span class="price-main"><?php echo sayiGoster($hesap['km'], 2); ?></span></td>
                            <td class="right"><span class="price-main"><?php echo paraGoster($hesap['baz_motorin'], 3); ?></span></td>
                            <td class="right">
                                <span class="price-main formula"><?php echo paraGoster($hesap['baz_birim']); ?></span>
                                <span class="price-sub"><?php echo sayiGoster($hesap['km'], 2); ?> km x <?php echo paraGoster($hesap['baz_motorin'], 3); ?></span>
                            </td>
                            <td class="right">
                                <span class="price-main"><?php echo paraGoster($hesap['gunluk_motorin'], 3); ?></span>
                                <span class="price-sub">%<?php echo sayiGoster($hesap['fark_yuzde'], 2); ?> fark</span>
                            </td>
                            <td class="right">
                                <span class="price-main"><?php echo paraGoster($hesap['onerilen_birim']); ?></span>
                                <span class="price-sub">%7 eşik, birim fiyatın %40 motorin payı</span>
                            </td>
                            <td>
                                <a class="btn btn-small" href="tarife-ekle.php?kaynak_id=<?php echo (int)$row['id']; ?>">KM/Fiyat Tanımla</a>
                                <?php if($hesap['guncellenecek']): ?>
                                    <span class="badge badge-green">Güncellenecek</span>
                                <?php elseif($hesap['aktif']): ?>
                                    <span class="badge badge-red">Eşik altında</span>
                                <?php else: ?>
                                    <span class="badge badge-gray">Arşiv</span>
                                <?php endif; ?>
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
const cariFilter = document.getElementById('filter_cari');
const sozlesmeFilter = document.getElementById('filter_sozlesme');
const selectedSozlesme = sozlesmeFilter.value;
const allSozlesmeOptions = Array.from(sozlesmeFilter.options).slice(1).map(option => option.cloneNode(true));

function filterContracts(){
    const cariId = cariFilter.value;
    sozlesmeFilter.innerHTML = '<option value="0">Tüm sözleşmeler</option>';

    allSozlesmeOptions
        .filter(option => cariId === '0' || option.dataset.cari === cariId)
        .forEach(option => {
            const clone = option.cloneNode(true);
            if(clone.value === selectedSozlesme){
                clone.selected = true;
            }
            sozlesmeFilter.appendChild(clone);
        });
}

cariFilter.addEventListener('change', filterContracts);
filterContracts();
</script>

</body>
</html>
