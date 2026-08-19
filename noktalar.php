<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

/* KOD ÜRET */

function kodUret($db, $tip){

    if($tip == 'cikis'){
        $prefix = 'CKS';
    }
    elseif($tip == 'varis'){
        $prefix = 'VRS';
    }
    else{
        $prefix = 'NKT';
    }

    $query = $db->prepare("
        SELECT COUNT(*) as toplam
        FROM noktalar
        WHERE tip = ?
    ");

    $query->execute([$tip]);

    $count =
        $query->fetch(PDO::FETCH_ASSOC);

    $sira =
        ($count['toplam'] ?? 0) + 1;

    return $prefix . '-' .
           str_pad($sira,3,'0',STR_PAD_LEFT);
}

/* TEKLİ EKLE */

if(isset($_POST['ekle'])){

    $nokta_adi =
        trim($_POST['nokta_adi']);

    $tip =
        $_POST['tip'];

    if(!empty($nokta_adi)){

        $kod =
            kodUret($db, $tip);

        $insert = $db->prepare("
            INSERT INTO noktalar
            (
                nokta_kodu,
                nokta_adi,
                tip
            )
            VALUES
            (?, ?, ?)
        ");

        $insert->execute([
            $kod,
            mb_strtoupper($nokta_adi,'UTF-8'),
            $tip
        ]);
    }
}

/* TOPLU EKLE */

if(isset($_POST['toplu_ekle'])){

    $toplu =
        trim($_POST['toplu_noktalar']);

    $tip =
        $_POST['toplu_tip'];

    $satirlar =
        explode("\n", $toplu);

    foreach($satirlar as $satir){

        $satir =
            trim($satir);

        if(empty($satir)){
            continue;
        }

        $kod =
            kodUret($db, $tip);

        $insert = $db->prepare("
            INSERT INTO noktalar
            (
                nokta_kodu,
                nokta_adi,
                tip
            )
            VALUES
            (?, ?, ?)
        ");

        $insert->execute([
            $kod,
            mb_strtoupper($satir,'UTF-8'),
            $tip
        ]);
    }
}

/* TOPLU SİL */

if(isset($_POST['toplu_sil'])){

    if(isset($_POST['secili'])){

        foreach($_POST['secili'] as $id){

            $delete = $db->prepare("
                DELETE FROM noktalar
                WHERE id = ?
            ");

            $delete->execute([$id]);
        }
    }

    header("Location: noktalar.php");
    exit;
}

/* TEKLİ SİL */

if(isset($_GET['sil'])){

    $id = $_GET['sil'];

    $delete = $db->prepare("
        DELETE FROM noktalar
        WHERE id = ?
    ");

    $delete->execute([$id]);

    header("Location: noktalar.php");
    exit;
}

/* LİSTE */

$query = $db->query("
    SELECT *
    FROM noktalar
    ORDER BY nokta_adi ASC
");

$noktalar = $query->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Noktalar Yönetimi</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>

.form-box{
    background:white;
    padding:25px;
    border-radius:12px;
    margin-bottom:20px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.form-row{
    display:grid;
    grid-template-columns:1fr 220px 180px;
    gap:15px;
}

textarea{
    width:100%;
    min-height:140px;
    padding:14px;
    border:1px solid #ddd;
    border-radius:8px;
    resize:vertical;
}

input,
select{
    width:100%;
    padding:14px;
    border:1px solid #ddd;
    border-radius:8px;
}

button{
    background:#16a34a;
    color:white;
    border:none;
    padding:14px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

.table-box{
    background:white;
    padding:20px;
    border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

table{
    width:100%;
    border-collapse:collapse;
}

table th{
    background:#1d3557;
    color:white;
    padding:14px;
    text-align:left;
}

table td{
    padding:14px;
    border-bottom:1px solid #eee;
}

.badge{
    padding:6px 10px;
    border-radius:20px;
    color:white;
    font-size:12px;
}

.blue{
    background:#2563eb;
}

.orange{
    background:#ea580c;
}

.green{
    background:#16a34a;
}

.red-btn{
    background:#dc2626;
    color:white;
    padding:8px 12px;
    border-radius:6px;
    text-decoration:none;
    font-size:12px;
}

.section-title{
    margin-bottom:15px;
    font-size:18px;
    font-weight:bold;
}

.toplu-sil-btn{
    background:#dc2626;
    color:white;
    border:none;
    padding:12px 18px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">

        <h2>Noktalar Yönetimi</h2>

        <p>
            Çıkış ve varış noktası tanımlama ekranı
        </p>

    </div>

    <!-- TEKLİ EKLE -->

    <div class="form-box">

        <div class="section-title">
            Tekli Nokta Ekle
        </div>

        <form method="POST">

            <div class="form-row">

                <div>

                    <input
                        type="text"
                        name="nokta_adi"
                        placeholder="Nokta Adı"
                        required
                    >

                </div>

                <div>

                    <select name="tip">

                        <option value="cikis">
                            Çıkış
                        </option>

                        <option value="varis">
                            Varış
                        </option>

                        <option value="ikisi">
                            İkisi
                        </option>

                    </select>

                </div>

                <div>

                    <button
                        type="submit"
                        name="ekle"
                    >
                        + Yeni Nokta
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- TOPLU EKLE -->

    <div class="form-box">

        <div class="section-title">
            Toplu Nokta Ekle
        </div>

        <form method="POST">

            <div style="margin-bottom:15px;">

                <textarea
                    name="toplu_noktalar"
                    placeholder="Her satıra bir nokta yazın"
                    required
                ></textarea>

            </div>

            <div class="form-row">

                <div></div>

                <div>

                    <select name="toplu_tip">

                        <option value="cikis">
                            Çıkış
                        </option>

                        <option value="varis">
                            Varış
                        </option>

                        <option value="ikisi">
                            İkisi
                        </option>

                    </select>

                </div>

                <div>

                    <button
                        type="submit"
                        name="toplu_ekle"
                    >
                        + Toplu Ekle
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- LİSTE -->

    <div class="table-box">

        <form method="POST">

        <table>

            <thead>

                <tr>

                    <th width="40">

                        <input
                            type="checkbox"
                            onclick="
                                let c=document.querySelectorAll('.secim');
                                for(let i=0;i<c.length;i++){
                                    c[i].checked=this.checked;
                                }
                            "
                        >

                    </th>

                    <th>Kod</th>
                    <th>Nokta Adı</th>
                    <th>Tip</th>
                    <th>İşlem</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($noktalar as $nokta): ?>

                <tr>

                    <td>

                        <input
                            type="checkbox"
                            name="secili[]"
                            value="<?php echo $nokta['id']; ?>"
                            class="secim"
                        >

                    </td>

                    <td>
                        <?php echo $nokta['nokta_kodu']; ?>
                    </td>

                    <td>
                        <?php echo $nokta['nokta_adi']; ?>
                    </td>

                    <td>

                        <?php if($nokta['tip']=='cikis'): ?>

                            <span class="badge blue">
                                Çıkış
                            </span>

                        <?php elseif($nokta['tip']=='varis'): ?>

                            <span class="badge orange">
                                Varış
                            </span>

                        <?php else: ?>

                            <span class="badge green">
                                İkisi
                            </span>

                        <?php endif; ?>

                    </td>

                    <td>

                        <a
                            href="noktalar.php?sil=<?php echo $nokta['id']; ?>"
                            class="red-btn"
                            onclick="return confirm('Nokta silinsin mi?');"
                        >
                            Sil
                        </a>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

        <div style="margin-top:20px;">

            <button
                type="submit"
                name="toplu_sil"
                class="toplu-sil-btn"
                onclick="return confirm('Seçilen noktalar silinsin mi?');"
            >
                Seçilenleri Sil
            </button>

        </div>

        </form>

    </div>

</div>

</body>
</html>