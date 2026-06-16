<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/config/database.php';

function parseMoney(string $value): float
{
    $value = preg_replace('/[^0-9,.-]/u', '', $value);
    if(str_contains($value, ',')){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

$rows = $db->query("SELECT id,demirbas_adi,aciklama FROM demirbas_tanimlari WHERE toplam_tutar=0")->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
$db->beginTransaction();
try {
    $query = $db->prepare("UPDATE demirbas_tanimlari SET demirbas_adi=?,birim_tutar=?,toplam_tutar=? WHERE id=?");
    foreach($rows as $row){
        $name = (string)$row['demirbas_adi'];
        $amount = 0;
        if(preg_match('/\s(\d{1,3}(?:\.\d{3})*,\d{2})$/u', $name, $match)){
            $amount = parseMoney($match[1]);
            $name = trim(substr($name, 0, -strlen($match[0])));
        } elseif(preg_match('/muhasebe liste değeri:\s*₺([0-9.,]+)/ui', (string)$row['aciklama'], $match)){
            $amount = parseMoney($match[1]);
        }
        if($amount > 0){
            $query->execute([$name, $amount, $amount, (int)$row['id']]);
            $updated++;
        }
    }
    $db->commit();
} catch(Throwable $e){
    $db->rollBack();
    throw $e;
}
echo "DUZELTILEN={$updated}\n";
echo "KALAN_SIFIR=" . $db->query("SELECT COUNT(*) FROM demirbas_tanimlari WHERE toplam_tutar=0")->fetchColumn() . "\n";
