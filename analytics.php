<?php
// analytics.php - Click Link Analytics Page
require_once 'config.php';

// Force login
require_login();

$user = get_current_user_data();
$code = trim($_GET['code'] ?? '');

if (empty($code)) {
    header("Location: dashboard.php");
    exit;
}

// Check link existence and user ownership
$stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ? AND user_id = ?");
$stmt->execute([$code, $user['id']]);
$link = $stmt->fetch();

if (!$link) {
    // Unauthorized access or link doesn't exist
    die("Bağlantı bulunamadı veya bu bağlantının istatistiklerini görmeye yetkiniz yok.");
}

// User agent parsing helpers
function get_browser_name($ua) {
    if (empty($ua)) return 'Bilinmiyor';
    if (preg_match('/edge/i', $ua)) return 'Edge';
    if (preg_match('/chrome/i', $ua)) return 'Chrome';
    if (preg_match('/firefox/i', $ua)) return 'Firefox';
    if (preg_match('/safari/i', $ua)) return 'Safari';
    if (preg_match('/opera/i', $ua)) return 'Opera';
    if (preg_match('/msie/i', $ua)) return 'IE';
    return 'Diğer / Bilinmeyen';
}

function get_os_name($ua) {
    if (empty($ua)) return 'Bilinmiyor';
    if (preg_match('/windows|win32/i', $ua)) return 'Windows';
    if (preg_match('/macintosh|mac os x/i', $ua)) return 'macOS';
    if (preg_match('/linux/i', $ua)) return 'Linux';
    if (preg_match('/iphone|ipad|ipod/i', $ua)) return 'iOS';
    if (preg_match('/android/i', $ua)) return 'Android';
    return 'Diğer / Bilinmeyen';
}

// --- Retrieve Click Metrics ---

// 1. Total click count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE link_id = ?");
$stmt->execute([$link['id']]);
$total_clicks = $stmt->fetchColumn();

// 2. Prepare 7-day timeline (inclusive of today)
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_data[$date] = 0;
}

