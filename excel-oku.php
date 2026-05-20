<?php

$rows = [];

if(isset($_FILES['csv'])){

    $tmp = $_FILES['csv']['tmp_name'];

    if(($file = fopen($tmp, 'r')) !== FALSE){

        while(($data = fgetcsv($file, 1000, ";")) !== FALSE){

            $rows[] = $data;

        }

        fclose($file);

    }

}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>CSV Oku</title>

<style>

body{
    font-family:Arial;
    background:#f4f7fb;
    padding:30px;
}

.box{
    background:white;
    padding:25px;
    border-radius:10px;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}

table td{
    border:1px solid #ddd;
    padding:8px;
    font-size:12px;
}

button{
    background:#16a34a;
    color:white;
    border:none;
    padding:12px 18px;
    border-radius:8px;
    cursor:pointer;
}

</style>

</head>
<body>

<div class="box">

<form method="POST" enctype="multipart/form-data">

    <input type="file" name="csv" required>

    <button type="submit">

        CSV Oku

    </button>

</form>

<?php if($rows): ?>

<table>

<?php foreach($rows as $row): ?>

<tr>

<?php foreach($row as $col): ?>

<td>

<?php echo htmlspecialchars($col); ?>

</td>

<?php endforeach; ?>

</tr>

<?php endforeach; ?>

</table>

<?php endif; ?>

</div>

</body>
</html>