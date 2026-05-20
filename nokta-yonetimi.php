<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

function temizNokta($text){
    $text = trim((string)$text);
    $text = str_replace(["\xc2\xa0"], ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    if(function_exists('mb_strtoupper')){
        return mb_strtoupper($text, 'UTF-8');
    }

    return strtoupper($text);
}

$db->exec("
CREATE TABLE IF NOT EXISTS noktalar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nokta_adi VARCHAR(255) NOT NULL,
    nokta_tipi VARCHAR(50) NOT NULL,
    durum TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if(isset($_POST['kaydet'])){

    $noktaAdi = temizNokta($_POST['nokta_adi'] ?? '');
    $noktaTipi = trim($_POST['nokta_tipi'] ?? '');

    if($noktaAdi != '' && in_array($noktaTipi, ['CIKIS','VARIS'])){

        $kontrol = $db->prepare("
            SELECT id 
            FROM noktalar
            WHERE nokta_adi = ?
            AND nokta_tipi = ?
            LIMIT 1
        ");

        $kontrol->execute([$noktaAdi, $noktaTipi]);

        if($kontrol->fetch()){
            $message = 'Bu nokta daha önce eklenmiş.';
        } else {

            $insert = $db->prepare("
                INSERT INTO noktalar
                (
                    nokta_adi,
                    nokta_tipi,
                    durum
                )
                VALUES
                (?, ?, 1)
            ");

            $insert->execute([
                $noktaAdi,
                $noktaTipi
            ]);

            $message = 'Nokta başarıyla eklendi.';
        }
    } else {
        $message = 'Nokta adı ve tipi zorunludur.';
    }
}

if(isset($_GET['pasif'])){

    $id = (int)$_GET['pasif'];

    $update = $db->prepare("
        UPDATE noktalar
        SET durum = 0
        WHERE id = ?
    ");

    $update->execute([$id]);

    header("Location: nokta-yonetimi.php");
    exit;
}

if(isset($_GET['aktif'])){

    $id = (int)$_GET['aktif'];

    $update = $db->prepare("
        UPDATE noktalar
        SET durum = 1
        WHERE id = ?
    ");

    $update->execute([$id]);

    header("Location: nokta-yonetimi.php");
    exit;
}

$query = $db->query("
    SELECT *
    FROM noktalar
    ORDER BY nokta_tipi ASC, nokta_adi ASC
");

$rows = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Nokta Yönetimi</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>

.box{
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.form-group{
    margin-bottom:15px;
}

label{
    display:block;
    margin-bottom:6px;
    font-weight:bold;
}

input,
select{
    width:100%;
    padding:12px;
    border:1px solid #d1d5db;
    border-radius:8px;
    box-sizing:border-box;
}

button{
    background:#2563eb;
    color:white;
    border:none;
    padding:14px 20px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
    font-weight:bold;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:25px;
}

table th{
    background:#1d3557;
    color:white;
    padding:12px;
    font-size:13px;
    text-align:left;
}

table td{
    padding:12px;
    border-bottom:1px solid #e5e7eb;
    font-size:13px;
}

.badge{
    display:inline-block;
    padding:6px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}

.badge-green{
    background:#dcfce7;
    color:#166534;
}

.badge-red{
    background:#fee2e2;
    color:#991b1b;
}

.btn-small{
    padding:8px 12px;
    border-radius:6px;
    color:white;
    text-decoration:none;
    font-size:12px;
    font-weight:bold;
}

.btn-red{
    background:#dc2626;
}

.btn-green{
    background:#16a34a;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Nokta Yönetimi</h2>

        <p>Çıkış ve varış noktalarını yönetin</p>

    </div>

    <div class="box">

        <?php if($message): ?>
            <div class="alert">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label>Nokta Adı</label>
                <input type="text" name="nokta_adi" required>
            </div>

            <div class="form-group">
                <label>Nokta Tipi</label>

                <select name="nokta_tipi" required>
                    <option value="">Seçiniz</option>
                    <option value="CIKIS">Çıkış Noktası</option>
                    <option value="VARIS">Varış Noktası</option>
                </select>
            </div>

            <button type="submit" name="kaydet">
                Nokta Kaydet
            </button>

        </form>

        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nokta</th>
                    <th>Tip</th>
                    <th>Durum</th>
                    <th>İşlem</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach($rows as $row): ?>

                <tr>

                    <td><?php echo $row['id']; ?></td>

                    <td><?php echo htmlspecialchars($row['nokta_adi']); ?></td>

                    <td>
                        <?php echo $row['nokta_tipi'] == 'CIKIS' ? 'Çıkış' : 'Varış'; ?>
                    </td>

                    <td>
                        <?php if($row['durum'] == 1): ?>
                            <span class="badge badge-green">AKTİF</span>
                        <?php else: ?>
                            <span class="badge badge-red">PASİF</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if($row['durum'] == 1): ?>
                            <a class="btn-small btn-red" href="?pasif=<?php echo $row['id']; ?>">
                                Pasif Yap
                            </a>
                        <?php else: ?>
                            <a class="btn-small btn-green" href="?aktif=<?php echo $row['id']; ?>">
                                Aktif Yap
                            </a>
                        <?php endif; ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>