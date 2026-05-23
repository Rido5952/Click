<?php
// password-link.php — Password-protected link access
require_once 'config.php';

$code = trim($_GET['code'] ?? '');
if (empty($code)) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ? AND is_active = 1");
$stmt->execute([$code]);
$link = $stmt->fetch();

if (!$link || empty($link['password'])) { header("Location: index.php?c=" . urlencode($code)); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforce_csrf();
    $entered = $_POST['link_password'] ?? '';
    if (password_verify($entered, $link['password'])) {
        $_SESSION['unlocked_' . $code] = true;
        header("Location: index.php?c=" . urlencode($code));
        exit;
    }
    $error = 'Şifre yanlış. Lütfen tekrar deneyin.';
}

$site_name = get_setting('site_name', 'Click');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifreli Link — <?php echo clean($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <div class="container nav-container">
            <a href="index.php" class="logo"><i class="fas fa-link"></i> <?php echo clean($site_name); ?></a>
        </div>
    </header>
    <main class="auth-wrapper">
        <div class="auth-card glass-panel">
            <div class="auth-header">
                <div style="font-size:56px; color:var(--accent-indigo); margin-bottom:16px;"><i class="fas fa-lock"></i></div>
                <h2 class="auth-title">Şifreli Bağlantı</h2>
                <p class="auth-subtitle">Bu bağlantıya erişmek için şifre gereklidir.</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo clean($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="password-link.php?code=<?php echo clean($code); ?>">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label class="form-label" for="link_password">Bağlantı Şifresi</label>
                    <div class="input-wrapper">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" id="link_password" name="link_password" class="form-input"
                               placeholder="Şifreyi girin..." required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn-submit" style="width:100%;">
                    <i class="fas fa-unlock-alt"></i> Erişimi Aç
                </button>
            </form>
        </div>
    </main>
    <footer><div class="container"><p>&copy; <?php echo date('Y'); ?> <?php echo clean($site_name); ?>.</p></div></footer>
</body>
</html>
