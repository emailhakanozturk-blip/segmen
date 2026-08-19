<?php
$activePage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$menuGroups = [
    'Genel Bakış' => [['dashboard.php', 'Dashboard']],
    'Yönetim' => [['cariler.php', 'Cariler'], ['sozlesmeler.php', 'Sözleşmeler'], ['promosyon-sozlesmeleri.php', 'Sponsorluk Sözleşmeleri'], ['tanimlar.php', 'Tanımlar'], ['nokta-yonetimi.php', 'Nokta Yönetimi']],
    'Operasyon' => [['hakedisler.php', 'Hakedişler'], ['motorin-yukle.php', 'Motorin Fiyatları'], ['demirbas-takip.php', 'Demirbaş Takip'], ['palet-takip.php', 'Palet Takip'], ['recete-maliyet.php', 'Reçete ve Maliyet'], ['gorev-takip.php', 'Görev Takip']],
    'Sistem' => [['raporlar.php', 'Raporlar'], ['log-kayitlari.php', 'Log Kayıtları'], ['kullanici-yonetimi.php', 'Ayarlar']],
];
?>
<aside class="sidebar" aria-label="Ana menü">
    <a class="brand" href="dashboard.php" aria-label="Seğmen Su ana sayfa">
        <span class="brand-name">Seğmen Su</span>
        <span class="brand-description">Hakediş ve operasyon</span>
    </a>

    <nav class="menu">
        <?php foreach($menuGroups as $groupTitle => $items): ?>
            <section class="menu-group" aria-label="<?= htmlspecialchars($groupTitle) ?>">
                <p class="menu-label"><?= htmlspecialchars($groupTitle) ?></p>
                <?php foreach($items as [$href, $label]): ?>
                    <a href="<?= $href ?>" class="<?= $activePage === $href ? 'is-active' : '' ?>"<?= $activePage === $href ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <span class="footer-label">Oturum</span>
        <a class="logout-link" href="logout.php" onclick="return confirm('Çıkış yapılsın mı?');">Güvenli Çıkış</a>
    </div>
</aside>
