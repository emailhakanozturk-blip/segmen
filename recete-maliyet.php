<?php
session_start();
require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header('Location: login.php');
    exit;
}

function rm_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function rm_money($value): string { return '₺' . number_format((float)$value, 4, ',', '.'); }
function rm_money4($value): string { return '₺' . number_format((float)$value, 4, ',', '.'); }
if(!function_exists('str_contains')){
    function str_contains($haystack, $needle){ return $needle === '' || strpos((string)$haystack, (string)$needle) !== false; }
}
function rm_lower($value): string { return function_exists('mb_strtolower') ? mb_strtolower((string)$value, 'UTF-8') : strtolower((string)$value); }
function rm_upper($value): string { return function_exists('mb_strtoupper') ? mb_strtoupper((string)$value, 'UTF-8') : strtoupper((string)$value); }
function rm_currency_num($value): float
{
    $value = str_replace([' ', 'TL', '₺', '$', '€', 'USD', 'EUR'], '', trim((string)$value));
    if(str_contains($value, ',')){ $value = str_replace('.', '', $value); $value = str_replace(',', '.', $value); }
    elseif(preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)){ $value = str_replace('.', '', $value); }
    return (float)$value;
}
function rm_num($value): float
{
    $value = str_replace([' ', 'TL', '₺'], '', trim((string)$value));
    if(str_contains($value, ',')){ $value = str_replace('.', '', $value); $value = str_replace(',', '.', $value); }
    elseif(preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value)){ $value = str_replace('.', '', $value); }
    return (float)$value;
}
function rm_input_num($value, int $dec = 4): string
{
    $v = (float)$value;
    $d = abs($v - round($v)) < 0.000001 ? 0 : $dec;
    return number_format($v, $d, ',', '.');
}
function rm_gider_values(PDO $db, string $donem): array
{
    $defaults = ['g730'=>7.858209,'g760'=>2.636136,'g770'=>12.485855,'g720'=>9.163123];
    try {
        $q = $db->prepare("SELECT * FROM recete_gider_ayarlari WHERE donem=? LIMIT 1");
        $q->execute([$donem]);
        $row = $q->fetch();
        if($row){
            return [
                'g730'=>(float)$row['g730'],
                'g760'=>(float)$row['g760'],
                'g770'=>(float)$row['g770'],
                'g720'=>(float)$row['g720'],
            ];
        }
    } catch(Throwable $e) {}
    return $defaults;
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
    if((string)($u['urun_kodu'] ?? '') === 'SU-PET-500'){ return ['pet','PET ÅiÅŸeler','PET ambalajlÄ± Ã¼rÃ¼nler']; }
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
function rm_kalem_id(PDO $db, string $kategori, string $ad, string $birim, float $fiyat = 0): int
{
    $stmt = $db->prepare("INSERT INTO maliyet_kalemleri (kategori,kalem_adi,birim,birim_fiyat) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE birim=VALUES(birim), birim_fiyat=VALUES(birim_fiyat)");
    $stmt->execute([$kategori, $ad, $birim, $fiyat]);
    $q = $db->prepare("SELECT id FROM maliyet_kalemleri WHERE kategori=? AND kalem_adi=? LIMIT 1");
    $q->execute([$kategori, $ad]);
    return (int)$q->fetchColumn();
}
function rm_recete_template_download(): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="recete_ornek_sablon.xls"');
    echo "\xEF\xBB\xBF";
    $rows = [
        ['Preform / Cam Şişe','1,38','USD','47,7168','1000','9,2','gr/adet','24','3'],
        ['Kapak','205','TL','1','1000','1','adet/adet','24','1,5'],
        ['Etiket','170','TL','1','1000','1','adet/adet','24','2'],
        ['Shrink Film','64','TL','1','1000','26','gr/koli','1','3,5'],
        ['Strech Film','70','TL','1','1000','8,8','gr/koli','1','2'],
        ['Palet Ara Seperatör','22','TL','1','116','5','adet/koli','1','3'],
    ];
    echo '<html><head><meta charset="UTF-8"><style>
        table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:11pt}
        th{background:#0f172a;color:#fff;font-weight:bold;text-align:center}
        th,td{border:1px solid #9ca3af;padding:8px;vertical-align:middle}
        td.num{text-align:right} .note{background:#eff6ff;font-weight:bold}
    </style></head><body>';
    echo '<table>';
    echo '<tr><td class="note" colspan="9">Reçete Excel Şablonu - Satırları doldurup aynı dosyayı yükleyebilirsiniz.</td></tr>';
    echo '<tr><th>Hammadde / Malzeme</th><th>Alış Fiyatı</th><th>Para Cinsi</th><th>Döviz Kuru</th><th>Bölen</th><th>Kullanım Miktarı</th><th>Birim</th><th>Kolideki Adet</th><th>Fire %</th></tr>';
    foreach($rows as $r){
        echo '<tr>';
        foreach($r as $i=>$v){ echo '<td'.($i>0 && $i!==2 && $i!==6 ? ' class="num"' : '').'>'.rm_e($v).'</td>'; }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
function rm_read_recete_upload(array $file): array
{
    if(empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])){ throw new RuntimeException('Excel dosyası seçilmedi.'); }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $rows = [];
    if(in_array($ext, ['xlsx','xlsm'], true)){
        if(!class_exists('ZipArchive')){ throw new RuntimeException('XLSX okumak için PHP ZipArchive eklentisi gerekli. Örnek .xls şablonunu doldurup yükleyebilirsiniz.'); }
        require_once __DIR__ . '/vendor/autoload.php';
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string)$file['tmp_name'])->getActiveSheet();
        for($r=2; $r <= $sheet->getHighestRow(); $r++){
            $row = [];
            for($c=1; $c<=9; $c++){
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
                $row[] = trim((string)$sheet->getCell($cell)->getFormattedValue());
            }
            if(trim(implode('', $row)) !== ''){ $rows[] = $row; }
        }
        return $rows;
    }
    $first = (string)file_get_contents((string)$file['tmp_name'], false, null, 0, 512);
    $delim = substr_count($first, "\t") >= substr_count($first, ';') ? "\t" : ';';
    $fh = fopen((string)$file['tmp_name'], 'r');
    if(!$fh){ throw new RuntimeException('Excel dosyası okunamadı.'); }
    fgetcsv($fh, 0, $delim, '"', '\\');
    while(($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false){
        $row = array_pad(array_map('trim', $row), 9, '');
        if(trim(implode('', $row)) !== ''){ $rows[] = array_slice($row, 0, 9); }
    }
    fclose($fh);
    return $rows;
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
            ->execute([(float)$r['giren'],(float)$r['sevk'],(float)$r['fire'],'Stok hareketlerinden otomatik güncellendi',$id]);
    } else {
        $db->prepare("INSERT INTO maliyet_stok_sayimlari (donem,depo_id,urun_id,giren,sevk,fire,aciklama) VALUES (?,?,?,?,?,?,?)")
            ->execute([$donem,$depoId,$urunId,(float)$r['giren'],(float)$r['sevk'],(float)$r['fire'],'Stok hareketlerinden otomatik güncellendi']);
    }
}
function rm_sync_bom_to_maliyet(PDO $db, int $receteId, int $urunId, string $donem): void
{
    if($receteId <= 0 || $urunId <= 0 || $donem === ''){ return; }
    $db->prepare("DELETE r FROM maliyet_receteler r JOIN maliyet_kalemleri k ON k.id=r.kalem_id WHERE r.donem=? AND r.urun_id=? AND k.kategori='Hammadde'")
        ->execute([$donem,$urunId]);
    $rows = $db->prepare("SELECT b.*, k.kalem_adi FROM recete_bom_kalemleri b JOIN maliyet_kalemleri k ON k.id=b.hammadde_id WHERE b.recete_id=? ORDER BY b.id");
    $rows->execute([$receteId]);
    $ins = $db->prepare("INSERT INTO maliyet_receteler (donem,urun_id,kalem_id,miktar,fire_orani,satir_tutari,aciklama) VALUES (?,?,?,?,?,?,?)");
    foreach($rows->fetchAll() as $r){
        $alis = (float)($r['alis_fiyati'] ?? 0);
        $kur = (float)($r['doviz_kuru'] ?? 1);
        $bolen = max((float)($r['bolen'] ?? 1), 0.000001);
        $miktar = (float)($r['tuketim_miktari'] ?? 0);
        $koli = (float)($r['koli_ici_adet'] ?? 1);
        $fire = (float)($r['fire_orani'] ?? 0);
        $birim = ($alis * $kur) / $bolen;
        $koliNet = $birim * $miktar * $koli;
        $fireli = $koliNet * (1 + $fire / 100);
        $aciklama = (string)$r['kalem_adi'] . ': (alış x kur / bölen) x kullanım x koli adedi x fire.';
        $ins->execute([$donem,$urunId,(int)$r['hammadde_id'],1,$fire,$fireli,$aciklama]);
    }
    rm_sync_standard_gider_to_maliyet($db, $urunId, $donem);
}
function rm_sync_standard_gider_to_maliyet(PDO $db, int $urunId, string $donem): void
{
    if($urunId <= 0 || $donem === ''){ return; }
    $gv = rm_gider_values($db, $donem);
    $giderler = [
        ['730 İŞÇİLİK HARİÇ GİDER', $gv['g730'], 'Koli başına işçilik hariç gider payı'],
        ['760 PAZARLAMA GİDERİ', $gv['g760'], 'Koli başına pazarlama gideri payı'],
        ['GENEL YÖNETİM GİDERİ', $gv['g770'], 'Koli başına genel yönetim gideri payı'],
        ['İŞÇİLİK GİDERİ', $gv['g720'], 'Koli başına işçilik gideri payı'],
    ];
    $names = array_column($giderler, 0);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $params = array_merge([$donem, $urunId], $names);
    $db->prepare("DELETE r FROM maliyet_receteler r JOIN maliyet_kalemleri k ON k.id=r.kalem_id WHERE r.donem=? AND r.urun_id=? AND k.kalem_adi IN ($placeholders)")
        ->execute($params);
    $ins = $db->prepare("INSERT INTO maliyet_receteler (donem,urun_id,kalem_id,miktar,fire_orani,satir_tutari,aciklama) VALUES (?,?,?,?,?,?,?)");
    foreach($giderler as $g){
        $kid = rm_kalem_id($db, 'Genel Gider', $g[0], 'Koli', 0);
        if($kid > 0){ $ins->execute([$donem,$urunId,$kid,1,0,$g[1],$g[2]]); }
    }
}
function rm_seed_cam033_fixed_cost(PDO $db, string $donem): void
{
    $urunQ = $db->prepare("SELECT id FROM maliyet_urunler WHERE urun_kodu='SU-CAM-033' LIMIT 1");
    $urunQ->execute();
    $urunId = (int)$urunQ->fetchColumn();
    if($urunId <= 0 || $donem === ''){ return; }
    $camRows = [
        ['Cam Şişe', 'Adet', 46.3212],
        ['Alüminyum Kapak', 'Adet', 7.6440],
        ['Stiker Etiket', 'Adet', 0.9000],
        ['Shrink (KĞ)', 'Kg', 2.26664064],
        ['Strech Film', 'Kg', 0],
        ['Palet Ara Seperatör', 'Adet', 0],
        ['Emniyet Bandı', 'Adet', 0],
        ['Karton Koli', 'Adet', 0],
        ['Koli Seperatör', 'Adet', 0],
        ['Alt folyo', 'Kg', 0],
        ['Üst Folyo', 'Kg', 0],
    ];
    $bomIns = $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,?,?,'0,33 L Cam Şişe Su maliyet reçetesi',1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1");
    $bomIns->execute([$urunId,$donem,$donem]);
    $bomQ = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem=? AND versiyon=? LIMIT 1");
    $bomQ->execute([$urunId,$donem,$donem]);
    $bomId = (int)$bomQ->fetchColumn();
    if($bomId <= 0){ return; }
    $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$bomId]);
    $ins = $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    foreach($camRows as $r){
        $kid = rm_kalem_id($db, 'Hammadde', $r[0], $r[1], 0);
        $net = (float)$r[2];
        $unit = $r[1] === 'Kg' ? 'gr/koli' : 'adet/adet';
        $ins->execute([$bomId,$kid,$net,'TL',1,1,1,$unit,$net,1,3]);
    }
    rm_sync_bom_to_maliyet($db, $bomId, $urunId, $donem);
    $db->prepare("INSERT INTO recete_nakliye_dekap (donem,urun_adi,nakliye_tl_koli,dekap_tl_koli) VALUES (?,'0,33 L Cam Şişe Su',3.74,6.36) ON DUPLICATE KEY UPDATE urun_adi=VALUES(urun_adi)")
        ->execute([$donem]);
}

if(isset($_GET['recete_sablon'])){ rm_recete_template_download(); }

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

