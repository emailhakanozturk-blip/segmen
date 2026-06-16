<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/config/database.php';

function readRows(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    array_shift($lines);
    return array_map(static fn(string $line): array => explode('|', $line), $lines);
}

function normalizeName(string $value): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    $value = strtr($value, ['İ'=>'I','Ş'=>'S','Ğ'=>'G','Ü'=>'U','Ö'=>'O','Ç'=>'C','Â'=>'A','_'=>' ']);
    $value = preg_replace('/\([^)]*\)/u', ' ', $value);
    $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value);
    return preg_replace('/\s+/', ' ', trim($value));
}

function similarityScore(string $left, string $right): float
{
    $left = normalizeName($left);
    $right = normalizeName($right);
    if($left === $right){
        return 1.0;
    }
    if($left === '' || $right === ''){
        return 0.0;
    }
    if(str_contains($left, $right) || str_contains($right, $left)){
        return (min(strlen($left), strlen($right)) / max(strlen($left), strlen($right))) * 0.95;
    }
    $length = max(strlen($left), strlen($right));
    $levenshtein = $length ? 1 - (levenshtein($left, $right) / $length) : 0;
    $leftTokens = array_unique(explode(' ', $left));
    $rightTokens = array_unique(explode(' ', $right));
    $intersection = count(array_intersect($leftTokens, $rightTokens));
    $union = count(array_unique(array_merge($leftTokens, $rightTokens)));
    $tokenScore = $union ? ($intersection / $union) * 0.95 : 0;
    return max($levenshtein, $tokenScore);
}

function numberValue(string $value): float
{
    $value = preg_replace('/[^0-9,.-]/u', '', $value);
    if(str_contains($value, ',')){
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return max(0, (float)$value);
}

function sqlDate(string $value): ?string
{
    $date = DateTime::createFromFormat('!d.m.Y', trim($value));
    return $date ? $date->format('Y-m-d') : null;
}

function categoryName(string $name): string
{
    $name = normalizeName($name);
    if(preg_match('/FORKLIFT|KAMYON|ARAC|OTOMOBIL|MINIBUS/', $name)) return 'Araç';
    if(preg_match('/TANK|DEPO/', $name)) return 'Tank / Depo';
    if(preg_match('/MAKINA|POMPA|MOTOR|KOMPRESOR|JENERATOR|UNITESI|KONVEYOR/', $name)) return 'Makine / Ekipman';
    if(preg_match('/BAKIM|ONARIM|YEDEK PARCA/', $name)) return 'Bakım / Yedek Parça';
    return 'Demirbaş';
}

$listOne = readRows(__DIR__ . '/demirbas_liste1.psv');
$listTwo = readRows(__DIR__ . '/demirbas_liste2.psv');

$candidates = [];
foreach($listOne as $oneIndex => $one){
    foreach($listTwo as $twoIndex => $two){
        $score = similarityScore($one[1] ?? '', $two[1] ?? '');
        if($score >= 0.75){
            $candidates[] = [$score, $oneIndex, $twoIndex];
        }
    }
}
usort($candidates, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

$oneToTwo = [];
$twoToOne = [];
foreach($candidates as [$score, $oneIndex, $twoIndex]){
    if(isset($oneToTwo[$oneIndex]) || isset($twoToOne[$twoIndex])) continue;
    $oneToTwo[$oneIndex] = $twoIndex;
    $twoToOne[$twoIndex] = $oneIndex;
}

$exists = $db->prepare("SELECT id FROM demirbas_tanimlari WHERE hesap_kodu=? OR (demirbas_kodu<>'' AND demirbas_kodu=?) LIMIT 1");
$insert = $db->prepare("INSERT INTO demirbas_tanimlari (hesap_kodu,demirbas_kodu,demirbas_adi,kategori,alis_tarihi,adet,birim_tutar,toplam_tutar,kullanim_yeri,zimmetli_kisi,durum,aciklama) VALUES (?,?,?,?,?,1,?,?,?,?,?,?)");

$insertedMain = 0;
$insertedUnmatched = 0;
$skipped = 0;
$db->beginTransaction();
try {
    foreach($listTwo as $index => $row){
        $code = trim((string)($row[0] ?? ''));
        $name = trim((string)($row[1] ?? ''));
        $amount = numberValue((string)($row[2] ?? ''));
        $date = sqlDate((string)($row[3] ?? ''));
        $location = trim((string)($row[4] ?? ''));
        if($code === '' || $name === '') continue;

        $matched = isset($twoToOne[$index]) ? $listOne[$twoToOne[$index]] : null;
        $secondaryCode = trim((string)($matched[0] ?? ''));
        $accountAmount = $matched ? numberValue((string)($matched[3] ?? '')) : 0;
        $description = 'Detaylı demirbaş listesinden aktarıldı.';
        if($matched){
            $description .= ' Muhasebe listesiyle eşleşti';
            if($accountAmount > 0){
                $description .= '; muhasebe liste değeri: ₺' . number_format($accountAmount, 2, ',', '.');
            }
            $description .= '.';
        }
        $status = mb_strtoupper($location, 'UTF-8') === 'HURDA' ? 'Hurda' : 'Aktif';
        $exists->execute([$code, $secondaryCode]);
        if($exists->fetchColumn()){
            $skipped++;
            continue;
        }
        $insert->execute([$code, $secondaryCode, $name, categoryName($name), $date, $amount, $amount, $location, '', $status, $description]);
        $insertedMain++;
    }

    foreach($listOne as $index => $row){
        if(isset($oneToTwo[$index])) continue;
        $code = trim((string)($row[0] ?? ''));
        $name = trim((string)($row[1] ?? ''));
        $amount = numberValue((string)($row[3] ?? ''));
        if($code === '' || $name === '') continue;
        $exists->execute([$code, $code]);
        if($exists->fetchColumn()){
            $skipped++;
            continue;
        }
        $insert->execute([$code, '', $name, categoryName($name), null, $amount, $amount, '', '', 'Aktif', 'Muhasebe demirbaş listesinden aktarıldı; detaylı listede güvenilir eşleşme bulunamadı.']);
        $insertedUnmatched++;
    }
    $db->commit();
} catch(Throwable $e){
    $db->rollBack();
    throw $e;
}

$summary = $db->query("SELECT COUNT(*) kayit,COALESCE(SUM(toplam_tutar),0) toplam,SUM(CASE WHEN demirbas_kodu<>'' THEN 1 ELSE 0 END) eslesen FROM demirbas_tanimlari")->fetch(PDO::FETCH_ASSOC);
echo "ESLESEN=" . count($oneToTwo) . PHP_EOL;
echo "DETAY_LISTE_EKLENEN={$insertedMain}" . PHP_EOL;
echo "MUHASEBE_ESLESMEYEN_EKLENEN={$insertedUnmatched}" . PHP_EOL;
echo "ATLANAN={$skipped}" . PHP_EOL;
echo "TOPLAM_KAYIT={$summary['kayit']}" . PHP_EOL;
echo "TOPLAM_DEGER={$summary['toplam']}" . PHP_EOL;
