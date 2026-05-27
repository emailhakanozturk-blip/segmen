<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$query = $db->query("
    SELECT
        sozlesmeler.*,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler
        ON cariler.id = sozlesmeler.cari_id
    ORDER BY sozlesmeler.id DESC
");

$sozlesmeler = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Sözleşmeler</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.table-area{
    background:white;
    padding:16px;
    border-radius:10px;
    border:1px solid #e5e7eb;
    box-shadow:0 8px 22px rgba(15,23,42,.04);
    overflow:auto;
}

.top-actions{
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.top-actions h3{
    margin:0;
    font-size:15px;
    color:#0f172a;
}

.btn{
    background:#16a34a;
    color:white;
    padding:8px 12px;
    border-radius:7px;
    text-decoration:none;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}

table th{
    background:#0f172a;
    color:#f8fafc;
    padding:9px 8px;
    text-align:left;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:0;
    border-bottom:1px solid #e5e7eb;
}

table td{
    padding:9px 8px;
    border-bottom:1px solid #eef2f7;
    font-size:12px;
    color:#111827;
    vertical-align:middle;
    overflow-wrap:anywhere;
}

table tr:hover td{
    background:#f8fafc;
}

.badge{
    padding:4px 8px;
    border-radius:999px;
    color:white;
    font-size:10px;
    font-weight:800;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.active{
    background:#16a34a;
}

.passive{
    background:#dc2626;
}

.action-btn{
    color:white;
    padding:6px 8px;
    border-radius:6px;
    text-decoration:none;
    font-size:10px;
    margin:2px;
    display:inline-block;
    font-weight:800;
}

.edit-btn{
    background:#2563eb;
}

.delete-btn{
    background:#dc2626;
}

.actions-cell{
    white-space:normal;
    min-width:92px;
}

@media(max-width:900px){
    .top-actions{align-items:flex-start;flex-direction:column;}
    table{min-width:760px;}
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Sözleşme Yönetimi</h2>

        <p>
            Cari bazlı sözleşme yönetim sistemi
        </p>

    </div>

    <div class="table-area">

        <div class="top-actions">

            <h3>Sözleşme Listesi</h3>

            <a href="sozlesme-ekle.php" class="btn">
                + Yeni Sözleşme
            </a>

        </div>

        <table>

            <thead>

                <tr>
                    <th>ID</th>
                    <th>Sözleşme No</th>
                    <th>Firma</th>
                    <th>Başlangıç</th>
                    <th>Bitiş</th>
                    <th>Tutar</th>
                    <th>Durum</th>
                    <th>İşlem</th>
                </tr>

            </thead>

            <tbody>

            <?php foreach($sozlesmeler as $sozlesme): ?>

                <tr>

                    <td><?php echo $sozlesme['id']; ?></td>

                    <td><?php echo $sozlesme['sozlesme_no']; ?></td>

                    <td><?php echo $sozlesme['firma_adi']; ?></td>

                    <td><?php echo $sozlesme['baslangic_tarihi']; ?></td>

                    <td><?php echo $sozlesme['bitis_tarihi']; ?></td>

                    <td class="actions-cell">
                        ₺<?php echo number_format($sozlesme['sozlesme_tutari'],2,',','.'); ?>
                    </td>

                    <td>

                        <?php if($sozlesme['durum']==1): ?>

                            <span class="badge active">Aktif</span>

                        <?php else: ?>

                            <span class="badge passive">Pasif</span>

                        <?php endif; ?>

                    </td>

                    <td>

                        <a
                            href="sozlesme-duzenle.php?id=<?php echo $sozlesme['id']; ?>"
                            class="action-btn edit-btn"
                        >
                            Düzenle
                        </a>

                        <a
                            href="sozlesme-sil.php?id=<?php echo $sozlesme['id']; ?>"
                            class="action-btn delete-btn"
                            onclick="return confirm('Bu sözleşmeyi silmek istediğinize emin misiniz?');"
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
