<?php

session_start();

require_once __DIR__ . '/config/database.php';

$error = '';

if($_SERVER['REQUEST_METHOD'] == 'POST'){

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $query = $db->prepare("SELECT * FROM users WHERE email = ?");
    $query->execute([$email]);

    $user = $query->fetch(PDO::FETCH_ASSOC);

    // GEÇİCİ TEST GİRİŞİ
    if($user && trim($password) == trim($user['password'])){

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];

        header("Location: dashboard.php");
        exit;

    }else{

        $error = 'E-posta veya şifre hatalı';

    }

}

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Kurumsal Su Yönetim Sistemi</title>

<style>

body{

    margin:0;
    padding:0;

    background:#f4f7fb;

    font-family:Arial, Helvetica, sans-serif;

    display:flex;
    justify-content:center;
    align-items:center;

    height:100vh;

}

.login-box{

    width:420px;

    background:#ffffff;

    padding:50px;

    border-radius:20px;

    box-shadow:0 10px 30px rgba(0,0,0,0.08);

}

h1{

    text-align:center;

    color:#1d3557;

    margin-bottom:40px;

    font-size:32px;

}

input{

    width:100%;

    padding:16px;

    margin-bottom:20px;

    border:1px solid #dcdcdc;

    border-radius:10px;

    font-size:18px;

    box-sizing:border-box;

}

button{

    width:100%;

    padding:16px;

    border:none;

    border-radius:10px;

    background:#1d3557;

    color:white;

    font-size:20px;

    cursor:pointer;

}

button:hover{

    background:#16324f;

}

.error{

    background:#ffdede;

    color:#c00000;

    padding:15px;

    border-radius:10px;

    margin-bottom:20px;

    font-size:16px;

}

</style>

</head>

<body>

<div class="login-box">

    <h1>Kurumsal Su Yönetim Sistemi</h1>

    <?php if($error): ?>

        <div class="error">
            <?php echo $error; ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <input 
            type="email" 
            name="email" 
            placeholder="E-posta"
            required
        >

        <input 
            type="password" 
            name="password" 
            placeholder="Şifre"
            required
        >

        <button type="submit">
            Giriş Yap
        </button>

    </form>

</div>

</body>
</html>