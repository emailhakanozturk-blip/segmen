<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';

if(isset($_FILES['excel'])){

    $dosya = $_FILES['excel']['name'];

    $tmp = $_FILES['excel']['tmp_name'];

    $hedef = 'uploads/' . time() . '_' . $dosya;

    move_uploaded_file($tmp, $hedef);

    $message = 'Excel başarıyla yüklendi';

}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Excel Yükleme</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>

.upload-box{
    background:white;
    padding:35px;
    border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.form-group{
    margin-bottom:20px;
}

.form-group label{
    display:block;
    margin-bottom:10px;
    font-weight:bold;
}

.form-group input{
    width:100%;
    padding:14px;
    border:1px solid #ddd;
    border-radius:8px;
}

.btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:14px 20px;
    border-radius:8px;
    cursor:pointer;
}

.alert{
    background:#dcfce7;
    color:#166534;
    padding:14px;
    border-radius:10px;
    margin-bottom:20px;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Excel Hakediş Yükleme</h2>

        <p>
            Hakediş excel dosyası yükleme ekranı
        </p>

    </div>

    <div class="upload-box">

        <?php if($message): ?>

            <div class="alert">
                <?php echo $message; ?>
            </div>

        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <div class="form-group">

                <label>Excel Dosyası</label>

                <input
                    type="file"
                    name="excel"
                    required
                >

            </div>

            <button type="submit" class="btn">
                Excel Yükle
            </button>

        </form>

    </div>

</div>

</body>
</html>