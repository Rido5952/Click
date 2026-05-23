<?php
// export.php — Click data CSV/JSON export
require_once 'config.php';
require_login();

$user = get_current_user_data();
$code = trim($_GET['code'] ?? '');
$fmt  = in_array($_GET['format'] ?? 'csv', ['csv','json']) ? ($_GET['format'] ?? 'csv') : 'csv';

if (empty($code)) { header("Location: dashboard.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ? AND user_id = ?");
$stmt->execute([$code, $user['id']]);
$link = $stmt->fetch();
if (!$link) { header("Location: dashboard.php?error=not_found"); exit; }

$clicks = $pdo->prepare("
    SELECT clicked_at, ip_address, country, city, device_type, user_agent, referrer
    FROM clicks WHERE link_id = ? ORDER BY clicked_at DESC
");
$clicks->execute([$link['id']]);
$rows = $clicks->fetchAll();

$filename = 'click_export_' . $code . '_' . date('Ymd');

if ($fmt === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
    echo json_encode([
        'link'   => ['code' => $code, 'url' => $link['original_url'], 'title' => $link['title']],
        'clicks' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// CSV
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

$out = fopen('php://output', 'w');
fputcsv($out, ['Tarih/Saat', 'IP Adresi', 'Ülke', 'Şehir', 'Cihaz', 'Tarayıcı/UA', 'Referrer']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['clicked_at'],
        $r['ip_address'],
        $r['country']     ?? '',
        $r['city']        ?? '',
        $r['device_type'] ?? '',
        $r['user_agent'],
        $r['referrer']    ?? '',
    ]);
}
fclose($out);
exit;
