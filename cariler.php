<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$search = $_GET['search'] ?? '';

if($search){

    $query = $db->prepare("
        SELECT * FROM cariler
        WHERE firma_adi LIKE ?
        OR firma_kodu LIKE ?
        OR yetkili LIKE ?
        ORDER BY id DESC
    ");

    $query->execute([
        "%$search%",
        "%$search%",
        "%$search%"
    ]);

}else{

    $query = $db->query("
        SELECT * FROM cariler
        ORDER BY id DESC
    ");

}

$cariler = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Cariler</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

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

.alici{
    background:#2563eb;
}

.satici{
    background:#dc2626;
}

.nakliyeci{
    background:#16a34a;
}

.sponsor{
    background:#9333ea;
}

.action-btn{

    padding:8px 12px;
    border-radius:6px;
    color:white;
    text-decoration:none;
    font-size:13px;
    margin-right:5px;

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

        <h2>
            Cari Yönetimi
        </h2>

        <p>
            Firma, alıcı, satıcı ve nakliyeci yönetimi
        </p>

    </div>

    <div class="table-area">

        <div class="top-actions">

            <form method="GET">

                <input
                    type="text"
                    name="search"
                    placeholder="Firma ara..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="
                        padding:12px;
                        border:1px solid #ddd;
                        border-radius:8px;
                        width:250px;
                    "
                >

            </form>

            <a href="cari-ekle.php" class="btn">
                + Yeni Cari
            </a>

        </div>

        <table>

            <thead>

                <tr>

                    <th>ID</th>
                    <th>Firma Kodu</th>
                    <th>Firma Adı</th>
                    <th>Yetkili</th>
                    <th>Telefon</th>
                    <th>Tip</th>
                    <th>İşlem</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($cariler as $cari): ?>

                <tr>

                    <td>
                        <?php echo $cari['id']; ?>
                    </td>

                    <td>
                        <?php echo $cari['firma_kodu']; ?>
                    </td>

                    <td>
                        <?php echo $cari['firma_adi']; ?>
                    </td>

                    <td>
                        <?php echo $cari['yetkili']; ?>
                    </td>

                    <td>
                        <?php echo $cari['telefon']; ?>
                    </td>

                    <td>

                        <span class="badge <?php echo $cari['tip']; ?>">

                            <?php echo strtoupper($cari['tip']); ?>

                        </span>

                    </td>

                    <td>

                        <a
                            href="cari-duzenle.php?id=<?php echo $cari['id']; ?>"
                            class="action-btn edit-btn"
                        >
                            Düzenle
                        </a>

                        <a
                            href="cari-sil.php?id=<?php echo $cari['id']; ?>"
                            class="action-btn delete-btn"
                            onclick="return confirm('Bu cariyi silmek istediğinize emin misiniz?');"
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