<?php
// bio.php — Public Bio Link Page (/bio.php?u=username)
require_once 'config.php';

$username = trim($_GET['u'] ?? '');
if (empty($username)) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$bioUser = $stmt->fetch();

$site_name = get_setting('site_name', 'Click');
$base = get_base_url();

if (!$bioUser) {
?><!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Bulunamadı</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head><body>
<header><div class="container nav-container"><a href="index.php" class="logo"><i class="fas fa-link"></i> <?php echo clean($site_name); ?></a></div></header>
<main class="auth-wrapper"><div class="auth-card glass-panel" style="text-align:center;">
<div style="font-size:60px;color:var(--text-muted);margin-bottom:16px;"><i class="fas fa-user-slash"></i></div>
<h2 class="auth-title">Kullanıcı Bulunamadı</h2>
<p style="color:var(--text-secondary);">@<?php echo clean($username); ?> adlı kullanıcı mevcut değil.</p>
<a href="index.php" class="btn-submit" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;padding:12px 28px;width:auto;margin-top:16px;"><i class="fas fa-home"></i> Ana Sayfa</a>
</div></main></body></html>
<?php exit; }

$displayName = $bioUser['display_name'] ?: $bioUser['username'];
$bio         = $bioUser['bio'] ?? '';
$avatar      = $bioUser['avatar_url'] ?? '';
$bgColor     = $bioUser['bio_bg_color'] ?: '#0f0f1a';
$socials     = json_decode($bioUser['social_links'] ?? '{}', true) ?: [];

// Load active links
$links = $pdo->prepare("
    SELECT l.*, COUNT(c.id) as click_count
    FROM links l LEFT JOIN clicks c ON c.link_id = l.id
    WHERE l.user_id = ? AND l.is_active = 1
      AND (l.expires_at IS NULL OR l.expires_at > datetime('now'))
      AND (l.password IS NULL OR l.password = '')
    GROUP BY l.id ORDER BY l.created_at DESC
");
$links->execute([$bioUser['id']]);
$userLinks = $links->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo clean($displayName); ?> — <?php echo clean($site_name); ?></title>
    <meta name="description" content="<?php echo clean($bio ?: $displayName . ' link sayfası'); ?>">
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo clean($displayName); ?>">
    <meta property="og:description" content="<?php echo clean($bio ?: $displayName . ' link sayfası'); ?>">
    <meta property="og:type" content="profile">
    <?php if ($avatar): ?>
    <meta property="og:image" content="<?php echo clean($avatar); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: linear-gradient(135deg, <?php echo clean($bgColor); ?> 0%, #1a1a2e 100%); min-height: 100vh; }
        .bio-page { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .bio-avatar { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-indigo); }
        .bio-avatar-icon { width: 96px; height: 96px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-indigo), var(--accent-purple)); display: flex; align-items: center; justify-content: center; font-size: 40px; color: white; border: 3px solid rgba(255,255,255,0.2); }
        .bio-header { text-align: center; margin-bottom: 32px; }
        .bio-name { font-size: 26px; font-weight: 700; margin: 12px 0 8px; }
        .bio-bio { color: var(--text-secondary); font-size: 14px; line-height: 1.6; max-width: 400px; margin: 0 auto 16px; }
        .bio-socials { display: flex; justify-content: center; gap: 14px; margin-bottom: 8px; }
        .bio-social-btn { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.08); border: 1px solid var(--border-glass); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 15px; text-decoration: none; transition: all 0.2s; }
        .bio-social-btn:hover { background: rgba(99,102,241,0.2); color: var(--accent-indigo); border-color: var(--accent-indigo); transform: translateY(-2px); }
        .bio-link-card { background: rgba(255,255,255,0.06); border: 1px solid var(--border-glass); border-radius: 14px; padding: 16px 20px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; text-decoration: none; transition: all 0.25s; position: relative; overflow: hidden; }
        .bio-link-card:hover { background: rgba(99,102,241,0.12); border-color: var(--accent-indigo); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(99,102,241,0.2); }
        .bio-link-title { font-weight: 600; color: var(--text-primary); font-size: 15px; }
        .bio-link-code { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .bio-link-actions { display: flex; gap: 8px; }
        .bio-action-btn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-glass); background: rgba(255,255,255,0.05); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; transition: all 0.2s; }
        .bio-action-btn:hover { background: rgba(99,102,241,0.2); color: var(--accent-indigo); }
        .bio-powered { text-align: center; margin-top: 32px; font-size: 12px; color: var(--text-muted); }
        .bio-powered a { color: var(--accent-indigo); text-decoration: none; }
        .qr-modal { display:none; position:fixed; top:0;left:0;right:0;bottom:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center; }
        .qr-modal.active { display:flex; }
        .qr-box { background:var(--bg-secondary); border:1px solid var(--border-glass); border-radius:16px; padding:28px; text-align:center; }
        .qr-box h4 { margin-bottom:16px; font-size:16px; }
        .clicks-badge { background: rgba(99,102,241,0.15); color:var(--accent-indigo); font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px; }
    </style>
