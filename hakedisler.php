<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$query = $db->query("
    SELECT
        hakedisler.*,
        sozlesmeler.sozlesme_no,
        cariler.firma_adi
    FROM hakedisler

    LEFT JOIN sozlesmeler
        ON sozlesmeler.id = hakedisler.sozlesme_id

    LEFT JOIN cariler
        ON cariler.id = sozlesmeler.cari_id

    ORDER BY hakedisler.id DESC
");

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
    padding:25px;
    border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.top-actions{
    margin-bottom:20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.action-top{
    display:flex;
    align-items:center;
}

.btn{
    background:#16a34a;
    color:white;
    padding:12px 18px;
    border-radius:8px;
    text-decoration:none;
    display:inline-block;
}

.btn-orange-main{
    background:#ea580c;
    margin-left:10px;
}

table{
    width:100%;
    border-collapse:collapse;
}

table th{
    background:#1d3557;
    color:white;
    padding:14px;
    text-align:left;
}

table td{
    padding:14px;
    border-bottom:1px solid #eee;
}

.badge{
    padding:6px 10px;
    border-radius:20px;
    color:white;
    font-size:12px;
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
    padding:7px 10px;
    border-radius:6px;
    text-decoration:none;
    font-size:12px;
    margin-right:5px;
    display:inline-block;
    margin-bottom:4px;
}

.btn-blue{
    background:#2563eb;
}

.btn-green{
    background:#16a34a;
}

.btn-red{
    background:#dc2626;
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

        <table>

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

                    <td>
                        <?php echo $hakedis['id']; ?>
                    </td>

                    <td>
                        <?php echo $hakedis['firma_adi']; ?>
                    </td>

                    <td>
                        <?php echo $hakedis['sozlesme_no']; ?>
                    </td>

                    <td>
                        <?php echo $hakedis['donem']; ?>
                    </td>

                    <td>
                        ₺<?php echo number_format($hakedis['toplam_tutar'],2,',','.'); ?>
                    </td>

                    <td>
                        ₺<?php echo number_format($hakedis['kdv_tutar'],2,',','.'); ?>
                    </td>

                    <td>
                        ₺<?php echo number_format($hakedis['tevkifat_tutar'],2,',','.'); ?>
                    </td>

                    <td>
                        ₺<?php echo number_format($hakedis['net_tutar'],2,',','.'); ?>
                    </td>

                    <td>

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

                    <td>

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
