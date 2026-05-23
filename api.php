<?php
// api.php - Click v2.0 REST API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

require_once 'config.php';

function api_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function api_error($message, $code = 400) {
    api_response(['success' => false, 'error' => $message], $code);
}

// --- Authenticate via API key ---
$apiKey = $_SERVER['HTTP_X_API_KEY'] 
    ?? $_SERVER['HTTP_AUTHORIZATION'] 
    ?? ($_GET['api_key'] ?? '');
$apiKey = str_replace('Bearer ', '', $apiKey);

$apiUser = null;
if (!empty($apiKey)) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE api_key = ?");
    $stmt->execute([trim($apiKey)]);
    $apiUser = $stmt->fetch();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Rate limit API
if (check_rate_limit('api', 60, 60)) {
    api_error('Rate limit aşıldı. Dakikada en fazla 60 istek yapabilirsiniz.', 429);
}

// ===================== PUBLIC ENDPOINTS =====================
if ($action === 'info') {
    api_response([
        'success'  => true,
        'app'      => get_setting('site_name', 'Click'),
        'version'  => '2.0',
        'docs'     => get_base_url() . '/api-docs.php',
    ]);
}

// ===================== AUTHENTICATED ENDPOINTS =====================
if (!$apiUser) {
    api_error('Geçersiz veya eksik API anahtarı. X-API-Key başlığıyla gönderin.', 401);
}

// POST /api.php?action=shorten — Shorten a URL
if ($action === 'shorten' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $url     = trim($body['url'] ?? '');
    $alias   = trim($body['alias'] ?? '');
    $title   = trim($body['title'] ?? '');
    $password= trim($body['password'] ?? '');
    $limit   = intval($body['click_limit'] ?? 0);

    if (empty($url)) api_error('url alanı zorunludur.');
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = 'http://' . $url;
    if (!is_valid_url($url)) api_error('Geçersiz URL formatı.');
    if (is_domain_blacklisted($url)) api_error('Bu domain kara listede bulunmaktadır.', 403);
    if (!is_url_safe($url)) api_error('Bu URL güvenli değil (spam/malware tespit edildi).', 403);

    if (!empty($alias)) {
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $alias)) api_error('Özel alias yalnızca harf, rakam, - ve _ içerebilir.');
        if (is_code_taken($alias)) api_error('Bu alias zaten kullanımda.');
        $code = $alias;
    } else {
        $code = generate_short_code();
    }

    if (empty($title)) {
        $host  = parse_url($url, PHP_URL_HOST);
        $title = $host ?: 'API Link';
    }

    $pwHash   = !empty($password) ? hash_password($password) : null;
    $clickLim = $limit > 0 ? $limit : null;

    $stmt = $pdo->prepare("INSERT INTO links (user_id, original_url, short_code, title, password, click_limit) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$apiUser['id'], $url, $code, $title, $pwHash, $clickLim]);
    $id = $pdo->lastInsertId();

    api_response([
        'success'    => true,
        'id'         => (int)$id,
        'short_code' => $code,
        'short_url'  => get_base_url() . '/' . $code,
        'original'   => $url,
        'title'      => $title,
    ], 201);
}

// GET /api.php?action=list — List user's links
if ($action === 'list' && $method === 'GET') {
    $page  = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare("
        SELECT l.id, l.short_code, l.original_url, l.title, l.is_active, l.click_limit, l.created_at, l.expires_at,
               COUNT(c.id) AS clicks
        FROM links l
        LEFT JOIN clicks c ON c.link_id = l.id
        WHERE l.user_id = ?
        GROUP BY l.id
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$apiUser['id'], $limit, $offset]);
    $links = $stmt->fetchAll();

    $total = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ?");
    $total->execute([$apiUser['id']]);

    foreach ($links as &$lnk) {
        $lnk['short_url'] = get_base_url() . '/' . $lnk['short_code'];
        $lnk['is_active'] = (bool)$lnk['is_active'];
    }

    api_response([
        'success' => true,
        'page'    => $page,
        'limit'   => $limit,
        'total'   => (int)$total->fetchColumn(),
        'links'   => $links,
    ]);
}

