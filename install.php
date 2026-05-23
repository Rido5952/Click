<?php
// install.php - Click Setup Wizard

// If session is not started, start it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lockFile = __DIR__ . '/database/install.lock';
$configFilePath = __DIR__ . '/database/config_db.php';

// If lock file exists, block installation
if (file_exists($lockFile)) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_type = $_POST['db_type'] ?? 'sqlite';
    $mysql_host = trim($_POST['mysql_host'] ?? '');
    $mysql_name = trim($_POST['mysql_name'] ?? '');
    $mysql_user = trim($_POST['mysql_user'] ?? '');
    $mysql_pass = $_POST['mysql_pass'] ?? '';
    
    $admin_user = trim($_POST['admin_user'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass = $_POST['admin_pass'] ?? '';
    $admin_pass_confirm = $_POST['admin_pass_confirm'] ?? '';
    
    // Validations
    if (empty($admin_user) || empty($admin_email) || empty($admin_pass)) {
        $error = 'Lütfen yönetici hesap bilgilerini eksiksiz doldurun.';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz yönetici e-posta adresi.';
    } elseif ($admin_pass !== $admin_pass_confirm) {
        $error = 'Yönetici şifreleri birbiriyle uyuşmuyor.';
    } elseif (strlen($admin_pass) < 6) {
        $error = 'Yönetici şifresi en az 6 karakter olmalıdır.';
    } elseif ($db_type === 'mysql' && (empty($mysql_host) || empty($mysql_name) || empty($mysql_user))) {
        $error = 'MySQL bağlantı bilgilerini eksiksiz doldurun.';
    } else {
        // Attempt Database Setup
        try {
            $pdo = null;
            if ($db_type === 'sqlite') {
                $dbDir = __DIR__ . '/database';
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                
                $sqlite_file = 'click.sqlite';
                $pdo = new PDO("sqlite:" . $dbDir . '/' . $sqlite_file);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                // MySQL Test Connection
                $dsn = "mysql:host=$mysql_host;charset=utf8mb4";
                $pdo = new PDO($dsn, $mysql_user, $mysql_pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Create database if not exists
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$mysql_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $pdo->exec("USE `$mysql_name`;");
            }
            
            // Build Database Schemas
            $tableQueries = [
                "CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY " . ($db_type === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ",
                    username VARCHAR(50) UNIQUE NOT NULL,
                    email VARCHAR(100) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    is_admin INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS links (
                    id INTEGER PRIMARY KEY " . ($db_type === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ",
                    user_id INTEGER DEFAULT NULL,
                    original_url TEXT NOT NULL,
                    short_code VARCHAR(50) UNIQUE NOT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    " . ($db_type === 'sqlite' ? 
                        "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE" : 
                        "CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE") . "
                )",
                "CREATE TABLE IF NOT EXISTS clicks (
                    id INTEGER PRIMARY KEY " . ($db_type === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ",
                    link_id INTEGER NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent TEXT NOT NULL,
                    referrer TEXT DEFAULT NULL,
                    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    " . ($db_type === 'sqlite' ? 
                        "FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE" : 
                        "CONSTRAINT fk_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE") . "
                )"
            ];
            
            foreach ($tableQueries as $q) {
                $pdo->exec($q);
            }
            
            // Insert Administrator
            $admin_hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute([$admin_user, $admin_email, $admin_hash]);
            
            // Save Configuration File
            $configData = [
                'type' => $db_type,
                'sqlite_file' => 'click.sqlite',
                'mysql_host' => $mysql_host,
                'mysql_name' => $mysql_name,
                'mysql_user' => $mysql_user,
                'mysql_pass' => $mysql_pass
            ];
            
            $configContent = "<?php\n// Click Dynamic Database Config\nreturn " . var_export($configData, true) . ";\n";
            file_put_contents($configFilePath, $configContent);
            
            // Lock installer
            file_put_contents($lockFile, date('Y-m-d H:i:s') . " - Installed successfully");
            
            $success = 'Kurulum başarıyla tamamlandı! Yönlendiriliyorsunuz...';
            
            // Redirect after 2 seconds
            header("refresh:2; url=login.php?installed=1");
        } catch (PDOException $e) {
            $error = 'Veritabanı kurulum hatası: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Click - Kurulum Sihirbazı</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .db-toggle-container {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }
        .db-card-label {
            flex: 1;
            cursor: pointer;
        }
        .db-card-label input {
            display: none;
        }
        .db-card {
            border: 1px solid var(--border-glass);
            border-radius: var(--border-radius-sm);
            padding: 16px;
            text-align: center;
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.2s;
        }
        .db-card i {
            font-size: 28px;
            margin-bottom: 8px;
            color: var(--text-muted);
        }
        .db-card-label input:checked + .db-card {
            border-color: var(--accent-indigo);
            background: rgba(99, 102, 241, 0.08);
            box-shadow: 0 0 12px rgba(99, 102, 241, 0.15);
        }
        .db-card-label input:checked + .db-card i {
            color: var(--accent-indigo);
        }
        .mysql-fields {
            display: none;
        }
        .mysql-fields.active {
            display: block;
        }
    </style>
</head>
<body>
    <header>
        <div class="container nav-container">
            <a href="install.php" class="logo"><i class="fas fa-link"></i> Click</a>
            <span class="hero-tag" style="margin-bottom: 0;">Kurulum Sihirbazı</span>
        </div>
    </header>

    <main class="auth-wrapper">
        <div class="auth-card glass-panel" style="max-width: 600px; padding: 36px;">
            <div class="auth-header">
                <div class="logo" style="justify-content: center; font-size: 32px;"><i class="fas fa-link"></i> Click</div>
                <h2 class="auth-title" style="margin-top: 12px;">Sistem Kurulumu</h2>
                <p class="auth-subtitle">Veritabanı seçiminizi yapıp yönetici hesabını oluşturarak başlayın.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="install.php">
                <h3 style="font-size: 16px; margin-bottom: 12px; font-weight: 600;">1. Veritabanı Seçimi</h3>
                
                <div class="db-toggle-container">
                    <label class="db-card-label">
                        <input type="radio" name="db_type" value="sqlite" checked onclick="toggleDBFields('sqlite')">
                        <div class="db-card">
                            <i class="fas fa-database"></i>
                            <div style="font-weight: 600;">SQLite</div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Tek dosya, yapılandırma gerektirmez</div>
                        </div>
                    </label>
                    <label class="db-card-label">
                        <input type="radio" name="db_type" value="mysql" onclick="toggleDBFields('mysql')">
                        <div class="db-card">
                            <i class="fas fa-server"></i>
                            <div style="font-weight: 600;">MySQL / MariaDB</div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Yoğun trafik için performanslı</div>
                        </div>
                    </label>
                </div>

                <!-- MySQL Details Fields -->
                <div id="mysql-section" class="mysql-fields">
                    <div class="form-group-row">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label" for="mysql_host">MySQL Sunucu (Host)</label>
                            <input type="text" id="mysql_host" name="mysql_host" class="form-input" placeholder="localhost" style="padding-left:18px;">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label" for="mysql_name">Veritabanı Adı (Database)</label>
                            <input type="text" id="mysql_name" name="mysql_name" class="form-input" placeholder="click_db" style="padding-left:18px;">
                        </div>
                    </div>
                    <div class="form-group-row">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label" for="mysql_user">Veritabanı Kullanıcısı</label>
                            <input type="text" id="mysql_user" name="mysql_user" class="form-input" placeholder="root" style="padding-left:18px;">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label" for="mysql_pass">Veritabanı Şifresi</label>
                            <input type="password" id="mysql_pass" name="mysql_pass" class="form-input" placeholder="••••••••" style="padding-left:18px;">
                        </div>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--border-glass); margin: 24px 0;">

                <h3 style="font-size: 16px; margin-bottom: 12px; font-weight: 600;">2. Yönetici Hesap Bilgileri</h3>
                
                <div class="form-group">
                    <label class="form-label" for="admin_user">Yönetici Kullanıcı Adı</label>
                    <input type="text" id="admin_user" name="admin_user" class="form-input" placeholder="admin" required style="padding-left:18px;" value="<?php echo isset($admin_user) ? htmlspecialchars($admin_user) : ''; ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="admin_email">Yönetici E-posta Adresi</label>
                    <input type="email" id="admin_email" name="admin_email" class="form-input" placeholder="admin@site.com" required style="padding-left:18px;" value="<?php echo isset($admin_email) ? htmlspecialchars($admin_email) : ''; ?>">
                </div>

                <div class="form-group-row">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="admin_pass">Yönetici Şifresi</label>
                        <input type="password" id="admin_pass" name="admin_pass" class="form-input" placeholder="••••••••" required style="padding-left:18px;">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="admin_pass_confirm">Şifre Tekrarı</label>
                        <input type="password" id="admin_pass_confirm" name="admin_pass_confirm" class="form-input" placeholder="••••••••" required style="padding-left:18px;">
                    </div>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%; margin-top: 16px;">
                    <i class="fas fa-magic"></i> Kurulumu Başlat
                </button>
            </form>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Click. Tüm Hakları Saklıdır.</p>
        </div>
    </footer>

    <script>
        function toggleDBFields(type) {
            const section = document.getElementById('mysql-section');
            if (type === 'mysql') {
                section.classList.add('active');
                document.getElementById('mysql_host').setAttribute('required', 'required');
                document.getElementById('mysql_name').setAttribute('required', 'required');
                document.getElementById('mysql_user').setAttribute('required', 'required');
            } else {
                section.classList.remove('active');
                document.getElementById('mysql_host').removeAttribute('required');
                document.getElementById('mysql_name').removeAttribute('required');
                document.getElementById('mysql_user').removeAttribute('required');
            }
        }
    </script>
</body>
</html>
