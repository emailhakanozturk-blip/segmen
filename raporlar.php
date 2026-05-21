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

$query = $db->prepare("
    SELECT
        hakedisler.*,
        cariler.firma_adi,
        sozlesmeler.sozlesme_no,
        sozlesmeler.sozlesme_tutari,
        COALESCE(sozlesme_gerceklesen.gerceklesen_tutar, 0) AS sozlesme_gerceklesen_tutar,
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
<link rel="stylesheet" href="assets/css/style.css">

<style>
.panel{
    background:#fff;
    border:1px solid #e7eaf0;
    border-radius:12px;
    padding:22px;
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

.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:10px 14px;
    border-radius:8px;
    background:#0f3e68;
    color:#fff;
    border:0;
    text-decoration:none;
    font-weight:700;
    cursor:pointer;
}

.btn-green{
    background:#16a34a;
}

.btn-blue{
    background:#2563eb;
    margin-bottom:6px;
}

.report-table{
    width:100%;
    border-collapse:collapse;
}

.report-table th{
    background:#17365f;
    color:#fff;
    text-align:left;
    padding:11px;
    font-size:13px;
}

.report-table td{
    padding:11px;
    border-bottom:1px solid #eef2f7;
    font-size:13px;
    vertical-align:top;
}

.muted{
    color:#64748b;
    font-size:12px;
}

.money{
    font-variant-numeric:tabular-nums;
    white-space:nowrap;
    font-weight:700;
}

@media(max-width:900px){
    .filters{
        grid-template-columns:1fr;
    }

    .report-table{
        min-width:900px;
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
        <form class="filters" method="GET">
            <div class="field">
                <label>Firma</label>
                <select name="firma">
                    <option value="">Tüm firmalar</option>
                    <?php foreach($firmalar as $firmaAdi): ?>
                        <option value="<?php echo htmlspecialchars($firmaAdi); ?>" <?php echo $firmaAdi === $firma ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($firmaAdi); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Sözleşme</label>
                <select name="sozlesme_id">
                    <option value="">Tüm sözleşmeler</option>
                    <?php foreach($sozlesmeler as $sozlesme): ?>
                        <option value="<?php echo $sozlesme['id']; ?>" <?php echo (string)$sozlesme['id'] === (string)$sozlesmeId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sozlesme['firma_adi'] . ' - ' . $sozlesme['sozlesme_no']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn" type="submit">Filtrele</button>
        </form>

        <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Firma / Sözleşme</th>
                        <th>Dönem</th>
                        <th>Sevkiyat</th>
                        <th>Sözleşme Tutarı</th>
                        <th>Mevcut Gerçekleşen</th>
                        <th>Kalan</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($hakedisler as $hakedis): ?>
                        <?php
                            $kalan = (float)$hakedis['sozlesme_tutari'] - (float)$hakedis['sozlesme_gerceklesen_tutar'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($hakedis['firma_adi']); ?></strong>
                                <div class="muted"><?php echo htmlspecialchars($hakedis['sozlesme_no']); ?></div>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($hakedis['donem']); ?>
                                <div class="muted">
                                    <?php echo date('d.m.Y', strtotime($hakedis['ilk_sevkiyat_tarihi'] ?: $hakedis['baslangic_tarihi'])); ?>
                                    -
                                    <?php echo date('d.m.Y', strtotime($hakedis['son_sevkiyat_tarihi'] ?: $hakedis['bitis_tarihi'])); ?>
                                </div>
                            </td>
                            <td><?php echo (int)$hakedis['sevkiyat_sayisi']; ?></td>
                            <td class="money"><?php echo para($hakedis['sozlesme_tutari']); ?></td>
                            <td class="money"><?php echo para($hakedis['sozlesme_gerceklesen_tutar']); ?></td>
                            <td class="money"><?php echo para($kalan); ?></td>
                            <td>
                                <a class="btn btn-blue" href="hakedis-raporu.php?hakedis_id=<?php echo $hakedis['id']; ?>">
                                    Hakediş Raporu
                                </a>
                                <a class="btn btn-green" href="muayene-kabul.php?hakedis_id=<?php echo $hakedis['id']; ?>">
                                    Muayene Kabul Oluştur
                                </a>
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
