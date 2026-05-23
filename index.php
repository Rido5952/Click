<?php
// index.php - Click Homepage and Redirect Controller
require_once 'config.php';

// --- Part 1: Handle Redirects ---
if (isset($_GET['c'])) {
    $code = trim($_GET['c']);
    
    // Find the link
    $stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ?");
    $stmt->execute([$code]);
    $link = $stmt->fetch();
    
    if ($link) {
        // Log the click details
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        
        $logStmt = $pdo->prepare("INSERT INTO clicks (link_id, ip_address, user_agent, referrer) VALUES (?, ?, ?, ?)");
        $logStmt->execute([$link['id'], $ip, $ua, $referrer]);
        
        // Redirect to original URL
        header("Location: " . $link['original_url'], true, 302);
        exit;
    } else {
        // Show customized premium 404 page
        http_response_code(404);
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Link Bulunamadı - Click</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <link rel="stylesheet" href="assets/css/style.css">
        </head>
        <body>
            <header>
                <div class="container nav-container">
                    <a href="index.php" class="logo"><i class="fas fa-link"></i> Click</a>
                </div>
            </header>
            <main class="auth-wrapper">
                <div class="auth-card glass-panel" style="text-align: center;">
                    <div style="font-size: 64px; color: var(--accent-danger); margin-bottom: 20px;">
                        <i class="fas fa-unlink"></i>
                    </div>
                    <h2 class="auth-title">Link Bulunamadı</h2>
                    <p class="auth-subtitle" style="margin-bottom: 24px;">Aradığınız bağlantı silinmiş, değiştirilmiş veya hiç var olmamış olabilir.</p>
                    <a href="index.php" class="btn-submit" style="display: inline-flex; width: auto;">
                        <i class="fas fa-home"></i> Ana Sayfaya Dön
                    </a>
                </div>
            </main>
            <footer>
                <div class="container">
                    <p>&copy; <?php echo date('Y'); ?> Click. Tüm Hakları Saklıdır.</p>
                </div>
            </footer>
        </body>
        </html>
        <?php
        exit;
    }
}

// --- Part 2: Handle Shortener Form Submission ---
$error = '';
$shortened_url = '';
$guest_expires_label = '';
$original_url_val = '';
$custom_alias_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'shorten') {
    // Enforce CSRF token check
    enforce_csrf();
    
    $original_url = trim($_POST['original_url'] ?? '');
    $custom_alias = trim($_POST['custom_alias'] ?? '');
    
    $original_url_val = $original_url;
    $custom_alias_val = $custom_alias;
    
    // Add protocol if missing
    if (!empty($original_url) && !preg_match("~^(?:f|ht)tps?://~i", $original_url)) {
        $original_url = "http://" . $original_url;
    }
    
    if (empty($original_url)) {
        $error = 'Lütfen kısaltmak istediğiniz URL adresini girin.';
    } elseif (!is_valid_url($original_url)) {
        $error = 'Lütfen geçerli bir URL adresi girin.';
    } else {
        $user_id = is_logged_in() ? $_SESSION['user_id'] : null;
        $code = '';
        
        // Guest link expiry
        $guest_days = intval(get_setting('guest_link_days', '15'));
        $expires_at  = ($user_id === null) ? date('Y-m-d H:i:s', strtotime("+{$guest_days} days")) : null;

        if (!empty($custom_alias)) {
            // Validate custom alias format
            if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $custom_alias)) {
                $error = 'Özel link uzantısı sadece harf, rakam, tire (-) ve alt çizgi (_) içerebilir.';
            } elseif (!is_logged_in()) {
                $error = 'Özel uzantı oluşturmak için üye olmanız gerekmektedir.';
            } elseif (is_code_taken($custom_alias)) {
                $error = 'Bu özel link uzantısı zaten kullanılıyor. Lütfen başka bir tane deneyin.';
            } else {
                $code = $custom_alias;
            }
        } else {
            $code = generate_short_code();
        }
        
        if (empty($error)) {
            // Try to extract title from URL
            $title = '';
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                $html = @file_get_contents($original_url, false, $ctx);
                if ($html && preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
                    $title = trim($matches[1]);
                    if (strlen($title) > 100) {
                        $title = mb_substr($title, 0, 97) . '...';
                    }
                }
            } catch (Exception $e) {}
            if (empty($title)) {
                $title = parse_url($original_url, PHP_URL_HOST) ?: 'Kısaltılmış Bağlantı';
            }
            
            // Insert into DB (with optional expires_at)
            $stmt = $pdo->prepare("INSERT INTO links (user_id, original_url, short_code, title, expires_at) VALUES (?, ?, ?, ?, ?)");
            try {
                $stmt->execute([$user_id, $original_url, $code, $title, $expires_at]);
                $shortened_url = get_base_url() . '/' . $code;
                if ($expires_at) {
                    $guest_expires_label = date('d.m.Y', strtotime($expires_at));
                }
                // Clear values on success
                $original_url_val = '';
                $custom_alias_val = '';
            } catch (PDOException $e) {
                $error = 'Kaydetme sırasında bir hata oluştu: ' . $e->getMessage();
            }
        }
    }
}

// Fetch some quick overall counts for homepage styling
$total_links_count = $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();
$total_clicks_count = $pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();

