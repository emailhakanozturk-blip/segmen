<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$firma = trim((string)($_GET['firma'] ?? ''));
$sozlesme = trim((string)($_GET['sozlesme'] ?? ''));
$donem = trim((string)($_GET['donem'] ?? ''));
$durum = trim((string)($_GET['durum'] ?? ''));

$where = [];
$params = [];

if($firma !== ''){
    $where[] = 'cariler.firma_adi LIKE ?';
    $params[] = '%' . $firma . '%';
}

if($sozlesme !== ''){
    $where[] = 'sozlesmeler.sozlesme_no LIKE ?';
    $params[] = '%' . $sozlesme . '%';
}

if($donem !== ''){
    $where[] = 'hakedisler.donem LIKE ?';
    $params[] = '%' . $donem . '%';
}

if($durum !== ''){
    $where[] = 'hakedisler.durum = ?';
    $params[] = $durum;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$query = $db->prepare("
    SELECT
        hakedisler.*,
        sozlesmeler.sozlesme_no,
        cariler.firma_adi
    FROM hakedisler

    LEFT JOIN sozlesmeler
        ON sozlesmeler.id = hakedisler.sozlesme_id

    LEFT JOIN cariler
        ON cariler.id = sozlesmeler.cari_id

    $whereSql
    ORDER BY hakedisler.id DESC
");

$query->execute($params);
$hakedisler = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Hakedişler</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260522-logo-total">

<style>

.table-area{
    background:white;
    padding:16px;
    border-radius:12px;
    border:1px solid #e5e7eb;
    box-shadow:0 10px 28px rgba(15,23,42,.04);
    overflow:hidden;
}

.top-actions{
    margin-bottom:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.top-actions h3{
    margin:0;
    font-size:18px;
    letter-spacing:0;
    color:#0f172a;
}

.action-top{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.filters{
    display:grid;
    grid-template-columns:1.4fr 1fr .8fr .8fr auto auto;
    gap:8px;
    align-items:end;
    margin:0 0 12px;
}

.filters input,
.filters select{
    width:100%;
    border:1px solid #d7dde8;
    border-radius:7px;
    padding:8px 9px;
    font-size:12px;
    box-sizing:border-box;
}

.filters .btn{
    border:0;
    text-align:center;
}

.btn{
    background:#0f172a;
    color:white;
    padding:8px 11px;
    border-radius:7px;
    text-decoration:none;
    display:inline-block;
    font-size:12px;
    font-weight:700;
    line-height:1.2;
}

.btn-orange-main{
    background:#f97316;
}

table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}

table th{
    background:#f8fafc;
    color:#64748b;
    padding:8px 6px;
    text-align:center;
    font-size:11px;
    line-height:1.2;
    text-transform:uppercase;
    border-bottom:1px solid #e2e8f0;
}

table td{
    padding:7px 6px;
    border-bottom:1px solid #edf2f7;
    font-size:12px;
    line-height:1.25;
    vertical-align:middle;
    color:#1f2937;
    text-align:center;
}

table th:nth-child(1),
table td:nth-child(1){
    width:38px;
    text-align:center;
    color:#64748b;
}

table th:nth-child(2),
table td:nth-child(2){
    width:13%;
    text-align:left;
}

table th:nth-child(3),
table td:nth-child(3){
    width:82px;
}

table th:nth-child(4),
table td:nth-child(4){
    width:68px;
}

table th:nth-child(5),
table td:nth-child(5),
table th:nth-child(6),
table td:nth-child(6),
table th:nth-child(7),
table td:nth-child(7),
table th:nth-child(8),
table td:nth-child(8){
    width:82px;
    white-space:nowrap;
    font-size:11px;
    font-variant-numeric:tabular-nums;
    color:#0f172a;
}

table th:nth-child(9),
table td:nth-child(9){
    width:70px;
    text-align:center;
}

table th:nth-child(10),
table td:nth-child(10){
    width:62px;
}

table td:nth-child(2){
    word-break:break-word;
}

.badge{
    padding:4px 7px;
    border-radius:999px;
    color:white;
    font-size:10px;
    display:inline-block;
    white-space:nowrap;
    font-weight:700;
}

.onay{
    background:#16a34a;
}

.bekliyor{
    background:#f59e0b;
}

.red{
    background:#dc2626;
}

.action-btn{
    color:white;
    padding:5px 7px;
    border-radius:6px;
    text-decoration:none;
    font-size:10px;
    display:block;
    width:52px;
    margin:0 auto 4px;
    font-weight:700;
    text-align:center;
}

.btn-blue{
    background:#334155;
}

.btn-green{
    background:#059669;
}

.btn-red{
    background:#e11d48;
}

@media(max-width:760px){
    .table-area{
        padding:14px;
    }

    .top-actions{
        align-items:flex-start;
    }

    .action-top,
    .btn,
    .filters{
        width:100%;
    }

    .filters{
        grid-template-columns:1fr;
    }

    .btn{
        text-align:center;
    }

    table,
    tbody,
    tr,
    td{
        display:block;
        width:100% !important;
    }

    table thead{
        display:none;
    }

    table tr{
        border:1px solid #e5e7eb;
        border-radius:10px;
        padding:10px;
        margin-bottom:10px;
        background:#fff;
    }

    table td{
        border-bottom:0;
        padding:6px 0;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        text-align:right;
        white-space:normal !important;
    }

    table td::before{
        content:attr(data-label);
        color:#64748b;
        font-weight:700;
        text-align:left;
        flex:0 0 88px;
    }

    table td:last-child{
        justify-content:center;
        flex-direction:column;
        flex-wrap:nowrap;
        padding-top:10px;
    }

    table td:last-child::before{
        display:none;
    }

    .action-btn{
        display:block;
        width:58px;
        margin:0 0 4px;
    }
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Hakediş Yönetimi</h2>

        <p>
            Nakliye ve taşıma hakediş yönetimi
        </p>

    </div>

    <div class="table-area">

        <div class="top-actions">

            <h3>Hakediş Listesi</h3>

            <div class="action-top">

                <a href="hakedis-olustur.php" class="btn">
                    + Yeni Hakediş
                </a>

                <a href="excel-eslestir.php" class="btn btn-orange-main">
                    Excel Aktarım
                </a>

            </div>

        </div>

        <form class="filters" method="GET">
            <input type="text" name="firma" value="<?php echo htmlspecialchars($firma); ?>" placeholder="Firma">
            <input type="text" name="sozlesme" value="<?php echo htmlspecialchars($sozlesme); ?>" placeholder="Sözleşme">
            <input type="text" name="donem" value="<?php echo htmlspecialchars($donem); ?>" placeholder="Dönem">
            <select name="durum">
                <option value="">Tüm durumlar</option>
                <option value="bekliyor" <?php echo $durum === 'bekliyor' ? 'selected' : ''; ?>>Bekliyor</option>
                <option value="onaylandi" <?php echo $durum === 'onaylandi' ? 'selected' : ''; ?>>Onaylandı</option>
                <option value="red" <?php echo $durum === 'red' ? 'selected' : ''; ?>>Red</option>
            </select>
            <button class="btn" type="submit">Filtrele</button>
            <a class="btn" href="hakedisler.php">Temizle</a>
        </form>

        <table class="hakedis-table">

            <thead>

                <tr>

                    <th>ID</th>
                    <th>Firma</th>
                    <th>Sözleşme</th>
                    <th>Dönem</th>
                    <th>Tutar</th>
                    <th>KDV</th>
                    <th>Tevkifat</th>
                    <th>Net</th>
                    <th>Durum</th>
                    <th>İşlem</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($hakedisler as $hakedis): ?>

                <tr>

                    <td data-label="ID">
                        <?php echo $hakedis['id']; ?>
                    </td>

                    <td data-label="Firma">
                        <?php echo $hakedis['firma_adi']; ?>
                    </td>

                    <td data-label="Sözleşme">
                        <?php echo $hakedis['sozlesme_no']; ?>
                    </td>

                    <td data-label="Dönem">
                        <?php echo $hakedis['donem']; ?>
                    </td>

                    <td data-label="Tutar">
                        ₺<?php echo number_format($hakedis['toplam_tutar'],2,',','.'); ?>
                    </td>

                    <td data-label="KDV">
                        ₺<?php echo number_format($hakedis['kdv_tutar'],2,',','.'); ?>
                    </td>

                    <td data-label="Tevkifat">
                        ₺<?php echo number_format($hakedis['tevkifat_tutar'],2,',','.'); ?>
                    </td>

                    <td data-label="Net">
                        ₺<?php echo number_format($hakedis['net_tutar'],2,',','.'); ?>
                    </td>

                    <td data-label="Durum">

                        <?php if($hakedis['durum']=='onaylandi'): ?>

                            <span class="badge onay">
                                Onaylandı
                            </span>

                        <?php elseif($hakedis['durum']=='bekliyor'): ?>

                            <span class="badge bekliyor">
                                Bekliyor
                            </span>

                        <?php else: ?>

                            <span class="badge red">
                                Red
                            </span>

                        <?php endif; ?>

                    </td>

                    <td data-label="İşlem">

                        <a
                            href="hakedis-detay.php?hakedis_id=<?php echo $hakedis['id']; ?>"
                            class="action-btn btn-blue"
                        >
                            Detay
                        </a>

                        <?php if($hakedis['durum'] != 'onaylandi'): ?>

                            <a
                                href="hakedis-onayla.php?id=<?php echo $hakedis['id']; ?>"
                                class="action-btn btn-green"
                            >
                                Onayla
                            </a>

                        <?php endif; ?>

                        <a
                            href="hakedis-sil.php?id=<?php echo $hakedis['id']; ?>"
                            class="action-btn btn-red"
                            onclick="return confirm('Bu hakediş ve buna bağlı tüm satırlar silinsin mi?');"
                        >
                            Sil
                        </a>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>
