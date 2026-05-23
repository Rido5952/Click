<?php
// profile.php — Click User Profile & Settings
require_once 'config.php';
require_login();

$user      = get_current_user_data();
$site_name = get_setting('site_name', 'Click');

$error   = '';
$success = '';

// Decode social_links JSON
$social = [];
if (!empty($user['social_links'])) {
    $social = json_decode($user['social_links'], true) ?: [];
}

// Active tab
$active_tab = in_array($_GET['tab'] ?? '', ['profile','security','api','notifications','referral'])
    ? $_GET['tab']
    : 'profile';

// =========================================================
// POST ACTION HANDLERS
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforce_csrf();
    $action = $_POST['action'] ?? '';

    // ── 1. Update Profile ────────────────────────────────
    if ($action === 'update_profile') {
        $active_tab   = 'profile';
        $display_name = trim($_POST['display_name'] ?? '');
        $bio          = trim($_POST['bio'] ?? '');
        $avatar_url   = trim($_POST['avatar_url'] ?? '');
        $bio_bg_color = trim($_POST['bio_bg_color'] ?? '#0f0f1a');

        $twitter   = trim($_POST['twitter'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $github    = trim($_POST['github'] ?? '');
        $website   = trim($_POST['website'] ?? '');

        if (mb_strlen($display_name) > 100) {
            $error = 'Görünen ad en fazla 100 karakter olabilir.';
        } elseif (!empty($avatar_url) && !is_valid_url($avatar_url)) {
            $error = 'Avatar URL geçerli bir web adresi olmalıdır.';
        } elseif (!empty($website) && !is_valid_url($website)) {
            $error = 'Web sitesi URL geçerli bir adres olmalıdır.';
        } else {
            // Validate hex color
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $bio_bg_color)) {
                $bio_bg_color = '#0f0f1a';
            }

            $social_arr = [
                'twitter'   => $twitter,
                'instagram' => $instagram,
                'github'    => $github,
                'website'   => $website,
            ];
            $social_json = json_encode($social_arr, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("UPDATE users SET display_name=?, bio=?, avatar_url=?, bio_bg_color=?, social_links=? WHERE id=?");
            $stmt->execute([$display_name ?: null, $bio ?: null, $avatar_url ?: null, $bio_bg_color, $social_json, $user['id']]);

            $success = 'Profil bilgileriniz başarıyla güncellendi.';
            $user    = get_current_user_data();
            $social  = $social_arr;
        }
    }

    // ── 2. Update Password ───────────────────────────────
    elseif ($action === 'update_password') {
        $active_tab      = 'security';
        $current_pass    = $_POST['current_password'] ?? '';
        $new_pass        = $_POST['new_password'] ?? '';
        $confirm_pass    = $_POST['confirm_password'] ?? '';

        if (!password_verify($current_pass, $user['password'])) {
            $error = 'Mevcut şifreniz hatalı.';
        } elseif (strlen($new_pass) < 6) {
            $error = 'Yeni şifre en az 6 karakter olmalıdır.';
        } elseif ($new_pass !== $confirm_pass) {
            $error = 'Yeni şifreler eşleşmiyor.';
        } else {
            $hash = hash_password($new_pass);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $user['id']]);
            $success = 'Şifreniz başarıyla güncellendi.';
            $user    = get_current_user_data();
        }
    }

    // ── 3. Generate API Key ──────────────────────────────
    elseif ($action === 'generate_api_key') {
        $active_tab = 'api';
        $new_key    = generate_api_key();
        $pdo->prepare("UPDATE users SET api_key=? WHERE id=?")->execute([$new_key, $user['id']]);
        $success = 'Yeni API anahtarı oluşturuldu.';
        $user    = get_current_user_data();
    }

    // ── 4. Update Notifications ──────────────────────────
    elseif ($action === 'update_notifications') {
        $active_tab       = 'notifications';
        $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');
        $pdo->prepare("UPDATE users SET telegram_chat_id=? WHERE id=?")->execute([$telegram_chat_id ?: null, $user['id']]);
        $success = 'Bildirim ayarlarınız kaydedildi.';
        $user    = get_current_user_data();
    }

    // ── 5. Update Theme ──────────────────────────────────
    elseif ($action === 'update_theme') {
        $active_tab = 'profile';
        $theme      = ($_POST['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
        $pdo->prepare("UPDATE users SET theme=? WHERE id=?")->execute([$theme, $user['id']]);
        $_SESSION['theme'] = $theme;
        $success = 'Tema tercihiniz kaydedildi.';
        $user    = get_current_user_data();
    }

    // ── 6. Test Telegram ─────────────────────────────────
    elseif ($action === 'test_telegram') {
        $active_tab = 'notifications';
        $chat_id    = $user['telegram_chat_id'] ?? '';
        if (empty($chat_id)) {
            $error = 'Önce Telegram Chat ID kaydedin.';
        } else {
            $sent = send_telegram($chat_id, "✅ <b>" . htmlspecialchars($site_name) . "</b>\n\nTelegram bildirimleri başarıyla aktif! Bu bir test mesajıdır.");
            $success = $sent ? 'Test mesajı gönderildi!' : 'Gönderim başarısız. Bot token veya Chat ID kontrol edin.';
        }
    }
}

// ── Referral count ───────────────────────────────────────
$ref_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ?");
$ref_stmt->execute([$user['id']]);
$referred_count = (int)$ref_stmt->fetchColumn();

$referral_link = get_base_url() . '/register.php?ref=' . urlencode($user['referral_code'] ?? '');
$base_url      = get_base_url();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Ayarları — <?php echo clean($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ── Tab navigation ── */
        .profile-tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 28px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding-bottom: 0;
        }
        .profile-tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px 12px 0 0;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all .2s;
            border: 1px solid transparent;
            border-bottom: none;
            position: relative;
            bottom: -1px;
        }
        .profile-tab:hover {
            color: var(--text-primary);
            background: rgba(255,255,255,0.05);
        }
        .profile-tab.active {
            color: #818cf8;
            background: rgba(99,102,241,0.12);
            border-color: rgba(255,255,255,0.08);
            border-bottom-color: transparent;
        }
        .profile-tab.active i { color: #818cf8; }

        /* ── Form section ── */
        .settings-section { display: none; }
        .settings-section.active { display: block; }

        .settings-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
        }
        .settings-card-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .settings-card-title i {
            color: #818cf8;
            font-size: 18px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .form-control {
            width: 100%;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        }
        textarea.form-control { resize: vertical; min-height: 100px; }

        /* Color picker row */
        .color-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .color-row input[type="color"] {
            width: 48px;
            height: 48px;
            border: 2px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 2px;
            background: transparent;
            cursor: pointer;
        }
        .color-row .form-control { flex: 1; }

        /* API key display */
        .api-key-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 12px 16px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #a5b4fc;
            letter-spacing: .5px;
            word-break: break-all;
            margin-bottom: 16px;
        }
        .api-key-box .key-text { flex: 1; }
        .btn-icon {
            background: rgba(99,102,241,0.15);
            border: 1px solid rgba(99,102,241,0.3);
            border-radius: 8px;
            color: #818cf8;
            padding: 7px 10px;
            cursor: pointer;
            font-size: 13px;
            transition: background .2s;
        }
        .btn-icon:hover { background: rgba(99,102,241,0.3); }

        /* Plan badge */
        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .plan-free     { background: rgba(100,116,139,0.2); color: #94a3b8; border: 1px solid rgba(100,116,139,.3); }
        .plan-pro      { background: rgba(99,102,241,0.2);  color: #818cf8; border: 1px solid rgba(99,102,241,.3); }
        .plan-business { background: rgba(234,179,8,0.15);  color: #fbbf24; border: 1px solid rgba(234,179,8,.3); }

        /* Referral code box */
        .referral-code-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(99,102,241,0.08);
            border: 1px solid rgba(99,102,241,0.25);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        .referral-code-value {
            font-size: 26px;
            font-weight: 900;
            color: #a5b4fc;
            letter-spacing: 4px;
            font-family: 'Courier New', monospace;
            flex: 1;
        }
        .referral-stat {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 12px;
        }
        .referral-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: rgba(99,102,241,0.15);
            color: #818cf8;
        }
        .referral-stat-info { flex: 1; }
        .referral-stat-value { font-size: 22px; font-weight: 800; color: var(--text-primary); }
        .referral-stat-label { font-size: 13px; color: var(--text-secondary); }

        /* Telegram instruction */
        .tg-instructions {
            background: rgba(29,161,242,0.07);
            border: 1px solid rgba(29,161,242,0.2);
            border-radius: 12px;
            padding: 16px 20px;
            font-size: 13px;
            color: #93c5fd;
            line-height: 1.7;
        }
        .tg-instructions strong { color: #bfdbfe; }
        .tg-instructions ol { padding-left: 20px; margin: 8px 0 0; }

        /* 2FA badge */
        .twofa-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }
        .twofa-on  { background: rgba(34,197,94,0.12); color: #4ade80; border: 1px solid rgba(34,197,94,.25); }
        .twofa-off { background: rgba(239,68,68,0.12);  color: #f87171; border: 1px solid rgba(239,68,68,.25); }

        /* Submit button */
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 28px;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
        }
        .btn-primary:hover { opacity: .9; transform: translateY(-1px); }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 28px;
            cursor: pointer;
            transition: opacity .2s;
        }
        .btn-danger:hover { opacity: .9; }
        .btn-secondary {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 600;
            padding: 12px 28px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background .2s;
        }
        .btn-secondary:hover { background: rgba(255,255,255,0.12); color: var(--text-primary); }

        /* Divider */
        .settings-divider {
            height: 1px;
            background: rgba(255,255,255,0.06);
            margin: 24px 0;
        }

        /* Avatar preview */
        .avatar-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(99,102,241,0.4);
            margin-bottom: 12px;
            display: block;
        }
        .avatar-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(99,102,241,0.15);
            border: 3px solid rgba(99,102,241,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #818cf8;
            margin-bottom: 12px;
        }

        .theme-toggle-group {
            display: flex;
            gap: 12px;
        }
        .theme-option {
            flex: 1;
            border: 2px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            text-align: center;
        }
        .theme-option input[type="radio"] { display: none; }
        .theme-option:has(input:checked) {
            border-color: #6366f1;
            background: rgba(99,102,241,0.1);
        }
        .theme-option-icon { font-size: 28px; margin-bottom: 8px; }
        .theme-option-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .theme-option:has(input:checked) .theme-option-label { color: #818cf8; }
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
                <span style="color: var(--text-secondary); font-size: 14px; font-weight: 500;">
                    <i class="fas fa-user-circle"></i> <?php echo clean($user['username']); ?>
                </span>
                <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-line"></i> Panelim</a>
                <?php if (intval($user['is_admin']) === 1): ?>
                    <a href="admin.php" class="nav-link"><i class="fas fa-cogs"></i> Yönetim</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-link active"><i class="fas fa-user-cog"></i> Profil</a>
                <a href="logout.php" class="nav-btn nav-btn-outline"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
            </div>
        </div>
    </header>

    <main class="container" style="padding: 40px 20px; max-width: 900px; margin: 0 auto;">

        <!-- Page heading -->
        <div style="margin-bottom: 32px;">
            <h1 style="font-size: 28px; font-weight: 800; color: var(--text-primary); margin-bottom: 6px;">
                <i class="fas fa-user-cog" style="color:#818cf8;"></i> Profil & Ayarlar
            </h1>
            <p style="color: var(--text-secondary); font-size: 15px;">Hesap bilgilerinizi, güvenlik ayarlarınızı ve tercihlerinizi yönetin.</p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo clean($error); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> <?php echo clean($success); ?>
            </div>
        <?php endif; ?>

        <!-- Tab navigation -->
        <div class="profile-tabs">
            <a href="?tab=profile"       class="profile-tab <?php echo $active_tab === 'profile'       ? 'active' : ''; ?>"><i class="fas fa-user"></i> Profil</a>
            <a href="?tab=security"      class="profile-tab <?php echo $active_tab === 'security'      ? 'active' : ''; ?>"><i class="fas fa-shield-alt"></i> Güvenlik</a>
            <a href="?tab=api"           class="profile-tab <?php echo $active_tab === 'api'           ? 'active' : ''; ?>"><i class="fas fa-code"></i> API</a>
            <a href="?tab=notifications" class="profile-tab <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>"><i class="fas fa-bell"></i> Bildirimler</a>
            <a href="?tab=referral"      class="profile-tab <?php echo $active_tab === 'referral'      ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Referans</a>
        </div>

        <!-- ══════════════════════════════════════════════════════
             TAB 1: PROFILE
        ══════════════════════════════════════════════════════ -->
        <div class="settings-section <?php echo $active_tab === 'profile' ? 'active' : ''; ?>">

            <!-- Profile info form -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-id-card"></i> Profil Bilgileri</div>

                <form method="POST" action="profile.php?tab=profile">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profile">

                    <!-- Avatar preview -->
                    <div style="margin-bottom: 20px;">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="<?php echo clean($user['avatar_url']); ?>" alt="Avatar" class="avatar-preview" id="avatar-preview-img">
                        <?php else: ?>
                            <div class="avatar-placeholder" id="avatar-placeholder"><i class="fas fa-user"></i></div>
                            <img src="" alt="Avatar" class="avatar-preview" id="avatar-preview-img" style="display:none;">
                        <?php endif; ?>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Görünen Ad</label>
                            <input type="text" name="display_name" class="form-control"
                                   value="<?php echo clean($user['display_name'] ?? ''); ?>"
                                   placeholder="<?php echo clean($user['username']); ?>"
                                   maxlength="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Avatar URL</label>
                            <input type="url" name="avatar_url" class="form-control" id="avatar-url-input"
                                   value="<?php echo clean($user['avatar_url'] ?? ''); ?>"
                                   placeholder="https://example.com/avatar.jpg">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Biyografi</label>
                        <textarea name="bio" class="form-control" placeholder="Kendinizden kısaca bahsedin..."><?php echo clean($user['bio'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Profil Sayfası Arka Plan Rengi</label>
                        <div class="color-row">
                            <input type="color" id="color-picker" value="<?php echo clean($user['bio_bg_color'] ?? '#0f0f1a'); ?>"
                                   oninput="document.getElementById('color-text').value = this.value">
                            <input type="text" name="bio_bg_color" id="color-text" class="form-control"
                                   value="<?php echo clean($user['bio_bg_color'] ?? '#0f0f1a'); ?>" maxlength="9"
                                   oninput="syncColor(this.value)">
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <div class="settings-card-title" style="margin-bottom: 16px;"><i class="fas fa-share-alt"></i> Sosyal Bağlantılar</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-twitter" style="color:#1d9bf0;"></i> Twitter / X</label>
                            <input type="text" name="twitter" class="form-control"
                                   value="<?php echo clean($social['twitter'] ?? ''); ?>"
                                   placeholder="@kullaniciadiniz">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-instagram" style="color:#e1306c;"></i> Instagram</label>
                            <input type="text" name="instagram" class="form-control"
                                   value="<?php echo clean($social['instagram'] ?? ''); ?>"
                                   placeholder="@kullaniciadiniz">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><i class="fab fa-github" style="color:#e2e8f0;"></i> GitHub</label>
                            <input type="text" name="github" class="form-control"
                                   value="<?php echo clean($social['github'] ?? ''); ?>"
                                   placeholder="kullaniciadiniz">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-globe" style="color:#818cf8;"></i> Web Sitesi</label>
                            <input type="url" name="website" class="form-control"
                                   value="<?php echo clean($social['website'] ?? ''); ?>"
                                   placeholder="https://siteniz.com">
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Profili Kaydet</button>
                        <?php if (!empty($user['username'])): ?>
                            <a href="u/<?php echo urlencode($user['username']); ?>" target="_blank" class="btn-secondary">
                                <i class="fas fa-external-link-alt"></i> Bio Sayfamı Görüntüle
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Theme -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-palette"></i> Tema Tercihi</div>
                <form method="POST" action="profile.php?tab=profile">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_theme">
                    <div class="theme-toggle-group" style="margin-bottom: 20px;">
                        <label class="theme-option">
                            <input type="radio" name="theme" value="dark" <?php echo ($user['theme'] ?? 'dark') === 'dark' ? 'checked' : ''; ?>>
                            <div class="theme-option-icon">🌙</div>
                            <div class="theme-option-label">Koyu Tema</div>
                        </label>
                        <label class="theme-option">
                            <input type="radio" name="theme" value="light" <?php echo ($user['theme'] ?? 'dark') === 'light' ? 'checked' : ''; ?>>
                            <div class="theme-option-icon">☀️</div>
                            <div class="theme-option-label">Açık Tema</div>
                        </label>
                    </div>
                    <button type="submit" class="btn-primary"><i class="fas fa-paint-brush"></i> Temayı Kaydet</button>
                </form>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             TAB 2: SECURITY
        ══════════════════════════════════════════════════════ -->
        <div class="settings-section <?php echo $active_tab === 'security' ? 'active' : ''; ?>">

            <!-- Change Password -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-lock"></i> Şifre Değiştir</div>
                <form method="POST" action="profile.php?tab=security">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_password">

                    <div class="form-group">
                        <label class="form-label">Mevcut Şifre</label>
                        <input type="password" name="current_password" class="form-control"
                               placeholder="••••••••" required autocomplete="current-password">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Yeni Şifre</label>
                            <input type="password" name="new_password" class="form-control"
                                   placeholder="En az 6 karakter" required minlength="6" autocomplete="new-password"
                                   oninput="checkPwStrength(this.value)">
                            <div id="pw-strength-bar" style="height:4px; border-radius:4px; margin-top:8px; background:rgba(255,255,255,0.07); overflow:hidden;">
                                <div id="pw-strength-fill" style="height:100%; width:0%; border-radius:4px; transition:all .3s;"></div>
                            </div>
                            <div id="pw-strength-label" style="font-size:11px; margin-top:4px; color:var(--text-secondary);"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Yeni Şifre (Tekrar)</label>
                            <input type="password" name="confirm_password" class="form-control"
                                   placeholder="Şifreyi tekrarlayın" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary"><i class="fas fa-key"></i> Şifreyi Güncelle</button>
                </form>
            </div>

            <!-- 2FA Status -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-shield-alt"></i> İki Faktörlü Doğrulama (2FA)</div>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 18px;">
                    Hesabınıza ekstra güvenlik katmanı ekleyin. 2FA aktifken giriş yaparken bir doğrulama kodu gerekir.
                </p>
                <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <?php if (!empty($user['totp_enabled']) && intval($user['totp_enabled']) === 1): ?>
                        <span class="twofa-status twofa-on"><i class="fas fa-check-circle"></i> 2FA Aktif</span>
                    <?php else: ?>
                        <span class="twofa-status twofa-off"><i class="fas fa-times-circle"></i> 2FA Pasif</span>
                    <?php endif; ?>
                    <a href="2fa-setup.php" class="btn-secondary">
                        <i class="fas fa-qrcode"></i>
                        <?php echo (!empty($user['totp_enabled']) && intval($user['totp_enabled']) === 1) ? '2FA Ayarlarını Yönet' : '2FA Aktifleştir'; ?>
                    </a>
                </div>
                <div style="margin-top: 16px; padding: 14px; background: rgba(251,191,36,0.07); border: 1px solid rgba(251,191,36,0.2); border-radius: 10px; font-size: 13px; color: #fde68a;">
                    <i class="fas fa-exclamation-triangle"></i>
                    2FA aktifleştirildiğinde, giriş yaparken Google Authenticator veya benzeri bir uygulama üzerinden kod girmeniz gerekecektir.
                </div>
            </div>

            <!-- Account Info -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-info-circle"></i> Hesap Bilgileri</div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div>
                        <div style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Kullanıcı Adı</div>
                        <div style="font-weight:600; color:var(--text-primary);"><?php echo clean($user['username']); ?></div>
                    </div>
                    <div>
                        <div style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">E-posta</div>
                        <div style="font-weight:600; color:var(--text-primary);"><?php echo clean($user['email']); ?></div>
                    </div>
                    <div>
                        <div style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Kayıt Tarihi</div>
                        <div style="font-weight:600; color:var(--text-primary);"><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></div>
                    </div>
                    <div>
                        <div style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Plan</div>
                        <?php
                            $plan = strtolower($user['plan'] ?? 'free');
                            $planLabels = ['free' => 'Ücretsiz', 'pro' => 'Pro', 'business' => 'Business'];
                            $planIcons  = ['free' => 'fa-user', 'pro' => 'fa-star', 'business' => 'fa-crown'];
                        ?>
                        <span class="plan-badge plan-<?php echo clean($plan); ?>">
                            <i class="fas <?php echo $planIcons[$plan] ?? 'fa-user'; ?>"></i>
                            <?php echo $planLabels[$plan] ?? 'Ücretsiz'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             TAB 3: API
        ══════════════════════════════════════════════════════ -->
        <div class="settings-section <?php echo $active_tab === 'api' ? 'active' : ''; ?>">

            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-key"></i> API Anahtarınız</div>

                <?php
                    $plan = strtolower($user['plan'] ?? 'free');
                    $has_api = in_array($plan, ['pro', 'business']);
                ?>

                <?php if (!$has_api): ?>
                    <div style="background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.25); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
                            <i class="fas fa-lock" style="color:#fbbf24; font-size:20px;"></i>
                            <strong style="color:#fde68a; font-size:15px;">API erişimi Pro veya Business plana gerektirir</strong>
                        </div>
                        <p style="color:#fde68a; font-size:13px; margin-bottom:14px; opacity:0.85;">
                            API anahtarı oluşturmak ve kullanmak için planınızı yükseltin.
                        </p>
                        <a href="plans.php" class="btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                            <i class="fas fa-arrow-up"></i> Planı Yükselt
                        </a>
                    </div>
                <?php else: ?>
                    <?php if (!empty($user['api_key'])): ?>
                        <div class="api-key-box">
                            <span class="key-text" id="api-key-display">
                                <?php echo str_repeat('•', 20) . substr($user['api_key'], -8); ?>
                            </span>
                            <button type="button" class="btn-icon" id="toggle-key-btn" title="Göster/Gizle"
                                    data-key="<?php echo clean($user['api_key']); ?>">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button type="button" class="btn-icon" id="copy-key-btn" title="Kopyala"
                                    data-key="<?php echo clean($user['api_key']); ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 16px;">
                            Henüz bir API anahtarınız yok. Aşağıdaki düğmeyle oluşturabilirsiniz.
                        </p>
                    <?php endif; ?>

                    <form method="POST" action="profile.php?tab=api"
                          onsubmit="return !<?php echo !empty($user['api_key']) ? 'true' : 'false'; ?> || confirm('Mevcut API anahtarınız geçersiz olacak. Emin misiniz?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="generate_api_key">
                        <button type="submit" class="btn-primary" style="margin-bottom: 0;">
                            <i class="fas fa-sync-alt"></i>
                            <?php echo !empty($user['api_key']) ? 'API Anahtarını Yenile' : 'API Anahtarı Oluştur'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Plan & limits -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-layer-group"></i> Mevcut Plan & Limitler</div>
                <?php
                    $planName  = ['free'=>'Ücretsiz','pro'=>'Pro','business'=>'Business'][$plan] ?? 'Ücretsiz';
                    $planIcon  = ['free'=>'fa-user','pro'=>'fa-star','business'=>'fa-crown'][$plan] ?? 'fa-user';
                    $planClass = 'plan-' . $plan;
                    $limits = [
                        'free'     => ['link_limit' => '50',        'analytics' => '7 gün',   'api' => false],
                        'pro'      => ['link_limit' => 'Sınırsız',  'analytics' => '30 gün',  'api' => true],
                        'business' => ['link_limit' => 'Sınırsız',  'analytics' => 'Tüm süre','api' => true],
                    ];
                    $lim = $limits[$plan] ?? $limits['free'];
                ?>
                <div style="display:flex; align-items:center; gap:14px; margin-bottom:20px;">
                    <span class="plan-badge <?php echo $planClass; ?>">
                        <i class="fas <?php echo $planIcon; ?>"></i> <?php echo $planName; ?>
                    </span>
                    <a href="plans.php" class="btn-secondary" style="font-size:13px; padding: 8px 16px;">
                        <i class="fas fa-arrow-up"></i> Planları Karşılaştır
                    </a>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px;">
                    <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:14px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:var(--text-primary);"><?php echo $lim['link_limit']; ?></div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Link Limiti</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:14px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:var(--text-primary);"><?php echo $lim['analytics']; ?></div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Analitik Geçmişi</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:14px; text-align:center;">
                        <div style="font-size:22px; font-weight:800; color:<?php echo $lim['api'] ? '#4ade80' : '#f87171'; ?>;">
                            <i class="fas <?php echo $lim['api'] ? 'fa-check' : 'fa-times'; ?>"></i>
                        </div>
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">API Erişimi</div>
                    </div>
                </div>
            </div>

            <!-- API docs link -->
            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-book"></i> API Dokümantasyonu</div>
                <p style="color:var(--text-secondary); font-size:14px; margin-bottom:16px;">
                    API ile link kısaltma, listeleme, silme ve istatistik alma işlemlerini otomatize edebilirsiniz.
                </p>
                <a href="api-docs.php" class="btn-secondary">
                    <i class="fas fa-external-link-alt"></i> API Dökümanlarını Görüntüle
                </a>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             TAB 4: NOTIFICATIONS
        ══════════════════════════════════════════════════════ -->
        <div class="settings-section <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>">

            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fab fa-telegram-plane" style="color:#2AABEE;"></i> Telegram Bildirimleri</div>

                <form method="POST" action="profile.php?tab=notifications">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_notifications">

                    <div class="form-group">
                        <label class="form-label">Telegram Chat ID</label>
                        <input type="text" name="telegram_chat_id" class="form-control"
                               value="<?php echo clean($user['telegram_chat_id'] ?? ''); ?>"
                               placeholder="Örnek: 123456789">
                        <div style="font-size:12px; color:var(--text-secondary); margin-top:6px;">
                            <i class="fas fa-info-circle"></i> Chat ID'nizi öğrenmek için <strong style="color:#93c5fd;">@userinfobot</strong>'a mesaj gönderin.
                        </div>
                    </div>

                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Kaydet</button>
                    </div>
                </form>

                <?php if (!empty($user['telegram_chat_id'])): ?>
                    <div class="settings-divider"></div>
                    <form method="POST" action="profile.php?tab=notifications" style="margin-top:0;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="test_telegram">
                        <button type="submit" class="btn-secondary">
                            <i class="fab fa-telegram-plane" style="color:#2AABEE;"></i> Test Mesajı Gönder
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-question-circle"></i> Nasıl Yapılır?</div>
                <div class="tg-instructions">
                    <strong>Telegram Chat ID'nizi bulmak için:</strong>
                    <ol>
                        <li>Telegram uygulamasını açın ve <strong>@userinfobot</strong>'u aratın.</li>
                        <li>Bota <strong>/start</strong> yazın ve ID numaranızı kopyalayın.</li>
                        <li>Bildirimleri almak için <strong>@<?php echo clean(get_setting('site_name','click')); ?>_bot</strong>'a da <em>/start</em> göndermeniz gerekir.</li>
                        <li>ID'nizi yukarıdaki alana yapıştırın ve kaydedin.</li>
                        <li>Test butonu ile bağlantıyı doğrulayın.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             TAB 5: REFERRAL
        ══════════════════════════════════════════════════════ -->
        <div class="settings-section <?php echo $active_tab === 'referral' ? 'active' : ''; ?>">

            <div class="settings-card glass-panel">
                <div class="settings-card-title"><i class="fas fa-gift"></i> Referans Programı</div>
                <p style="color:var(--text-secondary); font-size:14px; margin-bottom:22px;">
                    Arkadaşlarınızı davet edin! Her başarılı davet için özel avantajlar kazanabilirsiniz.
                </p>

                <!-- Code display -->
                <div style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px;">Referans Kodunuz</div>
                <div class="referral-code-box">
                    <div class="referral-code-value" id="ref-code-display"><?php echo clean($user['referral_code'] ?? '—'); ?></div>
                    <button type="button" class="btn-icon" id="copy-ref-code" data-code="<?php echo clean($user['referral_code'] ?? ''); ?>" title="Kodu Kopyala">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>

                <!-- Link display -->
                <div style="font-size:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px;">Referans Linkiniz</div>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:24px;">
                    <div style="flex:1; background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.08); border-radius:10px; padding:12px 16px; font-size:13px; color:#a5b4fc; word-break:break-all; font-family:'Courier New',monospace;"
                         id="ref-link-display"><?php echo clean($referral_link); ?></div>
                    <button type="button" class="btn-icon" id="copy-ref-link" data-link="<?php echo clean($referral_link); ?>" title="Linki Kopyala">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>

                <!-- Stats -->
                <div class="referral-stat">
                    <div class="referral-stat-icon"><i class="fas fa-users"></i></div>
                    <div class="referral-stat-info">
                        <div class="referral-stat-value"><?php echo number_format($referred_count); ?></div>
                        <div class="referral-stat-label">Davet Ettiğiniz Kullanıcı</div>
                    </div>
                </div>

                <div style="padding: 16px 20px; background: rgba(99,102,241,0.06); border: 1px solid rgba(99,102,241,0.15); border-radius: 12px; font-size: 13px; color: #a5b4fc; margin-top: 10px;">
                    <i class="fas fa-sparkles" style="margin-right:6px;"></i>
                    Referans programı hakkında detaylı bilgi almak için <a href="plans.php" style="color:#818cf8; font-weight:600;">planlar sayfasını</a> ziyaret edin.
                </div>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo clean($site_name); ?>. Tüm Hakları Saklıdır.</p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
    <script>
    // ── Color picker sync ──────────────────────────────────────────────
    function syncColor(val) {
        const picker = document.getElementById('color-picker');
        if (/^#[0-9a-fA-F]{3,8}$/.test(val) && picker) {
            picker.value = val.substring(0, 7);
        }
    }
    document.getElementById('color-picker')?.addEventListener('input', function() {
        const txt = document.getElementById('color-text');
        if (txt) txt.value = this.value;
    });

    // ── Avatar URL live preview ────────────────────────────────────────
    document.getElementById('avatar-url-input')?.addEventListener('input', function() {
        const img = document.getElementById('avatar-preview-img');
        const placeholder = document.getElementById('avatar-placeholder');
        if (this.value.trim()) {
            if (img) { img.src = this.value.trim(); img.style.display = 'block'; }
            if (placeholder) placeholder.style.display = 'none';
        } else {
            if (img) { img.src = ''; img.style.display = 'none'; }
            if (placeholder) placeholder.style.display = 'flex';
        }
    });

    // ── API key reveal toggle ──────────────────────────────────────────
    const toggleKeyBtn = document.getElementById('toggle-key-btn');
    const apiKeyDisplay = document.getElementById('api-key-display');
    if (toggleKeyBtn && apiKeyDisplay) {
        let revealed = false;
        const fullKey = toggleKeyBtn.dataset.key;
        toggleKeyBtn.addEventListener('click', () => {
            revealed = !revealed;
            apiKeyDisplay.textContent = revealed ? fullKey : ('•'.repeat(20) + fullKey.slice(-8));
            toggleKeyBtn.innerHTML = revealed ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        });
    }

    // ── API key copy ───────────────────────────────────────────────────
    document.getElementById('copy-key-btn')?.addEventListener('click', function() {
        navigator.clipboard.writeText(this.dataset.key).then(() => {
            const orig = this.innerHTML;
            this.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(() => this.innerHTML = orig, 1500);
        });
    });

    // ── Referral code copy ─────────────────────────────────────────────
    document.getElementById('copy-ref-code')?.addEventListener('click', function() {
        navigator.clipboard.writeText(this.dataset.code).then(() => {
            const orig = this.innerHTML;
            this.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(() => this.innerHTML = orig, 1500);
        });
    });

    // ── Referral link copy ─────────────────────────────────────────────
    document.getElementById('copy-ref-link')?.addEventListener('click', function() {
        navigator.clipboard.writeText(this.dataset.link).then(() => {
            const orig = this.innerHTML;
            this.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(() => this.innerHTML = orig, 1500);
        });
    });

    // ── Password strength meter ────────────────────────────────────────
    function checkPwStrength(val) {
        const fill  = document.getElementById('pw-strength-fill');
        const label = document.getElementById('pw-strength-label');
        if (!fill || !label) return;
        let score = 0;
        if (val.length >= 6)  score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const configs = [
            {w:'0%',   c:'transparent', t:''},
            {w:'25%',  c:'#ef4444',     t:'Çok Zayıf'},
            {w:'45%',  c:'#f97316',     t:'Zayıf'},
            {w:'65%',  c:'#eab308',     t:'Orta'},
            {w:'85%',  c:'#22c55e',     t:'Güçlü'},
            {w:'100%', c:'#10b981',     t:'Çok Güçlü'},
        ];
        const cfg = configs[Math.min(score, 5)];
        fill.style.width = cfg.w;
        fill.style.background = cfg.c;
        label.textContent = cfg.t;
        label.style.color = cfg.c;
    }
    </script>

    <?php if (!empty($success)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof showToast === 'function') showToast(<?php echo json_encode($success); ?>, 'success');
    });
    </script>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof showToast === 'function') showToast(<?php echo json_encode($error); ?>, 'error');
    });
    </script>
    <?php endif; ?>
</body>
</html>
