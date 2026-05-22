<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$firma = $_GET['firma'] ?? '';
$sozlesmeId = $_GET['sozlesme_id'] ?? '';

function para($value){
    return '₺' . number_format((float)$value, 2, ',', '.');
}

function kisaPara($value){
    $value = (float)$value;

    if(abs($value) >= 1000000){
        return '₺' . number_format($value / 1000000, 2, ',', '.') . ' Mn';
    }

    if(abs($value) >= 1000){
        return '₺' . number_format($value / 1000, 1, ',', '.') . ' B';
    }

    return para($value);
}

function yuzde($value){
    return number_format((float)$value, 1, ',', '.') . '%';
}

$firmalar = $db->query("
    SELECT DISTINCT cariler.firma_adi
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    WHERE cariler.firma_adi IS NOT NULL
    ORDER BY cariler.firma_adi
")->fetchAll(PDO::FETCH_COLUMN);

$sozlesmeler = $db->query("
    SELECT
        sozlesmeler.id,
        sozlesmeler.sozlesme_no,
        sozlesmeler.sozlesme_tutari,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    ORDER BY cariler.firma_adi, sozlesmeler.sozlesme_no
")->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];

if($firma !== ''){
    $where[] = 'cariler.firma_adi = ?';
    $params[] = $firma;
}

if($sozlesmeId !== ''){
    $where[] = 'hakedisler.sozlesme_id = ?';
    $params[] = $sozlesmeId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$summaryQuery = $db->prepare("
    SELECT
        COALESCE(SUM(hakedisler.toplam_tutar), 0) AS toplam_hakedis,
        COALESCE(SUM(hakedisler.kdv_tutar), 0) AS toplam_kdv,
        COALESCE(SUM(hakedisler.tevkifat_tutar), 0) AS toplam_tevkifat,
        COALESCE(SUM(hakedisler.net_tutar), 0) AS toplam_net,
        COUNT(hakedisler.id) AS hakedis_adet,
        COUNT(DISTINCT hakedisler.cari_id) AS firma_adet,
        COUNT(DISTINCT hakedisler.sozlesme_id) AS sozlesme_adet
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    $whereSql
");
$summaryQuery->execute($params);
$summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);

$shipmentCountQuery = $db->prepare("
    SELECT COUNT(hakedis_satirlari.id) AS sevkiyat_adet
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    LEFT JOIN hakedis_satirlari ON hakedis_satirlari.hakedis_id = hakedisler.id
    $whereSql
");
$shipmentCountQuery->execute($params);
$summary['sevkiyat_adet'] = (int)($shipmentCountQuery->fetch(PDO::FETCH_ASSOC)['sevkiyat_adet'] ?? 0);

$contractWhere = [];
$contractParams = [];

if($firma !== ''){
    $contractWhere[] = 'cariler.firma_adi = ?';
    $contractParams[] = $firma;
}

if($sozlesmeId !== ''){
    $contractWhere[] = 'sozlesmeler.id = ?';
    $contractParams[] = $sozlesmeId;
}

$contractWhereSql = $contractWhere ? 'WHERE ' . implode(' AND ', $contractWhere) : '';

$contractQuery = $db->prepare("
    SELECT COALESCE(SUM(sozlesmeler.sozlesme_tutari), 0) AS sozlesme_tutari
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    $contractWhereSql
");
$contractQuery->execute($contractParams);
$contractTotal = (float)($contractQuery->fetch(PDO::FETCH_ASSOC)['sozlesme_tutari'] ?? 0);
$realizationRate = $contractTotal > 0 ? ((float)$summary['toplam_hakedis'] / $contractTotal) * 100 : 0;
$remainingContract = $contractTotal - (float)$summary['toplam_hakedis'];
$averageShipment = (int)$summary['sevkiyat_adet'] > 0 ? (float)$summary['toplam_hakedis'] / (int)$summary['sevkiyat_adet'] : 0;

$firmQuery = $db->prepare("
    SELECT
        cariler.firma_adi AS label,
        COALESCE(SUM(hakedisler.toplam_tutar), 0) AS toplam,
        COUNT(DISTINCT hakedisler.id) AS adet
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    $whereSql
    GROUP BY cariler.firma_adi
    ORDER BY toplam DESC
");
$firmQuery->execute($params);
$firmChart = $firmQuery->fetchAll(PDO::FETCH_ASSOC);

$contractChartQuery = $db->prepare("
    SELECT
        CONCAT(cariler.firma_adi, ' / ', sozlesmeler.sozlesme_no) AS label,
        sozlesmeler.sozlesme_tutari,
        COALESCE(SUM(hakedisler.toplam_tutar), 0) AS toplam
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    LEFT JOIN hakedisler ON hakedisler.sozlesme_id = sozlesmeler.id
    $contractWhereSql
    GROUP BY sozlesmeler.id
    ORDER BY toplam DESC
");
$contractChartQuery->execute($contractParams);
$contractChart = $contractChartQuery->fetchAll(PDO::FETCH_ASSOC);

$monthQuery = $db->prepare("
    SELECT
        hakedisler.donem AS label,
        COALESCE(SUM(hakedisler.toplam_tutar), 0) AS toplam
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    $whereSql
    GROUP BY hakedisler.donem
    ORDER BY MIN(hakedisler.baslangic_tarihi), MIN(hakedisler.id)
");
$monthQuery->execute($params);
$monthChart = $monthQuery->fetchAll(PDO::FETCH_ASSOC);

$routeQuery = $db->prepare("
    SELECT
        CONCAT(hakedis_satirlari.cikis_noktasi, ' → ', hakedis_satirlari.varis_noktasi) AS label,
        COUNT(*) AS adet,
        COALESCE(SUM(hakedis_satirlari.guncel_birim_fiyat), 0) AS toplam
    FROM hakedis_satirlari
    LEFT JOIN hakedisler ON hakedisler.id = hakedis_satirlari.hakedis_id
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    $whereSql
    GROUP BY hakedis_satirlari.cikis_noktasi, hakedis_satirlari.varis_noktasi
    ORDER BY adet DESC
    LIMIT 8
");
$routeQuery->execute($params);
$routeChart = $routeQuery->fetchAll(PDO::FETCH_ASSOC);

$recentQuery = $db->prepare("
    SELECT
        hakedisler.id,
        hakedisler.donem,
        hakedisler.toplam_tutar,
        hakedisler.net_tutar,
        hakedisler.durum,
        hakedisler.baslangic_tarihi,
        cariler.firma_adi,
        sozlesmeler.sozlesme_no,
        COUNT(hakedis_satirlari.id) AS sevkiyat_adet
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    LEFT JOIN sozlesmeler ON sozlesmeler.id = hakedisler.sozlesme_id
    LEFT JOIN hakedis_satirlari ON hakedis_satirlari.hakedis_id = hakedisler.id
    $whereSql
    GROUP BY hakedisler.id
    ORDER BY hakedisler.baslangic_tarihi DESC, hakedisler.id DESC
    LIMIT 6
");
$recentQuery->execute($params);
$recent = $recentQuery->fetchAll(PDO::FETCH_ASSOC);

$maxFirm = max(array_column($firmChart ?: [['toplam' => 0]], 'toplam'));
$maxMonth = max(array_column($monthChart ?: [['toplam' => 0]], 'toplam'));
$maxRoute = max(array_column($routeChart ?: [['adet' => 0]], 'adet'));

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<link rel="stylesheet" href="assets/css/style.css?v=20260522-logo-total">

<style>
.dashboard-hero{
    display:grid;
    grid-template-columns:1.3fr .7fr;
    gap:18px;
    margin-bottom:18px;
}

.hero-card,
.filter-panel,
.metric-card,
.chart-card,
.recent-card{
    background:#fff;
    border:1px solid #e7eaf0;
    border-radius:12px;
}

.hero-card{
    padding:24px;
    background:linear-gradient(135deg,#0f3e68,#166b8f);
    color:white;
    overflow:hidden;
    position:relative;
}

.hero-card h2{
    font-size:26px;
    margin-bottom:8px;
}

.hero-card p{
    color:#d9ecf7;
    max-width:620px;
}

.hero-number{
    margin-top:22px;
    font-size:34px;
    font-weight:800;
    letter-spacing:0;
}

.hero-sub{
    color:#d9ecf7;
    margin-top:3px;
}

.filter-panel{
    padding:18px;
}

.filter-panel label{
    display:block;
    font-size:12px;
    font-weight:700;
    color:#475569;
    margin-bottom:6px;
}

.filter-panel select{
    width:100%;
    border:1px solid #d7dde8;
    border-radius:8px;
    padding:10px;
    margin-bottom:12px;
}

.filter-actions{
    display:flex;
    gap:8px;
}

.btn{
    border:0;
    border-radius:8px;
    background:#0f3e68;
    color:white;
    padding:10px 13px;
    text-decoration:none;
    font-weight:700;
    cursor:pointer;
}

.btn-light{
    background:#eef2f7;
    color:#334155;
}

.metrics{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:12px;
    margin-bottom:18px;
}

.metric-card{
    padding:16px;
}

.metric-card span{
    display:block;
    color:#64748b;
    font-size:12px;
    margin-bottom:6px;
}

.metric-card strong{
    display:block;
    font-size:22px;
    color:#0f172a;
}

.metric-card small{
    display:block;
    margin-top:6px;
    color:#64748b;
}

.progress-track{
    height:8px;
    background:#e7edf4;
    border-radius:999px;
    margin-top:12px;
    overflow:hidden;
}

.progress-fill{
    height:100%;
    width:var(--w);
    max-width:100%;
    border-radius:999px;
    background:#16a34a;
}

.charts{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
    margin-bottom:14px;
}

.chart-card{
    padding:18px;
}

.chart-card.wide{
    grid-column:1 / -1;
}

.chart-head{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}

.chart-head h3{
    font-size:17px;
}

.chart-head span{
    color:#64748b;
    font-size:12px;
}

.bar-row{
    display:grid;
    grid-template-columns:minmax(130px, 210px) 1fr minmax(82px, auto);
    gap:10px;
    align-items:center;
    margin-bottom:10px;
}

.bar-label{
    font-size:13px;
    color:#334155;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}

.bar-track{
    height:18px;
    background:#eef2f7;
    border-radius:999px;
    overflow:hidden;
}

.bar-fill{
    height:100%;
    width:var(--w);
    min-width:4px;
    border-radius:999px;
    background:linear-gradient(90deg,#0f8a7f,#18a999);
}

.bar-fill.blue{
    background:linear-gradient(90deg,#2563eb,#60a5fa);
}

.bar-fill.orange{
    background:linear-gradient(90deg,#ea580c,#f59e0b);
}

.bar-value{
    text-align:right;
    font-size:12px;
    font-weight:700;
    color:#0f172a;
}

.contract-row{
    margin-bottom:13px;
}

.contract-top{
    display:flex;
    justify-content:space-between;
    gap:10px;
    font-size:13px;
    margin-bottom:6px;
}

.contract-top strong{
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}

.recent-card{
    padding:18px;
}

.recent-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:10px;
}

.recent-item{
    border:1px solid #e7eaf0;
    border-radius:10px;
    padding:12px;
    background:#fbfcfe;
}

.recent-item strong{
    display:block;
    margin-bottom:5px;
}

.recent-item span{
    display:block;
    color:#64748b;
    font-size:12px;
    margin-bottom:3px;
}

.recent-actions{
    display:flex;
    gap:6px;
    margin-top:9px;
}

.mini-link{
    color:#0f3e68;
    text-decoration:none;
    font-weight:700;
    font-size:12px;
}

@media(max-width:1200px){
    .metrics,
    .recent-grid{
        grid-template-columns:repeat(2, 1fr);
    }
}

@media(max-width:900px){
    .dashboard-hero,
    .charts{
        grid-template-columns:1fr;
    }

    .chart-card.wide{
        grid-column:auto;
    }

    .metrics,
    .recent-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="dashboard-hero">
        <div class="hero-card">
            <h2>Seğmen Hakediş Dashboard</h2>
            <p>Firma, sözleşme, sevkiyat ve kümülatif hakediş performansını tek ekranda izleyin.</p>
            <div class="hero-number"><?php echo para($summary['toplam_net']); ?></div>
            <div class="hero-sub">Toplam net hakediş</div>
        </div>

        <form class="filter-panel" method="GET">
            <label>Firma</label>
            <select name="firma">
                <option value="">Tüm firmalar</option>
                <?php foreach($firmalar as $firmaAdi): ?>
                    <option value="<?php echo htmlspecialchars($firmaAdi); ?>" <?php echo $firmaAdi === $firma ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($firmaAdi); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Sözleşme</label>
            <select name="sozlesme_id">
                <option value="">Tüm sözleşmeler</option>
                <?php foreach($sozlesmeler as $sozlesme): ?>
                    <option value="<?php echo $sozlesme['id']; ?>" <?php echo (string)$sozlesme['id'] === (string)$sozlesmeId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sozlesme['firma_adi'] . ' / ' . $sozlesme['sozlesme_no']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="filter-actions">
                <button class="btn" type="submit">Uygula</button>
                <a class="btn btn-light" href="dashboard.php">Temizle</a>
            </div>
        </form>
    </div>

    <div class="metrics">
        <div class="metric-card">
            <span>KDV Hariç Hakediş</span>
            <strong><?php echo para($summary['toplam_hakedis']); ?></strong>
            <small><?php echo (int)$summary['hakedis_adet']; ?> hakediş kaydı</small>
        </div>
        <div class="metric-card">
            <span>Toplam Sevkiyat</span>
            <strong><?php echo number_format((int)$summary['sevkiyat_adet'], 0, ',', '.'); ?></strong>
            <small>Ortalama <?php echo kisaPara($averageShipment); ?> / sevkiyat</small>
        </div>
        <div class="metric-card">
            <span>Sözleşme Kullanımı</span>
            <strong><?php echo yuzde($realizationRate); ?></strong>
            <div class="progress-track"><div class="progress-fill" style="--w:<?php echo min(100, max(0, $realizationRate)); ?>%"></div></div>
        </div>
        <div class="metric-card">
            <span>Kalan Sözleşme</span>
            <strong><?php echo para($remainingContract); ?></strong>
            <small><?php echo (int)$summary['firma_adet']; ?> firma, <?php echo (int)$summary['sozlesme_adet']; ?> sözleşme</small>
        </div>
    </div>

    <div class="charts">
        <div class="chart-card">
            <div class="chart-head">
                <h3>Firma Bazlı Hakediş</h3>
                <span>KDV hariç</span>
            </div>
            <?php foreach($firmChart as $row): ?>
                <?php $width = $maxFirm > 0 ? ((float)$row['toplam'] / $maxFirm) * 100 : 0; ?>
                <div class="bar-row">
                    <div class="bar-label" title="<?php echo htmlspecialchars($row['label']); ?>"><?php echo htmlspecialchars($row['label']); ?></div>
                    <div class="bar-track"><div class="bar-fill" style="--w:<?php echo $width; ?>%"></div></div>
                    <div class="bar-value"><?php echo kisaPara($row['toplam']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="chart-card">
            <div class="chart-head">
                <h3>Dönem Bazlı Hakediş</h3>
                <span>Aylık dağılım</span>
            </div>
            <?php foreach($monthChart as $row): ?>
                <?php $width = $maxMonth > 0 ? ((float)$row['toplam'] / $maxMonth) * 100 : 0; ?>
                <div class="bar-row">
                    <div class="bar-label"><?php echo htmlspecialchars($row['label']); ?></div>
                    <div class="bar-track"><div class="bar-fill blue" style="--w:<?php echo $width; ?>%"></div></div>
                    <div class="bar-value"><?php echo kisaPara($row['toplam']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="chart-card wide">
            <div class="chart-head">
                <h3>Sözleşme Kullanım Durumu</h3>
                <span>Gerçekleşen / sözleşme tutarı</span>
            </div>
            <?php foreach($contractChart as $row): ?>
                <?php
                    $rate = (float)$row['sozlesme_tutari'] > 0 ? ((float)$row['toplam'] / (float)$row['sozlesme_tutari']) * 100 : 0;
                ?>
                <div class="contract-row">
                    <div class="contract-top">
                        <strong title="<?php echo htmlspecialchars($row['label']); ?>"><?php echo htmlspecialchars($row['label']); ?></strong>
                        <span><?php echo para($row['toplam']); ?> / <?php echo para($row['sozlesme_tutari']); ?> (<?php echo yuzde($rate); ?>)</span>
                    </div>
                    <div class="progress-track"><div class="progress-fill" style="--w:<?php echo min(100, max(0, $rate)); ?>%"></div></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="chart-card wide">
            <div class="chart-head">
                <h3>Güzergah Yoğunluğu</h3>
                <span>Sevkiyat adedi</span>
            </div>
            <?php foreach($routeChart as $row): ?>
                <?php $width = $maxRoute > 0 ? ((float)$row['adet'] / $maxRoute) * 100 : 0; ?>
                <div class="bar-row">
                    <div class="bar-label" title="<?php echo htmlspecialchars($row['label']); ?>"><?php echo htmlspecialchars($row['label']); ?></div>
                    <div class="bar-track"><div class="bar-fill orange" style="--w:<?php echo $width; ?>%"></div></div>
                    <div class="bar-value"><?php echo (int)$row['adet']; ?> sefer</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="recent-card">
        <div class="chart-head">
            <h3>Son Hakedişler</h3>
            <span>Detay ve raporlara hızlı erişim</span>
        </div>
        <div class="recent-grid">
            <?php foreach($recent as $row): ?>
                <div class="recent-item">
                    <strong><?php echo htmlspecialchars($row['firma_adi']); ?></strong>
                    <span><?php echo htmlspecialchars($row['sozlesme_no']); ?> / <?php echo htmlspecialchars($row['donem']); ?></span>
                    <span><?php echo (int)$row['sevkiyat_adet']; ?> sevkiyat</span>
                    <strong><?php echo para($row['net_tutar']); ?></strong>
                    <div class="recent-actions">
                        <a class="mini-link" href="hakedis-detay.php?hakedis_id=<?php echo $row['id']; ?>">Detay</a>
                        <a class="mini-link" href="hakedis-raporu.php?hakedis_id=<?php echo $row['id']; ?>">Rapor</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</body>
</html>
