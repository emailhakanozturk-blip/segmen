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

.btn{
    background:#16a34a;
    color:white;
    padding:12px 18px;
    border-radius:8px;
    text-decoration:none;
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

.active{
    background:#16a34a;
}

.passive{
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
}

.edit-btn{
    background:#2563eb;
}

.delete-btn{
    background:#dc2626;
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

                    <td>
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