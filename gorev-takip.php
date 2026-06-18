<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

function task_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function task_date($value): string
{
    return $value ? date('d.m.Y', strtotime((string)$value)) : '-';
}

function task_status_label(string $status): string
{
    return [
        'baslamadi' => 'Hiç Başlamadı',
        'devam' => 'Devam Ediyor',
        'tamamlandi' => 'Tamamlandı',
    ][$status] ?? 'Hiç Başlamadı';
}

function task_status_class(array $task): string
{
    if(($task['durum'] ?? '') === 'tamamlandi'){
        return 'done';
    }
    if(!empty($task['bitis_tarihi']) && strtotime((string)$task['bitis_tarihi']) < strtotime(date('Y-m-d'))){
        return 'late';
    }
    if(($task['durum'] ?? '') === 'devam'){
        return 'work';
    }
    return 'wait';
}

function task_token(): string
{
    return bin2hex(random_bytes(24));
}

function task_short(string $value, int $limit = 180): string
{
    if(function_exists('mb_strimwidth')){
        return mb_strimwidth($value, 0, $limit, '...', 'UTF-8');
    }
    return strlen($value) > $limit ? substr($value, 0, $limit) . '...' : $value;
}

function task_progress(array $task): int
{
    if(($task['durum'] ?? '') === 'tamamlandi'){
        return 100;
    }
    $start = !empty($task['baslangic_tarihi']) ? strtotime((string)$task['baslangic_tarihi']) : false;
    $end = !empty($task['bitis_tarihi']) ? strtotime((string)$task['bitis_tarihi']) : false;
    if(!$start || !$end || $end <= $start){
        return 0;
    }
    $today = strtotime(date('Y-m-d'));
    if($today <= $start){
        return 0;
    }
    if($today >= $end){
        return 100;
    }
    return max(0, min(100, (int)round((($today - $start) / ($end - $start)) * 100)));
}