$db->exec("CREATE TABLE IF NOT EXISTS recete_gider_ayarlari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donem VARCHAR(20) NOT NULL UNIQUE,
    g730 DECIMAL(15,6) NOT NULL DEFAULT 7.858209,
    g760 DECIMAL(15,6) NOT NULL DEFAULT 2.636136,
    g770 DECIMAL(15,6) NOT NULL DEFAULT 12.485855,
    g720 DECIMAL(15,6) NOT NULL DEFAULT 9.163123,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
try { $db->exec("ALTER TABLE recete_gider_ayarlari MODIFY g730 DECIMAL(15,6) NOT NULL DEFAULT 7.858209"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_gider_ayarlari MODIFY g760 DECIMAL(15,6) NOT NULL DEFAULT 2.636136"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_gider_ayarlari MODIFY g770 DECIMAL(15,6) NOT NULL DEFAULT 12.485855"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_gider_ayarlari MODIFY g720 DECIMAL(15,6) NOT NULL DEFAULT 9.163123"); } catch(Throwable $e) {}

$db->prepare("INSERT IGNORE INTO recete_gider_ayarlari (donem,g730,g760,g770,g720) VALUES (?,7.858209,2.636136,12.485855,9.163123)")
    ->execute([$donem ?? '2026-04']);
$db->prepare("UPDATE recete_gider_ayarlari
    SET g730=7.858209, g760=2.636136, g770=12.485855, g720=9.163123
    WHERE donem=?
      AND ABS(g730-7.8582) < 0.0002
      AND ABS(g760-2.6361) < 0.0002
      AND ABS(g770-12.4859) < 0.0002
      AND ABS(g720-9.1631) < 0.0002")
    ->execute([$donem ?? '2026-04']);

$db->exec("CREATE TABLE IF NOT EXISTS recete_bom (
    id INT AUTO_INCREMENT PRIMARY KEY,
    urun_id INT NOT NULL,
    donem VARCHAR(20) NOT NULL,
    versiyon VARCHAR(20) NOT NULL DEFAULT 'aktif',
    aciklama VARCHAR(255) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bom (urun_id, donem, versiyon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$db->exec("CREATE TABLE IF NOT EXISTS recete_bom_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recete_id INT NOT NULL,
    hammadde_id INT NOT NULL,
    alis_fiyati DECIMAL(15,6) NOT NULL DEFAULT 0,
    para_cinsi VARCHAR(10) NOT NULL DEFAULT 'TL',
    doviz_kuru DECIMAL(15,6) NOT NULL DEFAULT 1,
    bolen DECIMAL(15,6) NOT NULL DEFAULT 1,
    tuketim_miktari DECIMAL(15,6) NOT NULL DEFAULT 0,
    tuketim_birimi VARCHAR(30) NOT NULL DEFAULT 'adet/koli',
    birim_fiyat DECIMAL(15,6) NOT NULL DEFAULT 0,
    koli_ici_adet DECIMAL(12,2) NOT NULL DEFAULT 1,
    fire_orani DECIMAL(8,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bom_kalem_recete (recete_id),
    INDEX idx_bom_kalem_hammadde (hammadde_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");
try { $db->exec("ALTER TABLE recete_bom_kalemleri ADD COLUMN alis_fiyati DECIMAL(15,6) NOT NULL DEFAULT 0 AFTER hammadde_id"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_bom_kalemleri ADD COLUMN para_cinsi VARCHAR(10) NOT NULL DEFAULT 'TL' AFTER alis_fiyati"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_bom_kalemleri ADD COLUMN doviz_kuru DECIMAL(15,6) NOT NULL DEFAULT 1 AFTER para_cinsi"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_bom_kalemleri ADD COLUMN bolen DECIMAL(15,6) NOT NULL DEFAULT 1 AFTER doviz_kuru"); } catch(Throwable $e) {}
try { $db->exec("ALTER TABLE recete_bom_kalemleri ADD COLUMN birim_fiyat DECIMAL(15,6) NOT NULL DEFAULT 0 AFTER tuketim_birimi"); } catch(Throwable $e) {}

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
$db->exec("CREATE TABLE IF NOT EXISTS maliyet_silinen_urunler (
    urun_kodu VARCHAR(40) PRIMARY KEY,
    deleted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci");

$aylar2026 = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$donemIns = $db->prepare("INSERT INTO recete_donemler (donem,donem_adi,toplam_uretim,durum,son_hesaplama) VALUES (?,?,0,'Açık',NULL) ON DUPLICATE KEY UPDATE donem_adi=VALUES(donem_adi), durum=VALUES(durum)");
foreach($aylar2026 as $m=>$ad){ $donemIns->execute([sprintf('2026-%02d',$m), $ad.' 2026']); }
$urunSeed = [
    ['SU-PET-033','0,33 L Pet Şişe Su','PET Şişeler','PET Şişe',24],
    ['SU-PET-050','0,50 L Pet Şişe Su','PET Şişeler','PET Şişe',24],
    ['SU-PET-100','1 L Pet Şişe Su','PET Şişeler','PET Şişe',12],
    ['SU-PET-150','1,5 L Pet Şişe Su','PET Şişeler','PET Şişe',6],
    ['SU-PET-500','5 L Pet Şişe Su','PET Şişeler','PET Şişe',4],
    ['SU-CAM-033','0,33 L Cam Şişe Su','Cam Ürünler','Cam Şişe',24],
    ['SU-CAM-075','0,75 L Cam Şişe Su','Cam Ürünler','Cam Şişe',12],
    ['SU-DAM-1900','19 L Damacana Su','Damacana','Damacana',1],
    ['SU-BAR-200','200 ml Bardak Su','Bardak','Plastik bardak',60],
];
$urunIns = $db->prepare("INSERT INTO maliyet_urunler (urun_kodu,urun_adi,urun_grubu,ambalaj_tipi,koli_ici_adet) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE urun_adi=VALUES(urun_adi), urun_grubu=VALUES(urun_grubu), ambalaj_tipi=VALUES(ambalaj_tipi), koli_ici_adet=VALUES(koli_ici_adet)");
$deletedProductQ = $db->prepare("SELECT 1 FROM maliyet_silinen_urunler WHERE urun_kodu=? LIMIT 1");
foreach($urunSeed as $u){
    $deletedProductQ->execute([$u[0]]);
    if(!$deletedProductQ->fetchColumn()){ $urunIns->execute($u); }
}

$kalemSeed = [
    ['Hammadde','Preform / Cam Şişe','Adet',0.0864696],
    ['Hammadde','Kapak','Adet',0.1906766],
    ['Hammadde','Kulp','Adet',0],
    ['Hammadde','Etiket','Adet',0.0799767],
    ['Hammadde','Shrink Film','Kg',0.1241615],
    ['Hammadde','Strech Film','Kg',0.5099],
    ['Hammadde','Palet Ara Seperatör','Adet',0],
    ['Hammadde','Emniyet Bandı','Adet',0.3599],
    ['Hammadde','Karton Koli','Adet',13.0000],
    ['Hammadde','Koli Seperatör','Adet',0],
    ['Hammadde','Alt folyo','Kg',0.0020],
    ['Hammadde','Üst Folyo','Kg',0.5099],
];
$kalemIns = $db->prepare("INSERT INTO maliyet_kalemleri (kategori,kalem_adi,birim,birim_fiyat) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE birim=VALUES(birim), birim_fiyat=VALUES(birim_fiyat)");
foreach($kalemSeed as $k){ $kalemIns->execute($k); }

$fiyatIns = $db->prepare("INSERT INTO maliyet_fiyatlama (fiyat_tarihi,kategori,urun_adi,ton_fiyati,doviz_adet,tl_adet,aciklama) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tl_adet=VALUES(tl_adet), aciklama=VALUES(aciklama)");
foreach($kalemSeed as $k){ $fiyatIns->execute(['2026-04-01',$k[0],$k[1],'', '', $k[3], 'Nisan 2026 başlangıç fiyatı']); }

$camHaziranRows = [
    ['Cam Şişe','Adet',3.8601,3.8601,'TL',1,1,1,'adet/adet',null,3],
    ['Alüminyum Kapak','Adet',0.6370,0.6370,'TL',1,1,1,'adet/adet',null,1.5],
    ['Stiker Etiket','Adet',0.9000,0.9000,'TL',1,1,1,'adet/adet',null,2],
    ['Shrink (KĞ)','Kg',68.0000,68.0000,'TL',1,1000,26,'gr/koli',1,3.5],
];
foreach($camHaziranRows as $r){
    rm_kalem_id($db, 'Hammadde', $r[0], $r[1], $r[2]);
    $fiyatIns->execute(['2026-06-01','Hammadde',$r[0],'','',$r[2],'Haziran 2026 cam şişe birim maliyeti']);
}
$camBomIns = $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,'2026-06','2026-06','Haziran 2026 cam şişe reçetesi',1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1");
$camBomGet = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem='2026-06' AND versiyon='2026-06' LIMIT 1");
$camBomSum = $db->prepare("SELECT COUNT(*) satir, COALESCE(SUM(k.alis_fiyati),0) toplam FROM recete_bom_kalemleri k WHERE k.recete_id=?");
$camBomKalemIns = $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
foreach(['SU-CAM-033','SU-CAM-075'] as $camKod){
    $camUrunQ = $db->prepare("SELECT id,koli_ici_adet FROM maliyet_urunler WHERE urun_kodu=? LIMIT 1");
    $camUrunQ->execute([$camKod]);
    $camUrun = $camUrunQ->fetch();
    if(!$camUrun){ continue; }
    $camBomIns->execute([(int)$camUrun['id']]);
    $camBomGet->execute([(int)$camUrun['id']]);
    $camBomId = (int)$camBomGet->fetchColumn();
    if($camBomId <= 0){ continue; }
    $camBomSum->execute([$camBomId]);
    $camBomState = $camBomSum->fetch();
    if((int)($camBomState['satir'] ?? 0) > 0 && (float)($camBomState['toplam'] ?? 0) > 0){ continue; }
    $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$camBomId]);
    foreach($camHaziranRows as $r){
        $hId = rm_kalem_id($db, 'Hammadde', $r[0], $r[1], $r[2]);
        $koliAdet = $r[9] === null ? (float)$camUrun['koli_ici_adet'] : (float)$r[9];
        $camBomKalemIns->execute([$camBomId,$hId,$r[3],$r[4],$r[5],$r[6],$r[7],$r[8],0,$koliAdet,$r[10]]);
    }
}

$pet033Id = (int)$db->query("SELECT id FROM maliyet_urunler WHERE urun_kodu='SU-PET-033' LIMIT 1")->fetchColumn();
if($pet033Id > 0){
    $usdKur = 36.50;
    $eurKur = 39.80;
    $koliIci = 24;
    $fireOrani = 3;
    $toplamUretimKoli = 600000;
    $preformUsdTon = 1950;
    $kapakUsdKg = 4.30;
    $etiketEurPaket = 7;
    $etiketRuloAdet = 4500;
    $shrinkUsdTon = 2800;
    $strecUsdTon = 2600;
    $seperatorTlAdet = 22;
    $giderIsçilikHaric = 890000;
    $giderPazarlama = 420000;
    $giderGenelYonetim = 310000;
    $giderIscilik = 1250000;

    $preformAdet = 9.2 * (($preformUsdTon / 1000000) * $usdKur);
    $kapakAdet = ($kapakUsdKg / 1000) * $usdKur;
    $etiketAdet = ($etiketEurPaket / $etiketRuloAdet) * $eurKur;
    $shrinkAdet = (26 * (($shrinkUsdTon / 1000000) * $usdKur)) / $koliIci;
    $strecAdet = (500 * (($strecUsdTon / 1000000) * $usdKur)) / ($koliIci * 116);
    $seperatorAdet = (5 * $seperatorTlAdet) / (116 * $koliIci);
    $hammaddeKoli = ($preformAdet + $kapakAdet + $etiketAdet + $shrinkAdet + $strecAdet + $seperatorAdet) * $koliIci;
    $fireTutari = $hammaddeKoli * ($fireOrani / 100);
    $giderPaylari = [
        ['730 Genel Üretim', $giderIsçilikHaric / $toplamUretimKoli, 'İşçilik hariç gider payı'],
        ['760 Pazarlama Satış Dağıtım', $giderPazarlama / $toplamUretimKoli, 'Pazarlama gideri payı'],
        ['770 Genel Yönetim', $giderGenelYonetim / $toplamUretimKoli, 'Genel yönetim gideri payı'],
        ['720 Direkt İşçilik', $giderIscilik / $toplamUretimKoli, 'İşçilik gideri payı'],
    ];

    $db->prepare("DELETE FROM maliyet_receteler WHERE donem='2026-04' AND urun_id=?")->execute([$pet033Id]);
    $receteIns = $db->prepare("INSERT INTO maliyet_receteler (donem,urun_id,kalem_id,miktar,fire_orani,satir_tutari,aciklama) VALUES ('2026-04',?,?,?,?,?,?)");
    $rows033 = [
        ['Hammadde','Preform / Cam Şişe','Adet',$koliIci,0,$preformAdet * $koliIci,'9,2 gr x preform TL/gr x 24'],
        ['Hammadde','Kapak','Adet',$koliIci,0,$kapakAdet * $koliIci,'29,25 mm kapak TL/adet x 24'],
        ['Hammadde','Etiket','Adet',$koliIci,0,$etiketAdet * $koliIci,'0,33 etiket paket/rulo x euro kuru x 24'],
        ['Hammadde','Shrink Film','Kg',26,0,$shrinkAdet * $koliIci,'(26 x shrink TL/gr)'],
        ['Hammadde','Strech Film','Kg',500,0,$strecAdet * $koliIci,'(500 x streç TL/gr) / 116'],
        ['Hammadde','Palet Ara Seperatör','Adet',5,0,$seperatorAdet * $koliIci,'(5 x seperatör TL/adet) / 116'],
        ['Hammadde','Fire','Koli',1,$fireOrani,$fireTutari,'Hammadde koli toplamı x %3 fire'],
    ];
    foreach($rows033 as $r){
        $kid = rm_kalem_id($db, $r[0], $r[1], $r[2], 0);
        if($kid > 0){ $receteIns->execute([$pet033Id,$kid,$r[3],$r[4],$r[5],$r[6]]); }
    }
    foreach($giderPaylari as $g){
        $kid = rm_kalem_id($db, 'Genel Gider', $g[0], 'Koli', 0);
        if($kid > 0){ $receteIns->execute([$pet033Id,$kid,1,0,$g[1],$g[2] . ' / 600.000 koli']); }
    }
    $db->prepare("INSERT INTO recete_nakliye_dekap (donem,urun_adi,nakliye_tl_koli,dekap_tl_koli) VALUES ('2026-04','0,33 L Pet Şişe Su',8.8,8.4) ON DUPLICATE KEY UPDATE nakliye_tl_koli=VALUES(nakliye_tl_koli), dekap_tl_koli=VALUES(dekap_tl_koli)")
        ->execute();

    $bomQ = $db->prepare("SELECT id, aciklama FROM recete_bom WHERE urun_id=? AND donem='2026-04' AND aktif=1 ORDER BY id DESC LIMIT 1");
    $bomQ->execute([$pet033Id]);
    $petBom = $bomQ->fetch();
    $seedMarker = '0,33 lt Standart - Hammadde ve Reçete Hücre Matrisi';
    if(!$petBom || (string)($petBom['aciklama'] ?? '') !== $seedMarker){
        $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,'2026-04','2026-04',?,1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1")
            ->execute([$pet033Id,$seedMarker]);
        $bomQ->execute([$pet033Id]);
        $petBom = $bomQ->fetch();
        $petBomId = (int)($petBom['id'] ?? 0);
        if($petBomId > 0){
            $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$petBomId]);
            $bomIns = $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $bomRows033 = [
                ['Preform / Cam Şişe',1.38,'USD',47.7168,1000,9.2,'gr/adet',24,3],
                ['Kapak',205,'TL',1,1000,1,'adet/adet',24,1.5],
                ['Etiket',170,'TL',1,1000,1,'adet/adet',24,2],
                ['Shrink Film',64,'TL',1,1000,26,'gr/koli',1,3.5],
                ['Strech Film',70,'TL',1,1000,8.8,'gr/koli',1,2],
            ];
            foreach($bomRows033 as $r){
                $kid = rm_kalem_id($db, 'Hammadde', $r[0], str_contains($r[6], 'gr') || str_contains($r[6], 'kg') ? 'Kg' : 'Adet', 0);
                $birim = ($r[1] * $r[3]) / max((float)$r[4], 0.000001);
                $bomIns->execute([$petBomId,$kid,$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$birim,$r[7],$r[8]]);
            }
        }
    }
}

// Nisan 2026 Excel şablonu: ürün maliyetleri ve üretim dağılımı.
$excelNisanProducts = [
    ['SU-PET-033','0,33 L Pet Şişe Su','PET Şişeler','PET Şişe',24,1672,30.6584871225,51.4579573725,'0,33 lt Standart'],
    ['SU-PET-033-EUR','0,33 L Pet Şişe Su Euro','PET Şişeler','PET Şişe',24,0,30.7471804481,51.5466506981,'0,33 lt Euro'],
    ['SU-PET-050','0,50 L Pet Şişe Su','PET Şişeler','PET Şişe',24,114548,31.8390130569,52.6384833069,'0,50 lt Standart'],
    ['SU-PET-050-EUR','0,50 L Pet Şişe Su Euro','PET Şişeler','PET Şişe',24,0,32.1157858802,52.9152561302,'0,50 lt Euro'],
    ['SU-PET-100','1 L Pet Şişe Su','PET Şişeler','PET Şişe',12,0,27.4595701258,48.2590403758,'1 lt Standart'],
    ['SU-PET-150','1,5 L Pet Şişe Su','PET Şişeler','PET Şişe',12,52193,32.9195491282,53.7190193782,'1,5 lt Standart'],
    ['SU-PET-150-EUR','1,5 L Pet Şişe Su Euro','PET Şişeler','PET Şişe',12,0,33.1141105676,53.9135808176,'1,5 lt Euro'],
    ['SU-PET-500','5 L Pet Şişe Su','PET Şişeler','PET Şişe',4,4011,28.5968782821,49.3963485321,'5 lt Standart'],
    ['SU-PET-500-EUR','5 L Pet Şişe Su Euro','PET Şişeler','PET Şişe',4,0,34.3197048011,55.1191750511,'5 lt Euro'],
    ['SU-CAM-033','0,33 L Cam Şişe Su','Cam Ürünler','Cam Şişe',24,0,58.8458,90.9891,'Cam Şişe 0,33'],
    ['SU-CAM-075','0,75 L Cam Şişe Su','Cam Ürünler','Cam Şişe',12,0,54.1276587185,74.9271289685,'Cam Şişe 0,75'],
    ['SU-DAM-1900','19 L Damacana Su','Damacana','Damacana',1,109104,3.8487428977,24.6482131477,'19 lt Standart'],
    ['SU-BAR-200','200 ml Bardak Su','Bardak','Plastik bardak',60,0,31.5189188813,52.3183891313,'200 cc Standart'],
];
$excelUrunIns = $db->prepare("INSERT INTO maliyet_urunler (urun_kodu,urun_adi,urun_grubu,ambalaj_tipi,koli_ici_adet) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE urun_adi=VALUES(urun_adi), urun_grubu=VALUES(urun_grubu), ambalaj_tipi=VALUES(ambalaj_tipi), koli_ici_adet=VALUES(koli_ici_adet)");
$excelReceteIns = $db->prepare("INSERT INTO maliyet_receteler (donem,urun_id,kalem_id,miktar,fire_orani,satir_tutari,aciklama) VALUES ('2026-04',?,?,?,?,?,?)");
$excelQtyIns = $db->prepare("INSERT INTO recete_stok_hareketleri (donem,tarih,belge_no,urun_id,hareket_tipi,miktar,cikis_depo,varis_depo,aciklama) VALUES ('2026-04','2026-04-30',?,?, 'uretilen',?,'Dolum Tesisi','Genel Stok','Excel Nisan maliyet şablonundan aktarılmış üretim')");
$g730 = 7.858209; $g760 = 2.636136; $g770 = 12.485855; $g720 = 9.163123;
$db->exec("UPDATE recete_donemler SET toplam_uretim=600000, durum='Açık', son_hesaplama=NOW() WHERE donem='2026-04'");
$db->exec("DELETE FROM recete_stok_hareketleri WHERE donem='2026-04' AND hareket_tipi='uretilen'");
foreach($excelNisanProducts as $p){
    $deletedProductQ->execute([$p[0]]);
    if($deletedProductQ->fetchColumn()){ continue; }
    $excelUrunIns->execute([$p[0],$p[1],$p[2],$p[3],$p[4]]);
    $uidQ = $db->prepare("SELECT id FROM maliyet_urunler WHERE urun_kodu=? LIMIT 1");
    $uidQ->execute([$p[0]]);
    $uid = (int)$uidQ->fetchColumn();
    if($uid <= 0){ continue; }
    $db->prepare("DELETE FROM maliyet_receteler WHERE donem='2026-04' AND urun_id=?")->execute([$uid]);
    $hamId = rm_kalem_id($db, 'Hammadde', 'Hammadde Toplamı', 'Koli', 0);
    $excelReceteIns->execute([$uid,$hamId,1,3,$p[6],$p[8].': Excel satır 20, fireli hammadde maliyeti. Hammadde koli toplamı + %3 fire.']);
    foreach([
        ['730 İŞÇİLİK HARİÇ GİDER', $g730, 'Koli başına işçilik hariç gider payı'],
        ['760 PAZARLAMA GİDERİ', $g760, 'Koli başına pazarlama gideri payı'],
        ['GENEL YÖNETİM GİDERİ', $g770, 'Koli başına genel yönetim gideri payı'],
        ['İŞÇİLİK GİDERİ', $g720, 'Koli başına işçilik gideri payı'],
    ] as $g){
        $gid = rm_kalem_id($db, 'Genel Gider', $g[0], 'Koli', 0);
        $excelReceteIns->execute([$uid,$gid,1,0,$g[1],$p[8].': '.$g[2].' formülüyle paylaştırıldı.']);
    }
    if((float)$p[5] > 0){ $excelQtyIns->execute(['EXCEL-NISAN-URETIM-'.$p[0],$uid,$p[5]]); rm_stok_sync($db,'2026-04',$uid); }
}

$pet033ExcelId = (int)$db->query("SELECT id FROM maliyet_urunler WHERE urun_kodu='SU-PET-033' LIMIT 1")->fetchColumn();
if($pet033ExcelId > 0){
    $bomIdStmt = $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,'2026-04','2026-04','Excel Nisan 2026 - 0,33 lt Standart reçetesi',1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1");
    $bomIdStmt->execute([$pet033ExcelId]);
    $bomGet = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem='2026-04' AND versiyon='2026-04' LIMIT 1");
    $bomGet->execute([$pet033ExcelId]);
    $bomId = (int)$bomGet->fetchColumn();
    if($bomId > 0){
        $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$bomId]);
        $bomIns = $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach([
            ['Preform / Cam Şişe',1950,'USD',44.3434,1000000,9.2,'gr/adet',24,3],
            ['Kapak',4.30,'USD',44.3434,1000,1,'adet/adet',24,3],
            ['Etiket',7,'EUR',51.4136,4500,1,'adet/adet',24,3],
            ['Shrink Film',2800,'USD',44.3434,1000000,26,'gr/koli',1,3],
            ['Strech Film',0.0008718454,'TL',1,1,1,'adet/adet',1,3],
            ['Palet Ara Seperatör',22,'TL',1,116,5,'adet/koli',1,3],
        ] as $r){
            $kid = rm_kalem_id($db, 'Hammadde', $r[0], str_contains($r[6], 'gr') || str_contains($r[6], 'kg') ? 'Kg' : 'Adet', 0);
            $bomIns->execute([$bomId,$kid,$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],($r[1]*$r[3]) / max((float)$r[4],0.000001),$r[7],$r[8]]);
        }
    }
}

$summaryBomIns = $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,'2026-04','2026-04',?,1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1");
$summaryBomGet = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem='2026-04' AND versiyon='2026-04' LIMIT 1");
$summaryBomKalemIns = $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
foreach($excelNisanProducts as $p){
    if($p[0] === 'SU-PET-033'){ continue; }
    $deletedProductQ->execute([$p[0]]);
    if($deletedProductQ->fetchColumn()){ continue; }
    $uidQ = $db->prepare("SELECT id FROM maliyet_urunler WHERE urun_kodu=? LIMIT 1");
    $uidQ->execute([$p[0]]);
    $uid = (int)$uidQ->fetchColumn();
    if($uid <= 0){ continue; }
    $summaryBomIns->execute([$uid, $p[8].' Excel Nisan 2026 toplam reçete fiyatı']);
    $summaryBomGet->execute([$uid]);
    $bomId = (int)$summaryBomGet->fetchColumn();
    if($bomId <= 0){ continue; }
    $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$bomId]);
    $kid = rm_kalem_id($db, 'Hammadde', 'Hammadde Toplamı', 'Koli', 0);
    $netHammadde = ((float)$p[6]) / 1.03;
    $summaryBomKalemIns->execute([$bomId,$kid,$netHammadde,'TL',1,1,1,'adet/koli',$netHammadde,1,3]);
}

$tab = (string)($_GET['tab'] ?? 'ozet');
if(!in_array($tab, ['ozet','urunler','hammaddeler','fiyatlar','receteler','uretim','nakliye','stok_hareketleri'], true)){ $tab = 'ozet'; }
$requestedDonem = trim((string)($_GET['donem'] ?? ''));
if($requestedDonem !== ''){
    $donem = $requestedDonem;
} else {
    $latestDonem = '';
    try {
        $latestDonem = (string)$db->query("
            SELECT donem FROM (
                SELECT donem, MAX(COALESCE(son_hesaplama, '1000-01-01')) tarih FROM recete_donemler WHERE toplam_uretim > 0 OR son_hesaplama IS NOT NULL GROUP BY donem
                UNION ALL SELECT donem, MAX(COALESCE(created_at, '1000-01-01')) tarih FROM recete_bom GROUP BY donem
                UNION ALL SELECT donem, MAX('1000-01-01') tarih FROM maliyet_receteler GROUP BY donem
                UNION ALL SELECT donem, MAX('1000-01-01') tarih FROM recete_uretim WHERE koli_miktari > 0 GROUP BY donem
            ) x
            GROUP BY donem
            ORDER BY MAX(tarih) DESC, donem DESC
            LIMIT 1
        ")->fetchColumn();
    } catch(Throwable $e) {}
    $donem = $latestDonem !== '' ? $latestDonem : '2026-04';
}
$selectedId = (int)($_POST['urun_id'] ?? ($_GET['urun_id'] ?? 0));
$message = '';
$error = '';
$db->prepare("INSERT IGNORE INTO recete_gider_ayarlari (donem,g730,g760,g770,g720) VALUES (?,7.858209,2.636136,12.485855,9.163123)")
    ->execute([$donem]);
if($donem === '2026-06'){
    $db->prepare("INSERT INTO recete_donemler (donem,donem_adi,toplam_uretim,durum,son_hesaplama) VALUES ('2026-06','Haziran 2026',509895,'Açık',NOW()) ON DUPLICATE KEY UPDATE toplam_uretim=509895, durum='Açık'")->execute();
    $haziranUretimIns = $db->prepare("INSERT INTO recete_uretim (donem,urun_adi,koli_miktari) VALUES ('2026-06',?,?) ON DUPLICATE KEY UPDATE koli_miktari=VALUES(koli_miktari)");
    foreach([
        ['0,33 L Pet Şişe Su',0],
        ['0,50 L Pet Şişe Su',223920],
        ['1 L Pet Şişe Su',75921],
        ['1,5 L Pet Şişe Su',87676],
        ['5 L Pet Şişe Su',0],
        ['0,33 L Cam Şişe Su',0],
        ['0,75 L Cam Şişe Su',0],
        ['19 L Damacana Su',122378],
        ['200 ml Bardak Su',0],
    ] as $hu){ $haziranUretimIns->execute($hu); }
}
$db->prepare("UPDATE recete_gider_ayarlari
    SET g730=7.858209, g760=2.636136, g770=12.485855, g720=9.163123
    WHERE donem=?
      AND ABS(g730-7.8582) < 0.0002
      AND ABS(g760-2.6361) < 0.0002
      AND ABS(g770-12.4859) < 0.0002
      AND ABS(g720-9.1631) < 0.0002")
    ->execute([$donem]);
rm_seed_cam033_fixed_cost($db, $donem);

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = (string)($_POST['action'] ?? '');
        if($action === 'donem_ekle'){
            $yil = (int)($_POST['donem_yil'] ?? date('Y'));
            $ay = (int)($_POST['donem_ay'] ?? 0);
            if($yil < 2020 || $yil > 2100 || $ay < 1 || $ay > 12){ throw new RuntimeException('Geçerli dönem yılı ve ayı seçin.'); }
            $ayAdlari = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
            $newDonem = sprintf('%04d-%02d', $yil, $ay);
            $newDonemAdi = $ayAdlari[$ay] . ' ' . $yil;
            $toplamUretim = rm_num($_POST['toplam_uretim'] ?? 0);
            $db->prepare("INSERT INTO recete_donemler (donem,donem_adi,toplam_uretim,durum,son_hesaplama) VALUES (?,?,?,'Açık',NULL) ON DUPLICATE KEY UPDATE donem_adi=VALUES(donem_adi), toplam_uretim=VALUES(toplam_uretim), durum='Açık'")
                ->execute([$newDonem, $newDonemAdi, $toplamUretim]);
            $donem = $newDonem;
            $tab = (string)($_POST['return_tab'] ?? $tab);
            $message = $newDonemAdi . ' dönemi eklendi.';
        } elseif($action === 'urun_kaydet'){
            $ad = trim((string)($_POST['urun_adi'] ?? ''));
            if($ad === ''){ throw new RuntimeException('Ürün adı boş olamaz.'); }
            $kod = trim((string)($_POST['urun_kodu'] ?? ''));
            $db->prepare("INSERT INTO maliyet_urunler (urun_kodu,urun_adi,urun_grubu,ambalaj_tipi,koli_ici_adet) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE urun_adi=VALUES(urun_adi), urun_grubu=VALUES(urun_grubu), ambalaj_tipi=VALUES(ambalaj_tipi), koli_ici_adet=VALUES(koli_ici_adet)")
                ->execute([$kod !== '' ? $kod : null, $ad, trim((string)($_POST['urun_grubu'] ?? 'PET Şişeler')), trim((string)($_POST['ambalaj_tipi'] ?? 'PET')), rm_num($_POST['koli_ici_adet'] ?? 1)]);
            if($kod !== ''){ $db->prepare("DELETE FROM maliyet_silinen_urunler WHERE urun_kodu=?")->execute([$kod]); }
            $tab = 'urunler';
            $message = 'Ürün kaydedildi.';
        } elseif($action === 'fiyat_kaydet'){
            $ad = trim((string)($_POST['urun_adi'] ?? ''));
            if($ad === ''){ throw new RuntimeException('Ürün fiyat adı boş olamaz.'); }
            $db->prepare("INSERT INTO maliyet_fiyatlama (fiyat_tarihi,kategori,urun_adi,ton_fiyati,doviz_adet,tl_adet,cam_sise_033,cam_sise_075,aciklama) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kategori=VALUES(kategori), ton_fiyati=VALUES(ton_fiyati), doviz_adet=VALUES(doviz_adet), tl_adet=VALUES(tl_adet), cam_sise_033=VALUES(cam_sise_033), cam_sise_075=VALUES(cam_sise_075), aciklama=VALUES(aciklama)")
                ->execute([trim((string)($_POST['fiyat_tarihi'] ?? date('Y-m-d'))), trim((string)($_POST['kategori'] ?? 'Ürün')), $ad, trim((string)($_POST['ton_fiyati'] ?? '')), trim((string)($_POST['doviz_adet'] ?? '')), rm_num($_POST['tl_adet'] ?? 0), rm_num($_POST['cam_sise_033'] ?? 0), rm_num($_POST['cam_sise_075'] ?? 0), trim((string)($_POST['aciklama'] ?? ''))]);
            $tab = 'urunler';
            $message = 'Fiyat kaydedildi.';
        } elseif($action === 'recete_gider_kaydet'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $giderUretim = rm_num($_POST['gider_toplam_uretim'] ?? 0);
            $g730Total = rm_num($_POST['g730_total'] ?? 0);
            $g760Total = rm_num($_POST['g760_total'] ?? 0);
            $g770Total = rm_num($_POST['g770_total'] ?? 0);
            $g720Total = rm_num($_POST['g720_total'] ?? 0);
            if($giderUretim > 0 && ($g730Total + $g760Total + $g770Total + $g720Total) > 0){
                $g730Post = $g730Total / $giderUretim;
                $g760Post = $g760Total / $giderUretim;
                $g770Post = $g770Total / $giderUretim;
                $g720Post = $g720Total / $giderUretim;
                $db->prepare("UPDATE recete_donemler SET toplam_uretim=? WHERE donem=?")->execute([$giderUretim,$donem]);
            } else {
                $g730Post = rm_num($_POST['g730'] ?? 0);
                $g760Post = rm_num($_POST['g760'] ?? 0);
                $g770Post = rm_num($_POST['g770'] ?? 0);
                $g720Post = rm_num($_POST['g720'] ?? 0);
            }
            $db->prepare("INSERT INTO recete_gider_ayarlari (donem,g730,g760,g770,g720) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE g730=VALUES(g730), g760=VALUES(g760), g770=VALUES(g770), g720=VALUES(g720)")
                ->execute([$donem, $g730Post, $g760Post, $g770Post, $g720Post]);
            if($urunId > 0){
                $uq = $db->prepare("SELECT urun_adi FROM maliyet_urunler WHERE id=?");
                $uq->execute([$urunId]);
                $urunAdi = (string)$uq->fetchColumn();
                if($urunAdi !== ''){
                    $db->prepare("INSERT INTO recete_nakliye_dekap (donem,urun_adi,nakliye_tl_koli,dekap_tl_koli) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nakliye_tl_koli=VALUES(nakliye_tl_koli), dekap_tl_koli=VALUES(dekap_tl_koli)")
                        ->execute([$donem,$urunAdi,rm_num($_POST['nakliye'] ?? 0),rm_num($_POST['dekap'] ?? 0)]);
                }
            }
            $selectedId = $urunId;
            $tab = 'receteler';
            $message = 'Reçete giderleri kaydedildi.';
        } elseif($action === 'stok_hareket_kaydet'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $tip = trim((string)($_POST['hareket_tipi'] ?? ''));
            if($urunId <= 0 || $tip === ''){ throw new RuntimeException('Stok hareketi için ürün ve hareket tipi seçin.'); }
            $db->prepare("INSERT INTO recete_stok_hareketleri (donem,tarih,belge_no,urun_id,hareket_tipi,miktar,cikis_depo,varis_depo,aciklama) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$donem, trim((string)($_POST['tarih'] ?? date('Y-m-d'))), trim((string)($_POST['belge_no'] ?? '')), $urunId, $tip, rm_num($_POST['miktar'] ?? 0), trim((string)($_POST['cikis_depo'] ?? '')), trim((string)($_POST['varis_depo'] ?? '')), trim((string)($_POST['aciklama'] ?? ''))]);
            rm_stok_sync($db,$donem,$urunId);
            $tab = 'stok_hareketleri';
            $message = 'Stok hareketi kaydedildi ve stok sayımına aktarıldı.';
        } elseif($action === 'stok_hareket_sil'){
            $id = (int)($_POST['hareket_id'] ?? 0);
            $q = $db->prepare("SELECT donem,urun_id FROM recete_stok_hareketleri WHERE id=?");
            $q->execute([$id]);
            $old = $q->fetch();
            if($old){
                $db->prepare("DELETE FROM recete_stok_hareketleri WHERE id=?")->execute([$id]);
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
            $q = $db->prepare("SELECT urun_adi, urun_kodu FROM maliyet_urunler WHERE id=?");
            $q->execute([$urunId]);
            $urunRow = $q->fetch();
            $urunAdi = (string)($urunRow['urun_adi'] ?? '');
            $urunKodu = (string)($urunRow['urun_kodu'] ?? '');
            if($urunAdi === ''){ throw new RuntimeException('Ürün bulunamadı.'); }
            $checks = [
                [$db->prepare("SELECT COUNT(*) FROM maliyet_receteler WHERE urun_id=?"), [$urunId]],
                [$db->prepare("SELECT COUNT(*) FROM recete_bom WHERE urun_id=?"), [$urunId]],
                [$db->prepare("SELECT COUNT(*) FROM recete_uretim WHERE urun_adi=?"), [$urunAdi]],
                [$db->prepare("SELECT COUNT(*) FROM recete_nakliye_dekap WHERE urun_adi=?"), [$urunAdi]],
            ];
            $related = -1000000;
            foreach($checks as $c){ $c[0]->execute($c[1]); $related += (int)$c[0]->fetchColumn(); }
            if($related > 0){ throw new RuntimeException('Bu ürün reçete, üretim veya maliyet kayıtlarıyla ilişkili olduğu için silinemedi.'); }
            if($urunKodu !== ''){
                $db->prepare("INSERT IGNORE INTO maliyet_silinen_urunler (urun_kodu) VALUES (?)")->execute([$urunKodu]);
            }
            $db->prepare("DELETE kb FROM recete_bom_kalemleri kb JOIN recete_bom b ON b.id=kb.recete_id WHERE b.urun_id=?")->execute([$urunId]);
            $db->prepare("DELETE FROM recete_bom WHERE urun_id=?")->execute([$urunId]);
            $db->prepare("DELETE FROM maliyet_receteler WHERE urun_id=?")->execute([$urunId]);
            $db->prepare("DELETE FROM recete_stok_hareketleri WHERE urun_id=?")->execute([$urunId]);
            $db->prepare("DELETE FROM maliyet_stok_sayimlari WHERE urun_id=?")->execute([$urunId]);
            $db->prepare("DELETE FROM recete_uretim WHERE urun_adi=?")->execute([$urunAdi]);
            $db->prepare("DELETE FROM recete_nakliye_dekap WHERE urun_adi=?")->execute([$urunAdi]);
            $db->prepare("DELETE FROM maliyet_urunler WHERE id=?")->execute([$urunId]);
            $tab = 'urunler';
            $message = 'Ürün silindi.';
        } elseif($action === 'bom_excel_yukle'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            if($urunId <= 0){ throw new RuntimeException('Ürün seçimi eksik.'); }
            $rows = rm_read_recete_upload($_FILES['recete_excel'] ?? []);
            if(!$rows){ throw new RuntimeException('Excel içinde aktarılacak reçete satırı bulunamadı.'); }
            $aciklama = trim((string)($_POST['aciklama'] ?? 'Excel reçete aktarımı'));
            $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1")
                ->execute([$urunId,$donem,$donem,$aciklama]);
            $q = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem=? AND versiyon=? LIMIT 1");
            $q->execute([$urunId,$donem,$donem]);
            $receteId = (int)$q->fetchColumn();
            if($receteId <= 0){ throw new RuntimeException('Reçete kaydı oluşturulamadı.'); }
            $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$receteId]);
            $ins = $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $count = 0;
            foreach($rows as $r){
                $ad = trim((string)($r[0] ?? ''));
                if($ad === ''){ continue; }
                $alis = rm_num($r[1] ?? 0);
                $para = strtoupper(trim((string)($r[2] ?? 'TL')));
                if(!in_array($para, ['TL','USD','EUR'], true)){ $para = 'TL'; }
                $kur = max(rm_num($r[3] ?? 1), 0.000001);
                $bolen = max(rm_num($r[4] ?? 1), 0.000001);
                $miktar = rm_num($r[5] ?? 0);
                $birim = trim((string)($r[6] ?? 'adet/koli'));
                $koli = rm_num($r[7] ?? 1);
                $fire = rm_num($r[8] ?? 0);
                $kid = rm_kalem_id($db, 'Hammadde', $ad, str_contains($birim, 'gr') || str_contains($birim, 'kg') ? 'Kg' : 'Adet', 0);
                $ins->execute([$receteId,$kid,$alis,$para,$kur,$bolen,$miktar,$birim,($alis*$kur)/$bolen,$koli,$fire]);
                $count++;
            }
            $selectedId = $urunId;
            $tab = 'receteler';
            $message = $count . ' reçete satırı Excelden aktarıldı.';
        } elseif($action === 'bom_kaydet' || $action === 'bom_kopyala'){
            $urunId = (int)($_POST['urun_id'] ?? 0);
            $versiyon = trim((string)($_POST['versiyon'] ?? $donem));
            if($versiyon === ''){ $versiyon = $donem; }
            $aciklama = trim((string)($_POST['aciklama'] ?? ''));
            $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE aciklama=VALUES(aciklama), aktif=1")
                ->execute([$urunId,$donem,$versiyon,$aciklama]);
            $receteId = (int)$db->lastInsertId();
            if(!$receteId){
                $q = $db->prepare("SELECT id FROM recete_bom WHERE urun_id=? AND donem=? AND versiyon=?");
                $q->execute([$urunId,$donem,$versiyon]);
                $receteId = (int)$q->fetchColumn();
            }
            $db->prepare("DELETE FROM recete_bom_kalemleri WHERE recete_id=?")->execute([$receteId]);
            $hammaddeIds = $_POST['hammadde_id'] ?? [];
            foreach($hammaddeIds as $i => $hid){
                $hid = (int)$hid;
                if($hid <= 0){ continue; }
                $alis = rm_num($_POST['alis_fiyati'][$i] ?? 0);
                $kur = rm_num($_POST['doviz_kuru'][$i] ?? 1);
                $bolen = max(rm_num($_POST['bolen'][$i] ?? 1), 0.000001);
                $birimFiyat = ($alis * $kur) / $bolen;
                $db->prepare("INSERT INTO recete_bom_kalemleri (recete_id,hammadde_id,alis_fiyati,para_cinsi,doviz_kuru,bolen,tuketim_miktari,tuketim_birimi,birim_fiyat,koli_ici_adet,fire_orani) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$receteId,$hid,$alis,trim((string)($_POST['para_cinsi'][$i] ?? 'TL')),$kur,$bolen,rm_num($_POST['tuketim_miktari'][$i] ?? 0),trim((string)($_POST['tuketim_birimi'][$i] ?? 'adet/koli')),$birimFiyat,rm_num($_POST['koli_ici_adet'][$i] ?? 1),rm_num($_POST['fire_orani'][$i] ?? 0)]);
            }
            rm_sync_bom_to_maliyet($db, $receteId, $urunId, $donem);
            $selectedId = $urunId;
            $tab = 'receteler';
            $message = 'Reçete kaydedildi.';
        }
    }
} catch(Throwable $e){ $error = $e->getMessage(); }

$donemler = $db->query("SELECT * FROM recete_donemler ORDER BY donem DESC")->fetchAll();
$currentDonem = $db->prepare("SELECT * FROM recete_donemler WHERE donem=?");
$currentDonem->execute([$donem]);
$currentDonem = $currentDonem->fetch() ?: ['donem_adi'=>'Nisan 2026','toplam_uretim'=>600000,'durum'=>'Açık','son_hesaplama'=>date('Y-m-d H:i:s')];

$activeBomSync = $db->prepare("SELECT b.id,b.urun_id FROM recete_bom b JOIN recete_bom_kalemleri k ON k.recete_id=b.id WHERE b.donem=? AND b.aktif=1 GROUP BY b.id,b.urun_id");
$activeBomSync->execute([$donem]);
foreach($activeBomSync->fetchAll() as $ab){ rm_sync_bom_to_maliyet($db, (int)$ab['id'], (int)$ab['urun_id'], $donem); }

$costSql = "
    SELECT
        u.id, u.urun_adi,
        SUM(CASE WHEN k.kategori='Hammadde' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) hammadde,
        SUM(CASE WHEN k.kalem_adi LIKE '720%' OR k.kalem_adi LIKE 'İŞÇİLİK GİDERİ%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g720,
        SUM(CASE WHEN k.kalem_adi LIKE '730%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g730,
        SUM(CASE WHEN k.kalem_adi LIKE '760%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g760,
        SUM(CASE WHEN k.kalem_adi LIKE 'GENEL YÖNETİM%' OR k.kalem_adi LIKE 'GENEL Y?NET?M%' OR k.kalem_adi LIKE '770%' THEN COALESCE(NULLIF(r.satir_tutari,0), r.miktar*k.birim_fiyat*(1+r.fire_orani/100)) ELSE 0 END) g770,
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
            $db->prepare("INSERT INTO recete_bom (urun_id,donem,versiyon,aciklama,aktif) VALUES (?,?,?,?,1)")->execute([$selectedId,$donem,$donem,$currentDonem['donem_adi'].' Standart Reçetesi']);
        $activeBom = ['id'=>(int)$db->lastInsertId(),'urun_id'=>$selectedId,'donem'=>$donem,'versiyon'=>$donem,'aciklama'=>$currentDonem['donem_adi'].' Standart Reçetesi'];
        $defaultNames = ['Preform / Cam Şişe'=>[9.2,3],'Kapak'=>[1,1.5],'Etiket'=>[1,2],'Shrink Film'=>[26,3.5],'Streç Film'=>[8.8,2]];
        foreach($hammaddeler as $h){
            foreach($defaultNames as $name=>$def){
                if(stripos($h['kalem_adi'],$name) !== false || $h['kalem_adi'] === $name){
                    $unit = in_array($name, ['Kapak','Etiket'], true) ? 'adet/adet' : ($name === 'Preform / Cam Şişe' ? 'gr/adet' : 'gr/koli');
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
$uretimStmt = $db->prepare("SELECT urun_id, SUM(miktar) koli_miktari FROM recete_stok_hareketleri WHERE donem=? AND hareket_tipi='uretilen' GROUP BY urun_id");
$uretimStmt->execute([$donem]);
$uretimById = [];
foreach($uretimStmt->fetchAll() as $u){ $uretimById[(int)$u['urun_id']] = (float)$u['koli_miktari']; }
$uretimler = [];
foreach($urunler as $u){
    $uretimler[] = [
        'donem' => $donem,
        'urun_adi' => $u['urun_adi'],
        'urun_kodu' => rm_urun_kodu_satir($u),
        'koli_miktari' => $uretimById[(int)$u['id']] ?? 0,
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
$stokOzetStmt = $db->prepare("
    SELECT
        u.id, u.urun_kodu, u.urun_adi, u.ambalaj_tipi, u.koli_ici_adet,
        COALESCE(SUM(CASE WHEN h.hareket_tipi='uretilen' THEN h.miktar ELSE 0 END),0) uretilen,
        COALESCE(SUM(CASE WHEN h.hareket_tipi='iade_giris' THEN h.miktar ELSE 0 END),0) iade,
        COALESCE(SUM(CASE WHEN h.hareket_tipi IN ('birimler_arasi_sevk','bayi_sevk','zincir_market_sevk') THEN h.miktar ELSE 0 END),0) sevk,
        COALESCE(SUM(CASE WHEN h.hareket_tipi='fire' THEN h.miktar ELSE 0 END),0) fire
    FROM maliyet_urunler u
    LEFT JOIN recete_stok_hareketleri h ON h.urun_id=u.id AND h.donem=?
    GROUP BY u.id,u.urun_kodu,u.urun_adi,u.ambalaj_tipi,u.koli_ici_adet
    ORDER BY u.urun_adi
");
$stokOzetStmt->execute([$donem]);
$stokUrunOzet = $stokOzetStmt->fetchAll();

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
$totalProduction = $usedQty;
$avgCost = $usedQty > 0 ? $weightedTotal / $usedQty : 0;
$uretimToplam = array_sum(array_map(fn($u) => (float)$u['koli_miktari'], $uretimler));
$uretimAktif = count(array_filter($uretimler, fn($u) => (float)$u['koli_miktari'] > 0));
$uretimEnYuksek = $uretimler ? max(array_map(fn($u) => (float)$u['koli_miktari'], $uretimler)) : 0;
$warnings = [];
if(!$fiyatlar){ $warnings[] = 'Hammadde fiyatları tanımlanmamış.'; }
if(!$urunMaliyetleri){ $warnings[] = 'Aktif reçete maliyeti bulunamadı.'; }
$selectedNd = $selected ? ($ndByProduct[$selected['urun_adi']] ?? ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0]) : ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçete ve Maliyet</title>
    <link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">
    <style>
        :root{--navy:#111827;--slate:#64748b;--line:#e5e7eb;--blue:#2563eb;--soft:#f8fafc;--green:#16a34a;--red:#dc2626}
        body{background:#f3f6fa}.rm-page{padding:28px;max-width:1680px}.rm-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;border-radius:18px;padding:24px;margin-bottom:18px;box-shadow:0 20px 45px rgba(15,23,42,.16)}.rm-hero h1{margin:0;font-size:30px;letter-spacing:0}.rm-hero p{margin:6px 0 0;color:#dbeafe}.rm-filter{display:flex;gap:10px;align-items:center}.rm-filter select{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:10px;padding:10px 12px;font-weight:800}.rm-filter option{color:#111827}.rm-tabs{display:grid;grid-template-columns:repeat(8,minmax(105px,1fr));gap:10px;margin-bottom:16px}.rm-tabs a{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px;color:#334155;text-decoration:none;font-size:12px;font-weight:900;text-align:center}.rm-tabs a.active{border-color:#2563eb;background:#eff6ff;color:#1d4ed8}.rm-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.rm-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.rm-card span{display:block;color:var(--slate);font-size:12px;font-weight:800}.rm-card strong{display:block;margin-top:8px;font-size:25px;color:#0f172a}.rm-change{display:inline-block;margin-top:8px;border-radius:999px;padding:4px 9px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:900}.rm-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(360px,.85fr);gap:16px}.rm-panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.rm-panel h3{margin:0 0 10px;font-size:18px}.rm-table-wrap{overflow:auto}.rm-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;min-width:820px}.rm-table th{background:#0f172a;color:#fff;text-align:left;padding:11px 10px;white-space:nowrap}.rm-table th:first-child{border-top-left-radius:10px}.rm-table th:last-child{border-top-right-radius:10px}.rm-table td{padding:11px 10px;border-bottom:1px solid #eef2f7;vertical-align:middle}.rm-table tr:hover td{background:#f8fafc}.rm-table .num{text-align:right;font-variant-numeric:tabular-nums}.rm-product{font-weight:900;color:#0f172a}.rm-material{font-size:11px;font-weight:700;color:#334155;line-height:1.25}.rm-detail-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:12px}.rm-pill{background:#eef2ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:900}.rm-break{display:grid;gap:8px}.rm-break-row{display:flex;justify-content:space-between;gap:10px;background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:9px 10px;font-size:12px}.rm-break-row b{color:#0f172a}.rm-break-row small{display:block;margin-top:3px;color:#64748b;font-size:10px;font-weight:700}.side-subtitle{margin:14px 0 8px;color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.rm-total{margin-top:14px;background:#0f172a;color:#fff;border-radius:14px;padding:16px}.rm-total span{color:#cbd5e1;font-size:12px}.rm-total strong{display:block;font-size:28px;margin-top:4px}.rm-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:14px;padding:12px 14px;margin-bottom:16px;font-size:13px;font-weight:800}.rm-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.rm-field label{display:block;font-size:12px;font-weight:900;color:#334155;margin-bottom:6px}.rm-field input,.rm-field select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:13px}.rm-btn{border:0;border-radius:10px;background:#2563eb;color:#fff;padding:10px 13px;font-weight:900;cursor:pointer}.bom-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:18px}.bom-products{max-height:560px;overflow:auto}.bom-item{display:block;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:9px;color:#0f172a;text-decoration:none;background:#fff}.bom-item.active{border-color:#60a5fa;background:#eff6ff}.bom-code{display:block;color:#64748b;font-size:10px;font-weight:900;margin-top:4px}.bom-ok{float:right;background:#dcfce7;color:#047857;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:900}.bom-head{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:18px}.bom-title{font-size:21px;font-weight:950;color:#0f172a}.bom-editor{border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;background:#fff}.bom-actions{display:flex;gap:10px}.bom-table{width:100%;border-collapse:collapse;font-size:12px}.bom-table th{background:#eef2f7;color:#0f172a;text-align:left;padding:10px}.bom-table td{border-bottom:1px solid #edf2f7;padding:9px}.bom-table input,.bom-table select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:9px;font-size:12px}.bom-calc{font-weight:900;color:#0f172a;white-space:nowrap}.muted{color:#64748b}.green{color:var(--green)}.red{color:var(--red)}@media(max-width:1200px){.rm-grid,.rm-kpis{grid-template-columns:1fr 1fr}.rm-tabs{grid-template-columns:repeat(4,1fr)}.bom-layout{grid-template-columns:1fr}}@media(max-width:800px){.rm-page{padding:14px}.rm-grid,.rm-kpis,.rm-form{grid-template-columns:1fr}.rm-tabs{grid-template-columns:1fr 1fr}.rm-hero{display:block}.rm-filter{margin-top:14px}}
        .recipe-mode{max-width:1680px;width:100%}.recipe-mode .rm-tabs{display:none}.recipe-mode .rm-hero{min-height:118px;align-items:center;background:linear-gradient(110deg,#30308d 0%,#15213e 100%);border-radius:18px;padding:26px 32px}.hero-kicker{display:block;color:#60a5fa;font-size:12px;font-weight:950;letter-spacing:.04em;margin-bottom:10px}.hero-actions{display:flex;gap:12px}.hero-actions button{min-width:170px;border:1px solid rgba(255,255,255,.22);border-radius:14px;padding:14px 18px;background:#1d5cff;color:#fff;font-weight:950;cursor:pointer}.hero-actions .copy{background:rgba(255,255,255,.12)}.recipe-mode>.rm-panel{background:transparent;border:0;box-shadow:none;padding:10px 0 0}.recipe-mode .bom-layout{grid-template-columns:245px minmax(0,1fr);gap:30px}.recipe-mode aside{background:#fff;border:1px solid #dfe6ee;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.06)}.recipe-mode aside h3{font-size:13px;color:#64748b;letter-spacing:.04em;margin-bottom:14px}.recipe-mode .bom-products{max-height:640px;padding-right:8px}.recipe-mode .bom-item{border-radius:14px;padding:14px 14px;background:#fff}.recipe-mode .bom-item.active{background:#eff6ff;border-color:#80b9ff;box-shadow:0 0 0 1px #bfdbfe inset}.recipe-mode .bom-editor{border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.08)}.recipe-mode .bom-head{background:#fff;padding:24px 26px}.recipe-mode .recipe-bom-head{align-items:flex-start}.recipe-badges{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}.recipe-note{margin:6px 0 0;font-size:12px}.recipe-actions{align-items:end;flex-wrap:wrap}.recipe-actions label{min-width:300px;color:#334155;font-size:12px;font-weight:900}.recipe-actions input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:11px;font-size:13px}.recipe-actions .primary-add{height:42px}.recipe-mode .bom-table{min-width:720px}.recipe-mode .bom-table th{background:#eef2f7;padding:13px 14px;font-size:12px}.recipe-mode .bom-table td{padding:11px 10px}.recipe-mode .bom-table input,.recipe-mode .bom-table select{height:42px;border-radius:10px}.recipe-mode .bom-num,.recipe-mode .bom-fire{font-weight:900;text-align:center;color:#00359e}.recipe-mode .bom-fire{color:#c2410c}.recipe-mode .rm-table-wrap{padding:22px 22px 0}.bom-ok.missing{background:#fef3c7;color:#b45309}.trash-btn{border:0;background:transparent;color:#ef4444;font-size:18px;font-weight:900;cursor:pointer}.bom-footer{display:flex;justify-content:space-between;align-items:center;padding:16px 22px}.add-material{border:0;border-radius:10px;background:#10b981;color:#fff;padding:10px 16px;font-weight:950;cursor:pointer}.bom-count{font-size:12px;color:#64748b}.expense-mode .rm-hero{background:linear-gradient(120deg,#101827 0%,#241810 100%);align-items:center}.expense-save{border:0;border-radius:12px;background:#f97316;color:#fff;padding:13px 18px;font-weight:950;box-shadow:0 10px 22px rgba(249,115,22,.28);cursor:pointer}.expense-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(330px,1fr);gap:18px}.expense-panel{background:#fff;border:1px solid #e3e8ef;border-radius:18px;padding:22px;box-shadow:0 14px 34px rgba(15,23,42,.06)}.expense-cards{display:grid;grid-template-columns:1fr 1fr;gap:14px}.expense-card{border:1px solid #e5eaf1;border-radius:16px;background:#fff;padding:16px}.expense-card label{display:block;color:#172033;font-weight:950;margin-bottom:10px}.expense-card input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px 13px;font-size:22px;font-weight:950;color:#0f172a}.expense-card p{margin:10px 0 0;color:#64748b;font-size:12px;line-height:1.45}.method-title{margin:22px 0 10px;font-size:16px;font-weight:950;color:#111827}.method-buttons{display:flex;flex-wrap:wrap;gap:10px}.method-btn{border:1px solid #e2e8f0;border-radius:999px;background:#fff;color:#334155;padding:10px 14px;font-weight:900;cursor:pointer}.method-btn.active{background:#f97316;border-color:#f97316;color:#fff}.analysis-card{background:#0f172a;color:#fff;border-radius:18px;padding:24px;box-shadow:0 18px 38px rgba(15,23,42,.24)}.analysis-card h3{margin:0 0 8px;font-size:13px;letter-spacing:.06em;color:#cbd5e1}.kpi{border-top:1px solid rgba(255,255,255,.12);padding:18px 0}.kpi:first-of-type{border-top:0}.kpi span{display:block;color:#94a3b8;font-size:12px;font-weight:800}.kpi strong{display:block;margin-top:6px;font-size:30px}.kpi .amber{color:#fbbf24}.kpi .cyan{color:#2dd4bf}.analysis-note{margin-top:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:13px;color:#dbeafe;font-size:12px;line-height:1.5}.product-mode .rm-hero{display:none}.product-head,.product-add,.product-kpis,.product-group{margin-bottom:16px}.product-head{display:flex;justify-content:space-between;gap:16px;align-items:center;background:#fff;border:1px solid #e4e9f1;border-radius:18px;padding:22px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.product-head h1{margin:0;color:#0f172a;font-size:28px}.product-head p{margin:5px 0 0;color:#64748b}.primary-add{border:0;border-radius:12px;background:#2563eb;color:#fff;padding:12px 16px;font-weight:950;cursor:pointer}.product-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.product-kpi{background:#fff;border:1px solid #e4e9f1;border-radius:16px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.04)}.product-kpi span{display:block;font-size:12px;color:#64748b;font-weight:900}.product-kpi strong{display:block;margin-top:6px;font-size:26px;color:#0f172a}.product-add{display:none;background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.product-add.show{display:block}.product-tools{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;background:#fff;border:1px solid #e4e9f1;border-radius:16px;padding:14px;margin-bottom:16px}.product-tools input,.product-tools select,.product-add input,.product-add select,.edit-row input{border:1px solid #cbd5e1;border-radius:10px;padding:10px 11px;font-size:13px}.product-groups{display:grid;gap:12px}.product-group{background:#fff;border:1px solid #e4e9f1;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.04);overflow:hidden}.product-group summary{list-style:none;display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:18px 20px;cursor:pointer}.product-group summary::-webkit-details-marker{display:none}.group-title strong{display:block;color:#0f172a;font-size:17px}.group-title span{display:block;color:#64748b;font-size:12px;margin-top:3px}.group-count{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:950}.mini-add{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:8px 10px;font-weight:900;cursor:pointer}.product-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}.product-table th{background:#f8fafc;color:#334155;padding:11px;text-align:left;border-top:1px solid #eef2f7}.product-table td{padding:12px 11px;border-top:1px solid #eef2f7;vertical-align:middle}.code-pill{display:inline-block;background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:950}.status-pill{display:inline-flex;gap:6px;align-items:center;background:#dcfce7;color:#047857;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:950}.status-pill:before{content:'?';font-size:9px}.icon-btn{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:9px;padding:8px 10px;font-weight:900;cursor:pointer}.delete-btn{border:1px solid #fecaca;background:#fff1f2;color:#dc2626;border-radius:9px;padding:8px 10px;font-weight:900;cursor:pointer}.row-actions{display:flex;gap:8px}.edit-row{display:none;background:#f8fafc}.editing+.edit-row{display:table-row}.editing{display:none}.confirm-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:20;align-items:center;justify-content:center}.confirm-modal.show{display:flex}.confirm-box{width:min(420px,92vw);background:#fff;border-radius:18px;padding:22px;box-shadow:0 25px 70px rgba(15,23,42,.25)}.confirm-box h3{margin:0 0 10px}.confirm-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}@media(max-width:1100px){.expense-grid,.expense-cards,.product-kpis,.product-tools{grid-template-columns:1fr 1fr}}@media(max-width:760px){.product-head,.product-group summary{display:block}.product-kpis,.product-tools{grid-template-columns:1fr}.primary-add{margin-top:12px}}
        .price-panel{background:#fff;border:1px solid #e4e9f1;border-radius:18px;margin:18px 0;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.price-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}.price-head h3{margin:0;color:#0f172a;font-size:20px}.price-head p{margin:4px 0 0;color:#64748b;font-size:12px}.rate-boxes{display:flex;gap:10px}.rate-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:10px 13px;min-width:110px}.rate-box span{display:block;color:#64748b;font-size:11px;font-weight:900}.rate-box strong{display:block;color:#0f172a;margin-top:4px}.move-up{color:#16a34a;font-weight:950}.move-down{color:#dc2626;font-weight:950}.price-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:30;align-items:center;justify-content:center;padding:20px}.price-modal.show{display:flex}.price-box{width:min(980px,96vw);max-height:90vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 30px 80px rgba(15,23,42,.28)}.price-box-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:18px 20px;border-bottom:1px solid #e5e7eb}.price-box-head h3{margin:0;color:#0f172a}.price-box-head p{margin:4px 0 0;color:#64748b;font-size:12px}.price-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:16px 20px;background:#f8fafc}.price-form label{font-size:11px;font-weight:900;color:#475569}.price-form input,.price-form select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:9px}.price-history{padding:0 20px 20px}.stock-page{display:grid;gap:16px}.stock-head{background:linear-gradient(125deg,#0f172a,#1d4ed8);color:#fff;border:0;border-radius:20px;padding:24px 28px;box-shadow:0 18px 42px rgba(15,23,42,.18)}.stock-head-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.stock-head h3{margin:0;font-size:25px}.stock-head p{margin:7px 0 0;color:#dbeafe;font-size:13px}.stock-status{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.24);border-radius:14px;padding:11px 14px;text-align:right}.stock-status span{display:block;color:#bfdbfe;font-size:11px;font-weight:900}.stock-status strong{display:block;margin-top:4px;font-size:19px}.stock-chips{display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-top:18px}.stock-chip{border:1px solid rgba(191,219,254,.45);background:rgba(239,246,255,.12);color:#eff6ff;border-radius:13px;padding:11px 9px;font-size:11px;font-weight:950;cursor:pointer;text-align:left}.stock-chip small{display:block;margin-top:3px;color:#cbd5e1;font-size:10px}.stock-chip.plus{background:rgba(22,163,74,.15);border-color:rgba(134,239,172,.55)}.stock-chip.minus{background:rgba(234,88,12,.14);border-color:rgba(253,186,116,.52)}.stock-form{background:#fff;border:1px solid #dbeafe;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(37,99,235,.07)}.stock-form h3{margin:0 0 12px;color:#0f172a}.stock-form .rm-form{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.stock-form .rm-field.wide{grid-column:span 2}.stock-kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:12px}.stock-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:14px 15px;box-shadow:0 10px 24px rgba(15,23,42,.045)}.stock-kpi span{display:block;color:#64748b;font-size:10px;font-weight:950;text-transform:uppercase}.stock-kpi strong{display:block;margin-top:7px;font-size:21px;color:#0f172a}.stock-kpi.fire strong{color:#dc2626}.stock-kpi.dark{background:#0f172a;color:#fff}.stock-kpi.dark span{color:#cbd5e1}.stock-kpi.dark strong{color:#facc15}.stock-tools{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:12px}.stock-tools input,.stock-tools select{border:1px solid #cbd5e1;border-radius:11px;padding:11px;font-size:13px}.stock-list{background:#fff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(15,23,42,.05)}.stock-list-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:17px 19px;border-bottom:1px solid #e2e8f0}.stock-list-head h3{margin:0;color:#0f172a;font-size:18px}.stock-list-head span{color:#64748b;font-size:12px;font-weight:900}.stock-list .rm-table{table-layout:fixed}.stock-list th,.stock-list td{padding:10px 8px;font-size:12px}.stock-route{color:#475569;font-size:11px;line-height:1.45}.stock-route b{color:#0f172a}.stock-amount.plus{color:#047857}.stock-amount.minus{color:#b91c1c}.tip-badge{display:inline-block;border-radius:999px;padding:6px 9px;font-size:10px;font-weight:950;background:#eff6ff;color:#1d4ed8}.tip-badge.plus{background:#dcfce7;color:#047857}.tip-badge.minus{background:#fee2e2;color:#b91c1c}.tip-badge.neutral{background:#e0f2fe;color:#0369a1}.stock-empty{padding:28px;text-align:center;color:#64748b}.stock-empty strong{display:block;color:#0f172a;margin-bottom:5px}@media(max-width:1100px){.stock-chips,.stock-kpis{grid-template-columns:1fr 1fr 1fr}.stock-form .rm-form{grid-template-columns:1fr 1fr}.stock-tools{grid-template-columns:1fr}}@media(max-width:760px){.stock-head{padding:20px}.stock-head-top{display:block}.stock-status{text-align:left;margin-top:14px}.stock-chips,.stock-kpis,.stock-form .rm-form{grid-template-columns:1fr}.stock-form .rm-field.wide{grid-column:auto}.stock-list th:nth-child(2),.stock-list td:nth-child(2),.stock-list th:nth-child(7),.stock-list td:nth-child(7){display:none}.stock-list th,.stock-list td{font-size:11px;padding:8px 6px}}.recipe-mode .rm-tabs{display:grid}.product-mode .rm-hero{display:flex}.recipe-mode .rm-hero,.expense-mode .rm-hero,.product-mode .rm-hero{background:linear-gradient(135deg,#0f172a,#1d4ed8);align-items:center}
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
/* Products workspace: one component surface for catalogue, recipe and cost actions. */
.product-workspace{display:grid;gap:16px}
.product-overview{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding:22px 24px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}
.product-overview small{display:block;margin-bottom:7px;color:#2563eb;font-size:11px;font-weight:950;letter-spacing:.08em;text-transform:uppercase}
.product-overview h2{margin:0;color:#0f172a;font-size:24px}.product-overview p{max-width:680px;margin:7px 0 0;color:#64748b;font-size:13px;line-height:1.55}
.product-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.summary-tile{position:relative;overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:16px 18px}
.summary-tile:after{content:'';position:absolute;right:-14px;bottom:-18px;width:58px;height:58px;border-radius:50%;background:#eff6ff}
.summary-tile span{display:block;color:#64748b;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.summary-tile strong{display:block;margin-top:7px;color:#0f172a;font-size:25px}.summary-tile.alert strong{color:#c2410c}
.product-controlbar{display:grid;grid-template-columns:minmax(240px,1.2fr) minmax(0,2fr) auto;align-items:center;gap:12px;padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:15px}
.product-searchbox{position:relative}.product-searchbox:before{content:'Ara';position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:10px;font-weight:950;color:#2563eb;text-transform:uppercase}.product-searchbox input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:11px;padding:11px 12px 11px 48px;font-size:13px}
.product-filters{display:flex;gap:7px;overflow:auto;padding-bottom:1px}.product-filter{white-space:nowrap;border:1px solid #dbe3ee;background:#f8fafc;color:#475569;border-radius:999px;padding:8px 11px;font-size:11px;font-weight:900;cursor:pointer}.product-filter.active{border-color:#2563eb;background:#2563eb;color:#fff}
.view-switch{display:flex;border:1px solid #dbe3ee;border-radius:10px;padding:3px}.view-switch button{border:0;background:transparent;color:#64748b;border-radius:7px;padding:7px 10px;font-size:11px;font-weight:900;cursor:pointer}.view-switch button.active{background:#eaf2ff;color:#1d4ed8}
.product-catalogue{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.product-catalogue.list-view{grid-template-columns:1fr}
.product-module{display:flex;flex-direction:column;min-width:0;background:#fff;border:1px solid #e2e8f0;border-radius:17px;box-shadow:0 10px 24px rgba(15,23,42,.045);transition:.18s ease}.product-module:hover{border-color:#bfdbfe;transform:translateY(-2px);box-shadow:0 16px 30px rgba(37,99,235,.09)}
.product-module-head{display:flex;justify-content:space-between;gap:12px;padding:16px 16px 12px}.product-code{display:inline-flex;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:950;letter-spacing:.03em}.recipe-state{font-size:10px;font-weight:950;border-radius:999px;padding:5px 8px;background:#dcfce7;color:#047857}.recipe-state.missing{background:#fff7ed;color:#c2410c}
.product-module-body{padding:0 16px 15px}.product-module-body h3{margin:0;color:#0f172a;font-size:16px;line-height:1.35}.product-group-label{display:block;margin-top:5px;color:#64748b;font-size:11px;font-weight:800}
.product-specs{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:15px}.product-spec{min-width:0;background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:9px}.product-spec span{display:block;color:#94a3b8;font-size:9px;font-weight:950;text-transform:uppercase}.product-spec strong{display:block;overflow:hidden;margin-top:4px;color:#334155;font-size:11px;text-overflow:ellipsis;white-space:nowrap}
.product-flow{display:grid;grid-template-columns:repeat(4,1fr);margin-top:auto;border-top:1px solid #eef2f7}.product-flow a,.product-flow button{display:block;border:0;border-right:1px solid #eef2f7;background:#fff;color:#475569;padding:11px 5px;text-align:center;text-decoration:none;font-size:10px;font-weight:900;cursor:pointer}.product-flow>*:last-child{border-right:0}.product-flow a:hover,.product-flow button:hover{background:#eff6ff;color:#1d4ed8}
.product-card-actions{display:flex;gap:7px;padding:10px 12px;background:#f8fafc;border-top:1px solid #eef2f7;border-radius:0 0 17px 17px}.product-card-actions button{flex:1;border:1px solid #dbe3ee;border-radius:9px;background:#fff;color:#475569;padding:8px;font-size:10px;font-weight:900;cursor:pointer}.product-card-actions button.delete{flex:0 0 auto;color:#dc2626;border-color:#fecaca;background:#fff7f7}
.product-empty{display:none;grid-column:1/-1;padding:40px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b}.product-empty strong{display:block;margin-bottom:5px;color:#0f172a}
.product-catalogue.list-view .product-module{display:grid;grid-template-columns:minmax(240px,1.3fr) minmax(300px,1fr) minmax(300px,1.15fr);align-items:center}.product-catalogue.list-view .product-module-head{grid-column:1;padding-bottom:4px}.product-catalogue.list-view .product-module-body{grid-column:1;padding-top:0}.product-catalogue.list-view .product-specs{position:absolute;left:-9999px}.product-catalogue.list-view .product-flow{grid-column:2;margin:0;border:0}.product-catalogue.list-view .product-card-actions{grid-column:3;border-top:0;border-left:1px solid #eef2f7;border-radius:0 17px 17px 0;background:#fff}
@media(max-width:1250px){.product-catalogue{grid-template-columns:repeat(2,minmax(0,1fr))}.product-controlbar{grid-template-columns:1fr}.view-switch{justify-self:start}.product-catalogue.list-view .product-module{grid-template-columns:1fr 1fr}.product-catalogue.list-view .product-card-actions{grid-column:1/-1;border-left:0;border-top:1px solid #eef2f7}}
@media(max-width:760px){.product-overview{display:block}.product-overview .primary-add{width:100%;margin-top:16px}.product-summary{grid-template-columns:1fr 1fr}.product-catalogue{grid-template-columns:1fr}.product-catalogue.list-view .product-module{display:flex}.product-catalogue.list-view .product-specs{position:static}.product-catalogue.list-view .product-card-actions{border-left:0}.product-filters{margin-right:-4px}.view-switch{display:none}}
.production-workspace{display:grid;gap:16px}.production-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;background:linear-gradient(125deg,#0f172a,#1d4ed8);color:#fff;border-radius:20px;padding:24px 28px;box-shadow:0 18px 42px rgba(15,23,42,.18)}.production-hero small{display:inline-flex;background:rgba(96,165,250,.22);border:1px solid rgba(191,219,254,.3);border-radius:999px;padding:7px 11px;color:#bfdbfe;font-size:11px;font-weight:950;letter-spacing:.08em;text-transform:uppercase}.production-hero h2{margin:10px 0 6px;font-size:28px}.production-hero p{margin:0;color:#dbeafe;font-size:13px}.production-period{min-width:170px;text-align:center;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);border-radius:16px;padding:14px}.production-period span{display:block;color:#bfdbfe;font-size:11px;font-weight:900}.production-period strong{display:block;margin-top:4px;font-size:20px}.production-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.production-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px 18px;box-shadow:0 10px 24px rgba(15,23,42,.045)}.production-kpi span{display:block;color:#64748b;font-size:11px;font-weight:950;text-transform:uppercase}.production-kpi strong{display:block;margin-top:7px;color:#0f172a;font-size:24px}.production-kpi em{display:block;margin-top:4px;color:#2563eb;font-size:11px;font-style:normal;font-weight:900}.production-panel{background:#fff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(15,23,42,.05)}.production-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:18px 20px;border-bottom:1px solid #e2e8f0}.production-panel-head h3{margin:0;color:#0f172a;font-size:18px}.production-panel-head p{margin:4px 0 0;color:#64748b;font-size:12px}.production-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px}.production-card{border:1px solid #e2e8f0;border-radius:15px;padding:14px;background:#f8fafc}.production-card-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.production-code{display:inline-flex;background:#e0ecff;color:#1d4ed8;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:950}.production-card h4{margin:10px 0 4px;color:#0f172a;font-size:15px;line-height:1.35}.production-card .muted{font-size:11px}.production-qty{font-size:20px;font-weight:950;color:#0f172a;text-align:right}.production-qty small{display:block;color:#64748b;font-size:10px;font-weight:900}.production-bar{height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:13px}.production-bar span{display:block;height:100%;background:linear-gradient(90deg,#16a34a,#2563eb);border-radius:999px}.production-meta{display:flex;justify-content:space-between;margin-top:8px;color:#64748b;font-size:11px;font-weight:800}.production-table{padding:0 16px 16px}.production-table .rm-table{table-layout:fixed}.production-table th,.production-table td{font-size:12px;padding:10px}.production-table .rm-product{word-break:break-word}.production-ratio{display:flex;align-items:center;gap:8px}.production-ratio i{display:block;flex:1;height:7px;background:#e2e8f0;border-radius:999px;overflow:hidden}.production-ratio i b{display:block;height:100%;background:#2563eb}@media(max-width:1050px){.production-grid,.production-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.production-hero{align-items:flex-start}}@media(max-width:720px){.production-hero{display:block;padding:20px}.production-period{text-align:left;margin-top:14px}.production-grid,.production-kpis{grid-template-columns:1fr}.production-table th:nth-child(1),.production-table td:nth-child(1){display:none}.production-table th,.production-table td{font-size:11px;padding:8px 6px}.production-qty{text-align:left}}
.rm-page,.recipe-mode,.expense-mode,.product-mode{max-width:1440px;width:100%;box-sizing:border-box}
.period-tools{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.period-add-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);border-radius:12px;padding:8px}.period-add-form label{font-size:12px;font-weight:900;color:#dbeafe}.period-add-form input,.period-add-form select{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.95);color:#0f172a;border-radius:9px;padding:9px 10px;font-weight:800}.period-add-form input[type=number]{width:86px}.period-add-form input[name=toplam_uretim]{width:118px}.period-add-form button{border:0;border-radius:9px;background:#10b981;color:#fff;padding:9px 12px;font-weight:950;cursor:pointer}@media(max-width:800px){.period-tools{justify-content:flex-start;margin-top:14px}.period-add-form input[name=toplam_uretim]{width:100%}}
.cost-sheet{border:1px solid #111827;border-radius:8px;overflow:hidden;background:#fff}.cost-sheet-head,.cost-line{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(120px,.8fr);align-items:center}.cost-sheet-head{background:#5b9bd5;color:#06111f;font-weight:950;font-size:14px}.cost-sheet-head span,.cost-sheet-head b,.cost-line span,.cost-line b{padding:10px 12px;border-bottom:1px solid #111827}.cost-sheet-head span,.cost-line span{border-right:1px solid #111827}.cost-line span{font-weight:950;color:#020617}.cost-line b{text-align:right;font-weight:950;color:#020617}.cost-total span,.cost-total b{color:#ef0000}.cost-gap{height:22px;border-bottom:1px solid #111827}.cost-yellow span,.cost-yellow b{background:#fff200}.cost-blue span,.cost-blue b{background:#dbeaf7}.cost-sheet .cost-line:last-child span,.cost-sheet .cost-line:last-child b{border-bottom:0}
.cost-sheet{border:1px solid #dbe4ef;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 14px 32px rgba(15,23,42,.07)}
.cost-sheet-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:16px 18px;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff}
.cost-sheet-top span{display:block;color:#bfdbfe;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.cost-sheet-top strong{display:block;margin-top:4px;font-size:17px;line-height:1.25}.cost-sheet-top em{font-style:normal;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:7px 10px;font-size:11px;font-weight:900;color:#eff6ff;white-space:nowrap}
.cost-modern-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.cost-modern-table th{background:#f8fafc;color:#475569;text-align:left;padding:10px;border-bottom:1px solid #e2e8f0}.cost-modern-table td{padding:11px 10px;border-bottom:1px solid #edf2f7;vertical-align:top}.cost-modern-table tr:last-child td{border-bottom:0}.cost-modern-table td:first-child{font-weight:900;color:#0f172a}.cost-modern-table .num{text-align:right;font-weight:950;color:#0f172a;white-space:nowrap;font-variant-numeric:tabular-nums}
.cost-modern-table details{font-size:11px;color:#64748b}.cost-modern-table summary{display:inline-flex;cursor:pointer;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:5px 8px;font-weight:900;list-style:none}.cost-modern-table summary::-webkit-details-marker{display:none}.cost-modern-table details[open] summary{margin-bottom:8px;background:#dbeafe}.cost-modern-table details p{margin:5px 0;line-height:1.45}.cost-modern-table .cost-total td{background:#fff7ed;color:#c2410c}.cost-modern-table .cost-total td:first-child,.cost-modern-table .cost-total .num{color:#dc2626}.cost-modern-table .cost-yellow td{background:#fefce8}.cost-modern-table .cost-blue td{background:#eff6ff}
.calc-step-box{margin-bottom:16px;border:1px solid #dbe4ef;border-radius:14px;overflow:hidden;background:#fff}.calc-step-title{padding:14px 16px;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff}.calc-step-title h4{margin:0;font-size:16px}.calc-step-title p{margin:5px 0 0;color:#bfdbfe;font-size:12px}.calc-step-table{width:100%;border-collapse:collapse;font-size:12px}.calc-step-table th{background:#f1f5f9;color:#334155;text-align:left;padding:9px}.calc-step-table td{border-top:1px solid #e2e8f0;padding:9px;vertical-align:top}.calc-step-table td:last-child{font-weight:950;color:#0f172a;white-space:nowrap;text-align:right}.calc-step-note{padding:12px 16px;background:#fffbeb;color:#92400e;font-size:12px;line-height:1.55}.calc-step-total td{background:#fef2f2;color:#dc2626;font-weight:950}.calc-step-blue td{background:#eff6ff}.calc-step-yellow td{background:#fefce8}
.rm-tabs{grid-template-columns:repeat(5,minmax(120px,1fr))}
</style>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="main rm-page <?php echo $tab==='receteler'?'recipe-mode':''; ?> <?php echo $tab==='urunler'?'product-mode':''; ?>">
    <section class="rm-hero">
        <div><h1>Reçete ve Maliyet</h1><p>Reçete, fiyatlama, üretim ve koli başı maliyet kontrol paneli.</p></div>
        <div class="period-tools">
            <form class="rm-filter" method="GET"><input type="hidden" name="tab" value="<?php echo rm_e($tab); ?>"><label>Dönem</label><select name="donem" onchange="this.form.submit()"><?php foreach($donemler as $d): ?><option value="<?php echo rm_e($d['donem']); ?>" <?php echo $donem===$d['donem'] ? 'selected' : ''; ?>><?php echo rm_e($d['donem_adi']); ?></option><?php endforeach; ?></select><span class="rm-pill"><?php echo rm_e($currentDonem['durum']); ?></span></form>
            <?php $heroYear = (int)substr($donem,0,4); $heroMonth = (int)substr($donem,5,2); ?>
            <form class="period-add-form" method="POST">
                <input type="hidden" name="action" value="donem_ekle">
                <input type="hidden" name="return_tab" value="<?php echo rm_e($tab); ?>">
                <label>Ay Ekle</label>
                <input type="number" name="donem_yil" min="2020" max="2100" value="<?php echo rm_e($heroYear ?: (int)date('Y')); ?>">
                <select name="donem_ay"><?php foreach($aylar2026 as $m=>$ad): ?><option value="<?php echo (int)$m; ?>" <?php echo $heroMonth===(int)$m ? 'selected' : ''; ?>><?php echo rm_e($ad); ?></option><?php endforeach; ?></select>
                <input type="text" name="toplam_uretim" placeholder="Üretim">
                <button type="submit">Ekle</button>
            </form>
        </div>
    </section>
    <nav class="rm-tabs">
        <a class="<?php echo $tab==='ozet'?'active':''; ?>" href="?tab=ozet&donem=<?php echo rm_e($donem); ?>">Maliyet Özeti</a>
        <a class="<?php echo $tab==='urunler'?'active':''; ?>" href="?tab=urunler&donem=<?php echo rm_e($donem); ?>">Ürünler</a>
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
            <?php
                $hammaddeDetaylari = array_values(array_filter($detaylar, fn($d) => (string)$d['kategori'] === 'Hammadde'));
                $giderDetaylari = array_values(array_filter($detaylar, fn($d) => (string)$d['kategori'] !== 'Hammadde'));
            ?>
            <?php
                $selHammadde = (float)($selected['hammadde'] ?? 0);
                $selG730 = (float)($selected['g730'] ?? 0);
                $selG760 = (float)($selected['g760'] ?? 0);
                $selG770 = (float)($selected['g770'] ?? 0);
                $selG720 = (float)($selected['g720'] ?? 0);
                $selToplam = (float)($selected['toplam'] ?? 0);
                $selNakliye = (float)($selectedNd['nakliye_tl_koli'] ?? 0);
                $selDekap = (float)($selectedNd['dekap_tl_koli'] ?? 0);
            ?>
            <div class="cost-sheet">
                <div class="cost-sheet-top">
                    <div><span>Maliyet Özeti</span><strong><?php echo rm_e($selected['urun_adi'] ?? 'Ürün'); ?></strong></div>
                    <em><?php echo rm_e($currentDonem['donem_adi']); ?></em>
                </div>
                <table class="cost-modern-table">
                    <thead><tr><th>Kalem</th><th>Tutar</th><th>Kaynak / Hesap</th></tr></thead>
                    <tbody>
                        <tr><td>Hammadde Tutarı</td><td class="num"><?php echo rm_money4($selHammadde); ?></td><td><details><summary>Detay</summary><p>Kaynak: aktif reçete satırları (`recete_bom_kalemleri`) maliyet özetine aktarılır.</p><?php foreach($hammaddeDetaylari as $d): ?><p><?php echo rm_e($d['kalem_adi']); ?>: <?php echo rm_money4($d['tutar']); ?> · <?php echo rm_e($d['aciklama'] ?? ''); ?></p><?php endforeach; ?></details></td></tr>
                        <tr><td>730 İşçilik Hariç Gider</td><td class="num"><?php echo rm_money4($selG730); ?></td><td><details><summary>Detay</summary><p>Kaynak: genel gider kalemi. Formül: koli başı gider payı doğrudan ürün maliyetine eklenir.</p></details></td></tr>
                        <tr><td>760 Pazarlama Gideri</td><td class="num"><?php echo rm_money4($selG760); ?></td><td><details><summary>Detay</summary><p>Kaynak: genel gider kalemi. Formül: pazarlama gider payı koli başına eklenir.</p></details></td></tr>
                        <tr><td>Genel Yönetim Gideri</td><td class="num"><?php echo rm_money4($selG770); ?></td><td><details><summary>Detay</summary><p>Kaynak: genel gider kalemi. Formül: yönetim gider payı koli başına eklenir.</p></details></td></tr>
                        <tr><td>İşçilik Gideri</td><td class="num"><?php echo rm_money4($selG720); ?></td><td><details><summary>Detay</summary><p>Kaynak: genel gider kalemi. Formül: işçilik gider payı koli başına eklenir.</p></details></td></tr>
                        <tr class="cost-total"><td>Koli Başına Maliyet</td><td class="num"><?php echo rm_money4($selToplam); ?></td><td><details><summary>Formül</summary><p>Hammadde + 730 + 760 + Genel Yönetim + İşçilik = <?php echo rm_money4($selToplam); ?></p></details></td></tr>
                        <tr class="cost-yellow"><td>Nakliye Dahil Fiyat</td><td class="num"><?php echo rm_money4($selToplam); ?></td><td><details><summary>Formül</summary><p>Koli başına maliyet mevcut nakliyeli fiyat olarak alınır.</p></details></td></tr>
                        <tr class="cost-yellow"><td>Nakliyesiz Fiyat</td><td class="num"><?php echo rm_money4($selToplam - $selNakliye); ?></td><td><details><summary>Formül</summary><p>Koli başına maliyet - nakliye (<?php echo rm_money4($selNakliye); ?>).</p></details></td></tr>
                        <tr class="cost-blue"><td>Nakliyesiz + DEKAP Dahil</td><td class="num"><?php echo rm_money4($selToplam - $selNakliye + $selDekap); ?></td><td><details><summary>Formül</summary><p>Nakliyesiz fiyat + DEKAP (<?php echo rm_money4($selDekap); ?>).</p></details></td></tr>
                        <tr class="cost-blue"><td>Nakliye + DEKAP Dahil</td><td class="num"><?php echo rm_money4($selToplam + $selDekap); ?></td><td><details><summary>Formül</summary><p>Koli başına maliyet + DEKAP (<?php echo rm_money4($selDekap); ?>).</p></details></td></tr>
                    </tbody>
                </table>
            </div>
        </aside>
    </section>
    <div class="calc-modal" id="calcModal" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="calc-box">
            <div class="calc-head">
                <div><h3>Maliyet Hesaplama Detayı</h3><p><?php echo rm_e($selected['urun_adi'] ?? 'Ürün seçin'); ?> - <?php echo rm_e($currentDonem['donem_adi']); ?></p></div>
                <button class="calc-close" type="button" onclick="document.getElementById('calcModal').classList.remove('show')">Kapat</button>
            </div>
            <div class="calc-body">
                <?php if((string)($selected['urun_adi'] ?? '') === '0,33 L Cam Şişe Su'): ?>
                <div class="calc-step-box">
                    <div class="calc-step-title"><h4>0,33 lt Cam Şişe - Koli Başına Nihai Maliyet</h4><p>L sütunu mantığıyla gerçek sayılar ve kaynak hücre açıklamaları.</p></div>
                    <table class="calc-step-table"><thead><tr><th>Kalem</th><th>Formül</th><th>Kaynak veri</th><th>Sonuç</th></tr></thead><tbody>
                        <tr><td>Cam şişe</td><td>12 × W6</td><td>W6 = 3,8601 TL/şişe; 12 şişe/koli</td><td>46,3212</td></tr>
                        <tr><td>Kapak</td><td>12 × W7</td><td>W7 = 0,637 TL/kapak; 12 adet</td><td>7,6440</td></tr>
                        <tr><td>Kulp</td><td>U12</td><td>48mm kulp fiyatı girilmemiş</td><td>0,0000</td></tr>
                        <tr><td>Etiket</td><td>W8</td><td>Stiker etiket sabit fiyatı</td><td>0,9000</td></tr>
                        <tr><td>Shrink Film</td><td>17,6 × U21</td><td>U21 = (2790 / 1.000.000) × 46,16 = 0,1287864</td><td>2,2666</td></tr>
                        <tr><td>Diğer hammadde</td><td>0</td><td>Streç, seperatör, emniyet bandı, koli ve folyolar L sütununda girilmemiş</td><td>0,0000</td></tr>
                        <tr class="calc-step-total"><td>Hammadde toplamı</td><td>SUM(L5:L16)</td><td>Koli bazında toplam</td><td>57,1318</td></tr>
                        <tr><td>Fire</td><td>57,1318 / 100 × 3</td><td>S2 = %3 fire</td><td>1,7140</td></tr>
                        <tr class="calc-step-total"><td>Fireli hammadde</td><td>57,1318 + 1,7140</td><td>L20</td><td>58,8458</td></tr>
                    </tbody></table>
                    <table class="calc-step-table"><thead><tr><th>Gider</th><th>Toplam tutar</th><th>Formül</th><th>Koli başı</th></tr></thead><tbody>
                        <tr><td>730 İşçilik Hariç Gider</td><td>4.006.861,28</td><td>S37 / B23, B23 = 509.895 koli</td><td>7,8582</td></tr>
                        <tr><td>760 Pazarlama Gideri</td><td>1.344.152,66</td><td>S38 / B23</td><td>2,6361</td></tr>
                        <tr><td>770 Genel Yönetim Gideri</td><td>6.366.475,10</td><td>S39 / B23</td><td>12,4859</td></tr>
                        <tr><td>720 İşçilik Gideri</td><td>4.672.230,79</td><td>S40 / B23</td><td>9,1631</td></tr>
                        <tr class="calc-step-total"><td>Koli başına maliyet</td><td colspan="2">58,8458 + 7,8582 + 2,6361 + 12,4859 + 9,1631</td><td>90,9891</td></tr>
                        <tr class="calc-step-yellow"><td>Nakliye dahil fiyat</td><td colspan="2">L34</td><td>90,9891</td></tr>
                        <tr class="calc-step-yellow"><td>Nakliyesiz fiyat</td><td colspan="2">90,9891 - 3,7400</td><td>87,2491</td></tr>
                        <tr class="calc-step-blue"><td>Nakliyesiz + DEKAP dahil</td><td colspan="2">87,2491 + 6,3600</td><td>93,6091</td></tr>
                        <tr class="calc-step-blue"><td>Nakliye + DEKAP dahil</td><td colspan="2">90,9891 + 6,3600</td><td>97,3491</td></tr>
                    </tbody></table>
                    <div class="calc-step-note">Bu ürün için ilgili ayda üretim yoksa sonuç fiili gerçekleşen değil, bugün üretilseydi oluşacak teorik koli maliyetidir.</div>
                </div>
                <?php endif; ?>
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
    <section class="product-workspace">
        <div class="product-overview">
            <div><small>Ürün merkezi</small><h2>Ürün portföyü</h2><p>Ürün kartlarını yönetin; reçete, fiyat, üretim ve koli maliyeti adımlarına tek noktadan geçin.</p></div>
            <button class="primary-add" type="button" onclick="openProductCard()">+ Yeni Ürün</button>
        </div>
        <div class="product-summary" aria-label="Ürün özeti">
            <div class="summary-tile"><span>Toplam ürün</span><strong><?php echo (int)$urunKpi['toplam']; ?></strong></div>
            <div class="summary-tile"><span>Aktif ürün</span><strong><?php echo (int)$urunKpi['aktif']; ?></strong></div>
            <div class="summary-tile"><span>Ürün grubu</span><strong><?php echo (int)$urunKpi['grup']; ?></strong></div>
            <div class="summary-tile alert"><span>Eksik reçete</span><strong><?php echo (int)$urunKpi['eksik']; ?></strong></div>
        </div>
        <div class="product-controlbar">
            <label class="product-searchbox"><input id="productSearchModern" type="search" placeholder="Ürün adı, kod veya ambalaj tipi..." aria-label="Ürünlerde ara"></label>
            <div class="product-filters" aria-label="Ürün grupları">
                <button class="product-filter active" type="button" data-group="">Tümü <span><?php echo count($urunler); ?></span></button>
                <?php foreach($urunGruplari as $key=>$g): ?><button class="product-filter" type="button" data-group="<?php echo rm_e($key); ?>"><?php echo rm_e($g['baslik']); ?> <span><?php echo count($g['urunler']); ?></span></button><?php endforeach; ?>
            </div>
            <div class="view-switch" aria-label="Görünüm seçimi"><button class="active" type="button" data-view="grid">Kartlar</button><button type="button" data-view="list">Liste</button></div>
        </div>
        <div class="product-catalogue" id="productCatalogue">
            <?php foreach($urunler as $u): $code=rm_urun_kodu_satir($u); $hacim=rm_urun_hacim($u); $varyant=rm_urun_varyant($u); $group=rm_urun_grubu($u); $hasRecipe=(int)($bomDurum[(int)$u['id']] ?? 0)>0; ?>
            <article class="product-module" data-group="<?php echo rm_e($group[0]); ?>" data-search="<?php echo rm_e(rm_lower($code.' '.$u['urun_adi'].' '.$varyant.' '.$u['ambalaj_tipi'].' '.$group[1])); ?>">
                <div class="product-module-head"><span class="product-code"><?php echo rm_e($code); ?></span><span class="recipe-state <?php echo $hasRecipe?'':'missing'; ?>"><?php echo $hasRecipe?'Reçete hazır':'Reçete eksik'; ?></span></div>
                <div class="product-module-body"><h3><?php echo rm_e($u['urun_adi']); ?></h3><span class="product-group-label"><?php echo rm_e($group[1].' · '.$varyant); ?></span><div class="product-specs"><div class="product-spec"><span>Hacim</span><strong><?php echo rm_e($hacim ?: '-'); ?></strong></div><div class="product-spec"><span>Koli içi</span><strong><?php echo number_format((float)$u['koli_ici_adet'],0,',','.'); ?> adet</strong></div><div class="product-spec"><span>Ambalaj</span><strong><?php echo rm_e($u['ambalaj_tipi']); ?></strong></div></div></div>
                <nav class="product-flow" aria-label="<?php echo rm_e($u['urun_adi']); ?> modülleri"><a href="?tab=receteler&amp;donem=<?php echo rm_e($donem); ?>&amp;urun_id=<?php echo (int)$u['id']; ?>">Reçete</a><button type="button" data-name="<?php echo rm_e($u['urun_adi']); ?>" onclick="openPriceModal(this)">Fiyat</button><a href="?tab=uretim&amp;donem=<?php echo rm_e($donem); ?>">Üretim</a><a href="?tab=ozet&amp;donem=<?php echo rm_e($donem); ?>&amp;urun_id=<?php echo (int)$u['id']; ?>">Maliyet</a></nav>
                <div class="product-card-actions"><button type="button" data-id="<?php echo (int)$u['id']; ?>" data-code="<?php echo rm_e($code); ?>" data-name="<?php echo rm_e($u['urun_adi']); ?>" data-variant="<?php echo rm_e($u['urun_grubu']); ?>" data-hacim="<?php echo rm_e($hacim); ?>" data-koli="<?php echo number_format((float)$u['koli_ici_adet'],0,',','.'); ?>" data-ambalaj="<?php echo rm_e($u['ambalaj_tipi']); ?>" onclick="openProductCard(this)">Ürün kartını düzenle</button><button class="delete" type="button" aria-label="Ürünü sil" data-id="<?php echo (int)$u['id']; ?>" data-code="<?php echo rm_e($code); ?>" data-name="<?php echo rm_e($u['urun_adi']); ?>" onclick="confirmDeleteBtn(this)">Sil</button></div>
            </article>
            <?php endforeach; ?>
            <div class="product-empty" id="productEmpty"><strong>Uygun ürün bulunamadı</strong>Arama kelimesini veya ürün grubu filtresini değiştirin.</div>
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
    var psModern=document.getElementById('productSearchModern'), activeProductGroup='';
    function filterProductModules(){
        var q=psModern ? psModern.value.toLocaleLowerCase('tr-TR').trim() : '', visible=0;
        document.querySelectorAll('.product-module').forEach(function(card){
            var matchText=!q || card.dataset.search.indexOf(q)>-1;
            var matchGroup=!activeProductGroup || card.dataset.group===activeProductGroup;
            var show=matchText && matchGroup; card.style.display=show ? '' : 'none'; if(show) visible++;
        });
        var empty=document.getElementById('productEmpty'); if(empty){ empty.style.display=visible ? 'none' : 'block'; }
    }
    if(psModern){ psModern.addEventListener('input', filterProductModules); }
    document.querySelectorAll('.product-filter').forEach(function(btn){ btn.addEventListener('click',function(){
        document.querySelectorAll('.product-filter').forEach(function(b){ b.classList.remove('active'); });
        this.classList.add('active'); activeProductGroup=this.dataset.group||''; filterProductModules();
    }); });
    document.querySelectorAll('.view-switch button').forEach(function(btn){ btn.addEventListener('click',function(){
        document.querySelectorAll('.view-switch button').forEach(function(b){ b.classList.remove('active'); }); this.classList.add('active');
        var catalogue=document.getElementById('productCatalogue'); if(catalogue){ catalogue.classList.toggle('list-view',this.dataset.view==='list'); }
    }); });
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
    </script>
    <?php endif; ?>

    <?php if($tab==='hammaddeler'): ?>
    <section class="rm-panel"><h3>Hammaddeler</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Malzeme Kodu</th><th>Malzeme Ad?</th><th>Kategori</th><th>Ana Birim</th><th>Al?? Birimi</th><th>Durum</th></tr></thead><tbody><?php foreach($hammaddeler as $h): ?><tr><td>HM-<?php echo (int)$h['id']; ?></td><td><span class="rm-material"><?php echo rm_e($h['kalem_adi']); ?></span></td><td><?php echo rm_e($h['kategori']); ?></td><td><?php echo rm_e($h['birim']); ?></td><td><?php echo rm_e($h['birim']); ?></td><td><span class="rm-pill">Aktif</span></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>

    <?php if($tab==='stok_hareketleri'): $tipler=['uretilen'=>['Fabrika Dolum Girişi','plus','Stoğa ekler'],'birimler_arasi_sevk'=>['Birim Transferi','minus','Stoktan düşer'],'bayi_sevk'=>['Bayi Sevk','minus','Stoktan düşer'],'zincir_market_sevk'=>['Müşteri / Market Sevk','minus','Stoktan düşer'],'fire'=>['Fire / Zayiat','minus','Stoktan düşer'],'iade_giris'=>['İade Kabul','plus','Stoğa ekler']]; ?>
    <section class="stock-page">
        <div class="stock-head">
            <div class="stock-head-top">
                <div><h3>Su Ürünleri Stok Hareketleri</h3><p>Dolum, transfer, sevk, iade ve fire kayıtlarını tek ekranda takip edin.</p></div>
                <div class="stock-status"><span>Dönem</span><strong><?php echo rm_e($currentDonem['donem_adi']); ?></strong></div>
            </div>
            <div class="stock-chips">
                <?php foreach($tipler as $k=>$t): ?><button class="stock-chip <?php echo rm_e($t[1]); ?>" type="button" onclick="setStockType('<?php echo rm_e($k); ?>')"><?php echo rm_e($t[0]); ?><small><?php echo rm_e($t[2]); ?></small></button><?php endforeach; ?>
            </div>
        </div>
        <section class="stock-form">
            <h3>Yeni Stok Hareketi</h3>
            <form method="POST" class="rm-form">
                <input type="hidden" name="action" value="stok_hareket_kaydet">
                <div class="rm-field wide"><label>Su Ürünü</label><select name="urun_id" required><?php foreach($urunler as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo rm_e(rm_urun_kodu_satir($u).' - '.$u['urun_adi'].' (Koli)'); ?></option><?php endforeach; ?></select></div>
                <div class="rm-field"><label>Hareket Tipi</label><select name="hareket_tipi" id="stockType"><?php foreach($tipler as $k=>$t): ?><option value="<?php echo rm_e($k); ?>"><?php echo rm_e($t[0]); ?></option><?php endforeach; ?></select></div>
                <div class="rm-field"><label>Miktar (Koli)</label><input name="miktar" value="0"></div>
                <div class="rm-field"><label>Tarih</label><input type="date" name="tarih" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="rm-field"><label>Belge / İrsaliye No</label><input name="belge_no" placeholder="IRS-2026-104"></div>
                <div class="rm-field"><label>Çıkış Deposu / Noktası</label><input name="cikis_depo" list="depoList" placeholder="Dolum Tesis Deposu"></div>
                <div class="rm-field"><label>Varış Deposu / Teslim Yeri</label><input name="varis_depo" list="depoList" placeholder="Ankara Bayi"></div>
                <div class="rm-field wide"><label>Açıklama</label><input name="aciklama" placeholder="Araç, şoför, sevkiyat notu"></div>
                <datalist id="depoList"><?php foreach($depolar as $d): ?><option value="<?php echo rm_e($d['yer_adi']); ?>"></option><?php endforeach; ?></datalist>
                <div class="rm-field"><label>&nbsp;</label><button class="primary-add">Kaydet ve Stok Sayımına Aktar</button></div>
            </form>
        </section>
        <section class="stock-kpis">
            <div class="stock-kpi"><span>Üretilen / Dolum</span><strong><?php echo number_format($stokKpi['uretilen'],0,',','.'); ?></strong></div>
            <div class="stock-kpi"><span>Müşteri Sevkiyatı</span><strong><?php echo number_format($stokKpi['zincir_market_sevk'],0,',','.'); ?></strong></div>
            <div class="stock-kpi"><span>Bayi Sevkiyatı</span><strong><?php echo number_format($stokKpi['bayi_sevk'],0,',','.'); ?></strong></div>
            <div class="stock-kpi"><span>Birim Transferi</span><strong><?php echo number_format($stokKpi['birimler_arasi_sevk'],0,',','.'); ?></strong></div>
            <div class="stock-kpi fire"><span>Fire / Zayiat</span><strong><?php echo number_format($stokKpi['fire'],0,',','.'); ?></strong></div>
            <div class="stock-kpi dark"><span>Anlık Net Stok</span><strong><?php echo number_format($stokMevcut,0,',','.'); ?></strong></div>
        </section>
        <section class="stock-list">
            <div class="stock-list-head"><div><h3>Ürün Bazlı Stok Tablosu</h3><span><?php echo count($stokUrunOzet); ?> ürün listeleniyor</span></div></div>
            <div class="rm-table-wrap"><table class="rm-table stock-product-table"><thead><tr><th>Ürün Kodu</th><th>Ürün</th><th>Ambalaj</th><th>Üretilen Miktar</th><th>Sevk Edilen Miktar</th><th>Fire</th><th>İade</th><th>Kalan Stok</th></tr></thead><tbody>
            <?php foreach($stokUrunOzet as $s): $kalan=(float)$s['uretilen']+(float)$s['iade']-(float)$s['sevk']-(float)$s['fire']; ?>
                <tr class="stock-product-row" data-name="<?php echo rm_e(rm_lower(($s['urun_kodu'] ?? '').' '.$s['urun_adi'].' '.$s['ambalaj_tipi'])); ?>" data-product="<?php echo rm_e(rm_lower($s['urun_adi'])); ?>" data-tip="<?php echo $kalan > 0 ? 'positive' : 'zero'; ?>">
                    <td><span class="code-pill"><?php echo rm_e($s['urun_kodu'] ?: ('UR-'.$s['id'])); ?></span></td>
                    <td><strong><?php echo rm_e($s['urun_adi']); ?></strong><br><span class="bom-code">Koli içi: <?php echo number_format((float)$s['koli_ici_adet'],0,',','.'); ?> adet</span></td>
                    <td><?php echo rm_e($s['ambalaj_tipi'] ?: '-'); ?></td>
                    <td class="num"><strong class="stock-amount plus"><?php echo number_format((float)$s['uretilen'],0,',','.'); ?> koli</strong></td>
                    <td class="num"><strong class="stock-amount minus"><?php echo number_format((float)$s['sevk'],0,',','.'); ?> koli</strong></td>
                    <td class="num"><strong class="red"><?php echo number_format((float)$s['fire'],0,',','.'); ?> koli</strong></td>
                    <td class="num"><strong class="green"><?php echo number_format((float)$s['iade'],0,',','.'); ?> koli</strong></td>
                    <td class="num"><strong class="<?php echo $kalan > 0 ? 'stock-amount plus' : 'stock-amount minus'; ?>"><?php echo number_format($kalan,0,',','.'); ?> koli</strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$stokUrunOzet): ?><tr><td colspan="8"><div class="stock-empty"><strong>Henüz ürün yok.</strong>Ürün tanımları eklendiğinde stok tablosu burada oluşur.</div></td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <section class="stock-tools"><input id="stockSearch" placeholder="Ürün adı, ürün kodu veya depo ara"><select id="stockProduct"><option value="">Tüm Su Ürünleri</option><?php foreach($urunler as $u): ?><option value="<?php echo rm_e(rm_lower($u['urun_adi'])); ?>"><?php echo rm_e($u['urun_adi']); ?></option><?php endforeach; ?></select><select id="stockTip"><option value="">Tüm Stoklar</option><option value="positive">Stokta Olanlar</option><option value="zero">Stok Olmayanlar</option></select></section>
        <section class="stock-list">
            <div class="stock-list-head"><div><h3>Hareket Listesi</h3><span>Son <?php echo count($stokHareketleri); ?> kayıt</span></div></div>
            <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Tarih</th><th>İrsaliye</th><th>Su Ürünü</th><th>Hareket</th><th>Çıkış / Varış</th><th>Miktar</th><th>Açıklama</th><th>İşlem</th></tr></thead><tbody>
            <?php foreach($stokHareketleri as $h): $sign=in_array($h['hareket_tipi'],['uretilen','iade_giris'],true) ? '+' : '-'; $tc=$tipler[$h['hareket_tipi']] ?? [$h['hareket_tipi'],'neutral','']; ?>
                <tr class="stock-row" data-name="<?php echo rm_e(rm_lower($h['urun_adi'].' '.$h['belge_no'].' '.$h['cikis_depo'].' '.$h['varis_depo'])); ?>" data-product="<?php echo rm_e(rm_lower($h['urun_adi'])); ?>" data-tip="<?php echo rm_e($h['hareket_tipi']); ?>">
                    <td><?php echo rm_e($h['tarih']); ?></td>
                    <td><?php echo rm_e($h['belge_no'] ?: '-'); ?></td>
                    <td><strong><?php echo rm_e($h['urun_adi']); ?></strong><br><span class="bom-code">Koli</span></td>
                    <td><span class="tip-badge <?php echo rm_e($tc[1]); ?>"><?php echo rm_e($tc[0]); ?></span></td>
                    <td><div class="stock-route"><b>Çıkış:</b> <?php echo rm_e($h['cikis_depo'] ?: '-'); ?><br><b>Varış:</b> <?php echo rm_e($h['varis_depo'] ?: '-'); ?></div></td>
                    <td class="num"><strong class="stock-amount <?php echo $sign==='+'?'plus':'minus'; ?>"><?php echo $sign.number_format((float)$h['miktar'],0,',','.'); ?> koli</strong></td>
                    <td><?php echo rm_e($h['aciklama'] ?: '-'); ?></td>
                    <td><form method="POST" onsubmit="return confirm('Bu stok hareketi silinsin mi?')"><input type="hidden" name="action" value="stok_hareket_sil"><input type="hidden" name="hareket_id" value="<?php echo (int)$h['id']; ?>"><button class="delete-btn">Sil</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$stokHareketleri): ?><tr><td colspan="8"><div class="stock-empty"><strong>Henüz stok hareketi yok.</strong>İlk hareketi üstteki formdan ekleyebilirsiniz.</div></td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </section>
    <script>
    function setStockType(v){ document.getElementById('stockType').value=v; }
    function filterStock(){ var q=(document.getElementById('stockSearch').value||'').toLocaleLowerCase('tr-TR'), p=document.getElementById('stockProduct').value, t=document.getElementById('stockTip').value; document.querySelectorAll('.stock-product-row,.stock-row').forEach(function(r){ var ok=(!q||r.dataset.name.indexOf(q)>-1)&&(!p||r.dataset.product===p)&&(!t||r.dataset.tip===t); r.style.display=ok ? '' : 'none'; }); }
    ['stockSearch','stockProduct','stockTip'].forEach(function(id){ document.getElementById(id).addEventListener('input',filterStock); document.getElementById(id).addEventListener('change',filterStock); });
    </script>
    <?php endif; ?>

    <?php if($tab==='fiyatlar'): ?>
    <section class="rm-panel"><h3>Hammadde Fiyatları</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Dönem</th><th>Kategori</th><th>Hammadde</th><th>Fiyat</th><th>Döviz Adet</th><th>TL Birim</th><th>Cam 0,33</th><th>Cam 0,75</th></tr></thead><tbody><?php foreach($fiyatlar as $f): ?><tr><td><?php echo rm_e($f['fiyat_tarihi']); ?></td><td><?php echo rm_e($f['kategori']); ?></td><td><span class="rm-material"><?php echo rm_e($f['urun_adi']); ?></span></td><td><?php echo rm_e($f['ton_fiyati']); ?></td><td><?php echo rm_e($f['doviz_adet']); ?></td><td class="num"><?php echo rm_money($f['tl_adet']); ?></td><td class="num"><?php echo $f['cam_sise_033']===null ? '-' : rm_money($f['cam_sise_033']); ?></td><td class="num"><?php echo $f['cam_sise_075']===null ? '-' : rm_money($f['cam_sise_075']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>

    <?php if($tab==='receteler'): ?>
    <style>
    .bom-matrix{min-width:1080px;table-layout:fixed}
    .bom-matrix th,.bom-matrix td{font-size:10px;padding:6px 5px}
    .bom-matrix input,.bom-matrix select{min-width:0;width:100%;height:32px!important;padding:5px!important;font-size:10px!important}
    .bom-matrix td:first-child select{min-width:0}
    .bom-tl,.bom-price-view,.bom-total,.bom-fire-total{font-weight:950;color:#0f172a;white-space:nowrap;text-align:right}
    .bom-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:14px 22px;background:#f8fafc;border-top:1px solid #e5e7eb}
    .bom-summary div{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px}
    .bom-summary span{display:block;color:#64748b;font-size:11px;font-weight:900}
    .bom-summary strong{display:block;margin-top:4px;color:#0f172a;font-size:18px}
    .recipe-popup{display:none;position:fixed;inset:0;z-index:80;background:rgba(15,23,42,.42);align-items:stretch;justify-content:flex-end;overflow:hidden}
    .recipe-popup.show{display:flex}
    .recipe-popup .bom-editor{width:min(1180px,calc(100vw - 285px));height:92vh;max-height:92vh;margin:22px 22px 22px 0;border-radius:18px;overflow:auto;box-shadow:-24px 0 55px rgba(15,23,42,.24)}
    .recipe-popup.dragging .bom-editor{user-select:none;cursor:grabbing}
    .recipe-popup-close{border:0;background:#0f172a;color:#fff;border-radius:10px;padding:10px 13px;font-weight:950;cursor:pointer}
    .recipe-inline .recipe-popup-close{display:none}
    .recipe-popup .rm-table-wrap{padding:12px;overflow:auto}
    .recipe-popup .bom-head{padding:16px;position:sticky;top:0;z-index:3;cursor:grab}
    .recipe-popup .bom-summary{position:sticky;bottom:0;z-index:2}
    .bom-row-actions{display:flex;gap:6px;align-items:center;justify-content:center}
    .bom-select-col{width:42px!important;text-align:center!important}
    .bom-select-col input{width:16px!important;height:16px!important;min-width:16px!important;padding:0!important;accent-color:#2563eb}
    .detail-btn{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:8px;padding:6px 8px;font-size:10px;font-weight:950;cursor:pointer}
    .bom-bulk-delete{border:1px solid #fecaca;background:#fff1f2;color:#dc2626;border-radius:10px;padding:10px 13px;font-weight:950;cursor:pointer}
    .bom-bulk-delete.dark{background:#dc2626;color:#fff}
    .bom-detail-modal{display:none;position:fixed;inset:0;z-index:120;background:rgba(15,23,42,.42);align-items:center;justify-content:center;padding:16px}
    .bom-detail-modal.show{display:flex}
    .bom-detail-box{width:min(560px,94vw);background:#fff;border-radius:16px;border:1px solid #dbe3ee;box-shadow:0 24px 70px rgba(15,23,42,.28);overflow:hidden}
    .bom-detail-head{display:flex;justify-content:space-between;align-items:center;background:#0f172a;color:#fff;padding:14px 16px}
    .bom-detail-head h3{margin:0;font-size:16px}.bom-detail-head button{border:0;background:#334155;color:#fff;border-radius:8px;padding:7px 10px;cursor:pointer}
    .bom-detail-body{padding:16px;font-size:13px;line-height:1.55;color:#334155}.bom-detail-body b{color:#0f172a}.bom-detail-body .result{margin-top:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;font-weight:900;color:#0f172a}
    .bom-detail-table{width:100%;border-collapse:collapse;font-size:12px}.bom-detail-table th{background:#eef2f7;color:#0f172a;text-align:left;padding:9px}.bom-detail-table td{border-bottom:1px solid #e5e7eb;padding:9px;vertical-align:top}.bom-detail-table td:last-child{font-weight:900;color:#0f172a;white-space:nowrap}
    .recipe-mode .bom-layout{display:block}
    .recipe-mode .bom-layout>aside{display:none!important}
    .recipe-page-head{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:14px;padding:18px 20px;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;border-radius:20px;box-shadow:0 18px 38px rgba(15,23,42,.16)}
    .recipe-page-head h2{margin:4px 0 0;font-size:24px}.recipe-page-head p{margin:5px 0 0;color:#dbeafe;font-size:12px}.recipe-page-head small{color:#93c5fd;font-weight:950;letter-spacing:.08em;text-transform:uppercase}.recipe-page-stats{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.recipe-page-stat{min-width:105px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.1);border-radius:14px;padding:10px 12px}.recipe-page-stat span{display:block;color:#bfdbfe;font-size:10px;font-weight:900;text-transform:uppercase}.recipe-page-stat strong{display:block;margin-top:4px;font-size:16px}
    .recipe-product-strip{display:flex;gap:10px;overflow-x:auto;padding:14px;margin-bottom:14px;background:#fff;border:1px solid #dbe4ef;border-radius:18px;box-shadow:0 14px 30px rgba(15,23,42,.06)}
    .recipe-product-btn{min-width:160px;max-width:220px;border:1px solid #dbe3ee;border-radius:14px;background:#f8fafc;color:#0f172a;text-decoration:none;padding:12px 13px;transition:.15s ease}
    .recipe-product-btn:hover{border-color:#93c5fd;transform:translateY(-1px);box-shadow:0 10px 22px rgba(37,99,235,.08)}
    .recipe-product-btn.active{background:#0f172a;border-color:#0f172a;color:#fff;box-shadow:0 16px 28px rgba(15,23,42,.2)}
    .recipe-product-btn strong{display:block;font-size:12px;line-height:1.25;white-space:normal}
    .recipe-product-btn .bom-code{font-size:9px}
    .recipe-product-btn .bom-ok{float:none;display:inline-block;margin-top:6px}
    .template-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:950;text-decoration:none;cursor:pointer;box-shadow:0 8px 18px rgba(37,99,235,.12)}
    .template-btn.dark{background:#111827;border-color:#111827;color:#fff;box-shadow:0 10px 20px rgba(15,23,42,.22)}
    .excel-upload{display:inline-flex!important;align-items:center;justify-content:center;min-width:auto!important;border:1px solid #dbeafe;background:#fff;color:#0f172a!important;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:950;cursor:pointer;box-shadow:0 8px 18px rgba(37,99,235,.10)}
    .excel-upload input{display:none}
    .excel-format-box{margin:12px 16px 0;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:10px 14px;box-shadow:0 8px 20px rgba(15,23,42,.035)}
    .excel-format-box summary{cursor:pointer;color:#0f172a;font-size:13px;font-weight:950;list-style:none}.excel-format-box summary::-webkit-details-marker{display:none}
    .excel-format-box h4{margin:0 0 8px;color:#0f172a;font-size:13px}
    .excel-format-table{width:100%;border-collapse:collapse;font-size:11px}
    .excel-format-table th{background:#0f172a;color:#fff;text-align:left;padding:8px}
    .excel-format-table td{border:1px solid #e2e8f0;padding:8px;background:#fff}
    .pet033-cost-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
    .pet033-cost-card{border:1px solid #e2e8f0;border-radius:18px;padding:15px 16px;background:#fff;box-shadow:0 10px 22px rgba(15,23,42,.035);transition:.15s ease}
    .pet033-cost-card:hover{box-shadow:0 14px 26px rgba(15,23,42,.07);transform:translateY(-1px)}
    .pet033-cost-card span{display:block;color:#64748b;font-size:11px;font-weight:950;text-transform:uppercase}
    .pet033-cost-card strong{display:block;margin-top:8px;color:#0f172a;font-size:24px;letter-spacing:.02em}
    .pet033-cost-card small{display:block;margin-top:5px;color:#64748b;font-size:11px}
    .pet033-cost-card.fire{background:#fff8e1;border-color:#f59e0b}.pet033-cost-card.fire strong{color:#c2410c}
    .pet033-cost-card.total{background:linear-gradient(135deg,#0f766e,#059669);border-color:#0f766e;box-shadow:0 16px 30px rgba(5,150,105,.22)}
    .pet033-cost-card.total span,.pet033-cost-card.total small{color:#ecfdf5}.pet033-cost-card.total strong{color:#fff}
    .recipe-cost-band{display:grid;grid-template-columns:1.15fr repeat(4,.85fr) 1.15fr;gap:10px;padding:14px 16px;background:#fff;border-bottom:1px solid #e5e7eb}
    .recipe-cost-card{min-width:0;border:1px solid #e2e8f0;border-radius:16px;background:#fff;padding:12px 13px;box-shadow:0 8px 18px rgba(15,23,42,.035)}
    .recipe-cost-card span{display:block;color:#64748b;font-size:10px;font-weight:950;text-transform:uppercase;letter-spacing:.03em}
    .recipe-cost-card strong{display:block;margin-top:6px;color:#0f172a;font-size:17px;font-weight:950;font-variant-numeric:tabular-nums}
    .recipe-cost-card.total{background:linear-gradient(135deg,#0f172a,#1e1b4b);border-color:#0f172a;box-shadow:0 14px 26px rgba(15,23,42,.18)}.recipe-cost-card.total span{color:#fde68a}.recipe-cost-card.total strong{color:#fff}
    .recipe-cost-card small{display:block;margin-top:5px;color:#64748b;font-size:10px;line-height:1.35}
    .recipe-mode .bom-editor{border:1px solid #dbe4ef;border-radius:20px;box-shadow:0 18px 42px rgba(15,23,42,.08)}
    .recipe-mode .recipe-bom-head{background:linear-gradient(135deg,#111827 0%,#172554 52%,#1d4ed8 100%);border-bottom:1px solid #0f172a;color:#fff}
    .recipe-mode .recipe-bom-head .bom-title{color:#fff}.recipe-mode .recipe-bom-head .muted{color:#dbeafe}.recipe-mode .recipe-bom-head .rm-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.24);color:#fef3c7}
    .recipe-mode .recipe-bom-head .recipe-actions label:not(.excel-upload){color:#f8fafc}.recipe-mode .recipe-bom-head .recipe-actions input{background:#fff;color:#0f172a;border-color:#cbd5e1}
    .recipe-mode .recipe-bom-head .template-btn{background:#eaf2ff;border-color:#bfdbfe;color:#0f3f9e}
    .recipe-mode .recipe-bom-head .template-btn.dark{background:#0f172a;border-color:#0f172a;color:#fff}
    .recipe-mode .recipe-bom-head .primary-add{background:#2563eb;color:#fff;box-shadow:0 10px 22px rgba(37,99,235,.28)}
    .recipe-mode .rm-table-wrap{background:#fff}.recipe-mode .bom-matrix th{background:#eef4fb;color:#172033}.recipe-mode .bom-matrix tr:hover td{background:#f8fbff}
    .recipe-mode .rm-table-wrap{border-top:1px solid #d7e0eb}
    .bom-matrix{border-collapse:collapse!important;background:#fff}
    .bom-matrix th,.bom-matrix td{border-right:1px solid #d7e0eb;border-bottom:1px solid #d7e0eb}
    .bom-matrix th:last-child,.bom-matrix td:last-child{border-right:0}
    .bom-matrix tbody tr:nth-child(even) td{background:#fbfdff}
    .bom-matrix input,.bom-matrix select{background:#fff;border:1px solid #b8c7d9!important;border-radius:8px!important;font-variant-numeric:tabular-nums}
    .bom-matrix th:nth-child(2),.bom-matrix td:nth-child(2){width:230px;min-width:230px}
    .bom-matrix td:nth-child(2) select{text-align:left}
    .bom-matrix td:nth-child(3) input,.bom-matrix td:nth-child(5) input,.bom-matrix td:nth-child(7) input,.bom-matrix td:nth-child(9) input,.bom-matrix td:nth-child(11) input,.bom-matrix td:nth-child(13) input{text-align:right}
    .bom-matrix td:nth-child(6),.bom-matrix td:nth-child(8),.bom-matrix td:nth-child(12),.bom-matrix td:nth-child(14){text-align:right}
    .bom-matrix .bom-tl,.bom-matrix .bom-price-view,.bom-matrix .bom-total,.bom-matrix .bom-fire-total{font-variant-numeric:tabular-nums}
    .bom-matrix td:nth-child(6),.bom-matrix td:nth-child(8),.bom-matrix td:nth-child(12){background:#f8fafc!important}
    .bom-matrix td:nth-child(14){background:#ecfdf5!important}
    .recipe-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 16px 0}
    .recipe-flow-step{border:1px solid #e2e8f0;background:#fff;border-radius:16px;padding:12px 14px;box-shadow:0 8px 20px rgba(15,23,42,.035)}
    .recipe-flow-step b{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:#f59e0b;color:#0f172a;font-size:11px;margin-right:7px}
    .recipe-flow-step strong{color:#0f172a;font-size:12px}.recipe-flow-step span{display:block;margin-top:6px;color:#64748b;font-size:11px;line-height:1.35}
    .bom-matrix th{vertical-align:top;line-height:1.2}.bom-matrix th span{display:block;font-size:10px;font-weight:950}.bom-matrix th small{display:block;margin-top:4px;color:#64748b;font-size:9px;font-weight:800;line-height:1.25}
    .bom-fire-total{color:#047857}
    .bom-matrix input:focus,.bom-matrix select:focus{outline:2px solid #bfdbfe;border-color:#2563eb}
    .recipe-gider-panel{margin:14px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
    .recipe-gider-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.recipe-gider-head h3{margin:0;color:#0f172a;font-size:16px}.recipe-gider-head p{margin:3px 0 0;color:#64748b;font-size:11px}
    .recipe-gider-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.recipe-gider-field label{display:block;margin-bottom:5px;color:#475569;font-size:10px;font-weight:950;text-transform:uppercase}.recipe-gider-field input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:11px 12px;font-weight:900;background:#f8fafc}.recipe-gider-save{align-self:end;border:0;border-radius:12px;background:#f59e0b;color:#0f172a;padding:12px 14px;font-weight:950;cursor:pointer;box-shadow:0 10px 22px rgba(245,158,11,.22)}
    .recipe-production-total{display:flex;justify-content:space-between;align-items:center;margin-top:12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:10px 12px;color:#0f172a;font-weight:950}
    .recipe-production-total span{color:#1d4ed8;font-size:16px}
    .recipe-production-strip{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:8px;margin-top:12px}
    .recipe-production-strip div{border:1px solid #e2e8f0;background:#f8fafc;border-radius:12px;padding:10px;text-align:center}
    .recipe-production-strip strong{display:block;color:#0f172a;font-size:12px}.recipe-production-strip em{display:block;margin-top:5px;color:#0f172a;font-style:normal;font-size:13px;font-weight:950}.recipe-production-strip span{display:block;margin-top:3px;color:#2563eb;font-weight:950;font-size:12px}
    @media(max-width:1100px){.pet033-cost-summary,.recipe-cost-band,.recipe-gider-grid,.recipe-flow{grid-template-columns:1fr 1fr}}
    @media(max-width:1100px){.recipe-popup .bom-editor{width:96vw;margin:12px auto;border-radius:16px}}
    @media(max-width:900px){.bom-summary,.pet033-cost-summary{grid-template-columns:1fr}.recipe-actions label{min-width:100%}}
    </style>
    <section class="rm-panel">
        <div class="recipe-page-head">
            <div>
                <small>Reçete ve Ürün Ağacı</small>
                <h2><?php echo rm_e($selectedProductName ?: 'Ürün seçin'); ?></h2>
                <p>Malzeme, gider ve fire etkisini tek ekranda takip edin.</p>
            </div>
            <div class="recipe-page-stats">
                <div class="recipe-page-stat"><span>Dönem</span><strong><?php echo rm_e($currentDonem['donem_adi']); ?></strong></div>
                <div class="recipe-page-stat"><span>Ürün</span><strong><?php echo count($urunler); ?></strong></div>
                <div class="recipe-page-stat"><span>Koli İçi</span><strong><?php echo number_format((float)$selectedKoliIci,0,',','.'); ?></strong></div>
            </div>
        </div>
        <div class="recipe-product-strip">
            <?php foreach($urunler as $u): ?>
            <a class="recipe-product-btn <?php echo (int)$u['id']===$selectedId ? 'active' : ''; ?>" href="?tab=receteler&donem=<?php echo rm_e($donem); ?>&urun_id=<?php echo (int)$u['id']; ?>">
                <?php $ok = ($bomDurum[(int)$u['id']] ?? 0) > 0; ?>
                <strong><?php echo rm_e($u['urun_adi']); ?></strong>
                <span class="bom-code"><?php echo rm_e(rm_urun_kodu_satir($u)); ?></span>
                <span class="bom-ok <?php echo $ok ? '' : 'missing'; ?>"><?php echo $ok ? '&#10003; Hazır' : '&#9888; Eksik'; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="bom-layout">
            <aside>
                <h3>ÜRÜN SEÇİMİ (<?php echo count($urunler); ?>)</h3>
                <div class="bom-products">
                    <?php foreach($urunler as $u): ?>
                    <a class="bom-item <?php echo (int)$u['id']===$selectedId ? 'active' : ''; ?>" href="?tab=receteler&donem=<?php echo rm_e($donem); ?>&urun_id=<?php echo (int)$u['id']; ?>">
                        <?php $ok = ($bomDurum[(int)$u['id']] ?? 0) > 0; ?><span class="bom-ok <?php echo $ok ? '' : 'missing'; ?>"><?php echo $ok ? '&#10003; Hazır' : '&#9888; Eksik'; ?></span><strong><?php echo rm_e($u['urun_adi']); ?></strong>
                        <span class="bom-code"><?php echo rm_e(rm_urun_kodu_satir($u)); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </aside>
            <?php $isPet033Recipe = $selectedProductCode === 'SU-PET-033'; ?>
            <div class="recipe-inline" id="recipePopup">
            <form method="POST" class="bom-editor" id="bomForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bom_kaydet" id="bomAction">
                <input type="hidden" name="urun_id" value="<?php echo (int)$selectedId; ?>">
                <input type="hidden" name="versiyon" value="<?php echo rm_e($donem); ?>">
                <div class="bom-head recipe-bom-head">
                    <div>
                        <div class="recipe-badges">
                            <span class="rm-pill"><?php echo rm_e($isPet033Recipe ? 'UR-033-STD' : $selectedProductCode); ?></span>
                            <span class="rm-pill"><?php echo rm_e($currentDonem['donem_adi']); ?></span>
                            <span class="rm-pill">Koli İçi Adet: <?php echo number_format((float)$selectedKoliIci,0,',','.'); ?> Şişe</span>
                        </div>
                        <div class="bom-title"><?php echo rm_e($isPet033Recipe ? '0,33 lt Standart - Hammadde ve Reçete Hücre Matrisi' : (($selectedProductName ?: 'Ürün seçin') . ' Reçetesi')); ?></div>
                        <p class="muted recipe-note">Birim miktarı ve birim fiyat girildiğinde satır toplamı otomatik hesaplanır.</p>
                    </div>
                    <div class="bom-actions recipe-actions">
                        <a class="template-btn" href="?tab=receteler&donem=<?php echo rm_e($donem); ?>&recete_sablon=1">Örnek Excel</a>
                        <label class="excel-upload">Excel Yükle<input type="file" name="recete_excel" accept=".xls,.xlsx,.xlsm,.csv,.txt"></label>
                        <button type="submit" class="template-btn dark" onclick="document.getElementById('bomAction').value='bom_excel_yukle'">Exceli Aktar</button>
                        <label>Açıklama<br><input name="aciklama" value="<?php echo rm_e($activeBom['aciklama'] ?? ''); ?>"></label>
                        <button type="button" class="recipe-popup-close" onclick="document.getElementById('recipePopup').classList.remove('show')">Kapat</button>
                        <button type="submit" class="primary-add" onclick="document.getElementById('bomAction').value='bom_kaydet'">Reçeteyi Kaydet</button>
                    </div>
                </div>
                <details class="excel-format-box">
                    <summary>Excel Yükleme Örnek Formatı</summary>
                    <div class="rm-table-wrap"><table class="excel-format-table">
                        <thead><tr><th>Hammadde / Malzeme</th><th>Alış Fiyatı</th><th>Para Cinsi</th><th>Döviz Kuru</th><th>Bölen</th><th>Kullanım Miktarı</th><th>Birim</th><th>Kolideki Adet</th><th>Fire %</th></tr></thead>
                        <tbody>
                            <tr><td>Preform / Cam Şişe</td><td>1,38</td><td>USD</td><td>47,7168</td><td>1000</td><td>9,2</td><td>gr/adet</td><td>24</td><td>3</td></tr>
                            <tr><td>Kapak</td><td>205</td><td>TL</td><td>1</td><td>1000</td><td>1</td><td>adet/adet</td><td>24</td><td>1,5</td></tr>
                            <tr><td>Etiket</td><td>170</td><td>TL</td><td>1</td><td>1000</td><td>1</td><td>adet/adet</td><td>24</td><td>2</td></tr>
                        </tbody>
                    </table></div>
                </details>
                <div class="pet033-cost-summary">
                    <div class="pet033-cost-card"><span>Şişe Başı Hammadde</span><strong id="petBottleCost">0,00 TL</strong><small><?php echo number_format((float)$selectedKoliIci,0,',','.'); ?>'e bölünerek şişe payı</small></div>
                    <div class="pet033-cost-card"><span>Koli Maliyeti (Net)</span><strong id="petKoliNet">0,00 TL</strong><small>x <?php echo number_format((float)$selectedKoliIci,0,',','.'); ?> şişe toplamı</small></div>
                    <div class="pet033-cost-card fire"><span>Fire Payı</span><strong id="petFirePay">0,00 TL</strong><small id="petFireLabel">Zayiat / fire eklemesi</small></div>
                    <div class="pet033-cost-card total"><span>Fireli Hammadde / Koli</span><strong id="petFireTotal">0,00 TL</strong><small>Gerçek birim hammadde maliyeti</small></div>
                </div>
                <?php
                    $stdGv=rm_gider_values($db,$donem);
                    $stdG720=$stdGv['g720']; $stdG730=$stdGv['g730']; $stdG760=$stdGv['g760']; $stdG770=$stdGv['g770'];
                    $stdNakliye=(float)($selectedNd['nakliye_tl_koli'] ?? 0); $stdDekap=(float)($selectedNd['dekap_tl_koli'] ?? 0); $stdGiderToplam=$stdG720+$stdG730+$stdG760+$stdG770;
                    $stdUretim = (float)($currentDonem['toplam_uretim'] ?? 0);
                    if($donem === '2026-06' && $stdUretim <= 0){ $stdUretim = 509895; }
                    $stdG730Total = $donem === '2026-06' ? 4006861.28 : $stdG730 * max($stdUretim, 0);
                    $stdG760Total = $donem === '2026-06' ? 1344152.66 : $stdG760 * max($stdUretim, 0);
                    $stdG770Total = $donem === '2026-06' ? 6366475.10 : $stdG770 * max($stdUretim, 0);
                    $stdG720Total = $donem === '2026-06' ? 4672230.79 : $stdG720 * max($stdUretim, 0);
                    $uretimDagilimMap = ['0,33 lt'=>0,'0,5 lt'=>0,'1 lt'=>0,'1,5 lt'=>0,'5 lt'=>0,'Cam Şişe'=>0,'19 lt'=>0,'200 cc'=>0];
                    $uretimDagilimStmt = $db->prepare("SELECT urun_adi,koli_miktari FROM recete_uretim WHERE donem=?");
                    $uretimDagilimStmt->execute([$donem]);
                    foreach($uretimDagilimStmt->fetchAll() as $ur){
                        $ad = rm_lower((string)$ur['urun_adi']);
                        $qty = (float)$ur['koli_miktari'];
                        if(str_contains($ad,'cam')){ $uretimDagilimMap['Cam Şişe'] += $qty; }
                        elseif(str_contains($ad,'19')){ $uretimDagilimMap['19 lt'] += $qty; }
                        elseif(str_contains($ad,'200')){ $uretimDagilimMap['200 cc'] += $qty; }
                        elseif(str_contains($ad,'1,5')){ $uretimDagilimMap['1,5 lt'] += $qty; }
                        elseif(str_contains($ad,'0,50') || str_contains($ad,'0,5')){ $uretimDagilimMap['0,5 lt'] += $qty; }
                        elseif(str_contains($ad,'0,33')){ $uretimDagilimMap['0,33 lt'] += $qty; }
                        elseif(preg_match('/(^|[^0-9])1\s*l/u', $ad)){ $uretimDagilimMap['1 lt'] += $qty; }
                        elseif(str_contains($ad,'5 l')){ $uretimDagilimMap['5 lt'] += $qty; }
                    }
                    $uretimDagilimToplam = array_sum($uretimDagilimMap);
                    if($uretimDagilimToplam > 0){ $stdUretim = $uretimDagilimToplam; }
                    $uretimDagilim = [];
                    foreach($uretimDagilimMap as $label=>$qty){
                        $rate = $stdUretim > 0 ? ($qty / $stdUretim * 100) : 0;
                        $uretimDagilim[] = [$label, $rate, $qty];
                    }
                ?>
                <div class="recipe-gider-panel">
                    <div class="recipe-gider-head">
                        <div><h3>Reçete Giderleri</h3><p>Toplam giderler üretim miktarına bölünür; koli başı gider reçeteye otomatik yansır.</p></div>
                        <button class="recipe-gider-save" type="submit" onclick="document.getElementById('bomAction').value='recete_gider_kaydet'">Giderleri Kaydet</button>
                    </div>
                    <div class="recipe-gider-grid">
                        <input type="hidden" name="gider_toplam_uretim" value="<?php echo number_format($stdUretim,0,',','.'); ?>">
                        <div class="recipe-gider-field"><label>730 Toplam Gider</label><input name="g730_total" value="<?php echo number_format($stdG730Total,2,',','.'); ?>"></div>
                        <div class="recipe-gider-field"><label>760 Toplam Gider</label><input name="g760_total" value="<?php echo number_format($stdG760Total,2,',','.'); ?>"></div>
                        <div class="recipe-gider-field"><label>770 Toplam Gider</label><input name="g770_total" value="<?php echo number_format($stdG770Total,2,',','.'); ?>"></div>
                        <div class="recipe-gider-field"><label>720 Toplam Gider</label><input name="g720_total" value="<?php echo number_format($stdG720Total,2,',','.'); ?>"></div>
                        <div class="recipe-gider-field"><label>Nakliye</label><input name="nakliye" value="<?php echo rm_input_num($stdNakliye,4); ?>"></div>
                        <div class="recipe-gider-field"><label>DEKAP Gideri</label><input name="dekap" value="<?php echo rm_input_num($stdDekap,4); ?>"></div>
                        <input type="hidden" name="g730" value="<?php echo rm_input_num($stdG730,4); ?>">
                        <input type="hidden" name="g760" value="<?php echo rm_input_num($stdG760,4); ?>">
                        <input type="hidden" name="g770" value="<?php echo rm_input_num($stdG770,4); ?>">
                        <input type="hidden" name="g720" value="<?php echo rm_input_num($stdG720,4); ?>">
                    </div>
                    <div class="recipe-production-total"><strong>Üretim Miktarı Koli Toplamı</strong><span><?php echo number_format($stdUretim,0,',','.'); ?> koli</span></div>
                    <div class="recipe-production-strip">
                        <?php foreach($uretimDagilim as $ud): ?>
                            <div><strong><?php echo rm_e($ud[0]); ?></strong><em><?php echo number_format((float)$ud[2],0,',','.'); ?> koli</em><span><?php echo number_format((float)$ud[1],2,',','.'); ?>%</span></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="recipe-cost-band" data-gider-total="<?php echo rm_e($stdGiderToplam); ?>">
                    <div class="recipe-cost-card"><span>Hammadde Sonucu</span><strong id="recipeHamCost">₺0,0000</strong><small>Reçete satırlarından canlı hesaplanır.</small></div>
                    <div class="recipe-cost-card"><span>730 İşçilik Hariç</span><strong><?php echo rm_money4($stdG730); ?></strong><small>Koli başı gider payı</small></div>
                    <div class="recipe-cost-card"><span>760 Pazarlama</span><strong><?php echo rm_money4($stdG760); ?></strong><small>Koli başı gider payı</small></div>
                    <div class="recipe-cost-card"><span>Genel Yönetim</span><strong><?php echo rm_money4($stdG770); ?></strong><small>Koli başı gider payı</small></div>
                    <div class="recipe-cost-card"><span>İşçilik Gideri</span><strong><?php echo rm_money4($stdG720); ?></strong><small>Koli başı gider payı</small></div>
                    <div class="recipe-cost-card total"><span>Koli Başına Toplam</span><strong id="recipeGrandCost">₺0,0000</strong><small>Hammadde + gider payları</small></div>
                </div>
                <div class="recipe-flow">
                    <div class="recipe-flow-step"><strong><b>1</b> Fiyatı Gir</strong><span>Malzemenin fatura/alış fiyatı ve para cinsi yazılır.</span></div>
                    <div class="recipe-flow-step"><strong><b>2</b> TL'ye Çevir</strong><span>Dövizli fiyat, girilen kurla otomatik TL tutara çevrilir.</span></div>
                    <div class="recipe-flow-step"><strong><b>3</b> Birimleştir</strong><span>Bölen ile kg, ton veya paket fiyatı adet/gr maliyetine iner.</span></div>
                    <div class="recipe-flow-step"><strong><b>4</b> Koliye Yansıt</strong><span>Kullanım, koli adedi ve fire eklenerek koli maliyeti çıkar.</span></div>
                </div>
                <div class="rm-table-wrap"><table class="bom-table bom-matrix"><thead><tr><th class="bom-select-col"><input type="checkbox" id="bomSelectAll" onchange="toggleBomSelectAll(this)" title="Tümünü seç"></th><th><span>Malzeme</span><small>Reçetede kullanılan kalem</small></th><th><span>Alış Fiyatı</span><small>Fatura veya liste fiyatı</small></th><th><span>Para</span><small>TL / USD / EUR</small></th><th><span>Kur</span><small>Döviz ise TL kuru</small></th><th><span>TL Tutar</span><small>Alış fiyatı x kur</small></th><th><span>Bölen</span><small>Kg/ton/paket çevirimi</small></th><th><span>Birim TL</span><small>1 adet veya 1 gr maliyet</small></th><th><span>Kullanım</span><small>Bir ürün için miktar</small></th><th><span>Birim</span><small>gr/adet, adet/adet...</small></th><th><span>Koli Adedi</span><small>Kolideki ürün sayısı</small></th><th><span>Koli Fiyatı</span><small>Fire öncesi toplam</small></th><th><span>Fire %</span><small>Zayiat oranı</small></th><th><span>Fireli Koli</span><small>Nihai hammadde maliyeti</small></th><th><span>İşlem</span><small>Detay / sil</small></th></tr></thead><tbody id="bomRows">
                    <?php foreach($bomKalemleri as $b): ?>
                    <?php $alisFiyati = (float)($b['alis_fiyati'] ?? 0); if($alisFiyati == 0 && (float)($b['birim_fiyat'] ?? 0) > 0){ $alisFiyati = (float)$b['birim_fiyat']; } ?>
                    <tr>
                        <td class="bom-select-col"><input type="checkbox" class="bom-select-row"></td>
                        <td><select name="hammadde_id[]"><?php foreach($hammaddeler as $h): ?><option value="<?php echo (int)$h['id']; ?>" <?php echo (int)$h['id']===(int)$b['hammadde_id'] ? 'selected' : ''; ?>><?php echo rm_e($h['kalem_adi']); ?></option><?php endforeach; ?></select></td>
                        <td><input class="bom-alis" name="alis_fiyati[]" value="<?php echo number_format($alisFiyati,6,',','.'); ?>"></td>
                        <td><select class="bom-currency" name="para_cinsi[]"><?php $pc=$b['para_cinsi'] ?? 'TL'; ?><option <?php echo $pc==='TL'?'selected':''; ?>>TL</option><option <?php echo $pc==='USD'?'selected':''; ?>>USD</option><option <?php echo $pc==='EUR'?'selected':''; ?>>EUR</option></select></td>
                        <td><input class="bom-kur" name="doviz_kuru[]" value="<?php echo number_format(max((float)($b['doviz_kuru'] ?? 1),1),6,',','.'); ?>"></td>
                        <td class="bom-tl">₺0,00</td>
                        <td><input class="bom-bolen" name="bolen[]" value="<?php echo rm_input_num(max((float)($b['bolen'] ?? 1),1)); ?>"></td>
                        <td class="bom-price-view">₺0,00</td>
                        <td><input class="bom-num" name="tuketim_miktari[]" value="<?php echo rm_input_num((float)$b['tuketim_miktari']); ?>"></td>
                        <td><select class="bom-unit" name="tuketim_birimi[]"><option <?php echo $b['tuketim_birimi']==='gr/adet' ? 'selected' : ''; ?>>gr/adet</option><option <?php echo in_array($b['tuketim_birimi'], ['adet/adet','adet/koli'], true) ? 'selected' : ''; ?>>adet/adet</option><option <?php echo $b['tuketim_birimi']==='gr/koli' ? 'selected' : ''; ?>>gr/koli</option><option <?php echo $b['tuketim_birimi']==='kg/koli' ? 'selected' : ''; ?>>kg/koli</option></select></td>
                        <td><input class="bom-koli" name="koli_ici_adet[]" value="<?php echo number_format((float)$b['koli_ici_adet'],2,',','.'); ?>"></td>
                        <td class="bom-total">₺0,00</td>
                        <td><input class="bom-fire" name="fire_orani[]" value="<?php echo number_format((float)$b['fire_orani'],2,',','.'); ?>"></td>
                        <td class="bom-fire-total">₺0,00</td>
                        <td><div class="bom-row-actions"><button type="button" class="detail-btn" onclick="showBomDetail(this)">Detay</button><button type="button" class="trash-btn" title="Sil" onclick="this.closest('tr').remove();refreshBomSelectAll();calcBom();">&times;</button></div></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="bom-summary"><div><span>TL Çevrim Toplamı</span><strong id="bomTlSum">₺0,00</strong></div><div><span>Koli Fiyatı</span><strong id="bomKoliSum">₺0,00</strong></div><div><span>Fireli Koli Fiyatı</span><strong id="bomFireSum">₺0,00</strong></div></div>
                <div class="bom-footer"><button type="button" class="add-material" onclick="addBomRow();">+ Reçeteye Malzeme Kalemi Ekle</button><button type="button" class="bom-bulk-delete" onclick="deleteSelectedBomRows();">Seçilileri Sil</button><button type="button" class="bom-bulk-delete dark" onclick="deleteAllBomRows();">Tümünü Sil</button><button type="submit" class="primary-add" onclick="document.getElementById('bomAction').value='bom_kaydet'">DEĞİŞİKLİKLERİ KAYDET</button><span class="bom-count" id="bomCount">Toplam <?php echo count($bomKalemleri); ?> Hammadde Girdisi</span></div>
            </form>
            </div>
        </div>
    </section>
    <template id="bomTpl"><tr><td class="bom-select-col"><input type="checkbox" class="bom-select-row"></td><td><select name="hammadde_id[]"><?php foreach($hammaddeler as $h): ?><option value="<?php echo (int)$h['id']; ?>"><?php echo rm_e($h['kalem_adi']); ?></option><?php endforeach; ?></select></td><td><input class="bom-alis" name="alis_fiyati[]" value="0"></td><td><select class="bom-currency" name="para_cinsi[]"><option>TL</option><option>USD</option><option>EUR</option></select></td><td><input class="bom-kur" name="doviz_kuru[]" value="1"></td><td class="bom-tl">₺0,00</td><td><input class="bom-bolen" name="bolen[]" value="1"></td><td class="bom-price-view">₺0,00</td><td><input class="bom-num" name="tuketim_miktari[]" value="0"></td><td><select class="bom-unit" name="tuketim_birimi[]"><option>gr/adet</option><option>adet/adet</option><option>gr/koli</option><option>kg/koli</option></select></td><td><input class="bom-koli" name="koli_ici_adet[]" value="1"></td><td class="bom-total">₺0,00</td><td><input class="bom-fire" name="fire_orani[]" value="0"></td><td class="bom-fire-total">₺0,00</td><td><div class="bom-row-actions"><button type="button" class="detail-btn" onclick="showBomDetail(this)">Detay</button><button type="button" class="trash-btn" title="Sil" onclick="this.closest('tr').remove();refreshBomSelectAll();calcBom();">&times;</button></div></td></tr></template>
    <div class="bom-detail-modal" id="bomDetailModal"><div class="bom-detail-box"><div class="bom-detail-head"><h3>Hesaplama Detayı</h3><button type="button" onclick="closeBomDetail()">Kapat</button></div><div class="bom-detail-body" id="bomDetailBody"></div></div></div>
    <script>
    function trNum(v){
        v=(v||'').toString().trim().replace(/[^\d,.\-]/g,'');
        if(v.indexOf(',')>-1){ v=v.replace(/\./g,'').replace(',','.'); }
        else if(/^-?\d{1,3}(\.\d{3})+$/.test(v)){ v=v.replace(/\./g,''); }
        return parseFloat(v)||0;
    }
    function fmt(v,d){ return '₺' + v.toLocaleString('tr-TR',{minimumFractionDigits:d||2,maximumFractionDigits:d||2}); }
    function fmtNum(v,d){ return (v || 0).toLocaleString('tr-TR',{minimumFractionDigits:d,maximumFractionDigits:d}); }
    function formatBomInputs(scope){
        var root = scope || document;
        var list = [];
        if(root.matches && root.matches('.bom-alis,.bom-kur,.bom-bolen,.bom-num,.bom-koli,.bom-fire')){ list.push(root); }
        root.querySelectorAll && root.querySelectorAll('.bom-alis,.bom-kur,.bom-bolen,.bom-num,.bom-koli,.bom-fire').forEach(function(el){ list.push(el); });
        list.forEach(function(el){
            var d = el.classList.contains('bom-bolen') ? 0 : 4;
            el.value = fmtNum(trNum(el.value), d);
        });
    }
    function calcBom(){
        var tlSum=0,koliSum=0,fireSum=0, fireWeighted=0;
        document.querySelectorAll('#bomRows tr').forEach(function(row){
            var alis = trNum((row.querySelector('.bom-alis') || {}).value);
            var kur = trNum((row.querySelector('.bom-kur') || {}).value) || 1;
            var bolen = trNum((row.querySelector('.bom-bolen') || {}).value) || 1;
            var miktar = trNum((row.querySelector('.bom-num') || {}).value);
            var koli = trNum((row.querySelector('.bom-koli') || {}).value);
            var fire = trNum((row.querySelector('.bom-fire') || {}).value);
            var tl = alis * kur;
            var birim = tl / bolen;
            var toplam = birim * miktar * koli;
            var fireli = toplam * (1 + fire / 100);
            tlSum += tl; koliSum += toplam; fireSum += fireli; fireWeighted += toplam * fire;
            if(row.querySelector('.bom-tl')){ row.querySelector('.bom-tl').textContent = fmt(tl,4); }
            if(row.querySelector('.bom-price-view')){ row.querySelector('.bom-price-view').textContent = fmt(birim,4); }
            if(row.querySelector('.bom-total')){ row.querySelector('.bom-total').textContent = fmt(toplam,4); }
            if(row.querySelector('.bom-fire-total')){ row.querySelector('.bom-fire-total').textContent = fmt(fireli,4); }
        });
        if(document.getElementById('bomTlSum')){ document.getElementById('bomTlSum').textContent = fmt(tlSum,4); }
        if(document.getElementById('bomKoliSum')){ document.getElementById('bomKoliSum').textContent = fmt(koliSum,4); }
        if(document.getElementById('bomFireSum')){ document.getElementById('bomFireSum').textContent = fmt(fireSum,4); }
        var bottleCount = <?php echo max((float)$selectedKoliIci, 1); ?>;
        var firePay = fireSum - koliSum;
        var fireRate = koliSum > 0 ? fireWeighted / koliSum : 0;
        if(document.getElementById('petBottleCost')){ document.getElementById('petBottleCost').textContent = (fireSum / bottleCount).toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' TL'; }
        if(document.getElementById('petKoliNet')){ document.getElementById('petKoliNet').textContent = koliSum.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' TL'; }
        if(document.getElementById('petFirePay')){ document.getElementById('petFirePay').textContent = '+' + firePay.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' TL'; }
        if(document.getElementById('petFireLabel')){ document.getElementById('petFireLabel').textContent = 'Fire payı (%' + fireRate.toLocaleString('tr-TR',{minimumFractionDigits:1,maximumFractionDigits:2}) + ')'; }
        if(document.getElementById('petFireTotal')){ document.getElementById('petFireTotal').textContent = fireSum.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' TL'; }
        var giderUretim = trNum((document.querySelector('[name="gider_toplam_uretim"]') || {}).value);
        var giderTotals = ['g730_total','g760_total','g770_total','g720_total'].reduce(function(sum,name){
            var el = document.querySelector('[name="'+name+'"]');
            return sum + (el ? trNum(el.value) : 0);
        }, 0);
        var giderTotal = giderUretim > 0 && giderTotals > 0 ? (giderTotals / giderUretim) : ['g730','g760','g770','g720'].reduce(function(sum,name){
            var el = document.querySelector('[name="'+name+'"]');
            return sum + (el ? trNum(el.value) : 0);
        }, 0);
        if(document.getElementById('recipeHamCost')){ document.getElementById('recipeHamCost').textContent = fmt(fireSum,4); }
        if(document.getElementById('recipeGrandCost')){ document.getElementById('recipeGrandCost').textContent = fmt(fireSum + giderTotal,4); }
        updateBomCount();
    }
    function refreshBomSelectAll(){
        var all = Array.prototype.slice.call(document.querySelectorAll('#bomRows .bom-select-row'));
        var head = document.getElementById('bomSelectAll');
        if(!head){ return; }
        head.checked = all.length > 0 && all.every(function(cb){ return cb.checked; });
        head.indeterminate = all.some(function(cb){ return cb.checked; }) && !head.checked;
    }
    function toggleBomSelectAll(cb){
        document.querySelectorAll('#bomRows .bom-select-row').forEach(function(rowCb){ rowCb.checked = cb.checked; });
        refreshBomSelectAll();
    }
    function deleteSelectedBomRows(){
        var selected = document.querySelectorAll('#bomRows .bom-select-row:checked');
        if(selected.length === 0){ alert('Silmek için en az bir satır seçin.'); return; }
        if(!confirm(selected.length + ' satır silinip reçete kaydedilsin mi?')){ return; }
        selected.forEach(function(cb){ cb.closest('tr').remove(); });
        refreshBomSelectAll();
        calcBom();
        submitBomAfterDelete();
    }
    function deleteAllBomRows(){
        var rows = document.querySelectorAll('#bomRows tr');
        if(rows.length === 0){ return; }
        if(!confirm('Tüm reçete satırları silinip reçete kaydedilsin mi?')){ return; }
        rows.forEach(function(row){ row.remove(); });
        refreshBomSelectAll();
        calcBom();
        submitBomAfterDelete();
    }
    function submitBomAfterDelete(){
        var action = document.getElementById('bomAction');
        var form = document.getElementById('bomForm');
        if(action){ action.value = 'bom_kaydet'; }
        if(form){ form.requestSubmit ? form.requestSubmit() : form.submit(); }
    }
    function updateBomCount(){
        var count = document.querySelectorAll('#bomRows tr').length;
        if(document.getElementById('bomCount')){ document.getElementById('bomCount').textContent = 'Toplam ' + count + ' Hammadde Girdisi'; }
    }
    function addBomRow(){ document.getElementById('bomRows').insertAdjacentHTML('beforeend', document.getElementById('bomTpl').innerHTML); formatBomInputs(document.getElementById('bomRows').lastElementChild); refreshBomSelectAll(); calcBom(); }
    function showBomDetail(btn){
        calcBom();
        var row = btn.closest('tr');
        var material = row.querySelector('select[name="hammadde_id[]"] option:checked').textContent.trim();
        var alis = trNum(row.querySelector('.bom-alis').value);
        var kur = trNum(row.querySelector('.bom-kur').value) || 1;
        var bolen = trNum(row.querySelector('.bom-bolen').value) || 1;
        var miktar = trNum(row.querySelector('.bom-num').value);
        var koli = trNum(row.querySelector('.bom-koli').value);
        var fire = trNum(row.querySelector('.bom-fire').value);
        var para = row.querySelector('.bom-currency').value;
        var tl = alis * kur;
        var birim = tl / bolen;
        var koliFiyat = birim * miktar * koli;
        var fireli = koliFiyat * (1 + fire / 100);
        var unitName = (row.querySelector('.bom-unit') || {}).value || 'birim';
        var baseUnit = unitName.indexOf('gr') === 0 ? 'gr' : 'adet';
        var buyUnit = bolen >= 1000 ? 'kg' : 'adet';
        document.getElementById('bomDetailBody').innerHTML =
            '<p><b>Malzeme:</b> ' + material + '</p>' +
            '<table class="bom-detail-table"><thead><tr><th>Adım</th><th>Açıklama</th><th>İşlem</th><th>Sonuç</th></tr></thead><tbody>' +
            '<tr><td>1. Hammadde fiyatı</td><td>1 ' + buyUnit + ' malzemenin alış fiyatı</td><td>' + alis.toLocaleString('tr-TR') + ' ' + para + '</td><td>—</td></tr>' +
            '<tr><td>2. TL çevrimi</td><td>Fiyatı güncel kur ile TL’ye çevir</td><td>' + alis.toLocaleString('tr-TR') + ' × ' + kur.toLocaleString('tr-TR') + '</td><td>' + fmt(tl,4) + ' /' + buyUnit + '</td></tr>' +
            '<tr><td>3. Birim fiyat</td><td>Alış birimini reçete birimine indir</td><td>' + tl.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' ÷ ' + bolen.toLocaleString('tr-TR') + '</td><td>' + fmt(birim,4) + ' /' + baseUnit + '</td></tr>' +
            '<tr><td>4. Şişe maliyeti</td><td>Bir şişenin ihtiyacı olan miktar ile çarp</td><td>' + birim.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' × ' + miktar.toLocaleString('tr-TR') + '</td><td>' + fmt(birim * miktar,4) + ' /şişe</td></tr>' +
            '<tr><td>5. Koli fiyatı</td><td>Bir koli ' + koli.toLocaleString('tr-TR') + ' adet olduğu için çarp</td><td>' + birim.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' × ' + miktar.toLocaleString('tr-TR') + ' × ' + koli.toLocaleString('tr-TR') + '</td><td>' + fmt(koliFiyat,4) + ' /koli</td></tr>' +
            '<tr><td>6. Fireli koli fiyatı</td><td>Üretim fire payı eklenir</td><td>' + koliFiyat.toLocaleString('tr-TR',{minimumFractionDigits:4,maximumFractionDigits:4}) + ' × (1 + %' + fire.toLocaleString('tr-TR') + ')</td><td>' + fmt(fireli,4) + ' /koli</td></tr>' +
            '</tbody></table>';
        document.getElementById('bomDetailModal').classList.add('show');
    }
    function closeBomDetail(){ document.getElementById('bomDetailModal').classList.remove('show'); }
    document.addEventListener('input', calcBom);
    document.addEventListener('change', function(e){ if(e.target && e.target.classList.contains('bom-select-row')){ refreshBomSelectAll(); } });
    document.addEventListener('blur', function(e){ if(e.target.matches('.bom-alis,.bom-kur,.bom-bolen,.bom-num,.bom-koli,.bom-fire')){ formatBomInputs(e.target.parentNode); calcBom(); } }, true);
    document.addEventListener('DOMContentLoaded', function(){ formatBomInputs(document); calcBom(); });
    (function(){
        var popup=document.getElementById('recipePopup'), box=popup ? popup.querySelector('.bom-editor') : null, head=box ? box.querySelector('.bom-head') : null;
        if(!popup || !box || !head || !popup.classList.contains('recipe-popup')) return;
        var drag=false,sx=0,sy=0,ox=0,oy=0;
        head.addEventListener('mousedown',function(e){
            if(e.target.closest('input,select,button')) return;
            drag=true; sx=e.clientX; sy=e.clientY; ox=parseFloat(box.dataset.x||0); oy=parseFloat(box.dataset.y||0); popup.classList.add('dragging'); e.preventDefault();
        });
        document.addEventListener('mousemove',function(e){
            if(!drag) return;
            var x=ox+e.clientX-sx, y=oy+e.clientY-sy;
            box.dataset.x=x; box.dataset.y=y; box.style.transform='translate('+x+'px,'+y+'px)';
        });
        document.addEventListener('mouseup',function(){ drag=false; popup.classList.remove('dragging'); });
    })();
    </script>
    <?php endif; ?>

    <?php if($tab==='uretim'): ?>
    <section class="production-workspace">
        <div class="production-hero">
            <div>
                <small>Dönemsel üretim paneli</small>
                <h2><?php echo rm_e($currentDonem['donem_adi']); ?> Üretim Takibi</h2>
                <p>Ürün bazlı koli miktarlarını, dönem toplamını ve üretim dağılımını tek ekranda izleyin.</p>
            </div>
            <div class="production-period"><span>Dönem Durumu</span><strong><?php echo rm_e($currentDonem['durum']); ?></strong></div>
        </div>
        <div class="production-kpis">
            <div class="production-kpi"><span>Dönem Toplamı</span><strong><?php echo number_format($uretimToplam,0,',','.'); ?></strong><em>koli</em></div>
            <div class="production-kpi"><span>Aktif Ürün</span><strong><?php echo (int)$uretimAktif; ?></strong><em>üretim girilmiş</em></div>
            <div class="production-kpi"><span>En Yüksek Ürün</span><strong><?php echo number_format($uretimEnYuksek,0,',','.'); ?></strong><em>koli</em></div>
            <div class="production-kpi"><span>Ortalama</span><strong><?php echo $uretimAktif>0 ? number_format($uretimToplam/$uretimAktif,0,',','.') : '0'; ?></strong><em>koli / aktif ürün</em></div>
        </div>
        <div class="production-panel">
            <div class="production-panel-head">
                <div><h3>Ürün Bazlı Üretim</h3><p><?php echo rm_e($currentDonem['donem_adi']); ?> dönemine ait modüler üretim dağılımı.</p></div>
                <span class="rm-pill"><?php echo count($uretimler); ?> ürün</span>
            </div>
            <div class="production-grid">
                <?php foreach($uretimler as $u): $qty=(float)$u['koli_miktari']; $rate=$uretimToplam>0 ? ($qty/$uretimToplam*100) : 0; ?>
                <article class="production-card">
                    <div class="production-card-top">
                        <span class="production-code"><?php echo rm_e($u['urun_kodu'] ?? '-'); ?></span>
                        <div class="production-qty"><?php echo number_format($qty,0,',','.'); ?><small>koli</small></div>
                    </div>
                    <h4><?php echo rm_e($u['urun_adi']); ?></h4>
                    <span class="muted"><?php echo rm_e($u['donem']); ?></span>
                    <div class="production-bar"><span style="width:<?php echo min(100, $rate); ?>%"></span></div>
                    <div class="production-meta"><span>Dağılım</span><b><?php echo number_format($rate,2,',','.'); ?>%</b></div>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="production-table">
                <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Dönem</th><th>Kod</th><th>Ürün</th><th>Koli Miktarı</th><th>Dağılım</th></tr></thead><tbody>
                    <?php foreach($uretimler as $u): $qty=(float)$u['koli_miktari']; $rate=$uretimToplam>0 ? ($qty/$uretimToplam*100) : 0; ?>
                    <tr><td><?php echo rm_e($u['donem']); ?></td><td><?php echo rm_e($u['urun_kodu'] ?? ''); ?></td><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo number_format($qty,0,',','.'); ?></td><td><div class="production-ratio"><i><b style="width:<?php echo min(100, $rate); ?>%"></b></i><span><?php echo number_format($rate,2,',','.'); ?>%</span></div></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if($tab==='giderler'): $costTab = $_GET['cost'] ?? 'tum'; if($costTab === 'hammadde'){ $costTab = 'koli_maliyeti'; } if(!in_array($costTab, ['tum','koli_maliyeti','genel','nakliye'], true)){ $costTab='tum'; } ?>
    <nav class="cost-tabs">
        <a class="<?php echo $costTab==='tum'?'active':''; ?>" href="?tab=giderler&cost=tum&donem=<?php echo rm_e($donem); ?>">Tüm Giderler</a>
        <a class="<?php echo $costTab==='koli_maliyeti'?'active':''; ?>" href="?tab=giderler&cost=koli_maliyeti&donem=<?php echo rm_e($donem); ?>">Koli Maliyeti</a>
        <a class="<?php echo $costTab==='genel'?'active':''; ?>" href="?tab=giderler&cost=genel&donem=<?php echo rm_e($donem); ?>">Genel Giderler</a>
        <a class="<?php echo $costTab==='nakliye'?'active':''; ?>" href="?tab=giderler&cost=nakliye&donem=<?php echo rm_e($donem); ?>">Nakliye / DEKAP</a>
    </nav>
    <section class="cost-subsection <?php echo $costTab==='tum'?'active':''; ?>">
        <section class="rm-kpis">
            <div class="rm-card"><span>Koli Maliyeti</span><strong><?php echo count($hammaddeler); ?> Kalem</strong><em class="rm-change">Adet / ton bazlı giriş</em></div>
            <div class="rm-card"><span>Genel Giderler</span><strong>720 / 730 / 760 / 770</strong><em class="rm-change">Dağıtım anahtarı</em></div>
            <div class="rm-card"><span>Nakliye / DEKAP</span><strong><?php echo count($urunMaliyetleri); ?> ürün</strong><em class="rm-change">Koli bazlı</em></div>
            <div class="rm-card"><span>Dönem</span><strong><?php echo rm_e($currentDonem['donem_adi']); ?></strong><em class="rm-change">FIFO fiyat mantığı</em></div>
        </section>
        <section class="rm-grid">
            <div class="rm-panel">
                <h3>Koli Maliyeti Kalemleri</h3>
                <div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>#</th><th>Malzeme</th><th>Birim</th><th>Ay İçi Fiyat Mantığı</th></tr></thead><tbody>
                    <?php foreach($hammaddeler as $i=>$h): ?><tr><td><?php echo $i+1; ?></td><td class="rm-product"><?php echo rm_e($h['kalem_adi']); ?></td><td><?php echo rm_e($h['birim']); ?></td><td>Adet veya ton bazlı fiyat girilir; ürün reçetesine göre adet ve koli maliyetine çevrilir.</td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
            <aside class="rm-panel">
                <h3>Tüm Gider Başlıkları</h3>
                <div class="rm-break">
                    <div class="rm-break-row"><span>Koli Maliyeti</span><b>Hammadde & ambalaj</b></div>
                    <div class="rm-break-row"><span>730 İŞÇİLİK HARİÇ GİDER</span><b>₺7,8582</b></div>
                    <div class="rm-break-row"><span>760 PAZARLAMA GİDERİ</span><b>₺2,6361</b></div>
                    <div class="rm-break-row"><span>GENEL YÖNETİM GİDERİ</span><b>₺12,4859</b></div>
                    <div class="rm-break-row"><span>İŞÇİLİK GİDERİ</span><b>₺9,1631</b></div>
                    <div class="rm-break-row"><span>Nakliye</span><b>Koli maliyeti</b></div>
                    <div class="rm-break-row"><span>DEKAP</span><b>Koli maliyeti</b></div>
                </div>
            </aside>
        </section>
    </section>
    <section class="cost-subsection <?php echo $costTab==='koli_maliyeti'?'active':''; ?>">
        <div class="cost-page">
            <div class="price-hero">
                <div><span class="price-kicker"><?php echo rm_e(rm_upper((string)$currentDonem['donem_adi'])); ?> KOLİ MALİYETİ PANELİ</span><h2>Hammadde & Ambalaj Koli Maliyeti</h2><p>Malzemeler adet, kg veya ton bazlı fiyatlanabilir. Sistem bu fiyatları ürün reçetesindeki tüketim miktarına göre adet ve koli maliyetine çevirip reçeteye aktarır.</p></div>
                <div class="currency-card"><div class="currency-grid"><label>USD Dolar Kuru (TL)<input value="<?php echo $kurOzet['usd'] > 0 ? number_format($kurOzet['usd'],2,',','.') : '36,50'; ?>"></label><label>EUR Euro Kuru (TL)<input value="<?php echo $kurOzet['eur'] > 0 ? number_format($kurOzet['eur'],2,',','.') : '39,80'; ?>"></label></div><div style="display:flex;gap:10px;margin-top:18px"><button class="method-btn active" type="button">Sorgulanıyor...</button><button class="primary-add" type="button">Kurları Kaydet</button></div></div>
            </div>
            <div class="price-kpis"><div class="rm-card"><span>Toplam Aktif Malzeme</span><strong><?php echo count($hammaddeler); ?> Kalem</strong><em class="rm-change">Reçetelerde kullanılan</em></div><div class="rm-card"><span>Para Birimi Dağılımı</span><strong><?php echo count(array_filter($fiyatlar, function($f){ return str_contains((string)$f['ton_fiyati'],'$'); })); ?> USD</strong><em class="rm-change"><?php echo count(array_filter($fiyatlar, function($f){ return str_contains((string)$f['ton_fiyati'],'€'); })); ?> EUR / <?php echo count($fiyatlar); ?> kayıt</em></div><div class="rm-card"><span>Hızlı Aktarım</span><strong>Önceki Ay</strong><em class="rm-change">Fiyatları bu aya kopyala</em></div><div class="rm-card dark"><span>Toplu Kaydet</span><strong>Tüm Fiyatlar</strong><em class="rm-change">Reçeteyi senkronize et</em></div></div>
            <div class="price-tools"><input id="matSearch" placeholder="Malzeme adı veya kodu ile filtrele..."><div class="price-filter"><span class="fifo-note">Görünüm:</span><button class="filter-pill active" type="button">Tüm Malzemeler (<?php echo count($hammaddeler); ?>)</button><button class="filter-pill" type="button">USD ($)</button><button class="filter-pill" type="button">EUR (€)</button><button class="filter-pill" type="button">Çoklu Fatura Girdili</button></div></div>
            <div class="price-list"><div class="price-list-head"><h3><?php echo rm_e(rm_upper((string)$currentDonem['donem_adi'])); ?> KOLİ MALİYETİ ÇİZELGESİ</h3><span><?php echo count($hammaddeler); ?> Malzeme Listeleniyor</span></div><div class="rm-table-wrap"><table><thead><tr><th>#</th><th>Malzeme Kodu & Adı</th><th>Fatura Alış Fiyatı</th><th>Alış Birimi</th><th>Para Birimi</th><th>Uygulanan Kur</th><th>Net TL Birim Fiyatı</th><th>Ay İçi Fiyatlar</th><th>İşlem</th></tr></thead><tbody>
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
                <div class="expense-card"><label>730 İŞÇİLİK HARİÇ GİDER</label><input class="expense-input" value="7,8582"><p>Koli başına işçilik hariç gider payı</p></div>
                <div class="expense-card"><label>760 PAZARLAMA GİDERİ</label><input class="expense-input" value="2,6361"><p>Koli başına pazarlama gideri payı</p></div>
                <div class="expense-card"><label>GENEL YÖNETİM GİDERİ</label><input class="expense-input" value="12,4859"><p>Koli başına genel yönetim gideri payı</p></div>
                <div class="expense-card"><label>İŞÇİLİK GİDERİ</label><input class="expense-input" value="9,1631"><p>Koli başına işçilik gideri payı</p></div>
            </div>
            <div class="method-title">Gider Dağıtım Yöntemi (Maliyet Anahtarı)</div>
            <div class="method-buttons">
                <button class="method-btn active" type="button">Koli Miktarına Göre</button>
                <button class="method-btn" type="button">Hacim/Litreye Göre</button>
                <button class="method-btn" type="button">Ürün Bazlı Tutar</button>
                <button class="method-btn" type="button">Özel Dağıtım Anahtarı</button>
            </div>
        </div>
        <aside class="analysis-card">
            <h3>DAĞITIM ANALİZİ SUMMARY</h3>
            <div class="kpi"><span>Koli Başına Genel Gider Toplamı</span><strong class="amber" id="expenseTotal">32,1433 TL</strong></div>
            <div class="kpi"><span>Dönem Toplam Üretim</span><strong id="expenseProduction"><?php echo number_format($uretimToplam,0,',','.'); ?> Koli</strong></div>
            <div class="kpi"><span>Hesaplanan Koli Başına Gider Payı</span><strong class="cyan" id="expensePerBox">32,1433 TL / Koli</strong></div>
            <div class="analysis-note">Giderler seçilen "Koli" anahtarına göre üretilen tüm ürünlerin koli başına maliyetine otomatik eklenir.</div>
        </aside>
    </section>
    </section>
    <section class="cost-subsection <?php echo $costTab==='nakliye'?'active':''; ?>">
        <section class="rm-panel"><h3>Nakliye / DEKAP</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Ürün</th><th>Nakliye TL/Koli</th><th>DEKAP TL/Koli</th><th>Nakliyeli</th><th>Nakliyesiz</th><th>Nakliyesiz + DEKAP</th><th>Nakliye + DEKAP Dahil</th></tr></thead><tbody><?php foreach($urunMaliyetleri as $u): $nd=$ndByProduct[$u['urun_adi']] ?? ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0]; ?><tr><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo rm_money($nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money($nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money($u['toplam']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']-(float)$nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']-(float)$nd['nakliye_tl_koli']+(float)$nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['dekap_tl_koli']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    </section>
    <script>
    (function(){
        function num(v){ return parseFloat((v || '').toString().replace(/\./g,'').replace(',','.')) || 0; }
        function money(v, d){ return v.toLocaleString('tr-TR',{minimumFractionDigits:d,maximumFractionDigits:d}); }
        function calc(){
            var total = 0;
            document.querySelectorAll('.expense-input').forEach(function(i){ total += num(i.value); });
            document.getElementById('expenseTotal').textContent = money(total, 4) + ' TL';
            document.getElementById('expensePerBox').textContent = money(total, 4) + ' TL / Koli';
        }
        document.querySelectorAll('.expense-input').forEach(function(i){ i.addEventListener('input', calc); });
        document.querySelectorAll('.method-btn').forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('.method-btn').forEach(function(x){ x.classList.remove('active'); }); b.classList.add('active'); }); });
        calc();
    })();
    </script>
    <?php endif; ?>

    <?php if($tab==='nakliye'): ?>
    <section class="rm-panel"><h3>Nakliye / DEKAP</h3><div class="rm-table-wrap"><table class="rm-table"><thead><tr><th>Ürün</th><th>Nakliye TL/Koli</th><th>DEKAP TL/Koli</th><th>Nakliyeli</th><th>Nakliyesiz</th><th>Nakliyesiz + DEKAP</th><th>Nakliye + DEKAP Dahil</th></tr></thead><tbody><?php foreach($urunMaliyetleri as $u): $nd=$ndByProduct[$u['urun_adi']] ?? ['nakliye_tl_koli'=>0,'dekap_tl_koli'=>0]; ?><tr><td class="rm-product"><?php echo rm_e($u['urun_adi']); ?></td><td class="num"><?php echo rm_money($nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money($nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money($u['toplam']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']-(float)$nd['nakliye_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']-(float)$nd['nakliye_tl_koli']+(float)$nd['dekap_tl_koli']); ?></td><td class="num"><?php echo rm_money((float)$u['toplam']+(float)$nd['dekap_tl_koli']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>
</main>
</body>
</html>








