<?php
// admin.php - Click Administration Panel
require_once 'config.php';

// Force admin authentication
require_admin();

$user = get_current_user_data();

$error = '';
$success = '';

// --- Part 1: Handle Admin POST Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enforce CSRF protection
    enforce_csrf();
    
    $action = $_POST['action'] ?? '';
    
    // Toggle Admin Privileges
    if ($action === 'toggle_admin') {
        $targetUserId = intval($_POST['user_id'] ?? 0);
        
        // Cannot change own admin status
        if ($targetUserId === intval($user['id'])) {
            $error = 'Kendi yöneticilik yetkinizi kaldıramazsınız.';
        } else {
            // Get current is_admin status
            $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();
            
            if ($targetUser) {
                $newStatus = intval($targetUser['is_admin']) === 1 ? 0 : 1;
                $updateStmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
                $updateStmt->execute([$newStatus, $targetUserId]);
                $success = 'Kullanıcı yetkisi başarıyla güncellendi.';
            } else {
                $error = 'Kullanıcı bulunamadı.';
            }
        }
    }
    
    // Delete User (Cascades to their links and clicks)
    if ($action === 'delete_user') {
        $targetUserId = intval($_POST['user_id'] ?? 0);
        
        if ($targetUserId === intval($user['id'])) {
            $error = 'Kendi hesabınızı yönetim panelinden silemezsiniz.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $success = 'Kullanıcı hesabı ve kullanıcıya ait tüm linkler silindi.';
        }
    }
    
    // Delete Link
    if ($action === 'delete_link') {
        $linkId = intval($_POST['link_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
        $stmt->execute([$linkId]);
        $success = 'Bağlantı başarıyla silindi.';
    }
    
    // Edit Link Target URL
    if ($action === 'edit_link') {
        $linkId = intval($_POST['link_id'] ?? 0);
        $newUrl = trim($_POST['original_url'] ?? '');
        
        if (!empty($newUrl) && !preg_match("~^(?:f|ht)tps?://~i", $newUrl)) {
            $newUrl = "http://" . $newUrl;
        }
        
        if (empty($newUrl) || !is_valid_url($newUrl)) {
            $error = 'Lütfen geçerli bir URL adresi girin.';
        } else {
            // Fetch updated title
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
            
            $stmt = $pdo->prepare("UPDATE links SET original_url = ?, title = ? WHERE id = ?");
            $stmt->execute([$newUrl, $title, $linkId]);
            $success = 'Bağlantı başarıyla güncellendi.';
        }
    }

    // Save Site Settings
    if ($action === 'save_settings') {
        $fields = ['site_name', 'site_description', 'site_tagline', 'guest_link_days', 'site_url'];
        foreach ($fields as $field) {
            set_setting($field, trim($_POST[$field] ?? ''));
        }
        // Checkbox: unchecked = not sent = '0'
        set_setting('allow_guest', isset($_POST['allow_guest']) ? '1' : '0');
        $success = 'Site ayarları başarıyla kaydedildi.';
    }
}

// --- Part 2: Load Administration Metrics ---

// Global statistics
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_links = $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();
$total_clicks = $pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();

// Retrieve Users Table
$users_list = $pdo->query("
    SELECT u.id, u.username, u.email, u.is_admin, u.created_at, COUNT(l.id) as link_count 
    FROM users u 
    LEFT JOIN links l ON u.id = l.user_id 
    GROUP BY u.id 
    ORDER BY u.created_at DESC
")->fetchAll();

// Retrieve All Links Table
$links_list = $pdo->query("
    SELECT l.*, u.username, COUNT(c.id) as click_count 
    FROM links l 
    LEFT JOIN users u ON l.user_id = u.id 
    LEFT JOIN clicks c ON l.id = c.link_id 
    GROUP BY l.id 
    ORDER BY l.created_at DESC
")->fetchAll();

// Retrieve Recent System-wide Clicks (Last 20)
$recent_clicks = $pdo->query("
    SELECT c.*, l.short_code, l.title 
    FROM clicks c 
    JOIN links l ON c.link_id = l.id 
    ORDER BY c.clicked_at DESC 
    LIMIT 20
")->fetchAll();

// Active tab helper
$active_tab = clean($_GET['tab'] ?? 'users');

// Site settings
$site_name        = get_setting('site_name', 'Click');
$site_description = get_setting('site_description', '');
$site_tagline     = get_setting('site_tagline', '');
$guest_link_days  = get_setting('guest_link_days', '15');
$allow_guest      = get_setting('allow_guest', '1');
$site_url         = get_setting('site_url', '');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetim Paneli - <?php echo clean($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .tabs-header {
            display: flex;
            gap: 12px;
            border-bottom: 1px solid var(--border-glass);
            margin-bottom: 24px;
            overflow-x: auto;
        }
        .tab-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 16px;
            font-weight: 500;
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .tab-btn:hover {
            color: var(--text-primary);
        }
        .tab-btn.active {
            color: var(--accent-indigo);
            border-bottom-color: var(--accent-indigo);
        }
        .badge-admin {
            background: rgba(168, 85, 247, 0.1);
            color: var(--accent-purple);
            border: 1px solid rgba(168, 85, 247, 0.2);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-user {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            border: 1px solid var(--border-glass);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-link"></i> <?php echo clean($site_name); ?>
            </a>
            <div class="nav-links">
                <span style="color: var(--accent-purple); font-size: 14px; font-weight: 600;">
                    <i class="fas fa-user-shield"></i> YÖNETİCİ: <?php echo clean($user['username']); ?>
                </span>
                <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-line"></i> Panelim</a>
                <a href="admin.php" class="nav-link active"><i class="fas fa-cogs"></i> Yönetim</a>
                <a href="logout.php" class="nav-btn nav-btn-outline"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="dashboard-wrapper container">
        
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Sistem Yönetim Paneli</h1>
                <p>Kullanıcı hesaplarını yönetin, tüm sistem linklerini denetleyin ve aktiviteleri izleyin.</p>
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

        <!-- Stats Overview Cards -->
        <div class="stats-grid">
            <div class="stat-card glass-panel">
                <div class="stat-icon purple"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($total_users); ?></span>
                    <span class="stat-label">Toplam Kayıtlı Üye</span>
                </div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-icon indigo"><i class="fas fa-link"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($total_links); ?></span>
                    <span class="stat-label">Sistemdeki Toplam Link</span>
                </div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-icon success"><i class="fas fa-mouse-pointer"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($total_clicks); ?></span>
                    <span class="stat-label">Toplam Ziyaret / Tıklanma</span>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="tabs-header">
            <a href="admin.php?tab=users" class="tab-btn <?php echo $active_tab === 'users' ? 'active' : ''; ?>">
                <i class="fas fa-user-friends"></i> Kullanıcı Yönetimi
            </a>
            <a href="admin.php?tab=links" class="tab-btn <?php echo $active_tab === 'links' ? 'active' : ''; ?>">
                <i class="fas fa-link"></i> Link Denetimi
            </a>
            <a href="admin.php?tab=logs" class="tab-btn <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Tıklama Kayıtları
            </a>
            <a href="admin.php?tab=settings" class="tab-btn <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-sliders-h"></i> Site Ayarları
            </a>
        </div>

        <!-- Tab 1: Users Administration -->
        <?php if ($active_tab === 'users'): ?>
            <div class="links-card glass-panel">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Kullanıcı ID</th>
                                <th>Kullanıcı Adı</th>
                                <th>E-posta Adresi</th>
                                <th>Rol / Yetki</th>
                                <th>Kısaltılan Link</th>
                                <th>Kayıt Tarihi</th>
                                <th style="text-align: right;">Eylemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users_list as $u_item): ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: bold;">#<?php echo $u_item['id']; ?></td>
                                    <td>
                                        <span style="font-weight: 600; color: var(--text-primary);">
                                            <?php echo clean($u_item['username']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo clean($u_item['email']); ?></td>
                                    <td>
                                        <?php if (intval($u_item['is_admin']) === 1): ?>
                                            <span class="badge-admin"><i class="fas fa-shield-alt"></i> Yönetici</span>
                                        <?php else: ?>
                                            <span class="badge-user">Standart Üye</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-clicks"><?php echo $u_item['link_count']; ?> Link</span>
                                    </td>
                                    <td class="date-col">
                                        <?php echo date('d.m.Y H:i', strtotime($u_item['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="actions-cell" style="justify-content: flex-end;">
                                            <!-- Toggle Admin Status -->
                                            <form method="POST" action="admin.php?tab=users" style="display:inline;" onsubmit="return confirm('Kullanıcı yetkisini değiştirmek istediğinizden emin misiniz?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_admin">
                                                <input type="hidden" name="user_id" value="<?php echo $u_item['id']; ?>">
                                                <button type="submit" class="btn-action" title="Yetki Değiştir" <?php echo intval($u_item['id']) === intval($user['id']) ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''; ?>>
                                                    <i class="fas fa-user-tag"></i>
                                                </button>
                                            </form>
                                            
                                            <!-- Delete User Account -->
                                            <form method="POST" action="admin.php?tab=users" style="display:inline;" onsubmit="return confirm('Kullanıcı hesabını silmek istediğinizden emin misiniz? Kullanıcıya ait tüm linkler ve tıklama geçmişi kalıcı olarak silinecektir!');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $u_item['id']; ?>">
                                                <button type="submit" class="btn-action btn-action-delete" title="Hesabı Sil" <?php echo intval($u_item['id']) === intval($user['id']) ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''; ?>>
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
            </div>
        
        <!-- Tab 2: Links Moderation -->
        <?php elseif ($active_tab === 'links'): ?>
            <div class="links-card glass-panel">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Başlık / Orijinal URL</th>
                                <th>Oluşturan</th>
                                <th>Kısa Link</th>
                                <th style="text-align: center;">Tıklanma</th>
                                <th>Oluşturulma</th>
                                <th style="text-align: right;">Eylemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($links_list as $link): ?>
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
                                        <span style="font-size: 13px; font-weight: 500;">
                                            <?php echo $link['username'] ? clean($link['username']) : '<span style="color:var(--text-muted);">Ziyaretçi</span>'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo clean($short_url); ?>" target="_blank" class="link-short">
                                            /<?php echo clean($link['short_code']); ?>
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
                                            <button class="btn-action btn-action-copy" data-url="<?php echo clean($short_url); ?>" title="Kopyala">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                            
                                            <button class="btn-action btn-action-edit" 
                                                    data-id="<?php echo $link['id']; ?>" 
                                                    data-code="<?php echo clean($link['short_code']); ?>" 
                                                    data-url="<?php echo clean($link['original_url']); ?>" 
                                                    title="Hedefi Düzenle">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <form method="POST" action="admin.php?tab=links" style="display:inline;" onsubmit="return confirm('Bu kısa bağlantıyı silmek istediğinizden emin misiniz?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_link">
                                                <input type="hidden" name="link_id" value="<?php echo $link['id']; ?>">
                                                <button type="submit" class="btn-action btn-action-delete" title="Bağlantıyı Sil">
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
            </div>

        <!-- Tab 3: System Activities logs -->
        <?php elseif ($active_tab === 'logs'): ?>
            <div class="links-card glass-panel">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Tıklanma Zamanı</th>
                                <th>Tıklanan Link</th>
                                <th>Ziyaretçi IP Adresi</th>
                                <th>Yönlendiren Referrer</th>
                                <th>Tarayıcı / Cihaz</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_clicks)): ?>
                                <tr>
                                    <td colspan="5" class="empty-state" style="padding: 40px 0;">Sistemde henüz tıklama kaydı yok.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_clicks as $clk): ?>
                                    <tr>
                                        <td class="date-col"><?php echo date('d.m.Y H:i:s', strtotime($clk['clicked_at'])); ?></td>
                                        <td>
                                            <a href="analytics.php?code=<?php echo clean($clk['short_code']); ?>" class="link-short" style="font-weight:bold;">
                                                /<?php echo clean($clk['short_code']); ?>
                                            </a>
                                            <span style="font-size:11px; display:block; color:var(--text-muted); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo clean($clk['title']); ?>
                                            </span>
                                        </td>
                                        <td style="font-family:monospace;"><?php echo clean($clk['ip_address']); ?></td>
                                        <td>
                                            <span class="link-original" style="max-width:200px;" title="<?php echo $clk['referrer'] ? clean($clk['referrer']) : 'Doğrudan Giriş'; ?>">
                                                <?php echo $clk['referrer'] ? clean($clk['referrer']) : '<span style="color:var(--text-muted);">Doğrudan Giriş</span>'; ?>
                                            </span>
                                        </td>
                                        <td style="font-size:13px; color:var(--text-secondary);">
                                            <?php echo clean($_SERVER['HTTP_USER_AGENT'] ?? 'Bilinmeyen Tarayıcı'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- Tab 4: Site Settings -->
        <?php elseif ($active_tab === 'settings'): ?>
            <div class="links-card glass-panel" style="max-width: 720px;">
                <h3 style="font-size:18px; font-weight:700; margin-bottom:24px;"><i class="fas fa-sliders-h"></i> Site Genel Ayarları</h3>
                <form method="POST" action="admin.php?tab=settings">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_settings">

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" for="site_name">Site Adı</label>
                        <input type="text" id="site_name" name="site_name" class="form-input"
                               value="<?php echo clean($site_name); ?>" placeholder="Click" required style="padding-left:18px;">
                        <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">Sitenin logo ve tarayıcı sekmesinde görünecek ad.</span>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" for="site_description">Site Açıklaması (Meta)</label>
                        <input type="text" id="site_description" name="site_description" class="form-input"
                               value="<?php echo clean($site_description); ?>" placeholder="Hızlı ve güvenilir link kısaltıcı" style="padding-left:18px;">
                        <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">Tarayıcı sekmesi ve SEO meta description için kullanılır.</span>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" for="site_tagline">Ana Sayfa Sloganı</label>
                        <input type="text" id="site_tagline" name="site_tagline" class="form-input"
                               value="<?php echo clean($site_tagline); ?>" placeholder="Uzun bağlantılarınızı tek tıkla akıllı hale getirin" style="padding-left:18px;">
                        <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">Ana sayfada hero bölümünde görünen alt başlık.</span>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" for="site_url">Site URL / Alan Adı (Domain)</label>
                        <input type="text" id="site_url" name="site_url" class="form-input"
                               value="<?php echo clean($site_url); ?>" placeholder="http://127.0.0.1:8000" style="padding-left:18px;">
                        <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">Kısaltılmış bağlantılar için kullanılacak varsayılan alan adı (örn: <code>https://hellologi.com.tr</code>). Boş bırakırsanız tarayıcının çalıştığı adres otomatik kullanılır.</span>
                    </div>

                    <hr style="border:0; border-top:1px solid var(--border-glass); margin:28px 0;">
                    <h3 style="font-size:16px; font-weight:700; margin-bottom:20px;"><i class="fas fa-user-secret"></i> Misafir Link Ayarları</h3>

                    <div style="display:flex; align-items:center; gap:14px; margin-bottom:24px; padding:16px; background:rgba(255,255,255,0.03); border-radius:10px; border:1px solid var(--border-glass);">
                        <label class="form-label" style="margin:0; flex:1;">Misafir Link Oluşturmaya İzin Ver</label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="allow_guest" id="allow_guest" value="1" <?php echo $allow_guest === '1' ? 'checked' : ''; ?>
                                   style="width:18px; height:18px; accent-color: var(--accent-indigo); cursor:pointer;">
                            <span style="font-size:14px;">Aktif</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:32px;">
                        <label class="form-label" for="guest_link_days">Misafir Link Güncel Kalma Süresi (Gün)</label>
                        <input type="number" id="guest_link_days" name="guest_link_days" class="form-input"
                               value="<?php echo clean($guest_link_days); ?>" min="1" max="365" style="padding-left:18px; max-width:200px;">
                        <span style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">Oturum açmadan oluşturulan linkler bu süre sonunda otomatik silinir.</span>
                    </div>

                    <button type="submit" class="btn-submit" style="width:auto; padding: 12px 32px;">
                        <i class="fas fa-save"></i> Ayarları Kaydet
                    </button>
                </form>
            </div>
        <?php endif; ?>

    </main>

    <!-- Edit Link Modal (Admin Edition) -->
    <div class="modal" id="edit-modal">
        <div class="modal-content glass-panel">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <h3 class="modal-title">Sistem Bağlantısını Düzenle</h3>
            <form method="POST" action="admin.php?tab=links">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="edit_link">
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
                    <button type="submit" class="btn-submit" style="padding: 10px 24px; font-size: 14px;">Kaydet</button>
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
</body>
</html>
