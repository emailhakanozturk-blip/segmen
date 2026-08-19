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

$pageOptions = [
    'dashboard.php' => 'Dashboard',
    'cariler.php' => 'Cariler',
    'sozlesmeler.php' => 'Sözleşmeler',
    'promosyon-sozlesmeleri.php' => 'Sponsorluk Sözleşmeleri',
    'tanimlar.php' => 'Tanımlar',
    'nokta-yonetimi.php' => 'Nokta Yönetimi',
    'hakedisler.php' => 'Hakedişler',
    'motorin-yukle.php' => 'Motorin Fiyatları',
    'raporlar.php' => 'Raporlar',
    'demirbas-takip.php' => 'Demirbaş Takip',
    'gorev-takip.php' => 'Görev Takip',
    'log-kayitlari.php' => 'Log Kayıtları',
    'kullanici-yonetimi.php' => 'Ayarlar',
];

$writePageMap = [
    'cariler.php' => ['cariler.php', 'cari-ekle.php', 'cari-duzenle.php', 'cari-sil.php'],
    'sozlesmeler.php' => ['sozlesmeler.php', 'sozlesme-ekle.php', 'sozlesme-duzenle.php', 'sozlesme-sil.php'],
    'promosyon-sozlesmeleri.php' => ['promosyon-sozlesmeleri.php'],
    'tanimlar.php' => ['tanimlar.php'],
    'nokta-yonetimi.php' => ['nokta-yonetimi.php', 'noktalar.php', 'tarifeler.php', 'tarife-ekle.php', 'tarife-yukle.php'],
    'hakedisler.php' => ['hakedisler.php', 'hakedis-ekle.php', 'hakedis-olustur.php', 'hakedis-onayla.php', 'hakedis-sil.php', 'excel-eslestir.php', 'excel-yukle.php'],
    'motorin-yukle.php' => ['motorin-yukle.php'],
    'demirbas-takip.php' => ['demirbas-takip.php'],
    'gorev-takip.php' => ['gorev-takip.php'],
    'kullanici-yonetimi.php' => ['kullanici-yonetimi.php'],
];

function selected_pages(array $source, array $allowed): array
{
    return array_values(array_intersect(array_keys($allowed), $source));
}

function expand_edit_pages(array $selected, array $map): array
{
    $pages = [];
    foreach($selected as $page){
        foreach(($map[$page] ?? [$page]) as $mappedPage){
            $pages[] = $mappedPage;
        }
    }
    return array_values(array_unique($pages));
}

$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(180) NOT NULL UNIQUE,
        password VARCHAR(180) NOT NULL,
        can_view TINYINT(1) NOT NULL DEFAULT 1,
        can_edit TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$columns = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('can_view', $columns, true)){
    $db->exec("ALTER TABLE users ADD COLUMN can_view TINYINT(1) NOT NULL DEFAULT 1 AFTER password");
}
if(!in_array('can_edit', $columns, true)){
    $db->exec("ALTER TABLE users ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 1 AFTER can_view");
}
if(!in_array('allowed_pages', $columns, true)){
    $db->exec("ALTER TABLE users ADD COLUMN allowed_pages TEXT NULL AFTER can_edit");
}
if(!in_array('editable_pages', $columns, true)){
    $db->exec("ALTER TABLE users ADD COLUMN editable_pages TEXT NULL AFTER allowed_pages");
}
if(!in_array('created_at', $columns, true)){
    $db->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
}

