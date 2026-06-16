<?php

session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$kullanici = trim((string)($_GET['kullanici'] ?? ''));
$islem = trim((string)($_GET['islem'] ?? ''));
$tarih = trim((string)($_GET['tarih'] ?? ''));

$where = [];
$params = [];

if($kullanici !== ''){
    $where[] = "user_name LIKE ?";
    $params[] = '%' . $kullanici . '%';
}

if($islem !== ''){
    $where[] = "islem = ?";
    $params[] = $islem;
}

if($tarih !== ''){
    $where[] = "DATE(created_at) = ?";
    $params[] = $tarih;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$query = $db->prepare("
    SELECT *
    FROM kullanici_loglari
    {$whereSql}
    ORDER BY created_at DESC
    LIMIT 300
");
$query->execute($params);
$logs = $query->fetchAll(PDO::FETCH_ASSOC);

$islemler = $db->query("
    SELECT DISTINCT islem
    FROM kullanici_loglari
    ORDER BY islem ASC
")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Log Kayıtları</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 8px 22px rgba(15,23,42,.04);}
.filters{display:grid;grid-template-columns:1.2fr 1fr 1fr auto auto;gap:10px;align-items:end;margin-bottom:14px;}
label{display:block;margin-bottom:6px;font-size:12px;font-weight:800;color:#334155;}
input,select{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:13px;box-sizing:border-box;}
.btn{border:0;border-radius:8px;padding:10px 12px;background:#2563eb;color:#fff;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}
.btn-gray{background:#64748b;}
.table-wrap{overflow:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px;}
th{background:#0f172a;color:#fff;text-align:left;padding:9px 8px;white-space:nowrap;}
td{border-bottom:1px solid #edf2f7;padding:8px;vertical-align:top;color:#111827;}
.muted{color:#64748b;font-size:12px;}
.empty{padding:18px;border-radius:10px;background:#f8fafc;color:#64748b;font-size:13px;}
.badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#e0f2fe;color:#075985;font-weight:800;font-size:11px;}
@media(max-width:1000px){.filters{grid-template-columns:1fr 1fr}.filters .btn{width:100%;}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Log Kayıtları</h2>
        <p>Kullanıcı giriş, çıkış ve işlem kayıtlarını buradan takip edin.</p>
    </div>

    <div class="panel">
        <form method="GET" class="filters">
            <div>
                <label>Kullanıcı</label>
                <input type="text" name="kullanici" value="<?php echo htmlspecialchars($kullanici); ?>" placeholder="Kullanıcı adı ara">
            </div>
            <div>
                <label>İşlem</label>
                <select name="islem">
                    <option value="">Tüm işlemler</option>
                    <?php foreach($islemler as $item): ?>
                        <option value="<?php echo htmlspecialchars((string)$item); ?>" <?php echo $islem === (string)$item ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)$item); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tarih</label>
                <input type="date" name="tarih" value="<?php echo htmlspecialchars($tarih); ?>">
            </div>
            <button type="submit" class="btn">Filtrele</button>
            <a href="log-kayitlari.php" class="btn btn-gray">Temizle</a>
        </form>

        <?php if(empty($logs)): ?>
            <div class="empty">Gösterilecek kullanıcı işlem kaydı yok.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:145px;">Tarih</th>
                            <th style="width:170px;">Kullanıcı</th>
                            <th style="width:130px;">İşlem</th>
                            <th style="width:170px;">Sayfa</th>
                            <th>Detay</th>
                            <th style="width:120px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php echo date('d.m.Y H:i:s', strtotime((string)$log['created_at'])); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)($log['user_name'] ?: 'Bilinmiyor')); ?></strong>
                                    <div class="muted">ID: <?php echo htmlspecialchars((string)($log['user_id'] ?? '-')); ?></div>
                                </td>
                                <td><span class="badge"><?php echo htmlspecialchars((string)$log['islem']); ?></span></td>
                                <td><?php echo htmlspecialchars((string)$log['sayfa']); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['detay'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($log['ip_adresi'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted">Son 300 kayıt listelenir.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
