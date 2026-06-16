<?php
session_start();
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

$message = '';
$error = '';
$tab = (string)($_GET['tab'] ?? 'toplanti');
if(!in_array($tab, ['toplanti','gorev','personel','rapor'], true)){
    $tab = 'gorev';
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
                $message = 'Toplantı tutanağı güncellendi.';
            }else{
                $q = $db->prepare("INSERT INTO gorev_toplantilari (toplanti_tarihi,baslik,tutanak,created_by) VALUES (?,?,?,?)");
                $q->execute([$tarih, $baslik, $tutanak, (int)$_SESSION['user_id']]);
                $message = 'Toplantı tutanağı oluşturuldu. Şimdi bu toplantıdan görev atayabilirsiniz.';
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
            $ad = trim((string)($_POST['ad_soyad'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $unvan = trim((string)($_POST['unvan'] ?? ''));
            $aktif = isset($_POST['aktif']) ? 1 : 0;
            if($ad === ''){
                throw new Exception('Personel adı zorunludur.');
            }
            if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)){
                throw new Exception('Geçerli bir e-posta adresi girin.');
            }
            if($id > 0){
                $q = $db->prepare("UPDATE gorev_personelleri SET ad_soyad=?, email=?, unvan=?, aktif=? WHERE id=?");
                $q->execute([$ad, $email, $unvan, $aktif, $id]);
                $message = 'Personel güncellendi.';
            }else{
                $q = $db->prepare("INSERT INTO gorev_personelleri (ad_soyad,email,unvan,aktif) VALUES (?,?,?,?)");
                $q->execute([$ad, $email, $unvan, $aktif]);
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
            $body = "Merhaba {$task['ad_soyad']},\n\nSize bir görev atandı.\n\nGörev: {$task['baslik']}\nBitiş: " . task_date($task['bitis_tarihi']) . "\n\nGörevi görüntülemek için:\n{$link}";
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

$personeller = $db->query("SELECT * FROM gorev_personelleri ORDER BY aktif DESC, ad_soyad ASC")->fetchAll(PDO::FETCH_ASSOC);
$aktifPersoneller = array_values(array_filter($personeller, fn($p) => (int)$p['aktif'] === 1));
$toplantilar = $db->query("SELECT t.*, COUNT(g.id) AS gorev_adet FROM gorev_toplantilari t LEFT JOIN gorevler g ON g.toplanti_id=t.id GROUP BY t.id ORDER BY t.toplanti_tarihi DESC, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$personelFiltre = (int)($_GET['personel'] ?? 0);
$durumFiltre = trim((string)($_GET['durum'] ?? ''));
$where = [];
$params = [];
if($personelFiltre > 0){
    $where[] = 'g.personel_id=?';
    $params[] = $personelFiltre;
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

$summary = $db->query("
    SELECT
        COUNT(*) toplam,
        SUM(durum='baslamadi') baslamadi,
        SUM(durum='devam') devam,
        SUM(durum='tamamlandi') tamamlandi,
        SUM(durum<>'tamamlandi' AND bitis_tarihi IS NOT NULL AND bitis_tarihi < CURDATE()) geciken
    FROM gorevler
")->fetch(PDO::FETCH_ASSOC) ?: [];

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
.progress{height:7px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:7px}.progress-fill{display:block;height:100%;background:#2563eb;border-radius:999px}.progress-fill.done{background:#16a34a}.minute-list{display:grid;gap:8px}.minute-row{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;background:#f8fafc;border:1px solid #e5e7eb;border-radius:9px;padding:10px}.minute-row span{font-size:13px;line-height:1.4}.report-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px}@media(max-width:1150px){.report-layout{grid-template-columns:1fr}}
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
    <div class="layout">
        <div class="panel">
            <h3><?php echo $editingToplanti ? 'Toplantı Tutanağı Düzenle' : 'Toplantı Tutanağı Oluştur'; ?></h3>
            <form method="POST" class="grid">
                <input type="hidden" name="action" value="toplanti_kaydet">
                <input type="hidden" name="id" value="<?php echo (int)($editingToplanti['id'] ?? 0); ?>">
                <div class="field"><label>Toplantı Günü</label><input type="date" name="toplanti_tarihi" required value="<?php echo task_e($editingToplanti['toplanti_tarihi'] ?? date('Y-m-d')); ?>"></div>
                <div class="field span-2"><label>Toplantı Başlığı</label><input name="baslik" required value="<?php echo task_e($editingToplanti['baslik'] ?? ''); ?>" placeholder="Haftalık operasyon toplantısı"></div>
                <div class="field span-4"><label>Toplantı Tutanağı</label><textarea name="tutanak" placeholder="Her maddeyi ayrı satıra yazın."><?php echo task_e($editingToplanti['tutanak'] ?? ''); ?></textarea></div>
                <div class="span-4 actions"><button class="btn btn-green"><?php echo $editingToplanti ? 'Güncelle' : 'Tutanak Oluştur'; ?></button><?php if($editingToplanti): ?><a class="btn btn-gray" href="?tab=toplanti">Vazgeç</a><?php endif; ?></div>
            </form>
        </div>
        <aside class="panel timeline">
            <h3>Toplantıdan Görev Atama</h3>
            <p class="empty">Bir tutanak oluşturduktan sonra listeden “Görev Ata” seçin. Görev formunda toplantı ve gün otomatik gelir.</p>
        </aside>
    </div>
    <div class="panel">
        <h3>Toplantı Tutanakları</h3>
        <div class="table-wrap"><table><thead><tr><th>Gün</th><th>Toplantı</th><th>Tutanak</th><th>Görev</th><th>İşlem</th></tr></thead><tbody>
        <?php if(!$toplantilar): ?><tr><td colspan="5">Henüz toplantı tutanağı yok.</td></tr><?php endif; ?>
        <?php foreach($toplantilar as $t): ?><tr>
            <td><?php echo task_date($t['toplanti_tarihi']); ?></td>
            <td><strong><?php echo task_e($t['baslik']); ?></strong></td>
            <td><?php echo nl2br(task_e(task_short((string)$t['tutanak']))); ?></td>
            <td><span class="badge work"><?php echo (int)$t['gorev_adet']; ?> görev</span></td>
            <td><div class="actions"><a class="btn btn-green" href="?tab=gorev&toplanti_id=<?php echo (int)$t['id']; ?>">Görev Ata</a><a class="btn" href="?tab=toplanti&toplanti_edit=<?php echo (int)$t['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Toplantı tutanağı silinsin mi? Görevler silinmez, toplantı bağlantısı kaldırılır.');"><input type="hidden" name="action" value="toplanti_sil"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><button class="btn btn-red">Sil</button></form></div></td>
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

    <div class="panel">
        <h3><?php echo $editingTask ? 'Görev Düzenle' : 'Yeni Görev'; ?></h3>
        <form method="POST" class="grid">
            <input type="hidden" name="action" value="gorev_kaydet">
            <input type="hidden" name="id" value="<?php echo (int)($editingTask['id'] ?? 0); ?>">
            <div class="field span-2"><label>Toplantı Tutanağı</label><select name="toplanti_id"><option value="">Bağımsız görev</option><?php foreach($toplantilar as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo $selectedToplantiId===(int)$t['id']?'selected':''; ?>><?php echo task_date($t['toplanti_tarihi']); ?> - <?php echo task_e($t['baslik']); ?></option><?php endforeach; ?></select></div>
            <div class="field span-2"><label>Personel</label><select name="personel_id" required><option value="">Seçiniz</option><?php foreach($aktifPersoneller as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo (int)($editingTask['personel_id'] ?? 0)===(int)$p['id']?'selected':''; ?>><?php echo task_e($p['ad_soyad']); ?><?php echo $p['unvan'] ? ' - ' . task_e($p['unvan']) : ''; ?></option><?php endforeach; ?></select></div>
            <div class="field span-2"><label>Görev Başlığı</label><input name="baslik" required value="<?php echo task_e($defaultTitle); ?>"></div>
            <div class="field"><label>Görev Günü</label><input type="date" name="baslangic_tarihi" value="<?php echo task_e($defaultStartDate); ?>"></div>
            <div class="field"><label>Bitiş / Süre</label><input type="date" name="bitis_tarihi" value="<?php echo task_e($editingTask['bitis_tarihi'] ?? $defaultStartDate); ?>"></div>
            <div class="field"><label>Durum</label><select name="durum"><?php foreach(['baslamadi','devam','tamamlandi'] as $d): ?><option value="<?php echo $d; ?>" <?php echo ($editingTask['durum'] ?? 'baslamadi')===$d?'selected':''; ?>><?php echo task_status_label($d); ?></option><?php endforeach; ?></select></div>
            <div class="field span-4"><label>Açıklama</label><textarea name="aciklama"><?php echo task_e($defaultDescription); ?></textarea></div>
            <div class="span-4 actions"><button class="btn btn-green"><?php echo $editingTask ? 'Güncelle' : 'Görev Oluştur'; ?></button><?php if($editingTask): ?><a class="btn btn-gray" href="?tab=gorev">Vazgeç</a><?php endif; ?></div>
        </form>
    </div>

    <div class="layout">
        <div class="panel">
            <form method="GET" class="filters">
                <input type="hidden" name="tab" value="gorev">
                <div class="field"><label>Personel</label><select name="personel"><option value="">Tümü</option><?php foreach($personeller as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $personelFiltre===(int)$p['id']?'selected':''; ?>><?php echo task_e($p['ad_soyad']); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Durum</label><select name="durum"><option value="">Tümü</option><?php foreach(['baslamadi','devam','tamamlandi'] as $d): ?><option value="<?php echo $d; ?>" <?php echo $durumFiltre===$d?'selected':''; ?>><?php echo task_status_label($d); ?></option><?php endforeach; ?></select></div>
                <button class="btn">Filtrele</button><a class="btn btn-gray" href="?tab=gorev">Temizle</a>
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
                <td><a class="btn btn-dark" target="_blank" href="<?php echo task_e($link); ?>">HTML Link</a><form method="POST" style="display:inline"><input type="hidden" name="action" value="email_gonder"><input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>"><button class="btn btn-green">E-posta</button></form></td>
                <td><div class="actions"><a class="btn" href="?tab=gorev&edit=<?php echo (int)$g['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Görev silinsin mi?');"><input type="hidden" name="action" value="gorev_sil"><input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>"><button class="btn btn-red">Sil</button></form></div></td>
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
    <div class="panel">
            <h3><?php echo $editingPersonel ? 'Personel Düzenle' : 'Personel Ekle'; ?></h3>
            <form method="POST" class="grid">
                <input type="hidden" name="action" value="personel_kaydet"><input type="hidden" name="id" value="<?php echo (int)($editingPersonel['id'] ?? 0); ?>">
                <div class="field span-2"><label>Ad Soyad</label><input name="ad_soyad" required value="<?php echo task_e($editingPersonel['ad_soyad'] ?? ''); ?>"></div>
                <div class="field span-2"><label>E-posta</label><input type="email" name="email" value="<?php echo task_e($editingPersonel['email'] ?? ''); ?>"></div>
                <div class="field span-2"><label>Unvan</label><input name="unvan" value="<?php echo task_e($editingPersonel['unvan'] ?? ''); ?>"></div>
                <label class="checkbox-line"><input type="checkbox" name="aktif" value="1" <?php echo !isset($editingPersonel['aktif']) || (int)$editingPersonel['aktif']===1?'checked':''; ?>> Aktif</label>
                <div class="span-4 actions"><button class="btn btn-green"><?php echo $editingPersonel ? 'Güncelle' : 'Kaydet'; ?></button><?php if($editingPersonel): ?><a class="btn btn-gray" href="?tab=personel">Vazgeç</a><?php endif; ?></div>
            </form>
    </div>
    <div class="panel">
            <h3>Personel Listesi</h3>
            <div class="table-wrap"><table><thead><tr><th>Ad Soyad</th><th>E-posta</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>
            <?php foreach($personeller as $p): ?><tr><td><strong><?php echo task_e($p['ad_soyad']); ?></strong><br><small><?php echo task_e($p['unvan']); ?></small></td><td><?php echo task_e($p['email']); ?></td><td><span class="badge <?php echo (int)$p['aktif']===1?'done':'wait'; ?>"><?php echo (int)$p['aktif']===1?'Aktif':'Pasif'; ?></span></td><td><div class="actions"><a class="btn" href="?tab=personel&personel_edit=<?php echo (int)$p['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Personel silinsin mi?');"><input type="hidden" name="action" value="personel_sil"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn btn-red">Sil</button></form></div></td></tr><?php endforeach; ?>
            <?php if(!$personeller): ?><tr><td colspan="4">Henüz personel yok.</td></tr><?php endif; ?></tbody></table></div>
    </div>
    <?php endif; ?>

    <?php if($tab==='rapor'): ?>
    <?php
    $raporlar = $db->query("
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
        GROUP BY p.id,p.ad_soyad
        ORDER BY p.ad_soyad
    ")->fetchAll(PDO::FETCH_ASSOC);
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
</body>
</html>
