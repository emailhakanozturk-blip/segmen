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

$query = $db->prepare("

SELECT *

FROM hakedis_satirlari

WHERE hakedis_id = ?

ORDER BY
tasima_tarihi ASC,
id ASC

");

$query->execute([
    $hakedis_id
]);

$satirlar = $query->fetchAll(PDO::FETCH_ASSOC);

$toplamBaz = 0;
$toplamZam = 0;
$toplamGuncel = 0;
$toplamKdv = 0;
$toplamTevkifat = 0;
$toplamNet = 0;

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Hakediş Detay</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.table-area{
    background:white;
    padding:3px;
    border-radius:10px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
    overflow:hidden;
}

table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}

table th{
    background:#1d3557;
    color:white;
    padding:3px 2px;
    font-size:7px;
    text-align:center;
    border-right:1px solid #35507c;
    white-space:nowrap;
    line-height:1;
}

table td{
    padding:3px 2px;
    border-bottom:1px solid #eee;
    border-right:1px solid #eee;
    font-size:7px;
    white-space:nowrap;
    line-height:1;
}

table tr:hover{
    background:#f9fafb;
}

.right{
    text-align:right;
    font-variant-numeric:tabular-nums;
}

.center{
    text-align:center;
}

.green{
    color:#16a34a;
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

.red{
    color:#dc2626;
    font-weight:bold;
}

.total-row{
    background:#f3f4f6;
    font-weight:bold;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Hakediş Detayları</h2>

        <p>
            Sadece seçilen hakedişe ait satırlar gösterilmektedir
        </p>

    </div>

    <div class="table-area">

        <table>

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
                    <th>Zam</th>
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

            $toplamBaz += $row['birim_fiyat'];
            $toplamZam += $row['zam_indirim_tutari'];
            $toplamGuncel += $row['guncel_birim_fiyat'];
            $toplamKdv += $row['kdv_tutari'];
            $toplamTevkifat += $row['tevkifat_tutari'];
            $toplamNet += $row['net_tutar'];

            ?>

                <tr>

                    <td class="center">
                        <?php echo $sira++; ?>
                    </td>

                    <td class="center">
                        <?php echo date('d.m.Y', strtotime($row['tasima_tarihi'])); ?>
                    </td>

                    <td class="center">
                        <?php echo $row['irsaliye_no']; ?>
                    </td>

                    <td class="center">
                        <?php echo $row['cikis_noktasi']; ?>
                    </td>

                    <td class="center">
                        <?php echo $row['varis_noktasi']; ?>
                    </td>

                    <td class="right blue">
                        ₺<?php echo number_format($row['birim_fiyat'],2,',','.'); ?>
                    </td>

                    <td class="right">
                        ₺<?php echo number_format($row['motorin_baz_fiyati'],3,',','.'); ?>
                    </td>

                    <td class="right">
                        ₺<?php echo number_format($row['gunluk_motorin_fiyati'],3,',','.'); ?>
                    </td>

                    <td class="right orange">
                        ₺<?php echo number_format($row['motorin_fark_tutari'],2,',','.'); ?>
                    </td>

                    <td class="right">
                        %<?php echo number_format($row['motorin_fark_yuzde'],2,',','.'); ?>
                    </td>

                    <td class="right green">
                        ₺<?php echo number_format($row['zam_indirim_tutari'],2,',','.'); ?>
                    </td>

                    <td class="right blue">
                        ₺<?php echo number_format($row['guncel_birim_fiyat'],2,',','.'); ?>
                    </td>

                    <td class="right">
                        ₺<?php echo number_format($row['kdv_tutari'],2,',','.'); ?>
                    </td>

                    <td class="right red">
                        ₺<?php echo number_format($row['tevkifat_tutari'],2,',','.'); ?>
                    </td>

                    <td class="right green">
                        ₺<?php echo number_format($row['net_tutar'],2,',','.'); ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            <tr class="total-row">

                <td colspan="5" class="center">
                    TOPLAM
                </td>

                <td class="right">
                    ₺<?php echo number_format($toplamBaz,2,',','.'); ?>
                </td>

                <td colspan="4"></td>

                <td class="right green">
                    ₺<?php echo number_format($toplamZam,2,',','.'); ?>
                </td>

                <td class="right blue">
                    ₺<?php echo number_format($toplamGuncel,2,',','.'); ?>
                </td>

                <td class="right">
                    ₺<?php echo number_format($toplamKdv,2,',','.'); ?>
                </td>

                <td class="right red">
                    ₺<?php echo number_format($toplamTevkifat,2,',','.'); ?>
                </td>

                <td class="right green">
                    ₺<?php echo number_format($toplamNet,2,',','.'); ?>
                </td>

            </tr>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>