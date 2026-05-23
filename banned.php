<?php
// banned.php — IP Ban Info Page (loads DB directly to avoid redirect loop)
if (session_status() === PHP_SESSION_NONE) session_start();
$dbConfigPath = __DIR__ . '/database/config_db.php';
$site_name = 'Click';
if (file_exists($dbConfigPath) && file_exists(__DIR__ . '/database/install.lock')) {
    try {
        $cfg = require $dbConfigPath;
        $p = $cfg['type'] === 'sqlite'
            ? new PDO("sqlite:" . __DIR__ . '/database/' . $cfg['sqlite_file'])
            : new PDO("mysql:host={$cfg['mysql_host']};dbname={$cfg['mysql_name']};charset=utf8mb4", $cfg['mysql_user'], $cfg['mysql_pass']);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $s = $p->prepare("SELECT setting_value FROM settings WHERE setting_key='site_name'");
        $s->execute(); $v = $s->fetchColumn();
        if ($v) $site_name = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erişim Engellendi — <?php echo $site_name; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header>
    <div class="container nav-container">
        <a href="index.php" class="logo"><i class="fas fa-link"></i> <?php echo $site_name; ?></a>
    </div>
</header>
<main class="auth-wrapper">
    <div class="auth-card glass-panel" style="text-align:center; max-width:500px;">
        <div style="font-size:72px; color:#ef4444; margin-bottom:20px; animation: pulse 2s infinite;">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h2 class="auth-title" style="color:#ef4444;">Erişim Engellendi</h2>
        <p style="color:var(--text-secondary); line-height:1.7; margin:16px 0 24px;">
            IP adresiniz sistemden engellenmiştir.<br>
            Bu bir hata olduğunu düşünüyorsanız site yöneticisiyle iletişime geçin.
        </p>
        <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:10px; padding:14px; margin-bottom:24px; font-family:monospace; font-size:13px; color:#fca5a5;">
            IP: <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', ENT_QUOTES); ?>
        </div>
        <a href="index.php" class="btn-submit" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none; padding:12px 28px; width:auto;">
            <i class="fas fa-home"></i> Ana Sayfaya Dön
        </a>
    </div>
</main>
<footer><div class="container"><p>&copy; <?php echo date('Y'); ?> <?php echo $site_name; ?>.</p></div></footer>
<style>@keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.6;} }</style>
</body>
</html>
