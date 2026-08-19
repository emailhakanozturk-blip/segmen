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

function tableExists(PDO $db, string $table): bool{
    try{
        $q = $db->prepare("SHOW TABLES LIKE ?");
        $q->execute([$table]);
        return (bool)$q->fetchColumn();
    }catch(Throwable $e){
        return false;
    }
}

function scalarValue(PDO $db, string $sql, array $params = [], $default = 0){
    try{
        $q = $db->prepare($sql);
        $q->execute($params);
        $v = $q->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    }catch(Throwable $e){
        return $default;
    }
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
        CONCAT(hakedis_satirlari.cikis_noktasi, ' - ', hakedis_satirlari.varis_noktasi) AS label,
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

$currentCostPeriod = tableExists($db, 'recete_donemler')
    ? (scalarValue($db, "SELECT donem_adi FROM recete_donemler ORDER BY donem DESC LIMIT 1", [], 'Nisan 2026'))
    : 'Nisan 2026';
$currentCostProduction = tableExists($db, 'recete_donemler')
    ? (float)scalarValue($db, "SELECT toplam_uretim FROM recete_donemler ORDER BY donem DESC LIMIT 1", [], 0)
    : 0;
$activeRecipes = tableExists($db, 'recete_bom')
    ? (int)scalarValue($db, "SELECT COUNT(*) FROM recete_bom WHERE aktif=1", [], 0)
    : 0;
$openTasks = tableExists($db, 'gorevler')
    ? (int)scalarValue($db, "SELECT COUNT(*) FROM gorevler WHERE durum NOT IN ('Tamamlandı','tamamlandi','tamamlandı')", [], 0)
    : 0;
$assetCount = tableExists($db, 'demirbas_tanimlari')
    ? (int)scalarValue($db, "SELECT COUNT(*) FROM demirbas_tanimlari", [], 0)
    : 0;
$palletNet = tableExists($db, 'palet_hareketleri')
    ? (float)scalarValue($db, "SELECT COALESCE(SUM(giden_adet - gelen_adet),0) FROM palet_hareketleri", [], 0)
    : 0;

$moduleCards = [
    ['Hakedişler', (int)$summary['hakedis_adet'] . ' kayıt', para($summary['toplam_net']), 'hakedisler.php', 'Net hakediş ve sevkiyat takibi', 'blue'],
    ['Reçete & Maliyet', number_format($currentCostProduction,0,',','.') . ' koli', $currentCostPeriod, 'recete-maliyet.php', $activeRecipes . ' aktif reçete', 'indigo'],
    ['Görev Takip', $openTasks . ' açık görev', 'Toplantı ve aksiyon', 'gorev-takip.php', 'Durum ve süre takibi', 'amber'],
    ['Demirbaş', $assetCount . ' demirbaş', 'Zimmet ve kullanım', 'demirbas-takip.php', 'Tanım ve raporlar', 'emerald'],
    ['Palet Takip', number_format($palletNet,0,',','.') . ' net', 'Sevk hareketleri', 'palet-takip.php', 'Giden / gelen / kalan', 'violet'],
];

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>
.main{background:#f4f7fb;min-height:100vh}
.dashboard-hero{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px;margin-bottom:18px}
.hero-card,.filter-panel,.metric-card,.chart-card,.recent-card,.module-card,.quick-card{background:#fff;border:1px solid #dfe7f2;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.055)}
.hero-card{padding:30px 32px;background:linear-gradient(120deg,#0f172a 0%,#1e3a8a 56%,#2563eb 100%);color:#fff;overflow:hidden;position:relative}
.hero-card:after{content:'';position:absolute;right:-80px;top:-90px;width:260px;height:260px;border-radius:999px;background:rgba(255,255,255,.12)}
.hero-kicker{display:inline-flex;align-items:center;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.10);border-radius:999px;padding:7px 11px;color:#bfdbfe;font-size:11px;font-weight:950;letter-spacing:.06em;text-transform:uppercase}
.hero-card h2{font-size:34px;line-height:1.05;margin:14px 0 9px;letter-spacing:0}
.hero-card p{color:#dbeafe;max-width:720px;font-size:14px;line-height:1.55}
.hero-number{margin-top:24px;font-size:42px;font-weight:950;letter-spacing:-.02em}
.hero-sub{color:#c7d2fe;margin-top:5px;font-weight:800}
.hero-mini-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:22px;max-width:760px}
.hero-mini{border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.09);border-radius:14px;padding:12px}
.hero-mini span{display:block;color:#bfdbfe;font-size:11px;font-weight:900}.hero-mini strong{display:block;margin-top:5px;font-size:18px;color:#fff}
.filter-panel{padding:18px;align-self:stretch}
.filter-title{font-size:16px;font-weight:950;color:#0f172a;margin-bottom:12px}
.filter-panel label{display:block;font-size:11px;font-weight:950;color:#475569;margin:10px 0 6px;text-transform:uppercase;letter-spacing:.03em}
.filter-panel select{width:100%;border:1px solid #cbd5e1;border-radius:12px;padding:12px;background:#f8fafc;color:#0f172a;font-weight:800;margin-bottom:10px}
.filter-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
.btn{border:0;border-radius:12px;background:#2563eb;color:white;padding:12px 13px;text-decoration:none;font-weight:950;cursor:pointer;text-align:center;box-shadow:0 10px 20px rgba(37,99,235,.20)}
.btn-light{background:#eef2f7;color:#334155;box-shadow:none}
.module-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}
.module-card{padding:16px;text-decoration:none;color:#0f172a;min-height:132px;display:flex;flex-direction:column;justify-content:space-between;transition:.15s ease}
.module-card:hover{transform:translateY(-2px);box-shadow:0 16px 34px rgba(15,23,42,.09)}
.module-card .module-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.module-icon{width:38px;height:38px;border-radius:13px;display:flex;align-items:center;justify-content:center;background:#eff6ff;color:#2563eb;font-weight:950}
.module-card h3{font-size:15px;margin:0}.module-card p{font-size:12px;color:#64748b;margin:4px 0 0;line-height:1.35}
.module-card strong{font-size:20px;letter-spacing:-.01em}.module-card small{display:block;color:#64748b;font-size:11px;margin-top:4px}
.module-card.indigo .module-icon{background:#eef2ff;color:#4f46e5}.module-card.amber .module-icon{background:#fffbeb;color:#d97706}.module-card.emerald .module-icon{background:#ecfdf5;color:#059669}.module-card.violet .module-icon{background:#f5f3ff;color:#7c3aed}
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.metric-card{padding:17px}
.metric-card span{display:block;color:#64748b;font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}
.metric-card strong{display:block;font-size:24px;color:#0f172a;letter-spacing:-.015em}
.metric-card small{display:block;margin-top:7px;color:#64748b;font-size:12px}
.progress-track{height:9px;background:#e7edf4;border-radius:999px;margin-top:13px;overflow:hidden}.progress-fill{height:100%;width:var(--w);max-width:100%;border-radius:999px;background:linear-gradient(90deg,#10b981,#22c55e)}
.dashboard-content{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(360px,.75fr);gap:14px;align-items:start}
.charts{display:grid;grid-template-columns:1fr 1fr;gap:14px}.chart-card{padding:18px}.chart-card.wide{grid-column:1/-1}
.chart-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:15px;align-items:flex-start}.chart-head h3{font-size:17px;margin:0;color:#0f172a}.chart-head span{color:#64748b;font-size:12px;font-weight:800}
.bar-row{display:grid;grid-template-columns:minmax(120px,190px) 1fr minmax(78px,auto);gap:10px;align-items:center;margin-bottom:10px}
.bar-label{font-size:13px;color:#334155;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.bar-track{height:16px;background:#eef2f7;border-radius:999px;overflow:hidden}.bar-fill{height:100%;width:var(--w);min-width:4px;border-radius:999px;background:linear-gradient(90deg,#14b8a6,#22c55e)}.bar-fill.blue{background:linear-gradient(90deg,#2563eb,#60a5fa)}.bar-fill.orange{background:linear-gradient(90deg,#f97316,#fbbf24)}.bar-value{text-align:right;font-size:12px;font-weight:900;color:#0f172a}
.contract-row{margin-bottom:13px}.contract-top{display:flex;justify-content:space-between;gap:10px;font-size:13px;margin-bottom:7px}.contract-top strong{overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#0f172a}.contract-top span{color:#475569;font-weight:800}
.side-stack{display:grid;gap:14px}.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.quick-card{padding:14px;text-decoration:none;color:#0f172a}.quick-card strong{display:block;font-size:14px}.quick-card span{display:block;color:#64748b;font-size:12px;margin-top:4px}
.recent-card{padding:18px}.recent-grid{display:grid;grid-template-columns:1fr;gap:10px}.recent-item{border:1px solid #e7eaf0;border-radius:14px;padding:13px;background:#fbfcfe}.recent-item strong{display:block;margin-bottom:5px;color:#0f172a}.recent-item span{display:block;color:#64748b;font-size:12px;margin-bottom:3px}.recent-actions{display:flex;gap:8px;margin-top:10px}.mini-link{color:#2563eb;background:#eff6ff;border-radius:999px;padding:6px 10px;text-decoration:none;font-weight:950;font-size:12px}
@media(max-width:1280px){.module-grid{grid-template-columns:repeat(3,1fr)}.dashboard-content{grid-template-columns:1fr}.recent-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.dashboard-hero,.charts{grid-template-columns:1fr}.module-grid,.metrics,.quick-grid,.recent-grid{grid-template-columns:1fr}.hero-mini-grid{grid-template-columns:1fr}.hero-card h2{font-size:28px}.hero-number{font-size:32px}.chart-card.wide{grid-column:auto}}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="dashboard-hero">
        <div class="hero-card">
            <span class="hero-kicker">Seğmen Su Operasyon Merkezi</span>
            <h2>Seğmen Hakediş ve Maliyet Kontrol Paneli</h2>
            <p>Hakediş, reçete-maliyet, görev, demirbaş ve palet süreçlerini tek ekranda takip edin. Kritik göstergeler ve hızlı erişimler yeni modüllere göre güncellendi.</p>
            <div class="hero-number"><?php echo para($summary['toplam_net']); ?></div>
            <div class="hero-sub">Toplam net hakediş</div>
            <div class="hero-mini-grid">
                <div class="hero-mini"><span>Sevkiyat</span><strong><?php echo number_format((int)$summary['sevkiyat_adet'],0,',','.'); ?></strong></div>
                <div class="hero-mini"><span>Sözleşme</span><strong><?php echo (int)$summary['sozlesme_adet']; ?></strong></div>
                <div class="hero-mini"><span>Üretim Dönemi</span><strong><?php echo htmlspecialchars((string)$currentCostPeriod); ?></strong></div>
            </div>
        </div>

        <form class="filter-panel" method="GET">
            <div class="filter-title">Hakediş Filtresi</div>
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

    <div class="module-grid">
        <?php foreach($moduleCards as $i => $card): ?>
            <a class="module-card <?php echo htmlspecialchars($card[5]); ?>" href="<?php echo htmlspecialchars($card[3]); ?>">
                <div class="module-top">
                    <div>
                        <h3><?php echo htmlspecialchars($card[0]); ?></h3>
                        <p><?php echo htmlspecialchars($card[4]); ?></p>
                    </div>
                    <div class="module-icon"><?php echo $i + 1; ?></div>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($card[1]); ?></strong>
                    <small><?php echo htmlspecialchars($card[2]); ?></small>
                </div>
            </a>
        <?php endforeach; ?>
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

    <div class="dashboard-content">
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
                    <?php $rate = (float)$row['sozlesme_tutari'] > 0 ? ((float)$row['toplam'] / (float)$row['sozlesme_tutari']) * 100 : 0; ?>
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

        <div class="side-stack">
            <div class="recent-card">
                <div class="chart-head">
                    <h3>Son Hakedişler</h3>
                    <span>Hızlı erişim</span>
                </div>
                <div class="recent-grid">
                    <?php foreach($recent as $row): ?>
                        <div class="recent-item">
                            <strong><?php echo htmlspecialchars((string)$row['firma_adi']); ?></strong>
                            <span><?php echo htmlspecialchars((string)$row['sozlesme_no']); ?> / <?php echo htmlspecialchars((string)$row['donem']); ?></span>
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

            <div class="chart-card">
                <div class="chart-head">
                    <h3>Hızlı İşlemler</h3>
                    <span>Modül kısayolları</span>
                </div>
                <div class="quick-grid">
                    <a class="quick-card" href="excel-eslestir.php"><strong>Excel Aktarım</strong><span>Hakediş dosyası yükle</span></a>
                    <a class="quick-card" href="nokta-yonetimi.php"><strong>Nokta Yönetimi</strong><span>Güzergah ve revizyon</span></a>
                    <a class="quick-card" href="recete-maliyet.php?tab=receteler"><strong>Reçeteler</strong><span>BOM ve maliyet</span></a>
                    <a class="quick-card" href="gorev-takip.php"><strong>Görev Ata</strong><span>Toplantı ve aksiyon</span></a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
