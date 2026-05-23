<?php
// register.php - Click Register Page
require_once 'config.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enforce CSRF validation
    enforce_csrf();
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // Validations
    if (empty($username) || empty($email) || empty($password)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $error = 'Kullanıcı adı 3 ile 20 karakter arasında olmalıdır.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'Kullanıcı adı sadece harf, rakam ve alt çizgi içerebilir.';
    } elseif (strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter uzunluğunda olmalıdır.';
    } elseif ($password !== $password_confirm) {
        $error = 'Şifreler uyuşmuyor.';
    } else {
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Bu kullanıcı adı zaten alınmış.';
        } else {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Bu e-posta adresi zaten kayıtlı.';
            } else {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                
                // Insert new user
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                try {
                    $stmt->execute([$username, $email, $hashedPassword]);
                    $userId = $pdo->lastInsertId();
                    
                    // Prevent session fixation
                    session_regenerate_id(true);
                    
                    // Log in immediately
                    $_SESSION['user_id'] = $userId;
                    
                    header("Location: dashboard.php?registered=1");
                    exit;
                } catch (PDOException $e) {
                    $error = 'Kayıt sırasında bir hata oluştu: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt Ol - Click Link Kısaltıcı</title>
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
                <h2 class="auth-title">Yeni Hesap Oluştur</h2>
                <p class="auth-subtitle">Linklerinizi yönetmeye ve istatistiklerini takip etmeye başlayın.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo clean($error); ?>
                </div>
            <?php endif; ?>

            <form class="auth-form" method="POST" action="register.php">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label for="username" class="form-label">Kullanıcı Adı</label>
                    <input type="text" id="username" name="username" class="form-input" 
                           placeholder="Kullanıcı adınızı girin" required 
                           value="<?php echo isset($username) ? clean($username) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-posta Adresi</label>
                    <input type="email" id="email" name="email" class="form-input" 
                           placeholder="E-posta adresinizi girin" required 
                           value="<?php echo isset($email) ? clean($email) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Şifre</label>
                    <input type="password" id="password" name="password" class="form-input" 
                           placeholder="••••••••" required>
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="form-label">Şifre Tekrar</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input" 
                           placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%;">
                    <i class="fas fa-user-plus"></i> Hesap Oluştur
                </button>
            </form>

            <div class="auth-footer">
                Zaten bir hesabınız var mı? <a href="login.php">Giriş Yap</a>
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
