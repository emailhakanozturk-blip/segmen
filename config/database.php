<?php

function db_env(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

$httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$isLocalhost = $httpHost === 'localhost'
    || $httpHost === '127.0.0.1'
    || str_starts_with($httpHost, 'localhost:')
    || str_starts_with($httpHost, '127.0.0.1:');

define('DB_HOST', db_env('DB_HOST', 'localhost'));
define('DB_NAME', db_env('DB_NAME', $isLocalhost ? 'segmen' : 'yerelsof_segmen'));
define('DB_USER', db_env('DB_USER', $isLocalhost ? 'root' : 'yerelsof_segmen_admin'));
define('DB_PASS', db_env('DB_PASS', $isLocalhost ? '' : 'Hb9863'));

try {

    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET CHARACTER SET utf8mb4");

    $usersTable = $db->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if($usersTable){
        $userColumns = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if(!in_array('can_view', $userColumns, true)){
            $db->exec("ALTER TABLE users ADD COLUMN can_view TINYINT(1) NOT NULL DEFAULT 1 AFTER password");
        }
        if(!in_array('can_edit', $userColumns, true)){
            $db->exec("ALTER TABLE users ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 1 AFTER can_view");
        }
    }

    if(session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) && $usersTable){
        $permissionQuery = $db->prepare("SELECT can_view, can_edit FROM users WHERE id = ?");
        $permissionQuery->execute([(int)$_SESSION['user_id']]);
        $permissionUser = $permissionQuery->fetch(PDO::FETCH_ASSOC);

        if($permissionUser){
            $_SESSION['can_view'] = (int)($permissionUser['can_view'] ?? 1);
            $_SESSION['can_edit'] = (int)($permissionUser['can_edit'] ?? 1);

            if((int)$_SESSION['can_view'] !== 1){
                session_destroy();
                header("Location: login.php");
                exit;
            }

            $currentScript = basename((string)($_SERVER['PHP_SELF'] ?? ''));
            $writePages = [
                'cari-ekle.php',
                'cari-duzenle.php',
                'cari-sil.php',
                'excel-eslestir.php',
                'excel-yukle.php',
                'hakedis-ekle.php',
                'hakedis-olustur.php',
                'hakedis-onayla.php',
                'hakedis-sil.php',
                'motorin-yukle.php',
                'nokta-yonetimi.php',
                'noktalar.php',
                'sozlesme-ekle.php',
                'sozlesme-duzenle.php',
                'sozlesme-sil.php',
                'tarife-ekle.php',
                'tarife-yukle.php',
                'tarifeler.php',
            ];

            if((int)$_SESSION['can_edit'] !== 1 && ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($currentScript, $writePages, true))){
                http_response_code(403);
                die('Bu işlem için düzeltme yetkiniz yok.');
            }
        }
    }

} catch (PDOException $e) {

    die("Veritabanı bağlantı hatası: " . $e->getMessage());

}
