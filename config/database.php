<?php

function db_env(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

$isLocalhost =
    $httpHost === 'localhost' ||
    $httpHost === '127.0.0.1' ||
    str_starts_with($httpHost, 'localhost:') ||
    str_starts_with($httpHost, '127.0.0.1:') ||
    str_contains($httpHost, '.test') ||
    str_contains($httpHost, '.local');

if ($isLocalhost) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'segmen');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    define('DB_HOST', db_env('DB_HOST', 'localhost'));
    define('DB_NAME', db_env('DB_NAME', 'yerelsof_segmen'));
    define('DB_USER', db_env('DB_USER', 'yerelsof_segmen_admin'));
    define('DB_PASS', db_env('DB_PASS', 'BURAYA_CANLI_VERITABANI_SIFRESI'));
}

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

    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_turkish_ci");

    $usersTable = $db->query("SHOW TABLES LIKE 'users'")->fetchColumn();

    if ($usersTable) {
        $userColumns = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('can_view', $userColumns, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN can_view TINYINT(1) NOT NULL DEFAULT 1 AFTER password");
        }

        if (!in_array('can_edit', $userColumns, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 1 AFTER can_view");
        }
    }

    $tarifelerTable = $db->query("SHOW TABLES LIKE 'tarifeler'")->fetchColumn();

    if ($tarifelerTable) {
        $tarifeColumns = $db->query("SHOW COLUMNS FROM tarifeler")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('cari_id', $tarifeColumns, true)) {
            $db->exec("ALTER TABLE tarifeler ADD COLUMN cari_id INT NULL AFTER id");
        }

        if (!in_array('sozlesme_id', $tarifeColumns, true)) {
            $db->exec("ALTER TABLE tarifeler ADD COLUMN sozlesme_id INT NULL AFTER cari_id");
        }

        if (!in_array('sevkiyat_km', $tarifeColumns, true)) {
            $db->exec("ALTER TABLE tarifeler ADD COLUMN sevkiyat_km DECIMAL(10,2) NULL DEFAULT 0 AFTER arac_tipi");
        }

        $db->exec("ALTER TABLE tarifeler MODIFY birim_fiyat DECIMAL(15,4) NULL");
        $db->exec("ALTER TABLE tarifeler MODIFY motorin_baz_fiyati DECIMAL(15,4) NULL");

        $db->exec("
            UPDATE tarifeler t
            LEFT JOIN cariler c ON c.firma_adi = t.firma_adi
            SET t.cari_id = c.id
            WHERE (t.cari_id IS NULL OR t.cari_id = 0)
            AND c.id IS NOT NULL
        ");

        $db->exec("
            UPDATE tarifeler t
            INNER JOIN sozlesmeler s
                ON (
                    s.sozlesme_no = t.sozlesme_no
                    OR t.sozlesme_no LIKE CONCAT('%', s.sozlesme_no)
                )
                AND (
                    t.cari_id IS NULL
                    OR t.cari_id = 0
                    OR t.cari_id = s.cari_id
                )
            SET
                t.sozlesme_id = s.id,
                t.sozlesme_no = s.sozlesme_no,
                t.cari_id = s.cari_id
            WHERE (t.sozlesme_id IS NULL OR t.sozlesme_id = 0)
        ");

        $db->exec("
            UPDATE tarifeler t
            INNER JOIN (
                SELECT cari_id, MIN(id) AS sozlesme_id
                FROM sozlesmeler
                WHERE durum = 1
                GROUP BY cari_id
                HAVING COUNT(*) = 1
            ) tek_sozlesme ON tek_sozlesme.cari_id = t.cari_id
            INNER JOIN sozlesmeler s ON s.id = tek_sozlesme.sozlesme_id
            SET
                t.sozlesme_id = s.id,
                t.sozlesme_no = s.sozlesme_no
            WHERE (t.sozlesme_id IS NULL OR t.sozlesme_id = 0)
        ");

        $db->exec("
            UPDATE tarifeler
            SET sevkiyat_km = birim_fiyat / motorin_baz_fiyati
            WHERE (sevkiyat_km IS NULL OR sevkiyat_km = 0)
            AND birim_fiyat > 0
            AND motorin_baz_fiyati > 0
        ");

    }

    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) && $usersTable) {
        $permissionQuery = $db->prepare("SELECT can_view, can_edit FROM users WHERE id = ?");
        $permissionQuery->execute([(int)$_SESSION['user_id']]);
        $permissionUser = $permissionQuery->fetch(PDO::FETCH_ASSOC);

        if ($permissionUser) {
            $_SESSION['can_view'] = (int)($permissionUser['can_view'] ?? 1);
            $_SESSION['can_edit'] = (int)($permissionUser['can_edit'] ?? 1);

            if ((int)$_SESSION['can_view'] !== 1) {
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

            if (
                (int)$_SESSION['can_edit'] !== 1 &&
                ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($currentScript, $writePages, true))
            ) {
                http_response_code(403);
                die('Bu işlem için düzeltme yetkiniz yok.');
            }
        }
    }

} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}
