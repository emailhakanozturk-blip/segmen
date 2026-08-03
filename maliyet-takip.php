<?php
session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header('Location: login.php');
    exit;
}

function maliyet_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function maliyet_num($value): float
{
    $value = str_replace([' ', 'TL', '₺'], '', trim((string)$value));
    if(str_contains($value, ',')){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

function maliyet_money($value): string
{
    return '₺' . number_format((float)$value, 2, ',', '.');
}

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_urunler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_adi VARCHAR(180) NOT NULL UNIQUE,
    urun_grubu VARCHAR(80) NOT NULL DEFAULT 'Mamul',
    ambalaj_tipi VARCHAR(100) NULL,
    koli_ici_adet DECIMAL(12,2) NOT NULL DEFAULT 1,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_depolar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    yer_adi VARCHAR(180) NOT NULL UNIQUE,
    yer_tipi VARCHAR(40) NOT NULL DEFAULT 'Depo',
    aciklama VARCHAR(255) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kalem_adi VARCHAR(180) NOT NULL UNIQUE,
    kategori VARCHAR(80) NOT NULL DEFAULT 'Hammadde',
    birim VARCHAR(40) NULL,
    birim_fiyat DECIMAL(15,4) NOT NULL DEFAULT 0,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_receteler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL,
    urun_id INT NOT NULL,
    kalem_id INT NOT NULL,
    miktar DECIMAL(15,6) NOT NULL DEFAULT 0,
    fire_orani DECIMAL(8,4) NOT NULL DEFAULT 0,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recete_donem (donem),
    INDEX idx_recete_urun (urun_id),
    INDEX idx_recete_kalem (kalem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_stok_sayimlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL,
    depo_id INT NOT NULL,
    urun_id INT NOT NULL,
    devir DECIMAL(15,2) NOT NULL DEFAULT 0,
    giren DECIMAL(15,2) NOT NULL DEFAULT 0,
    sevk DECIMAL(15,2) NOT NULL DEFAULT 0,
    fire DECIMAL(15,2) NOT NULL DEFAULT 0,
    manuel_sayim DECIMAL(15,2) NOT NULL DEFAULT 0,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stok_donem (donem),
    INDEX idx_stok_depo (depo_id),
    INDEX idx_stok_urun (urun_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$urunSeed = [
    ['0,33 lt Standart','Mamul','Pet',24], ['0,33 lt Euro','Mamul','Pet',24],
    ['0,5 lt Standart','Mamul','Pet',24], ['0,5 lt Euro','Mamul','Pet',24],
    ['1 lt Standart','Mamul','Pet',12], ['1,5 lt Standart','Mamul','Pet',12],
    ['1,5 lt Euro','Mamul','Pet',12], ['5 lt Standart','Mamul','Pet',4],
    ['5 lt Euro','Mamul','Pet',4], ['Cam Şişe 0,33','Mamul','Cam',1],
    ['Cam Şişe 0,75','Mamul','Cam',1], ['19 lt Damacana','Mamul','Damacana',1],
    ['200 cc','Mamul','Pet',60],
];
$urunInsert = $db->prepare("INSERT IGNORE INTO maliyet_urunler (urun_adi,urun_grubu,ambalaj_tipi,koli_ici_adet) VALUES (?,?,?,?)");
foreach($urunSeed as $u){ $urunInsert->execute($u); }

$kalemSeed = ['Preform / Cam Şişe','Kapak','Kulp','Etiket','Shrink Film','Streç Film','Palet Ara Seperatör','Emniyet Bandı','Karton Koli','Koli Seperatör','Alt Folyo','Üst Folyo','730 İşçilik Hariç Gider','760 Pazarlama Gideri','770 Genel Yönetim Gideri','720 İşçilik Gideri','Nakliye','DEKAP'];
$kalemInsert = $db->prepare("INSERT IGNORE INTO maliyet_kalemleri (kalem_adi,kategori,birim) VALUES (?,?,?)");
foreach($kalemSeed as $k){
    $kategori = str_contains($k, 'Gider') || str_contains($k, 'İşçilik') ? 'Genel Gider' : 'Hammadde';
    $kalemInsert->execute([$k, $kategori, 'Adet']);
}
$db->exec("INSERT IGNORE INTO maliyet_depolar (yer_adi,yer_tipi,aciklama) VALUES ('Çiftlik Depo','Depo','Ana depo'),('Üretim Fabrikası','Fabrika','Üretim merkezi')");

$message = '';
$error = '';
$tab = (string)($_GET['tab'] ?? 'ozet');
if(!in_array($tab, ['ozet','urun','depo','kalem','recete','stok'], true)){ $tab = 'ozet'; }

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = (string)($_POST['action'] ?? '');
        if($action === 'urun_kaydet'){
            $ad = trim((string)($_POST['urun_adi'] ?? ''));
            if($ad === ''){ throw new Exception('Ürün adı zorunludur.'); }
            $db->prepare("INSERT INTO maliyet_urunler (urun_adi,urun_grubu,ambalaj_tipi,koli_ici_adet) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE urun_grubu=VALUES(urun_grubu), ambalaj_tipi=VALUES(ambalaj_tipi), koli_ici_adet=VALUES(koli_ici_adet)")
                ->execute([$ad, trim((string)($_POST['urun_grubu'] ?? 'Mamul')), trim((string)($_POST['ambalaj_tipi'] ?? '')), maliyet_num($_POST['koli_ici_adet'] ?? 1)]);
            $message = 'Ürün kaydedildi.'; $tab = 'urun';
        }
        if($action === 'depo_kaydet'){
            $ad = trim((string)($_POST['yer_adi'] ?? ''));
            if($ad === ''){ throw new Exception('Depo/Fabrika adı zorunludur.'); }
            $db->prepare("INSERT INTO maliyet_depolar (yer_adi,yer_tipi,aciklama) VALUES (?,?,?) ON DUPLICATE KEY UPDATE yer_tipi=VALUES(yer_tipi), aciklama=VALUES(aciklama)")
                ->execute([$ad, trim((string)($_POST['yer_tipi'] ?? 'Depo')), trim((string)($_POST['aciklama'] ?? ''))]);
            $message = 'Depo/Fabrika kaydedildi.'; $tab = 'depo';
        }
        if($action === 'kalem_kaydet'){
            $ad = trim((string)($_POST['kalem_adi'] ?? ''));
            if($ad === ''){ throw new Exception('Maliyet kalemi zorunludur.'); }
            $db->prepare("INSERT INTO maliyet_kalemleri (kalem_adi,kategori,birim,birim_fiyat) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE kategori=VALUES(kategori), birim=VALUES(birim), birim_fiyat=VALUES(birim_fiyat)")
                ->execute([$ad, trim((string)($_POST['kategori'] ?? 'Hammadde')), trim((string)($_POST['birim'] ?? 'Adet')), maliyet_num($_POST['birim_fiyat'] ?? 0)]);
            $message = 'Maliyet kalemi kaydedildi.'; $tab = 'kalem';
        }
        if($action === 'recete_kaydet'){
            $db->prepare("INSERT INTO maliyet_receteler (donem,urun_id,kalem_id,miktar,fire_orani,aciklama) VALUES (?,?,?,?,?,?)")
                ->execute([trim((string)($_POST['donem'] ?? date('Y-m'))), (int)$_POST['urun_id'], (int)$_POST['kalem_id'], maliyet_num($_POST['miktar'] ?? 0), maliyet_num($_POST['fire_orani'] ?? 0), trim((string)($_POST['aciklama'] ?? ''))]);
            $message = 'Reçete satırı eklendi.'; $tab = 'recete';
        }
        if($action === 'stok_kaydet'){
            $db->prepare("INSERT INTO maliyet_stok_sayimlari (donem,depo_id,urun_id,devir,giren,sevk,fire,manuel_sayim,aciklama) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([trim((string)($_POST['donem'] ?? date('Y-m'))), (int)$_POST['depo_id'], (int)$_POST['urun_id'], maliyet_num($_POST['devir'] ?? 0), maliyet_num($_POST['giren'] ?? 0), maliyet_num($_POST['sevk'] ?? 0), maliyet_num($_POST['fire'] ?? 0), maliyet_num($_POST['manuel_sayim'] ?? 0), trim((string)($_POST['aciklama'] ?? ''))]);
            $message = 'Stok sayım satırı kaydedildi.'; $tab = 'stok';
        }
    }
} catch(Throwable $e){ $error = $e->getMessage(); }

$urunler = $db->query("SELECT * FROM maliyet_urunler ORDER BY urun_adi")->fetchAll();
$depolar = $db->query("SELECT * FROM maliyet_depolar ORDER BY yer_tipi, yer_adi")->fetchAll();
$kalemler = $db->query("SELECT * FROM maliyet_kalemleri ORDER BY kategori, kalem_adi")->fetchAll();
$receteOzet = $db->query("SELECT u.urun_adi, r.donem, COUNT(*) satir, SUM(r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) maliyet FROM maliyet_receteler r JOIN maliyet_urunler u ON u.id=r.urun_id JOIN maliyet_kalemleri k ON k.id=r.kalem_id GROUP BY r.donem,u.id ORDER BY r.donem DESC,u.urun_adi")->fetchAll();
$receteler = $db->query("SELECT r.*, u.urun_adi, k.kalem_adi, k.birim_fiyat, (r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) satir_maliyeti FROM maliyet_receteler r JOIN maliyet_urunler u ON u.id=r.urun_id JOIN maliyet_kalemleri k ON k.id=r.kalem_id ORDER BY r.donem DESC,u.urun_adi,k.kalem_adi LIMIT 300")->fetchAll();
$stoklar = $db->query("SELECT s.*, u.urun_adi, d.yer_adi, (s.devir+s.giren-s.sevk-s.fire) sistem_stok, ((s.devir+s.giren-s.sevk-s.fire)-s.manuel_sayim) fark FROM maliyet_stok_sayimlari s JOIN maliyet_urunler u ON u.id=s.urun_id JOIN maliyet_depolar d ON d.id=s.depo_id ORDER BY s.donem DESC,d.yer_adi,u.urun_adi LIMIT 300")->fetchAll();
$ozet = [
    'urun' => count($urunler),
    'depo' => count($depolar),
    'kalem' => count($kalemler),
    'recete' => (int)$db->query("SELECT COUNT(*) FROM maliyet_receteler")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maliyet Takip</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .cost-tabs{display:grid;grid-template-columns:repeat(6,minmax(110px,1fr));gap:10px;margin-bottom:18px}.cost-tab{padding:12px 14px;border:1px solid #dce2ea;border-radius:8px;background:#fff;color:#1f2937;text-decoration:none;font-weight:800;font-size:12px}.cost-tab.active{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#334155}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cfd6df;border-radius:7px;padding:9px 10px;font-size:13px}.span-2{grid-column:span 2}.span-4{grid-column:span 4}.btn{border:0;border-radius:7px;padding:9px 12px;background:#2563eb;color:#fff;font-size:12px;font-weight:800;cursor:pointer}.btn-green{background:#16a34a}.notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:800}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.summary-item{background:#fff;border:1px solid #e5e7eb;border-radius:9px;padding:14px}.summary-item span{display:block;color:#64748b;font-size:11px;margin-bottom:5px}.summary-item strong{font-size:20px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px;min-width:850px}th{background:#17233b;color:#fff;text-align:left;padding:9px 8px;white-space:nowrap}td{border-bottom:1px solid #e8edf3;padding:8px;vertical-align:middle}.num{text-align:right;font-variant-numeric:tabular-nums}.hint{color:#64748b;font-size:12px;margin-top:-6px}@media(max-width:900px){.cost-tabs,.grid,.summary{grid-template-columns:1fr 1fr}.span-2,.span-4{grid-column:span 2}}@media(max-width:620px){.cost-tabs,.grid,.summary{grid-template-columns:1fr}.span-2,.span-4{grid-column:span 1}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="main">
    <div class="topbar"><h2>Maliyet Takip</h2><p>Ürün reçetesi, depo/fabrika stok sayımı ve birim maliyet takibi.</p></div>
    <?php if($message): ?><div class="notice ok"><?php echo maliyet_e($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="notice err"><?php echo maliyet_e($error); ?></div><?php endif; ?>
    <nav class="cost-tabs">
        <a class="cost-tab <?php echo $tab==='ozet'?'active':''; ?>" href="?tab=ozet">Özet</a>
        <a class="cost-tab <?php echo $tab==='urun'?'active':''; ?>" href="?tab=urun">Ürünler</a>
        <a class="cost-tab <?php echo $tab==='depo'?'active':''; ?>" href="?tab=depo">Depo / Fabrika</a>
        <a class="cost-tab <?php echo $tab==='kalem'?'active':''; ?>" href="?tab=kalem">Maliyet Kalemleri</a>
        <a class="cost-tab <?php echo $tab==='recete'?'active':''; ?>" href="?tab=recete">Reçeteler</a>
        <a class="cost-tab <?php echo $tab==='stok'?'active':''; ?>" href="?tab=stok">Stok Sayım</a>
    </nav>

    <?php if($tab==='ozet'): ?>
    <div class="summary"><div class="summary-item"><span>Ürün</span><strong><?php echo $ozet['urun']; ?></strong></div><div class="summary-item"><span>Depo/Fabrika</span><strong><?php echo $ozet['depo']; ?></strong></div><div class="summary-item"><span>Maliyet kalemi</span><strong><?php echo $ozet['kalem']; ?></strong></div><div class="summary-item"><span>Reçete satırı</span><strong><?php echo $ozet['recete']; ?></strong></div></div>
    <div class="panel"><h3>Ürün Bazlı Reçete Maliyeti</h3><p class="hint">Exceldeki mantık: hammadde + fire + genel gider/nakliye kalemleri ürün reçetesine bağlanır.</p><div class="table-wrap"><table><thead><tr><th>Dönem</th><th>Ürün</th><th>Reçete Satırı</th><th>Hesaplanan Maliyet</th></tr></thead><tbody><?php if(!$receteOzet): ?><tr><td colspan="4">Henüz reçete maliyeti girilmedi.</td></tr><?php endif; ?><?php foreach($receteOzet as $r): ?><tr><td><?php echo maliyet_e($r['donem']); ?></td><td><strong><?php echo maliyet_e($r['urun_adi']); ?></strong></td><td><?php echo (int)$r['satir']; ?></td><td class="num"><?php echo maliyet_money($r['maliyet']); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

    <?php if($tab==='urun'): ?>
    <div class="panel"><h3>Ürün Ekle</h3><form method="POST" class="grid"><input type="hidden" name="action" value="urun_kaydet"><div class="field span-2"><label>Ürün adı</label><input name="urun_adi" required></div><div class="field"><label>Ürün grubu</label><input name="urun_grubu" value="Mamul"></div><div class="field"><label>Ambalaj tipi</label><input name="ambalaj_tipi" placeholder="Pet, Cam, Damacana"></div><div class="field"><label>Koli içi adet</label><input name="koli_ici_adet" value="1"></div><div class="span-4"><button class="btn btn-green">Kaydet</button></div></form></div>
    <div class="panel"><h3>Ürün Listesi</h3><div class="table-wrap"><table><thead><tr><th>Ürün</th><th>Grup</th><th>Ambalaj</th><th>Koli İçi</th></tr></thead><tbody><?php foreach($urunler as $u): ?><tr><td><strong><?php echo maliyet_e($u['urun_adi']); ?></strong></td><td><?php echo maliyet_e($u['urun_grubu']); ?></td><td><?php echo maliyet_e($u['ambalaj_tipi']); ?></td><td class="num"><?php echo number_format((float)$u['koli_ici_adet'],2,',','.'); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

    <?php if($tab==='depo'): ?>
    <div class="panel"><h3>Depo / Fabrika Ekle</h3><form method="POST" class="grid"><input type="hidden" name="action" value="depo_kaydet"><div class="field span-2"><label>Yer adı</label><input name="yer_adi" required></div><div class="field"><label>Tip</label><select name="yer_tipi"><option>Depo</option><option>Fabrika</option></select></div><div class="field"><label>Açıklama</label><input name="aciklama"></div><div class="span-4"><button class="btn btn-green">Kaydet</button></div></form></div>
    <div class="panel"><h3>Depo / Fabrika Listesi</h3><div class="table-wrap"><table><thead><tr><th>Yer</th><th>Tip</th><th>Açıklama</th></tr></thead><tbody><?php foreach($depolar as $d): ?><tr><td><strong><?php echo maliyet_e($d['yer_adi']); ?></strong></td><td><?php echo maliyet_e($d['yer_tipi']); ?></td><td><?php echo maliyet_e($d['aciklama']); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

    <?php if($tab==='kalem'): ?>
    <div class="panel"><h3>Maliyet Kalemi Ekle</h3><form method="POST" class="grid"><input type="hidden" name="action" value="kalem_kaydet"><div class="field span-2"><label>Kalem adı</label><input name="kalem_adi" required></div><div class="field"><label>Kategori</label><select name="kategori"><option>Hammadde</option><option>Ambalaj</option><option>Genel Gider</option><option>Nakliye</option></select></div><div class="field"><label>Birim</label><input name="birim" value="Adet"></div><div class="field"><label>Birim fiyat</label><input name="birim_fiyat" value="0,00"></div><div class="span-4"><button class="btn btn-green">Kaydet</button></div></form></div>
    <div class="panel"><h3>Maliyet Kalemleri</h3><div class="table-wrap"><table><thead><tr><th>Kalem</th><th>Kategori</th><th>Birim</th><th>Birim Fiyat</th></tr></thead><tbody><?php foreach($kalemler as $k): ?><tr><td><strong><?php echo maliyet_e($k['kalem_adi']); ?></strong></td><td><?php echo maliyet_e($k['kategori']); ?></td><td><?php echo maliyet_e($k['birim']); ?></td><td class="num"><?php echo maliyet_money($k['birim_fiyat']); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

    <?php if($tab==='recete'): ?>
    <div class="panel"><h3>Reçete Satırı Ekle</h3><form method="POST" class="grid"><input type="hidden" name="action" value="recete_kaydet"><div class="field"><label>Dönem</label><input name="donem" value="<?php echo date('Y-m'); ?>"></div><div class="field"><label>Ürün</label><select name="urun_id" required><?php foreach($urunler as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo maliyet_e($u['urun_adi']); ?></option><?php endforeach; ?></select></div><div class="field"><label>Maliyet kalemi</label><select name="kalem_id" required><?php foreach($kalemler as $k): ?><option value="<?php echo (int)$k['id']; ?>"><?php echo maliyet_e($k['kalem_adi']); ?></option><?php endforeach; ?></select></div><div class="field"><label>Miktar</label><input name="miktar" value="0"></div><div class="field"><label>Fire %</label><input name="fire_orani" value="0"></div><div class="field span-2"><label>Açıklama</label><input name="aciklama"></div><div class="span-4"><button class="btn btn-green">Reçeteye Ekle</button></div></form></div>
    <div class="panel"><h3>Reçete Detayları</h3><div class="table-wrap"><table><thead><tr><th>Dönem</th><th>Ürün</th><th>Kalem</th><th>Miktar</th><th>Birim Fiyat</th><th>Fire %</th><th>Satır Maliyeti</th></tr></thead><tbody><?php if(!$receteler): ?><tr><td colspan="7">Henüz reçete satırı yok.</td></tr><?php endif; ?><?php foreach($receteler as $r): ?><tr><td><?php echo maliyet_e($r['donem']); ?></td><td><?php echo maliyet_e($r['urun_adi']); ?></td><td><?php echo maliyet_e($r['kalem_adi']); ?></td><td class="num"><?php echo number_format((float)$r['miktar'],6,',','.'); ?></td><td class="num"><?php echo maliyet_money($r['birim_fiyat']); ?></td><td class="num"><?php echo number_format((float)$r['fire_orani'],2,',','.'); ?></td><td class="num"><strong><?php echo maliyet_money($r['satir_maliyeti']); ?></strong></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

    <?php if($tab==='stok'): ?>
    <div class="panel"><h3>Stok Sayım Satırı Ekle</h3><form method="POST" class="grid"><input type="hidden" name="action" value="stok_kaydet"><div class="field"><label>Dönem</label><input name="donem" value="<?php echo date('Y-m'); ?>"></div><div class="field"><label>Depo/Fabrika</label><select name="depo_id" required><?php foreach($depolar as $d): ?><option value="<?php echo (int)$d['id']; ?>"><?php echo maliyet_e($d['yer_adi']); ?></option><?php endforeach; ?></select></div><div class="field"><label>Ürün/Malzeme</label><select name="urun_id" required><?php foreach($urunler as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo maliyet_e($u['urun_adi']); ?></option><?php endforeach; ?></select></div><div class="field"><label>Devir</label><input name="devir" value="0"></div><div class="field"><label>Üretilen/Giren</label><input name="giren" value="0"></div><div class="field"><label>Sevk Edilen</label><input name="sevk" value="0"></div><div class="field"><label>Fire</label><input name="fire" value="0"></div><div class="field"><label>Manuel Sayım</label><input name="manuel_sayim" value="0"></div><div class="field span-4"><label>Açıklama</label><input name="aciklama"></div><div class="span-4"><button class="btn btn-green">Sayım Kaydet</button></div></form></div>
    <div class="panel"><h3>Stok Sayım Listesi</h3><div class="table-wrap"><table><thead><tr><th>Dönem</th><th>Depo/Fabrika</th><th>Ürün</th><th>Devir</th><th>Giren</th><th>Sevk</th><th>Fire</th><th>Sistem Stok</th><th>Manuel Sayım</th><th>Fark</th></tr></thead><tbody><?php if(!$stoklar): ?><tr><td colspan="10">Henüz stok sayımı yok.</td></tr><?php endif; ?><?php foreach($stoklar as $s): ?><tr><td><?php echo maliyet_e($s['donem']); ?></td><td><?php echo maliyet_e($s['yer_adi']); ?></td><td><?php echo maliyet_e($s['urun_adi']); ?></td><td class="num"><?php echo number_format((float)$s['devir'],2,',','.'); ?></td><td class="num"><?php echo number_format((float)$s['giren'],2,',','.'); ?></td><td class="num"><?php echo number_format((float)$s['sevk'],2,',','.'); ?></td><td class="num"><?php echo number_format((float)$s['fire'],2,',','.'); ?></td><td class="num"><strong><?php echo number_format((float)$s['sistem_stok'],2,',','.'); ?></strong></td><td class="num"><?php echo number_format((float)$s['manuel_sayim'],2,',','.'); ?></td><td class="num"><?php echo number_format((float)$s['fark'],2,',','.'); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>
</div>
</body>
</html>
