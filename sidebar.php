<?php
$activePage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$menuItems = [
    ['dashboard.php', 'Dashboard', 'grid'],
    ['cariler.php', 'Cariler', 'building'],
    ['sozlesmeler.php', 'Sözleşmeler', 'file'],
    ['promosyon-sozlesmeleri.php', 'Sponsorluk Sözleşmeleri', 'star'],
    ['tanimlar.php', 'Tanımlar', 'sliders'],
    ['nokta-yonetimi.php', 'Nokta Yönetimi', 'pin'],
    ['hakedisler.php', 'Hakedişler', 'receipt'],
    ['motorin-yukle.php', 'Motorin Fiyatları', 'fuel'],
    ['demirbas-takip.php', 'Demirbaş Takip', 'box'],
    ['palet-takip.php', 'Palet Takip', 'layers'],
    ['recete-maliyet.php', 'Reçete ve Maliyet', 'chart'],
    ['gorev-takip.php', 'Görev Takip', 'check'],
    ['raporlar.php', 'Raporlar', 'report'],
    ['log-kayitlari.php', 'Log Kayıtları', 'clock'],
    ['kullanici-yonetimi.php', 'Ayarlar', 'settings'],
];
$icons = [
    'grid' => '<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><rect x="14" y="14" width="6" height="6"/>',
    'building' => '<path d="M5 21V4h10v17M3 21h18M8 8h1M12 8h1M8 12h1M12 12h1M8 16h1M12 16h1M15 21v-4h4v4"/>',
    'file' => '<path d="M6 3h8l4 4v14H6zM14 3v5h5M9 13h6M9 17h6"/>',
    'star' => '<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
    'sliders' => '<path d="M4 7h5M13 7h7M4 17h9M17 17h3M9 4v6M13 14v6"/>',
    'pin' => '<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3"/>',
    'fuel' => '<path d="M4 21V5h9v16M7 9h3M13 8h2l3 3v6a2 2 0 0 1-2 2h-1M7 21h8"/>',
    'box' => '<path d="m4 7 8-4 8 4-8 4zM4 7v10l8 4 8-4V7M12 11v10"/>',
    'layers' => '<path d="m12 3 8 4-8 4-8-4zM4 12l8 4 8-4M4 16l8 4 8-4"/>',
    'chart' => '<path d="M4 20V4M4 20h17M8 16l4-5 3 2 5-7"/>',
    'check' => '<path d="M9 11l2 2 4-5M7 4h10a2 2 0 0 1 2 2v14H5V6a2 2 0 0 1 2-2Z"/>',
    'report' => '<path d="M5 20V10M10 20V4M15 20v-7M20 20H3"/>',
    'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.2 2.2-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2h-3.2v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L6.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H5v-3.2h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.2-2.2.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V4h3.2v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.2 2.2-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.2V14h-.2a1.7 1.7 0 0 0-1.5 1Z"/>',
    'logout' => '<path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/>',
];
?>
<aside class="sidebar" aria-label="Ana menü">
    <nav class="menu">
        <?php foreach($menuItems as [$href, $label, $icon]): ?>
            <a href="<?= $href ?>" class="<?= $activePage === $href ? 'is-active' : '' ?>"<?= $activePage === $href ? ' aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg>
                <span><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <a class="logout-link" href="logout.php" onclick="return confirm('Çıkış yapılsın mı?');">
            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['logout'] ?></svg>
            <span>Güvenli Çıkış</span>
        </a>
    </div>
</aside>
