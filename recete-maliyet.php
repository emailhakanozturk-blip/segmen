<?php
session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header('Location: login.php');
    exit;
}

function rm_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function rm_money($value): string { return '₺' . number_format((float)$value, 2, ',', '.'); }
if(!function_exists('str_contains')){
    function str_contains($haystack, $needle){ return $needle === '' || strpos((string)$haystack, (string)$needle) !== false; }
}
function rm_lower($value): string { return function_exists('mb_strtolower') ? mb_strtolower((string)$value, 'UTF-8') : strtolower((string)$value); }
function rm_upper($value): string { return function_exists('mb_strtoupper') ? mb_strtoupper((string)$value, 'UTF-8') : strtoupper((string)$value); }
function rm_currency_num($value): float
{
    $value = str_replace([' ', 'TL', '₺', '$', '€', 'USD', 'EUR'], '', trim((string)$value));
    if(str_contains($value, ',')){ $value = str_replace('.', '', $value); $value = str_replace(',', '.', $value); }
    return (float)$value;
}
function rm_num($value): float
{
    $value = str_replace([' ', 'TL', '₺'], '', trim((string)$value));
    if(str_contains($value, ',')){ $value = str_replace('.', '', $value); $value = str_replace(',', '.', $value); }
    return (float)$value;
}
function rm_urun_kodu(string $name, int $id): string
{
    $n = strtolower(str_replace(['?','I','?','?','?','?','?'], ['i','i','?','?','?','?','?'], $name));
    if(str_contains($n, '0,33') && str_contains($n, 'pet')){ return 'SU-PET-033'; }
    if((str_contains($n, '0,50') || str_contains($n, '0,5')) && str_contains($n, 'pet')){ return 'SU-PET-050'; }
    if(str_contains($n, '1 l') && str_contains($n, 'pet')){ return 'SU-PET-100'; }
    if(str_contains($n, '1,5') && str_contains($n, 'pet')){ return 'SU-PET-150'; }
    if(str_contains($n, '5 l') && str_contains($n, 'pet')){ return 'SU-PET-500'; }
    if(str_contains($n, '0,33') && str_contains($n, 'cam')){ return 'SU-CAM-033'; }
    if(str_contains($n, '0,75') && str_contains($n, 'cam')){ return 'SU-CAM-075'; }
    if(str_contains($n, '19') && str_contains($n, 'damacana')){ return 'SU-DAM-1900'; }
    if((str_contains($n, '200 ml') || str_contains($n, '200 cc')) && str_contains($n, 'bardak')){ return 'SU-BAR-200'; }
    if(str_contains($n, '0,33')){ return str_contains($n, 'euro') ? 'UR-033-EUR' : 'UR-033-STD'; }
    if(str_contains($n, '0,5') || str_contains($n, '0,50')){ return str_contains($n, 'euro') ? 'UR-050-EUR' : 'UR-050-STD'; }
    if(str_contains($n, '1,5')){ return str_contains($n, 'euro') ? 'UR-150-EUR' : 'UR-150-STD'; }
    if(str_contains($n, '1 lt')){ return 'UR-100-STD'; }
    if(str_contains($n, '5 lt')){ return str_contains($n, 'euro') ? 'UR-500-EUR' : 'UR-500-STD'; }
    if(str_contains($n, '19')){ return 'UR-190-STD'; }
    return 'UR-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}
function rm_urun_kodu_satir(array $u): string
{
    return trim((string)($u['urun_kodu'] ?? '')) !== '' ? (string)$u['urun_kodu'] : rm_urun_kodu((string)($u['urun_adi'] ?? ''), (int)($u['id'] ?? 0));
}
function rm_urun_grubu(array $u): array
{
    $txt = rm_lower(($u['urun_adi'] ?? '') . ' ' . ($u['urun_grubu'] ?? '') . ' ' . ($u['ambalaj_tipi'] ?? ''));
    if(str_contains($txt, 'cam')){ return ['cam','Cam Ürünler','Cam ambalajlı ürünler']; }
    if(str_contains($txt, 'damacana') || str_contains($txt, '19')){ return ['damacana','Damacana','Polikarbon damacana ürünleri']; }
    if(str_contains($txt, 'bardak') || str_contains($txt, '200 cc')){ return ['bardak','Bardak','Tek kullanımlık bardak ürünleri']; }
    if(str_contains($txt, 'bidon') || str_contains($txt, '5 lt') || str_contains($txt, '10 lt')){ return ['bidon','Bidon / Büyük Hacimli','Yüksek hacimli PET ürünleri']; }
    return ['pet','PET Şişeler','PET ambalajlı ürünler'];
}
function rm_urun_hacim(array $u): string
{
    if(preg_match('/^([0-9]+(:,[0-9]+)|[0-9]+(:\.[0-9]+))\s*(ml|l)/iu', (string)$u['urun_adi'], $m)){
        return str_replace('.', ',', $m[1]).' '.rm_lower($m[2]);
    }
    return '';
}
function rm_urun_varyant(array $u): string
{
    $a = rm_lower((string)($u['ambalaj_tipi'] ?? ''));
    if(str_contains($a, 'pet')) return 'Standart PET';
    if(str_contains($a, 'cam')) return 'Cam Şişe';
    if(str_contains($a, 'damacana')) return 'Damacana';
    if(str_contains($a, 'bardak')) return 'Bardak';
    return (string)($u['urun_grubu'] ?? '');
}
function rm_stok_sync(PDO $db, string $donem, int $urunId): void
{
    $depoId = (int)$db->query("SELECT id FROM maliyet_depolar ORDER BY id LIMIT 1")->fetchColumn();
    if($depoId <= 0){
        $db->exec("INSERT IGNORE INTO maliyet_depolar (yer_adi,yer_tipi,aciklama) VALUES ('Genel Stok','Depo','Otomatik stok hareket deposu')");
        $depoId = (int)$db->query("SELECT id FROM maliyet_depolar ORDER BY id LIMIT 1")->fetchColumn();
    }
    $q = $db->prepare("SELECT
        SUM(CASE WHEN hareket_tipi IN ('uretilen','iade_giris') THEN miktar ELSE 0 END) giren,
        SUM(CASE WHEN hareket_tipi IN ('birimler_arasi_sevk','bayi_sevk','zincir_market_sevk') THEN miktar ELSE 0 END) sevk,
        SUM(CASE WHEN hareket_tipi='fire' THEN miktar ELSE 0 END) fire
        FROM recete_stok_hareketleri WHERE donem=? AND urun_id=?");
    $q->execute([$donem,$urunId]);
    $r = $q->fetch() ?: ['giren'=>0,'sevk'=>0,'fire'=>0];
    $find = $db->prepare("SELECT id FROM maliyet_stok_sayimlari WHERE donem=? AND depo_id=? AND urun_id=? ORDER BY id LIMIT 1");
    $find->execute([$donem,$depoId,$urunId]);
    $id = (int)$find->fetchColumn();
    if($id > 0){
        $db->prepare("UPDATE maliyet_stok_sayimlari SET giren=?, sevk=?, fire=?, aciklama=? WHERE id=?")
            ->execute([(float)$r['giren'],(float)$r['sevk'],(float)$r['fire'],'Stok hareketlerinden otomatik g?ncellendi',$id]);
    } else {
        $db->prepare("INSERT INTO maliyet_stok_sayimlari (donem,depo_id,urun_id,giren,sevk,fire,aciklama) VALUES (?,?,?,?,?,?,?)")
            ->execute([$donem,$depoId,$urunId,(float)$r['giren'],(float)$r['sevk'],(float)$r['fire'],'Stok hareketlerinden otomatik g?ncellendi']);
    }
}

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_urunler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_kodu VARCHAR(40) NULL UNIQUE,
    urun_adi VARCHAR(180) NOT NULL,
    urun_grubu VARCHAR(80) NULL,
    ambalaj_tipi VARCHAR(80) NULL,
    koli_ici_adet DECIMAL(12,2) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kategori VARCHAR(80) NOT NULL DEFAULT 'Hammadde',
    kalem_adi VARCHAR(180) NOT NULL,
    birim VARCHAR(40) NULL,
    birim_fiyat DECIMAL(15,6) NOT NULL DEFAULT 0,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_maliyet_kalem (kategori, kalem_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_receteler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL,
    urun_id INT NOT NULL,
    kalem_id INT NOT NULL,
    miktar DECIMAL(15,6) NOT NULL DEFAULT 0,
    fire_orani DECIMAL(8,4) NOT NULL DEFAULT 0,
    satir_tutari DECIMAL(15,4) NOT NULL DEFAULT 0,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maliyet_recete_donem (donem),
    INDEX idx_maliyet_recete_urun (urun_id),
    INDEX idx_maliyet_recete_kalem (kalem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_fiyatlama (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiyat_tarihi DATE NOT NULL,
    kategori VARCHAR(80) NOT NULL,
    urun_adi VARCHAR(180) NOT NULL,
    ton_fiyati VARCHAR(80) NULL,
    doviz_adet VARCHAR(80) NULL,
    tl_adet DECIMAL(15,7) NOT NULL DEFAULT 0,
    cam_sise_033 DECIMAL(15,4) NOT NULL DEFAULT 0,
    cam_sise_075 DECIMAL(15,4) NOT NULL DEFAULT 0,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_maliyet_fiyat (fiyat_tarihi, kategori, urun_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS recete_donemler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL UNIQUE,
    donem_adi VARCHAR(80) NOT NULL,
    toplam_uretim DECIMAL(15,2) NOT NULL DEFAULT 0,
    durum VARCHAR(20) NOT NULL DEFAULT 'A??k',
    son_hesaplama DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS recete_uretim (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL,
    urun_adi VARCHAR(180) NOT NULL,
    koli_miktari DECIMAL(15,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_recete_uretim (donem, urun_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS recete_nakliye_dekap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL,
    urun_adi VARCHAR(180) NOT NULL,
    nakliye_tl_koli DECIMAL(15,4) NOT NULL DEFAULT 0,
    dekap_tl_koli DECIMAL(15,4) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_recete_nd (donem, urun_adi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS recete_bom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_id INT NOT NULL,
    donem VARCHAR(20) NOT NULL,
    versiyon VARCHAR(20) NOT NULL DEFAULT 'v1.0',
    aciklama VARCHAR(255) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bom (urun_id, donem, versiyon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS recete_bom_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recete_id INT NOT NULL,
    hammadde_id INT NOT NULL,
    tuketim_miktari DECIMAL(15,6) NOT NULL DEFAULT 0,
    tuketim_birimi VARCHAR(30) NOT NULL DEFAULT 'adet/koli',
    koli_ici_adet DECIMAL(12,2) NOT NULL DEFAULT 1,
    fire_orani DECIMAL(8,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bom_kalem_recete (recete_id),
    INDEX idx_bom_kalem_hammadde (hammadde_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS maliyet_depolar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    yer_adi VARCHAR(180) NOT NULL UNIQUE,
    yer_tipi VARCHAR(40) NOT NULL DEFAULT 'Depo',
    aciklama VARCHAR(255) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
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

$db->exec("CREATE TABLE IF NOT EXISTS recete_stok_hareketleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL,
    tarih DATE NOT NULL,
    belge_no VARCHAR(80) NULL,
    urun_id INT NOT NULL,
    hareket_tipi VARCHAR(40) NOT NULL,
    miktar DECIMAL(15,2) NOT NULL DEFAULT 0,
    cikis_depo VARCHAR(180) NULL,
    varis_depo VARCHAR(180) NULL,
    aciklama VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rsh_donem (donem),
    INDEX idx_rsh_urun (urun_id),
    INDEX idx_rsh_tip (hareket_tipi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
try { $db->exec("ALTER TABLE maliyet_urunler ADD COLUMN urun_kodu VARCHAR(40) NULL AFTER id"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE maliyet_urunler ADD UNIQUE KEY uniq_maliyet_urun_kodu (urun_kodu)"); } catch(Throwable $e) {}

$db->exec("INSERT INTO recete_donemler (donem,donem_adi,toplam_uretim,durum,son_hesaplama) VALUES ('2026-04','Nisan 2026',600000,'Açık',NOW()) ON DUPLICATE KEY UPDATE donem_adi=VALUES(donem_adi), durum=VALUES(durum)");
$uretimSeed = [
    ['2026-04','0,33 L Pet Şişe Su',1672], ['2026-04','0,50 L Pet Şişe Su',114548],
    ['2026-04','1,5 L Pet Şişe Su',52193], ['2026-04','5 L Pet Şişe Su',4011],
    ['2026-04','19 L Damacana Su',109104], ['2026-04','200 ml Bardak Su',0],
];
$uretimIns = $db->prepare("INSERT INTO recete_uretim (donem,urun_adi,koli_miktari) VALUES (?,?,?) ON DUPLICATE KEY UPDATE koli_miktari=VALUES(koli_miktari)");
foreach($uretimSeed as $u){ $uretimIns->execute($u); }
$db->exec("UPDATE recete_uretim SET koli_miktari=1672 WHERE donem='2026-04' AND urun_adi LIKE '%0,33%'");

$tab = (string)($_GET['tab'] ?? 'ozet');
if(!in_array($tab, ['ozet','urunler','hammaddeler','fiyatlar','receteler','uretim','giderler','nakliye','stok_hareketleri'], true)){ $tab = 'ozet'; }
$donem = (string)($_GET['donem'] ?? '2026-04');
$selectedId = (int)($_POST['urun_id'] ?? ($_GET['urun_id'] ?? 0));
$message = '';
$error = '';

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = (string)($_POST['action'] ?? '');
        if($action === 'urun_kaydet'){
            $ad = trim((string)($_POST['urun_adi'] ?? ''));
            if($ad === ''){ throw new RuntimeException('Ürün adı boş olamaz.'); }
            $kod = trim((string)($_POST['urun_kodu'] ?? ''));
            $db->prepare("INSERT INTO maliyet_urunler (urun_kodu,urun_adi,urun_grubu,ambalaj_tipi,koli_ici_adet) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE urun_adi=VALUES(urun_adi), urun_grubu=VALUES(urun_grubu), ambalaj_tipi=VALUES(ambalaj_tipi), koli_ici_adet=VALUES(koli_ici_adet)")
                ->execute([$kod !== '' ? $kod : null, $ad, trim((string)($_POST['urun_grubu'] ?? 'PET Şişeler')), trim((string)($_POST['ambalaj_tipi'] ?? 'PET')), rm_num($_POST['koli_ici_adet'] ?? 1)]);
            $tab = 'urunler';
            $message = 'Ürün kaydedildi.';
        } elseif($action === 'fiyat_kaydet'){
            $ad = trim((string)($_POST['urun_adi'] ?? ''));
            if($ad === ''){ throw new RuntimeException('Ürün fiyat adı boş olamaz.'); }
            $db->prepare("INSERT INTO maliyet_fiyatlama (fiyat_tarihi,kategori,urun_adi,ton_fiyati,doviz_adet,tl_adet,cam_sise_033,cam_sise_075,aciklama) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kategori=VALUES(kategori), ton_fiyati=VALUES(ton_fiyati), doviz_adet=VALUES(doviz_adet), tl_adet=VALUES(tl_adet), cam_sise_033=VALUES(cam_sise_033), cam_sise_075=VALUES(cam_sise_075), aciklama=VALUES(aciklama)")
                ->execute([trim((string)($_POST['fiyat_tarihi'] ?? date('Y-m-d'))), trim((string)($_POST['kategori'] ?? 'Ürün')), $ad, trim((string)($_POST['ton_fiyati'] ?? '')), trim((string)($_POST['doviz_adet'] ?? '')), rm_num($_POST['tl_adet'] ?? 0), rm_num($_POST['cam_sise_033'] ?? 0), rm_num($_POST['cam_sise_075'] ?? 0), trim((string)($_POST['aciklama'] ?? ''))]);
            $tab = 'urunler';
            $message = 'Fiyat kaydedildi.';
        } elseif($action === 'stok_hareket_kaydet'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $tip = trim((string)($_POST['hareket_tipi'] ?? ''));
            if($urunId <= 0 || $tip === ''){ throw new RuntimeException('Stok hareketi i?in ?r?n ve hareket tipi se?in.'); }
            $db->prepare("INSERT INTO recete_stok_hareketleri (donem,tarih,belge_no,urun_id,hareket_tipi,miktar,cikis_depo,varis_depo,aciklama) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$donem, trim((string)($_POST['tarih'] ?? date('Y-m-d'))), trim((string)($_POST['belge_no'] ?? '')), $urunId, $tip, rm_num($_POST['miktar'] ?? 0), trim((string)($_POST['cikis_depo'] ?? '')), trim((string)($_POST['varis_depo'] ?? '')), trim((string)($_POST['aciklama'] ?? ''))]);
            rm_stok_sync($db,$donem,$urunId);
            $tab = 'stok_hareketleri';
            $message = 'Stok hareketi kaydedildi ve stok say?m?na aktar?ld?.';
        } elseif($action === 'stok_hareket_sil'){
            $id = (int)($_POST['hareket_id'] ?? 0);
            $q = $db->prepare("SELECT donem,urun_id FROM recete_stok_hareketleri WHERE id=");
            $q->execute([$id]);
            $old = $q->fetch();
            if($old){
                $db->prepare("DELETE FROM recete_stok_hareketleri WHERE id=")->execute([$id]);
                rm_stok_sync($db,(string)$old['donem'],(int)$old['urun_id']);
            }
            $tab = 'stok_hareketleri';
            $message = 'Stok hareketi silindi.';
        } elseif($action === 'urun_guncelle'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $ad = trim((string)($_POST['urun_adi'] ?? ''));
            if($urunId <= 0 || $ad === ''){ throw new RuntimeException('Ürün bilgisi eksik.'); }
            $db->prepare("UPDATE maliyet_urunler SET urun_kodu=?, urun_adi=?, urun_grubu=?, ambalaj_tipi=?, koli_ici_adet=? WHERE id=?")
                ->execute([trim((string)($_POST['urun_kodu'] ?? '')), $ad, trim((string)($_POST['urun_grubu'] ?? 'PET Şişeler')), trim((string)($_POST['ambalaj_tipi'] ?? '')), rm_num($_POST['koli_ici_adet'] ?? 1), $urunId]);
            $tab = 'urunler';
            $message = 'Ürün güncellendi.';
        } elseif($action === 'urun_sil'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $q = $db->prepare("SELECT urun_adi FROM maliyet_urunler WHERE id=?");
            $q->execute([$urunId]);
            $urunAdi = (string)$q->fetchColumn();
            if($urunAdi === ''){ throw new RuntimeException('Ürün bulunamadı.'); }
            $checks = [
                [$db->prepare("SELECT COUNT(*) FROM maliyet_receteler WHERE urun_id=?"), [$urunId]],
                [$db->prepare("SELECT COUNT(*) FROM recete_bom WHERE urun_id=?"), [$urunId]],
                [$db->prepare("SELECT COUNT(*) FROM recete_uretim WHERE urun_adi=?"), [$urunAdi]],
                [$db->prepare("SELECT COUNT(*) FROM recete_nakliye_dekap WHERE urun_adi=?"), [$urunAdi]],
            ];
            $related = 0;
            foreach($checks as $c){ $c[0]->execute($c[1]); $related += (int)$c[0]->fetchColumn(); }
            if($related > 0){ throw new RuntimeException('Bu ürün reçete, üretim veya maliyet kayıtlarıyla ilişkili olduğu için silinemedi.'); }
            $db->prepare("DELETE FROM maliyet_urunler WHERE id=?")->execute([$urunId]);
            $tab = 'urunler';
            $message = 'Ürün silindi.';
        } elseif($action === 'bom_kaydet' || $action === 'bom_kopyala'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $versiyon = trim((string)($_POST['versiyon'] ?? 'v1.0'));
            if($action === 'bom_kopyala'){
                $num = (float)str_replace('v','',$versiyon);
                $versiyon = 'v' . number_format($num + 0.1, 1, '.', '');
            }
            $aciklama = trim((string)($_POST['aciklama'] ?? ''));
            $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1")
                ->execute([$urunId,$donem,$versiyon,$aciklama]);
            $receteId = (int)$db->lastInsertId();
            if(!$receteId){
                $q = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem=? AND versiyon=?");
                $q->execute([$urunId,$donem,$versiyon]);
                $receteId = (int)$q->fetchColumn();
            }
            $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=")->execute([$receteId]);
            $hammaddeIds = $_POST['hammadde_id'] ?? [];
            foreach($hammaddeIds as $i => $hid){
                $hid = (int)$hid;
                if($hid <= 0){ continue; }
                $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,tuketim_miktari,tuketim_birimi,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?)")
                    ->execute([$receteId,$hid,rm_num($_POST['tuketim_miktari'][$i] ?? 0),trim((string)($_POST['tuketim_birimi'][$i] ?? 'adet/koli')),rm_num($_POST['koli_ici_adet'][$i] ?? 1),rm_num($_POST['fire_orani'][$i] ?? 0)]);
            }
            $selectedId = $urunId;
            $tab = 'receteler';
            $message = $action === 'bom_kopyala' ? 'Reçete yeni versiyon olarak kopyalandı.' : 'Reçete kaydedildi.';
        }
    }
} catch(Throwable $e){ $error = $e->getMessage(); }

$donemler = $db->query("SELECT * FROM recete_donemler ORDER BY donem DESC")->fetchAll();
$currentDonem = $db->prepare("SELECT * FROM recete_donemler WHERE donem=?");
$currentDonem->execute([$donem]);
$currentDonem = $currentDonem->fetch() ?: ['donem_adi'=>'Nisan 2026','toplam_uretim'=>600000,'durum'=>'Açık','son_hesaplama'=>date('Y-m-d H:i:s')];

$costSql = "
    SELECT
        u.id, u.urun_adi,
        SUM(CASE WHEN k.kategori='Hammadde' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) hammadde,
        SUM(CASE WHEN k.kalem_adi LIKE '720%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g720,
        SUM(CASE WHEN k.kalem_adi LIKE '730%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g730,
        SUM(CASE WHEN k.kalem_adi LIKE '760%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g760,
        SUM(CASE WHEN k.kalem_adi LIKE 'GENEL Y?NET?M%' OR k.kalem_adi LIKE '770%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g770,
        SUM(COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100))) toplam
    FROM maliyet_receteler r
    JOIN maliyet_urunler u ON u.id=r.urun_id
    JOIN maliyet_kalemleri k ON k.id=r.kalem_id
    WHERE r.donem=?
    GROUP BY u.id,u.urun_adi
    ORDER BY u.urun_adi";
$stmt = $db->prepare($costSql);
$stmt->execute([$donem]);
$urunMaliyetleri = $stmt->fetchAll();
if(!$selectedId && $urunMaliyetleri){ $selectedId = (int)$urunMaliyetleri[0]['id']; }

$selected = null;
foreach($urunMaliyetleri as $u){ if((int)$u['id'] === $selectedId){ $selected = $u; break; } }
$detayStmt = $db->prepare("SELECT k.kategori,k.kalem_adi,k.birim_fiyat,r.satir_tutari,COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) tutar,r.miktar,r.fire_orani,r.aciklama FROM maliyet_receteler r JOIN maliyet_kalemleri k ON k.id=r.kalem_id WHERE r.donem=? AND r.urun_id=? ORDER BY k.kategori,k.kalem_adi");
$detayStmt->execute([$donem,$selectedId]);
$detaylar = $detayStmt->fetchAll();

$fiyatlar = $db->query("SELECT * FROM maliyet_fiyatlama ORDER BY fiyat_tarihi DESC,kategori,urun_adi")->fetchAll();
$kurOzet = ['usd'=>0, 'eur'=>0];
$fiyatHareketleri = [];
$olderPriceByName = [];
foreach(array_reverse($fiyatlar) as $old){
    $name = (string)$old['urun_adi'];
    $tl = (float)($old['tl_adet'] ?? 0);
    $olderPriceByName[$name][] = $tl;
}
foreach($fiyatlar as $f){
    $doviz = rm_currency_num($f['doviz_adet'] ?? 0);
    $tl = (float)($f['tl_adet'] ?? 0);
    $ton = (string)($f['ton_fiyati'] ?? '');
    if($doviz > 0 && $tl > 0){
        if($kurOzet['usd'] <= 0 && str_contains($ton . ($f['doviz_adet'] ?? ''), '$')){ $kurOzet['usd'] = $tl / $doviz; }
        if($kurOzet['eur'] <= 0 && str_contains($ton . ($f['doviz_adet'] ?? ''), '€')){ $kurOzet['eur'] = $tl / $doviz; }
    }
    $name = (string)$f['urun_adi'];
    $history = $olderPriceByName[$name] ?? [];
    $pos = array_search($tl, $history, true);
    $prev = ($pos !== false && $pos > 0) ? $history[$pos - 1] : null;
    $f['hareket'] = $prev === null ? 0 : $tl - (float)$prev;
    $fiyatHareketleri[] = $f;
}
$urunler = $db->query("SELECT * FROM maliyet_urunler ORDER BY urun_adi")->fetchAll();
$depolar = $db->query("SELECT * FROM maliyet_depolar ORDER BY yer_tipi,yer_adi")->fetchAll();
$hammaddeler = $db->query("SELECT * FROM maliyet_kalemleri WHERE kategori='Hammadde' ORDER BY id")->fetchAll();
$bomDurumStmt = $db->prepare("SELECT b.urun_id, COUNT(k.id) satir FROM recete_bom b LEFT JOIN recete_bom_kalemleri k ON k.recete_id=b.id WHERE b.donem=? AND b.aktif=1 GROUP BY b.urun_id");
$bomDurumStmt->execute([$donem]);
$bomDurum = [];
foreach($bomDurumStmt->fetchAll() as $bd){ $bomDurum[(int)$bd['urun_id']] = (int)$bd['satir']; }
$urunGruplari = [];
foreach($urunler as $u){
    $g = rm_urun_grubu($u);
    if(!isset($urunGruplari[$g[0]])){ $urunGruplari[$g[0]] = ['baslik'=>$g[1], 'aciklama'=>$g[2], 'urunler'=>[]]; }
    $urunGruplari[$g[0]]['urunler'][] = $u;
}
$fiyatlarByGroup = [];
foreach($urunGruplari as $key=>$g){
    $names = array_map(function($u){ return rm_lower($u['urun_adi']); }, $g['urunler']);
    $fiyatlarByGroup[$key] = array_values(array_filter($fiyatHareketleri, function($f) use ($names){
        $fn = rm_lower($f['urun_adi'] ?? '');
        foreach($names as $n){ if($n !== '' && (str_contains($fn, $n) || str_contains($n, $fn))){ return true; } }
        return false;
    }));
}
$urunKpi = [
    'toplam' => count($urunler),
    'aktif' => count($urunler),
    'grup' => count($urunGruplari),
    'eksik' => count(array_filter($urunler, function($u) use ($bomDurum){ return ($bomDurum[(int)$u['id']] ?? 0) <= 0; })),
];
$selectedProductName = (string)($selected['urun_adi'] ?? '');
$selectedProductCode = '';
$selectedKoliIci = 24;
if($selectedId > 0 && $selectedProductName === ''){
    foreach($urunler as $u){ if((int)$u['id'] === $selectedId){ $selectedProductName = (string)$u['urun_adi']; $selectedProductCode = rm_urun_kodu_satir($u); $selectedKoliIci = (float)$u['koli_ici_adet']; break; } }
} elseif($selectedId > 0) {
    foreach($urunler as $u){ if((int)$u['id'] === $selectedId){ $selectedProductCode = rm_urun_kodu_satir($u); $selectedKoliIci = (float)$u['koli_ici_adet']; break; } }
}
if($selectedProductCode === '' && $selectedProductName !== ''){ $selectedProductCode = rm_urun_kodu($selectedProductName, $selectedId); }
$activeBom = null;
$bomKalemleri = [];
if($selectedId > 0){
    $bomQ = $db->prepare("SELECT * FROM recete_bom WHERE urun_id=? AND donem=? AND aktif=1 ORDER BY id DESC LIMIT 1");
    $bomQ->execute([$selectedId,$donem]);
    $activeBom = $bomQ->fetch();
        if(!$activeBom){
            $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,?,?,?,1)")->execute([$selectedId,$donem,'v1.0',$currentDonem['donem_adi'].' Standart Reçetesi']);
        $activeBom = ['id'=>(int)$db->lastInsertId(),'urun_id'=>$selectedId,'donem'=>$donem,'versiyon'=>'v1.0','aciklama'=>$currentDonem['donem_adi'].' Standart Reçetesi'];
        $defaultNames = ['Preform / Cam Şişe'=>[9.2,3],'Kapak'=>[1,1.5],'Etiket'=>[1,2],'Shrink Film'=>[26,3.5],'Streç Film'=>[8.8,2]];
        foreach($hammaddeler as $h){
            foreach($defaultNames as $name=>$def){
                if(stripos($h['kalem_adi'],$name) !== false || $h['kalem_adi'] === $name){
                    $unit = in_array($name, ['Kapak','Etiket'], true) ? 'adet/adet' : ($name === 'Preform / Cam ?i?e' ? 'gr/adet' : 'gr/koli');
                    $koli = $unit === 'gr/adet' || $unit === 'adet/adet' ? 24 : 1;
                    $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,tuketim_miktari,tuketim_birimi,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?)")
                        ->execute([(int)$activeBom['id'],(int)$h['id'],$def[0],$unit,$koli,$def[1]]);
                    break;
                }
            }
        }
    }
    $bk = $db->prepare("SELECT b.*, k.kalem_adi, k.kategori FROM recete_bom_kalemleri b JOIN maliyet_kalemleri k ON k.id=b.hammadde_id WHERE b.recete_id=? ORDER BY b.id");
    $bk->execute([(int)$activeBom['id']]);
    $bomKalemleri = $bk->fetchAll();
}
$uretimler = $db->prepare("SELECT * FROM recete_uretim WHERE donem=? ORDER BY urun_adi");
$uretimler->execute([$donem]);
$uretimler = $uretimler->fetchAll();
$uretimByName = [];
foreach($uretimler as $u){ $uretimByName[rm_lower($u['urun_adi'])] = $u; }
$uretimler = [];
foreach($urunler as $u){
    $old = $uretimByName[rm_lower($u['urun_adi'])] ?? null;
    $uretimler[] = [
        'donem' => $donem,
        'urun_adi' => $u['urun_adi'],
        'urun_kodu' => rm_urun_kodu_satir($u),
        'koli_miktari' => $old ? (float)$old['koli_miktari'] : 0,
    ];
}
$ndStmt = $db->prepare("SELECT * FROM recete_nakliye_dekap WHERE donem=?");
$ndStmt->execute([$donem]);
$ndByProduct = [];
foreach($ndStmt->fetchAll() as $n){ $ndByProduct[$n['urun_adi']] = $n; }
$stokHareketStmt = $db->prepare("SELECT h.*, u.urun_adi, u.koli_ici_adet FROM recete_stok_hareketleri h JOIN maliyet_urunler u ON u.id=h.urun_id WHERE h.donem=? ORDER BY h.tarih DESC,h.id DESC LIMIT 500");
$stokHareketStmt->execute([$donem]);
$stokHareketleri = $stokHareketStmt->fetchAll();
$stokKpi = ['uretilen'=>0,'zincir_market_sevk'=>0,'bayi_sevk'=>0,'birimler_arasi_sevk'=>0,'fire'=>0,'iade_giris'=>0];
foreach($stokHareketleri as $h){ if(isset($stokKpi[$h['hareket_tipi']])){ $stokKpi[$h['hareket_tipi']] += (float)$h['miktar']; } }
$stokMevcut = $stokKpi['uretilen'] + $stokKpi['iade_giris'] - $stokKpi['zincir_market_sevk'] - $stokKpi['bayi_sevk'] - $stokKpi['birimler_arasi_sevk'] - $stokKpi['fire'];

$qtyByProduct = [];
foreach($uretimler as $u){ $qtyByProduct[$u['urun_adi']] = (float)$u['koli_miktari']; }
$weightedTotal = 0; $weightedHammadde = 0; $weightedGider = 0; $usedQty = 0;
foreach($urunMaliyetleri as $u){
    $qty = $qtyByProduct[$u['urun_adi']] ?? 0;
    $usedQty += $qty;
    $weightedTotal += $qty * (float)$u['toplam'];
    $weightedHammadde += $qty * (float)$u['hammadde'];
    $weightedGider += $qty * ((float)$u['g720']+(float)$u['g730']+(float)$u['g760']+(float)$u['g770']);
}
$totalProduction = $usedQty > 0 ? $usedQty : (float)($currentDonem['toplam_uretim'] ?? 0);
$avgCost = $usedQty > 0 ? $weightedTotal / $usedQty : 0;
$warnings = [];
if(!$fiyatlar){ $warnings[] = 'Hammadde fiyatlar? tan?mlanmam??.'; }
if(!$urunMaliyetleri){ $warnings[] = 'Aktif reçete maliyeti bulunamadı.'; }
$selectedNd = $selected ? ($ndByProduct[$selected['urun_adi']] ?? ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0]) : ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçete ve Maliyet</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root{--navy:#111827;--slate:#64748b;--line:#e5e7eb;--blue:#2563eb;--soft:#f8fafc;--green:#16a34a;--red:#dc2626}
        body{background:#f3f6fa}.rm-page{padding:28px;max-width:1680px}.rm-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;border-radius:18px;padding:24px;margin-bottom:18px;box-shadow:0 20px 45px rgba(15,23,42,.16)}.rm-hero h1{margin:0;font-size:30px;letter-spacing:0}.rm-hero p{margin:6px 0 0;color:#dbeafe}.rm-filter{display:flex;gap:10px;align-items:center}.rm-filter select{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:10px;padding:10px 12px;font-weight:800}.rm-filter option{color:#111827}.rm-tabs{display:grid;grid-template-columns:repeat(8,minmax(105px,1fr));gap:10px;margin-bottom:16px}.rm-tabs a{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px;color:#334155;text-decoration:none;font-size:12px;font-weight:900;text-align:center}.rm-tabs a.active{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}.rm-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.rm-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.rm-card span{display:block;color:var(--slate);font-size:12px;font-weight:800}.rm-card strong{display:block;margin-top:8px;font-size:25px;color:#0f172a}.rm-change{display:inline-block;margin-top:8px;border-radius:999px;padding:4px 9px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:900}.rm-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(360px,.85fr);gap:16px}.rm-panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.rm-panel h3{margin:0 0 10px;font-size:18px}.rm-table-wrap{overflow:auto}.rm-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;min-width:820px}.rm-table th{background:#0f172a;color:#fff;text-align:left;padding:11px 10px;white-space:nowrap}.rm-table th:first-child{border-top-left-radius:10px}.rm-table th:last-child{border-top-right-radius:10px}.rm-table td{padding:11px 10px;border-bottom:1px solid #eef2f7;vertical-align:middle}.rm-table tr:hover td{background:#f8fafc}.rm-table .num{text-align:right;font-variant-numeric:tabular-nums}.rm-product{font-weight:900;color:#0f172a}.rm-material{font-size:11px;font-weight:700;color:#334155;line-height:1.25}.rm-detail-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:12px}.rm-pill{background:#eef2ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:900}.rm-break{display:grid;gap:8px}.rm-break-row{display:flex;justify-content:space-between;gap:10px;background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:9px 10px;font-size:12px}.rm-break-row b{color:#0f172a}.rm-total{margin-top:14px;background:#0f172a;color:#fff;border-radius:14px;padding:16px}.rm-total span{color:#cbd5e1;font-size:12px}.rm-total strong{display:block;font-size:28px;margin-top:4px}.rm-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:14px;padding:12px 14px;margin-bottom:16px;font-size:13px;font-weight:800}.rm-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.rm-field label{display:block;font-size:12px;font-weight:900;color:#334155;margin-bottom:6px}.rm-field input,.rm-field select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:13px}.rm-btn{border:0;border-radius:10px;background:#2563eb;color:#fff;padding:10px 13px;font-weight:900;cursor:pointer}.bom-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:18px}.bom-products{max-height:560px;overflow:auto}.bom-item{display:block;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:9px;color:#0f172a;text-decoration:none;background:#fff}.bom-item.active{border-color:#60a5fa;background:#eff6ff}.bom-code{display:block;color:#64748b;font-size:10px;font-weight:900;margin-top:4px}.bom-ok{float:right;background:#dcfce7;color:#047857;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:900}.bom-head{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:18px}.bom-title{font-size:21px;font-weight:950;color:#0f172a}.bom-editor{border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;background:#fff}.bom-actions{display:flex;gap:10px}.bom-table{width:100%;border-collapse:collapse;font-size:12px}.bom-table th{background:#eef2f7;color:#0f172a;text-align:left;padding:10px}.bom-table td{border-bottom:1px solid #edf2f7;padding:9px}.bom-table input,.bom-table select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:9px;font-size:12px}.bom-calc{font-weight:900;color:#0f172a;white-space:nowrap}.muted{color:#64748b}.green{color:var(--green)}.red{color:var(--red)}@media(max-width:1200px){.rm-grid,.rm-kpis{grid-template-columns:1fr 1fr}.rm-tabs{grid-template-columns:repeat(4,1fr)}.bom-layout{grid-template-columns:1fr}}@media(max-width:800px){.rm-page{padding:14px}.rm-grid,.rm-kpis,.rm-form{grid-template-columns:1fr}.rm-tabs{grid-template-columns:1fr 1fr}.rm-hero{display:block}.rm-filter{margin-top:14px}}
        .recipe-mode{max-width:1220px}.recipe-mode .rm-tabs{display:none}.recipe-mode .rm-hero{min-height:118px;align-items:center;background:linear-gradient(110deg,#30308d 0%,#15213e 100%);border-radius:18px;padding:26px 32px}.hero-kicker{display:block;color:#60a5fa;font-size:12px;font-weight:950;letter-spacing:.04em;margin-bottom:10px}.hero-actions{display:flex;gap:12px}.hero-actions button{min-width:170px;border:1px solid rgba(255,255,255,.22);border-radius:14px;padding:14px 18px;background:#1d5cff;color:#fff;font-weight:950;cursor:pointer}.hero-actions .copy{background:rgba(255,255,255,.12)}.recipe-mode>.rm-panel{background:transparent;border:0;box-shadow:none;padding:10px 0 0}.recipe-mode .bom-layout{grid-template-columns:245px minmax(0,1fr);gap:30px}.recipe-mode aside{background:#fff;border:1px solid #dfe6ee;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.06)}.recipe-mode aside h3{font-size:13px;color:#64748b;letter-spacing:.04em;margin-bottom:14px}.recipe-mode .bom-products{max-height:640px;padding-right:8px}.recipe-mode .bom-item{border-radius:14px;padding:14px 14px;background:#fff}.recipe-mode .bom-item.active{background:#eff6ff;border-color:#80b9ff;box-shadow:0 0 0 1px #bfdbfe inset}.recipe-mode .bom-editor{border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.08)}.recipe-mode .bom-head{background:#fff;padding:24px 26px}.recipe-mode .bom-table{min-width:720px}.recipe-mode .bom-table th{background:#eef2f7;padding:13px 14px;font-size:12px}.recipe-mode .bom-table td{padding:11px 10px}.recipe-mode .bom-table input,.recipe-mode .bom-table select{height:42px;border-radius:10px}.recipe-mode .bom-num,.recipe-mode .bom-fire{font-weight:900;text-align:center;color:#00359e}.recipe-mode .bom-fire{color:#c2410c}.recipe-mode .rm-table-wrap{padding:22px 22px 0}.bom-ok.missing{background:#fef3c7;color:#b45309}.trash-btn{border:0;background:transparent;color:#ef4444;font-size:18px;font-weight:900;cursor:pointer}.bom-footer{display:flex;justify-content:space-between;align-items:center;padding:16px 22px}.add-material{border:0;border-radius:10px;background:#10b981;color:#fff;padding:10px 16px;font-weight:950;cursor:pointer}.bom-count{font-size:12px;color:#64748b}.expense-mode .rm-hero{background:linear-gradient(120deg,#101827 0%,#241810 100%);align-items:center}.expense-save{border:0;border-radius:12px;background:#f97316;color:#fff;padding:13px 18px;font-weight:950;box-shadow:0 10px 22px rgba(249,115,22,.28);cursor:pointer}.expense-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(330px,1fr);gap:18px}.expense-panel{background:#fff;border:1px solid #e3e8ef;border-radius:18px;padding:22px;box-shadow:0 14px 34px rgba(15,23,42,.06)}.expense-cards{display:grid;grid-template-columns:1fr 1fr;gap:14px}.expense-card{border:1px solid #e5eaf1;border-radius:16px;background:#fff;padding:16px}.expense-card label{display:block;color:#172033;font-weight:950;margin-bottom:10px}.expense-card input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px 13px;font-size:22px;font-weight:950;color:#0f172a}.expense-card p{margin:10px 0 0;color:#64748b;font-size:12px;line-height:1.45}.method-title{margin:22px 0 10px;font-size:16px;font-weight:950;color:#111827}.method-buttons{display:flex;flex-wrap:wrap;gap:10px}.method-btn{border:1px solid #e2e8f0;border-radius:999px;background:#fff;color:#334155;padding:10px 14px;font-weight:900;cursor:pointer}.method-btn.active{background:#f97316;border-color:#f97316;color:#fff}.analysis-card{background:#0f172a;color:#fff;border-radius:18px;padding:24px;box-shadow:0 18px 38px rgba(15,23,42,.24)}.analysis-card h3{margin:0 0 8px;font-size:13px;letter-spacing:.06em;color:#cbd5e1}.kpi{border-top:1px solid rgba(255,255,255,.12);padding:18px 0}.kpi:first-of-type{border-top:0}.kpi span{display:block;color:#94a3b8;font-size:12px;font-weight:800}.kpi strong{display:block;margin-top:6px;font-size:30px}.kpi .amber{color:#fbbf24}.kpi .cyan{color:#2dd4bf}.analysis-note{margin-top:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:13px;color:#dbeafe;font-size:12px;line-height:1.5}.product-mode .rm-hero{display:none}.product-head,.product-add,.product-kpis,.product-group{margin-bottom:16px}.product-head{display:flex;justify-content:space-between;gap:16px;align-items:center;background:#fff;border:1px solid #e4e9f1;border-radius:18px;padding:22px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.product-head h1{margin:0;color:#0f172a;font-size:28px}.product-head p{margin:5px 0 0;color:#64748b}.primary-add{border:0;border-radius:12px;background:#2563eb;color:#fff;padding:12px 16px;font-weight:950;cursor:pointer}.product-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.product-kpi{background:#fff;border:1px solid #e4e9f1;border-radius:16px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.04)}.product-kpi span{display:block;font-size:12px;color:#64748b;font-weight:900}.product-kpi strong{display:block;margin-top:6px;font-size:26px;color:#0f172a}.product-add{display:none;background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.product-add.show{display:block}.product-tools{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;background:#fff;border:1px solid #e4e9f1;border-radius:16px;padding:14px;margin-bottom:16px}.product-tools input,.product-tools select,.product-add input,.product-add select,.edit-row input{border:1px solid #cbd5e1;border-radius:10px;padding:10px 11px;font-size:13px}.product-groups{display:grid;gap:12px}.product-group{background:#fff;border:1px solid #e4e9f1;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.04);overflow:hidden}.product-group summary{list-style:none;display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:18px 20px;cursor:pointer}.product-group summary::-webkit-details-marker{display:none}.group-title strong{display:block;color:#0f172a;font-size:17px}.group-title span{display:block;color:#64748b;font-size:12px;margin-top:3px}.group-count{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:950}.mini-add{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:8px 10px;font-weight:900;cursor:pointer}.product-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}.product-table th{background:#f8fafc;color:#334155;padding:11px;text-align:left;border-top:1px solid #eef2f7}.product-table td{padding:12px 11px;border-top:1px solid #eef2f7;vertical-align:middle}.code-pill{display:inline-block;background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:950}.status-pill{display:inline-flex;gap:6px;align-items:center;background:#dcfce7;color:#047857;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:950}.status-pill:before{content:'?';font-size:9px}.icon-btn{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:9px;padding:8px 10px;font-weight:900;cursor:pointer}.delete-btn{border:1px solid #fecaca;background:#fff1f2;color:#dc2626;border-radius:9px;padding:8px 10px;font-weight:900;cursor:pointer}.row-actions{display:flex;gap:8px}.edit-row{display:none;background:#f8fafc}.editing+.edit-row{display:table-row}.editing{display:none}.confirm-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:20;align-items:center;justify-content:center}.confirm-modal.show{display:flex}.confirm-box{width:min(420px,92vw);background:#fff;border-radius:18px;padding:22px;box-shadow:0 25px 70px rgba(15,23,42,.25)}.confirm-box h3{margin:0 0 10px}.confirm-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}@media(max-width:1100px){.expense-grid,.expense-cards,.product-kpis,.product-tools{grid-template-columns:1fr 1fr}}@media(max-width:760px){.product-head,.product-group summary{display:block}.product-kpis,.product-tools{grid-template-columns:1fr}.primary-add{margin-top:12px}}
        .price-panel{background:#fff;border:1px solid #e4e9f1;border-radius:18px;margin:18px 0;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.price-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}.price-head h3{margin:0;color:#0f172a;font-size:20px}.price-head p{margin:4px 0 0;color:#64748b;font-size:12px}.rate-boxes{display:flex;gap:10px}.rate-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:10px 13px;min-width:110px}.rate-box span{display:block;color:#64748b;font-size:11px;font-weight:900}.rate-box strong{display:block;color:#0f172a;margin-top:4px}.move-up{color:#16a34a;font-weight:950}.move-down{color:#dc2626;font-weight:950}.price-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:30;align-items:center;justify-content:center;padding:20px}.price-modal.show{display:flex}.price-box{width:min(980px,96vw);max-height:90vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 30px 80px rgba(15,23,42,.28)}.price-box-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:18px 20px;border-bottom:1px solid #e5e7eb}.price-box-head h3{margin:0;color:#0f172a}.price-box-head p{margin:4px 0 0;color:#64748b;font-size:12px}.price-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:16px 20px;background:#f8fafc}.price-form label{font-size:11px;font-weight:900;color:#475569}.price-form input,.price-form select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px}.price-history{padding:0 20px 20px}.stock-head{background:#fff;border:1px solid #dbe5f2;border-radius:18px;padding:20px;margin-bottom:16px;box-shadow:0 14px 32px rgba(37,99,235,.08)}.stock-chips{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:14px}.stock-chip{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:12px;padding:10px;font-size:12px;font-weight:950;cursor:pointer}.stock-chip.plus{background:#ecfdf5;border-color:#86efac;color:#047857}.stock-chip.minus{background:#fff7ed;border-color:#fed7aa;color:#c2410c}.stock-form{background:#fff;border:1px solid #bfdbfe;border-radius:18px;padding:18px;margin-bottom:16px;box-shadow:0 14px 32px rgba(37,99,235,.08)}.stock-kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px}.stock-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px}.stock-kpi span{display:block;color:#64748b;font-size:11px;font-weight:900}.stock-kpi strong{display:block;margin-top:6px;font-size:21px;color:#0f172a}.stock-kpi.fire strong{color:#dc2626}.stock-kpi.dark{background:#0f172a;color:#fff}.stock-kpi.dark span{color:#cbd5e1}.stock-kpi.dark strong{color:#facc15}.stock-tools{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px;margin-bottom:16px}.stock-tools input,.stock-tools select{border:1px solid #cbd5e1;border-radius:10px;padding:10px}.tip-badge{display:inline-block;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:950;background:#eff6ff;color:#1d4ed8}.tip-badge.plus{background:#dcfce7;color:#047857}.tip-badge.minus{background:#fee2e2;color:#b91c1c}@media(max-width:1100px){.stock-chips,.stock-kpis{grid-template-columns:1fr 1fr 1fr}.stock-tools{grid-template-columns:1fr}}.recipe-mode .rm-tabs{display:grid}.product-mode .rm-hero{display:flex}.recipe-mode .rm-hero,.expense-mode .rm-hero,.product-mode .rm-hero{background:linear-gradient(135deg,#0f172a,#1d4ed8);align-items:center}
        .product-mode .product-head,.product-mode .product-kpis,.product-mode .product-tools,.product-mode .product-groups{display:none}
        .product-list-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 16px 38px rgba(15,23,42,.07);overflow:hidden}
        .product-list-top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px 20px}
        .product-list-top h3{margin:0;color:#0f172a;font-size:14px;letter-spacing:.06em;font-weight:950}
        .product-list-actions{display:flex;gap:10px;align-items:center}
        .product-search{width:330px;max-width:42vw;border:1px solid #d5dfeb;border-radius:16px;padding:11px 16px;font-size:14px;color:#334155;background:#fff}
        .product-list-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}
        .product-list-table th{background:#f1f5f9;color:#334155;text-align:left;padding:16px 10px;font-weight:950;border-top:1px solid #e2e8f0}
        .product-list-table td{padding:18px 10px;border-top:1px solid #eef2f7;color:#1e293b;vertical-align:middle}
        .product-list-table .code-text{font-weight:950;color:#172554;letter-spacing:.02em}
        .product-list-table .product-name{font-weight:950;color:#0f172a}
        .product-list-table .active-badge{display:inline-flex;align-items:center;gap:6px;border:1px solid #86efac;background:#ecfdf5;color:#047857;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:950}
        .product-list-table .active-badge:before{content:'?';display:grid;place-items:center;width:14px;height:14px;border:1px solid currentColor;border-radius:50%;font-size:10px}
        .product-icon-actions{display:flex;justify-content:flex-end;gap:14px}
        .product-icon{border:0;background:transparent;color:#475569;font-size:22px;line-height:1;cursor:pointer;padding:4px}
        .product-icon.delete{color:#ef4444}
        .product-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.46);z-index:40;align-items:center;justify-content:center;padding:20px}
        .product-modal.show{display:flex}
        .product-modal-box{width:min(590px,95vw);background:#fff;border-radius:16px;box-shadow:0 30px 80px rgba(15,23,42,.32);overflow:hidden}
        .product-modal-head{display:flex;justify-content:space-between;align-items:center;background:#0f172a;color:#fff;padding:20px}
        .product-modal-head h3{margin:0;font-size:20px}.product-close{border:0;background:transparent;color:#94a3b8;font-size:34px;cursor:pointer;line-height:1}
        .product-modal-body{padding:24px 20px}.product-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .product-modal-grid .wide{grid-column:1/-1}.product-modal-grid label{display:block;color:#475569;font-weight:900;font-size:14px}
        .product-modal-grid input{width:100%;box-sizing:border-box;margin-top:7px;border:1px solid #cbd5e1;border-radius:11px;padding:13px 12px;font-size:15px;color:#243244}
        .product-check{display:flex;align-items:center;gap:9px;margin-top:24px;color:#475569;font-weight:900}.product-check input{width:18px;height:18px}
        .product-modal-footer{display:flex;justify-content:flex-end;gap:12px;border-top:1px solid #e2e8f0;margin-top:22px;padding-top:22px}
        .product-cancel{border:0;border-radius:12px;background:#e2e8f0;color:#334155;padding:12px 20px;font-weight:950;cursor:pointer}
        .product-save{border:0;border-radius:12px;background:#2563eb;color:#fff;padding:12px 22px;font-weight:950;cursor:pointer}
        .cost-tabs{display:flex;gap:10px;margin-bottom:16px}.cost-tabs a{background:#fff;border:1px solid #dbe4ef;border-radius:14px;padding:12px 16px;color:#334155;text-decoration:none;font-weight:950}.cost-tabs a.active{background:#0f172a;color:#fff;border-color:#0f172a}.cost-page{display:grid;gap:16px}.price-hero{display:grid;grid-template-columns:1.2fr .8fr;gap:20px;background:linear-gradient(125deg,#0f172a,#2b216b);color:#fff;border-radius:18px;padding:28px;box-shadow:0 18px 42px rgba(15,23,42,.18)}.price-hero h2{margin:10px 0 8px;font-size:28px}.price-hero p{margin:0;color:#dbeafe;line-height:1.6}.price-kicker{display:inline-block;background:#1e40af;border:1px solid rgba(147,197,253,.5);border-radius:999px;padding:7px 12px;color:#93c5fd;font-size:12px;font-weight:950}.currency-card{border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.1);border-radius:16px;padding:18px}.currency-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.currency-grid label{font-size:12px;font-weight:950;color:#dbeafe}.currency-grid input{width:100%;box-sizing:border-box;margin-top:7px;border:0;border-radius:8px;background:#020617;color:#fff;padding:13px;text-align:center;font-size:18px;font-weight:950}.price-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.price-kpis .rm-card.dark{background:#0f172a;color:#fff}.price-tools{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px}.price-tools input{border:1px solid #cbd5e1;border-radius:13px;padding:12px 14px;min-width:310px}.price-filter{display:flex;gap:8px;align-items:center}.filter-pill{border:0;border-radius:999px;padding:10px 14px;font-weight:950;background:#f1f5f9;color:#334155}.filter-pill.active{background:#0f172a;color:#fff}.price-list{background:#fff;border:1px solid #dbe4ef;border-radius:18px;overflow:hidden;box-shadow:0 14px 32px rgba(15,23,42,.06)}.price-list-head{display:flex;justify-content:space-between;align-items:center;background:#0f172a;color:#fff;padding:18px 20px}.price-list-head h3{margin:0;font-size:17px}.price-list table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}.price-list th{background:#f1f5f9;color:#172033;text-align:left;padding:13px}.price-list td{padding:12px;border-top:1px solid #e8eef6}.price-input{width:110px;border:1px solid #cbd5e1;border-radius:12px;padding:10px}.tl-cell{background:#ecfdf5;text-align:center;font-weight:950;color:#064e3b}.fifo-note{font-size:12px;color:#64748b}.invoice-btn{border:1px solid #dbe4ef;background:#fff;border-radius:12px;padding:10px 12px;font-weight:900;color:#334155}.cost-subsection{display:none}.cost-subsection.active{display:block}@media(max-width:900px){.price-hero,.price-kpis{grid-template-columns:1fr}.price-tools{display:block}.price-tools input{width:100%;min-width:0;margin-bottom:10px}}
        @media(max-width:760px){.product-list-top,.product-list-actions{display:block}.product-search{width:100%;max-width:none;margin-top:12px}.product-modal-grid{grid-template-columns:1fr}.product-list-card{overflow:auto}.product-list-table{min-width:900px}}
        .calc-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;align-items:center;justify-content:center;padding:20px}.calc-modal.show{display:flex}.calc-box{width:min(860px,96vw);max-height:88vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 30px 80px rgba(15,23,42,.28)}.calc-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;background:#0f172a;color:#fff;padding:18px 20px}.calc-head h3{margin:0;font-size:20px}.calc-head p{margin:4px 0 0;color:#cbd5e1;font-size:13px}.calc-close{border:0;background:#334155;color:#fff;border-radius:10px;padding:9px 12px;font-weight:900;cursor:pointer}.calc-body{padding:18px 20px}.calc-note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px;color:#1e3a8a;font-size:13px;margin-bottom:12px}.calc-table{width:100%;border-collapse:collapse;font-size:12px}.calc-table th{background:#f1f5f9;text-align:left;color:#334155;padding:10px}.calc-table td{border-bottom:1px solid #e5e7eb;padding:10px}.calc-table .num{text-align:right;font-variant-numeric:tabular-nums}.calc-info-btn{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:900;cursor:pointer}
.cost-fit{min-width:0!important;table-layout:fixed}
.cost-fit th,.cost-fit td{padding:8px 6px;font-size:11px}
.cost-fit .rm-product{font-size:11px;word-break:break-word}
.cost-fit .rm-btn{padding:7px 9px;font-size:11px}
@media(max-width:900px){
    .cost-fit th:nth-child(n+3):nth-child(-n+6),
    .cost-fit td:nth-child(n+3):nth-child(-n+6){display:none}
    .cost-fit th,.cost-fit td{font-size:10px;padding:7px 5px}
}
</style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="main rm-page <?php echo $tab==='receteler'?'recipe-mode':''; ?> <?php echo $tab==='giderler'?'expense-mode':''; ?> <?php echo $tab==='urunler'?'product-mode':''; ?>">
    <section class="rm-hero">
        <div><h1>Reçete ve Maliyet</h1><p>Reçete, fiyatlama, üretim ve koli başı maliyet kontrol paneli.</p></div>
        <form class="rm-filter" method="GET"><input type="hidden" name="tab" value="<?php echo rm_e($tab); ?>"><label>Dönem</label><select name="donem" onchange="this.form.submit()"><?php foreach($donemler as $d): ?><option value="<?php echo rm_e($d['donem']); ?>" <?php echo $donem===$d['donem'] ? 'selected' : ''; ?>><?php echo rm_e($d['donem_adi']); ?></option><?php endforeach; ?></select><span class="rm-pill"><?php echo rm_e($currentDonem['durum']); ?></span></form>
    </section>
    <nav class="rm-tabs">
        <a class="<?php echo $tab==='ozet'?'active':''; ?>" href="?tab=ozet&donem=<?php echo rm_e($donem); ?>">Maliyet Özeti</a>
        <a class="<?php echo $tab==='urunler'?'active':''; ?>" href="?tab=urunler&donem=<?php echo rm_e($donem); ?>">Ürünler</a>
        <a class="<?php echo $tab==='giderler'?'active':''; ?>" href="?tab=giderler&donem=<?php echo rm_e($donem); ?>">Giderler</a>
        <a class="<?php echo $tab==='receteler'?'active':''; ?>" href="?tab=receteler&donem=<?php echo rm_e($donem); ?>">Reçeteler</a>
        <a class="<?php echo $tab==='uretim'?'active':''; ?>" href="?tab=uretim&donem=<?php echo rm_e($donem); ?>">Üretim</a>
        <a class="<?php echo $tab==='stok_hareketleri'?'active':''; ?>" href="?tab=stok_hareketleri&donem=<?php echo rm_e($donem); ?>">Stok Hareketleri</a>
    </nav>

    <?php if($message): ?><div class="rm-warn" style="background:#dcfce7;color:#166534;border-color:#bbf7d0"><?php echo rm_e($message); ?></div><?php endif; ?>
    <?php if($error): ?><div class="rm-warn"><?php echo rm_e($error); ?></div><?php endif; ?>
    <?php foreach($warnings as $w): ?><div class="rm-warn"><?php echo rm_e($w); ?></div><?php endforeach; ?>

    <?php if($tab==='ozet'): ?>
    <section class="rm-kpis">
        <div class="rm-card"><span>Toplam Üretim</span><strong><?php echo number_format($totalProduction,0,',','.'); ?> koli</strong><em class="rm-change">Dönem açık</em></div>
        <div class="rm-card"><span>Ortalama Koli Maliyeti</span><strong><?php echo rm_money($avgCost); ?></strong><em class="rm-change">Nisan 2026</em></div>
        <div class="rm-card"><span>Hammadde Maliyeti</span><strong><?php echo number_format($weightedHammadde/1000000,2,',','.'); ?> Mn TL</strong><em class="rm-change">Reçeteden</em></div>
        <div class="rm-card"><span>Genel Giderler</span><strong><?php echo number_format($weightedGider/1000000,2,',','.'); ?> Mn TL</strong><em class="rm-change">720/730/760/770</em></div>
    </section>
    <section class="rm-grid">
        <div class="rm-panel"><h3>Ürün Maliyet Tablosu</h3><div class="rm-table-wrap"><table class="rm-table cost-fit"><thead><tr><th>Ürün</th><th>Hammadde</th><th>720</th><th>730</th><th>760</th><th>770</th><th>Toplam</th><th>Değişim</th><th>Detay</th></tr></thead><tbody><?php foreach($urunMaliyetleri as $u): ?><tr onclick="location.href='?tab=ozet&donem=<?php echo rm_e($donem); ?>&urun_id=<?php echo (int)$u['id']; ?>'"><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo rm_money($u['hammadde']); ?></td><td class="num"><?php echo rm_money($u['g720']); ?></td><td class="num"><?php echo rm_money($u['g730']); ?></td><td class="num"><?php echo rm_money($u['g760']); ?></td><td class="num"><?php echo rm_money($u['g770']); ?></td><td class="num"><b><?php echo rm_money($u['toplam']); ?></b></td><td><span class="green">%0,00</span></td><td><button class="rm-btn" type="button">Aç</button></td></tr><?php endforeach; ?></tbody></table></div></div>
        <aside class="rm-panel">
            <div class="rm-detail-head"><div><h3><?php echo rm_e($selected['urun_adi'] ?? 'Ürün seçin'); ?></h3><span class="muted"><?php echo rm_e($currentDonem['donem_adi']); ?></span></div><button class="calc-info-btn" type="button" onclick="document.getElementById('calcModal').classList.add('show')">Nasıl hesaplandı</button></div>
            <div class="rm-break"><?php foreach($detaylar as $d): ?><div class="rm-break-row"><span><?php echo rm_e($d['kalem_adi']); ?></span><b><?php echo rm_money($d['tutar']); ?></b></div><?php endforeach; ?></div>
            <div class="rm-total"><span>Koli Başına Maliyet</span><strong><?php echo rm_money($selected['toplam'] ?? 0); ?></strong></div>
            <div class="rm-break" style="margin-top:12px"><div class="rm-break-row"><span>Nakliyeli</span><b><?php echo rm_money(($selected['toplam'] ?? 0) + (float)$selectedNd['nakliye_tl_koli']); ?></b></div><div class="rm-break-row"><span>Nakliyesiz</span><b><?php echo rm_money($selected['toplam'] ?? 0); ?></b></div><div class="rm-break-row"><span>Nakliyesiz + DEKAP</span><b><?php echo rm_money(($selected['toplam'] ?? 0) + (float)$selectedNd['dekap_tl_koli']); ?></b></div></div>
        </aside>
    </section>
    <div class="calc-modal" id="calcModal" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="calc-box">
            <div class="calc-head">
                <div><h3>Maliyet Hesaplama Detayı</h3><p><?php echo rm_e($selected['urun_adi'] ?? 'Ürün seçin'); ?> - <?php echo rm_e($currentDonem['donem_adi']); ?></p></div>
                <button class="calc-close" type="button" onclick="document.getElementById('calcModal').classList.remove('show')">Kapat</button>
            </div>
            <div class="calc-body">
                <div class="calc-note">Satır tutarı varsa doğrudan alınır. Satır tutarı yoksa formül: miktar x birim fiyat x (1 + fire oranı / 100). Koli başına maliyet, aşağıdaki satır tutarlarının toplamıdır.</div>
                <table class="calc-table"><thead><tr><th>Kalem</th><th class="num">Miktar</th><th class="num">Birim Fiyat</th><th class="num">Fire</th><th class="num">Satır Tutarı</th><th>Hesap</th></tr></thead><tbody>
                    <?php foreach($detaylar as $d): $manual=(float)($d['satir_tutari'] ?? 0); ?>
                    <tr>
                        <td><?php echo rm_e($d['kalem_adi']); ?></td>
                        <td class="num"><?php echo number_format((float)$d['miktar'],4,',','.'); ?></td>
                        <td class="num"><?php echo rm_money($d['birim_fiyat']); ?></td>
                        <td class="num">%<?php echo number_format((float)$d['fire_orani'],2,',','.'); ?></td>
                        <td class="num"><b><?php echo rm_money($d['tutar']); ?></b></td>
                        <td><?php echo $manual > 0 ? 'Manuel satır tutarı kullanıldı.' : 'Miktar x birim fiyat x fire katsayısı'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <div class="rm-total" style="margin-top:14px"><span>Toplam koli başına maliyet</span><strong><?php echo rm_money($selected['toplam'] ?? 0); ?></strong></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($tab==='urunler'): ?>
    <section class="product-list-card">
        <div class="product-list-top">
            <h3>KAYITLI ÜRÜN LİSTESİ (<?php echo count($urunler); ?>)</h3>
            <div class="product-list-actions">
                <input class="product-search" id="productSearchModern" placeholder="Ürün adı, kod ara...">
                <button class="primary-add" type="button" onclick="openProductCard()">+ Yeni Ürün</button>
            </div>
        </div>
        <div class="rm-table-wrap" style="padding:0">
            <table class="product-list-table">
                <thead><tr><th>Ürün Kodu</th><th>Ürün Adı</th><th>Varyant</th><th>Hacim</th><th>Koli İçi Adet</th><th>Ambalaj Tipi</th><th>Durum</th><th style="text-align:right">İşlemler</th></tr></thead>
                <tbody>
                <?php foreach($urunler as $u): $code=rm_urun_kodu_satir($u); $hacim=rm_urun_hacim($u); $varyant=rm_urun_varyant($u); ?>
                    <tr class="product-modern-row" data-search="<?php echo rm_e(rm_lower($code.' '.$u['urun_adi'].' '.$varyant.' '.$u['ambalaj_tipi'])); ?>">
                        <td class="code-text"><?php echo rm_e($code); ?></td>
                        <td class="product-name"><?php echo rm_e($u['urun_adi']); ?></td>
                        <td><?php echo rm_e($varyant); ?></td>
                        <td><strong><?php echo rm_e($hacim); ?></strong></td>
                        <td><strong><?php echo number_format((float)$u['koli_ici_adet'],0,',','.'); ?> Adet</strong></td>
                        <td><?php echo rm_e($u['ambalaj_tipi']); ?></td>
                        <td><span class="active-badge">Aktif</span></td>
                        <td><div class="product-icon-actions"><button class="product-icon" type="button" title="Düzelt" data-id="<?php echo (int)$u['id']; ?>" data-code="<?php echo rm_e($code); ?>" data-name="<?php echo rm_e($u['urun_adi']); ?>" data-variant="<?php echo rm_e($u['urun_grubu']); ?>" data-hacim="<?php echo rm_e($hacim); ?>" data-koli="<?php echo number_format((float)$u['koli_ici_adet'],0,',','.'); ?>" data-ambalaj="<?php echo rm_e($u['ambalaj_tipi']); ?>" onclick="openProductCard(this)">Düzelt</button><button class="product-icon delete" type="button" title="Sil" data-id="<?php echo (int)$u['id']; ?>" data-code="<?php echo rm_e($code); ?>" data-name="<?php echo rm_e($u['urun_adi']); ?>" onclick="confirmDeleteBtn(this)">Sil</button></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <div class="product-modal" id="productCardModal">
        <div class="product-modal-box">
            <div class="product-modal-head"><h3>Ürün Kartı Kaydı</h3><button class="product-close" type="button" onclick="closeProductCard()">×</button></div>
            <form method="POST" class="product-modal-body">
                <input type="hidden" name="action" id="productAction" value="urun_kaydet">
                <input type="hidden" name="urun_id" id="productId">
                <div class="product-modal-grid">
                    <label class="wide">Ürün Kodu<input name="urun_kodu" id="productCode" placeholder="SU-PET-033"></label>
                    <label class="wide">Ürün Adı<input name="urun_adi" id="productName" required placeholder="0,33 L Pet Şişe Su"></label>
                    <label>Varyant<input name="urun_grubu" id="productVariant" placeholder="Standart PET"></label>
                    <label>Hacim<input id="productHacim" placeholder="0.33 lt"></label>
                    <label>Koli İçi Adet<input name="koli_ici_adet" id="productKoli" placeholder="24"></label>
                    <label>Ambalaj Tipi<input name="ambalaj_tipi" id="productAmbalaj" placeholder="PET Şişe"></label>
                </div>
                <label class="product-check"><input type="checkbox" checked> Aktif Ürün Kartı</label>
                <div class="product-modal-footer"><button class="product-cancel" type="button" onclick="closeProductCard()">İptal</button><button class="product-save">Kaydet</button></div>
            </form>
        </div>
    </div>
    <section class="product-head">
        <div><h1>Ürün Tanımları</h1><p>Sistemde maliyet hesaplamasına tabi tutulacak ambalajlı ürünlerin listesi</p></div>
        <button class="primary-add" type="button" onclick="openProductForm('')">+ Yeni Ürün Ekle</button>
    </section>
    <section class="product-kpis">
        <div class="product-kpi"><span>Toplam Ürün</span><strong><?php echo (int)$urunKpi['toplam']; ?></strong></div>
        <div class="product-kpi"><span>Aktif Ürün</span><strong><?php echo (int)$urunKpi['aktif']; ?></strong></div>
        <div class="product-kpi"><span>Ürün Grubu</span><strong><?php echo (int)$urunKpi['grup']; ?></strong></div>
        <div class="product-kpi"><span>Eksik Reçete</span><strong><?php echo (int)$urunKpi['eksik']; ?></strong></div>
    </section>
    <section class="product-add" id="productForm">
        <form method="POST" class="rm-form">
            <input type="hidden" name="action" value="urun_kaydet">
            <div class="rm-field"><label>Ürün Kodu</label><input name="urun_kodu" placeholder="SU-PET-033"></div>
            <div class="rm-field"><label>Ürün Adı</label><input name="urun_adi" required></div>
            <div class="rm-field"><label>Ürün Grubu</label><select name="urun_grubu" id="addGroup"><option>PET Şişeler</option><option>Bidon / Büyük Hacimli</option><option>Cam Ürünler</option><option>Damacana</option><option>Bardak</option></select></div>
            <div class="rm-field"><label>Ambalaj Tipi</label><input name="ambalaj_tipi" value="PET"></div>
            <div class="rm-field"><label>Koli İçi</label><input name="koli_ici_adet" value="24"></div>
            <div class="rm-field"><label>&nbsp;</label><button class="primary-add">Kaydet</button></div>
        </form>
    </section>
    <section class="product-tools">
        <input id="productSearch" placeholder="Ürün adı, kod ara...">
        <select id="groupFilter"><option value="">Gruplar</option><?php foreach($urunGruplari as $key=>$g): ?><option value="<?php echo rm_e($key); ?>"><?php echo rm_e($g['baslik']); ?></option><?php endforeach; ?></select>
        <select id="statusFilter"><option value="">Durum</option><option value="aktif">Aktif</option><option value="pasif">Pasif</option></select>
        <select id="sortProducts"><option value="az">Ürün Adı A-Z</option><option value="za">Ürün Adı Z-A</option><option value="kod">Ürün Kodu</option><option value="hacim">Hacim</option></select>
    </section>
    <section class="product-groups">
        <?php foreach($urunGruplari as $key=>$g): ?>
        <details class="product-group" open data-group="<?php echo rm_e($key); ?>">
            <summary>
                <div class="group-title"><strong><?php echo rm_e($g['baslik']); ?></strong><span><?php echo rm_e($g['aciklama']); ?></span></div>
                <button class="mini-add" type="button" onclick="event.preventDefault();openProductForm('<?php echo rm_e($g['baslik']); ?>')">+ Ürün Ekle</button>
                <span class="group-count"><?php echo count($g['urunler']); ?> ürün</span>
            </summary>
            <div class="rm-table-wrap">
                <table class="product-table"><thead><tr><th>Ürün Kodu</th><th>Ürün Adı</th><th>Varyant</th><th>Hacim</th><th>Koli İçi</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
                <?php foreach($g['urunler'] as $u): $code = rm_urun_kodu_satir($u); ?>
                    <tr class="product-row" data-group="<?php echo rm_e($key); ?>" data-status="aktif" data-name="<?php echo rm_e(rm_lower($u['urun_adi'].' '.$code)); ?>" data-code="<?php echo rm_e($code); ?>" data-hacim="<?php echo rm_e($u['urun_adi']); ?>">
                        <td><span class="code-pill"><?php echo rm_e($code); ?></span></td>
                        <td><strong><?php echo rm_e($u['urun_adi']); ?></strong></td>
                        <td><?php echo rm_e($u['urun_grubu']); ?></td>
                        <td><?php echo rm_e($u['urun_adi']); ?></td>
                        <td><?php echo number_format((float)$u['koli_ici_adet'],2,',','.'); ?></td>
                        <td><span class="status-pill">Aktif</span></td>
                        <td><div class="row-actions"><button class="icon-btn" type="button" onclick="editProduct(this)">Düzenle</button><button class="icon-btn" type="button" data-name="<?php echo rm_e($u['urun_adi']); ?>" onclick="openPriceModal(this)">Fiyat</button><button class="delete-btn" type="button" data-id="<?php echo (int)$u['id']; ?>" data-code="<?php echo rm_e($code); ?>" data-name="<?php echo rm_e($u['urun_adi']); ?>" onclick="confirmDeleteBtn(this)">Sil</button></div></td>
                    </tr>
                    <tr class="edit-row"><td colspan="7"><form method="POST" class="rm-form"><input type="hidden" name="action" value="urun_guncelle"><input type="hidden" name="urun_id" value="<?php echo (int)$u['id']; ?>"><div class="rm-field"><label>Ürün Kodu</label><input name="urun_kodu" value="<?php echo rm_e($code); ?>"></div><div class="rm-field"><label>Ürün Adı</label><input name="urun_adi" value="<?php echo rm_e($u['urun_adi']); ?>"></div><div class="rm-field"><label>Ürün Grubu</label><input name="urun_grubu" value="<?php echo rm_e($u['urun_grubu']); ?>"></div><div class="rm-field"><label>Ambalaj Tipi</label><input name="ambalaj_tipi" value="<?php echo rm_e($u['ambalaj_tipi']); ?>"></div><div class="rm-field"><label>Koli İçi</label><input name="koli_ici_adet" value="<?php echo number_format((float)$u['koli_ici_adet'],2,',','.'); ?>"></div><div class="rm-field"><label>&nbsp;</label><button class="primary-add">Kaydet</button> <button class="icon-btn" type="button" onclick="cancelEdit(this)">Vazgeç</button></div></form></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <div class="price-panel" style="margin:12px 16px 16px">
                <div class="price-head">
                    <div><h3>Fiyat Geçmişi</h3><p>Bu gruba ait ürün fiyatlarını buradan izleyebilir, satırdaki Fiyat butonuyla ekleyip düzeltebilirsiniz.</p></div>
                    <div class="rate-boxes">
                        <div class="rate-box"><span>USD/TL</span><strong><?php echo $kurOzet['usd'] > 0 ? number_format($kurOzet['usd'],4,',','.') : '-'; ?></strong></div>
                        <div class="rate-box"><span>EUR/TL</span><strong><?php echo $kurOzet['eur'] > 0 ? number_format($kurOzet['eur'],4,',','.') : '-'; ?></strong></div>
                    </div>
                </div>
                <div class="rm-table-wrap">
                    <table class="product-table"><thead><tr><th>Tarih</th><th>Kategori</th><th>Ürün / Malzeme</th><th>Döviz Fiyatı</th><th>Döviz Adet</th><th>TL Birim</th><th>Hareket</th><th>İşlem</th></tr></thead><tbody>
                    <?php $groupPrices = $fiyatlarByGroup[$key] ?? []; if(!$groupPrices): ?><tr><td colspan="8">Bu grup için fiyat geçmişi yok.</td></tr><?php endif; ?>
                    <?php foreach(array_slice($groupPrices,0,24) as $f): $mv=(float)$f['hareket']; ?>
                        <tr>
                            <td><?php echo rm_e($f['fiyat_tarihi']); ?></td><td><?php echo rm_e($f['kategori']); ?></td><td><strong><?php echo rm_e($f['urun_adi']); ?></strong></td><td><?php echo rm_e($f['ton_fiyati']); ?></td><td><?php echo rm_e($f['doviz_adet']); ?></td><td class="num"><?php echo rm_money($f['tl_adet']); ?></td><td class="num <?php echo $mv>0 ? 'move-up' : ($mv<0 ? 'move-down' : ''); ?>"><?php echo $mv==0 ? '-' : (($mv>0 ? '+' : '').rm_money($mv)); ?></td><td><button type="button" class="icon-btn" data-name="<?php echo rm_e($f['urun_adi']); ?>" data-date="<?php echo rm_e($f['fiyat_tarihi']); ?>" data-cat="<?php echo rm_e($f['kategori']); ?>" data-ton="<?php echo rm_e($f['ton_fiyati']); ?>" data-doviz="<?php echo rm_e($f['doviz_adet']); ?>" data-tl="<?php echo number_format((float)$f['tl_adet'],2,',','.'); ?>" data-c033="<?php echo number_format((float)($f['cam_sise_033'] ?? 0),2,',','.'); ?>" data-c075="<?php echo number_format((float)($f['cam_sise_075'] ?? 0),2,',','.'); ?>" onclick="openPriceModal(this);fillPriceForm(this)">Düzelt</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
            </div>
        </details>
        <?php endforeach; ?>
    </section>
    <div class="price-modal" id="priceModal">
        <div class="price-box">
            <div class="price-box-head"><div><h3 id="priceTitle">Fiyat Ekle / Düzelt</h3><p>Fiyatı kaydedin; aynı tarih ve ürün varsa kayıt güncellenir. Hareketler aşağıda görünür.</p></div><button class="icon-btn" type="button" onclick="closePriceModal()">Kapat</button></div>
            <form method="POST" class="price-form">
                <input type="hidden" name="action" value="fiyat_kaydet">
                <label>Tarih<input type="date" name="fiyat_tarihi" value="<?php echo date('Y-m-d'); ?>"></label>
                <label>Kategori<select name="kategori"><option>Ürün</option><option>Pet Hammadde</option><option>Kapak</option><option>Etiket</option><option>Ambalaj</option><option>Cam</option></select></label>
                <label>Ürün / Malzeme<input name="urun_adi" id="priceProductName" required></label>
                <label>Döviz Fiyatı<input name="ton_fiyati" placeholder="$1.950 / 7,00 €"></label>
                <label>Döviz Adet<input name="doviz_adet" placeholder="$0,0019500"></label>
                <label>TL Adet<input name="tl_adet" value="0,00"></label>
                <label>Cam 0,33<input name="cam_sise_033" value="0,00"></label>
                <label>Cam 0,75<input name="cam_sise_075" value="0,00"></label>
                <label style="grid-column:span 3">Açıklama<input name="aciklama"></label>
                <label><span>&nbsp;</span><button class="primary-add" style="width:100%">Fiyat Kaydet</button></label>
            </form>
            <div class="price-history">
                <table class="product-table"><thead><tr><th>Tarih</th><th>Kategori</th><th>Ürün</th><th>Döviz</th><th>Döviz Adet</th><th>TL</th><th>Hareket</th><th>Düzelt</th></tr></thead><tbody>
                <?php foreach($fiyatHareketleri as $f): $mv=(float)$f['hareket']; ?>
                    <tr class="modal-price-row" data-price-name="<?php echo rm_e(rm_lower($f['urun_adi'])); ?>">
                        <td><?php echo rm_e($f['fiyat_tarihi']); ?></td><td><?php echo rm_e($f['kategori']); ?></td><td><strong><?php echo rm_e($f['urun_adi']); ?></strong></td><td><?php echo rm_e($f['ton_fiyati']); ?></td><td><?php echo rm_e($f['doviz_adet']); ?></td><td class="num"><?php echo rm_money($f['tl_adet']); ?></td><td class="num <?php echo $mv>0 ? 'move-up' : ($mv<0 ? 'move-down' : ''); ?>"><?php echo $mv==0 ? '-' : (($mv>0 ? '+' : '').rm_money($mv)); ?></td><td><button type="button" class="icon-btn" data-date="<?php echo rm_e($f['fiyat_tarihi']); ?>" data-cat="<?php echo rm_e($f['kategori']); ?>" data-name="<?php echo rm_e($f['urun_adi']); ?>" data-ton="<?php echo rm_e($f['ton_fiyati']); ?>" data-doviz="<?php echo rm_e($f['doviz_adet']); ?>" data-tl="<?php echo number_format((float)$f['tl_adet'],2,',','.'); ?>" data-c033="<?php echo number_format((float)($f['cam_sise_033'] ?? 0),2,',','.'); ?>" data-c075="<?php echo number_format((float)($f['cam_sise_075'] ?? 0),2,',','.'); ?>" onclick="fillPriceForm(this)">Düzelt</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="confirm-modal" id="deleteModal"><div class="confirm-box"><h3>Ürünü Sil</h3><p>Bu ürünü silmek istediğinizden emin misiniz?</p><p><strong id="delName"></strong><br><span class="code-pill" id="delCode"></span></p><form method="POST" class="confirm-actions"><input type="hidden" name="action" value="urun_sil"><input type="hidden" name="urun_id" id="delId"><button class="icon-btn" type="button" onclick="closeDelete()">Vazgeç</button><button class="delete-btn">Ürünü Sil</button></form></div></div>
    <script>
    function openProductForm(group){ var f=document.getElementById('productForm'); f.classList.add('show'); if(group){ document.getElementById('addGroup').value=group; } f.scrollIntoView({behavior:'smooth',block:'center'}); }
    function openProductCard(btn){
        var m=document.getElementById('productCardModal');
        document.getElementById('productAction').value=btn ? 'urun_guncelle' : 'urun_kaydet';
        document.getElementById('productId').value=btn ? btn.dataset.id : '';
        document.getElementById('productCode').value=btn ? btn.dataset.code : '';
        document.getElementById('productName').value=btn ? btn.dataset.name : '';
        document.getElementById('productVariant').value=btn ? btn.dataset.variant : '';
        document.getElementById('productHacim').value=btn ? btn.dataset.hacim : '';
        document.getElementById('productKoli').value=btn ? btn.dataset.koli : '';
        document.getElementById('productAmbalaj').value=btn ? btn.dataset.ambalaj : '';
        m.classList.add('show');
    }
    function closeProductCard(){ document.getElementById('productCardModal').classList.remove('show'); }
    var psModern=document.getElementById('productSearchModern');
    if(psModern){ psModern.addEventListener('input', function(){
        var q=this.value.toLocaleLowerCase('tr-TR');
        document.querySelectorAll('.product-modern-row').forEach(function(r){ r.style.display = (!q || r.dataset.search.indexOf(q)>-1) ? '' : 'none'; });
    }); }
    function editProduct(btn){ btn.closest('tr').classList.add('editing'); }
    function cancelEdit(btn){ btn.closest('tr').previousElementSibling.classList.remove('editing'); }
    function confirmDeleteBtn(btn){ document.getElementById('delId').value=btn.dataset.id; document.getElementById('delCode').textContent=btn.dataset.code; document.getElementById('delName').textContent=btn.dataset.name; document.getElementById('deleteModal').classList.add('show'); }
    function closeDelete(){ document.getElementById('deleteModal').classList.remove('show'); }
    function openPriceModal(btn){
        var name=btn.dataset.name||'';
        document.getElementById('priceTitle').textContent=name+' - Fiyat Ekle / Düzelt';
        document.getElementById('priceProductName').value=name;
        var q=name.toLocaleLowerCase('tr-TR'), any=false;
        document.querySelectorAll('.modal-price-row').forEach(function(r){ var ok=r.dataset.priceName.indexOf(q)>-1; r.style.display=ok ? '' : 'none'; if(ok) any=true; });
        document.getElementById('priceModal').classList.add('show');
    }
    function closePriceModal(){ document.getElementById('priceModal').classList.remove('show'); }
    function fillPriceForm(btn){
        var f=btn.closest('.price-box').querySelector('form');
        f.fiyat_tarihi.value=btn.dataset.date||'';
        f.kategori.value=btn.dataset.cat||'Ürün';
        f.urun_adi.value=btn.dataset.name||'';
        f.ton_fiyati.value=btn.dataset.ton||'';
        f.doviz_adet.value=btn.dataset.doviz||'';
        f.tl_adet.value=btn.dataset.tl||'0,00';
        f.cam_sise_033.value=btn.dataset.c033||'0,00';
        f.cam_sise_075.value=btn.dataset.c075||'0,00';
        f.scrollIntoView({behavior:'smooth',block:'center'});
    }
    function filterProducts(){
        var q=(document.getElementById('productSearch').value||'').toLocaleLowerCase('tr-TR'), gf=document.getElementById('groupFilter').value, sf=document.getElementById('statusFilter').value;
        document.querySelectorAll('.product-group').forEach(function(g){
            var any=false;
            g.querySelectorAll('.product-row').forEach(function(r){
                var ok=(!q || r.dataset.name.toLocaleLowerCase('tr-TR').indexOf(q)>-1) && (!gf || r.dataset.group===gf) && (!sf || r.dataset.status===sf);
                r.style.display=ok'':'none';
                if(r.nextElementSibling && r.nextElementSibling.classList.contains('edit-row')) r.nextElementSibling.style.display='none';
                if(ok) any=true;
            });
            g.style.display=any'':'none';
            if(q && any) g.open=true;
        });
    }
    ['productSearch','groupFilter','statusFilter'].forEach(function(id){ document.getElementById(id).addEventListener('input',filterProducts); document.getElementById(id).addEventListener('change',filterProducts); });
    document.getElementById('sortProducts').addEventListener('change', function(){
        var mode=this.value;
        document.querySelectorAll('.product-table tbody').forEach(function(tb){
            Array.from(tb.querySelectorAll('.product-row')).sort(function(a,b){
                var av=mode==='kod'a.dataset.code:(mode==='hacim'a.dataset.hacim:a.dataset.name), bv=mode==='kod'b.dataset.code:(mode==='hacim'b.dataset.hacim:b.dataset.name);
                return mode==='za'bv.localeCompare(av,'tr'):av.localeCompare(bv,'tr');
            }).forEach(function(r){ var e=r.nextElementSibling && r.nextElementSibling.classList.contains('edit-row') ? r.nextElementSibling : null; tb.appendChild(r); if(e) tb.appendChild(e); });
        });
    });
    </script>
    <?php endif; ?>

    <?php if($tab==='hammaddeler'): ?>
    <section class="rm-panel"><h3>Hammaddeler</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Malzeme Kodu</th><th>Malzeme Ad?</th><th>Kategori</th><th>Ana Birim</th><th>Al?? Birimi</th><th>Durum</th></tr></thead><tbody><?php foreach($hammaddeler as $h): ?><tr><td>HM-<?php echo (int)$h['id']; ?></td><td><span class="rm-material"><?php echo rm_e($h['kalem_adi']); ?></span></td><td><?php echo rm_e($h['kategori']); ?></td><td><?php echo rm_e($h['birim']); ?></td><td><?php echo rm_e($h['birim']); ?></td><td><span class="rm-pill">Aktif</span></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>

    <?php if($tab==='stok_hareketleri'): $tipler=['uretilen'=>['Fabrika Sevk / Dolum Giri?','plus'],'birimler_arasi_sevk'=>['Ba?ka Birime Sevk','minus'],'bayi_sevk'=>['Bayi Sevk','minus'],'zincir_market_sevk'=>['M??teri / Market Sevk','minus'],'fire'=>['Fire / Zayiat','minus'],'iade_giris'=>['?ade Kabul','plus']]; ?>
    <section class="stock-head">
        <h3 style="margin:0">Su ?r?nleri Stok Hareketleri & Sevkiyat Ekran?</h3>
        <p class="muted" style="margin:6px 0 0">Dolum, transfer, bayi sevkiyat?, m??teri sevkiyat? ve zayiat kay?tlar?.</p>
        <div class="stock-chips">
            <?php foreach($tipler as $k=>$t): ?><button class="stock-chip <?php echo rm_e($t[1]); ?>" type="button" onclick="setStockType('<?php echo rm_e($k); ?>')"><?php echo rm_e($t[0]); ?></button><?php endforeach; ?>
        </div>
    </section>
    <section class="stock-form">
        <h3 style="margin-top:0">Yeni Stok Hareketi Doldurma Formu</h3>
        <form method="POST" class="rm-form">
            <input type="hidden" name="action" value="stok_hareket_kaydet">
            <div class="rm-field"><label>Su ?r?n?</label><select name="urun_id" required><?php foreach($urunler as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo rm_e(rm_urun_kodu_satir($u).' - '.$u['urun_adi'].' (Koli)'); ?></option><?php endforeach; ?></select></div>
            <div class="rm-field"><label>Hareket Tipi</label><select name="hareket_tipi" id="stockType"><?php foreach($tipler as $k=>$t): ?><option value="<?php echo rm_e($k); ?>"><?php echo rm_e($t[0]); ?></option><?php endforeach; ?></select></div>
            <div class="rm-field"><label>Miktar (Koli)</label><input name="miktar" value="0"></div>
            <div class="rm-field"><label>Tarih</label><input type="date" name="tarih" value="<?php echo date('Y-m-d'); ?>"></div>
            <div class="rm-field"><label>Belge / ?rsaliye No</label><input name="belge_no" placeholder="IRS-2026-104"></div>
            <div class="rm-field"><label>??k?? Deposu / Noktas?</label><input name="cikis_depo" list="depoList" placeholder="Dolum Tesis Deposu"></div>
            <div class="rm-field"><label>Var?? Deposu / Teslim Yeri</label><input name="varis_depo" list="depoList" placeholder="Ankara Bayi"></div>
            <div class="rm-field"><label>A??klama</label><input name="aciklama" placeholder="Ara?, ?of?r, sevkiyat notu"></div>
            <datalist id="depoList"><?php foreach($depolar as $d): ?><option value="<?php echo rm_e($d['yer_adi']); ?>"></option><?php endforeach; ?></datalist>
            <div class="rm-field"><label>&nbsp;</label><button class="primary-add">Kaydet ve Stok Say?m?na Aktar</button></div>
        </form>
    </section>
    <section class="stock-kpis">
        <div class="stock-kpi"><span>Toplam ?retilen / Dolum</span><strong><?php echo number_format($stokKpi['uretilen'],0,',','.'); ?></strong></div>
        <div class="stock-kpi"><span>M??teri Sevkiyat?</span><strong><?php echo number_format($stokKpi['zincir_market_sevk'],0,',','.'); ?></strong></div>
        <div class="stock-kpi"><span>Bayi Sevkiyat?</span><strong><?php echo number_format($stokKpi['bayi_sevk'],0,',','.'); ?></strong></div>
        <div class="stock-kpi"><span>Birim Transferi</span><strong><?php echo number_format($stokKpi['birimler_arasi_sevk'],0,',','.'); ?></strong></div>
        <div class="stock-kpi fire"><span>Fire / Zayiat</span><strong><?php echo number_format($stokKpi['fire'],0,',','.'); ?></strong></div>
        <div class="stock-kpi dark"><span>Anl?k Mevcut Stok</span><strong><?php echo number_format($stokMevcut,0,',','.'); ?></strong></div>
    </section>
    <section class="stock-tools"><input id="stockSearch" placeholder="?r?n ad?, irsaliye no veya depo ara"><select id="stockProduct"><option value="">T?m Su ?r?nleri</option><?php foreach($urunler as $u): ?><option value="<?php echo rm_e(rm_lower($u['urun_adi'])); ?>"><?php echo rm_e($u['urun_adi']); ?></option><?php endforeach; ?></select><select id="stockTip"><option value="">T?m Hareket Tipleri</option><?php foreach($tipler as $k=>$t): ?><option value="<?php echo rm_e($k); ?>"><?php echo rm_e($t[0]); ?></option><?php endforeach; ?></select></section>
    <section class="rm-panel">
        <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Tarih</th><th>?rsaliye No</th><th>Su ?r?n?</th><th>Hareket Tipi</th><th>??k?? -> Var??</th><th>Miktar</th><th>A??klama</th><th>??lem</th></tr></thead><tbody>
        <?php foreach($stokHareketleri as $h): $sign=in_array($h['hareket_tipi'],['uretilen','iade_giris'],true) ? '+' : '-'; $tc=$tipler[$h['hareket_tipi']] ?? [$h['hareket_tipi'],'minus']; ?>
            <tr class="stock-row" data-name="<?php echo rm_e(rm_lower($h['urun_adi'].' '.$h['belge_no'].' '.$h['cikis_depo'].' '.$h['varis_depo'])); ?>" data-product="<?php echo rm_e(rm_lower($h['urun_adi'])); ?>" data-tip="<?php echo rm_e($h['hareket_tipi']); ?>">
                <td><?php echo rm_e($h['tarih']); ?></td><td><?php echo rm_e($h['belge_no']); ?></td><td><strong><?php echo rm_e($h['urun_adi']); ?></strong><br><span class="bom-code">Koli</span></td><td><span class="tip-badge <?php echo rm_e($tc[1]); ?>"><?php echo rm_e($tc[0]); ?></span></td><td><small>??k??: <?php echo rm_e($h['cikis_depo']); ?><br>Var??: <?php echo rm_e($h['varis_depo']); ?></small></td><td class="num"><strong><?php echo $sign.number_format((float)$h['miktar'],0,',','.'); ?> koli</strong></td><td><?php echo rm_e($h['aciklama']); ?></td><td><form method="POST" onsubmit="return confirm('Bu stok hareketi silinsin mi')"><input type="hidden" name="action" value="stok_hareket_sil"><input type="hidden" name="hareket_id" value="<?php echo (int)$h['id']; ?>"><button class="delete-btn">Sil</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$stokHareketleri): ?><tr><td colspan="8">Hen?z stok hareketi yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <script>
    function setStockType(v){ document.getElementById('stockType').value=v; }
    function filterStock(){ var q=(document.getElementById('stockSearch').value||'').toLocaleLowerCase('tr-TR'), p=document.getElementById('stockProduct').value, t=document.getElementById('stockTip').value; document.querySelectorAll('.stock-row').forEach(function(r){ var ok=(!q||r.dataset.name.indexOf(q)>-1)&&(!p||r.dataset.product===p)&&(!t||r.dataset.tip===t); r.style.display=ok'':'none'; }); }
    ['stockSearch','stockProduct','stockTip'].forEach(function(id){ document.getElementById(id).addEventListener('input',filterStock); document.getElementById(id).addEventListener('change',filterStock); });
    </script>
    <?php endif; ?>

    <?php if($tab==='fiyatlar'): ?>
    <section class="rm-panel"><h3>Hammadde Fiyatları</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Dönem</th><th>Kategori</th><th>Hammadde</th><th>Fiyat</th><th>Döviz Adet</th><th>TL Birim</th><th>Cam 0,33</th><th>Cam 0,75</th></tr></thead><tbody><?php foreach($fiyatlar as $f): ?><tr><td><?php echo rm_e($f['fiyat_tarihi']); ?></td><td><?php echo rm_e($f['kategori']); ?></td><td><span class="rm-material"><?php echo rm_e($f['urun_adi']); ?></span></td><td><?php echo rm_e($f['ton_fiyati']); ?></td><td><?php echo rm_e($f['doviz_adet']); ?></td><td class="num"><?php echo rm_money($f['tl_adet']); ?></td><td class="num"><?php echo $f['cam_sise_033']===null ? '-' : rm_money($f['cam_sise_033']); ?></td><td class="num"><?php echo $f['cam_sise_075']===null ? '-' : rm_money($f['cam_sise_075']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>

    <?php if($tab==='receteler'): ?>
    <section class="rm-panel">
        <div class="bom-layout">
            <aside>
                <h3>ÜRÜN SEÇİMİ (<?php echo count($urunler); ?>)</h3>
                <div class="bom-products">
                    <?php foreach($urunler as $u): ?>
                    <a class="bom-item <?php echo (int)$u['id']===$selectedId ? 'active' : ''; ?>" href="?tab=receteler&donem=<?php echo rm_e($donem); ?>&urun_id=<?php echo (int)$u['id']; ?>">
                        <?php $ok = ($bomDurum[(int)$u['id']] ?? 0) > 0; ?><span class="bom-ok <?php echo $ok ? '' : 'missing'; ?>"><?php echo $ok ? '&#10003; v1.0' : '&#9888; Eksik'; ?></span><strong><?php echo rm_e($u['urun_adi']); ?></strong>
                        <span class="bom-code"><?php echo rm_e(rm_urun_kodu_satir($u)); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </aside>
            <form method="POST" class="bom-editor" id="bomForm">
                <input type="hidden" name="action" value="bom_kaydet" id="bomAction">
                <input type="hidden" name="urun_id" value="<?php echo (int)$selectedId; ?>">
                <div class="bom-head">
                    <div><span class="rm-pill"><?php echo rm_e($selectedProductCode); ?></span><span class="muted" style="margin-left:12px"><?php echo number_format((float)$selectedKoliIci,0,',','.'); ?> Adet / Koli Ambalaj Standard?</span><div class="bom-title"><?php echo rm_e($selectedProductName ?: '?r?n se?in'); ?> Re?ete Detay?</div></div>
                    <div class="bom-actions"><label>Versiyon<br><input name="versiyon" value="<?php echo rm_e($activeBom['versiyon'] ?? 'v1.0'); ?>" style="width:90px;text-align:center;font-weight:900"></label><label>A??klama<br><input name="aciklama" value="<?php echo rm_e($activeBom['aciklama'] ?? ''); ?>"></label></div>
                </div>
                <div class="rm-table-wrap"><table class="bom-table"><thead><tr><th>Hammadde / Malzeme</th><th>Tüketim Miktarı</th><th>Tüketim Birimi</th><th>Koli İçi Adet</th><th>Fire Oranı (%)</th><th>Sil</th></tr></thead><tbody id="bomRows">
                    <?php foreach($bomKalemleri as $b): ?>
                    <tr>
                        <td><select name="hammadde_id[]"><?php foreach($hammaddeler as $h): ?><option value="<?php echo (int)$h['id']; ?>" <?php echo (int)$h['id']===(int)$b['hammadde_id'] ? 'selected' : ''; ?>><?php echo rm_e($h['kalem_adi']); ?></option><?php endforeach; ?></select></td>
                        <td><input class="bom-num" name="tuketim_miktari[]" value="<?php echo number_format((float)$b['tuketim_miktari'],6,',','.'); ?>"></td>
                        <td><select class="bom-unit" name="tuketim_birimi[]"><option <?php echo $b['tuketim_birimi']==='gr/adet' ? 'selected' : ''; ?>>gr/adet</option><option <?php echo in_array($b['tuketim_birimi'], ['adet/adet','adet/koli'], true) ? 'selected' : ''; ?>>adet/adet</option><option <?php echo $b['tuketim_birimi']==='gr/koli' ? 'selected' : ''; ?>>gr/koli</option><option <?php echo $b['tuketim_birimi']==='kg/koli' ? 'selected' : ''; ?>>kg/koli</option></select></td>
                        <td><input class="bom-koli" name="koli_ici_adet[]" value="<?php echo number_format((float)$b['koli_ici_adet'],2,',','.'); ?>"></td>
                        <td><input class="bom-fire" name="fire_orani[]" value="<?php echo number_format((float)$b['fire_orani'],2,',','.'); ?>"></td>
                        <td><button type="button" class="trash-btn" title="Sil" onclick="this.closest('tr').remove();calcBom();">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="bom-footer"><button type="button" class="add-material" onclick="addBomRow();">+ Reçeteye Malzeme Kalemi Ekle</button><span class="bom-count">Toplam <?php echo count($bomKalemleri); ?> Hammadde Girdisi</span></div>
            </form>
        </div>
    </section>
    <template id="bomTpl"><tr><td><select name="hammadde_id[]"><?php foreach($hammaddeler as $h): ?><option value="<?php echo (int)$h['id']; ?>"><?php echo rm_e($h['kalem_adi']); ?></option><?php endforeach; ?></select></td><td><input class="bom-num" name="tuketim_miktari[]" value="0"></td><td><select class="bom-unit" name="tuketim_birimi[]"><option>gr/adet</option><option>adet/adet</option><option>gr/koli</option><option>kg/koli</option></select></td><td><input class="bom-koli" name="koli_ici_adet[]" value="1"></td><td><input class="bom-fire" name="fire_orani[]" value="0"></td><td><button type="button" class="trash-btn" title="Sil" onclick="this.closest('tr').remove();calcBom();">&times;</button></td></tr></template>
    <script>
    function trNum(v){ v=(v||'').toString().replace(/\./g,'').replace(',','.'); return parseFloat(v)||0; }
    function fmt(v){ return v.toLocaleString('tr-TR',{maximumFractionDigits:3}); }
    function calcBom(){}
    function addBomRow(){ document.getElementById('bomRows').insertAdjacentHTML('beforeend', document.getElementById('bomTpl').innerHTML); calcBom(); }
    document.addEventListener('input', calcBom); document.addEventListener('DOMContentLoaded', calcBom);
    </script>
    <?php endif; ?>

    <?php if($tab==='uretim'): ?>
    <section class="rm-panel"><h3>Üretim</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Dönem</th><th>Kod</th><th>Ürün</th><th>Koli Miktarı</th><th>Dağılım Oranı</th></tr></thead><tbody><?php foreach($uretimler as $u): $rate=$totalProduction>0 ? ((float)$u['koli_miktari']/$totalProduction*100) : 0; ?><tr><td><?php echo rm_e($u['donem']); ?></td><td><?php echo rm_e($u['urun_kodu'] ?? ''); ?></td><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo number_format((float)$u['koli_miktari'],0,',','.'); ?></td><td class="num"><?php echo number_format($rate,2,',','.'); ?>%</td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>

    <?php if($tab==='giderler'): $costTab = $_GET['cost'] ?? 'tum'; if(!in_array($costTab, ['tum','hammadde','genel','nakliye'], true)){ $costTab='tum'; } ?>
    <nav class="cost-tabs">
        <a class="<?php echo $costTab==='tum'?'active':''; ?>" href="?tab=giderler&cost=tum&donem=<?php echo rm_e($donem); ?>">T?m Giderler</a>
        <a class="<?php echo $costTab==='hammadde'?'active':''; ?>" href="?tab=giderler&cost=hammadde&donem=<?php echo rm_e($donem); ?>">Hammadde & Ambalaj</a>
        <a class="<?php echo $costTab==='genel'?'active':''; ?>" href="?tab=giderler&cost=genel&donem=<?php echo rm_e($donem); ?>">Genel Giderler</a>
        <a class="<?php echo $costTab==='nakliye'?'active':''; ?>" href="?tab=giderler&cost=nakliye&donem=<?php echo rm_e($donem); ?>">Nakliye / DEKAP</a>
    </nav>
    <section class="cost-subsection <?php echo $costTab==='tum'?'active':''; ?>">
        <section class="rm-kpis">
            <div class="rm-card"><span>Hammadde & Ambalaj</span><strong><?php echo count($hammaddeler); ?> Kalem</strong><em class="rm-change">Ay bazl? fiyat</em></div>
            <div class="rm-card"><span>Genel Giderler</span><strong>720 / 730 / 760 / 770</strong><em class="rm-change">Da??t?m anahtar?</em></div>
            <div class="rm-card"><span>Nakliye / DEKAP</span><strong><?php echo count($urunMaliyetleri); ?> ?r?n</strong><em class="rm-change">Koli bazl?</em></div>
            <div class="rm-card"><span>D?nem</span><strong><?php echo rm_e($currentDonem['donem_adi']); ?></strong><em class="rm-change">FIFO fiyat mant???</em></div>
        </section>
        <section class="rm-grid">
            <div class="rm-panel">
                <h3>Hammadde & Ambalaj Kalemleri</h3>
                <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>#</th><th>Malzeme</th><th>Birim</th><th>Ay ??i Fiyat Mant???</th></tr></thead><tbody>
                    <?php foreach($hammaddeler as $i=>$h): ?><tr><td><?php echo $i+1; ?></td><td class="rm-product"><?php echo rm_e($h['kalem_adi']); ?></td><td><?php echo rm_e($h['birim']); ?></td><td>Faturalar ay i?inde birden fazla girilebilir; ilk giren ilk ??kar mant???yla izlenir.</td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
            <aside class="rm-panel">
                <h3>T?m Gider Ba?l?klar?</h3>
                <div class="rm-break">
                    <div class="rm-break-row"><span>Hammadde & Ambalaj</span><b><?php echo count($hammaddeler); ?> kalem</b></div>
                    <div class="rm-break-row"><span>720 Direkt ???ilik</span><b>Genel gider</b></div>
                    <div class="rm-break-row"><span>730 Genel ?retim</span><b>Genel gider</b></div>
                    <div class="rm-break-row"><span>760 Pazarlama Sat?? Da??t?m</span><b>Genel gider</b></div>
                    <div class="rm-break-row"><span>770 Genel Y?netim</span><b>Genel gider</b></div>
                    <div class="rm-break-row"><span>Nakliye</span><b>Koli maliyeti</b></div>
                    <div class="rm-break-row"><span>DEKAP</span><b>Koli maliyeti</b></div>
                </div>
            </aside>
        </section>
    </section>
    <section class="cost-subsection <?php echo $costTab==='hammadde'?'active':''; ?>">
        <div class="cost-page">
            <div class="price-hero">
                <div><span class="price-kicker"><?php echo rm_e(rm_upper((string)$currentDonem['donem_adi'])); ?> F?YATLANDIRMA PANEL?</span><h2>Hammadde & Ambalaj Al?? Fiyatlar?</h2><p>Bu ekranda tan?mlanan fiyatlar re?ete maliyetleri ve mamul maliyet kartlar? taraf?ndan otomatik kullan?l?r. Ay i?inde birden fazla fatura girilebilir; maliyetlendirme ilk giren ilk ??kar mant???yla izlenir.</p></div>
                <div class="currency-card"><div class="currency-grid"><label>USD Dolar Kuru (TL)<input value="<?php echo $kurOzet['usd'] > 0 ? number_format($kurOzet['usd'],2,',','.') : '36,50'; ?>"></label><label>EUR Euro Kuru (TL)<input value="<?php echo $kurOzet['eur'] > 0 ? number_format($kurOzet['eur'],2,',','.') : '39,80'; ?>"></label></div><div style="display:flex;gap:10px;margin-top:18px"><button class="method-btn active" type="button">Sorgulanıyor...</button><button class="primary-add" type="button">Kurları Kaydet</button></div></div>
            </div>
            <div class="price-kpis"><div class="rm-card"><span>Toplam Aktif Malzeme</span><strong><?php echo count($hammaddeler); ?> Kalem</strong><em class="rm-change">Re?etelerde kullan?lan</em></div><div class="rm-card"><span>Para Birimi Da??l?m?</span><strong><?php echo count(array_filter($fiyatlar, function($f){ return str_contains((string)$f['ton_fiyati'],'$'); })); ?> USD</strong><em class="rm-change"><?php echo count(array_filter($fiyatlar, function($f){ return str_contains((string)$f['ton_fiyati'],'€'); })); ?> EUR / <?php echo count($fiyatlar); ?> kay?t</em></div><div class="rm-card"><span>H?zl? Aktar?m</span><strong>?nceki Ay</strong><em class="rm-change">Fiyatlar? bu aya kopyala</em></div><div class="rm-card dark"><span>Toplu Kaydet</span><strong>T?m Fiyatlar</strong><em class="rm-change">Re?eteyi senkronize et</em></div></div>
            <div class="price-tools"><input id="matSearch" placeholder="Malzeme ad? veya kodu ile filtrele..."><div class="price-filter"><span class="fifo-note">G?r?n?m:</span><button class="filter-pill active" type="button">T?m Malzemeler (<?php echo count($hammaddeler); ?>)</button><button class="filter-pill" type="button">USD ($)</button><button class="filter-pill" type="button">EUR (?)</button><button class="filter-pill" type="button">?oklu Fatura Girdili</button></div></div>
            <div class="price-list"><div class="price-list-head"><h3><?php echo rm_e(rm_upper((string)$currentDonem['donem_adi'])); ?> AKT?F F?YAT ??ZELGES?</h3><span><?php echo count($hammaddeler); ?> Malzeme Listeleniyor</span></div><div class="rm-table-wrap"><table><thead><tr><th>#</th><th>Malzeme Kodu & Ad?</th><th>Fatura Al?? Fiyat?</th><th>Al?? Birimi</th><th>Para Birimi</th><th>Uygulanan Kur</th><th>Net TL Birim Fiyat?</th><th>Ay ??i Fiyatlar</th><th>??lem</th></tr></thead><tbody>
                <?php foreach($hammaddeler as $i=>$h): $last=$fiyatHareketleri[$i % max(1,count($fiyatHareketleri))] ?? ['ton_fiyati'=>'0','tl_adet'=>0,'doviz_adet'=>'']; ?>
                <tr class="mat-row" data-name="<?php echo rm_e(rm_lower($h['kalem_adi'].' '.$h['kategori'])); ?>"><td><?php echo $i+1; ?></td><td><strong><?php echo rm_e($h['kalem_adi']); ?></strong><br><span class="bom-code">Kod: HM-<?php echo (int)$h['id']; ?> · <?php echo rm_e($h['kategori']); ?></span></td><td><input class="price-input" value="<?php echo rm_e($last['ton_fiyati']); ?>"></td><td><input class="price-input" value="<?php echo rm_e($h['birim']); ?>"></td><td><select class="price-input"><option>TL</option><option <?php echo str_contains((string)$last['ton_fiyati'],'$') ? 'selected' : ''; ?>>USD ($)</option><option <?php echo str_contains((string)$last['ton_fiyati'],'€') ? 'selected' : ''; ?>>EUR (€)</option></select></td><td><?php echo $kurOzet['usd'] > 0 ? number_format($kurOzet['usd'],4,',','.') : '-'; ?> TL</td><td class="tl-cell"><?php echo rm_money($last['tl_adet']); ?><br><small><?php echo rm_e($last['doviz_adet'] ?? ''); ?></small></td><td><button class="invoice-btn" type="button">+ Fatura Ekle</button><br><span class="fifo-note">FIFO ay içi giriş</span></td><td><button class="primary-add" type="button">Kaydet</button></td></tr>
                <?php endforeach; ?>
            </tbody></table></div></div>
        </div>
        <script>var ms=document.getElementById('matSearch'); if(ms){ms.addEventListener('input',function(){var q=this.value.toLocaleLowerCase('tr-TR');document.querySelectorAll('.mat-row').forEach(function(r){r.style.display=(!q||r.dataset.name.indexOf(q)>-1)?'':'none';});});}</script>
    </section>
    <section class="cost-subsection <?php echo $costTab==='genel'?'active':''; ?>">
    <section class="expense-grid">
        <div class="expense-panel">
            <div class="expense-cards">
                <div class="expense-card"><label>720 Direkt ???ilik Giderleri (TL)</label><input class="expense-input" value="1.250.000"><p>Fabrika personeli ve mavi yaka direkt i??ilik maliyetleri</p></div>
                <div class="expense-card"><label>730 Genel ?retim Giderleri (TL)</label><input class="expense-input" value="890.000"><p>Elektrik, su, tesis bak?m, amortisman vb.</p></div>
                <div class="expense-card"><label>760 Pazarlama Sat?? Da??t?m (TL)</label><input class="expense-input" value="420.000"><p>Saha sat??, reklam ve pazarlama birimi giderleri</p></div>
                <div class="expense-card"><label>770 Genel Y?netim Giderleri (TL)</label><input class="expense-input" value="310.000"><p>Merkez ofis, idari personel, muhasebe ve bilgi i?lem</p></div>
            </div>
            <div class="method-title">Gider Da??t?m Y?ntemi (Maliyet Anahtar?)</div>
            <div class="method-buttons">
                <button class="method-btn active" type="button">Koli Miktar?na G?re</button>
                <button class="method-btn" type="button">Hacim/Litreye G?re</button>
                <button class="method-btn" type="button">?r?n Bazl? Tutar</button>
                <button class="method-btn" type="button">?zel Da??t?m Anahtar?</button>
            </div>
        </div>
        <aside class="analysis-card">
            <h3>DA?ITIM ANAL?Z? SUMMARY</h3>
            <div class="kpi"><span>D?nem Toplam Genel Gider</span><strong class="amber" id="expenseTotal">2.870.000 TL</strong></div>
            <div class="kpi"><span>D?nem Toplam ?retim</span><strong id="expenseProduction">380.000 Koli</strong></div>
            <div class="kpi"><span>Hesaplanan Koli Ba??na Gider Pay?</span><strong class="cyan" id="expensePerBox">7,55 TL / Koli</strong></div>
            <div class="analysis-note">Giderler se?ilen ?Koli? anahtar?na g?re ?retilen t?m ?r?nlerin koli ba??na maliyetine otomatik eklenir.</div>
        </aside>
    </section>
    </section>
    <section class="cost-subsection <?php echo $costTab==='nakliye'?'active':''; ?>">
        <section class="rm-panel"><h3>Nakliye / DEKAP</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>?r?n</th><th>Nakliye TL/Koli</th><th>DEKAP TL/Koli</th><th>Nakliyeli</th><th>Nakliyesiz</th><th>Nakliyesiz + DEKAP</th><th>Nakliye + DEKAP Dahil</th></tr></thead><tbody><?php foreach($urunMaliyetleri as $u): $nd=$ndByProduct[$u['urun_adi']] ?? ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0]; ?><tr><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo rm_money($nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money($nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money($u['toplam']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['nakliye_tl_koli']+(float)$nd['dekap_tl_koli']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    </section>
    <script>
    (function(){
        var production = 380000;
        function num(v){ return parseFloat((v || '').toString().replace(/\./g,'').replace(',','.')) || 0; }
        function money(v, d){ return v.toLocaleString('tr-TR',{minimumFractionDigits:d,maximumFractionDigits:d}); }
        function calc(){
            var total = 0;
            document.querySelectorAll('.expense-input').forEach(function(i){ total += num(i.value); });
            document.getElementById('expenseTotal').textContent = money(total, 0) + ' TL';
            document.getElementById('expensePerBox').textContent = money(production  total / production : 0, 2) + ' TL / Koli';
        }
        document.querySelectorAll('.expense-input').forEach(function(i){ i.addEventListener('input', calc); });
        document.querySelectorAll('.method-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('.method-btn').forEach(function(x){ x.classList.remove('active'); }); b.classList.add('active'); }); });
        calc();
    })();
    </script>
    <?php endif; ?>

    <?php if($tab==='nakliye'): ?>
    <section class="rm-panel"><h3>Nakliye / DEKAP</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>?r?n</th><th>Nakliye TL/Koli</th><th>DEKAP TL/Koli</th><th>Nakliyeli</th><th>Nakliyesiz</th><th>Nakliyesiz + DEKAP</th><th>Nakliye + DEKAP Dahil</th></tr></thead><tbody><?php foreach($urunMaliyetleri as $u): $nd=$ndByProduct[$u['urun_adi']] ?? ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0]; ?><tr><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo rm_money($nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money($nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money($u['toplam']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['nakliye_tl_koli']+(float)$nd['dekap_tl_koli']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>
</main>
</body>
</html>








