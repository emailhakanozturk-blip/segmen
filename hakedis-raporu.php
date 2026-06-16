<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$hakedisId = (int)($_GET['hakedis_id'] ?? 0);

if(!$hakedisId){
    die('Hakediş bulunamadı.');
}

function para($value){
    return number_format((float)$value, 2, ',', '.');
}

function tarih($value){
    return $value ? date('d.m.Y', strtotime($value)) : '-';
}

$query = $db->prepare("
    SELECT
        hakedisler.*,
        cariler.firma_adi,
        cariler.yetkili,
        sozlesmeler.sozlesme_no,
        sozlesmeler.sozlesme_tutari
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    LEFT JOIN sozlesmeler ON sozlesmeler.id = hakedisler.sozlesme_id
    WHERE hakedisler.id = ?
    LIMIT 1
");
$query->execute([$hakedisId]);
$hakedis = $query->fetch(PDO::FETCH_ASSOC);

if(!$hakedis){
    die('Hakediş bulunamadı.');
}

$previousQuery = $db->prepare("
    SELECT
        COALESCE(SUM(toplam_tutar), 0) AS onceki_toplam,
        COUNT(*) AS onceki_adet
    FROM hakedisler
    WHERE sozlesme_id = ?
    AND (
        baslangic_tarihi < ?
        OR (baslangic_tarihi = ? AND id < ?)
    )
");
$previousQuery->execute([
    $hakedis['sozlesme_id'],
    $hakedis['baslangic_tarihi'],
    $hakedis['baslangic_tarihi'],
    $hakedisId
]);
$onceki = $previousQuery->fetch(PDO::FETCH_ASSOC);

$currentBase = (float)$hakedis['toplam_tutar'];
$previousTotal = (float)$onceki['onceki_toplam'];
$cumulativeTotal = $previousTotal + $currentBase;
$priceDiff = 0;
$totalWithDiff = $cumulativeTotal + $priceDiff;
$kdv = (float)$hakedis['kdv_tutar'];
$tahakkuk = $currentBase + $kdv;
$kdvTevkifat = (float)$hakedis['tevkifat_tutar'];
$kesintiToplam = $kdvTevkifat;
$odenecek = $tahakkuk - $kesintiToplam;
$kalanSozlesme = (float)$hakedis['sozlesme_tutari'] - $cumulativeTotal;
$hakedisNo = ((int)$onceki['onceki_adet']) + 1;

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Hakediş Raporu</title>
<link rel="stylesheet" href="assets/css/style.css">

<style>
body{
    background:#eef1f5;
}

.report-shell{
    max-width:980px;
    margin:0 auto;
}

.actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-bottom:14px;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:38px;
    padding:9px 13px;
    border-radius:8px;
    border:0;
    background:#0f3e68;
    color:#fff;
    text-decoration:none;
    font-weight:700;
    cursor:pointer;
}

.sheet{
    background:#fff;
    border:1px solid #111;
    color:#000;
}

.sheet h1{
    text-align:center;
    font-size:24px;
    margin:0;
    padding:4px 0;
    border-bottom:1px solid #111;
}

.report-table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}

.report-table td,
.report-table th{
    border:1px solid #111;
    padding:7px 8px;
    font-size:15px;
    vertical-align:middle;
}

.company{
    text-align:center;
    font-weight:700;
    font-size:18px !important;
}

.center{
    text-align:center;
}

.right{
    text-align:right;
    font-variant-numeric:tabular-nums;
}

.bold{
    font-weight:700;
}

.code{
    width:7%;
    text-align:center;
    font-weight:700;
}

.amount{
    width:34%;
}

.vertical{
    writing-mode:vertical-rl;
    transform:rotate(180deg);
    text-align:center;
    font-weight:700;
    letter-spacing:1px;
}

.spacer td{
    height:22px;
}

.signature{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:120px;
    padding:70px 85px 26px;
    min-height:150px;
}

.signature div{
    text-align:center;
    font-size:18px;
}

.meta-row td{
    background:#f8fafc;
    font-weight:700;
}

.print-page-line{
    display:none;
}

