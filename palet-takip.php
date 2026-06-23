<?php
session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header('Location: login.php');
    exit;
}

if(file_exists(__DIR__ . '/vendor/autoload.php')){
    require_once __DIR__ . '/vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

function palet_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function palet_num($value): float
{
    $value = trim((string)$value);
    $value = str_replace([' ', 'TL', '₺'], '', $value);
    if(str_contains($value, ',')){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

function palet_date($value): ?string
{
    if($value === null || $value === ''){
        return null;
    }
    if(is_numeric($value)){
        return Date::excelToDateTimeObject($value)->format('Y-m-d');
    }
    if($value instanceof DateTimeInterface){
        return $value->format('Y-m-d');
    }
    $value = trim((string)$value);
    $value = str_replace('/', '.', $value);
    $parts = explode('.', $value);
    if(count($parts) === 3){
        return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    }
    return $value;
}

$db->exec("
CREATE TABLE IF NOT EXISTS palet_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tarih DATE NOT NULL,
    sevk_eden_yer VARCHAR(180) NOT NULL,
    sevk_edilen_yer VARCHAR(180) NOT NULL,
    giden_adet DECIMAL(12,2) NOT NULL DEFAULT 0,
    gelen_adet DECIMAL(12,2) NOT NULL DEFAULT 0,
    teslim_eden VARCHAR(180) NULL,
    teslim_alan VARCHAR(180) NULL,
    aciklama TEXT NULL,
    kaydeden_user_id INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_palet_tarih (tarih),
    INDEX idx_palet_sevk_edilen (sevk_edilen_yer),
    INDEX idx_palet_sevk_eden (sevk_eden_yer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$message = '';
$error = '';
$tab = (string)($_GET['tab'] ?? 'hareket');
if(!in_array($tab, ['hareket', 'excel', 'kalan', 'liste', 'ozet'], true)){
    $tab = 'hareket';
}

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = (string)($_POST['action'] ?? '');

        if($action === 'hareket_kaydet'){
            $tarih = palet_date($_POST['tarih'] ?? '');
            $sevkEden = trim((string)($_POST['sevk_eden_yer'] ?? ''));
            $sevkEdilen = trim((string)($_POST['sevk_edilen_yer'] ?? ''));
            $giden = max(0, palet_num($_POST['giden_adet'] ?? 0));
            $gelen = max(0, palet_num($_POST['gelen_adet'] ?? 0));
            if(!$tarih || $sevkEden === '' || $sevkEdilen === ''){
                throw new Exception('Tarih, sevk eden yer ve sevk edilen yer zorunludur.');
            }
            if($giden <= 0 && $gelen <= 0){
                throw new Exception('Giden veya gelen palet adedi giriniz.');
            }
            $query = $db->prepare("INSERT INTO palet_hareketleri (tarih,sevk_eden_yer,sevk_edilen_yer,giden_adet,gelen_adet,teslim_eden,teslim_alan,aciklama,kaydeden_user_id) VALUES (?,?,?,?,?,?,?,?,?)");
            $query->execute([
                $tarih,
                $sevkEden,
                $sevkEdilen,
                $giden,
                $gelen,
                trim((string)($_POST['teslim_eden'] ?? '')),
                trim((string)($_POST['teslim_alan'] ?? '')),
                trim((string)($_POST['aciklama'] ?? '')),
                (int)$_SESSION['user_id'],
            ]);
            $message = 'Palet hareketi kaydedildi.';
            $tab = 'liste';
        }

        if($action === 'excel_aktar'){
            if(empty($_FILES['excel']['tmp_name'])){
                throw new Exception('Excel dosyası seçiniz.');
            }
            if(!class_exists(IOFactory::class)){
                throw new Exception('Excel okumak için PhpSpreadsheet bulunamadı.');
            }
            if(!class_exists('ZipArchive')){
                throw new Exception('XLSX okumak için PHP ZipArchive eklentisi aktif olmalıdır.');
            }

            $depoAdi = trim((string)($_POST['depo_adi'] ?? 'SEĞMEN SU DEPO'));
            $spreadsheet = IOFactory::load($_FILES['excel']['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            $insert = $db->prepare("INSERT INTO palet_hareketleri (tarih,sevk_eden_yer,sevk_edilen_yer,giden_adet,gelen_adet,teslim_eden,teslim_alan,aciklama,kaydeden_user_id) VALUES (?,?,?,?,?,?,?,?,?)");
            $count = 0;

            foreach($rows as $index => $row){
                if($index < 3){
                    continue;
                }
                $tarih = palet_date($row['A'] ?? null);
                $giden = max(0, palet_num($row['B'] ?? 0));
                $gelen = max(0, palet_num($row['C'] ?? 0));
                $teslimEden = trim((string)($row['E'] ?? ''));
                $teslimAlan = trim((string)($row['F'] ?? ''));
                $yer = trim((string)($row['G'] ?? ''));
                if(!$tarih || $yer === '' || ($giden <= 0 && $gelen <= 0)){
                    continue;
                }
                if($giden > 0){
                    $insert->execute([$tarih, $depoAdi, $yer, $giden, 0, $teslimEden, $teslimAlan, 'Excel aktarım - giden', (int)$_SESSION['user_id']]);
                    $count++;
                }
                if($gelen > 0){
                    $insert->execute([$tarih, $yer, $depoAdi, 0, $gelen, $teslimEden, $teslimAlan, 'Excel aktarım - geri gelen', (int)$_SESSION['user_id']]);
                    $count++;
                }
            }
            $message = $count . ' palet hareketi Excelden aktarıldı.';
            $tab = 'liste';
        }

        if($action === 'hareket_sil'){
            $db->prepare('DELETE FROM palet_hareketleri WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Palet hareketi silindi.';
            $tab = 'liste';
        }
    }
} catch(Throwable $e){
    $error = $e->getMessage();
}

$ozet = $db->query("SELECT COALESCE(SUM(giden_adet),0) giden, COALESCE(SUM(gelen_adet),0) gelen, COUNT(*) kayit FROM palet_hareketleri")->fetch();
$yerKalan = $db->query("
    SELECT yer, SUM(giden) giden, SUM(gelen) gelen, SUM(giden)-SUM(gelen) kalan
    FROM (
        SELECT sevk_edilen_yer yer, giden_adet giden, 0 gelen FROM palet_hareketleri WHERE giden_adet > 0
        UNION ALL
        SELECT sevk_eden_yer yer, 0 giden, gelen_adet gelen FROM palet_hareketleri WHERE gelen_adet > 0
    ) x
    WHERE yer <> 'SEĞMEN SU DEPO'
    GROUP BY yer
    ORDER BY kalan DESC, yer ASC
")->fetchAll();
$hareketler = $db->query("SELECT * FROM palet_hareketleri ORDER BY tarih DESC, id DESC LIMIT 300")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palet Takip</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .palet-tabs{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-bottom:18px}
        .palet-tab{padding:13px 15px;border:1px solid #dce2ea;border-radius:8px;background:#fff;color:#1f2937;text-decoration:none;font-weight:800;font-size:13px}
        .palet-tab.active{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}
        .panel{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-bottom:18px}
        .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#334155}
        .field input,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cfd6df;border-radius:7px;padding:9px 10px;font-size:13px;background:#fff}
        .field textarea{min-height:72px;resize:vertical}.span-2{grid-column:span 2}.span-4{grid-column:span 4}
        .btn{border:0;border-radius:7px;padding:9px 12px;background:#2563eb;color:#fff;text-decoration:none;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;justify-content:center}
        .btn-green{background:#16a34a}.btn-red{background:#dc2626}.btn-gray{background:#64748b}
        .notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:800}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}
        .summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.summary-item{background:#fff;border:1px solid #e5e7eb;border-radius:9px;padding:14px}.summary-item span{display:block;color:#64748b;font-size:11px;margin-bottom:5px}.summary-item strong{font-size:20px}
        .table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px}th{background:#17233b;color:#fff;text-align:left;padding:9px 8px;white-space:nowrap}td{border-bottom:1px solid #e8edf3;padding:8px;vertical-align:middle}.num{text-align:right;font-variant-numeric:tabular-nums}.actions{display:flex;gap:6px;flex-wrap:wrap}
        @media(max-width:900px){.palet-tabs,.grid,.summary{grid-template-columns:1fr 1fr}.span-2,.span-4{grid-column:span 2}}@media(max-width:620px){.palet-tabs,.grid,.summary{grid-template-columns:1fr}.span-2,.span-4{grid-column:span 1}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="main">
    <div class="topbar">
        <h2>Palet Takip</h2>
        <p>Palet sevk, iade ve kalan takibini yer bazlı izleyin.</p>
    </div>
    <?php if($message): ?><div class="notice ok"><?php echo palet_e($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="notice err"><?php echo palet_e($error); ?></div><?php endif; ?>

    <nav class="palet-tabs">
        <a class="palet-tab <?php echo $tab==='hareket'?'active':''; ?>" href="?tab=hareket">Hareket Ekle</a>
        <a class="palet-tab <?php echo $tab==='excel'?'active':''; ?>" href="?tab=excel">Excel Aktarım</a>
        <a class="palet-tab <?php echo $tab==='kalan'?'active':''; ?>" href="?tab=kalan">Yer Bazlı Kalan</a>
        <a class="palet-tab <?php echo $tab==='liste'?'active':''; ?>" href="?tab=liste">Hareket Listesi</a>
        <a class="palet-tab <?php echo $tab==='ozet'?'active':''; ?>" href="?tab=ozet">Toplam Özet</a>
    </nav>

    <?php if($tab === 'hareket'): ?>
    <div class="panel">
        <h3>Hareket Ekleme Formu</h3>
        <form method="POST" class="grid">
            <input type="hidden" name="action" value="hareket_kaydet">
            <div class="field"><label>Tarih</label><input type="date" name="tarih" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="field"><label>Sevk Eden Yer</label><input name="sevk_eden_yer" required placeholder="Seğmen Su Depo"></div>
            <div class="field"><label>Sevk Edilen Yer</label><input name="sevk_edilen_yer" required placeholder="Bayi / kullanım yeri"></div>
            <div class="field"><label>Giden Palet</label><input name="giden_adet" inputmode="decimal" placeholder="0"></div>
            <div class="field"><label>Gelen Palet</label><input name="gelen_adet" inputmode="decimal" placeholder="0"></div>
            <div class="field"><label>Teslim Eden</label><input name="teslim_eden"></div>
            <div class="field"><label>Teslim Alan</label><input name="teslim_alan"></div>
            <div class="field span-4"><label>Açıklama</label><textarea name="aciklama"></textarea></div>
            <div class="span-4"><button class="btn btn-green">Hareketi Kaydet</button></div>
        </form>
    </div>
    <?php endif; ?>

    <?php if($tab === 'excel'): ?>
    <div class="panel">
        <h3>Excel’den Aktarım</h3>
        <p>Dosya kolonları: Tarih, giden, geri gelen, teslim eden, teslim alan, kullanım yeri.</p>
        <form method="POST" enctype="multipart/form-data" class="grid">
            <input type="hidden" name="action" value="excel_aktar">
            <div class="field span-2"><label>Depo adı</label><input name="depo_adi" value="SEĞMEN SU DEPO"></div>
            <div class="field span-2"><label>Excel dosyası</label><input type="file" name="excel" accept=".xlsx,.xls" required></div>
            <div class="span-4"><button class="btn btn-green">Excel’den Aktar</button></div>
        </form>
    </div>
    <?php endif; ?>

    <?php if($tab === 'kalan'): ?>
    <div class="panel">
        <h3>Sevk Edilen Yer Bazlı Kalan Palet</h3>
        <div class="table-wrap"><table><thead><tr><th>Yer</th><th>Giden</th><th>Geri Gelen</th><th>Kalan</th></tr></thead><tbody>
        <?php if(!$yerKalan): ?><tr><td colspan="4">Henüz hareket bulunmuyor.</td></tr><?php endif; ?>
        <?php foreach($yerKalan as $row): ?><tr><td><strong><?php echo palet_e($row['yer']); ?></strong></td><td class="num"><?php echo number_format((float)$row['giden'],2,',','.'); ?></td><td class="num"><?php echo number_format((float)$row['gelen'],2,',','.'); ?></td><td class="num"><strong><?php echo number_format((float)$row['kalan'],2,',','.'); ?></strong></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>

    <?php if($tab === 'liste'): ?>
    <div class="panel">
        <h3>Sevk Eden / Sevk Edilen Hareket Listesi</h3>
        <div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Sevk Eden</th><th>Sevk Edilen</th><th>Giden</th><th>Gelen</th><th>Teslim Eden</th><th>Teslim Alan</th><th>Açıklama</th><th>İşlem</th></tr></thead><tbody>
        <?php if(!$hareketler): ?><tr><td colspan="9">Henüz hareket bulunmuyor.</td></tr><?php endif; ?>
        <?php foreach($hareketler as $row): ?><tr><td><?php echo date('d.m.Y', strtotime($row['tarih'])); ?></td><td><?php echo palet_e($row['sevk_eden_yer']); ?></td><td><?php echo palet_e($row['sevk_edilen_yer']); ?></td><td class="num"><?php echo number_format((float)$row['giden_adet'],2,',','.'); ?></td><td class="num"><?php echo number_format((float)$row['gelen_adet'],2,',','.'); ?></td><td><?php echo palet_e($row['teslim_eden']); ?></td><td><?php echo palet_e($row['teslim_alan']); ?></td><td><?php echo palet_e($row['aciklama']); ?></td><td><form method="POST" onsubmit="return confirm('Hareket silinsin mi?');"><input type="hidden" name="action" value="hareket_sil"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-red">Sil</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>

    <?php if($tab === 'ozet'): ?>
    <div class="summary">
        <div class="summary-item"><span>Toplam giden</span><strong><?php echo number_format((float)$ozet['giden'],2,',','.'); ?></strong></div>
        <div class="summary-item"><span>Toplam gelen</span><strong><?php echo number_format((float)$ozet['gelen'],2,',','.'); ?></strong></div>
        <div class="summary-item"><span>Net kalan</span><strong><?php echo number_format((float)$ozet['giden'] - (float)$ozet['gelen'],2,',','.'); ?></strong></div>
        <div class="summary-item"><span>Hareket kaydı</span><strong><?php echo number_format((int)$ozet['kayit']); ?></strong></div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
