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

function e($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function paraGoster($value, $decimals = 2){
    return '₺' . number_format((float)$value, $decimals, ',', '.');
}

function yuzdeGoster($value){
    return '%' . number_format((float)$value, 2, ',', '.');
}

function tarifeHesapla($row){

    $aktifMi =
        empty($row['bitis_tarihi']) ||
        $row['bitis_tarihi'] == '0000-00-00';

    $bazTarife = (float)$row['birim_fiyat'];
    $motorinBaz = (float)$row['motorin_baz_fiyati'];
    $gunlukMotorin = (float)($row['motorin_fiyati'] ?? 0);

    $farkTutar = $gunlukMotorin - $motorinBaz;
    $farkYuzde = 0;

    if($motorinBaz > 0){
        $farkYuzde = ($farkTutar / $motorinBaz) * 100;
    }

    $zamIndirim = 0;
    $guncellenecek = false;

    if($aktifMi && abs($farkYuzde) >= 7){
        $zamIndirim =
            (($bazTarife * 40) / 100)
            * ($farkYuzde / 100);

        $guncellenecek = true;
    }

    return [
        'aktif' => $aktifMi,
        'baz_tarife' => $bazTarife,
        'motorin_baz' => $motorinBaz,
        'gunluk_motorin' => $gunlukMotorin,
        'fark_tutar' => $farkTutar,
        'fark_yuzde' => $farkYuzde,
        'zam_indirim' => $zamIndirim,
        'yeni_fiyat' => $bazTarife + $zamIndirim,
        'guncellenecek' => $guncellenecek
    ];
}

/*
MOTORIN GUNCELLE
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
            ESKI TARIFEYI KAPAT
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
            YENI TARIFE
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
TARIFELER
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

$durum = $_GET['durum'] ?? 'aktif';
$firma = $_GET['firma'] ?? '';
$arama = trim($_GET['arama'] ?? '');

$firmalar = [];
$aktifSayisi = 0;
$gecmisSayisi = 0;
$guncellenecekSayisi = 0;
$sonMotorin = 0;
$sonMotorinTarih = '';
$filteredRows = [];

foreach($rows as $row){

    $firmalar[$row['firma_adi']] = $row['firma_adi'];

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
        $durum == 'tum' ||
        ($durum == 'aktif' && $hesap['aktif']) ||
        ($durum == 'gecmis' && !$hesap['aktif']) ||
        ($durum == 'guncellenecek' && $hesap['guncellenecek']);

    $firmaUygun =
        $firma == '' ||
        $row['firma_adi'] == $firma;

    $aramaMetni =
        mb_strtolower(
            $row['firma_adi']
            . ' '
            . $row['cikis_noktasi']
            . ' '
            . $row['varis_noktasi'],
            'UTF-8'
        );

    $aramaUygun =
        $arama == '' ||
        mb_strpos($aramaMetni, mb_strtolower($arama, 'UTF-8')) !== false;

    if($durumUygun && $firmaUygun && $aramaUygun){
        $row['_hesap'] = $hesap;
        $filteredRows[] = $row;
    }
}

ksort($firmalar, SORT_NATURAL | SORT_FLAG_CASE);

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

.alert{
    background:#dcfce7;
    color:#166534;
    padding:14px 18px;
    border-radius:8px;
    margin-bottom:20px;
    font-size:16px;
    font-weight:bold;
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

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(160px,1fr));
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
    grid-template-columns:1.3fr 1fr 1fr auto;
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
    font-size:14px;
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
    min-width:1180px;
    border-collapse:collapse;
    table-layout:auto;
}

table th{
    background:#f9fafb;
    color:#6b7280;
    padding:11px 14px;
    font-size:11px;
    border-bottom:1px solid #e5e7eb;
    text-align:left;
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.04em;
}

table td{
    padding:14px;
    border-bottom:1px solid #e5e7eb;
    font-size:13px;
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
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
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

.badge-blue{
    background:#dbeafe;
    color:#1d4ed8;
}

.route{
    min-width:220px;
}

.route strong{
    display:block;
    color:#111827;
    margin-bottom:4px;
}

.route span{
    color:#6b7280;
}

.muted{
    color:#6b7280;
}

.price-main{
    display:block;
    color:#111827;
    font-weight:700;
    font-size:15px;
}

.price-sub{
    display:block;
    color:#6b7280;
    font-size:12px;
    margin-top:3px;
}

.empty-state{
    padding:32px;
    text-align:center;
    color:#6b7280;
}

@media(max-width:900px){
    .topbar{
        display:block;
    }

    .top-actions{
        justify-content:flex-start;
        margin-top:16px;
    }

    .summary-grid,
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

        <div>

            <h2>Tarife Yönetimi</h2>

            <p>
                Aktif fiyatlar, geçmiş revizyonlar ve motorin etkisi tek ekranda.
            </p>

        </div>

        <div class="top-actions">

            <a href="tarife-ekle.php" class="btn">
                + Yeni Tarife
            </a>

            <a href="tarife-yukle.php" class="btn btn-orange">
                Excelden Yükle
            </a>

            <form method="POST" style="margin:0;">

                <button
                type="submit"
                name="motorin_guncelle"
                class="btn btn-blue">

                    Motorine Göre Güncelle

                </button>

            </form>

        </div>

    </div>

    <?php if($message): ?>

        <div class="alert">
            <?php echo e($message); ?>
        </div>

    <?php endif; ?>

    <div class="summary-grid">

        <div class="summary-card">
            <span>Aktif tarife</span>
            <strong><?php echo number_format($aktifSayisi); ?></strong>
        </div>

        <div class="summary-card">
            <span>Geçmiş revizyon</span>
            <strong><?php echo number_format($gecmisSayisi); ?></strong>
        </div>

        <div class="summary-card">
            <span>Güncelleme eşiğinde</span>
            <strong><?php echo number_format($guncellenecekSayisi); ?></strong>
        </div>

        <div class="summary-card">
            <span>Güncel motorin</span>
            <strong><?php echo paraGoster($sonMotorin, 3); ?></strong>
        </div>

    </div>

    <div class="toolbar">

        <form method="GET" class="filters">

            <div class="field">
                <label>Firma veya güzergah ara</label>
                <input
                    type="text"
                    name="arama"
                    value="<?php echo e($arama); ?>"
                    placeholder="Firma, çıkış veya varış"
                >
            </div>

            <div class="field">
                <label>Firma</label>
                <select name="firma">
                    <option value="">Tüm firmalar</option>
                    <?php foreach($firmalar as $firmaAdi): ?>
                        <option
                            value="<?php echo e($firmaAdi); ?>"
                            <?php echo $firmaAdi == $firma ? 'selected' : ''; ?>>
                            <?php echo e($firmaAdi); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Durum</label>
                <select name="durum">
                    <option value="aktif" <?php echo $durum == 'aktif' ? 'selected' : ''; ?>>
                        Aktif tarifeler
                    </option>
                    <option value="guncellenecek" <?php echo $durum == 'guncellenecek' ? 'selected' : ''; ?>>
                        Güncellenecekler
                    </option>
                    <option value="gecmis" <?php echo $durum == 'gecmis' ? 'selected' : ''; ?>>
                        Geçmiş revizyonlar
                    </option>
                    <option value="tum" <?php echo $durum == 'tum' ? 'selected' : ''; ?>>
                        Tüm kayıtlar
                    </option>
                </select>
            </div>

            <button type="submit" class="btn">
                Filtrele
            </button>

        </form>

    </div>

    <div class="table-card">

        <div class="table-head">

            <div>
                <h3>Tarife Listesi</h3>
                <p>
                    <?php echo number_format(count($filteredRows)); ?>
                    kayıt gösteriliyor. Motorin farkı %7 ve üzerindeyse güncelleme önerilir.
                </p>
            </div>

        </div>

        <?php if(empty($filteredRows)): ?>

            <div class="empty-state">
                Seçilen filtrelere uygun tarife bulunamadı.
            </div>

        <?php else: ?>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Firma ve Güzergah</th>
                        <th>Durum</th>
                        <th>Dönem</th>
                        <th class="right">Mevcut Fiyat</th>
                        <th class="right">Motorin Etkisi</th>
                        <th class="right">Önerilen Fiyat</th>
                        <th>İşlem Durumu</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach($filteredRows as $row): ?>

                <?php

                $hesap = $row['_hesap'];
                $aktifMi = $hesap['aktif'];

                ?>

                <tr class="<?php echo $aktifMi ? '' : 'pasif-row'; ?>">

                    <td class="route">
                        <strong><?php echo e($row['firma_adi']); ?></strong>
                        <span>
                            <?php echo e($row['cikis_noktasi']); ?>
                            →
                            <?php echo e($row['varis_noktasi']); ?>
                        </span>
                    </td>

                    <td>

                        <?php if($aktifMi): ?>

                            <span class="badge badge-green">
                                Aktif
                            </span>

                        <?php else: ?>

                            <span class="badge badge-gray">
                                Geçmiş
                            </span>

                        <?php endif; ?>

                        <span class="badge badge-blue">
                            Rev. <?php echo e($row['revizyon_no']); ?>
                        </span>

                    </td>

                    <td>
                        <span class="price-main">
                            <?php echo tarihAraligi($row['baslangic_tarihi'], $row['bitis_tarihi']); ?>
                        </span>
                        <span class="price-sub">
                            Kayıt no: <?php echo e($row['id']); ?>
                        </span>
                    </td>

                    <td class="right">
                        <span class="price-main">
                            <?php echo paraGoster($hesap['baz_tarife']); ?>
                        </span>
                        <span class="price-sub">
                            Baz motorin:
                            <?php echo paraGoster($hesap['motorin_baz'], 3); ?>
                        </span>
                    </td>

                    <td class="right">
                        <span class="price-main <?php echo $hesap['fark_tutar'] >= 0 ? 'orange' : 'green'; ?>">
                            <?php echo paraGoster($hesap['fark_tutar']); ?>
                        </span>
                        <span class="price-sub">
                            <?php echo yuzdeGoster($hesap['fark_yuzde']); ?>
                            |
                            Güncel:
                            <?php echo paraGoster($hesap['gunluk_motorin'], 3); ?>
                        </span>
                    </td>

                    <td class="right blue">
                        <span class="price-main">
                            <?php echo paraGoster($hesap['yeni_fiyat']); ?>
                        </span>
                        <span class="price-sub">
                            Etki:
                            <?php echo paraGoster($hesap['zam_indirim']); ?>
                        </span>
                    </td>

                    <td>

                        <?php if($hesap['guncellenecek']): ?>

                            <span class="badge badge-green">
                                Güncellenecek
                            </span>

                        <?php elseif($aktifMi): ?>

                            <span class="badge badge-red">
                                Eşik altında
                            </span>

                        <?php else: ?>

                            <span class="badge badge-gray">
                                Arşiv
                            </span>

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

</body>
</html>
