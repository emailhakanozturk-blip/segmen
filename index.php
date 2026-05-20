<?php

require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html lang="tr">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Kurumsal Su Yönetim Sistemi</title>

    <style>

        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
        }

        body{

            font-family:Arial, Helvetica, sans-serif;
            background:#f4f7fb;
            color:#333;
        }

        .container{

            width:100%;
            min-height:100vh;
            display:flex;
            justify-content:center;
            align-items:center;
        }

        .card{

            width:700px;
            background:#fff;
            border-radius:15px;
            padding:50px;
            box-shadow:0 10px 35px rgba(0,0,0,0.10);
            text-align:center;
        }

        h1{

            font-size:36px;
            margin-bottom:15px;
            color:#1d3557;
        }

        p{

            font-size:18px;
            color:#666;
            margin-bottom:30px;
        }

        .status{

            display:inline-block;
            padding:12px 25px;
            background:#27ae60;
            color:#fff;
            border-radius:8px;
            font-size:16px;
        }

    </style>

</head>
<body>

<div class="container">

    <div class="card">

        <h1>Kurumsal Su Yönetim Sistemi</h1>

        <p>
            Nakliye Hakediş, Motorin Fiyat Farkı,
            Muayene Kabul ve Raporlama Sistemi
        </p>

        <div class="status">
            Sistem Başarıyla Kuruldu
        </div>

    </div>

</div>

</body>
</html>