// Load site settings
$site_name        = get_setting('site_name', 'Click');
$site_description = get_setting('site_description', 'Hızlı ve güvenilir link kısaltıcı');
$site_tagline     = get_setting('site_tagline', 'Uzun bağlantılarınızı tek tıkla akıllı hale getirin');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo clean($site_name); ?> - <?php echo clean($site_description); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-link"></i> <?php echo clean($site_name); ?>
            </a>
            <div class="nav-links">
                <?php if (is_logged_in()): ?>
                    <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-line"></i> Panelim</a>
                    <a href="logout.php" class="nav-btn nav-btn-outline"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
                <?php else: ?>
                    <a href="login.php" class="nav-link">Giriş Yap</a>
                    <a href="register.php" class="nav-btn nav-btn-primary">Kayıt Ol</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <section class="hero-section">
            <div class="hero-tag">YENİ NESİL LINK KISALTICI</div>
            <h1 class="hero-title">Uzun Bağlantılarınızı <span>Tek Tıkla</span> Akıllı Hale Getirin</h1>
            <p class="hero-subtitle">Click ile uzun ve karmaşık linkleri kısaltın, paylaşın ve detaylı tıklama istatistiklerini anlık olarak takip edin.</p>
            
            <!-- Shortener Form -->
            <div class="shortener-container glass-panel">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="text-align: left;">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo clean($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="shorten">
                    <div class="form-group-row">
                        <div class="input-wrapper">
                            <i class="fas fa-link input-icon"></i>
                            <input type="text" name="original_url" class="form-input" 
                                   placeholder="Kısaltmak istediğiniz uzun linki buraya yapıştırın..." required
                                   value="<?php echo clean($original_url_val); ?>">
                        </div>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-magic"></i> Kısalt
                        </button>
                    </div>
                    
                    <div class="optional-settings-toggle" id="toggle-options">
                        <i class="fas fa-chevron-right"></i> Gelişmiş Seçenekler (Özel Link Uzantısı)
                    </div>
                    
                    <div class="optional-fields <?php echo !empty($custom_alias_val) ? 'show' : ''; ?>" id="optional-fields">
                        <div class="form-group" style="text-align: left;">
                            <label class="form-label" for="custom_alias">Özel Link Uzantısı (İsteğe Bağlı)</label>
                            <div class="input-wrapper">
                                <i class="fas fa-at input-icon"></i>
                                <input type="text" id="custom_alias" name="custom_alias" class="form-input" 
                                       placeholder="Örn: benimlinkim" value="<?php echo clean($custom_alias_val); ?>">
                            </div>
                            <span style="font-size: 12px; color: var(--text-muted); margin-top: 4px; display: block;">
                                Boş bırakırsanız sistem otomatik rastgele bir kod oluşturacaktır.
                            </span>
                        </div>
                    </div>
                </form>

                <!-- Result Box -->
                <div class="result-box <?php echo !empty($shortened_url) ? 'active' : ''; ?>">
                    <span class="result-url"><?php echo clean($shortened_url); ?></span>
                    <button class="btn-copy" data-url="<?php echo clean($shortened_url); ?>">
                        <i class="fas fa-copy"></i> Kopyala
                    </button>
                    <?php if (!empty($guest_expires_label)): ?>
                    <div style="width:100%; margin-top:10px; padding:8px 12px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.3); border-radius:8px; font-size:13px; color:#f59e0b; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-clock"></i>
                        Bu link misafir linki olduğundan <strong><?php echo clean($guest_expires_label); ?></strong> tarihinde otomatik silinecektir.
                        <a href="register.php" style="color:#f59e0b; font-weight:700; text-decoration:underline; margin-left:auto;">Üye ol &rarr;</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Ribbon -->
            <div class="stats-grid" style="width: 100%; max-width: 600px; margin: 0 auto 40px auto;">
                <div class="stat-card glass-panel">
                    <div class="stat-icon indigo"><i class="fas fa-link"></i></div>
                    <div class="stat-info" style="text-align: left;">
                        <span class="stat-value"><?php echo number_format($total_links_count); ?></span>
                        <span class="stat-label">Toplam Link</span>
                    </div>
                </div>
                <div class="stat-card glass-panel">
                    <div class="stat-icon purple"><i class="fas fa-mouse-pointer"></i></div>
                    <div class="stat-info" style="text-align: left;">
                        <span class="stat-value"><?php echo number_format($total_clicks_count); ?></span>
                        <span class="stat-label">Toplam Tıklanma</span>
                    </div>
                </div>
            </div>
            
            <?php if (!is_logged_in()): ?>
                <p style="font-size: 14px; color: var(--text-secondary);">
                    Tıklanma istatistiklerini grafiklerle takip etmek için <a href="register.php">hemen ücretsiz hesap oluşturun</a>.
                </p>
            <?php endif; ?>
        </section>

        <!-- Features Section -->
        <section style="margin-bottom: 80px;">
            <div class="features-grid">
                <div class="feature-card glass-panel glass-panel-interactive">
                    <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                    <h3 class="feature-title">Işık Hızında</h3>
                    <p class="feature-desc">Gelişmiş yönlendirme altyapısı sayesinde ziyaretçileriniz beklemek zorunda kalmadan hedefe ulaşır.</p>
                </div>
                <div class="feature-card glass-panel glass-panel-interactive">
                    <div class="feature-icon"><i class="fas fa-chart-bar"></i></div>
                    <h3 class="feature-title">Gelişmiş Analitik</h3>
                    <p class="feature-desc">Üye olarak her bir linkin günlük tıklanma sayısını, referans kaynaklarını, tarayıcı ve cihaz istatistiklerini izleyin.</p>
                </div>
                <div class="feature-card glass-panel glass-panel-interactive">
                    <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                    <h3 class="feature-title">Güvenli Yapı</h3>
                    <p class="feature-desc">SQLite veri tabanı ve güvenli oturum yönetimi ile linkleriniz ve kullanıcı verileriniz koruma altındadır.</p>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Click. Tüm Hakları Saklıdır.</p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
    <?php if (!empty($shortened_url)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                showToast('Link kısaltıldı! Kopyalamaya hazır.', 'success');
            });
        </script>
    <?php endif; ?>
</body>
</html>