// GET /api.php?action=stats&code=X — Link statistics
if ($action === 'stats' && $method === 'GET') {
    $code = trim($_GET['code'] ?? '');
    if (empty($code)) api_error('code parametresi zorunludur.');

    $stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ? AND user_id = ?");
    $stmt->execute([$code, $apiUser['id']]);
    $link = $stmt->fetch();
    if (!$link) api_error('Link bulunamadı veya bu linke erişim yetkiniz yok.', 404);

    $total = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE link_id = ?");
    $total->execute([$link['id']]);

    $today = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE link_id = ? AND date(clicked_at) = date('now','localtime')");
    $today->execute([$link['id']]);

    $countries = $pdo->prepare("SELECT country, COUNT(*) as cnt FROM clicks WHERE link_id = ? AND country IS NOT NULL GROUP BY country ORDER BY cnt DESC LIMIT 10");
    $countries->execute([$link['id']]);

    $devices = $pdo->prepare("SELECT device_type, COUNT(*) as cnt FROM clicks WHERE link_id = ? GROUP BY device_type ORDER BY cnt DESC");
    $devices->execute([$link['id']]);

    $daily = $pdo->prepare("SELECT date(clicked_at,'localtime') as d, COUNT(*) as cnt FROM clicks WHERE link_id = ? AND clicked_at >= date('now','-7 days') GROUP BY d ORDER BY d");
    $daily->execute([$link['id']]);

    api_response([
        'success'       => true,
        'code'          => $code,
        'short_url'     => get_base_url() . '/' . $code,
        'original_url'  => $link['original_url'],
        'title'         => $link['title'],
        'is_active'     => (bool)$link['is_active'],
        'created_at'    => $link['created_at'],
        'total_clicks'  => (int)$total->fetchColumn(),
        'clicks_today'  => (int)$today->fetchColumn(),
        'top_countries' => $countries->fetchAll(),
        'devices'       => $devices->fetchAll(),
        'daily_7days'   => $daily->fetchAll(),
    ]);
}

// DELETE /api.php?action=delete&code=X
if ($action === 'delete' && $method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = trim($_GET['code'] ?? $body['code'] ?? '');
    if (empty($code)) api_error('code parametresi zorunludur.');

    $stmt = $pdo->prepare("SELECT id FROM links WHERE short_code = ? AND user_id = ?");
    $stmt->execute([$code, $apiUser['id']]);
    if (!$stmt->fetch()) api_error('Link bulunamadı veya yetki yok.', 404);

    $pdo->prepare("DELETE FROM links WHERE short_code = ? AND user_id = ?")->execute([$code, $apiUser['id']]);
    api_response(['success' => true, 'message' => 'Link silindi.']);
}

// PATCH /api.php?action=toggle&code=X — Toggle is_active
if ($action === 'toggle' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $code = trim($_GET['code'] ?? $body['code'] ?? '');
    if (empty($code)) api_error('code parametresi zorunludur.');

    $stmt = $pdo->prepare("SELECT id, is_active FROM links WHERE short_code = ? AND user_id = ?");
    $stmt->execute([$code, $apiUser['id']]);
    $link = $stmt->fetch();
    if (!$link) api_error('Link bulunamadı.', 404);

    $newState = $link['is_active'] ? 0 : 1;
    $pdo->prepare("UPDATE links SET is_active = ? WHERE id = ?")->execute([$newState, $link['id']]);
    api_response(['success' => true, 'is_active' => (bool)$newState]);
}

// GET /api.php?action=me — User info
if ($action === 'me' && $method === 'GET') {
    $linkCount = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ?");
    $linkCount->execute([$apiUser['id']]);
    $clickCount = $pdo->prepare("SELECT COUNT(c.id) FROM clicks c JOIN links l ON c.link_id = l.id WHERE l.user_id = ?");
    $clickCount->execute([$apiUser['id']]);

    api_response([
        'success'     => true,
        'id'          => (int)$apiUser['id'],
        'username'    => $apiUser['username'],
        'email'       => $apiUser['email'],
        'plan'        => $apiUser['plan'],
        'is_admin'    => (bool)$apiUser['is_admin'],
        'total_links' => (int)$linkCount->fetchColumn(),
        'total_clicks'=> (int)$clickCount->fetchColumn(),
        'created_at'  => $apiUser['created_at'],
    ]);
}

api_error("Bilinmeyen action: '{$action}'. Geçerli: shorten, list, stats, delete, toggle, me, info.", 404);
