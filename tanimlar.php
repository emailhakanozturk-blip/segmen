<?php

session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';
$error = '';

$db->exec("
    CREATE TABLE IF NOT EXISTS tanimlar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kategori VARCHAR(60) NOT NULL,
        tanim_adi VARCHAR(180) NOT NULL,
        aciklama TEXT NULL,
        durum TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tanim (kategori, tanim_adi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$varsayilanlar = [
    ['Su Markası', 'Seğmen Su', 'Ana su markası'],
    ['Ürün', '19 L Damacana', 'Damacana ürün tanımı'],
    ['Ürün', "1,5 L (6'lı Paket)", 'Paketli ürün tanımı'],
    ['Ürün', "1,5 L (12'li Paket)", 'Paketli ürün tanımı'],
    ['Ürün', "0,5 L (12'li Paket)", 'Paketli ürün tanımı'],
    ['Ürün', "0,5 L (24'lü Paket)", 'Paketli ürün tanımı'],
    ['Malzeme', 'Palet', 'Palet çıkış ve takip tanımı'],
];

$seed = $db->prepare("INSERT IGNORE INTO tanimlar (kategori, tanim_adi, aciklama, durum) VALUES (?, ?, ?, 1)");
foreach($varsayilanlar as $row){
    $seed->execute($row);
}

function form_text(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if($action === 'ekle' || $action === 'guncelle'){
            $kategori = form_text('kategori');
            $tanimAdi = form_text('tanim_adi');
            $aciklama = form_text('aciklama');

            if($kategori === '' || $tanimAdi === ''){
                throw new Exception('Kategori ve tanım adı zorunludur.');
            }

            if($action === 'guncelle'){
                if($id <= 0){
                    throw new Exception('Tanım seçilemedi.');
                }

                $query = $db->prepare("UPDATE tanimlar SET kategori = ?, tanim_adi = ?, aciklama = ? WHERE id = ?");
                $query->execute([$kategori, $tanimAdi, $aciklama, $id]);
                $message = 'Tanım güncellendi.';
            }else{
                $query = $db->prepare("INSERT INTO tanimlar (kategori, tanim_adi, aciklama, durum) VALUES (?, ?, ?, 1)");
                $query->execute([$kategori, $tanimAdi, $aciklama]);
                $message = 'Tanım eklendi.';
            }
        }

        if($action === 'durum'){
            $durum = (int)($_POST['durum'] ?? 0);
            if($id <= 0){
                throw new Exception('Tanım seçilemedi.');
            }

            $query = $db->prepare("UPDATE tanimlar SET durum = ? WHERE id = ?");
            $query->execute([$durum === 1 ? 1 : 0, $id]);
            $message = 'Tanım durumu güncellendi.';
        }

        if($action === 'sil'){
            if($id <= 0){
                throw new Exception('Tanım seçilemedi.');
            }

            $query = $db->prepare("DELETE FROM tanimlar WHERE id = ?");
            $query->execute([$id]);
            $message = 'Tanım silindi.';
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
if($editId > 0){
    $query = $db->prepare("SELECT * FROM tanimlar WHERE id = ?");
    $query->execute([$editId]);
    $editing = $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

$kategoriFiltre = trim((string)($_GET['kategori'] ?? ''));
$kategoriler = $db->query("SELECT DISTINCT kategori FROM tanimlar ORDER BY kategori ASC")->fetchAll(PDO::FETCH_COLUMN);

if($kategoriFiltre !== ''){
    $query = $db->prepare("SELECT * FROM tanimlar WHERE kategori = ? ORDER BY kategori ASC, tanim_adi ASC");
    $query->execute([$kategoriFiltre]);
}else{
    $query = $db->query("SELECT * FROM tanimlar ORDER BY kategori ASC, tanim_adi ASC");
}
$tanimlar = $query->fetchAll(PDO::FETCH_ASSOC);

$kategoriDegeri = (string)($editing['kategori'] ?? '');

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Tanımlar</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.page-grid{display:grid;grid-template-columns:1fr;gap:18px;}
.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.04);}
.panel h3{margin:0 0 14px;font-size:18px;color:#0f172a;}
.form-group{margin-bottom:12px;}
label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#1f2937;}
input,select,textarea{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px 11px;font-size:13px;font-family:Arial, Helvetica, sans-serif;box-sizing:border-box;}
textarea{min-height:82px;resize:vertical;}
.btn{border:0;border-radius:8px;padding:9px 12px;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;color:#fff;background:#2563eb;}
.btn-green{background:#16a34a;}.btn-red{background:#dc2626;}.btn-gray{background:#64748b;}
.notice{margin-bottom:16px;padding:12px 14px;border-radius:10px;font-size:13px;font-weight:700;}
.notice-ok{background:#dcfce7;color:#166534;}.notice-error{background:#fee2e2;color:#991b1b;}
.filter-row{display:flex;gap:10px;align-items:flex-end;margin-bottom:14px;}
.filter-row .form-group{flex:1;margin-bottom:0;}
table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;}
th{background:#0f172a;color:#fff;padding:10px 8px;font-size:11px;text-align:left;text-transform:uppercase;}
td{padding:10px 8px;border-bottom:1px solid #eef2f7;font-size:12px;color:#111827;vertical-align:middle;overflow-wrap:anywhere;}
.badge{padding:5px 8px;border-radius:999px;color:#fff;font-size:11px;font-weight:800;display:inline-flex;}
.active{background:#16a34a;}.passive{background:#64748b;}
.actions{display:flex;flex-wrap:wrap;gap:6px;}.inline-form{display:inline;}
@media(max-width:1000px){table{min-width:760px;}.panel{overflow:auto;}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Tanımlar</h2>
        <p>Su markası, ürün, palet ve diğer malzeme tanımlarını yönetin.</p>
    </div>

    <?php if($message): ?><div class="notice notice-ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="notice notice-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="page-grid">
        <div class="panel">
            <h3><?php echo $editing ? 'Tanım Düzelt' : 'Yeni Tanım'; ?></h3>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editing ? 'guncelle' : 'ekle'; ?>">
                <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">

                <div class="form-group">
                    <label>Kategori</label>
                    <select name="kategori" required>
                        <option value="">Seçiniz</option>
                        <?php foreach(['Su Markası', 'Ürün', 'Malzeme', 'Palet', 'Diğer'] as $kategori): ?>
                            <option value="<?php echo htmlspecialchars($kategori); ?>" <?php echo $kategoriDegeri === $kategori ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($kategori); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tanım adı</label>
                    <input type="text" name="tanim_adi" value="<?php echo htmlspecialchars((string)($editing['tanim_adi'] ?? '')); ?>" placeholder="Örn: 19 L Damacana" required>
                </div>

                <div class="form-group">
                    <label>Açıklama</label>
                    <textarea name="aciklama" placeholder="İsteğe bağlı açıklama"><?php echo htmlspecialchars((string)($editing['aciklama'] ?? '')); ?></textarea>
                </div>

                <button type="submit" class="btn btn-green"><?php echo $editing ? 'Güncelle' : 'Kaydet'; ?></button>
                <?php if($editing): ?><a href="tanimlar.php" class="btn btn-gray">Vazgeç</a><?php endif; ?>
            </form>
        </div>

        <div class="panel">
            <h3>Tanım Listesi</h3>
            <form method="GET" class="filter-row">
                <div class="form-group">
                    <label>Kategori filtrele</label>
                    <select name="kategori">
                        <option value="">Tüm kategoriler</option>
                        <?php foreach($kategoriler as $kategori): ?>
                            <option value="<?php echo htmlspecialchars((string)$kategori); ?>" <?php echo $kategoriFiltre === $kategori ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$kategori); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn">Filtrele</button>
                <a href="tanimlar.php" class="btn btn-gray">Temizle</a>
            </form>

            <table>
                <thead>
                    <tr>
                        <th style="width:150px;">Kategori</th>
                        <th>Tanım</th>
                        <th>Açıklama</th>
                        <th style="width:100px;">Durum</th>
                        <th style="width:250px;">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tanimlar as $tanim): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$tanim['kategori']); ?></td>
                            <td><strong><?php echo htmlspecialchars((string)$tanim['tanim_adi']); ?></strong></td>
                            <td><?php echo htmlspecialchars((string)($tanim['aciklama'] ?? '')); ?></td>
                            <td><span class="badge <?php echo (int)$tanim['durum'] === 1 ? 'active' : 'passive'; ?>"><?php echo (int)$tanim['durum'] === 1 ? 'Aktif' : 'Pasif'; ?></span></td>
                            <td>
                                <div class="actions">
                                    <a href="tanimlar.php?edit=<?php echo (int)$tanim['id']; ?>" class="btn">Düzelt</a>

                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="durum">
                                        <input type="hidden" name="id" value="<?php echo (int)$tanim['id']; ?>">
                                        <input type="hidden" name="durum" value="<?php echo (int)$tanim['durum'] === 1 ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-gray"><?php echo (int)$tanim['durum'] === 1 ? 'Pasifleştir' : 'Aktifleştir'; ?></button>
                                    </form>

                                    <form method="POST" class="inline-form" onsubmit="return confirm('Bu tanım silinsin mi?');">
                                        <input type="hidden" name="action" value="sil">
                                        <input type="hidden" name="id" value="<?php echo (int)$tanim['id']; ?>">
                                        <button type="submit" class="btn btn-red">Sil</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(empty($tanimlar)): ?><tr><td colspan="5">Tanım bulunamadı.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>