$db->exec("
CREATE TABLE IF NOT EXISTS gorev_personelleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_soyad VARCHAR(180) NOT NULL,
    email VARCHAR(180) NULL,
    unvan VARCHAR(150) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gorev_personel_ad (ad_soyad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS gorev_toplantilari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    toplanti_tarihi DATE NOT NULL,
    baslik VARCHAR(220) NOT NULL,
    tutanak TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_toplanti_tarih (toplanti_tarihi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS gorevler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    toplanti_id INT NULL,
    personel_id INT NOT NULL,
    baslik VARCHAR(220) NOT NULL,
    aciklama TEXT NULL,
    baslangic_tarihi DATE NULL,
    bitis_tarihi DATE NULL,
    durum ENUM('baslamadi','devam','tamamlandi') NOT NULL DEFAULT 'baslamadi',
    link_token VARCHAR(96) NOT NULL UNIQUE,
    tamamlanma_tarihi DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gorev_personel (personel_id),
    INDEX idx_gorev_durum (durum),
    INDEX idx_gorev_bitis (bitis_tarihi),
    CONSTRAINT fk_gorev_personel FOREIGN KEY (personel_id) REFERENCES gorev_personelleri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$gorevColumns = $db->query("SHOW COLUMNS FROM gorevler")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('toplanti_id', $gorevColumns, true)){
    $db->exec("ALTER TABLE gorevler ADD COLUMN toplanti_id INT NULL AFTER id");
}
$personelColumns = $db->query("SHOW COLUMNS FROM gorev_personelleri")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('user_id', $personelColumns, true)){
    $db->exec("ALTER TABLE gorev_personelleri ADD COLUMN user_id INT NULL AFTER id, ADD INDEX idx_gorev_personel_user (user_id)");
}

$message = '';
$error = '';
$tab = (string)($_GET['tab'] ?? 'toplanti');
if(!in_array($tab, ['toplanti','gorev','personel','rapor'], true)){
    $tab = 'gorev';
}
$editablePages = json_decode((string)($_SESSION['editable_pages'] ?? ''), true);
$editablePages = is_array($editablePages) ? $editablePages : [];
$canManageTasks = (int)($_SESSION['can_edit'] ?? 1) === 1 || in_array('gorev-takip.php', $editablePages, true);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if(!$canManageTasks && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($_GET['edit']) || isset($_GET['personel_edit']) || isset($_GET['toplanti_edit']))){
    http_response_code(403);
    exit('Bu işlem için yetkiniz yok.');
}

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = (string)($_POST['action'] ?? '');

        if($action === 'toplanti_kaydet'){
            $id = (int)($_POST['id'] ?? 0);
            $tarih = trim((string)($_POST['toplanti_tarihi'] ?? ''));
            $baslik = trim((string)($_POST['baslik'] ?? ''));
            $tutanak = trim((string)($_POST['tutanak'] ?? ''));
            if($tarih === '' || $baslik === ''){
                throw new Exception('Toplantı tarihi ve başlık zorunludur.');
            }
            if($id > 0){
                $q = $db->prepare("UPDATE gorev_toplantilari SET toplanti_tarihi=?, baslik=?, tutanak=? WHERE id=?");
                $q->execute([$tarih, $baslik, $tutanak, $id]);
                $savedToplantiId = $id;
                $message = 'Toplantı tutanağı güncellendi.';
            }else{
                $q = $db->prepare("INSERT INTO gorev_toplantilari (toplanti_tarihi,baslik,tutanak,created_by) VALUES (?,?,?,?)");
                $q->execute([$tarih, $baslik, $tutanak, (int)$_SESSION['user_id']]);
                $savedToplantiId = (int)$db->lastInsertId();
                $message = 'Toplantı tutanağı oluşturuldu. Şimdi bu toplantıdan görev atayabilirsiniz.';
            }
            $maddePersoneller = is_array($_POST['madde_personel'] ?? null) ? $_POST['madde_personel'] : [];
            $maddeBitisleri = is_array($_POST['madde_bitis'] ?? null) ? $_POST['madde_bitis'] : [];
            $maddeler = array_values(array_filter(array_map('trim', preg_split('/\R+/', $tutanak))));
            $atanan = 0;
            foreach($maddeler as $i => $madde){
                $personelId = (int)($maddePersoneller[$i] ?? 0);
                if($personelId <= 0 || $madde === ''){
                    continue;
                }
                $bitis = trim((string)($maddeBitisleri[$i] ?? '')) ?: $tarih;
                $exists = $db->prepare("SELECT COUNT(*) FROM gorevler WHERE toplanti_id=? AND personel_id=? AND baslik=?");
                $exists->execute([$savedToplantiId, $personelId, $madde]);
                if((int)$exists->fetchColumn() > 0){
                    continue;
                }
                $q = $db->prepare("INSERT INTO gorevler (toplanti_id,personel_id,baslik,aciklama,baslangic_tarihi,bitis_tarihi,durum,link_token,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
                $q->execute([$savedToplantiId, $personelId, $madde, $madde, $tarih, $bitis, 'baslamadi', task_token(), (int)$_SESSION['user_id']]);
                $atanan++;
            }
            if($atanan > 0){
                $message .= ' ' . $atanan . ' görev atandı.';
            }
            $tab = 'toplanti';
        }

        if($action === 'toplanti_sil'){
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE gorevler SET toplanti_id=NULL WHERE toplanti_id=?")->execute([$id]);
            $db->prepare("DELETE FROM gorev_toplantilari WHERE id=?")->execute([$id]);
            $message = 'Toplantı tutanağı silindi.';
            $tab = 'toplanti';
        }

        if($action === 'personel_kaydet'){
            $id = (int)($_POST['id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $unvan = trim((string)($_POST['unvan'] ?? ''));
            $aktif = isset($_POST['aktif']) ? 1 : 0;
            if($userId <= 0){
                throw new Exception('Personel adı zorunludur.');
            }
            $userQuery = $db->prepare("SELECT id, name, email FROM users WHERE id=? LIMIT 1");
            $userQuery->execute([$userId]);
            $selectedUser = $userQuery->fetch(PDO::FETCH_ASSOC);
            if(!$selectedUser){
                throw new Exception('Seçilen kullanıcı bulunamadı.');
            }
            $ad = trim((string)$selectedUser['name']);
            $email = trim((string)$selectedUser['email']);
            if(false){
                throw new Exception('Geçerli bir e-posta adresi girin.');
            }
            if($id > 0){
                $q = $db->prepare("UPDATE gorev_personelleri SET user_id=?, ad_soyad=?, email=?, unvan=?, aktif=? WHERE id=?");
                $q->execute([$userId, $ad, $email, $unvan, $aktif, $id]);
                $message = 'Personel güncellendi.';
            }else{
                $q = $db->prepare("INSERT INTO gorev_personelleri (user_id,ad_soyad,email,unvan,aktif) VALUES (?,?,?,?,?)");
                $q->execute([$userId, $ad, $email, $unvan, $aktif]);
                $message = 'Personel eklendi.';
            }
            $tab = 'personel';
        }

        if($action === 'personel_sil'){
            $db->prepare("DELETE FROM gorev_personelleri WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Personel silindi.';
            $tab = 'personel';
        }

        if($action === 'gorev_kaydet'){
            $id = (int)($_POST['id'] ?? 0);
            $toplantiId = (int)($_POST['toplanti_id'] ?? 0);
            $personelId = (int)($_POST['personel_id'] ?? 0);
            $baslik = trim((string)($_POST['baslik'] ?? ''));
            $aciklama = trim((string)($_POST['aciklama'] ?? ''));
            $baslangic = trim((string)($_POST['baslangic_tarihi'] ?? ''));
            $bitis = trim((string)($_POST['bitis_tarihi'] ?? ''));
            $durum = (string)($_POST['durum'] ?? 'baslamadi');
            if($personelId <= 0 || $baslik === ''){
                throw new Exception('Personel ve görev başlığı zorunludur.');
            }
            if(!in_array($durum, ['baslamadi','devam','tamamlandi'], true)){
                $durum = 'baslamadi';
            }
            $tamamlanma = $durum === 'tamamlandi' ? date('Y-m-d') : null;
            if($id > 0){
                $q = $db->prepare("UPDATE gorevler SET toplanti_id=?, personel_id=?, baslik=?, aciklama=?, baslangic_tarihi=?, bitis_tarihi=?, durum=?, tamamlanma_tarihi=? WHERE id=?");
                $q->execute([$toplantiId ?: null, $personelId, $baslik, $aciklama, $baslangic ?: null, $bitis ?: null, $durum, $tamamlanma, $id]);
                $message = 'Görev güncellendi.';
            }else{
                $q = $db->prepare("INSERT INTO gorevler (toplanti_id,personel_id,baslik,aciklama,baslangic_tarihi,bitis_tarihi,durum,link_token,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
                $q->execute([$toplantiId ?: null, $personelId, $baslik, $aciklama, $baslangic ?: null, $bitis ?: null, $durum, task_token(), (int)$_SESSION['user_id']]);
                $message = 'Görev oluşturuldu.';
            }
            $tab = 'gorev';
        }

        if($action === 'gorev_sil'){
            $db->prepare("DELETE FROM gorevler WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Görev silindi.';
            $tab = 'gorev';
        }

        if($action === 'email_gonder'){
            $taskId = (int)($_POST['id'] ?? 0);
            $q = $db->prepare("SELECT g.*, p.ad_soyad, p.email FROM gorevler g INNER JOIN gorev_personelleri p ON p.id=g.personel_id WHERE g.id=?");
            $q->execute([$taskId]);
            $task = $q->fetch(PDO::FETCH_ASSOC);
            if(!$task || trim((string)$task['email']) === ''){
                throw new Exception('Göreve ait personel e-postası yok.');
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $link = rtrim($base, '/\\') . '/gorev-goruntule.php?token=' . urlencode($task['link_token']);
            $subject = 'Yeni görev: ' . $task['baslik'];
            $body = "Merhaba {$task['ad_soyad']},

Size bir görev atandı.

Görev: {$task['baslik']}
Bitiş: " . task_date($task['bitis_tarihi']) . "

Görevi görüntülemek için:
{$link}";
            $sent = @mail((string)$task['email'], $subject, $body, "Content-Type: text/plain; charset=UTF-8\r\n");
            $message = $sent ? 'Görev e-postası gönderildi.' : 'Sunucu mail gönderimini kabul etmedi. Linki kopyalayarak gönderebilirsiniz: ' . $link;
            $tab = 'gorev';
        }
    }
} catch(Throwable $e){
    $error = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$editingTask = null;
if($editId > 0){
    $q = $db->prepare("SELECT * FROM gorevler WHERE id=?");
    $q->execute([$editId]);
    $editingTask = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'gorev';
}

$personelEditId = (int)($_GET['personel_edit'] ?? 0);
$editingPersonel = null;
if($personelEditId > 0){
    $q = $db->prepare("SELECT * FROM gorev_personelleri WHERE id=?");
    $q->execute([$personelEditId]);
    $editingPersonel = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'personel';
}

$toplantiEditId = (int)($_GET['toplanti_edit'] ?? 0);
$editingToplanti = null;
if($toplantiEditId > 0){
    $q = $db->prepare("SELECT * FROM gorev_toplantilari WHERE id=?");
    $q->execute([$toplantiEditId]);
    $editingToplanti = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'toplanti';
}

$usersForTask = $db->query("SELECT id, name, email FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$personelSql = "SELECT p.*, u.name AS user_name FROM gorev_personelleri p LEFT JOIN users u ON u.id=p.user_id";
$personelParams = [];
if(!$canManageTasks){
    $personelSql .= " WHERE p.user_id=?";
    $personelParams[] = $currentUserId;
}
$personelSql .= " ORDER BY p.aktif DESC, p.ad_soyad ASC";
$personelStmt = $db->prepare($personelSql);
$personelStmt->execute($personelParams);
$personeller = $personelStmt->fetchAll(PDO::FETCH_ASSOC);
$aktifPersoneller = array_values(array_filter($personeller, fn($p) => (int)$p['aktif'] === 1));
$toplantiSql = "SELECT t.*, COUNT(g.id) AS gorev_adet FROM gorev_toplantilari t LEFT JOIN gorevler g ON g.toplanti_id=t.id";
$toplantiParams = [];
if(!$canManageTasks){
    $toplantiSql .= " LEFT JOIN gorev_personelleri p ON p.id=g.personel_id WHERE p.user_id=?";
    $toplantiParams[] = $currentUserId;
}
$toplantiSql .= " GROUP BY t.id ORDER BY t.toplanti_tarihi DESC, t.id DESC";
$toplantiStmt = $db->prepare($toplantiSql);
$toplantiStmt->execute($toplantiParams);
$toplantilar = $toplantiStmt->fetchAll(PDO::FETCH_ASSOC);

$personelFiltre = (int)($_GET['personel'] ?? 0);
$durumFiltre = trim((string)($_GET['durum'] ?? ''));
$where = [];
$params = [];
if($personelFiltre > 0){
    $where[] = 'g.personel_id=?';
    $params[] = $personelFiltre;
}
if(!$canManageTasks){
    $where[] = 'p.user_id=?';
    $params[] = $currentUserId;
}
if($durumFiltre !== ''){
    $where[] = 'g.durum=?';
    $params[] = $durumFiltre;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$q = $db->prepare("
    SELECT g.*, p.ad_soyad, p.email, p.unvan, t.baslik AS toplanti_baslik, t.toplanti_tarihi
    FROM gorevler g
    INNER JOIN gorev_personelleri p ON p.id=g.personel_id
    LEFT JOIN gorev_toplantilari t ON t.id=g.toplanti_id
    {$whereSql}
    ORDER BY COALESCE(g.bitis_tarihi,'2999-12-31') ASC, g.id DESC
");
$q->execute($params);
$gorevler = $q->fetchAll(PDO::FETCH_ASSOC);

$summarySql = "
    SELECT
        COUNT(g.id) toplam,
        COALESCE(SUM(g.durum='baslamadi'),0) baslamadi,
        COALESCE(SUM(g.durum='devam'),0) devam,
        COALESCE(SUM(g.durum='tamamlandi'),0) tamamlandi,
        COALESCE(SUM(g.durum<>'tamamlandi' AND g.bitis_tarihi IS NOT NULL AND g.bitis_tarihi < CURDATE()),0) geciken
    FROM gorevler g
    INNER JOIN gorev_personelleri p ON p.id=g.personel_id
";
$summaryParams = [];
if(!$canManageTasks){
    $summarySql .= " WHERE p.user_id=?";
    $summaryParams[] = $currentUserId;
}
$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute($summaryParams);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

if(isset($_GET['toplanti_export'])){
    $exportId = (int)($_GET['toplanti_export'] ?? 0);
    $format = (string)($_GET['format'] ?? 'pdf');
    $q = $db->prepare("SELECT * FROM gorev_toplantilari WHERE id=?");
    $q->execute([$exportId]);
    $exportToplanti = $q->fetch(PDO::FETCH_ASSOC);
    if(!$exportToplanti){
        http_response_code(404);
        exit('Toplantı tutanağı bulunamadı.');
    }
    $exportWhere = "g.toplanti_id=?";
    $exportParams = [$exportId];
    if(!$canManageTasks){
        $exportWhere .= " AND p.user_id=?";
        $exportParams[] = $currentUserId;
    }
    $q = $db->prepare("SELECT g.*, p.ad_soyad FROM gorevler g LEFT JOIN gorev_personelleri p ON p.id=g.personel_id WHERE {$exportWhere} ORDER BY g.id ASC");
    $q->execute($exportParams);
    $exportGorevler = $q->fetchAll(PDO::FETCH_ASSOC);
    if(!$canManageTasks && !$exportGorevler){
        http_response_code(403);
        exit('Bu toplantı tutanağını görüntüleme yetkiniz yok.');
    }
    $items = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string)($exportToplanti['tutanak'] ?? '')))));
    $fileBase = 'toplanti-tutanagi-' . date('Ymd', strtotime((string)$exportToplanti['toplanti_tarihi']));
    if($format === 'word'){
        header('Content-Type: application/msword; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$fileBase.'.doc"');
        echo "\xEF\xBB\xBF";
    }else{
        header('Content-Type: text/html; charset=UTF-8');
    }
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Toplantı Tutanağı</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;color:#0f172a;margin:0;background:#eef3f9;font-size:14px}.doc{max-width:980px;margin:0 auto;background:#fff;padding:34px 40px;min-height:100vh}.brand{letter-spacing:5px;color:#0086a6;font-size:12px;font-weight:900;margin-bottom:14px}.title{font-size:34px;font-weight:900;margin:0 0 24px}.info{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #cbd5e1;margin-bottom:26px}.info div{padding:18px 16px;border-right:1px solid #cbd5e1}.info div:last-child{border-right:0}.info span{display:block;color:#475569;font-size:12px;font-weight:900;text-transform:uppercase;margin-bottom:8px}.info b{font-size:16px}.section{border:1px solid #cbd5e1;margin-top:24px}.section h2{background:#173f6d;color:#fff;font-size:18px;margin:0;padding:18px;border-bottom:3px solid #f2b84b}.section-body{padding:22px 26px}ul{margin:0;padding-left:22px}li{margin:12px 0;line-height:1.45;font-size:15px}.assign-person{font-weight:900}.assign-task{font-weight:900}.export-timeline{display:grid;gap:12px}.export-time-item{border:1px solid #dbe3ef;border-radius:10px;padding:12px;background:#f8fafc}.export-time-top{display:flex;justify-content:space-between;gap:10px;font-size:13px;font-weight:800}.export-time-meta{font-size:12px;color:#64748b;margin-top:4px}.export-progress{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:9px}.export-progress span{display:block;height:100%;background:#2563eb;border-radius:999px}.export-progress span.done{background:#16a34a}.export-progress span.late{background:#dc2626}.print{position:fixed;right:24px;top:18px;background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}@media print{.print{display:none}body{background:#fff}.doc{padding:24px 32px}}
</style>
</head>
<body>
<?php if($format !== 'word'): ?><button class="print" onclick="window.print()">PDF Olarak Kaydet / Yazdır</button><?php endif; ?>
<div class="doc">
    <div class="brand">SEĞMEN SU - GÖREV TAKİP</div>
    <h1 class="title"><?php echo task_e($exportToplanti['baslik']); ?></h1>
    <div class="info">
        <div><span>Birim</span><b>Seğmen Su</b></div>
        <div><span>Toplantı Tarihi</span><b><?php echo task_date($exportToplanti['toplanti_tarihi']); ?></b></div>
        <div><span>Görev Sayısı</span><b><?php echo count($exportGorevler); ?></b></div>
    </div>
    <div class="section">
        <h2>Toplantı Notları</h2>
        <div class="section-body">
            <?php if($items): ?><ul><?php foreach($items as $item): ?><li><?php echo task_e($item); ?></li><?php endforeach; ?></ul><?php else: ?><p>Madde girilmemiş.</p><?php endif; ?>
        </div>
    </div>
    <div class="section">
        <h2>Görev Atamaları</h2>
        <div class="section-body">
            <?php if($exportGorevler): ?><ul><?php foreach($exportGorevler as $g): ?><li><span class="assign-person"><?php echo task_e($g['ad_soyad']); ?></span> kişisine <span class="assign-task"><?php echo task_e($g['baslik']); ?></span> görevi atanmıştır.</li><?php endforeach; ?></ul><?php else: ?><p>Bu tutanağa bağlı görev yok.</p><?php endif; ?>
        </div>
    </div>
    <div class="section">
        <h2>Zaman Çizelgesi</h2>
        <div class="section-body export-timeline">
            <?php if($exportGorevler): ?><?php foreach($exportGorevler as $g): $p=task_progress($g); $cls=task_status_class($g); ?><div class="export-time-item"><div class="export-time-top"><span><?php echo task_e($g['baslik']); ?></span><span><?php echo $p; ?>%</span></div><div class="export-time-meta"><?php echo task_e($g['ad_soyad']); ?> | <?php echo task_date($g['baslangic_tarihi']); ?> - <?php echo task_date($g['bitis_tarihi']); ?> | <?php echo task_status_label((string)$g['durum']); ?></div><div class="export-progress"><span class="<?php echo $cls; ?>" style="width:<?php echo $p; ?>%"></span></div></div><?php endforeach; ?><?php else: ?><p>Çizelgede gösterilecek görev yok.</p><?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
<?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Görev Takip</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.task-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.task-tab{padding:10px 13px;border:1px solid #d8e0ea;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-size:12px;font-weight:800}.task-tab.active{background:#17233b;color:#fff;border-color:#17233b}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 8px 24px rgba(15,23,42,.04)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:6px}.field input,.field select,.field textarea{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:13px;box-sizing:border-box;background:#fff}.field textarea{min-height:86px;resize:vertical}.span-2{grid-column:span 2}.span-4{grid-column:span 4}.btn{border:0;border-radius:8px;padding:9px 12px;background:#2563eb;color:#fff;text-decoration:none;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.btn-green{background:#16a34a}.btn-red{background:#dc2626}.btn-gray{background:#64748b}.btn-dark{background:#0f172a}.actions{display:flex;gap:6px;flex-wrap:wrap}.notice{padding:12px 14px;border-radius:9px;margin-bottom:14px;font-size:13px;font-weight:800}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:16px}.summary-card{background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:13px}.summary-card span{display:block;font-size:11px;color:#64748b;font-weight:800}.summary-card strong{display:block;font-size:22px;margin-top:5px}.filters{display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end;margin-bottom:14px}.layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px}th{background:#0f172a;color:#fff;text-align:left;padding:9px 8px;white-space:nowrap}td{border-bottom:1px solid #edf2f7;padding:8px;vertical-align:top}.muted{color:#64748b}.badge{display:inline-flex;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:800}.badge.wait{background:#e2e8f0;color:#334155}.badge.work{background:#dbeafe;color:#1d4ed8}.badge.done{background:#dcfce7;color:#166534}.badge.late{background:#fee2e2;color:#991b1b}.timeline{position:sticky;top:18px}.timeline-list{display:flex;flex-direction:column;gap:9px}.time-item{border-left:4px solid #cbd5e1;background:#f8fafc;border-radius:8px;padding:9px 10px}.time-item.work{border-color:#2563eb}.time-item.done{border-color:#16a34a}.time-item.late{border-color:#dc2626}.time-item b{display:block;font-size:12px}.time-item span{display:block;color:#64748b;font-size:11px;margin-top:3px}.empty{padding:16px;background:#f8fafc;border-radius:9px;color:#64748b}.checkbox-line{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:700}.checkbox-line input{width:auto}@media(max-width:1150px){.layout{grid-template-columns:1fr}.timeline{position:static}.summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:750px){.grid,.filters{grid-template-columns:1fr}.span-2,.span-4{grid-column:span 1}.summary{grid-template-columns:1fr}}
.progress{height:7px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:7px}.progress-fill{display:block;height:100%;background:#2563eb;border-radius:999px}.progress-fill.done{background:#16a34a}.minute-list{display:grid;gap:8px}.minute-row{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;background:#f8fafc;border:1px solid #e5e7eb;border-radius:9px;padding:10px}.minute-row span{font-size:13px;line-height:1.4}.minute-assign-box{background:#f8fafc;border:1px solid #dbeafe;border-radius:10px;padding:12px}.assign-row{display:grid;grid-template-columns:minmax(0,1fr) 220px 150px;gap:10px;align-items:center;border-top:1px solid #e5e7eb;padding:9px 0}.assign-row:first-child{border-top:0}.assign-row b{font-size:13px}.assign-row select,.assign-row input{border:1px solid #d1d5db;border-radius:8px;padding:8px;font-size:12px}.report-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px}@media(max-width:1150px){.report-layout{grid-template-columns:1fr}.assign-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="main">
    <div class="topbar">
        <h2>Görev Takip</h2>
        <p>Personele görev atayın, süre verin, durumları ve geciken işleri tek ekranda izleyin.</p>
    </div>

    <?php if($message): ?><div class="notice ok"><?php echo task_e($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="notice err"><?php echo task_e($error); ?></div><?php endif; ?>

    <nav class="task-tabs">
        <a class="task-tab <?php echo $tab==='toplanti'?'active':''; ?>" href="?tab=toplanti">Toplantı Tutanakları</a>
        <a class="task-tab <?php echo $tab==='gorev'?'active':''; ?>" href="?tab=gorev">Görevler</a>
        <a class="task-tab <?php echo $tab==='personel'?'active':''; ?>" href="?tab=personel">Personel</a>
        <a class="task-tab <?php echo $tab==='rapor'?'active':''; ?>" href="?tab=rapor">Raporlar</a>
    </nav>

    <?php if($tab==='toplanti'): ?>
    <?php if($canManageTasks): ?>
    <div class="layout">
        <div class="panel">
            <h3><?php echo $editingToplanti ? 'Toplantı Tutanağı Düzenle' : 'Toplantı Tutanağı Oluştur'; ?></h3>
            <form method="POST" class="grid">
                <input type="hidden" name="action" value="toplanti_kaydet">
                <input type="hidden" name="id" value="<?php echo (int)($editingToplanti['id'] ?? 0); ?>">
                <div class="field"><label>Toplantı Günü</label><input type="date" name="toplanti_tarihi" required value="<?php echo task_e($editingToplanti['toplanti_tarihi'] ?? date('Y-m-d')); ?>"></div>
                <div class="field span-2"><label>Toplantı Başlığı</label><input name="baslik" required value="<?php echo task_e($editingToplanti['baslik'] ?? ''); ?>" placeholder="Haftalık operasyon toplantısı"></div>
                <div class="field span-4"><label>Toplantı Tutanağı</label><textarea id="meetingNotes" name="tutanak" placeholder="Her maddeyi ayrı satıra yazın."><?php echo task_e($editingToplanti['tutanak'] ?? ''); ?></textarea></div>
                <div class="span-4 minute-assign-box">
                    <b>Tutanak İçinden Görev Ata</b>
                    <p class="muted">Her satır ayrı görev maddesi olur. Personel seçerseniz toplantı kaydıyla birlikte görev açılır.</p>
                    <select id="personelOptionsSource" style="display:none"><option value="">Görev atama</option><?php foreach($aktifPersoneller as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo task_e($p['ad_soyad']); ?><?php echo $p['unvan'] ? ' - ' . task_e($p['unvan']) : ''; ?></option><?php endforeach; ?></select>
                    <div id="minuteAssignList"></div>
                </div>
                <div class="span-4 actions"><button class="btn btn-green"><?php echo $editingToplanti ? 'Güncelle' : 'Tutanak Oluştur'; ?></button><?php if($editingToplanti): ?><a class="btn btn-gray" href="?tab=toplanti">Vazgeç</a><?php endif; ?></div>
            </form>
        </div>
        <aside class="panel timeline">
            <h3>Toplantıdan Görev Atama</h3>
            <p class="empty">Bir tutanak oluşturduktan sonra listeden “Görev Ata” seçin. Görev formunda toplantı ve gün otomatik gelir.</p>
        </aside>
    </div>
    <?php endif; ?>
    <div class="panel">
        <h3>Toplantı Tutanakları</h3>
        <div class="table-wrap"><table><thead><tr><th>Gün</th><th>Toplantı</th><th>Tutanak</th><th>Görev</th><th>İşlem</th></tr></thead><tbody>
        <?php if(!$toplantilar): ?><tr><td colspan="5">Henüz toplantı tutanağı yok.</td></tr><?php endif; ?>
        <?php foreach($toplantilar as $t): ?><tr>
            <td><?php echo task_date($t['toplanti_tarihi']); ?></td>
            <td><strong><?php echo task_e($t['baslik']); ?></strong></td>
            <td><?php echo nl2br(task_e(task_short((string)$t['tutanak']))); ?></td>
            <td><span class="badge work"><?php echo (int)$t['gorev_adet']; ?> görev</span></td>
            <td><div class="actions"><?php if($canManageTasks): ?><a class="btn btn-green" href="?tab=gorev&toplanti_id=<?php echo (int)$t['id']; ?>">Görev Ata</a><?php endif; ?><a class="btn btn-dark" target="_blank" href="?toplanti_export=<?php echo (int)$t['id']; ?>&format=pdf">PDF</a><a class="btn btn-gray" href="?toplanti_export=<?php echo (int)$t['id']; ?>&format=word">Word</a><?php if($canManageTasks): ?><a class="btn" href="?tab=toplanti&toplanti_edit=<?php echo (int)$t['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Silinsin mi?');"><input type="hidden" name="action" value="toplanti_sil"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><button class="btn btn-red">Sil</button></form><?php endif; ?></div></td>
        </tr><?php endforeach; ?></tbody></table></div>
    </div>
    <?php endif; ?>

    <?php if($tab==='gorev'): ?>
    <?php
        $selectedToplantiId = (int)($editingTask['toplanti_id'] ?? $_GET['toplanti_id'] ?? 0);
        $selectedToplanti = null;
        foreach($toplantilar as $toplanti){
            if((int)$toplanti['id'] === $selectedToplantiId){
                $selectedToplanti = $toplanti;
                break;
            }
        }
        $defaultStartDate = $editingTask['baslangic_tarihi'] ?? ($selectedToplanti['toplanti_tarihi'] ?? date('Y-m-d'));
        $selectedMadde = trim((string)($_GET['madde'] ?? ''));
        $toplantiMaddeleri = [];
        if($selectedToplanti && trim((string)($selectedToplanti['tutanak'] ?? '')) !== ''){
            $toplantiMaddeleri = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string)$selectedToplanti['tutanak']))));
        }
        $defaultTitle = $editingTask['baslik'] ?? $selectedMadde;
        $defaultDescription = $editingTask['aciklama'] ?? $selectedMadde;
    ?>
    <div class="summary">
        <div class="summary-card"><span>Toplam</span><strong><?php echo (int)($summary['toplam'] ?? 0); ?></strong></div>
        <div class="summary-card"><span>Hiç Başlamadı</span><strong><?php echo (int)($summary['baslamadi'] ?? 0); ?></strong></div>
        <div class="summary-card"><span>Devam Eden</span><strong><?php echo (int)($summary['devam'] ?? 0); ?></strong></div>
        <div class="summary-card"><span>Tamamlanan</span><strong><?php echo (int)($summary['tamamlandi'] ?? 0); ?></strong></div>
        <div class="summary-card"><span>Geciken</span><strong><?php echo (int)($summary['geciken'] ?? 0); ?></strong></div>
    </div>

    <?php if($selectedToplanti): ?>
    <div class="panel">
        <h3><?php echo task_date($selectedToplanti['toplanti_tarihi']); ?> Toplantı Maddeleri</h3>
        <?php if(!$toplantiMaddeleri): ?><div class="empty">Bu tutanakta madde yok. Tutanak metnini her madde ayrı satır olacak şekilde yazabilirsiniz.</div><?php endif; ?>
        <div class="minute-list">
            <?php foreach($toplantiMaddeleri as $madde): ?>
                <div class="minute-row"><span><?php echo task_e($madde); ?></span><a class="btn btn-green" href="?tab=gorev&toplanti_id=<?php echo (int)$selectedToplanti['id']; ?>&madde=<?php echo urlencode($madde); ?>">Bu Maddeden Görev Ata</a></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if($canManageTasks): ?>
    <div class="panel">
        <h3><?php echo $editingTask ? 'Görev Düzenle' : 'Yeni Görev'; ?></h3>
        <form method="POST" class="grid">
            <input type="hidden" name="action" value="gorev_kaydet">
            <input type="hidden" name="id" value="<?php echo (int)($editingTask['id'] ?? 0); ?>">
            <div class="field span-2"><label>Toplantı Tutanağı</label><select name="toplanti_id"><option value="">Bağımsız görev</option><?php foreach($toplantilar as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo $selectedToplantiId===(int)$t['id']?'selected':''; ?>><?php echo task_date($t['toplanti_tarihi']); ?> - <?php echo task_e($t['baslik']); ?></option><?php endforeach; ?></select></div>
            <div class="field span-2"><label>Personel</label><select name="personel_id" required><option value="">Seçiniz</option><?php foreach($aktifPersoneller as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo (int)($editingTask['personel_id'] ?? 0)===(int)$p['id']?'selected':''; ?>><?php echo task_e($p['ad_soyad']); ?><?php echo $p['unvan'] ? ' - ' . task_e($p['unvan']) : ''; ?></option><?php endforeach; ?></select></div>
            <div class="field span-2"><label>Toplantı Başlığı</label><input name="baslik" required value="<?php echo task_e($defaultTitle); ?>"></div>
            <div class="field"><label>Görev Günü</label><input type="date" name="baslangic_tarihi" value="<?php echo task_e($defaultStartDate); ?>"></div>
            <div class="field"><label>Bitiş / Süre</label><input type="date" name="bitis_tarihi" value="<?php echo task_e($editingTask['bitis_tarihi'] ?? $defaultStartDate); ?>"></div>
            <div class="field"><label>Durum</label><select name="durum"><?php foreach(['baslamadi','devam','tamamlandi'] as $d): ?><option value="<?php echo $d; ?>" <?php echo ($editingTask['durum'] ?? 'baslamadi')===$d?'selected':''; ?>><?php echo task_status_label($d); ?></option><?php endforeach; ?></select></div>
            <div class="field span-4"><label>Açıklama</label><textarea name="aciklama"><?php echo task_e($defaultDescription); ?></textarea></div>
            <div class="span-4 actions"><button class="btn btn-green"><?php echo $editingTask ? 'Güncelle' : 'Görev Oluştur'; ?></button><?php if($editingTask): ?><a class="btn btn-gray" href="?tab=gorev">Vazgeç</a><?php endif; ?></div>
        </form>
    </div>
    <?php endif; ?>

    <div class="layout">
        <div class="panel">
            <form method="GET" class="filters">
                <input type="hidden" name="tab" value="gorev">
                <div class="field"><label>Personel</label><select name="personel"><option value="">Tümü</option><?php foreach($personeller as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $personelFiltre===(int)$p['id']?'selected':''; ?>><?php echo task_e($p['ad_soyad']); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Durum</label><select name="durum"><option value="">Tümü</option><?php foreach(['baslamadi','devam','tamamlandi'] as $d): ?><option value="<?php echo $d; ?>" <?php echo $durumFiltre===$d?'selected':''; ?>><?php echo task_status_label($d); ?></option><?php endforeach; ?></select></div>
                <button class="btn">Filtrele</button><a class="btn btn-gray" href="?tab=gorev">Görevler</a>
            </form>
            <div class="table-wrap"><table><thead><tr><th>Toplantı</th><th>Görev</th><th>Personel</th><th>Süre</th><th>Durum</th><th>Link / E-posta</th><th>İşlem</th></tr></thead><tbody>
            <?php if(!$gorevler): ?><tr><td colspan="7"><div class="empty">Henüz görev yok.</div></td></tr><?php endif; ?>
            <?php foreach($gorevler as $g): $cls=task_status_class($g); $link='gorev-goruntule.php?token='.urlencode($g['link_token']); ?>
            <tr>
                <td><?php echo $g['toplanti_baslik'] ? '<strong>'.task_e($g['toplanti_baslik']).'</strong><br><small>'.task_date($g['toplanti_tarihi']).'</small>' : '<span class="muted">Bağımsız</span>'; ?></td>
                <td><strong><?php echo task_e($g['baslik']); ?></strong><br><small><?php echo task_e($g['aciklama']); ?></small></td>
                <td><?php echo task_e($g['ad_soyad']); ?><br><small><?php echo task_e($g['email']); ?></small></td>
                <td><?php echo task_date($g['baslangic_tarihi']); ?> - <?php echo task_date($g['bitis_tarihi']); ?><div class="progress"><span class="progress-fill <?php echo $g['durum']==='tamamlandi'?'done':''; ?>" style="width:<?php echo task_progress($g); ?>%"></span></div></td>
                <td><span class="badge <?php echo $cls; ?>"><?php echo $cls==='late' ? 'Gecikti' : task_status_label($g['durum']); ?></span></td>
                <td><a class="btn btn-dark" target="_blank" href="<?php echo task_e($link); ?>">HTML Link</a><?php if($canManageTasks): ?><form method="POST" style="display:inline"><input type="hidden" name="action" value="email_gonder"><input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>"><button class="btn btn-green">E-posta</button></form><?php endif; ?></td>
                <td><div class="actions"><?php if($canManageTasks): ?><a class="btn" href="?tab=gorev&edit=<?php echo (int)$g['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Silinsin mi?');"><input type="hidden" name="action" value="gorev_sil"><input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>"><button class="btn btn-red">Sil</button></form><?php else: ?><span class="muted">Sadece görüntüleme</span><?php endif; ?></div></td>
            </tr>
            <?php endforeach; ?></tbody></table></div>
        </div>
        <aside class="panel timeline">
            <h3>Zaman Çizelgesi</h3>
            <div class="timeline-list">
                <?php foreach(array_slice($gorevler,0,12) as $g): $cls=task_status_class($g); ?>
                    <div class="time-item <?php echo $cls; ?>"><b><?php echo task_e($g['baslik']); ?></b><span><?php echo task_e($g['ad_soyad']); ?> | <?php echo task_date($g['baslangic_tarihi']); ?> - <?php echo task_date($g['bitis_tarihi']); ?></span><div class="progress"><span class="progress-fill <?php echo $g['durum']==='tamamlandi'?'done':''; ?>" style="width:<?php echo task_progress($g); ?>%"></span></div></div>
                <?php endforeach; ?>
                <?php if(!$gorevler): ?><div class="empty">Çizelgede gösterilecek görev yok.</div><?php endif; ?>
            </div>
        </aside>
    </div>
    <?php endif; ?>

    <?php if($tab==='personel'): ?>
    <?php if($canManageTasks): ?>
    <div class="panel">
            <h3><?php echo $editingPersonel ? 'Personel Düzenle' : 'Personel Ekle'; ?></h3>
            <form method="POST" class="grid">
                <input type="hidden" name="action" value="personel_kaydet"><input type="hidden" name="id" value="<?php echo (int)($editingPersonel['id'] ?? 0); ?>">
                <div class="field span-2"><label>Kullanıcı</label><select name="user_id" required><option value="">Seçiniz</option><?php foreach($usersForTask as $u): ?><option value="<?php echo (int)$u['id']; ?>" <?php echo (int)($editingPersonel['user_id'] ?? 0)===(int)$u['id']?'selected':''; ?>><?php echo task_e($u['name']); ?> - <?php echo task_e($u['email']); ?></option><?php endforeach; ?></select></div>
                <div class="field span-2"><label>Unvan</label><input name="unvan" value="<?php echo task_e($editingPersonel['unvan'] ?? ''); ?>"></div>
                <label class="checkbox-line"><input type="checkbox" name="aktif" value="1" <?php echo !isset($editingPersonel['aktif']) || (int)$editingPersonel['aktif']===1?'checked':''; ?>> Aktif</label>
                <div class="span-4 actions"><button class="btn btn-green"><?php echo $editingPersonel ? 'Güncelle' : 'Kaydet'; ?></button><?php if($editingPersonel): ?><a class="btn btn-gray" href="?tab=personel">Vazgeç</a><?php endif; ?></div>
            </form>
    </div>
    <?php endif; ?>
    <div class="panel">
            <h3>Personel Listesi</h3>
            <div class="table-wrap"><table><thead><tr><th>Ad Soyad</th><th>Kullanıcı</th><th>E-posta</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>
            <?php foreach($personeller as $p): ?><tr><td><strong><?php echo task_e($p['ad_soyad']); ?></strong><br><small><?php echo task_e($p['unvan']); ?></small></td><td><?php echo task_e($p['user_name'] ?? '-'); ?></td><td><?php echo task_e($p['email']); ?></td><td><span class="badge <?php echo (int)$p['aktif']===1?'done':'wait'; ?>"><?php echo (int)$p['aktif']===1?'Aktif':'Pasif'; ?></span></td><td><div class="actions"><?php if($canManageTasks): ?><a class="btn" href="?tab=personel&personel_edit=<?php echo (int)$p['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Silinsin mi?');"><input type="hidden" name="action" value="personel_sil"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn btn-red">Sil</button></form><?php else: ?><span class="muted">Sadece görüntüleme</span><?php endif; ?></div></td></tr><?php endforeach; ?>
            <?php if(!$personeller): ?><tr><td colspan="5">Henüz toplantı tutanağı yok.</td></tr><?php endif; ?></tbody></table></div>
    </div>
    <?php endif; ?>

    <?php if($tab==='rapor'): ?>
    <?php
    $raporSql = "
        SELECT p.ad_soyad,
            MIN(g.baslangic_tarihi) baslangic,
            MAX(g.bitis_tarihi) bitis,
            COUNT(g.id) toplam,
            COALESCE(SUM(g.durum='baslamadi'),0) baslamadi,
            COALESCE(SUM(g.durum='devam'),0) devam,
            COALESCE(SUM(g.durum='tamamlandi'),0) tamamlandi,
            COALESCE(SUM(g.durum<>'tamamlandi' AND g.bitis_tarihi IS NOT NULL AND g.bitis_tarihi < CURDATE()),0) geciken
        FROM gorev_personelleri p
        LEFT JOIN gorevler g ON g.personel_id=p.id
    ";
    $raporParams = [];
    if(!$canManageTasks){
        $raporSql .= " WHERE p.user_id=?";
        $raporParams[] = $currentUserId;
    }
    $raporSql .= " GROUP BY p.id,p.ad_soyad ORDER BY p.ad_soyad";
    $raporStmt = $db->prepare($raporSql);
    $raporStmt->execute($raporParams);
    $raporlar = $raporStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="report-layout">
        <div class="panel"><h3>Personel Bazlı Görev Özeti</h3><div class="table-wrap"><table><thead><tr><th>Personel</th><th>Başlangıç</th><th>Bitiş</th><th>Toplam</th><th>Hiç Başlamadı</th><th>Devam</th><th>Tamamlanan</th><th>Geciken</th></tr></thead><tbody>
        <?php foreach($raporlar as $r): ?><tr><td><strong><?php echo task_e($r['ad_soyad']); ?></strong></td><td><?php echo task_date($r['baslangic']); ?></td><td><?php echo task_date($r['bitis']); ?></td><td><?php echo (int)$r['toplam']; ?></td><td><?php echo (int)$r['baslamadi']; ?></td><td><?php echo (int)$r['devam']; ?></td><td><?php echo (int)$r['tamamlandi']; ?></td><td><?php echo (int)$r['geciken']; ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <aside class="panel timeline">
            <h3>Zaman Çizelgesi</h3>
            <div class="timeline-list">
                <?php foreach(array_slice($gorevler,0,18) as $g): $cls=task_status_class($g); ?>
                    <div class="time-item <?php echo $cls; ?>"><b><?php echo task_e($g['ad_soyad']); ?></b><span><?php echo task_e($g['baslik']); ?></span><span><?php echo task_date($g['baslangic_tarihi']); ?> - <?php echo task_date($g['bitis_tarihi']); ?></span><div class="progress"><span class="progress-fill <?php echo $g['durum']==='tamamlandi'?'done':''; ?>" style="width:<?php echo task_progress($g); ?>%"></span></div></div>
                <?php endforeach; ?>
                <?php if(!$gorevler): ?><div class="empty">Çizelgede gösterilecek görev yok.</div><?php endif; ?>
            </div>
        </aside>
    </div>
    <?php endif; ?>
</div>
<script>
(function(){
    var notes = document.getElementById('meetingNotes');
    var list = document.getElementById('minuteAssignList');
    var source = document.getElementById('personelOptionsSource');
    if(!notes || !list || !source){ return; }
    function renderAssignments(){
        var rows = notes.value.split(/\r?\n/).map(function(v){ return v.trim(); }).filter(Boolean);
        var oldSelects = list.querySelectorAll('select');
        var oldDates = list.querySelectorAll('input[type="date"]');
        var selected = Array.prototype.map.call(oldSelects, function(s){ return s.value; });
        var dates = Array.prototype.map.call(oldDates, function(i){ return i.value; });
        list.innerHTML = '';
        if(!rows.length){
            list.innerHTML = '<div class="empty">Tutanak satırı yazınca burada görev atama alanları görünür.</div>';
            return;
        }
        rows.forEach(function(text, i){
            var row = document.createElement('div');
            row.className = 'assign-row';
            row.innerHTML = '<b></b><select name="madde_personel[]">' + source.innerHTML + '</select><input type="date" name="madde_bitis[]">';
            row.querySelector('b').textContent = text;
            row.querySelector('select').value = selected[i] || '';
            row.querySelector('input').value = dates[i] || '';
            list.appendChild(row);
        });
    }
    notes.addEventListener('input', renderAssignments);
    renderAssignments();
})();
</script>
</body>
</html>