@media print{
    @page{
        size:A4 portrait;
        margin:12mm 10mm 16mm;
    }

    .sidebar,
    .topbar,
    .actions{
        display:none !important;
    }

    html,
    body{
        width:auto;
        min-height:0;
        background:#fff;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    .main{
        margin:0;
        padding:0;
    }

    .report-shell{
        max-width:none;
        margin:0;
    }

    .sheet{
        width:100%;
        border:1px solid #111;
        break-inside:auto;
        page-break-inside:auto;
    }

    .sheet h1{
        padding:3px 0;
    }

    .report-table{
        page-break-inside:auto;
    }

    .report-table tr{
        break-inside:avoid;
        page-break-inside:avoid;
    }

    .report-table td,
    .report-table th{
        padding:5px 6px;
        font-size:12px;
    }

    .company{
        font-size:14px !important;
    }

    .signature{
        break-inside:avoid;
        page-break-inside:avoid;
        padding:42px 70px 18px;
        min-height:120px;
    }

    .signature div{
        font-size:14px;
    }

    .print-page-line{
        display:block;
        position:fixed;
        left:0;
        right:0;
        bottom:0;
        border-top:1px solid #111;
        height:0;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="print-page-line" aria-hidden="true"></div>
    <div class="report-shell">
        <div class="topbar">
            <h2>Hakediş Raporu</h2>
            <p><?php echo htmlspecialchars($hakedis['firma_adi']); ?> - <?php echo htmlspecialchars($hakedis['donem']); ?></p>
        </div>

        <div class="actions">
            <a class="btn" href="raporlar.php">Raporlara Dön</a>
            <button class="btn" type="button" onclick="window.print()">Yazdır</button>
        </div>

        <div class="sheet">
            <h1>HAKEDİŞ RAPORU</h1>
            <table class="report-table">
                <tr>
                    <td colspan="2" class="company">
                        SEĞMEN SU MADENCİLİK MAKİNE GIDA İNŞAAT TURİZM İTHALAT<br>
                        İHRACAT SANAYİ VE TİCARET ANONİM ŞİRKETİ
                    </td>
                    <td class="center amount">Hakediş No : <?php echo $hakedisNo; ?></td>
                </tr>
                <tr>
                    <td colspan="3" class="center">
                        <?php echo tarih($hakedis['baslangic_tarihi']); ?>-<?php echo tarih($hakedis['bitis_tarihi']); ?> TARİHİNE KADAR YAPILAN HİZMETİN
                    </td>
                </tr>
                <tr class="meta-row">
                    <td colspan="2">Sözleşme No: <?php echo htmlspecialchars($hakedis['sozlesme_no']); ?></td>
                    <td class="right">Sözleşme Tutarı: <?php echo para($hakedis['sozlesme_tutari']); ?></td>
                </tr>
                <tr class="meta-row">
                    <td colspan="2">Kümülatif Gerçekleşen</td>
                    <td class="right"><?php echo para($cumulativeTotal); ?></td>
                </tr>
                <tr class="meta-row">
                    <td colspan="2">Kalan Sözleşme Tutarı</td>
                    <td class="right"><?php echo para($kalanSozlesme); ?></td>
                </tr>
                <tr>
                    <td class="code">A</td>
                    <td>Sözleşme Fiyatları İle Yapılan Hizmet Tutarı</td>
                    <td class="right"><?php echo para($cumulativeTotal); ?></td>
                </tr>
                <tr>
                    <td class="code">B</td>
                    <td>Fiyat Farkı Tutarı</td>
                    <td class="right"><?php echo para($priceDiff); ?></td>
                </tr>
                <tr>
                    <td class="code">C</td>
                    <td class="bold">Toplam Tutar ( A + B )</td>
                    <td class="right bold"><?php echo para($totalWithDiff); ?></td>
                </tr>
                <tr class="spacer"><td colspan="3"></td></tr>
                <tr>
                    <td class="code">D</td>
                    <td>Bir Önceki Hakedişin Toplam Tutarı</td>
                    <td class="right"><?php echo para($previousTotal); ?></td>
                </tr>
                <tr>
                    <td class="code">E</td>
                    <td>Bu Hakedişin Tutarı ( C - D )</td>
                    <td class="right bold"><?php echo para($currentBase); ?></td>
                </tr>
                <tr>
                    <td class="code">F</td>
                    <td>KDV ( E x %20 )</td>
                    <td class="right"><?php echo para($kdv); ?></td>
                </tr>
                <tr>
                    <td class="code">G</td>
                    <td class="bold">Tahakkuk Tutarı</td>
                    <td class="right bold"><?php echo para($tahakkuk); ?></td>
                </tr>
                <tr>
                    <td class="code vertical" rowspan="8">KESİNTİLER VE MAHSUPLAR</td>
                    <td>a) Gelir / Kurumlar Vergisi ( E x % ... )</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td>b) Damga Vergisi ( E - g x % ... )</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td>c) KDV Tevkifatı ( F x ... )</td>
                    <td class="right"><?php echo para($kdvTevkifat); ?></td>
                </tr>
                <tr>
                    <td>d) Sosyal Sigortalar Kurumu Kesintisi</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td>e) İdare Makinesi Kiraları</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td>f) Gecikme Cezası</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td>g) Avans Mahsubu</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td>Teminat Kesintisi<br>........................<br>........................</td>
                    <td class="right"></td>
                </tr>
                <tr>
                    <td class="code">H</td>
                    <td class="bold">Kesintiler ve Mahsuplar Toplamı</td>
                    <td class="right"><?php echo para($kesintiToplam); ?></td>
                </tr>
                <tr>
                    <td></td>
                    <td class="bold">Yükleniciye Ödenecek Tutar ( G - H )</td>
                    <td class="right bold"><?php echo para($odenecek); ?></td>
                </tr>
            </table>

            <div class="signature">
                <div>
                    <strong>Yüklenici</strong><br>
                    <?php echo htmlspecialchars($hakedis['firma_adi']); ?><br>
                    <?php echo htmlspecialchars($hakedis['yetkili'] ?: ''); ?>
                </div>
                <div>
                    <strong>Düzenleyen</strong><br>
                    ................................
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
