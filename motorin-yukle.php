<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once __DIR__ . '/config/database.php';

if(file_exists(__DIR__ . '/vendor/autoload.php')){
    require_once __DIR__ . '/vendor/autoload.php';
}else{
    if(!interface_exists('Psr\\SimpleCache\\CacheInterface')){
        eval('namespace Psr\\SimpleCache; interface CacheInterface { public function get(string $key, mixed $default = null): mixed; public function set(string $key, mixed $value, null|int|\\DateInterval $ttl = null): bool; public function delete(string $key): bool; public function clear(): bool; public function getMultiple(iterable $keys, mixed $default = null): iterable; public function setMultiple(iterable $values, null|int|\\DateInterval $ttl = null): bool; public function deleteMultiple(iterable $keys): bool; public function has(string $key): bool; }');
    }

    spl_autoload_register(function($class){
        $prefix = 'PhpOffice\\PhpSpreadsheet\\';
        if(strpos($class, $prefix) !== 0){
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/vendor/PhpSpreadsheet-5.7.0/src/PhpSpreadsheet/' . str_replace('\\', '/', $relative) . '.php';

        if(file_exists($file)){
            require_once $file;
        }
    });
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

function temizFiyat($value){

    $value = trim((string)$value);

    $value = str_replace(['₺','TL',' '], '', $value);

    if(strpos($value, ',') !== false){

        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return floatval($value);
}

function tarihCevir($value){

    if(is_numeric($value)){

        return Date::excelToDateTimeObject($value)
            ->format('Y-m-d');
    }

    $value = trim((string)$value);

    if(empty($value)){
        return null;
    }

    $value = str_replace('/', '.', $value);

    $parca = explode('.', $value);

    if(count($parca) == 3){

        return $parca[2] . '-' .
               str_pad($parca[1],2,'0',STR_PAD_LEFT) . '-' .
               str_pad($parca[0],2,'0',STR_PAD_LEFT);
    }

    return $value;
}

/* SİL */
if(isset($_GET['sil'])){

    $delete = $db->prepare("
        DELETE FROM motorin_fiyatlari
        WHERE id = ?
    ");

    $delete->execute([
        $_GET['sil']
    ]);

    $message = 'Motorin fiyatı silindi.';
}

/* GÜNCELLE */
if(isset($_POST['guncelle'])){

    $id = $_POST['id'];

    $tarih = $_POST['tarih'];

    $motorin =
        temizFiyat($_POST['motorin_fiyati']);

    $update = $db->prepare("
        UPDATE motorin_fiyatlari
        SET tarih = ?, motorin_fiyati = ?
        WHERE id = ?
    ");

    $update->execute([
        $tarih,
        $motorin,
        $id
    ]);

    $message = 'Motorin fiyatı güncellendi.';
}

/* DOSYA YÜKLE */
if(isset($_FILES['csv'])){

    $tmp = $_FILES['csv']['tmp_name'];

    $name = $_FILES['csv']['name'];

    $ext = strtolower(
        pathinfo($name, PATHINFO_EXTENSION)
    );

    $satirlar = [];

    /* XLSX */
    if(in_array($ext, ['xlsx','xls'])){

        if(!class_exists('ZipArchive')){
            $message = 'Excel okumak için PHP zip eklentisi aktif olmalıdır. Apache yeniden başlatıldıktan sonra tekrar deneyin.';
            $satirlar = [];
        }else{
            $spreadsheet = IOFactory::load($tmp);

            $sheet = $spreadsheet->getActiveSheet();

            $satirlar = $sheet->toArray();
        }

    }else{

        /* CSV */

        if(($file = fopen($tmp, 'r')) !== FALSE){

            while(($data = fgetcsv(
                $file,
                5000,
                ";",
                '"',
                "\\"
            )) !== FALSE){

                $satirlar[] = $data;
            }

            fclose($file);
        }
    }

    foreach($satirlar as $index => $data){

        if($index == 0){
            continue;
        }

        /* TARİH */
        $tarih =
            tarihCevir($data[0] ?? '');

        /* MOTORİN KOLONU = 4. kolon */
        $motorin =
            temizFiyat($data[3] ?? 0);

        if(!$tarih || $motorin <= 0){
            continue;
        }

        $kontrol = $db->prepare("
            SELECT id
            FROM motorin_fiyatlari
            WHERE tarih = ?
            LIMIT 1
        ");

        $kontrol->execute([
            $tarih
        ]);

        $varMi =
            $kontrol->fetch(PDO::FETCH_ASSOC);

        /* VARSA GÜNCELLE */
        if($varMi){

            $update = $db->prepare("
                UPDATE motorin_fiyatlari
                SET motorin_fiyati = ?
                WHERE id = ?
            ");

            $update->execute([
                $motorin,
                $varMi['id']
            ]);

        }else{

            /* YOKSA EKLE */

            $insert = $db->prepare("
                INSERT INTO motorin_fiyatlari
                (
                    tarih,
                    motorin_fiyati
                )
                VALUES
                (?, ?)
            ");

            $insert->execute([
                $tarih,
                $motorin
            ]);
        }
    }

    $message =
        'Motorin fiyatları başarıyla yüklendi.';
}

/* LİSTE */

$query = $db->query("
    SELECT *
    FROM motorin_fiyatlari
    ORDER BY tarih DESC
");

$motorinler =
    $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Motorin Fiyatları</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>

.box{
    background:white;
    padding:25px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
    margin-bottom:20px;
}

button{
    background:#16a34a;
    color:white;
    border:none;
    padding:10px 15px;
    border-radius:8px;
    cursor:pointer;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
}

input[type=file],
input[type=date],
input[type=text]{
    padding:8px;
    border:1px solid #ddd;
    border-radius:6px;
}

table{
    width:100%;
    border-collapse:collapse;
    background:white;
}

table th{
    background:#1d3557;
    color:white;
    padding:10px;
    font-size:12px;
}

table td{
    padding:8px;
    border-bottom:1px solid #eee;
    text-align:center;
    font-size:12px;
}

.btn-red{
    background:#dc2626;
    color:white;
    padding:8px 12px;
    border-radius:6px;
    text-decoration:none;
}

.btn-blue{
    background:#2563eb;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Motorin Fiyatları</h2>

        <p>
            Motorin fiyatlarını yükleyin, listeleyin, düzeltin veya silin
        </p>

    </div>

    <div class="box">

        <?php if($message): ?>

        <div class="alert">

            <?php echo $message; ?>

        </div>

        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <input type="file" name="csv" required>

            <button type="submit">

                Motorin Dosyası Yükle

            </button>

        </form>

    </div>

    <div class="box">

        <h3>Motorin Fiyat Listesi</h3>

        <table>

            <thead>

                <tr>

                    <th>ID</th>
                    <th>Tarih</th>
                    <th>Motorin Fiyatı</th>
                    <th>İşlem</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($motorinler as $m): ?>

                <tr>

                    <form method="POST">

                        <td>

                            <?php echo $m['id']; ?>

                            <input
                                type="hidden"
                                name="id"
                                value="<?php echo $m['id']; ?>"
                            >

                        </td>

                        <td>

                            <input
                                type="date"
                                name="tarih"
                                value="<?php echo $m['tarih']; ?>"
                            >

                        </td>

                        <td>

                            <input
                                type="text"
                                name="motorin_fiyati"
                                value="<?php echo number_format($m['motorin_fiyati'],3,',','.'); ?>"
                            >

                        </td>

                        <td>

                            <button
                                type="submit"
                                name="guncelle"
                                value="1"
                                class="btn-blue"
                            >
                                Güncelle
                            </button>

                            <a
                                href="motorin-yukle.php?sil=<?php echo $m['id']; ?>"
                                class="btn-red"
                                onclick="return confirm('Bu kayıt silinsin mi?');"
                            >
                                Sil
                            </a>

                        </td>

                    </form>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>
