<?php
// dashboard.php - Click Member Dashboard
require_once 'config.php';

// Force login
require_login();

$user = get_current_user_data();

$error = '';
$success = '';

// --- Part 1: Handle Link Deletion ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    enforce_csrf();
    $deleteId = intval($_POST['link_id'] ?? 0);
    
    // Verify ownership before deleting
    $stmt = $pdo->prepare("SELECT id FROM links WHERE id = ? AND user_id = ?");
    $stmt->execute([$deleteId, $user['id']]);
    if ($stmt->fetch()) {
        $deleteStmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
        $deleteStmt->execute([$deleteId]);
        header("Location: dashboard.php?deleted=1");
        exit;
    } else {
        $error = 'Bağlantı silinemedi: Yetkisiz işlem.';
    }
}

// --- Part 2: Handle Link Editing ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    enforce_csrf();
    $linkId = intval($_POST['link_id'] ?? 0);
    $newUrl = trim($_POST['original_url'] ?? '');
    
    // Add protocol if missing
    if (!empty($newUrl) && !preg_match("~^(?:f|ht)tps?://~i", $newUrl)) {
        $newUrl = "http://" . $newUrl;
    }
    
    // Verify ownership
    $stmt = $pdo->prepare("SELECT id FROM links WHERE id = ? AND user_id = ?");
    $stmt->execute([$linkId, $user['id']]);
    
    if (!$stmt->fetch()) {
        $error = 'Bağlantı güncellenemedi: Yetkisiz işlem.';
    } elseif (empty($newUrl) || !is_valid_url($newUrl)) {
        $error = 'Lütfen geçerli bir URL adresi girin.';
    } else {
        // Try fetching new title
        $title = '';
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $html = @file_get_contents($newUrl, false, $ctx);
            if ($html && preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
                $title = trim($matches[1]);
                if (strlen($title) > 100) {
                    $title = mb_substr($title, 0, 97) . '...';
                }
            }
        } catch (Exception $e) {}
        
        if (empty($title)) {
            $title = parse_url($newUrl, PHP_URL_HOST) ?: 'Güncellenmiş Bağlantı';
        }
        
        // Update URL and title
        $updateStmt = $pdo->prepare("UPDATE links SET original_url = ?, title = ? WHERE id = ?");
        $updateStmt->execute([$newUrl, $title, $linkId]);
        
        header("Location: dashboard.php?updated=1");
        exit;
    }
}

// --- Part 3: Handle New Link Creation ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'shorten') {
    enforce_csrf();
    $original_url = trim($_POST['original_url'] ?? '');
    $custom_alias = trim($_POST['custom_alias'] ?? '');
    
    // Add protocol if missing
    if (!empty($original_url) && !preg_match("~^(?:f|ht)tps?://~i", $original_url)) {
        $original_url = "http://" . $original_url;
    }
    
    if (empty($original_url)) {
        $error = 'Lütfen kısaltmak istediğiniz URL adresini girin.';
    } elseif (!is_valid_url($original_url)) {
        $error = 'Lütfen geçerli bir URL adresi girin.';
    } else {
        $code = '';
        if (!empty($custom_alias)) {
            if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $custom_alias)) {
                $error = 'Özel link uzantısı sadece harf, rakam, tire (-) ve alt çizgi (_) içerebilir.';
            } elseif (is_code_taken($custom_alias)) {
                $error = 'Bu özel link uzantısı zaten kullanılıyor.';
            } else {
                $code = $custom_alias;
            }
        } else {
            $code = generate_short_code();
        }
        
        if (empty($error)) {
            // Get URL title
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
            
            $stmt = $pdo->prepare("INSERT INTO links (user_id, original_url, short_code, title, expires_at) VALUES (?, ?, ?, ?, NULL)");
            $stmt->execute([$user['id'], $original_url, $code, $title]);
            
            header("Location: dashboard.php?created=1&code=" . urlencode($code));
            exit;
        }
    }
}

