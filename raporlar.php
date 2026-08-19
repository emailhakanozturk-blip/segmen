<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$cariId = (int)($_GET['cari_id'] ?? 0);
$sozlesmeId = (int)($_GET['sozlesme_id'] ?? 0);

function para($value){
    return '₺' . number_format((float)$value, 2, ',', '.');
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
        sozlesmeler.cari_id,
        sozlesmeler.sozlesme_no,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    ORDER BY cariler.firma_adi, sozlesmeler.sozlesme_no
")->fetchAll(PDO::FETCH_ASSOC);

if($sozlesmeId <= 0 && !empty($sozlesmeler)){
    $sozlesmeId = (int)$sozlesmeler[0]['id'];
    $cariId = (int)$sozlesmeler[0]['cari_id'];
}

foreach($sozlesmeler as $sozlesme){
    if((int)$sozlesme['id'] === $sozlesmeId){
        $cariId = (int)$sozlesme['cari_id'];
        break;
    }
}

$where = [];
$params = [];

if($cariId > 0){
    $where[] = 'hakedisler.cari_id = ?';
    $params[] = $cariId;
}

if($sozlesmeId > 0){
    $where[] = 'hakedisler.sozlesme_id = ?';
    $params[] = $sozlesmeId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$query = $db->prepare("
    SELECT
        hakedisler.*,
        cariler.firma_adi,
        sozlesmeler.sozlesme_no,
        sozlesmeler.sozlesme_tutari,
        COALESCE(sozlesme_gerceklesen.gerceklesen_tutar, 0) AS sozlesme_gerceklesen_tutar,
        COALESCE(kumulatif_gerceklesen.kumulatif_tutar, 0) AS kumulatif_tutar,
        COALESCE(satir_ozet.sevkiyat_sayisi, 0) AS sevkiyat_sayisi,
        satir_ozet.ilk_sevkiyat_tarihi,
        satir_ozet.son_sevkiyat_tarihi
    FROM hakedisler
    LEFT JOIN cariler ON cariler.id = hakedisler.cari_id
    LEFT JOIN sozlesmeler ON sozlesmeler.id = hakedisler.sozlesme_id
    LEFT JOIN (
        SELECT sozlesme_id, SUM(toplam_tutar) AS gerceklesen_tutar
        FROM hakedisler
        GROUP BY sozlesme_id
    ) sozlesme_gerceklesen ON sozlesme_gerceklesen.sozlesme_id = hakedisler.sozlesme_id
    LEFT JOIN (
        SELECT
            h1.id AS hakedis_id,
            SUM(h2.toplam_tutar) AS kumulatif_tutar
        FROM hakedisler h1
        INNER JOIN hakedisler h2
            ON h2.sozlesme_id = h1.sozlesme_id
            AND (
                COALESCE(h2.bitis_tarihi, h2.baslangic_tarihi, h2.created_at) < COALESCE(h1.bitis_tarihi, h1.baslangic_tarihi, h1.created_at)
                OR (
                    COALESCE(h2.bitis_tarihi, h2.baslangic_tarihi, h2.created_at) = COALESCE(h1.bitis_tarihi, h1.baslangic_tarihi, h1.created_at)
                    AND h2.id <= h1.id
                )
            )
        GROUP BY h1.id
    ) kumulatif_gerceklesen ON kumulatif_gerceklesen.hakedis_id = hakedisler.id
    LEFT JOIN (
        SELECT
            hakedis_id,
            COUNT(*) AS sevkiyat_sayisi,
            MIN(tasima_tarihi) AS ilk_sevkiyat_tarihi,
            MAX(tasima_tarihi) AS son_sevkiyat_tarihi
        FROM hakedis_satirlari
        GROUP BY hakedis_id
    ) satir_ozet ON satir_ozet.hakedis_id = hakedisler.id
    $whereSql
    ORDER BY cariler.firma_adi, sozlesmeler.sozlesme_no, hakedisler.baslangic_tarihi DESC, hakedisler.id DESC
");
$query->execute($params);
$hakedisler = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Raporlar</title>
<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>
.panel{
    background:#fff;
    border:1px solid #e7eaf0;
    border-radius:10px;
    padding:12px;
    box-shadow:0 8px 22px rgba(15,23,42,.05);
}

.filters{
    display:grid;
    grid-template-columns:1fr 1fr auto;
    gap:12px;
    align-items:end;
    margin-bottom:18px;
}

.field label{
    display:block;
    font-size:12px;
    font-weight:700;
    color:#475569;
    margin-bottom:6px;
}

.field select{
    width:100%;
    border:1px solid #d7dde8;
    border-radius:8px;
    padding:11px;
    font-size:14px;
}

.contract-tabs{
    display:flex;
    gap:6px;
    overflow-x:auto;
    padding-bottom:4px;
    margin-bottom:10px;
}

.contract-tab{
    min-width:145px;
    border:1px solid #e5e7eb;
    border-radius:7px;
    padding:7px 9px;
    color:#334155;
    background:#fff;
    text-decoration:none;
    font-size:10px;
}

.contract-tab strong{
    display:block;
    color:#111827;
    font-size:11px;
    margin-bottom:2px;
}

.contract-tab.active{
    border-color:#2563eb;
    background:#eff6ff;
}

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:28px;
    padding:6px 8px;
    border-radius:6px;
    background:#0f3e68;
    color:#fff;
    border:0;
    text-decoration:none;
    font-weight:700;
    font-size:10px;
    cursor:pointer;
}

.btn-green{
    background:#16a34a;
}

.btn-blue{
    background:#2563eb;
}

.report-table{
    width:100%;
    table-layout:fixed;
    border-collapse:collapse;
    border-spacing:0;
}

.report-table th{
    background:#0f172a;
    color:#fff;
    text-align:center;
    padding:8px 4px;
    font-size:9px;
    letter-spacing:0;
    text-transform:uppercase;
    white-space:normal;
    line-height:1.15;
}

.report-table td{
    padding:7px 4px;
    border-bottom:1px solid #eef2f7;
    font-size:10px;
    vertical-align:middle;
    text-align:center;
}

.report-table tbody tr{
    transition:background .15s ease, box-shadow .15s ease;
}

.report-table tbody tr:hover{
    background:#f8fbff;
}

.muted{
    color:#64748b;
    font-size:10px;
    line-height:1.2;
}

.money{
    font-variant-numeric:tabular-nums;
    font-weight:700;
    color:#0f172a;
}

.money-pill{
    display:inline-flex;
    min-width:0;
    width:100%;
    max-width:100%;
    justify-content:center;
    padding:5px 4px;
    border-radius:7px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    box-sizing:border-box;
    white-space:normal;
    line-height:1.15;
}

.money.negative{
    color:#dc2626;
}

.money.positive{
    color:#166534;
}

.identity{
    text-align:left !important;
}

.identity strong{
    display:block;
    color:#0f172a;
    font-size:10px;
    margin-bottom:2px;
    overflow-wrap:anywhere;
}

.period-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:54px;
    padding:4px 6px;
    border-radius:999px;
    background:#eef6ff;
    color:#0f3e68;
    font-weight:800;
}

.ship-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:32px;
    height:28px;
    border-radius:7px;
    background:#f1f5f9;
    color:#0f172a;
    font-weight:800;
}

