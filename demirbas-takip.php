<?php
session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header('Location: login.php');
    exit;
}

function asset_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function asset_number($value): float
{
    $value = str_replace(['₺', 'TL', ' '], '', trim((string)$value));
    if(str_contains($value, ',')){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

function asset_money($value): string
{
    return '₺' . number_format((float)$value, 2, ',', '.');
}

$db->exec("
CREATE TABLE IF NOT EXISTS demirbas_tanimlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hesap_kodu VARCHAR(80) NULL,
    demirbas_kodu VARCHAR(80) NULL,
    demirbas_adi VARCHAR(255) NOT NULL,
    kategori VARCHAR(120) NULL,
    marka_model VARCHAR(180) NULL,
    seri_no VARCHAR(150) NULL,
    alis_tarihi DATE NULL,
    adet DECIMAL(12,2) NOT NULL DEFAULT 1,
    birim_tutar DECIMAL(15,2) NOT NULL DEFAULT 0,
    toplam_tutar DECIMAL(15,2) NOT NULL DEFAULT 0,
    kullanim_yeri VARCHAR(180) NULL,
    zimmetli_kisi VARCHAR(180) NULL,
    durum ENUM('Aktif','Bakımda','Hurda','Pasif') NOT NULL DEFAULT 'Aktif',
    aciklama TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_demirbas_adi (demirbas_adi),
    INDEX idx_kullanim_yeri (kullanim_yeri),
    INDEX idx_zimmetli_kisi (zimmetli_kisi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$db->exec("
CREATE TABLE IF NOT EXISTS demirbas_zimmet_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    demirbas_id INT NOT NULL,
    islem_tipi ENUM('Zimmet','Devir','İade') NOT NULL DEFAULT 'Zimmet',
    kullanim_yeri VARCHAR(180) NULL,
    zimmetli_kisi VARCHAR(180) NULL,
    islem_tarihi DATE NOT NULL,
    aciklama TEXT NULL,
    kaydeden_user_id INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_zimmet_demirbas (demirbas_id),
    CONSTRAINT fk_zimmet_demirbas FOREIGN KEY (demirbas_id)
        REFERENCES demirbas_tanimlari(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$db->exec("CREATE TABLE IF NOT EXISTS demirbas_kullanim_yerleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    yer_adi VARCHAR(180) NOT NULL UNIQUE,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS demirbas_personelleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_soyad VARCHAR(180) NOT NULL,
    unvan VARCHAR(150) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_personel_ad (ad_soyad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$message = '';
$error = '';
$tab = (string)($_GET['tab'] ?? 'liste');
$tanimTuru = (string)($_GET['tanim_turu'] ?? $_POST['tanim_turu'] ?? 'demirbas');
if(!in_array($tanimTuru, ['demirbas', 'yer', 'personel'], true)){
    $tanimTuru = 'demirbas';
}
if(!in_array($tab, ['liste', 'tanim', 'zimmet', 'rapor'], true)){
    $tab = 'liste';
}

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = (string)($_POST['action'] ?? '');

        if($action === 'demirbas_kaydet'){
            $id = (int)($_POST['id'] ?? 0);
            $adi = trim((string)($_POST['demirbas_adi'] ?? ''));
            $adet = max(0, asset_number($_POST['adet'] ?? 1));
            $birim = max(0, asset_number($_POST['birim_tutar'] ?? 0));
            $toplam = max(0, asset_number($_POST['toplam_tutar'] ?? 0));
            if($adi === ''){
                throw new Exception('Demirbaş adı zorunludur.');
            }
            if($toplam <= 0 && $adet > 0){
                $toplam = $adet * $birim;
            }
            $values = [
                trim((string)($_POST['hesap_kodu'] ?? '')),
                trim((string)($_POST['demirbas_kodu'] ?? '')),
                $adi,
                trim((string)($_POST['kategori'] ?? '')),
                trim((string)($_POST['marka_model'] ?? '')),
                trim((string)($_POST['seri_no'] ?? '')),
                ($_POST['alis_tarihi'] ?? '') !== '' ? $_POST['alis_tarihi'] : null,
                $adet,
                $birim,
                $toplam,
                trim((string)($_POST['kullanim_yeri'] ?? '')),
                trim((string)($_POST['zimmetli_kisi'] ?? '')),
                (string)($_POST['durum'] ?? 'Aktif'),
                trim((string)($_POST['aciklama'] ?? '')),
            ];
            if($id > 0){
                $query = $db->prepare("UPDATE demirbas_tanimlari SET hesap_kodu=?,demirbas_kodu=?,demirbas_adi=?,kategori=?,marka_model=?,seri_no=?,alis_tarihi=?,adet=?,birim_tutar=?,toplam_tutar=?,kullanim_yeri=?,zimmetli_kisi=?,durum=?,aciklama=? WHERE id=?");
                $values[] = $id;
                $query->execute($values);
                $message = 'Demirbaş kaydı güncellendi.';
            }else{
                $query = $db->prepare("INSERT INTO demirbas_tanimlari (hesap_kodu,demirbas_kodu,demirbas_adi,kategori,marka_model,seri_no,alis_tarihi,adet,birim_tutar,toplam_tutar,kullanim_yeri,zimmetli_kisi,durum,aciklama) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $query->execute($values);
                $message = 'Demirbaş kaydı eklendi.';
            }
            $tab = 'liste';
        }

        if($action === 'yer_kaydet'){
            $id = (int)($_POST['id'] ?? 0);
            $yerAdi = trim((string)($_POST['yer_adi'] ?? ''));
            if($yerAdi === ''){
                throw new Exception('Kullanım yeri adı zorunludur.');
            }
            if($id > 0){
                $query = $db->prepare('UPDATE demirbas_kullanim_yerleri SET yer_adi=?, aciklama=? WHERE id=?');
                $query->execute([$yerAdi, trim((string)($_POST['aciklama'] ?? '')), $id]);
                $message = 'Kullanım yeri güncellendi.';
            }else{
                $query = $db->prepare('INSERT INTO demirbas_kullanim_yerleri (yer_adi,aciklama) VALUES (?,?)');
                $query->execute([$yerAdi, trim((string)($_POST['aciklama'] ?? ''))]);
                $message = 'Kullanım yeri eklendi.';
            }
            $tab = 'tanim';
            $tanimTuru = 'yer';
        }

        if($action === 'yer_sil'){
            $db->prepare('DELETE FROM demirbas_kullanim_yerleri WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Kullanım yeri silindi.';
            $tab = 'tanim';
            $tanimTuru = 'yer';
        }

        if($action === 'personel_kaydet'){
            $id = (int)($_POST['id'] ?? 0);
            $adSoyad = trim((string)($_POST['ad_soyad'] ?? ''));
            if($adSoyad === ''){
                throw new Exception('Personel adı zorunludur.');
            }
            $values = [$adSoyad, trim((string)($_POST['unvan'] ?? '')), isset($_POST['aktif']) ? 1 : 0];
            if($id > 0){
                $values[] = $id;
                $db->prepare('UPDATE demirbas_personelleri SET ad_soyad=?, unvan=?, aktif=? WHERE id=?')->execute($values);
                $message = 'Personel güncellendi.';
            }else{
                $db->prepare('INSERT INTO demirbas_personelleri (ad_soyad,unvan,aktif) VALUES (?,?,?)')->execute($values);
                $message = 'Personel eklendi.';
            }
            $tab = 'tanim';
            $tanimTuru = 'personel';
        }

        if($action === 'personel_sil'){
            $db->prepare('DELETE FROM demirbas_personelleri WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Personel silindi.';
            $tab = 'tanim';
            $tanimTuru = 'personel';
        }

        if($action === 'zimmet_kaydet'){
            $demirbasId = (int)($_POST['demirbas_id'] ?? 0);
            $tip = (string)($_POST['islem_tipi'] ?? 'Zimmet');
            $yer = trim((string)($_POST['kullanim_yeri'] ?? ''));
            $kisi = trim((string)($_POST['zimmetli_kisi'] ?? ''));
            $tarih = (string)($_POST['islem_tarihi'] ?? date('Y-m-d'));
            if($demirbasId <= 0){
                throw new Exception('Demirbaş seçiniz.');
            }
            $query = $db->prepare("INSERT INTO demirbas_zimmet_hareketleri (demirbas_id,islem_tipi,kullanim_yeri,zimmetli_kisi,islem_tarihi,aciklama,kaydeden_user_id) VALUES (?,?,?,?,?,?,?)");
            $query->execute([$demirbasId, $tip, $yer, $kisi, $tarih, trim((string)($_POST['aciklama'] ?? '')), (int)$_SESSION['user_id']]);
            if($tip === 'İade'){
                $update = $db->prepare("UPDATE demirbas_tanimlari SET kullanim_yeri='', zimmetli_kisi='' WHERE id=?");
                $update->execute([$demirbasId]);
            }else{
                $update = $db->prepare("UPDATE demirbas_tanimlari SET kullanim_yeri=?, zimmetli_kisi=? WHERE id=?");
                $update->execute([$yer, $kisi, $demirbasId]);
            }
            $message = 'Zimmet hareketi kaydedildi.';
            $tab = 'zimmet';
        }

        if($action === 'demirbas_sil'){
            $query = $db->prepare("DELETE FROM demirbas_tanimlari WHERE id=?");
            $query->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Demirbaş kaydı silindi.';
            $tab = 'liste';
        }
    }
} catch(Throwable $e){
    $error = $e->getMessage();
}

$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
if($editId > 0){
    $query = $db->prepare("SELECT * FROM demirbas_tanimlari WHERE id=?");
    $query->execute([$editId]);
    $editing = $query->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'tanim';
    $tanimTuru = 'demirbas';
}

$yerEdit = null;
$yerEditId = (int)($_GET['yer_edit'] ?? 0);
if($yerEditId > 0){
    $query = $db->prepare('SELECT * FROM demirbas_kullanim_yerleri WHERE id=?');
    $query->execute([$yerEditId]);
    $yerEdit = $query->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'tanim';
    $tanimTuru = 'yer';
}

$personelEdit = null;
$personelEditId = (int)($_GET['personel_edit'] ?? 0);
if($personelEditId > 0){
    $query = $db->prepare('SELECT * FROM demirbas_personelleri WHERE id=?');
    $query->execute([$personelEditId]);
    $personelEdit = $query->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'tanim';
    $tanimTuru = 'personel';
}

$arama = trim((string)($_GET['arama'] ?? ''));
$durumFiltre = trim((string)($_GET['durum'] ?? ''));
$yerFiltre = trim((string)($_GET['yer'] ?? ''));
$where = [];
$params = [];
if($arama !== ''){
    $where[] = '(demirbas_adi LIKE ? OR hesap_kodu LIKE ? OR demirbas_kodu LIKE ? OR zimmetli_kisi LIKE ?)';
    array_push($params, "%{$arama}%", "%{$arama}%", "%{$arama}%", "%{$arama}%");
}
if($durumFiltre !== ''){
    $where[] = 'durum=?';
    $params[] = $durumFiltre;
}
if($yerFiltre !== ''){
    $where[] = 'kullanim_yeri=?';
    $params[] = $yerFiltre;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$query = $db->prepare("SELECT * FROM demirbas_tanimlari {$whereSql} ORDER BY demirbas_adi,id");
$query->execute($params);
$demirbaslar = $query->fetchAll(PDO::FETCH_ASSOC);
$tumDemirbaslar = $db->query("SELECT id,demirbas_adi,demirbas_kodu FROM demirbas_tanimlari ORDER BY demirbas_adi")->fetchAll(PDO::FETCH_ASSOC);
$yerTanimlari = $db->query('SELECT * FROM demirbas_kullanim_yerleri ORDER BY yer_adi')->fetchAll(PDO::FETCH_ASSOC);
$personelTanimlari = $db->query('SELECT * FROM demirbas_personelleri ORDER BY ad_soyad')->fetchAll(PDO::FETCH_ASSOC);
$kullanimYerleri = array_column($yerTanimlari, 'yer_adi');
$hareketler = $db->query("SELECT z.*,d.demirbas_adi,d.demirbas_kodu FROM demirbas_zimmet_hareketleri z INNER JOIN demirbas_tanimlari d ON d.id=z.demirbas_id ORDER BY z.islem_tarihi DESC,z.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$ozet = $db->query("SELECT COUNT(*) kayit,COALESCE(SUM(adet),0) adet,COALESCE(SUM(toplam_tutar),0) toplam,SUM(CASE WHEN zimmetli_kisi IS NOT NULL AND zimmetli_kisi<>'' THEN 1 ELSE 0 END) zimmetli FROM demirbas_tanimlari")->fetch(PDO::FETCH_ASSOC);
$yerRapor = $db->query("SELECT COALESCE(NULLIF(kullanim_yeri,''),'Atanmamış') baslik,COUNT(*) kayit,SUM(adet) adet,SUM(toplam_tutar) toplam FROM demirbas_tanimlari GROUP BY COALESCE(NULLIF(kullanim_yeri,''),'Atanmamış') ORDER BY toplam DESC")->fetchAll(PDO::FETCH_ASSOC);
$kisiRapor = $db->query("SELECT COALESCE(NULLIF(zimmetli_kisi,''),'Zimmetlenmemiş') baslik,COUNT(*) kayit,SUM(adet) adet,SUM(toplam_tutar) toplam FROM demirbas_tanimlari GROUP BY COALESCE(NULLIF(zimmetli_kisi,''),'Zimmetlenmemiş') ORDER BY toplam DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Demirbaş Takip</title><link rel="stylesheet" href="assets/css/style.css">
<style>
.asset-tabs{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;margin-bottom:18px}.asset-tab{padding:14px 16px;border:1px solid #dce2ea;border-radius:8px;background:#fff;color:#1f2937;text-decoration:none;font-weight:800;font-size:13px}.asset-tab.active{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-bottom:18px}.panel h3{margin:0 0 14px;font-size:18px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#334155}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cfd6df;border-radius:7px;padding:9px 10px;font-size:13px;background:#fff}.field textarea{min-height:74px;resize:vertical}.span-2{grid-column:span 2}.span-4{grid-column:span 4}.btn{border:0;border-radius:7px;padding:9px 12px;background:#2563eb;color:#fff;text-decoration:none;font-size:12px;font-weight:800;cursor:pointer;display:inline-flex;justify-content:center}.btn-green{background:#16a34a}.btn-red{background:#dc2626}.btn-gray{background:#64748b}.actions{display:flex;gap:5px;flex-wrap:wrap}.notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:700}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.filters{display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:8px;align-items:end;margin-bottom:14px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:11px;min-width:1000px}th{background:#17233b;color:#fff;text-align:left;padding:9px 7px;white-space:nowrap}td{border-bottom:1px solid #e8edf3;padding:8px 7px;vertical-align:middle}.money{text-align:right;font-variant-numeric:tabular-nums}.badge{display:inline-flex;border-radius:999px;padding:4px 7px;background:#e2e8f0;font-size:10px;font-weight:800}.badge.active{background:#dcfce7;color:#166534}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.summary-item{background:#fff;border:1px solid #e5e7eb;border-radius:9px;padding:14px}.summary-item span{display:block;color:#64748b;font-size:11px;margin-bottom:5px}.summary-item strong{font-size:19px}.report-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:1100px){.grid{grid-template-columns:repeat(2,1fr)}.span-4{grid-column:span 2}.filters{grid-template-columns:1fr 1fr}.asset-tabs{grid-template-columns:1fr 1fr}.report-grid{grid-template-columns:1fr}}@media(max-width:650px){.grid,.summary{grid-template-columns:1fr}.span-2,.span-4{grid-column:span 1}.asset-tabs{grid-template-columns:1fr}}
.asset-list{min-width:0}.asset-list th:nth-child(1){width:22%}.asset-list th:nth-child(2){width:auto}.asset-list th:nth-child(3){width:18%;text-align:right}.asset-list th:nth-child(4){width:100px;text-align:center}.asset-list .detail-cell{text-align:center}.asset-detail-row[hidden]{display:none}.asset-detail-row td{padding:0;background:#f8fafc}.asset-detail{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:14px 16px;border-bottom:1px solid #dbe3ed}.asset-detail-item{min-width:0}.asset-detail-item span{display:block;margin-bottom:3px;color:#64748b;font-size:10px;font-weight:700}.asset-detail-item strong{display:block;color:#1e293b;font-size:12px;overflow-wrap:anywhere}.asset-detail-actions{grid-column:1/-1;display:flex;align-items:center;gap:6px;padding-top:4px}.detail-toggle[aria-expanded="true"]{background:#475569}@media(max-width:750px){.asset-detail{grid-template-columns:repeat(2,minmax(0,1fr))}.asset-list th:nth-child(1){width:28%}.asset-list th:nth-child(3){width:24%}}
.definition-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.definition-tab{padding:9px 13px;border:1px solid #d6dee8;border-radius:7px;background:#fff;color:#334155;text-decoration:none;font-size:12px;font-weight:800}.definition-tab.active{background:#17233b;border-color:#17233b;color:#fff}.definition-layout{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:16px}.definition-table{min-width:0}.definition-table th:last-child,.definition-table td:last-child{width:150px}.checkbox-line{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:700}.checkbox-line input{width:auto}@media(max-width:900px){.definition-layout{grid-template-columns:1fr}}
</style></head><body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="main"><div class="topbar"><h2>Demirbaş Takip</h2><p>Demirbaş tanımları, kullanım yerleri, zimmet hareketleri ve raporları.</p></div>
<?php if($message): ?><div class="notice ok"><?php echo asset_e($message); ?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?php echo asset_e($error); ?></div><?php endif; ?>
<nav class="asset-tabs"><a class="asset-tab <?php echo $tab==='liste'?'active':''; ?>" href="?tab=liste">Demirbaş Listesi</a><a class="asset-tab <?php echo $tab==='tanim'?'active':''; ?>" href="?tab=tanim">Demirbaş Tanımları</a><a class="asset-tab <?php echo $tab==='zimmet'?'active':''; ?>" href="?tab=zimmet">Zimmet / Kullanım</a><a class="asset-tab <?php echo $tab==='rapor'?'active':''; ?>" href="?tab=rapor">Raporlar</a></nav>

<?php if($tab==='liste'): ?>
<div class="summary"><div class="summary-item"><span>Demirbaş kaydı</span><strong><?php echo number_format((int)$ozet['kayit']); ?></strong></div><div class="summary-item"><span>Toplam adet</span><strong><?php echo number_format((float)$ozet['adet'],2,',','.'); ?></strong></div><div class="summary-item"><span>Toplam değer</span><strong><?php echo asset_money($ozet['toplam']); ?></strong></div><div class="summary-item"><span>Zimmetli kayıt</span><strong><?php echo number_format((int)$ozet['zimmetli']); ?></strong></div></div>
<div class="panel"><form method="GET" class="filters"><input type="hidden" name="tab" value="liste"><div class="field"><label>Arama</label><input name="arama" value="<?php echo asset_e($arama); ?>" placeholder="Kod, demirbaş veya kişi"></div><div class="field"><label>Durum</label><select name="durum"><option value="">Tümü</option><?php foreach(['Aktif','Bakımda','Hurda','Pasif'] as $d): ?><option <?php echo $durumFiltre===$d?'selected':''; ?>><?php echo asset_e($d); ?></option><?php endforeach; ?></select></div><div class="field"><label>Kullanım yeri</label><select name="yer"><option value="">Tümü</option><?php foreach($kullanimYerleri as $yer): ?><option value="<?php echo asset_e($yer); ?>" <?php echo $yerFiltre===$yer?'selected':''; ?>><?php echo asset_e($yer); ?></option><?php endforeach; ?></select></div><button class="btn">Filtrele</button><a class="btn btn-gray" href="?tab=liste">Temizle</a></form>
<div class="table-wrap"><table class="asset-list"><thead><tr><th>Hesap Kodu</th><th>Hesap Adı</th><th>Tutar</th><th>Detay</th></tr></thead><tbody>
<?php if(!$demirbaslar): ?><tr><td colspan="4">Henüz demirbaş kaydı bulunmuyor.</td></tr><?php endif; ?>
<?php foreach($demirbaslar as $row): $detailId='asset-detail-'.(int)$row['id']; ?>
<tr><td><?php echo asset_e($row['hesap_kodu']); ?></td><td><strong><?php echo asset_e($row['demirbas_adi']); ?></strong></td><td class="money"><strong><?php echo asset_money($row['toplam_tutar']); ?></strong></td><td class="detail-cell"><button type="button" class="btn detail-toggle" aria-expanded="false" aria-controls="<?php echo $detailId; ?>" onclick="toggleAssetDetail(this)">Detay</button></td></tr>
<tr id="<?php echo $detailId; ?>" class="asset-detail-row" hidden><td colspan="4"><div class="asset-detail">
<div class="asset-detail-item"><span>Demirbaş Kodu</span><strong><?php echo asset_e($row['demirbas_kodu'] ?: '-'); ?></strong></div><div class="asset-detail-item"><span>Kategori</span><strong><?php echo asset_e($row['kategori'] ?: '-'); ?></strong></div><div class="asset-detail-item"><span>Marka / Model</span><strong><?php echo asset_e($row['marka_model'] ?: '-'); ?></strong></div><div class="asset-detail-item"><span>Seri No</span><strong><?php echo asset_e($row['seri_no'] ?: '-'); ?></strong></div>
<div class="asset-detail-item"><span>Alış Tarihi</span><strong><?php echo $row['alis_tarihi'] ? date('d.m.Y',strtotime($row['alis_tarihi'])) : '-'; ?></strong></div><div class="asset-detail-item"><span>Adet</span><strong><?php echo number_format((float)$row['adet'],2,',','.'); ?></strong></div><div class="asset-detail-item"><span>Birim Tutar</span><strong><?php echo asset_money($row['birim_tutar']); ?></strong></div><div class="asset-detail-item"><span>Durum</span><strong><span class="badge <?php echo $row['durum']==='Aktif'?'active':''; ?>"><?php echo asset_e($row['durum']); ?></span></strong></div>
<div class="asset-detail-item"><span>Kullanım Yeri</span><strong><?php echo asset_e($row['kullanim_yeri'] ?: '-'); ?></strong></div><div class="asset-detail-item"><span>Zimmetli Kişi</span><strong><?php echo asset_e($row['zimmetli_kisi'] ?: '-'); ?></strong></div><div class="asset-detail-item"><span>Açıklama</span><strong><?php echo asset_e($row['aciklama'] ?: '-'); ?></strong></div>
<div class="asset-detail-actions"><a class="btn" href="?tab=tanim&edit=<?php echo (int)$row['id']; ?>">Düzelt</a><a class="btn btn-green" href="?tab=zimmet&demirbas_id=<?php echo (int)$row['id']; ?>">Zimmet</a><form method="POST" onsubmit="return confirm('Demirbaş silinsin mi?');"><input type="hidden" name="action" value="demirbas_sil"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-red">Sil</button></form></div>
</div></td></tr><?php endforeach; ?></tbody></table></div></div>
<script>function toggleAssetDetail(button){var row=document.getElementById(button.getAttribute('aria-controls'));var open=button.getAttribute('aria-expanded')==='true';button.setAttribute('aria-expanded',open?'false':'true');button.textContent=open?'Detay':'Kapat';row.hidden=open;}</script>
<?php endif; ?>

<?php if($tab==='tanim'): ?>
<nav class="definition-tabs"><a class="definition-tab <?php echo $tanimTuru==='demirbas'?'active':''; ?>" href="?tab=tanim&tanim_turu=demirbas">Demirbaş Tanımları</a><a class="definition-tab <?php echo $tanimTuru==='yer'?'active':''; ?>" href="?tab=tanim&tanim_turu=yer">Kullanım Yerleri</a><a class="definition-tab <?php echo $tanimTuru==='personel'?'active':''; ?>" href="?tab=tanim&tanim_turu=personel">Personel Tanımları</a></nav>

<?php if($tanimTuru==='demirbas'): ?><div class="panel"><h3><?php echo $editing?'Demirbaş Düzenle':'Yeni Demirbaş Tanımı'; ?></h3><form method="POST" class="grid"><input type="hidden" name="action" value="demirbas_kaydet"><input type="hidden" name="tanim_turu" value="demirbas"><input type="hidden" name="id" value="<?php echo (int)($editing['id']??0); ?>">
<div class="field"><label>Hesap kodu</label><input name="hesap_kodu" value="<?php echo asset_e($editing['hesap_kodu']??''); ?>" placeholder="253.002.001.002"></div><div class="field"><label>Demirbaş kodu</label><input name="demirbas_kodu" value="<?php echo asset_e($editing['demirbas_kodu']??''); ?>"></div><div class="field span-2"><label>Demirbaş adı *</label><input name="demirbas_adi" required value="<?php echo asset_e($editing['demirbas_adi']??''); ?>"></div>
<div class="field"><label>Kategori</label><input name="kategori" value="<?php echo asset_e($editing['kategori']??''); ?>" placeholder="Makine, araç, ekipman"></div><div class="field"><label>Marka / model</label><input name="marka_model" value="<?php echo asset_e($editing['marka_model']??''); ?>"></div><div class="field"><label>Seri no</label><input name="seri_no" value="<?php echo asset_e($editing['seri_no']??''); ?>"></div><div class="field"><label>Alış tarihi</label><input type="date" name="alis_tarihi" value="<?php echo asset_e($editing['alis_tarihi']??''); ?>"></div>
<div class="field"><label>Adet</label><input name="adet" value="<?php echo asset_e($editing['adet']??'1'); ?>"></div><div class="field"><label>Birim tutar</label><input name="birim_tutar" value="<?php echo asset_e(isset($editing['birim_tutar'])?number_format((float)$editing['birim_tutar'],2,',','.'):'0,00'); ?>"></div><div class="field"><label>Toplam tutar</label><input name="toplam_tutar" value="<?php echo asset_e(isset($editing['toplam_tutar'])?number_format((float)$editing['toplam_tutar'],2,',','.'):'0,00'); ?>"></div><div class="field"><label>Durum</label><select name="durum"><?php foreach(['Aktif','Bakımda','Hurda','Pasif'] as $d): ?><option <?php echo ($editing['durum']??'Aktif')===$d?'selected':''; ?>><?php echo asset_e($d); ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Kullanım yeri</label><select name="kullanim_yeri"><option value="">Seçiniz</option><?php foreach($yerTanimlari as $yer): ?><option value="<?php echo asset_e($yer['yer_adi']); ?>" <?php echo ($editing['kullanim_yeri']??'')===$yer['yer_adi']?'selected':''; ?>><?php echo asset_e($yer['yer_adi']); ?></option><?php endforeach; ?></select></div><div class="field"><label>Zimmetli kişi</label><select name="zimmetli_kisi"><option value="">Seçiniz</option><?php foreach($personelTanimlari as $personel): ?><option value="<?php echo asset_e($personel['ad_soyad']); ?>" <?php echo ($editing['zimmetli_kisi']??'')===$personel['ad_soyad']?'selected':''; ?>><?php echo asset_e($personel['ad_soyad']); ?></option><?php endforeach; ?></select></div><div class="field span-4"><label>Açıklama</label><textarea name="aciklama"><?php echo asset_e($editing['aciklama']??''); ?></textarea></div><div class="span-4 actions"><button class="btn btn-green"><?php echo $editing?'Güncelle':'Kaydet'; ?></button><?php if($editing): ?><a class="btn btn-gray" href="?tab=tanim&tanim_turu=demirbas">Vazgeç</a><?php endif; ?></div></form></div><?php endif; ?>

<?php if($tanimTuru==='yer'): ?><div class="definition-layout"><div class="panel"><h3><?php echo $yerEdit?'Kullanım Yeri Düzenle':'Yeni Kullanım Yeri'; ?></h3><form method="POST"><input type="hidden" name="action" value="yer_kaydet"><input type="hidden" name="tanim_turu" value="yer"><input type="hidden" name="id" value="<?php echo (int)($yerEdit['id']??0); ?>"><div class="field"><label>Kullanım yeri adı *</label><input name="yer_adi" required value="<?php echo asset_e($yerEdit['yer_adi']??''); ?>"></div><div class="field" style="margin-top:12px"><label>Açıklama</label><textarea name="aciklama"><?php echo asset_e($yerEdit['aciklama']??''); ?></textarea></div><div class="actions" style="margin-top:12px"><button class="btn btn-green"><?php echo $yerEdit?'Güncelle':'Kaydet'; ?></button><?php if($yerEdit): ?><a class="btn btn-gray" href="?tab=tanim&tanim_turu=yer">Vazgeç</a><?php endif; ?></div></form></div><div class="panel"><h3>Kullanım Yerleri</h3><div class="table-wrap"><table class="definition-table"><thead><tr><th>Kullanım Yeri</th><th>Açıklama</th><th>İşlem</th></tr></thead><tbody><?php if(!$yerTanimlari): ?><tr><td colspan="3">Henüz kullanım yeri tanımlanmadı.</td></tr><?php endif; ?><?php foreach($yerTanimlari as $yer): ?><tr><td><strong><?php echo asset_e($yer['yer_adi']); ?></strong></td><td><?php echo asset_e($yer['aciklama']); ?></td><td><div class="actions"><a class="btn" href="?tab=tanim&tanim_turu=yer&yer_edit=<?php echo (int)$yer['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Kullanım yeri silinsin mi?');"><input type="hidden" name="action" value="yer_sil"><input type="hidden" name="tanim_turu" value="yer"><input type="hidden" name="id" value="<?php echo (int)$yer['id']; ?>"><button class="btn btn-red">Sil</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>

<?php if($tanimTuru==='personel'): ?><div class="definition-layout"><div class="panel"><h3><?php echo $personelEdit?'Personel Düzenle':'Yeni Personel'; ?></h3><form method="POST"><input type="hidden" name="action" value="personel_kaydet"><input type="hidden" name="tanim_turu" value="personel"><input type="hidden" name="id" value="<?php echo (int)($personelEdit['id']??0); ?>"><div class="field"><label>Ad soyad *</label><input name="ad_soyad" required value="<?php echo asset_e($personelEdit['ad_soyad']??''); ?>"></div><div class="field" style="margin-top:12px"><label>Unvan</label><input name="unvan" value="<?php echo asset_e($personelEdit['unvan']??''); ?>"></div><label class="checkbox-line" style="margin-top:14px"><input type="checkbox" name="aktif" value="1" <?php echo !isset($personelEdit['aktif']) || (int)$personelEdit['aktif']===1?'checked':''; ?>> Aktif</label><div class="actions" style="margin-top:12px"><button class="btn btn-green"><?php echo $personelEdit?'Güncelle':'Kaydet'; ?></button><?php if($personelEdit): ?><a class="btn btn-gray" href="?tab=tanim&tanim_turu=personel">Vazgeç</a><?php endif; ?></div></form></div><div class="panel"><h3>Personel Listesi</h3><div class="table-wrap"><table class="definition-table"><thead><tr><th>Ad Soyad</th><th>Unvan</th><th>Durum</th><th>İşlem</th></tr></thead><tbody><?php if(!$personelTanimlari): ?><tr><td colspan="4">Henüz personel tanımlanmadı.</td></tr><?php endif; ?><?php foreach($personelTanimlari as $personel): ?><tr><td><strong><?php echo asset_e($personel['ad_soyad']); ?></strong></td><td><?php echo asset_e($personel['unvan']); ?></td><td><span class="badge <?php echo (int)$personel['aktif']===1?'active':''; ?>"><?php echo (int)$personel['aktif']===1?'Aktif':'Pasif'; ?></span></td><td><div class="actions"><a class="btn" href="?tab=tanim&tanim_turu=personel&personel_edit=<?php echo (int)$personel['id']; ?>">Düzelt</a><form method="POST" onsubmit="return confirm('Personel silinsin mi?');"><input type="hidden" name="action" value="personel_sil"><input type="hidden" name="tanim_turu" value="personel"><input type="hidden" name="id" value="<?php echo (int)$personel['id']; ?>"><button class="btn btn-red">Sil</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
<?php endif; ?>

<?php if($tab==='zimmet'): ?><div class="panel"><h3>Zimmet / Kullanım Yeri İşlemi</h3><form method="POST" class="grid"><input type="hidden" name="action" value="zimmet_kaydet"><div class="field span-2"><label>Demirbaş</label><select name="demirbas_id" required><option value="">Seçiniz</option><?php foreach($tumDemirbaslar as $d): ?><option value="<?php echo (int)$d['id']; ?>" <?php echo (int)($_GET['demirbas_id']??0)===(int)$d['id']?'selected':''; ?>><?php echo asset_e(($d['demirbas_kodu']?$d['demirbas_kodu'].' - ':'').$d['demirbas_adi']); ?></option><?php endforeach; ?></select></div><div class="field"><label>İşlem tipi</label><select name="islem_tipi"><option>Zimmet</option><option>Devir</option><option>İade</option></select></div><div class="field"><label>İşlem tarihi</label><input type="date" name="islem_tarihi" value="<?php echo date('Y-m-d'); ?>" required></div><div class="field span-2"><label>Kullanım yeri</label><select name="kullanim_yeri"><option value="">Seçiniz</option><?php foreach($yerTanimlari as $yer): ?><option value="<?php echo asset_e($yer['yer_adi']); ?>"><?php echo asset_e($yer['yer_adi']); ?></option><?php endforeach; ?></select></div><div class="field span-2"><label>Zimmetli kişi</label><select name="zimmetli_kisi"><option value="">Seçiniz</option><?php foreach($personelTanimlari as $personel): ?><?php if((int)$personel['aktif']===1): ?><option value="<?php echo asset_e($personel['ad_soyad']); ?>"><?php echo asset_e($personel['ad_soyad']); ?><?php echo $personel['unvan']?' - '.asset_e($personel['unvan']):''; ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="field span-4"><label>Açıklama</label><textarea name="aciklama"></textarea></div><div class="span-4"><button class="btn btn-green">Hareketi Kaydet</button></div></form></div>
<div class="panel"><h3>Son Zimmet Hareketleri</h3><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Demirbaş</th><th>İşlem</th><th>Kullanım Yeri</th><th>Zimmetli Kişi</th><th>Açıklama</th></tr></thead><tbody><?php foreach($hareketler as $h): ?><tr><td><?php echo date('d.m.Y',strtotime($h['islem_tarihi'])); ?></td><td><?php echo asset_e($h['demirbas_adi']); ?></td><td><span class="badge"><?php echo asset_e($h['islem_tipi']); ?></span></td><td><?php echo asset_e($h['kullanim_yeri']); ?></td><td><?php echo asset_e($h['zimmetli_kisi']); ?></td><td><?php echo asset_e($h['aciklama']); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>

<?php if($tab==='rapor'): ?><div class="summary"><div class="summary-item"><span>Demirbaş kaydı</span><strong><?php echo number_format((int)$ozet['kayit']); ?></strong></div><div class="summary-item"><span>Toplam adet</span><strong><?php echo number_format((float)$ozet['adet'],2,',','.'); ?></strong></div><div class="summary-item"><span>Toplam değer</span><strong><?php echo asset_money($ozet['toplam']); ?></strong></div><div class="summary-item"><span>Zimmetli kayıt</span><strong><?php echo number_format((int)$ozet['zimmetli']); ?></strong></div></div><div class="report-grid"><div class="panel"><h3>Kullanım Yerine Göre</h3><div class="table-wrap"><table><thead><tr><th>Kullanım Yeri</th><th>Kayıt</th><th>Adet</th><th>Toplam Değer</th></tr></thead><tbody><?php foreach($yerRapor as $r): ?><tr><td><?php echo asset_e($r['baslik']); ?></td><td><?php echo number_format((int)$r['kayit']); ?></td><td><?php echo number_format((float)$r['adet'],2,',','.'); ?></td><td class="money"><?php echo asset_money($r['toplam']); ?></td></tr><?php endforeach; ?></tbody></table></div></div><div class="panel"><h3>Zimmetli Kişiye Göre</h3><div class="table-wrap"><table><thead><tr><th>Zimmetli Kişi</th><th>Kayıt</th><th>Adet</th><th>Toplam Değer</th></tr></thead><tbody><?php foreach($kisiRapor as $r): ?><tr><td><?php echo asset_e($r['baslik']); ?></td><td><?php echo number_format((int)$r['kayit']); ?></td><td><?php echo number_format((float)$r['adet'],2,',','.'); ?></td><td class="money"><?php echo asset_money($r['toplam']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
</div></body></html>
