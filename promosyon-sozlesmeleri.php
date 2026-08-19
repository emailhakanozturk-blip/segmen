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
    CREATE TABLE IF NOT EXISTS promosyon_sozlesmeleri (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cari_id INT NOT NULL,
        baslangic_tarihi DATE NOT NULL,
        bitis_tarihi DATE NOT NULL,
        urun_tanimi VARCHAR(255) NOT NULL,
        sozlesme_adedi INT NOT NULL DEFAULT 0,
        aciklama TEXT NULL,
        durum VARCHAR(20) NOT NULL DEFAULT 'Aktif',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_promosyon_cari (cari_id),
        INDEX idx_promosyon_durum (durum)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

$db->exec("
    CREATE TABLE IF NOT EXISTS promosyon_cikislari (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sozlesme_id INT NOT NULL,
        cikis_tarihi DATE NOT NULL,
        cikis_adedi INT NOT NULL DEFAULT 0,
        aciklama TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_promosyon_cikis_sozlesme (sozlesme_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci
");

function post_text(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function post_int(string $key): int
{
    return max(0, (int)($_POST[$key] ?? 0));
}

function tr_date(?string $date): string
{
    if(!$date){
        return '-';
    }

    return date('d.m.Y', strtotime($date));
}

try {
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $action = $_POST['action'] ?? '';

        if($action === 'sozlesme_ekle'){
            $cariId = post_int('cari_id');
            $baslangic = post_text('baslangic_tarihi');
            $bitis = post_text('bitis_tarihi');
            $urun = post_text('urun_tanimi');
            $adet = post_int('sozlesme_adedi');
            $aciklama = post_text('aciklama');
            $durum = post_text('durum');
            $durumlar = ['Aktif', 'Pasif', 'Tamamlandı'];

            if($cariId <= 0 || $baslangic === '' || $bitis === '' || $urun === '' || $adet <= 0){
                throw new Exception('Cari, tarih, ürün tanımı ve sözleşme adedi zorunludur.');
            }

            if(!in_array($durum, $durumlar, true)){
                $durum = 'Aktif';
            }

            $insert = $db->prepare("
                INSERT INTO promosyon_sozlesmeleri
                    (cari_id, baslangic_tarihi, bitis_tarihi, urun_tanimi, sozlesme_adedi, aciklama, durum)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$cariId, $baslangic, $bitis, $urun, $adet, $aciklama, $durum]);

            $message = 'Sponsorluk sözleşmesi oluşturuldu.';
        }

        if($action === 'cikis_ekle'){
            $sozlesmeId = post_int('sozlesme_id');
            $cikisTarihi = post_text('cikis_tarihi');
            $cikisAdedi = post_int('cikis_adedi');
            $aciklama = post_text('cikis_aciklama');

            if($sozlesmeId <= 0 || $cikisTarihi === '' || $cikisAdedi <= 0){
                throw new Exception('Sözleşme, çıkış tarihi ve çıkış adedi zorunludur.');
            }

            $sozlesmeQuery = $db->prepare("
                SELECT ps.*, COALESCE(SUM(pc.cikis_adedi), 0) AS toplam_cikis
                FROM promosyon_sozlesmeleri ps
                LEFT JOIN promosyon_cikislari pc ON pc.sozlesme_id = ps.id
                WHERE ps.id = ?
                GROUP BY ps.id
            ");
            $sozlesmeQuery->execute([$sozlesmeId]);
            $sozlesme = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

            if(!$sozlesme){
                throw new Exception('Sponsorluk sözleşmesi bulunamadı.');
            }

            $kalan = (int)$sozlesme['sozlesme_adedi'] - (int)$sozlesme['toplam_cikis'];

            if($cikisAdedi > $kalan){
                throw new Exception('Çıkış adedi kalan adedi aşamaz. Kalan adet: ' . $kalan);
            }

            $insert = $db->prepare("
                INSERT INTO promosyon_cikislari
                    (sozlesme_id, cikis_tarihi, cikis_adedi, aciklama)
                VALUES (?, ?, ?, ?)
            ");
            $insert->execute([$sozlesmeId, $cikisTarihi, $cikisAdedi, $aciklama]);

            $message = 'Sponsorluk çıkışı kaydedildi.';
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$cariler = $db->query("
    SELECT id, firma_adi
    FROM cariler
    ORDER BY firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$urunTanimlari = $db->query("
    SELECT tanim_adi, kategori
    FROM tanimlar
    WHERE durum = 1
    AND kategori IN ('Ürün', 'Malzeme', 'Palet')
    ORDER BY kategori ASC, tanim_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sozlesmeler = $db->query("
    SELECT
        ps.*,
        c.firma_adi,
        COALESCE(SUM(pc.cikis_adedi), 0) AS toplam_cikis
    FROM promosyon_sozlesmeleri ps
    LEFT JOIN cariler c ON c.id = ps.cari_id
    LEFT JOIN promosyon_cikislari pc ON pc.sozlesme_id = ps.id
    GROUP BY ps.id
    ORDER BY ps.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$aktifSozlesmeler = array_filter($sozlesmeler, function($row){
    return (string)$row['durum'] === 'Aktif'
        && ((int)$row['sozlesme_adedi'] - (int)$row['toplam_cikis']) > 0;
});

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Sponsorluk Sözleşmeleri</title>
<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>
.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}
.panel{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:18px;
    box-shadow:0 8px 22px rgba(15,23,42,.04);
}
.panel h3{
    margin:0 0 14px;
    font-size:18px;
    color:#0f172a;
}
.form-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.form-group{
    margin-bottom:12px;
}
label{
    display:block;
    margin-bottom:6px;
    font-size:13px;
    font-weight:700;
    color:#1f2937;
}
input, select, textarea{
    width:100%;
    box-sizing:border-box;
    border:1px solid #d1d5db;
    border-radius:8px;
    padding:10px 11px;
    font-size:13px;
    font-family:Arial, Helvetica, sans-serif;
}
textarea{
    min-height:76px;
    resize:vertical;
}
.btn{
    border:0;
    background:#16a34a;
    color:#fff;
    padding:10px 14px;
    border-radius:8px;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
}
.notice{
    margin-bottom:16px;
    padding:12px 14px;
    border-radius:10px;
    font-weight:700;
    font-size:13px;
}
.notice-ok{
    background:#dcfce7;
    color:#166534;
}
.notice-error{
    background:#fee2e2;
    color:#991b1b;
}
.table-area{
    margin-top:18px;
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:16px;
    overflow:auto;
}
table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}
th{
    background:#0f172a;
    color:#fff;
    padding:10px 8px;
    font-size:11px;
    text-align:left;
    text-transform:uppercase;
}
td{
    padding:10px 8px;
    border-bottom:1px solid #eef2f7;
    font-size:12px;
    color:#111827;
    overflow-wrap:anywhere;
    vertical-align:middle;
}
.badge{
    display:inline-flex;
    padding:5px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    color:#fff;
}
.aktif{background:#16a34a;}
.pasif{background:#64748b;}
.tamamlandi{background:#2563eb;}
.kalan{
    font-weight:900;
    color:#0f766e;
}
@media(max-width:1000px){
    .grid,.form-row{grid-template-columns:1fr;}
    table{min-width:900px;}
}
</style>
</head>

<body>
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Sponsorluk Sözleşmeleri</h2>
        <p>Carilere bağlı sponsorluk sözleşmeleri ve ürün çıkış takip ekranı</p>
    </div>

    <?php if($message): ?>
        <div class="notice notice-ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="notice notice-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="panel">
            <h3>Sponsorluk Sözleşmesi Oluştur</h3>

            <form method="POST">
                <input type="hidden" name="action" value="sozlesme_ekle">

                <div class="form-group">
                    <label>Cari</label>
                    <select name="cari_id" required>
                        <option value="">Cari seçiniz</option>
                        <?php foreach($cariler as $cari): ?>
                            <option value="<?php echo (int)$cari['id']; ?>">
                                <?php echo htmlspecialchars((string)$cari['firma_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Sözleşme başlangıç tarihi</label>
                        <input type="date" name="baslangic_tarihi" required>
                    </div>
                    <div class="form-group">
                        <label>Sözleşme bitiş tarihi</label>
                        <input type="date" name="bitis_tarihi" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Ürün tanımı</label>
                        <select name="urun_tanimi" required>
                            <option value="">Ürün / malzeme seçiniz</option>
                            <?php foreach($urunTanimlari as $urun): ?>
                                <option value="<?php echo htmlspecialchars((string)$urun['tanim_adi']); ?>">
                                    <?php echo htmlspecialchars((string)$urun['kategori'] . ' - ' . (string)$urun['tanim_adi']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sözleşme adedi</label>
                        <input type="number" name="sozlesme_adedi" min="1" step="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Açıklama</label>
                    <textarea name="aciklama"></textarea>
                </div>

                <div class="form-group">
                    <label>Durum</label>
                    <select name="durum">
                        <option value="Aktif">Aktif</option>
                        <option value="Pasif">Pasif</option>
                        <option value="Tamamlandı">Tamamlandı</option>
                    </select>
                </div>

                <button type="submit" class="btn">Sözleşme Kaydet</button>
            </form>
        </div>

        <div class="panel">
            <h3>Sponsorluk Çıkışı Ekle</h3>

            <form method="POST">
                <input type="hidden" name="action" value="cikis_ekle">

                <div class="form-group">
                    <label>Sözleşme seçimi</label>
                    <select name="sozlesme_id" required>
                        <option value="">Sözleşme seçiniz</option>
                        <?php foreach($aktifSozlesmeler as $sozlesme): ?>
                            <?php $kalan = (int)$sozlesme['sozlesme_adedi'] - (int)$sozlesme['toplam_cikis']; ?>
                            <option value="<?php echo (int)$sozlesme['id']; ?>">
                                <?php echo htmlspecialchars((string)$sozlesme['firma_adi'] . ' - ' . (string)$sozlesme['urun_tanimi'] . ' / Kalan: ' . $kalan); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Çıkış tarihi</label>
                        <input type="date" name="cikis_tarihi" required>
                    </div>
                    <div class="form-group">
                        <label>Çıkış adedi</label>
                        <input type="number" name="cikis_adedi" min="1" step="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Açıklama</label>
                    <textarea name="cikis_aciklama"></textarea>
                </div>

                <button type="submit" class="btn">Çıkış Kaydet</button>
            </form>
        </div>
    </div>

    <div class="table-area">
        <table>
            <thead>
                <tr>
                    <th>Cari adı</th>
                    <th>Ürün tanımı</th>
                    <th>Sözleşme adedi</th>
                    <th>Toplam çıkış</th>
                    <th>Kalan adet</th>
                    <th>Başlangıç</th>
                    <th>Bitiş</th>
                    <th>Durum</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($sozlesmeler as $sozlesme): ?>
                    <?php
                        $kalan = (int)$sozlesme['sozlesme_adedi'] - (int)$sozlesme['toplam_cikis'];
                        $durumClass = 'aktif';
                        if($sozlesme['durum'] === 'Pasif'){
                            $durumClass = 'pasif';
                        }elseif($sozlesme['durum'] === 'Tamamlandı'){
                            $durumClass = 'tamamlandi';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$sozlesme['firma_adi']); ?></td>
                        <td><?php echo htmlspecialchars((string)$sozlesme['urun_tanimi']); ?></td>
                        <td><?php echo number_format((int)$sozlesme['sozlesme_adedi'], 0, ',', '.'); ?></td>
                        <td><?php echo number_format((int)$sozlesme['toplam_cikis'], 0, ',', '.'); ?></td>
                        <td class="kalan"><?php echo number_format($kalan, 0, ',', '.'); ?></td>
                        <td><?php echo tr_date($sozlesme['baslangic_tarihi']); ?></td>
                        <td><?php echo tr_date($sozlesme['bitis_tarihi']); ?></td>
                        <td><span class="badge <?php echo $durumClass; ?>"><?php echo htmlspecialchars((string)$sozlesme['durum']); ?></span></td>
                    </tr>
                <?php endforeach; ?>

                <?php if(empty($sozlesmeler)): ?>
                    <tr>
                        <td colspan="8">Henüz sponsorluk sözleşmesi yok.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
