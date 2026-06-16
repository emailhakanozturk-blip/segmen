<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$hakedisId = (int)($_GET['hakedis_id'] ?? 0);

if(!$hakedisId){
    die('HakediÅŸ bulunamadÄ±.');
}

function tarih($value){
    return $value ? date('d.m.Y', strtotime($value)) : '-';
}

function donemGoster($value){
    if(preg_match('/^(\d{4})-(\d{1,2})$/', (string)$value, $match)){
        return sprintf('%04d-%02d', (int)$match[1], (int)$match[2]);
    }

    return (string)$value;
}

$query = $db->prepare("
    SELECT
        hakedisler.*,
        cariler.firma_adi,
        sozlesmeler.sozlesme_no
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    LEFT JOIN sozlesmeler ON sozlesmeler.id = hakedisler.sozlesme_id
    WHERE hakedisler.id = ?
    LIMIT 1
");
$query->execute([$hakedisId]);
$hakedis = $query->fetch(PDO::FETCH_ASSOC);

if(!$hakedis){
    die('HakediÅŸ bulunamadÄ±.');
}

$satirQuery = $db->prepare("
    SELECT
        COUNT(*) AS sevkiyat_sayisi,
        MIN(tasima_tarihi) AS ilk_sevkiyat_tarihi,
        MAX(tasima_tarihi) AS son_sevkiyat_tarihi,
        GROUP_CONCAT(DISTINCT cikis_noktasi ORDER BY cikis_noktasi SEPARATOR ', ') AS yukleme_noktalari,
        GROUP_CONCAT(DISTINCT varis_noktasi ORDER BY varis_noktasi SEPARATOR ', ') AS bosaltma_noktalari
    FROM hakedis_satirlari
    WHERE hakedis_id = ?
");
$satirQuery->execute([$hakedisId]);
$ozet = $satirQuery->fetch(PDO::FETCH_ASSOC);

$sevkiyatQuery = $db->prepare("
    SELECT
        cikis_noktasi,
        varis_noktasi,
        COUNT(*) AS sefer_sayisi,
        COUNT(DISTINCT REGEXP_REPLACE(irsaliye_no, '-[0-9]+$', '')) AS irsaliye_sayisi,
        MIN(tasima_tarihi) AS ilk_tarih,
        MAX(tasima_tarihi) AS son_tarih
    FROM hakedis_satirlari
    WHERE hakedis_id = ?
    GROUP BY cikis_noktasi, varis_noktasi
    ORDER BY cikis_noktasi ASC, varis_noktasi ASC
");
$sevkiyatQuery->execute([$hakedisId]);
$sevkiyatOzetleri = $sevkiyatQuery->fetchAll(PDO::FETCH_ASSOC);

$donemBaslangic = $ozet['ilk_sevkiyat_tarihi'] ?: $hakedis['baslangic_tarihi'];
$donemBitis = $ozet['son_sevkiyat_tarihi'] ?: $hakedis['bitis_tarihi'];

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Muayene ve Kabul TutanaÄŸÄ±</title>
<link rel="stylesheet" href="assets/css/style.css">

<style>
body{
    background:#eef1f5;
}

.report-shell{
    max-width:980px;
    margin:0 auto;
}

.actions,
.member-editor{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.actions{
    justify-content:flex-end;
}

.member-editor{
    background:#fff;
    border:1px solid #dbe3ef;
    border-radius:10px;
    padding:14px;
}

.member-editor label{
    display:block;
    font-size:12px;
    font-weight:700;
    color:#475569;
    margin-bottom:5px;
}

.member-editor input{
    width:190px;
    border:1px solid #cfd8e3;
    border-radius:7px;
    padding:9px;
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

.document{
    background:#fff;
    border:1px solid #dbe3ef;
    padding:34px;
    color:#111827;
}

.document h1{
    text-align:center;
    font-size:22px;
    letter-spacing:.5px;
    margin-bottom:24px;
}

.info-table,
.shipment-table,
.commission-table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:18px;
}

.info-table th,
.info-table td,
.shipment-table th,
.shipment-table td,
.commission-table th,
.commission-table td{
    border:1px solid #cfd8e3;
    padding:9px 10px;
    font-size:12px;
    vertical-align:top;
}

.info-table th{
    width:22%;
    background:#f8fafc;
    text-align:left;
}

.shipment-table th,
.commission-table th{
    background:#f8fafc;
    text-align:left;
}

.pivot-total td{
    background:#f8fafc;
    font-weight:700;
}

.section-title{
    font-weight:700;
    margin:18px 0 8px;
}

.decision{
    border:1px solid #cfd8e3;
    padding:13px;
    min-height:68px;
    line-height:1.55;
    font-size:12px;
    margin-bottom:16px;
}

.sign-cell{
    height:66px;
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
    .actions,
    .member-editor{
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

    .document{
        border:0;
        padding:0;
    }

    .document h1{
        margin:0 0 16px;
        font-size:20px;
    }

    .info-table,
    .shipment-table,
    .commission-table{
        margin-bottom:12px;
        page-break-inside:auto;
    }

    .info-table tr,
    .shipment-table tr,
    .commission-table tr,
    .decision,
    .section-title{
        break-inside:avoid;
        page-break-inside:avoid;
    }

    .info-table th,
    .info-table td,
    .shipment-table th,
    .shipment-table td,
    .commission-table th,
    .commission-table td{
        padding:7px 8px;
        font-size:11px;
    }

    .section-title{
        margin:13px 0 6px;
    }

    .decision{
        min-height:56px;
        margin-bottom:12px;
    }

    .sign-cell{
        height:54px;
    }

    .print-page-line{
        display:block;
        position:fixed;
        left:0;
        right:0;
        bottom:0;
        border-top:1px solid #111827;
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
            <h2>Muayene Kabul TutanaÄŸÄ±</h2>
            <p><?php echo htmlspecialchars($hakedis['firma_adi']); ?> - <?php echo htmlspecialchars(donemGoster($hakedis['donem'])); ?></p>
        </div>

        <div class="member-editor">
            <div>
                <label>BaÅŸkan Ad Soyad</label>
                <input type="text" data-member-name="0" placeholder="Ad Soyad">
            </div>
            <div>
                <label>1. Ãœye Ad Soyad</label>
                <input type="text" data-member-name="1" placeholder="Ad Soyad">
            </div>
            <div>
                <label>2. Ãœye Ad Soyad</label>
                <input type="text" data-member-name="2" placeholder="Ad Soyad">
            </div>
        </div>

        <div class="actions">
            <a class="btn" href="raporlar.php">Raporlara DÃ¶n</a>
            <button class="btn" type="button" onclick="window.print()">YazdÄ±r</button>
        </div>

        <div class="document">
            <h1>MUAYENE VE KABUL TUTANAÄI</h1>

            <table class="info-table">
                <tr>
                    <th>YÃ¼klenici</th>
                    <td colspan="3"><?php echo htmlspecialchars($hakedis['firma_adi']); ?></td>
                </tr>
                <tr>
                    <th>DÃ¶nem BaÅŸlangÄ±Ã§</th>
                    <td><?php echo tarih($donemBaslangic); ?></td>
                    <th>DÃ¶nem BitiÅŸ</th>
                    <td><?php echo tarih($donemBitis); ?></td>
                </tr>
                <tr>
                    <th>Toplam Sevkiyat</th>
                    <td><?php echo (int)$ozet['sevkiyat_sayisi']; ?></td>
                    <th>HakediÅŸ KaydÄ±</th>
                    <td>HakediÅŸ <?php echo htmlspecialchars(donemGoster($hakedis['donem'])); ?></td>
                </tr>
                <tr>
                    <th>YÃ¼kleme NoktalarÄ±</th>
                    <td colspan="3"><?php echo htmlspecialchars($ozet['yukleme_noktalari'] ?: '-'); ?></td>
                </tr>
                <tr>
                    <th>BoÅŸaltma NoktalarÄ±</th>
                    <td colspan="3"><?php echo htmlspecialchars($ozet['bosaltma_noktalari'] ?: '-'); ?></td>
                </tr>
            </table>

            <div class="section-title">Sevkiyat Ã–zeti</div>
            <table class="shipment-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>YÃ¼kleme NoktasÄ±</th>
                        <th>BoÅŸaltma NoktasÄ±</th>
                        <th>Sefer SayÄ±sÄ±</th>
                        <th>Ä°rsaliye Adedi</th>
                        <th>Ä°lk Sevkiyat</th>
                        <th>Son Sevkiyat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($sevkiyatOzetleri as $index => $satir): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($satir['cikis_noktasi']); ?></td>
                            <td><?php echo htmlspecialchars($satir['varis_noktasi']); ?></td>
                            <td><?php echo (int)$satir['sefer_sayisi']; ?></td>
                            <td><?php echo (int)$satir['irsaliye_sayisi']; ?></td>
                            <td><?php echo tarih($satir['ilk_tarih']); ?></td>
                            <td><?php echo tarih($satir['son_tarih']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="pivot-total">
                        <td colspan="3">TOPLAM</td>
                        <td><?php echo (int)$ozet['sevkiyat_sayisi']; ?></td>
                        <td><?php echo array_sum(array_map(fn($row) => (int)$row['irsaliye_sayisi'], $sevkiyatOzetleri)); ?></td>
                        <td><?php echo tarih($donemBaslangic); ?></td>
                        <td><?php echo tarih($donemBitis); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="section-title">Karar</div>
            <div class="decision">
                YapÄ±lan inceleme sonucunda sÃ¶z konusu hizmetin ÅŸartname ve sÃ¶zleÅŸme hÃ¼kÃ¼mlerine uygun olarak yerine getirildiÄŸi gÃ¶rÃ¼lmÃ¼ÅŸ ve kabulÃ¼ne karar verilmiÅŸtir.
            </div>

            <div class="section-title">Not</div>
            <div class="decision"></div>

            <div class="section-title">Muayene Kabul Komisyonu</div>
            <table class="commission-table">
                <thead>
                    <tr>
                        <th>Ad Soyad</th>
                        <th>Unvan</th>
                        <th>Ä°mza</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="sign-cell member-name" data-member-output="0"></td>
                        <td>BaÅŸkan</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="sign-cell member-name" data-member-output="1"></td>
                        <td>Ãœye</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="sign-cell member-name" data-member-output="2"></td>
                        <td>Ãœye</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-member-name]').forEach(input => {
    input.addEventListener('input', () => {
        const target = document.querySelector(`[data-member-output="${input.dataset.memberName}"]`);

        if(target){
            target.textContent = input.value;
        }
    });
});
</script>

</body>
</html>