.action-stack{
    display:grid;
    gap:5px;
    justify-content:stretch;
}

.report-table th:nth-child(1),
.report-table td:nth-child(1){width:17%;}
.report-table th:nth-child(2),
.report-table td:nth-child(2){width:12%;}
.report-table th:nth-child(3),
.report-table td:nth-child(3){width:7%;}
.report-table th:nth-child(4),
.report-table td:nth-child(4){width:13%;}
.report-table th:nth-child(5),
.report-table td:nth-child(5){width:13%;}
.report-table th:nth-child(6),
.report-table td:nth-child(6){width:15%;}
.report-table th:nth-child(7),
.report-table td:nth-child(7){width:13%;}
.report-table th:nth-child(8),
.report-table td:nth-child(8){width:10%;}

@media(max-width:900px){
    .filters{
        grid-template-columns:1fr;
    }

    .table-wrap{
        overflow:auto;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Raporlar</h2>
        <p>Firma ve sözleşme bazında hakediş raporları ve muayene kabul tutanakları</p>
    </div>

    <div class="panel">
        <div class="contract-tabs">
            <?php foreach($sozlesmeler as $sozlesme): ?>
                <?php
                    $active = (int)$sozlesme['id'] === $sozlesmeId;
                    $href = 'raporlar.php?cari_id=' . (int)$sozlesme['cari_id'] . '&sozlesme_id=' . (int)$sozlesme['id'];
                ?>
                <a class="contract-tab <?php echo $active ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($href); ?>">
                    <strong><?php echo htmlspecialchars($sozlesme['sozlesme_no']); ?></strong>
                    <span><?php echo htmlspecialchars($sozlesme['firma_adi'] ?? '-'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Firma / Sözleşme</th>
                        <th>Dönem</th>
                        <th>Sevkiyat</th>
                        <th>Sözleşme Tutarı</th>
                        <th>Mevcut Hakediş</th>
                        <th>Kümülatif Gerçekleşen</th>
                        <th>Sözleşme Farkı</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($hakedisler as $hakedis): ?>
                        <?php
                            $mevcutHakedis = (float)$hakedis['toplam_tutar'];
                            $kumulatif = (float)$hakedis['kumulatif_tutar'];
                            $sozlesmeFarki = (float)$hakedis['sozlesme_tutari'] - $kumulatif;
                        ?>
                        <tr>
                            <td class="identity">
                                <strong><?php echo htmlspecialchars($hakedis['firma_adi']); ?></strong>
                                <div class="muted"><?php echo htmlspecialchars($hakedis['sozlesme_no']); ?></div>
                            </td>
                            <td>
                                <span class="period-badge"><?php echo htmlspecialchars($hakedis['donem']); ?></span>
                                <div class="muted">
                                    <?php echo date('d.m.Y', strtotime($hakedis['ilk_sevkiyat_tarihi'] ?: $hakedis['baslangic_tarihi'])); ?>
                                    -
                                    <?php echo date('d.m.Y', strtotime($hakedis['son_sevkiyat_tarihi'] ?: $hakedis['bitis_tarihi'])); ?>
                                </div>
                            </td>
                            <td><span class="ship-count"><?php echo (int)$hakedis['sevkiyat_sayisi']; ?></span></td>
                            <td class="money"><span class="money-pill"><?php echo para($hakedis['sozlesme_tutari']); ?></span></td>
                            <td class="money"><span class="money-pill"><?php echo para($mevcutHakedis); ?></span></td>
                            <td class="money"><span class="money-pill"><?php echo para($kumulatif); ?></span></td>
                            <td class="money <?php echo $sozlesmeFarki < 0 ? 'negative' : 'positive'; ?>">
                                <span class="money-pill"><?php echo para($sozlesmeFarki); ?></span>
                                <div class="muted">
                                    <?php echo $sozlesmeFarki < 0 ? 'Aşım' : 'Kalan'; ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-stack">
                                    <a class="btn btn-blue" href="hakedis-raporu.php?hakedis_id=<?php echo $hakedis['id']; ?>">
                                        Hakediş Raporu
                                    </a>
                                    <a class="btn btn-green" href="muayene-kabul.php?hakedis_id=<?php echo $hakedis['id']; ?>">
                                        Muayene Kabul
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
