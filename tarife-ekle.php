<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';
$error = false;
$kaynakId = (int)($_GET['kaynak_id'] ?? 0);
$kaynakTarife = null;

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sayiCevir($value): float
{
    $value = trim((string)$value);
    $value = str_replace(['₺', 'TL', ' ', "\xc2\xa0"], '', $value);

    if(strpos($value, ',') !== false){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

$cariler = $db->query("
    SELECT id, firma_adi
    FROM cariler
    ORDER BY firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sozlesmeler = $db->query("
    SELECT
        sozlesmeler.id,
        sozlesmeler.cari_id,
        sozlesmeler.sozlesme_no,
        sozlesmeler.sozlesme_tutari,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    WHERE sozlesmeler.durum = 1
    ORDER BY cariler.firma_adi ASC, sozlesmeler.sozlesme_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

$mevcutTarifeler = $db->query("
    SELECT
        sozlesme_id,
        cikis_noktasi,
        varis_noktasi,
        tarife_tipi,
        sevkiyat_km,
        birim_fiyat,
        motorin_baz_fiyati
    FROM tarifeler
    WHERE aktif = 1
    AND (
        bitis_tarihi IS NULL
        OR bitis_tarihi = '0000-00-00'
    )
")->fetchAll(PDO::FETCH_ASSOC);

if($kaynakId > 0){
    $kaynakQuery = $db->prepare("SELECT * FROM tarifeler WHERE id = ? LIMIT 1");
    $kaynakQuery->execute([$kaynakId]);
    $kaynakTarife = $kaynakQuery->fetch(PDO::FETCH_ASSOC) ?: null;
}

$columns = $db->query("SHOW COLUMNS FROM noktalar")->fetchAll(PDO::FETCH_COLUMN);
$tipKolonu = in_array('tip', $columns, true) ? 'tip' : 'nokta_tipi';
$kodKolonu = in_array('nokta_kodu', $columns, true) ? 'nokta_kodu' : 'id';

$cikisNoktalari = $db->query("
    SELECT *
    FROM noktalar
    WHERE (
        $tipKolonu IN ('cikis','CIKIS','ikisi','IKISI')
        OR $tipKolonu IS NULL
        OR $tipKolonu = ''
    )
    ORDER BY nokta_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

$varisNoktalari = $db->query("
    SELECT *
    FROM noktalar
    WHERE (
        $tipKolonu IN ('varis','VARIS','ikisi','IKISI')
        OR $tipKolonu IS NULL
        OR $tipKolonu = ''
    )
    ORDER BY nokta_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        $cariId = (int)($_POST['cari_id'] ?? 0);
        $sozlesmeId = (int)($_POST['sozlesme_id'] ?? 0);
        $cikis = trim((string)($_POST['cikis_noktasi'] ?? ''));
        $varis = trim((string)($_POST['varis_noktasi'] ?? ''));
        $arac = trim((string)($_POST['arac_tipi'] ?? ''));
        $tarifeTipi = trim((string)($_POST['tarife_tipi'] ?? 'NORMAL'));
        $sevkiyatKm = sayiCevir($_POST['sevkiyat_km'] ?? 0);
        $motorinBaz = sayiCevir($_POST['motorin_baz_fiyati'] ?? 0);
        $birimGirdi = sayiCevir($_POST['birim_fiyat'] ?? 0);
        $motorinRevize = isset($_POST['motorin_revize']) ? 1 : 0;
        $baslangic = trim((string)($_POST['baslangic_tarihi'] ?? '')) ?: null;
        $bitis = trim((string)($_POST['bitis_tarihi'] ?? '')) ?: null;
        $aciklama = trim((string)($_POST['aciklama'] ?? ''));

        if($cariId <= 0 || $sozlesmeId <= 0){
            throw new Exception('Firma ve sözleşme seçimi zorunludur.');
        }

        if($cikis === '' || $varis === ''){
            throw new Exception('Çıkış ve varış noktası zorunludur.');
        }

        if($motorinBaz <= 0){
            throw new Exception('Baz motorin fiyatı sıfırdan büyük olmalıdır.');
        }

        if($birimGirdi > 0){
            $sevkiyatKm = round($birimGirdi / $motorinBaz, 2);
            $birim = round($birimGirdi, 4);
        } elseif($sevkiyatKm > 0){
            $birim = round($sevkiyatKm * $motorinBaz, 4);
        } else {
            throw new Exception('Sevkiyat km veya birim fiyat alanlarından en az biri girilmelidir.');
        }

        if($sevkiyatKm <= 0 || $birim <= 0){
            throw new Exception('KM ve birim fiyat hesaplanamadı. Lütfen değerleri kontrol edin.');
        }

        $sozlesmeQuery = $db->prepare("
            SELECT
                sozlesmeler.*,
                cariler.firma_adi
            FROM sozlesmeler
            LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
            WHERE sozlesmeler.id = ?
            AND sozlesmeler.cari_id = ?
            LIMIT 1
        ");
        $sozlesmeQuery->execute([$sozlesmeId, $cariId]);
        $sozlesmeData = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

        if(!$sozlesmeData){
            throw new Exception('Seçilen sözleşme firma ile eşleşmiyor.');
        }

        $firma = $sozlesmeData['firma_adi'];
        $sozlesmeNo = $sozlesmeData['sozlesme_no'];
        $oncekiGun = $baslangic ? date('Y-m-d', strtotime($baslangic . ' -1 day')) : null;

        $pasifSql = "
            UPDATE tarifeler
            SET aktif = 0" . ($oncekiGun ? ", bitis_tarihi = ?" : "") . "
            WHERE sozlesme_id = ?
            AND cikis_noktasi = ?
            AND varis_noktasi = ?
            AND tarife_tipi = ?
            AND (
                bitis_tarihi IS NULL
                OR bitis_tarihi = '0000-00-00'
            )
        ";

        $pasifParams = $oncekiGun
            ? [$oncekiGun, $sozlesmeId, $cikis, $varis, $tarifeTipi]
            : [$sozlesmeId, $cikis, $varis, $tarifeTipi];

        $pasif = $db->prepare($pasifSql);
        $pasif->execute($pasifParams);

        $revizyonQuery = $db->prepare("
            SELECT MAX(revizyon_no) AS max_rev
            FROM tarifeler
            WHERE sozlesme_id = ?
            AND cikis_noktasi = ?
            AND varis_noktasi = ?
            AND tarife_tipi = ?
        ");
        $revizyonQuery->execute([$sozlesmeId, $cikis, $varis, $tarifeTipi]);
        $revizyonNo = ((int)($revizyonQuery->fetch(PDO::FETCH_ASSOC)['max_rev'] ?? 0)) + 1;

        $insert = $db->prepare("
            INSERT INTO tarifeler
            (
                cari_id,
                sozlesme_id,
                firma_adi,
                sozlesme_no,
                cikis_noktasi,
                varis_noktasi,
                arac_tipi,
                sevkiyat_km,
                tarife_tipi,
                birim_fiyat,
                motorin_baz_fiyati,
                motorin_revize,
                baslangic_tarihi,
                bitis_tarihi,
                revizyon_no,
                aciklama,
                aktif
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");

        $insert->execute([
            $cariId,
            $sozlesmeId,
            $firma,
            $sozlesmeNo,
            $cikis,
            $varis,
            $arac,
            $sevkiyatKm,
            $tarifeTipi,
            $birim,
            $motorinBaz,
            $motorinRevize,
            $baslangic,
            $bitis,
            $revizyonNo,
            $aciklama
        ]);

        $message = 'Tarife kaydedildi. KM: ' . number_format($sevkiyatKm, 2, ',', '.') . ' | Birim baz fiyat: ₺' . number_format($birim, 2, ',', '.') . ' (' . number_format($sevkiyatKm, 2, ',', '.') . ' km x ₺' . number_format($motorinBaz, 3, ',', '.') . ')';
    } catch(Throwable $exception){
        $error = true;
        $message = $exception->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Yeni Tarife</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.box{
    background:white;
    padding:22px;
    border-radius:12px;
    max-width:1100px;
    border:1px solid #e7eaf0;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
}
.grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}
.form-group{
    display:flex;
    flex-direction:column;
}
label{
    margin-bottom:5px;
    font-size:13px;
    font-weight:bold;
    color:#374151;
}
input,
select,
textarea{
    padding:11px 12px;
    border:1px solid #d8dde6;
    border-radius:8px;
    box-sizing:border-box;
    font-size:14px;
}
textarea{
    min-height:76px;
}
.readonly{
    background:#f8fafc;
    font-weight:800;
    color:#0f766e;
}
button,
.btn-link{
    background:#16a34a;
    color:white;
    border:none;
    padding:11px 16px;
    border-radius:8px;
    cursor:pointer;
    margin-top:15px;
    font-weight:bold;
    text-decoration:none;
    display:inline-flex;
}
.btn-link{
    background:#64748b;
    margin-left:8px;
}
.alert{
    background:#dcfce7;
    color:#166534;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
    font-weight:bold;
}
.alert.error{
    background:#fee2e2;
    color:#991b1b;
}
.info{
    background:#eff6ff;
    color:#1d4ed8;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
    font-weight:bold;
    line-height:1.5;
}
.check-row{
    display:flex;
    align-items:center;
    gap:10px;
    padding:11px;
    border:1px solid #d8dde6;
    border-radius:8px;
    background:#f9fafb;
}
.check-row input{
    width:auto;
}
@media(max-width:900px){
    .grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Yeni Tarife Ekle</h2>
        <p>Tarifeler firma ve sözleşme bazında tutulur. KM ile birim fiyat birbirini baz motorin üzerinden hesaplar.</p>
    </div>

    <div class="box">
        <div class="info">
            Örnek: 145 km ve ₺54,766 baz motorin girildiğinde birim fiyat ₺7.941,07 olur. Birim fiyatı yazarsanız KM otomatik olarak birim fiyat / baz motorin hesabıyla çıkar.
        </div>

        <?php if($message): ?>
            <div class="alert <?php echo $error ? 'error' : ''; ?>">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <?php if($kaynakTarife): ?>
            <div class="info">
                Seçilen eski tarife bilgileri forma getirildi. Kaydedince aynı rota için yeni revizyon oluşur.
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="grid">
                <div class="form-group">
                    <label>Firma</label>
                    <select name="cari_id" id="cari_id" required>
                        <option value="">Firma seçiniz</option>
                        <?php foreach($cariler as $cari): ?>
                            <option
                                value="<?php echo (int)$cari['id']; ?>"
                                <?php echo (int)($kaynakTarife['cari_id'] ?? 0) === (int)$cari['id'] ? 'selected' : ''; ?>>
                                <?php echo e($cari['firma_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Sözleşme</label>
                    <select name="sozlesme_id" id="sozlesme_id" required>
                        <option value="">Önce firma seçiniz</option>
                        <?php foreach($sozlesmeler as $sozlesme): ?>
                            <option
                                value="<?php echo (int)$sozlesme['id']; ?>"
                                data-cari="<?php echo (int)$sozlesme['cari_id']; ?>"
                                <?php echo (int)($kaynakTarife['sozlesme_id'] ?? 0) === (int)$sozlesme['id'] ? 'selected' : ''; ?>>
                                <?php echo e($sozlesme['sozlesme_no']); ?>
                                - ₺<?php echo number_format((float)$sozlesme['sozlesme_tutari'], 2, ',', '.'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tarife Tipi</label>
                    <select name="tarife_tipi" required>
                        <?php $seciliTip = (string)($kaynakTarife['tarife_tipi'] ?? 'NORMAL'); ?>
                        <option value="NORMAL" <?php echo $seciliTip === 'NORMAL' ? 'selected' : ''; ?>>Normal Nakliye</option>
                        <option value="PALET" <?php echo $seciliTip === 'PALET' ? 'selected' : ''; ?>>Palet Nakliyesi</option>
                        <option value="DAMACANA" <?php echo $seciliTip === 'DAMACANA' ? 'selected' : ''; ?>>Damacana Nakliyesi</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Araç Tipi</label>
                    <input type="text" name="arac_tipi" placeholder="Kamyon / Tır / Panelvan" value="<?php echo e($kaynakTarife['arac_tipi'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Çıkış Noktası</label>
                    <select name="cikis_noktasi" required>
                        <option value="">Çıkış noktası seçiniz</option>
                        <?php foreach($cikisNoktalari as $nokta): ?>
                            <option
                                value="<?php echo e($nokta['nokta_adi']); ?>"
                                <?php echo (string)($kaynakTarife['cikis_noktasi'] ?? '') === (string)$nokta['nokta_adi'] ? 'selected' : ''; ?>>
                                <?php echo e($nokta[$kodKolonu] ?? ''); ?> - <?php echo e($nokta['nokta_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Varış Noktası</label>
                    <select name="varis_noktasi" required>
                        <option value="">Varış noktası seçiniz</option>
                        <?php foreach($varisNoktalari as $nokta): ?>
                            <option
                                value="<?php echo e($nokta['nokta_adi']); ?>"
                                <?php echo (string)($kaynakTarife['varis_noktasi'] ?? '') === (string)$nokta['nokta_adi'] ? 'selected' : ''; ?>>
                                <?php echo e($nokta[$kodKolonu] ?? ''); ?> - <?php echo e($nokta['nokta_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Sevkiyat KM</label>
                    <input type="text" name="sevkiyat_km" id="sevkiyat_km" placeholder="145" value="<?php echo $kaynakTarife ? e(number_format((float)$kaynakTarife['sevkiyat_km'], 2, ',', '.')) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Baz Motorin Fiyatı</label>
                    <input type="text" name="motorin_baz_fiyati" id="motorin_baz_fiyati" placeholder="54,766" required value="<?php echo $kaynakTarife ? e(number_format((float)$kaynakTarife['motorin_baz_fiyati'], 3, ',', '.')) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Birim Baz Fiyat</label>
                    <input type="text" name="birim_fiyat" id="birim_fiyat" placeholder="7.920,00" value="<?php echo $kaynakTarife ? e(number_format((float)$kaynakTarife['birim_fiyat'], 2, ',', '.')) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Başlangıç Tarihi</label>
                    <input type="date" name="baslangic_tarihi" value="<?php echo e($kaynakTarife['baslangic_tarihi'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Bitiş Tarihi</label>
                    <input type="date" name="bitis_tarihi">
                </div>

                <div class="form-group">
                    <label>Motorin Revizyonu</label>
                    <div class="check-row">
                        <input type="checkbox" name="motorin_revize" value="1" <?php echo (int)($kaynakTarife['motorin_revize'] ?? 1) === 1 ? 'checked' : ''; ?>>
                        <span>Güncel motorin değiştiğinde bu tarife revize edilsin</span>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-top:15px;">
                <label>Açıklama</label>
                <textarea name="aciklama" placeholder="Örn: Damacana taşıması, özel rota veya dönem notu"><?php echo e($kaynakTarife['aciklama'] ?? ''); ?></textarea>
            </div>

            <button type="submit">Tarife Kaydet</button>
            <a class="btn-link" href="tarifeler.php">Tarifelere Dön</a>
        </form>
    </div>
</div>

<script>
const firmaSelect = document.getElementById('cari_id');
const sozlesmeSelect = document.getElementById('sozlesme_id');
const sozlesmeOptions = Array.from(sozlesmeSelect.options).slice(1).map(option => option.cloneNode(true));
const mevcutTarifeler = <?php echo json_encode($mevcutTarifeler, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const ilkSozlesme = '<?php echo (int)($kaynakTarife['sozlesme_id'] ?? 0); ?>';
let lastEdited = 'km';

function parseTrNumber(value){
    value = String(value || '').replace(/[₺TL\s]/g, '');
    if(value.includes(',')){
        value = value.replace(/\./g, '').replace(',', '.');
    }
    return Number(value) || 0;
}

function formatMoney(value){
    return new Intl.NumberFormat('tr-TR', {
        style: 'currency',
        currency: 'TRY',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(value || 0);
}

function filtreleSozlesmeler(){
    const cariId = firmaSelect.value;
    sozlesmeSelect.innerHTML = '<option value="">Sözleşme seçiniz</option>';

    sozlesmeOptions
        .filter(option => option.dataset.cari === cariId)
        .forEach(option => {
            const clone = option.cloneNode(true);
            if(ilkSozlesme !== '0' && clone.value === ilkSozlesme){
                clone.selected = true;
            }
            sozlesmeSelect.appendChild(clone);
        });
}

function formatDecimal(value, digits = 2){
    return new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits
    }).format(value || 0);
}

function hesaplaTarife(){
    const kmInput = document.getElementById('sevkiyat_km');
    const motorinInput = document.getElementById('motorin_baz_fiyati');
    const birimInput = document.getElementById('birim_fiyat');
    const motorin = parseTrNumber(motorinInput.value);

    if(motorin <= 0){
        return;
    }

    if(lastEdited === 'birim'){
        const birim = parseTrNumber(birimInput.value);
        if(birim > 0){
            kmInput.value = formatDecimal(birim / motorin, 2);
        }
        return;
    }

    const km = parseTrNumber(kmInput.value);
    if(km > 0){
        birimInput.value = formatDecimal(km * motorin, 2);
    }
}

function routeKey(value){
    return String(value || '').trim().toLocaleUpperCase('tr-TR');
}

function mevcutTarifeyiDoldur(){
    const sozlesmeId = sozlesmeSelect.value;
    const cikis = document.querySelector('[name="cikis_noktasi"]').value;
    const varis = document.querySelector('[name="varis_noktasi"]').value;
    const tarifeTipi = document.querySelector('[name="tarife_tipi"]').value;

    if(!sozlesmeId || !cikis || !varis){
        return;
    }

    const bulunan = mevcutTarifeler.find(row =>
        String(row.sozlesme_id || '') === String(sozlesmeId) &&
        routeKey(row.cikis_noktasi) === routeKey(cikis) &&
        routeKey(row.varis_noktasi) === routeKey(varis) &&
        routeKey(row.tarife_tipi || 'NORMAL') === routeKey(tarifeTipi || 'NORMAL')
    );

    if(!bulunan){
        return;
    }

    document.getElementById('sevkiyat_km').value = formatDecimal(Number(bulunan.sevkiyat_km || 0), 2);
    document.getElementById('motorin_baz_fiyati').value = formatDecimal(Number(bulunan.motorin_baz_fiyati || 0), 3);
    document.getElementById('birim_fiyat').value = formatDecimal(Number(bulunan.birim_fiyat || 0), 2);
}

firmaSelect.addEventListener('change', filtreleSozlesmeler);
document.getElementById('sevkiyat_km').addEventListener('input', () => {
    lastEdited = 'km';
    hesaplaTarife();
});
document.getElementById('birim_fiyat').addEventListener('input', () => {
    lastEdited = 'birim';
    hesaplaTarife();
});
document.getElementById('motorin_baz_fiyati').addEventListener('input', hesaplaTarife);
sozlesmeSelect.addEventListener('change', mevcutTarifeyiDoldur);
document.querySelector('[name="cikis_noktasi"]').addEventListener('change', mevcutTarifeyiDoldur);
document.querySelector('[name="varis_noktasi"]').addEventListener('change', mevcutTarifeyiDoldur);
document.querySelector('[name="tarife_tipi"]').addEventListener('change', mevcutTarifeyiDoldur);
filtreleSozlesmeler();
</script>

</body>
</html>
