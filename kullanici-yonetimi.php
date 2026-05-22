<?php

session_start();

require_once __DIR__ . '/config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$message = '';
$error = '';
$editing = null;

function post_value(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function redirect_users(): void
{
    header("Location: kullanici-yonetimi.php");
    exit;
}

$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(180) NOT NULL UNIQUE,
        password VARCHAR(180) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$columns = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('created_at', $columns, true)){
    $db->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';

    try {
        if($action === 'save'){
            $id = (int)($_POST['id'] ?? 0);
            $name = post_value('name');
            $email = post_value('email');
            $password = post_value('password');

            if($name === '' || $email === ''){
                throw new Exception('Ad soyad ve e-posta zorunludur.');
            }

            if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                throw new Exception('Geçerli bir e-posta adresi girin.');
            }

            if($id > 0){
                if($password !== ''){
                    $query = $db->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
                    $query->execute([$name, $email, $password, $id]);
                }else{
                    $query = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    $query->execute([$name, $email, $id]);
                }

                if((int)$_SESSION['user_id'] === $id){
                    $_SESSION['user_name'] = $name;
                }
            }else{
                if($password === ''){
                    throw new Exception('Yeni kullanıcı için şifre zorunludur.');
                }

                $query = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                $query->execute([$name, $email, $password]);
            }

            redirect_users();
        }

        if($action === 'delete'){
            $id = (int)($_POST['id'] ?? 0);

            if($id <= 0){
                throw new Exception('Kullanıcı seçilemedi.');
            }

            if((int)$_SESSION['user_id'] === $id){
                throw new Exception('Açık oturumdaki kullanıcı silinemez.');
            }

            $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if($userCount <= 1){
                throw new Exception('Sistemde en az bir kullanıcı kalmalıdır.');
            }

            $query = $db->prepare("DELETE FROM users WHERE id = ?");
            $query->execute([$id]);
            redirect_users();
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if($editId > 0){
    $query = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $query->execute([$editId]);
    $editing = $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

$users = $db->query("SELECT id, name, email, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Kullanıcı Yönetimi</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260522-logo-total">

<style>

.settings-grid{
    display:grid;
    grid-template-columns:minmax(280px, 380px) 1fr;
    gap:22px;
    align-items:start;
}

.panel{
    background:white;
    padding:24px;
    border-radius:15px;
    box-shadow:0 5px 15px rgba(0,0,0,.05);
}

.panel h3{
    margin-bottom:18px;
    color:#1d3557;
}

.form-group{
    margin-bottom:14px;
}

.form-group label{
    display:block;
    font-weight:bold;
    margin-bottom:6px;
    color:#374151;
}

.form-control{
    width:100%;
    padding:12px 13px;
    border:1px solid #dcdcdc;
    border-radius:8px;
    font-size:14px;
}

.form-hint{
    color:#6b7280;
    font-size:12px;
    margin-top:5px;
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:18px;
}

.btn{
    border:0;
    background:#16a34a;
    color:white;
    padding:11px 16px;
    border-radius:8px;
    text-decoration:none;
    display:inline-block;
    cursor:pointer;
    font-size:14px;
}

.btn-secondary{
    background:#64748b;
}

.btn-blue{
    background:#2563eb;
}

.btn-red{
    background:#dc2626;
}

table{
    width:100%;
    border-collapse:collapse;
}

table th{
    background:#1d3557;
    color:white;
    padding:13px;
    text-align:left;
}

table td{
    padding:13px;
    border-bottom:1px solid #eee;
    vertical-align:middle;
}

.inline-form{
    display:inline;
}

.notice{
    padding:12px 14px;
    border-radius:10px;
    margin-bottom:16px;
}

.notice-error{
    background:#fee2e2;
    color:#991b1b;
}

@media(max-width:900px){
    .settings-grid{
        grid-template-columns:1fr;
    }
}

</style>

</head>
<body>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main">

    <div class="topbar">
        <h2>Kullanıcı Yönetimi</h2>
        <p>Sisteme giriş yapacak kullanıcıları ekleyin, düzenleyin ve pasif kayıtları silin.</p>
    </div>

    <?php if($error): ?>
        <div class="notice notice-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="settings-grid">

        <div class="panel">
            <h3><?php echo $editing ? 'Kullanıcı Düzenle' : 'Yeni Kullanıcı'; ?></h3>

            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">

                <div class="form-group">
                    <label>Ad Soyad</label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars((string)($editing['name'] ?? '')); ?>" required>
                </div>

                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars((string)($editing['email'] ?? '')); ?>" required>
                </div>

                <div class="form-group">
                    <label>Şifre</label>
                    <input type="text" name="password" class="form-control" value="">
                    <?php if($editing): ?>
                        <div class="form-hint">Boş bırakırsanız mevcut şifre değişmez.</div>
                    <?php else: ?>
                        <div class="form-hint">Yeni kullanıcı için şifre zorunludur.</div>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <button type="submit" class="btn"><?php echo $editing ? 'Güncelle' : 'Kaydet'; ?></button>
                    <?php if($editing): ?>
                        <a href="kullanici-yonetimi.php" class="btn btn-secondary">Vazgeç</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="panel">
            <h3>Kullanıcılar</h3>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ad Soyad</th>
                        <th>E-posta</th>
                        <th>Kayıt Tarihi</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $user): ?>
                        <tr>
                            <td><?php echo (int)$user['id']; ?></td>
                            <td><?php echo htmlspecialchars((string)$user['name']); ?></td>
                            <td><?php echo htmlspecialchars((string)$user['email']); ?></td>
                            <td><?php echo htmlspecialchars((string)($user['created_at'] ?? '-')); ?></td>
                            <td>
                                <a href="kullanici-yonetimi.php?edit=<?php echo (int)$user['id']; ?>" class="btn btn-blue">Düzenle</a>

                                <?php if((int)$_SESSION['user_id'] !== (int)$user['id']): ?>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Bu kullanıcı silinsin mi?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                        <button type="submit" class="btn btn-red">Sil</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

</body>
</html>