</head>
<body>
<div class="bio-page">
    <div class="bio-header">
        <?php if ($avatar): ?>
            <img src="<?php echo clean($avatar); ?>" alt="Avatar" class="bio-avatar">
        <?php else: ?>
            <div class="bio-avatar-icon" style="margin:0 auto;"><i class="fas fa-user"></i></div>
        <?php endif; ?>
        <div class="bio-name"><?php echo clean($displayName); ?></div>
        <?php if ($bio): ?><div class="bio-bio"><?php echo nl2br(clean($bio)); ?></div><?php endif; ?>
        <?php if (!empty(array_filter($socials))): ?>
        <div class="bio-socials">
            <?php if (!empty($socials['twitter'])): ?><a href="https://twitter.com/<?php echo clean($socials['twitter']); ?>" class="bio-social-btn" target="_blank"><i class="fab fa-twitter"></i></a><?php endif; ?>
            <?php if (!empty($socials['instagram'])): ?><a href="https://instagram.com/<?php echo clean($socials['instagram']); ?>" class="bio-social-btn" target="_blank"><i class="fab fa-instagram"></i></a><?php endif; ?>
            <?php if (!empty($socials['github'])): ?><a href="https://github.com/<?php echo clean($socials['github']); ?>" class="bio-social-btn" target="_blank"><i class="fab fa-github"></i></a><?php endif; ?>
            <?php if (!empty($socials['website'])): ?><a href="<?php echo clean($socials['website']); ?>" class="bio-social-btn" target="_blank"><i class="fas fa-globe"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($userLinks)): ?>
        <div style="text-align:center;color:var(--text-muted);padding:40px 0;"><i class="fas fa-link" style="font-size:32px;margin-bottom:12px;display:block;"></i>Henüz paylaşılan link yok.</div>
    <?php else: ?>
        <?php foreach ($userLinks as $lnk):
            $shortUrl = $base . '/' . $lnk['short_code'];
        ?>
        <div class="bio-link-card">
            <a href="<?php echo clean($shortUrl); ?>" target="_blank" style="flex:1;text-decoration:none;">
                <div class="bio-link-title"><?php echo clean($lnk['title'] ?: $lnk['short_code']); ?></div>
                <div class="bio-link-code"><?php echo clean($base); ?>/<strong><?php echo clean($lnk['short_code']); ?></strong> &nbsp;<span class="clicks-badge"><?php echo number_format($lnk['click_count']); ?> tıklanma</span></div>
            </a>
            <div class="bio-link-actions">
                <button class="bio-action-btn" onclick="copyText('<?php echo clean($shortUrl); ?>')" title="Kopyala"><i class="fas fa-copy"></i></button>
                <button class="bio-action-btn" onclick="showQR('<?php echo clean($shortUrl); ?>','<?php echo clean($lnk['title'] ?: $lnk['short_code']); ?>')" title="QR Kod"><i class="fas fa-qrcode"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="bio-powered">Powered by <a href="<?php echo $base; ?>"><?php echo clean($site_name); ?></a> — <a href="<?php echo $base; ?>/register.php">Siz de oluşturun →</a></div>
</div>

<!-- QR Modal -->
<div class="qr-modal" id="qr-modal" onclick="this.classList.remove('active')">
    <div class="qr-box" onclick="event.stopPropagation()">
        <h4 id="qr-title">QR Kod</h4>
        <div id="qr-canvas" style="margin:0 auto 16px;"></div>
        <button onclick="document.getElementById('qr-modal').classList.remove('active')" style="background:none;border:1px solid var(--border-glass);color:var(--text-secondary);padding:8px 20px;border-radius:8px;cursor:pointer;">Kapat</button>
    </div>
</div>

<div id="toast" style="position:fixed;bottom:24px;right:24px;background:rgba(99,102,241,0.9);color:white;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:500;opacity:0;transition:opacity 0.3s;z-index:9999;">Kopyalandı!</div>

<script src="assets/js/qrcode.min.js"></script>
<script>
function copyText(url) {
    navigator.clipboard.writeText(url).then(() => {
        const t = document.getElementById('toast');
        t.style.opacity = '1';
        setTimeout(() => t.style.opacity = '0', 2000);
    });
}
let qrInstance = null;
function showQR(url, title) {
    document.getElementById('qr-title').textContent = title;
    const canvas = document.getElementById('qr-canvas');
    canvas.innerHTML = '';
    qrInstance = new QRCode(canvas, { text: url, width: 200, height: 200, colorDark: '#ffffff', colorLight: '#1a1a2e' });
    document.getElementById('qr-modal').classList.add('active');
}
</script>
</body>
</html>