$currentUserQuery = $db->prepare("SELECT can_edit, editable_pages FROM users WHERE id = ?");
$currentUserQuery->execute([(int)$_SESSION['user_id']]);
$currentUser = $currentUserQuery->fetch(PDO::FETCH_ASSOC) ?: ['can_edit' => 1, 'editable_pages' => ''];
$currentEditPages = json_decode((string)($currentUser['editable_pages'] ?? ''), true);
$currentEditPages = is_array($currentEditPages) ? $currentEditPages : [];
$canEditUsers = (int)($currentUser['can_edit'] ?? 1) === 1 || in_array('kullanici-yonetimi.php', $currentEditPages, true);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = $_POST['action'] ?? '';

    try {
        if($action === 'save'){
            if(!$canEditUsers){
                throw new Exception('Kullanıcı düzenleme yetkiniz yok.');
            }

            $id = (int)($_POST['id'] ?? 0);
            $name = post_value('name');
            $email = post_value('email');
            $password = post_value('password');
            $canView = isset($_POST['can_view']) ? 1 : 0;
            $canEdit = isset($_POST['can_edit']) ? 1 : 0;
            $viewPages = selected_pages($_POST['allowed_pages'] ?? [], $pageOptions);
            $editGroups = selected_pages($_POST['editable_groups'] ?? [], $pageOptions);
            $editablePages = expand_edit_pages($editGroups, $writePageMap);
            $allowedPagesJson = empty($viewPages) ? null : json_encode($viewPages, JSON_UNESCAPED_UNICODE);
            $editablePagesJson = empty($editablePages) ? null : json_encode($editablePages, JSON_UNESCAPED_UNICODE);

            if($name === '' || $email === ''){
                throw new Exception('Ad soyad ve e-posta zorunludur.');
            }

            if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                throw new Exception('Geçerli bir e-posta adresi girin.');
            }

            if($id > 0){
                if((int)$_SESSION['user_id'] === $id && $canView === 0){
                    throw new Exception('Kendi görüntüleme yetkinizi kapatamazsınız.');
                }

                if($password !== ''){
                    $query = $db->prepare("UPDATE users SET name = ?, email = ?, password = ?, can_view = ?, can_edit = ?, allowed_pages = ?, editable_pages = ? WHERE id = ?");
                    $query->execute([$name, $email, $password, $canView, $canEdit, $allowedPagesJson, $editablePagesJson, $id]);
                }else{
                    $query = $db->prepare("UPDATE users SET name = ?, email = ?, can_view = ?, can_edit = ?, allowed_pages = ?, editable_pages = ? WHERE id = ?");
                    $query->execute([$name, $email, $canView, $canEdit, $allowedPagesJson, $editablePagesJson, $id]);
                }

                if((int)$_SESSION['user_id'] === $id){
                    $_SESSION['user_name'] = $name;
                    $_SESSION['can_view'] = $canView;
                    $_SESSION['can_edit'] = $canEdit;
                    $_SESSION['allowed_pages'] = (string)$allowedPagesJson;
                    $_SESSION['editable_pages'] = (string)$editablePagesJson;
                }
            }else{
                if($password === ''){
                    throw new Exception('Yeni kullanıcı için şifre zorunludur.');
                }

                $query = $db->prepare("INSERT INTO users (name, email, password, can_view, can_edit, allowed_pages, editable_pages) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $query->execute([$name, $email, $password, $canView, $canEdit, $allowedPagesJson, $editablePagesJson]);
            }

            redirect_users();
        }

        if($action === 'delete'){
            if(!$canEditUsers){
                throw new Exception('Kullanıcı silme yetkiniz yok.');
            }

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
    $query = $db->prepare("SELECT id, name, email, can_view, can_edit, allowed_pages, editable_pages FROM users WHERE id = ?");
    $query->execute([$editId]);
    $editing = $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

$users = $db->query("SELECT id, name, email, can_view, can_edit, allowed_pages, editable_pages, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$editingAllowedPages = json_decode((string)($editing['allowed_pages'] ?? ''), true);
$editingEditablePages = json_decode((string)($editing['editable_pages'] ?? ''), true);
$editingAllowedPages = is_array($editingAllowedPages) ? $editingAllowedPages : [];
$editingEditablePages = is_array($editingEditablePages) ? $editingEditablePages : [];

?>

<!DOCTYPE html>
<html lang="tr">
<head>

<meta charset="UTF-8">

<title>Kullanıcı Yönetimi</title>

<link rel="stylesheet" href="assets/css/style.css?v=20260819-sidebar-v3">

<style>

.settings-grid{
    display:grid;
    grid-template-columns:minmax(230px, 290px) minmax(0, 1fr);
    gap:16px;
    align-items:start;
}

.panel{
    background:white;
    padding:18px;
    border-radius:12px;
    border:1px solid #e7eaf0;
    box-shadow:0 8px 24px rgba(15,23,42,.04);
    overflow:hidden;
}

.panel h3{
    margin-bottom:14px;
    color:#1d3557;
    font-size:18px;
}

.form-group{
    margin-bottom:11px;
}

.form-group label{
    display:block;
    font-weight:bold;
    margin-bottom:5px;
    color:#374151;
    font-size:13px;
}

.form-control{
    width:100%;
    padding:10px 11px;
    border:1px solid #dcdcdc;
    border-radius:8px;
    font-size:13px;
}

.form-hint{
    color:#6b7280;
    font-size:12px;
    margin-top:5px;
}

.permission-box{
    display:grid;
    gap:7px;
    padding:10px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    background:#f9fafb;
}

.permission-box label{
    display:flex;
    align-items:center;
    gap:8px;
    margin:0;
    font-weight:600;
}

.permission-box input{
    width:16px;
    height:16px;
}

.page-permissions{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
}

.page-permissions label{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    margin:0;
    font-weight:600;
}

.permission-badge{
    display:inline-block;
    padding:4px 7px;
    border-radius:20px;
    background:#e0f2fe;
    color:#075985;
    font-size:11px;
    font-weight:bold;
    margin-right:4px;
    margin-bottom:4px;
}

.permission-badge.muted{
    background:#f3f4f6;
    color:#6b7280;
}

.actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    margin-top:14px;
}

.btn{
    border:0;
    background:#16a34a;
    color:white;
    padding:8px 11px;
    border-radius:8px;
    text-decoration:none;
    display:inline-block;
    cursor:pointer;
    font-size:12px;
    font-weight:700;
    line-height:1.2;
    white-space:nowrap;
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
    table-layout:fixed;
}

.table-wrap{
    width:100%;
    max-width:100%;
    overflow:auto;
    border-radius:10px;
    border:1px solid #e7eaf0;
}

table th{
    background:#1d3557;
    color:white;
    padding:9px 10px;
    text-align:left;
    font-size:11px;
    white-space:nowrap;
}

table td{
    padding:8px 9px;
    border-bottom:1px solid #eee;
    vertical-align:middle;
    font-size:11px;
}

th:nth-child(1),
td:nth-child(1){
    width:40px;
}

th:nth-child(2),
td:nth-child(2){
    width:16%;
}

th:nth-child(3),
td:nth-child(3){
    width:25%;
}

th:nth-child(4),
td:nth-child(4){
    width:24%;
}

th:nth-child(5),
td:nth-child(5){
    width:17%;
}

th:nth-child(6),
td:nth-child(6){
    width:110px;
}

td:nth-child(2),
td:nth-child(3){
    word-break:break-word;
}

td:last-child{
    white-space:nowrap;
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

    <?php if(!$canEditUsers): ?>
        <div class="notice notice-error">Bu kullanıcıyla sadece görüntüleme yapabilirsiniz. Kullanıcı ekleme, düzeltme ve silme kapalıdır.</div>
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

                <div class="form-group">
                    <label>Yetkiler</label>
                    <div class="permission-box">
                        <label>
                            <input type="checkbox" name="can_view" value="1" <?php echo (int)($editing['can_view'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            Görüntüle
                        </label>
                        <label>
                            <input type="checkbox" name="can_edit" value="1" <?php echo (int)($editing['can_edit'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            Düzelt / Ekle / Sil
                        </label>
                    </div>
                    <div class="form-hint">Görüntüle kapalıysa kullanıcı giriş yapamaz.</div>
                </div>

                <div class="form-group">
                    <label>Görüntüleyebileceği Sayfalar</label>
                    <div class="permission-box page-permissions">
                        <?php foreach($pageOptions as $page => $label): ?>
                            <label>
                                <input type="checkbox" name="allowed_pages[]" value="<?php echo htmlspecialchars($page); ?>" <?php echo empty($editingAllowedPages) || in_array($page, $editingAllowedPages, true) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-hint">Seçili sayfaları görüntüler. Hepsi seçiliyse tüm sayfalar açıktır.</div>
                </div>

                <div class="form-group">
                    <label>İşlem Yapabileceği Alanlar</label>
                    <div class="permission-box page-permissions">
                        <?php foreach($pageOptions as $page => $label): ?>
                            <?php if($page === 'dashboard.php' || $page === 'raporlar.php'){ continue; } ?>
                            <?php $groupPages = $writePageMap[$page] ?? [$page]; ?>
                            <label>
                                <input type="checkbox" name="editable_groups[]" value="<?php echo htmlspecialchars($page); ?>" <?php echo count(array_intersect($groupPages, $editingEditablePages)) > 0 ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-hint">Genel düzeltme kapalı olsa bile seçili alanlarda ekle, düzelt ve sil çalışır.</div>
                </div>

                <div class="actions">
                    <?php if($canEditUsers): ?>
                        <button type="submit" class="btn"><?php echo $editing ? 'Güncelle' : 'Kaydet'; ?></button>
                    <?php endif; ?>
                    <?php if($editing): ?>
                        <a href="kullanici-yonetimi.php" class="btn btn-secondary">Vazgeç</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="panel">
            <h3>Kullanıcılar</h3>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ad Soyad</th>
                            <th>E-posta</th>
                            <th>Yetki</th>
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
                                <td>
                                    <span class="permission-badge <?php echo (int)$user['can_view'] === 1 ? '' : 'muted'; ?>">Görüntüle: <?php echo (int)$user['can_view'] === 1 ? 'Açık' : 'Kapalı'; ?></span>
                                    <span class="permission-badge <?php echo (int)$user['can_edit'] === 1 ? '' : 'muted'; ?>">Düzelt: <?php echo (int)$user['can_edit'] === 1 ? 'Açık' : 'Kapalı'; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars((string)($user['created_at'] ?? '-')); ?></td>
                                <td>
                                    <?php if($canEditUsers): ?>
                                        <a href="kullanici-yonetimi.php?edit=<?php echo (int)$user['id']; ?>" class="btn btn-blue">Düzenle</a>
                                    <?php endif; ?>

                                    <?php if($canEditUsers && (int)$_SESSION['user_id'] !== (int)$user['id']): ?>
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

</div>

</body>
</html>
