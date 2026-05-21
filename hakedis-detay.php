<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$hakedis_id = $_GET['hakedis_id'] ?? 0;

if(!$hakedis_id){
    die('Hakediş ID bulunamadı.');
}

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
$hakedis = $hakedisQuery->fetch(PDO::FETCH_ASSOC);

$query = $db->prepare("
    SELECT *
    FROM hakedis_satirlari
    WHERE hakedis_id = ?
    ORDER BY tasima_tarihi ASC, id ASC
");
$query->execute([$hakedis_id]);
$satirlar = $query->fetchAll(PDO::FETCH_ASSOC);

$toplamBaz = 0;
$toplamZam = 0;
$toplamGuncel = 0;
$toplamKdv = 0;
$toplamTevkifat = 0;
$toplamNet = 0;
$zamliSatir = 0;
$indirimliSatir = 0;
$degisenSatir = 0;
$satirDurumlari = [];
$rotaBazFiyatlari = [];
$birimFiyatOzetleri = [];

foreach($satirlar as $row){
    $toplamBaz += (float)$row['birim_fiyat'];
    $toplamZam += (float)$row['zam_indirim_tutari'];
    $toplamGuncel += (float)$row['guncel_birim_fiyat'];
    $toplamKdv += (float)$row['kdv_tutari'];
    $toplamTevkifat += (float)$row['tevkifat_tutari'];
    $toplamNet += (float)$row['net_tutar'];

    $rotaKey = ($row['cikis_noktasi'] ?? '') . '|' . ($row['varis_noktasi'] ?? '');
    $birimFiyat = (float)$row['birim_fiyat'];
    $guncelFiyat = (float)$row['guncel_birim_fiyat'];
    $birimOzetKey = $rotaKey . '|' . number_format($guncelFiyat, 2, '.', '');

    if(!isset($birimFiyatOzetleri[$birimOzetKey])){
        $birimFiyatOzetleri[$birimOzetKey] = [
            'cikis' => $row['cikis_noktasi'] ?? '',
            'varis' => $row['varis_noktasi'] ?? '',
            'birim_fiyat' => $guncelFiyat,
            'sefer' => 0,
            'toplam' => 0
        ];
    }

    $birimFiyatOzetleri[$birimOzetKey]['sefer']++;
    $birimFiyatOzetleri[$birimOzetKey]['toplam'] += $guncelFiyat;

    if(!isset($rotaBazFiyatlari[$rotaKey])){
        $rotaBazFiyatlari[$rotaKey] = $birimFiyat;
    }

    $rotaBaz = $rotaBazFiyatlari[$rotaKey];
    $zamTutar = (float)$row['zam_indirim_tutari'];
    $durum = [
        'yon' => 'flat',
        'metin' => 'Sabit'
    ];

    if($zamTutar > 0){
        $durum = [
            'yon' => 'up',
            'metin' => 'Zamlı'
        ];
        $zamliSatir++;
        $degisenSatir++;
    } elseif($zamTutar < 0){
        $durum = [
            'yon' => 'down',
            'metin' => 'İndirim'
        ];
        $indirimliSatir++;
        $degisenSatir++;
    } elseif($birimFiyat > ($rotaBaz + 0.005)){
        $durum = [
            'yon' => 'up',
            'metin' => 'Fiyat ↑'
        ];
        $zamliSatir++;
        $degisenSatir++;
    } elseif($birimFiyat < ($rotaBaz - 0.005)){
        $durum = [
            'yon' => 'down',
            'metin' => 'Fiyat ↓'
        ];
        $indirimliSatir++;
        $degisenSatir++;
    }

    $satirDurumlari[(int)$row['id']] = $durum;
}

$sabitSatir = count($satirlar) - $degisenSatir;

function para($value, $decimal = 2){
    return '₺' . number_format((float)$value, $decimal, ',', '.');
}

function yuzde($value){
    return '%' . number_format((float)$value, 2, ',', '.');
}

function gorunenMetin($value){
    return str_replace(
        ['?ahin', '?irketi', 'BÃœYÃœKYA?CI', 'Ä®?FTL?K', 'Ã‡?FTL?K', 'BA??Ä®?', 'ESENBO?A', 'GÃ–LBA?I', 'G?MAT', 'S?NCAN', 'YEN?KENT'],
        ['Şahin', 'Şirketi', 'BÜYÜKYAĞCI', 'ÇİFTLİK', 'ÇİFTLİK', 'BAŞİSKELE', 'ESENBOĞA', 'GÖLBAŞI', 'GİMAT', 'SİNCAN', 'YENİKENT'],
        (string)$value
    );
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Hakediş Detay</title>
<link rel="stylesheet" href="assets/css/style.css">

<style>
.detail-header{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:flex-start;
}

.detail-title h2{
    margin-bottom:6px;
}

.detail-meta{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    justify-content:flex-end;
}

.meta-pill,
.legend-pill{
    border:1px solid #e5e7eb;
    border-radius:999px;
    padding:7px 10px;
    font-size:11px;
    color:#475569;
    background:#fff;
    white-space:nowrap;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(6, minmax(120px, 1fr));
    gap:10px;
    margin-bottom:16px;
}

.summary-card{
    background:#fff;
    border:1px solid #e7eaf0;
    border-radius:10px;
    padding:10px;
}

.summary-card span{
    display:block;
    font-size:10px;
    color:#64748b;
    margin-bottom:5px;
}

.summary-card strong{
    display:block;
    font-size:15px;
    color:#0f172a;
}

.table-area{
    background:white;
    border:1px solid #e7eaf0;
    border-radius:10px;
    overflow:auto;
}

.detail-table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}

.detail-table th{
    position:sticky;
    top:0;
    z-index:2;
    background:#17365f;
    color:white;
    padding:7px 6px;
    font-size:10px;
    line-height:1.1;
    text-align:center;
    border-right:1px solid rgba(255,255,255,.14);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.detail-table td{
    padding:6px;
    border-bottom:1px solid #eef2f7;
    border-right:1px solid #eef2f7;
    font-size:10px;
    line-height:1.15;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.detail-table th:nth-child(1),
.detail-table td:nth-child(1){width:3%;}
.detail-table th:nth-child(2),
.detail-table td:nth-child(2){width:6%;}
.detail-table th:nth-child(3),
.detail-table td:nth-child(3){width:12%;}
.detail-table th:nth-child(4),
.detail-table td:nth-child(4){width:8%;}
.detail-table th:nth-child(5),
.detail-table td:nth-child(5){width:7%;}
.detail-table th:nth-child(6),
.detail-table td:nth-child(6){width:7%;}
.detail-table th:nth-child(7),
.detail-table td:nth-child(7){width:6%;}
.detail-table th:nth-child(8),
.detail-table td:nth-child(8){width:6%;}
.detail-table th:nth-child(9),
.detail-table td:nth-child(9){width:5%;}
.detail-table th:nth-child(10),
.detail-table td:nth-child(10){width:5%;}
.detail-table th:nth-child(11),
.detail-table td:nth-child(11){width:6%;}
.detail-table th:nth-child(12),
.detail-table td:nth-child(12){width:6%;}
.detail-table th:nth-child(13),
.detail-table td:nth-child(13){width:7%;}
.detail-table th:nth-child(14),
.detail-table td:nth-child(14){width:5%;}
.detail-table th:nth-child(15),
.detail-table td:nth-child(15){width:5%;}
.detail-table th:nth-child(16),
.detail-table td:nth-child(16){width:6%;}

.detail-table tbody tr:hover{
    background:#f8fafc;
}

.row-up{
    background:#ecfdf5;
}

.row-down{
    background:#fff1f2;
}

.row-up td:first-child{
    border-left:4px solid #16a34a;
}

.row-down td:first-child{
    border-left:4px solid #dc2626;
}

.status-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:54px;
    border-radius:999px;
    padding:4px 7px;
    font-size:9px;
    font-weight:700;
}

.status-up{
    color:#166534;
    background:#dcfce7;
}

.status-down{
    color:#991b1b;
    background:#fee2e2;
}

.status-flat{
    color:#475569;
    background:#f1f5f9;
}

.right{
    text-align:right;
    font-variant-numeric:tabular-nums;
}

.center{
    text-align:center;
}

.strong-money{
    font-weight:700;
    color:#0f3e68;
}

.total-row{
    background:#f8fafc;
    font-weight:700;
}

.total-row td{
    border-top:2px solid #dbe4ee;
}

.footer-summary{
    display:grid;
    grid-template-columns:1.2fr .8fr;
    gap:14px;
    margin-top:16px;
}

.summary-box{
    background:#fff;
    border:1px solid #e7eaf0;
    border-radius:10px;
    padding:14px;
}

.summary-box h3{
    font-size:14px;
    margin-bottom:10px;
}

.calc-line{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:7px 0;
    border-bottom:1px solid #eef2f7;
    font-size:12px;
}

.calc-line:last-child{
    border-bottom:0;
}

.calc-line strong{
    font-variant-numeric:tabular-nums;
}

.net-line{
    color:#166534;
    font-weight:700;
}

.unit-breakdown{
    margin-bottom:10px;
}

.unit-breakdown .calc-line{
    align-items:flex-start;
}

.unit-detail{
    color:#64748b;
    font-size:11px;
    margin-top:2px;
}

.legend{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.legend-pill.up{
    border-color:#bbf7d0;
    background:#ecfdf5;
    color:#166534;
}

.legend-pill.down{
    border-color:#fecdd3;
    background:#fff1f2;
    color:#991b1b;
}

@media(max-width:1200px){
    .summary-grid{
        grid-template-columns:repeat(3, 1fr);
    }
}

@media(max-width:760px){
    .detail-header,
    .footer-summary{
        grid-template-columns:1fr;
        display:block;
    }

    .detail-meta,
    .legend{
        justify-content:flex-start;
        margin-top:10px;
    }

    .summary-grid{
        grid-template-columns:repeat(2, 1fr);
    }

    .detail-table{
        min-width:980px;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar detail-header">
        <div class="detail-title">
            <h2>Hakediş Detayları</h2>
            <p><?php echo htmlspecialchars(gorunenMetin($hakedis['firma_adi'] ?? '')); ?> için satır bazlı hesap özeti</p>
        </div>

        <div class="detail-meta">
            <span class="meta-pill">Dönem: <?php echo htmlspecialchars($hakedis['donem'] ?? '-'); ?></span>
            <span class="meta-pill">Sözleşme: <?php echo htmlspecialchars($hakedis['sozlesme_no'] ?? '-'); ?></span>
            <span class="meta-pill">Satır: <?php echo count($satirlar); ?></span>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <span>KDV hariç toplam</span>
            <strong><?php echo para($toplamGuncel); ?></strong>
        </div>
        <div class="summary-card">
            <span>Zam / indirim etkisi</span>
            <strong><?php echo para($toplamZam); ?></strong>
        </div>
        <div class="summary-card">
            <span>KDV</span>
            <strong><?php echo para($toplamKdv); ?></strong>
        </div>
        <div class="summary-card">
            <span>Tevkifat</span>
            <strong><?php echo para($toplamTevkifat); ?></strong>
        </div>
        <div class="summary-card">
            <span>Net hakediş</span>
            <strong><?php echo para($toplamNet); ?></strong>
        </div>
        <div class="summary-card">
            <span>Değişen / sabit</span>
            <strong><?php echo $degisenSatir; ?> / <?php echo $sabitSatir; ?></strong>
        </div>
    </div>

    <div class="table-area">
        <table class="detail-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tarih</th>
                    <th>İrsaliye</th>
                    <th>Çıkış</th>
                    <th>Varış</th>
                    <th>Baz</th>
                    <th>M.Baz</th>
                    <th>G.Motorin</th>
                    <th>Fark</th>
                    <th>%</th>
                    <th>Durum</th>
                    <th>Zam/İnd.</th>
                    <th>Güncel</th>
                    <th>KDV</th>
                    <th>Tevk.</th>
                    <th>Net</th>
                </tr>
            </thead>
            <tbody>
            <?php $sira = 1; ?>
            <?php foreach($satirlar as $row): ?>
                <?php
                    $durum = $satirDurumlari[(int)$row['id']] ?? ['yon' => 'flat', 'metin' => 'Sabit'];
                    $rowClass = '';
                    $statusClass = 'status-flat';
                    $statusText = $durum['metin'];

                    if($durum['yon'] === 'up'){
                        $rowClass = 'row-up';
                        $statusClass = 'status-up';
                    } elseif($durum['yon'] === 'down'){
                        $rowClass = 'row-down';
                        $statusClass = 'status-down';
                    }
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td class="center"><?php echo $sira++; ?></td>
                    <td class="center"><?php echo date('d.m.Y', strtotime($row['tasima_tarihi'])); ?></td>
                    <td class="center" title="<?php echo htmlspecialchars($row['irsaliye_no']); ?>"><?php echo htmlspecialchars($row['irsaliye_no']); ?></td>
                    <td class="center" title="<?php echo htmlspecialchars(gorunenMetin($row['cikis_noktasi'])); ?>"><?php echo htmlspecialchars(gorunenMetin($row['cikis_noktasi'])); ?></td>
                    <td class="center" title="<?php echo htmlspecialchars(gorunenMetin($row['varis_noktasi'])); ?>"><?php echo htmlspecialchars(gorunenMetin($row['varis_noktasi'])); ?></td>
                    <td class="right"><?php echo para($row['birim_fiyat']); ?></td>
                    <td class="right"><?php echo para($row['motorin_baz_fiyati'], 3); ?></td>
                    <td class="right"><?php echo para($row['gunluk_motorin_fiyati'], 3); ?></td>
                    <td class="right"><?php echo para($row['motorin_fark_tutari']); ?></td>
                    <td class="right"><?php echo yuzde($row['motorin_fark_yuzde']); ?></td>
                    <td class="center"><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                    <td class="right"><?php echo para($row['zam_indirim_tutari']); ?></td>
                    <td class="right strong-money"><?php echo para($row['guncel_birim_fiyat']); ?></td>
                    <td class="right"><?php echo para($row['kdv_tutari']); ?></td>
                    <td class="right"><?php echo para($row['tevkifat_tutari']); ?></td>
                    <td class="right strong-money"><?php echo para($row['net_tutar']); ?></td>
                </tr>
            <?php endforeach; ?>

                <tr class="total-row">
                    <td colspan="5" class="center">TOPLAM</td>
                    <td class="right"><?php echo para($toplamBaz); ?></td>
                    <td colspan="5"></td>
                    <td class="right"><?php echo para($toplamZam); ?></td>
                    <td class="right"><?php echo para($toplamGuncel); ?></td>
                    <td class="right"><?php echo para($toplamKdv); ?></td>
                    <td class="right"><?php echo para($toplamTevkifat); ?></td>
                    <td class="right"><?php echo para($toplamNet); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="footer-summary">
        <div class="summary-box">
            <h3>Toplama Nasıl Ulaşıldı?</h3>
            <div class="unit-breakdown">
                <?php foreach($birimFiyatOzetleri as $ozet): ?>
                    <div class="calc-line">
                        <span>
                            <?php echo htmlspecialchars(gorunenMetin($ozet['cikis'])); ?>
                            →
                            <?php echo htmlspecialchars(gorunenMetin($ozet['varis'])); ?>
                            <div class="unit-detail">
                                <?php echo para($ozet['birim_fiyat']); ?>
                                x
                                <?php echo (int)$ozet['sefer']; ?>
                                sefer
                            </div>
                        </span>
                        <strong><?php echo para($ozet['toplam']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="calc-line">
                <span>Baz taşıma toplamı</span>
                <strong><?php echo para($toplamBaz); ?></strong>
            </div>
            <div class="calc-line">
                <span>Motorin kaynaklı zam / indirim</span>
                <strong><?php echo para($toplamZam); ?></strong>
            </div>
            <div class="calc-line">
                <span>KDV hariç güncel toplam</span>
                <strong><?php echo para($toplamGuncel); ?></strong>
            </div>
            <div class="calc-line">
                <span>KDV (%20)</span>
                <strong><?php echo para($toplamKdv); ?></strong>
            </div>
            <div class="calc-line">
                <span>KDV tevkifatı (%20)</span>
                <strong><?php echo para($toplamTevkifat); ?></strong>
            </div>
            <div class="calc-line net-line">
                <span>Net hakediş</span>
                <strong><?php echo para($toplamNet); ?></strong>
            </div>
        </div>

        <div class="summary-box">
            <h3>Kısa Özet</h3>
            <div class="calc-line">
                <span>Toplam satır</span>
                <strong><?php echo count($satirlar); ?></strong>
            </div>
            <div class="calc-line">
                <span>Zamlı / fiyat artan satır</span>
                <strong><?php echo $zamliSatir; ?></strong>
            </div>
            <div class="calc-line">
                <span>İndirimli / fiyat düşen satır</span>
                <strong><?php echo $indirimliSatir; ?></strong>
            </div>
            <div class="calc-line">
                <span>Değişmeyen satır</span>
                <strong><?php echo $sabitSatir; ?></strong>
            </div>
            <div class="legend">
                <span class="legend-pill up">Yeşil: zamlı veya fiyat artmış satır</span>
                <span class="legend-pill down">Kırmızı: indirimli veya fiyat düşmüş satır</span>
                <span class="legend-pill">Gri: fiyat değişmedi</span>
            </div>
        </div>
    </div>

</div>

</body>
</html>