// --- Part 4: Retrieve Statistics & Data ---

// Total links shortened by user
$stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_links = $stmt->fetchColumn();

// Total clicks across all user's links
$stmt = $pdo->prepare("SELECT COUNT(clicks.id) FROM clicks JOIN links ON clicks.link_id = links.id WHERE links.user_id = ?");
$stmt->execute([$user['id']]);
$total_clicks = $stmt->fetchColumn();

// Clicks received today
$stmt = $pdo->prepare("
    SELECT COUNT(clicks.id) 
    FROM clicks 
    JOIN links ON clicks.link_id = links.id 
    WHERE links.user_id = ? AND date(clicks.clicked_at) = date('now', 'localtime')
");
$stmt->execute([$user['id']]);
$clicks_today = $stmt->fetchColumn();

// Get list of all links
$stmt = $pdo->prepare("
    SELECT l.*, COUNT(c.id) AS click_count 
    FROM links l 
    LEFT JOIN clicks c ON l.id = c.link_id 
    WHERE l.user_id = ? 
    GROUP BY l.id 
    ORDER BY l.created_at DESC
");
$stmt->execute([$user['id']]);
$user_links = $stmt->fetchAll();

// Toast alerts
if (isset($_GET['deleted'])) $success = 'Bağlantı başarıyla silindi.';
if (isset($_GET['updated'])) $success = 'Hedef URL başarıyla güncellendi.';
if (isset($_GET['created'])) {
    $success = 'Kısa linkiniz başarıyla oluşturuldu: ' . clean(get_base_url() . '/' . ($_GET['code'] ?? ''));
}

// Load site settings
$site_name = get_setting('site_name', 'Click');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontrol Paneli - <?php echo clean($site_name); ?></title>
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
                <span style="color: var(--text-secondary); font-size: 14px; font-weight: 500;">
                    <i class="fas fa-user-circle"></i> <?php echo clean($user['username']); ?>
                </span>
                <a href="dashboard.php" class="nav-link active"><i class="fas fa-chart-line"></i> Panelim</a>
                <?php if (intval($user['is_admin']) === 1): ?>
                    <a href="admin.php" class="nav-link"><i class="fas fa-cogs"></i> Yönetim</a>
                <?php endif; ?>
                <a href="logout.php" class="nav-btn nav-btn-outline"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="dashboard-wrapper container">
        
        <!-- Welcome Header -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Kullanıcı Paneli</h1>
                <p>Kısaltılmış bağlantılarınızı oluşturun, güncelleyin ve analiz edin.</p>
            </div>
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

        <!-- Stats Cards Grid -->
        <div class="stats-grid">
            <div class="stat-card glass-panel">
                <div class="stat-icon indigo"><i class="fas fa-link"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($total_links); ?></span>
                    <span class="stat-label">Kısaltılan Link</span>
                </div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-icon purple"><i class="fas fa-mouse-pointer"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($total_clicks); ?></span>
                    <span class="stat-label">Toplam Tıklanma</span>
                </div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-icon pink"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($clicks_today); ?></span>
                    <span class="stat-label">Bugünkü Tıklanma</span>
                </div>
            </div>
        </div>

        <!-- Quick Link Creator Panel -->
        <div class="dashboard-actions-panel glass-panel">
            <h2><i class="fas fa-plus"></i> Yeni Kısa Link Oluştur</h2>
            <form method="POST" action="dashboard.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="shorten">
                <div class="form-group-row" style="margin-bottom: 0;">
                    <div class="input-wrapper">
                        <i class="fas fa-link input-icon"></i>
                        <input type="text" name="original_url" class="form-input" 
                               placeholder="Kısaltmak istediğiniz uzun linki girin..." required>
                    </div>
                    <div class="input-wrapper" style="max-width: 250px;">
                        <i class="fas fa-at input-icon"></i>
                        <input type="text" name="custom_alias" class="form-input" 
                               placeholder="Özel uzantı (İsteğe bağlı)" style="padding-left: 42px;">
                    </div>
                    <button type="submit" class="btn-submit" style="padding: 16px 24px;">
                        <i class="fas fa-plus"></i> Ekle
                    </button>
                </div>
            </form>
        </div>

        <!-- Links Table Card -->
        <div class="links-card glass-panel">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-list"></i> Linklerim</div>
            </div>
            
            <?php if (empty($user_links)): ?>
                <div class="empty-state">
                    <i class="fas fa-link"></i>
                    <p>Henüz kısaltılmış bir linkiniz bulunmuyor. Yukarıdaki formu kullanarak hemen ilk linkinizi oluşturun!</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Başlık / Orijinal URL</th>
                                <th>Kısa Link</th>
                                <th style="text-align: center;">Tıklanma</th>
                                <th>Kayıt Tarihi</th>
                                <th style="text-align: right;">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_links as $link): ?>
                                <?php 
                                $short_url = get_base_url() . '/' . $link['short_code'];
                                ?>
                                <tr>
                                    <td>
                                        <span style="font-weight: 600; display: block; color: var(--text-primary);">
                                            <?php echo clean($link['title']); ?>
                                        </span>
                                        <a href="<?php echo clean($link['original_url']); ?>" target="_blank" class="link-original" title="<?php echo clean($link['original_url']); ?>">
                                            <?php echo clean($link['original_url']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo clean($short_url); ?>" target="_blank" class="link-short">
                                            /<?php echo clean($link['short_code']); ?> <i class="fas fa-external-link-alt" style="font-size: 11px;"></i>
                                        </a>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge-clicks"><?php echo number_format($link['click_count']); ?></span>
                                    </td>
                                    <td class="date-col">
                                        <?php echo date('d.m.Y H:i', strtotime($link['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="actions-cell" style="justify-content: flex-end;">
                                            <button class="btn-action btn-action-copy" data-url="<?php echo clean($short_url); ?>" title="Panoya Kopyala">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                            <a href="analytics.php?code=<?php echo clean($link['short_code']); ?>" class="btn-action btn-action-analytics" title="İstatistikleri İncele">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                            <button class="btn-action btn-action-edit" 
                                                    data-id="<?php echo $link['id']; ?>" 
                                                    data-code="<?php echo clean($link['short_code']); ?>" 
                                                    data-url="<?php echo clean($link['original_url']); ?>" 
                                                    title="URL Güncelle">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="dashboard.php" style="display:inline;" onsubmit="return confirm('Bu kısa linki silmek istediğinizden emin misiniz?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="link_id" value="<?php echo $link['id']; ?>">
                                                <button type="submit" class="btn-action btn-action-delete" title="Linki Sil">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Edit Link Target Modal -->
    <div class="modal" id="edit-modal">
        <div class="modal-content glass-panel">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <h3 class="modal-title">Bağlantıyı Güncelle</h3>
            <form method="POST" action="dashboard.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="link_id" id="edit-link-id">
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" for="edit-short-code">Kısa Link Adresi</label>
                    <input type="text" id="edit-short-code" class="form-input" readonly disabled style="opacity: 0.6;">
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label" for="edit-original-url">Yeni Hedef URL (Uzun Link)</label>
                    <input type="text" name="original_url" id="edit-original-url" class="form-input" placeholder="https://example.com" required style="padding-left: 18px;">
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="nav-btn nav-btn-outline btn-modal-close">İptal</button>
                    <button type="submit" class="btn-submit" style="padding: 10px 24px; font-size: 14px;">Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Click. Tüm Hakları Saklıdır.</p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
    <?php if (isset($_GET['created']) && isset($_GET['code'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                showToast('Yeni kısa link oluşturuldu!', 'success');
            });
        </script>
    <?php endif; ?>
</body>
</html>
