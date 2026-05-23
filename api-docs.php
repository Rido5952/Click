<?php
// api-docs.php - Click v2.0 API Documentation
require_once 'config.php';
$site_name = get_setting('site_name', 'Click');
$base = get_base_url();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Dokümantasyonu — <?php echo clean($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .api-section { margin-bottom: 40px; }
        .endpoint-card { background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); border-radius: 12px; padding: 24px; margin-bottom: 16px; }
        .method-badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; margin-right: 10px; font-family: monospace; }
        .method-get  { background: rgba(16,185,129,0.15); color: #10b981; border:1px solid rgba(16,185,129,0.3); }
        .method-post { background: rgba(99,102,241,0.15); color: #6366f1; border:1px solid rgba(99,102,241,0.3); }
        .method-delete { background: rgba(239,68,68,0.15); color: #ef4444; border:1px solid rgba(239,68,68,0.3); }
        .endpoint-url { font-family: monospace; font-size: 15px; color: var(--text-primary); font-weight: 600; }
        .code-block { background: rgba(0,0,0,0.4); border: 1px solid var(--border-glass); border-radius: 8px; padding: 16px; font-family: monospace; font-size: 13px; color: #a5b4fc; white-space: pre-wrap; overflow-x: auto; margin-top: 12px; }
        .param-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
        .param-table th { text-align: left; padding: 8px 12px; background: rgba(255,255,255,0.05); color: var(--text-secondary); font-weight: 600; }
        .param-table td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-primary); }
        .required-badge { background: rgba(239,68,68,0.15); color: #ef4444; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
        .optional-badge { background: rgba(107,114,128,0.2); color: var(--text-secondary); font-size: 11px; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <div class="container nav-container">
        <a href="index.php" class="logo"><i class="fas fa-link"></i> <?php echo clean($site_name); ?></a>
        <div class="nav-links">
            <?php if (is_logged_in()): ?>
                <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-line"></i> Panelim</a>
                <a href="profile.php?tab=api" class="nav-link"><i class="fas fa-key"></i> API Anahtarım</a>
            <?php else: ?>
                <a href="login.php" class="nav-link">Giriş Yap</a>
                <a href="register.php" class="nav-btn nav-btn-primary">Kayıt Ol</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="container" style="max-width:900px; padding: 40px 20px;">
    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1><i class="fas fa-code"></i> REST API Dokümantasyonu</h1>
            <p>Click API'si ile link kısaltma işlemlerini programatik olarak gerçekleştirin.</p>
        </div>
    </div>

    <!-- Auth -->
    <div class="glass-panel" style="padding:24px; margin-bottom:32px; border-left:3px solid var(--accent-indigo);">
        <h3 style="margin-bottom:12px;"><i class="fas fa-shield-alt"></i> Kimlik Doğrulama</h3>
        <p style="color:var(--text-secondary); margin-bottom:12px;">Tüm isteklerde <code style="background:rgba(99,102,241,0.15);padding:2px 6px;border-radius:4px;">X-API-Key</code> başlığı ile API anahtarınızı gönderin.</p>
        <div class="code-block">curl -H "X-API-Key: ck_your_api_key" <?php echo $base; ?>/api.php?action=me</div>
        <p style="margin-top:12px; font-size:13px; color:var(--text-muted);">API anahtarınızı <a href="profile.php?tab=api">Profil → API</a> sayfasından alabilirsiniz. Ücretsiz planda dakikada 60 istek limiti vardır.</p>
    </div>

    <!-- Base URL -->
    <div class="api-section">
        <h2 style="font-size:20px; margin-bottom:16px; color:var(--accent-indigo);">Temel URL</h2>
        <div class="code-block"><?php echo $base; ?>/api.php?action={action}</div>
    </div>

    <!-- Endpoints -->
    <div class="api-section">
        <h2 style="font-size:20px; margin-bottom:20px; color:var(--accent-indigo);">Endpoint'ler</h2>

        <!-- info -->
        <div class="endpoint-card">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-url">?action=info</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Uygulama bilgilerini döner. Kimlik doğrulama gerektirmez.</p>
            <div class="code-block">{ "success": true, "app": "Click", "version": "2.0", "docs": "..." }</div>
        </div>

        <!-- me -->
        <div class="endpoint-card">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-url">?action=me</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Giriş yapan kullanıcı bilgilerini döner.</p>
            <div class="code-block">{ "success": true, "id": 1, "username": "admin", "plan": "free", "total_links": 12, "total_clicks": 347 }</div>
        </div>

        <!-- shorten -->
        <div class="endpoint-card">
            <span class="method-badge method-post">POST</span>
            <span class="endpoint-url">?action=shorten</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Yeni kısa link oluşturur. JSON body veya form-data kabul eder.</p>
            <table class="param-table">
                <tr><th>Parametre</th><th>Tür</th><th>Zorunlu</th><th>Açıklama</th></tr>
                <tr><td>url</td><td>string</td><td><span class="required-badge">Zorunlu</span></td><td>Kısaltılacak URL</td></tr>
                <tr><td>alias</td><td>string</td><td><span class="optional-badge">İsteğe bağlı</span></td><td>Özel kısa kod</td></tr>
                <tr><td>title</td><td>string</td><td><span class="optional-badge">İsteğe bağlı</span></td><td>Link başlığı</td></tr>
                <tr><td>password</td><td>string</td><td><span class="optional-badge">İsteğe bağlı</span></td><td>Erişim şifresi</td></tr>
                <tr><td>click_limit</td><td>integer</td><td><span class="optional-badge">İsteğe bağlı</span></td><td>Max tıklanma (0=sınırsız)</td></tr>
            </table>
            <div class="code-block">curl -X POST \
  -H "X-API-Key: ck_..." \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com","alias":"ornek","click_limit":100}' \
  <?php echo $base; ?>/api.php?action=shorten

→ { "success": true, "id": 5, "short_code": "ornek", "short_url": "<?php echo $base; ?>/ornek" }</div>
        </div>

        <!-- list -->
        <div class="endpoint-card">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-url">?action=list[&page=1&limit=20]</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Kullanıcının linklerini sayfalı olarak listeler.</p>
            <div class="code-block">{ "success": true, "page": 1, "total": 42, "links": [ { "short_code": "abc123", "clicks": 17, ... } ] }</div>
        </div>

        <!-- stats -->
        <div class="endpoint-card">
            <span class="method-badge method-get">GET</span>
            <span class="endpoint-url">?action=stats&code=abc123</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Belirli bir linkin detaylı istatistiklerini döner.</p>
            <div class="code-block">{ "success": true, "total_clicks": 347, "clicks_today": 12, "top_countries": [...], "devices": [...], "daily_7days": [...] }</div>
        </div>

        <!-- toggle -->
        <div class="endpoint-card">
            <span class="method-badge method-post">POST</span>
            <span class="endpoint-url">?action=toggle&code=abc123</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Linkin aktif/pasif durumunu değiştirir.</p>
            <div class="code-block">{ "success": true, "is_active": false }</div>
        </div>

        <!-- delete -->
        <div class="endpoint-card">
            <span class="method-badge method-delete">DELETE</span>
            <span class="endpoint-url">?action=delete&code=abc123</span>
            <p style="color:var(--text-secondary); margin-top:8px; font-size:14px;">Belirtilen linki kalıcı olarak siler.</p>
            <div class="code-block">{ "success": true, "message": "Link silindi." }</div>
        </div>
    </div>

    <!-- Error codes -->
    <div class="api-section">
        <h2 style="font-size:20px; margin-bottom:16px; color:var(--accent-indigo);">HTTP Durum Kodları</h2>
        <div class="endpoint-card">
            <table class="param-table">
                <tr><th>Kod</th><th>Anlamı</th></tr>
                <tr><td><strong style="color:#10b981;">200</strong></td><td>Başarılı</td></tr>
                <tr><td><strong style="color:#6366f1;">201</strong></td><td>Kayıt oluşturuldu</td></tr>
                <tr><td><strong style="color:#f59e0b;">400</strong></td><td>Geçersiz istek</td></tr>
                <tr><td><strong style="color:#ef4444;">401</strong></td><td>Kimlik doğrulama hatası</td></tr>
                <tr><td><strong style="color:#ef4444;">403</strong></td><td>Erişim yasak</td></tr>
                <tr><td><strong style="color:#ef4444;">404</strong></td><td>Kayıt bulunamadı</td></tr>
                <tr><td><strong style="color:#ef4444;">429</strong></td><td>Rate limit aşıldı</td></tr>
            </table>
        </div>
    </div>
</main>

<footer><div class="container"><p>&copy; <?php echo date('Y'); ?> <?php echo clean($site_name); ?>. API v2.0</p></div></footer>
<script src="assets/js/app.js"></script>
</body>
</html>
