<?php
// login.php - Click Login Page
require_once 'config.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';

if (isset($_GET['registered'])) {
    $success = 'Kayıt başarılı! Şimdi giriş yapabilirsiniz.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enforce CSRF validation
    enforce_csrf();
    
    $login_input = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login_input) || empty($password)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } else {
        // Find user by username or email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent Session Fixation / Hijacking
            session_regenerate_id(true);
            
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            
            header("Location: dashboard.php");
            exit;
        } else {
            $error = 'Geçersiz kullanıcı adı/e-posta adresi veya şifre.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Click Link Kısaltıcı</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-link"></i> Click
            </a>
            <div class="nav-links">
                <a href="login.php" class="nav-link">Giriş Yap</a>
                <a href="register.php" class="nav-btn nav-btn-primary">Kayıt Ol</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="auth-wrapper">
        <div class="auth-card glass-panel">
            <div class="auth-header">
                <div class="auth-logo logo">
                    <i class="fas fa-link"></i> Click
                </div>
                <h2 class="auth-title">Tekrar Hoş Geldiniz</h2>
                <p class="auth-subtitle">Hesabınıza giriş yapın ve linklerinizi yönetin.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo clean($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo clean($success); ?>
                </div>
            <?php endif; ?>

            <form class="auth-form" method="POST" action="login.php">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label for="login_input" class="form-label">Kullanıcı Adı veya E-posta</label>
                    <input type="text" id="login_input" name="login_input" class="form-input" 
                           placeholder="Kullanıcı adınızı veya e-postanızı girin" required 
                           value="<?php echo isset($login_input) ? clean($login_input) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Şifre</label>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%;">
                    <i class="fas fa-sign-in-alt"></i> Giriş Yap
                </button>
            </form>

            <div class="auth-footer">
                Henüz bir hesabınız yok mu? <a href="register.php">Kayıt Ol</a>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Click. Tüm Hakları Saklıdır.</p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
</body>
</html>
