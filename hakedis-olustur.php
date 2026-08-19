<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';
$errorMessage = false;

$sozlesmeler = $db->query("
    SELECT
        sozlesmeler.id,
        sozlesmeler.sozlesme_no,
        sozlesmeler.cari_id,
        cariler.firma_adi
    FROM sozlesmeler
    LEFT JOIN cariler ON cariler.id = sozlesmeler.cari_id
    WHERE sozlesmeler.durum = 1
    ORDER BY cariler.firma_adi ASC
")->fetchAll(PDO::FETCH_ASSOC);

function tarihCevir($value){
    $value = trim((string)$value);

    if($value === ''){
        return null;
    }

    if(preg_match('/^\d{4}-\d{2}-\d{2}/', $value)){
        return substr($value, 0, 10);
    }

    $value = str_replace('/', '.', $value);
    $parca = explode('.', $value);

    if(count($parca) === 3){
        return $parca[2] . '-' . str_pad($parca[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parca[0], 2, '0', STR_PAD_LEFT);
    }

    return null;
}

function xlsxSatirlariOku($filePath){
    $python = 'C:\\Users\\asus\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'xlsx_to_rows.py';

    if(!is_file($python) || !is_file($script)){
        throw new Exception('Excel okuyucu bulunamadı. Dosyayı CSV olarak yükleyin.');
    }

    $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($filePath) . ' 2>&1';
    $output = shell_exec($command);
    $rows = json_decode($output, true);

    if(!is_array($rows)){
        throw new Exception('Excel dosyası okunamadı: ' . trim((string)$output));
    }

    return $rows;
}

function yuklenenDosyaSatirlari(){
    if(empty($_FILES['aktarim_dosyasi']['tmp_name'])){
        return null;
    }

    $tmp = $_FILES['aktarim_dosyasi']['tmp_name'];
    $fileName = $_FILES['aktarim_dosyasi']['name'] ?? '';
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if(in_array($extension, ['xlsx', 'xlsm'], true)){
        return [
            'rows' => xlsxSatirlariOku($tmp),
            'file_name' => $fileName
        ];
    }

    $rows = [];

    if(($file = fopen($tmp, 'r')) !== false){
        while(($data = fgetcsv($file, 5000, ';', '"', '\\')) !== false){
            $rows[] = $data;
        }

        fclose($file);
    }

    return [
        'rows' => $rows,
        'file_name' => $fileName
    ];
}

function dosyadanTarihAraligi($rows){
    $dates = [];

    foreach($rows as $index => $row){
        if($index === 0){
            continue;
        }

        $date = tarihCevir($row[2] ?? '');

        if($date && preg_match('/\bSEI\d+/i', (string)($row[1] ?? ''))){
            $dates[] = $date;
        }
    }

    if(empty($dates)){
        return null;
    }

    sort($dates);

    return [
        'start' => reset($dates),
        'end' => end($dates),
        'period' => date('Y-n', strtotime(reset($dates)))
    ];
}

function donemIlkGun($donem){
    if(!preg_match('/^(\d{4})-(\d{1,2})$/', $donem, $match)){
        return null;
    }

    return sprintf('%04d-%02d-01', (int)$match[1], (int)$match[2]);
}

function donemSonGun($donem){
    $ilkGun = donemIlkGun($donem);

    if(!$ilkGun){
        return null;
    }

    return date('Y-m-t', strtotime($ilkGun));
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        $sozlesme_id = (int)($_POST['sozlesme_id'] ?? 0);
        $donem = trim($_POST['donem'] ?? '');
        $baslangic_tarihi = trim($_POST['baslangic_tarihi'] ?? '');
        $bitis_tarihi = trim($_POST['bitis_tarihi'] ?? '');
        $uploaded = yuklenenDosyaSatirlari();

        if($uploaded){
            $range = dosyadanTarihAraligi($uploaded['rows']);

            if($range){
                $donem = $donem ?: $range['period'];
                $baslangic_tarihi = $baslangic_tarihi ?: $range['start'];
                $bitis_tarihi = $bitis_tarihi ?: $range['end'];

                $_SESSION['csv_rows'] = $uploaded['rows'];
                $_SESSION['csv_file_name'] = $uploaded['file_name'];
            }
        }

        if($donem !== ''){
            $baslangic_tarihi = $baslangic_tarihi ?: donemIlkGun($donem);
            $bitis_tarihi = $bitis_tarihi ?: donemSonGun($donem);
        }

        if(!$sozlesme_id || !$donem || !$baslangic_tarihi || !$bitis_tarihi){
            throw new Exception('Sözleşme ve dönem seçin. Tarihler dönemden veya yüklenen dosyadan otomatik gelir.');
        }

        $sozlesmeQuery = $db->prepare("SELECT * FROM sozlesmeler WHERE id = ? LIMIT 1");
        $sozlesmeQuery->execute([$sozlesme_id]);
        $sozlesme = $sozlesmeQuery->fetch(PDO::FETCH_ASSOC);

        if(!$sozlesme){
            throw new Exception('Sözleşme bulunamadı.');
        }

        $hakedis_no = 'HKD-' . date('Y') . '-' . rand(1000, 9999);

        $insert = $db->prepare("
            INSERT INTO hakedisler
            (
                hakedis_no,
                cari_id,
                sozlesme_id,
                donem,
                baslangic_tarihi,
                bitis_tarihi,
                toplam_tutar,
                kdv_tutar,
                tevkifat_tutar,
                net_tutar,
                durum
            )
            VALUES
            (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 'bekliyor')
        ");

        $insert->execute([
            $hakedis_no,
            $sozlesme['cari_id'],
            $sozlesme_id,
            $donem,
            $baslangic_tarihi,
            $bitis_tarihi
        ]);

        $hakedis_id = $db->lastInsertId();

        header("Location: excel-eslestir.php?hakedis_id=" . $hakedis_id);
        exit;
    } catch(Exception $e){
        $errorMessage = true;
        $message = $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Hakediş Oluştur</title>
<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>
.create-layout{
    display:grid;
    grid-template-columns:1fr 340px;
    gap:18px;
    align-items:start;
}

.form-area,
.helper-panel{
    background:white;
    padding:24px;
    border-radius:12px;
    border:1px solid #e7eaf0;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}

.form-group{
    margin-bottom:16px;
}

.form-group.full{
    grid-column:1 / -1;
}

.form-group label{
    display:block;
    margin-bottom:7px;
    font-weight:700;
    color:#1f2937;
}

.form-group input,
.form-group select{
    width:100%;
    padding:12px 13px;
    border:1px solid #d7dde8;
    border-radius:8px;
    font-size:14px;
    background:#fff;
}

.hint{
    margin-top:6px;
    color:#64748b;
    font-size:12px;
}

.btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:13px 18px;
    border-radius:8px;
    cursor:pointer;
    font-size:14px;
    font-weight:700;
}

.alert{
    background:#fee2e2;
    color:#991b1b;
    padding:13px;
    border-radius:8px;
    margin-bottom:16px;
    font-weight:700;
}

.quick-months{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:8px;
    margin-top:8px;
}

.quick-months button{
    border:1px solid #d7dde8;
    background:#f8fafc;
    color:#334155;
    border-radius:8px;
    padding:9px 8px;
    cursor:pointer;
    font-weight:700;
}

.quick-months button:hover{
    background:#eaf3ff;
    border-color:#9cc7ff;
}

.helper-panel h3{
    font-size:16px;
    margin-bottom:10px;
}

.helper-panel p,
.helper-panel li{
    color:#64748b;
    font-size:13px;
    line-height:1.45;
}

.helper-panel ul{
    padding-left:18px;
    margin-top:8px;
}

@media(max-width:1050px){
    .create-layout{
        grid-template-columns:1fr;
    }
}

@media(max-width:680px){
    .form-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h2>Yeni Hakediş Oluştur</h2>
        <p>Sözleşmeyi seçin, dönemi belirleyin; tarihleri sistem otomatik doldursun.</p>
    </div>

    <div class="create-layout">
        <div class="form-area">
            <?php if($message): ?>
                <div class="alert"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Sözleşme</label>
                        <select name="sozlesme_id" required>
                            <option value="">Sözleşme seçiniz</option>
                            <?php foreach($sozlesmeler as $sozlesme): ?>
                                <option value="<?php echo $sozlesme['id']; ?>">
                                    <?php echo htmlspecialchars($sozlesme['firma_adi']); ?> - <?php echo htmlspecialchars($sozlesme['sozlesme_no']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>Dönem</label>
                        <input type="month" name="donem" id="donem" value="<?php echo date('Y-m'); ?>">
                        <div class="quick-months" id="quickMonths"></div>
                        <div class="hint">Örneğin Şubat 2026 seçildiğinde tarih alanları otomatik `01.02.2026 - 28.02.2026` olur.</div>
                    </div>

                    <div class="form-group">
                        <label>Başlangıç Tarihi</label>
                        <input type="date" name="baslangic_tarihi" id="baslangic_tarihi">
                    </div>

                    <div class="form-group">
                        <label>Bitiş Tarihi</label>
                        <input type="date" name="bitis_tarihi" id="bitis_tarihi">
                    </div>

                    <div class="form-group full">
                        <label>Dosya</label>
                        <input type="file" name="aktarim_dosyasi" id="aktarim_dosyasi" accept=".csv,.xlsx,.xlsm">
                        <div class="hint">Dosya yüklerseniz sistem dosyadaki ilk ve son sevk tarihini kullanabilir. Dosya sonraki aktarım ekranına hazır taşınır.</div>
                    </div>
                </div>

                <button type="submit" class="btn">Hakediş Oluştur ve Aktarıma Geç</button>
            </form>
        </div>

        <div class="helper-panel">
            <h3>Pratik Akış</h3>
            <p>En hızlı kullanım:</p>
            <ul>
                <li>Sözleşmeyi seçin.</li>
                <li>Dönemi seçin veya dosyayı yükleyin.</li>
                <li>Tarihler otomatik gelsin.</li>
                <li>Aktarım ekranında doğrudan hakedişi oluşturun.</li>
            </ul>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const donemInput = document.getElementById('donem');
    const baslangicInput = document.getElementById('baslangic_tarihi');
    const bitisInput = document.getElementById('bitis_tarihi');
    const fileInput = document.getElementById('aktarim_dosyasi');
    const quickMonths = document.getElementById('quickMonths');

    const monthNames = [
        'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'
    ];

    function fillDates(){
        if(!donemInput.value){
            return;
        }

        const parts = donemInput.value.split('-');
        const year = Number(parts[0]);
        const month = Number(parts[1]);
        const start = `${year}-${String(month).padStart(2, '0')}-01`;
        const endDate = new Date(year, month, 0);
        const end = `${endDate.getFullYear()}-${String(endDate.getMonth() + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')}`;

        baslangicInput.value = start;
        bitisInput.value = end;
    }

    function setPeriod(year, month){
        donemInput.value = `${year}-${String(month).padStart(2, '0')}`;
        fillDates();
    }

    function guessFromFileName(){
        const file = fileInput.files && fileInput.files[0];

        if(!file){
            return;
        }

        const name = file.name.toLocaleLowerCase('tr-TR');
        const yearMatch = name.match(/20\d{2}/);
        const year = yearMatch ? Number(yearMatch[0]) : new Date().getFullYear();
        const foundMonth = monthNames.findIndex(month => name.includes(month.toLocaleLowerCase('tr-TR')));

        if(foundMonth >= 0){
            setPeriod(year, foundMonth + 1);
        }
    }

    const now = new Date();
    const quickYear = now.getFullYear();
    [1, 2, 3, 4, 5, 6].forEach(month => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = `${monthNames[month - 1]} ${quickYear}`;
        button.addEventListener('click', () => setPeriod(quickYear, month));
        quickMonths.appendChild(button);
    });

    donemInput.addEventListener('input', fillDates);
    donemInput.addEventListener('change', fillDates);
    fileInput.addEventListener('change', guessFromFileName);

    fillDates();
});
</script>

</body>
</html>
