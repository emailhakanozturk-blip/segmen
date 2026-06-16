<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/config/database.php';

function sourceRows(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    array_shift($lines);
    return array_map(static fn(string $line): array => explode('|', $line), $lines);
}

function ocrCurrency(string $value): float
{
    $value = trim($value);
    if($value !== '' && $value[0] === '5'){
        $value = substr($value, 1);
    }
    $value = preg_replace('/[^0-9,.-]/u', '', $value);
    if(str_contains($value, ',')){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return max(0, (float)$value);
}

$rows = sourceRows(__DIR__ . '/demirbas_liste2.psv');
$update = $db->prepare("UPDATE demirbas_tanimlari SET demirbas_adi=?,birim_tutar=?,toplam_tutar=? WHERE hesap_kodu=?");
$updated = 0;
$db->beginTransaction();
try {
    foreach($rows as $row){
        $code = trim((string)($row[0] ?? ''));
        $name = trim((string)($row[1] ?? ''));
        $rawAmount = trim((string)($row[2] ?? ''));
        if($rawAmount === '' && preg_match('/\s(5\d{1,3}(?:\.\d{3})*,\d{2})$/u', $name, $match)){
            $rawAmount = $match[1];
            $name = trim(substr($name, 0, -strlen($match[0])));
        }
        $amount = ocrCurrency($rawAmount);
        if($code !== '' && $amount > 0){
            $update->execute([$name, $amount, $amount, $code]);
            $updated += $update->rowCount();
        }
    }
    $db->commit();
} catch(Throwable $e){
    $db->rollBack();
    throw $e;
}
echo "YENIDEN_HESAPLANAN={$updated}\n";
echo "KALAN_SIFIR=" . $db->query("SELECT COUNT(*) FROM demirbas_tanimlari WHERE toplam_tutar=0")->fetchColumn() . "\n";
echo "TOPLAM=" . $db->query("SELECT SUM(toplam_tutar) FROM demirbas_tanimlari")->fetchColumn() . "\n";