$stmt = $pdo->prepare("
    SELECT date(clicked_at, 'localtime') as click_date, COUNT(*) as click_count 
    FROM clicks 
    WHERE link_id = ? AND clicked_at >= date('now', '-7 days')
    GROUP BY click_date
");
$stmt->execute([$link['id']]);
while ($row = $stmt->fetch()) {
    if (isset($chart_data[$row['click_date']])) {
        $chart_data[$row['click_date']] = intval($row['click_count']);
    }
}

// Format dates for chart labels (e.g., "22 May")
$months = [
    'Jan' => 'Oca', 'Feb' => 'Şub', 'Mar' => 'Mar', 'Apr' => 'Nis',
    'May' => 'May', 'Jun' => 'Haz', 'Jul' => 'Tem', 'Aug' => 'Ağu',
    'Sep' => 'Eyl', 'Oct' => 'Eki', 'Nov' => 'Kas', 'Dec' => 'Ara'
];
$labels = [];
$data_points = [];
foreach ($chart_data as $date_str => $clicks) {
    $formatted_date = date('d M', strtotime($date_str));
    // Translate month to Turkish
    foreach ($months as $en => $tr) {
        $formatted_date = str_replace($en, $tr, $formatted_date);
    }
    $labels[] = $formatted_date;
    $data_points[] = $clicks;
}

// 3. Top Referrers
$stmt = $pdo->prepare("
    SELECT referrer, COUNT(*) as ref_count 
    FROM clicks 
    WHERE link_id = ? 
    GROUP BY referrer 
    ORDER BY ref_count DESC 
    LIMIT 5
");
$stmt->execute([$link['id']]);
$referrers = $stmt->fetchAll();

// 4. Browser & OS breakdowns
$browsers = [];
$os = [];
$stmt = $pdo->prepare("SELECT user_agent FROM clicks WHERE link_id = ?");
$stmt->execute([$link['id']]);
while ($row = $stmt->fetch()) {
    $b = get_browser_name($row['user_agent']);
    $o = get_os_name($row['user_agent']);
    
    $browsers[$b] = ($browsers[$b] ?? 0) + 1;
    $os[$o] = ($os[$o] ?? 0) + 1;
}
arsort($browsers);
arsort($os);
$top_browsers = array_slice($browsers, 0, 5, true);
$top_os = array_slice($os, 0, 5, true);

// 5. Recent Clicks Log (Last 10)
$stmt = $pdo->prepare("
    SELECT ip_address, referrer, user_agent, clicked_at 
    FROM clicks 
    WHERE link_id = ? 
    ORDER BY clicked_at DESC 
    LIMIT 10
");
$stmt->execute([$link['id']]);
$recent_clicks = $stmt->fetchAll();

$short_url = get_base_url() . '/' . $link['short_code'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Analizi - /<?php echo clean($link['short_code']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- ChartJS for visual analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-link"></i> Click
            </a>
            <div class="nav-links">
                <span style="color: var(--text-secondary); font-size: 14px; font-weight: 500;">
                    <i class="fas fa-user-circle"></i> <?php echo clean($user['username']); ?>
                </span>
                <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-line"></i> Panelim</a>
                <?php if (intval($user['is_admin']) === 1): ?>
                    <a href="admin.php" class="nav-link"><i class="fas fa-cogs"></i> Yönetim</a>
                <?php endif; ?>
                <a href="logout.php" class="nav-btn nav-btn-outline"><i class="fas fa-sign-out-alt"></i> Çıkış</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="dashboard-wrapper container">
        
        <!-- Back navigation & Details -->
        <div class="dashboard-header" style="align-items: flex-start;">
            <div>
                <a href="dashboard.php" style="font-size: 14px; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 12px;">
                    <i class="fas fa-arrow-left"></i> Panale Geri Dön
                </a>
                <h1 style="font-size: 28px;"><?php echo clean($link['title']); ?></h1>
                <a href="<?php echo clean($short_url); ?>" target="_blank" style="font-size: 18px; font-weight: 600; color: var(--text-primary);">
                    /<?php echo clean($link['short_code']); ?> <i class="fas fa-external-link-alt" style="font-size: 12px; color: var(--accent-indigo);"></i>
                </a>
                <span style="color: var(--text-muted); display: block; font-size: 13px; margin-top: 4px; word-break: break-all;">
                    Hedef URL: <?php echo clean($link['original_url']); ?>
                </span>
            </div>
            
            <button class="btn-copy nav-btn nav-btn-primary" data-url="<?php echo clean($short_url); ?>" style="display: flex;">
                <i class="fas fa-copy"></i> Kısa Linki Kopyala
            </button>
        </div>

        <!-- Key Metrics Cards -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <div class="stat-card glass-panel">
                <div class="stat-icon purple"><i class="fas fa-mouse-pointer"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo number_format($total_clicks); ?></span>
                    <span class="stat-label">Toplam Tıklanma</span>
                </div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-icon indigo"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo date('d.m.Y', strtotime($link['created_at'])); ?></span>
                    <span class="stat-label">Oluşturulma Tarihi</span>
                </div>
            </div>
        </div>

        <!-- Chart and Traffic Breakdown Grid -->
        <div class="analytics-grid">
            
            <!-- Graph (Last 7 Days) -->
            <div class="chart-card glass-panel">
                <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 24px;"><i class="fas fa-chart-line"></i> Son 7 Günlük Performans</h3>
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="clicksChart"></canvas>
                </div>
            </div>

            <!-- Referrers Panel -->
            <div class="list-panel glass-panel">
                <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;"><i class="fas fa-arrow-circle-right"></i> Trafik Kaynakları</h3>
                <div class="list-items">
                    <?php if (empty($referrers)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                            Henüz tıklama kaydı yok.
                        </div>
                    <?php else: ?>
                        <?php foreach ($referrers as $ref): ?>
                            <?php 
                            $ref_url = $ref['referrer'] ? clean($ref['referrer']) : 'Doğrudan Giriş / Direct';
                            $ref_clean = $ref['referrer'] ? parse_url($ref['referrer'], PHP_URL_HOST) : 'Doğrudan Giriş';
                            ?>
                            <div class="list-item">
                                <span class="list-item-label" title="<?php echo $ref_url; ?>">
                                    <i class="fas fa-globe" style="font-size: 12px; color: var(--text-muted); margin-right: 6px;"></i>
                                    <?php echo clean($ref_clean ?: $ref_url); ?>
                                </span>
                                <span class="list-item-value badge-clicks"><?php echo $ref['ref_count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Device Split Grid -->
        <div class="analytics-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 32px;">
            
            <!-- Browsers Card -->
            <div class="list-panel glass-panel">
                <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;"><i class="fas fa-window-maximize"></i> Tarayıcı Dağılımı</h3>
                <div class="list-items">
                    <?php if (empty($top_browsers)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 30px 0;">İstatistik yok.</div>
                    <?php else: ?>
                        <?php foreach ($top_browsers as $browser => $count): ?>
                            <div class="list-item">
                                <span class="list-item-label"><?php echo clean($browser); ?></span>
                                <span class="list-item-value"><?php echo $count; ?> tıklanma</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- OS Card -->
            <div class="list-panel glass-panel">
                <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;"><i class="fas fa-laptop-code"></i> İşletim Sistemleri</h3>
                <div class="list-items">
                    <?php if (empty($top_os)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 30px 0;">İstatistik yok.</div>
                    <?php else: ?>
                        <?php foreach ($top_os as $os_system => $count): ?>
                            <div class="list-item">
                                <span class="list-item-label"><?php echo clean($os_system); ?></span>
                                <span class="list-item-value"><?php echo $count; ?> tıklanma</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Latest Hits Logs Table -->
        <div class="links-card glass-panel" style="margin-bottom: 32px;">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-history"></i> Son 10 Tıklama Günlüğü</div>
            </div>
            <?php if (empty($recent_clicks)): ?>
                <div class="empty-state" style="padding: 40px 24px;">
                    <p>Bu bağlantıya henüz tıklanmadı.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Tarih / Saat</th>
                                <th>IP Adresi</th>
                                <th>Referans Kaynağı (Referrer)</th>
                                <th>Tarayıcı / İşletim Sistemi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_clicks as $click): ?>
                                <tr>
                                    <td class="date-col">
                                        <?php echo date('d.m.Y H:i:s', strtotime($click['clicked_at'])); ?>
                                    </td>
                                    <td style="font-family: monospace; color: var(--text-secondary);">
                                        <?php echo clean($click['ip_address']); ?>
                                    </td>
                                    <td>
                                        <span class="link-original" style="max-width: 300px;" title="<?php echo $click['referrer'] ? clean($click['referrer']) : 'Doğrudan Giriş'; ?>">
                                            <?php echo $click['referrer'] ? clean($click['referrer']) : '<span style="color: var(--text-muted);">Doğrudan / Direct</span>'; ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 13px; color: var(--text-secondary);">
                                        <span style="font-weight: 500;"><?php echo clean(get_browser_name($click['user_agent'])); ?></span> 
                                        on <?php echo clean(get_os_name($click['user_agent'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Click. Tüm Hakları Saklıdır.</p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Render the line chart with PHP populated values
            const labels = <?php echo json_encode($labels); ?>;
            const dataPoints = <?php echo json_encode($data_points); ?>;
            renderAnalyticsChart('clicksChart', labels, dataPoints);
        });
    </script>
</body>
</html>
