<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/config/database.php';

function view_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function view_date($value): string
{
    return $value ? date('d.m.Y', strtotime((string)$value)) : '-';
}

function view_status_label(string $status): string
{
    return [
        'baslamadi' => 'Hiç Başlamadı',
        'devam' => 'Devam Ediyor',
        'tamamlandi' => 'Tamamlandı',
    ][$status] ?? 'Hiç Başlamadı';
}

$token = trim((string)($_GET['token'] ?? ''));
if($token === ''){
    http_response_code(404);
    die('Görev bağlantısı bulunamadı.');
}

$query = $db->prepare("
    SELECT g.*, p.ad_soyad, p.email, p.unvan
    FROM gorevler g
    INNER JOIN gorev_personelleri p ON p.id=g.personel_id
    WHERE g.link_token=?
    LIMIT 1
");
$query->execute([$token]);
$gorev = $query->fetch(PDO::FETCH_ASSOC);

if(!$gorev){
    http_response_code(404);
    die('Görev bulunamadı.');
}

$message = '';
if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
    $durum = (string)($_POST['durum'] ?? $gorev['durum']);
    if(in_array($durum, ['baslamadi','devam','tamamlandi'], true)){
        $tamamlanma = $durum === 'tamamlandi' ? date('Y-m-d') : null;
        $update = $db->prepare("UPDATE gorevler SET durum=?, tamamlanma_tarihi=? WHERE id=?");
        $update->execute([$durum, $tamamlanma, (int)$gorev['id']]);
        $gorev['durum'] = $durum;
        $gorev['tamamlanma_tarihi'] = $tamamlanma;
        $message = 'Görev durumu güncellendi.';
    }
}

$late = $gorev['durum'] !== 'tamamlandi' && !empty($gorev['bitis_tarihi']) && strtotime((string)$gorev['bitis_tarihi']) < strtotime(date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Görev Detayı</title>
<style>
body{margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#111827}.wrap{max-width:820px;margin:36px auto;padding:0 18px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:26px;box-shadow:0 14px 34px rgba(15,23,42,.07)}h1{margin:0 0 8px;font-size:30px}.muted{color:#64748b}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:22px 0}.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:13px}.box span{display:block;font-size:12px;color:#64748b;font-weight:800}.box strong{display:block;margin-top:5px;font-size:16px}.desc{white-space:pre-wrap;line-height:1.55;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:16px}.badge{display:inline-flex;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800;background:#dbeafe;color:#1d4ed8}.badge.late{background:#fee2e2;color:#991b1b}.badge.done{background:#dcfce7;color:#166534}.notice{margin-bottom:14px;padding:12px;border-radius:9px;background:#dcfce7;color:#166534;font-weight:800}.actions{margin-top:20px;display:flex;gap:8px;flex-wrap:wrap}.btn{border:0;border-radius:8px;padding:11px 14px;background:#2563eb;color:#fff;font-weight:800;cursor:pointer}.btn-green{background:#16a34a}.btn-gray{background:#64748b}@media(max-width:700px){.meta{grid-template-columns:1fr}h1{font-size:24px}}
</style>
</head>
<body>
<div class="wrap">
    <?php if($message): ?><div class="notice"><?php echo view_e($message); ?></div><?php endif; ?>
    <div class="card">
        <span class="badge <?php echo $late ? 'late' : ($gorev['durum']==='tamamlandi'?'done':''); ?>"><?php echo $late ? 'Gecikti' : view_status_label($gorev['durum']); ?></span>
        <h1><?php echo view_e($gorev['baslik']); ?></h1>
        <p class="muted"><?php echo view_e($gorev['ad_soyad']); ?><?php echo $gorev['unvan'] ? ' - ' . view_e($gorev['unvan']) : ''; ?></p>
        <div class="meta">
            <div class="box"><span>Başlangıç</span><strong><?php echo view_date($gorev['baslangic_tarihi']); ?></strong></div>
            <div class="box"><span>Bitiş</span><strong><?php echo view_date($gorev['bitis_tarihi']); ?></strong></div>
            <div class="box"><span>Tamamlanma</span><strong><?php echo view_date($gorev['tamamlanma_tarihi']); ?></strong></div>
        </div>
        <div class="desc"><?php echo view_e($gorev['aciklama'] ?: 'Açıklama girilmemiş.'); ?></div>
        <form method="POST" class="actions">
            <button class="btn btn-gray" name="durum" value="baslamadi">Hiç Başlamadı</button>
            <button class="btn" name="durum" value="devam">Devam Ediyor</button>
            <button class="btn btn-green" name="durum" value="tamamlandi">Tamamlandı</button>
        </form>
    </div>
</div>
</body>
</html>
