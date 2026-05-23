<?php
// config.php - Click v2.0 — Dynamic Configuration & Security Engine

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

$dbConfigPath = __DIR__ . '/database/config_db.php';
$isInstalled  = file_exists($dbConfigPath) && file_exists(__DIR__ . '/database/install.lock');

// Redirect to installer if not installed
$currentScript = basename($_SERVER['SCRIPT_NAME']);
if (!$isInstalled && $currentScript !== 'install.php') {
    header("Location: install.php");
    exit;
}

$pdo = null;

if ($isInstalled) {
    $dbConfig = require $dbConfigPath;

    try {
        if ($dbConfig['type'] === 'sqlite') {
            $pdo = new PDO("sqlite:" . __DIR__ . '/database/' . $dbConfig['sqlite_file']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("PRAGMA foreign_keys = ON; PRAGMA journal_mode=WAL;");
        } else {
            $dsn = "mysql:host={$dbConfig['mysql_host']};dbname={$dbConfig['mysql_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConfig['mysql_user'], $dbConfig['mysql_pass']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        die("Veritabanı bağlantı hatası: " . htmlspecialchars($e->getMessage()));
    }

    $isSQLite = ($dbConfig['type'] === 'sqlite');
    $AI = $isSQLite ? 'AUTOINCREMENT' : 'AUTO_INCREMENT';

    // =========================================================
    // LAZY DB MIGRATIONS — runs silently on every boot
    // =========================================================
    try {
        // --- links table new columns ---
        if ($isSQLite) {
            $linkCols = array_column($pdo->query("PRAGMA table_info(links)")->fetchAll(), 'name');
            $newLinkCols = [
                'expires_at'  => "ALTER TABLE links ADD COLUMN expires_at TIMESTAMP DEFAULT NULL",
                'password'    => "ALTER TABLE links ADD COLUMN password VARCHAR(255) DEFAULT NULL",
                'is_active'   => "ALTER TABLE links ADD COLUMN is_active INTEGER DEFAULT 1",
                'click_limit' => "ALTER TABLE links ADD COLUMN click_limit INTEGER DEFAULT NULL",
                'group_id'    => "ALTER TABLE links ADD COLUMN group_id INTEGER DEFAULT NULL",
                'meta_title'  => "ALTER TABLE links ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL",
            ];
            foreach ($newLinkCols as $col => $sql) {
                if (!in_array($col, $linkCols)) $pdo->exec($sql);
            }
        } else {
            foreach (['expires_at VARCHAR(30)', 'password VARCHAR(255)', 'is_active TINYINT DEFAULT 1',
                      'click_limit INT DEFAULT NULL', 'group_id INT DEFAULT NULL', 'meta_title VARCHAR(255)'] as $def) {
                $col = explode(' ', $def)[0];
                $chk = $pdo->query("SHOW COLUMNS FROM links LIKE '$col'")->fetch();
                if (!$chk) $pdo->exec("ALTER TABLE links ADD COLUMN $def");
            }
        }

        // --- users table new columns ---
        if ($isSQLite) {
            $userCols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
            $newUserCols = [
                'plan'            => "ALTER TABLE users ADD COLUMN plan VARCHAR(20) DEFAULT 'free'",
                'api_key'         => "ALTER TABLE users ADD COLUMN api_key VARCHAR(64) DEFAULT NULL",
                'email_verified'  => "ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0",
                'totp_secret'     => "ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL",
                'totp_enabled'    => "ALTER TABLE users ADD COLUMN totp_enabled INTEGER DEFAULT 0",
                'referral_code'   => "ALTER TABLE users ADD COLUMN referral_code VARCHAR(16) DEFAULT NULL",
                'referred_by'     => "ALTER TABLE users ADD COLUMN referred_by INTEGER DEFAULT NULL",
                'telegram_chat_id'=> "ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(64) DEFAULT NULL",
                'display_name'    => "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) DEFAULT NULL",
                'bio'             => "ALTER TABLE users ADD COLUMN bio TEXT DEFAULT NULL",
                'avatar_url'      => "ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) DEFAULT NULL",
                'bio_bg_color'    => "ALTER TABLE users ADD COLUMN bio_bg_color VARCHAR(20) DEFAULT '#0f0f1a'",
                'social_links'    => "ALTER TABLE users ADD COLUMN social_links TEXT DEFAULT NULL",
                'theme'           => "ALTER TABLE users ADD COLUMN theme VARCHAR(10) DEFAULT 'dark'",
            ];
            foreach ($newUserCols as $col => $sql) {
                if (!in_array($col, $userCols)) $pdo->exec($sql);
            }
        } else {
            $newUserDefs = ["plan VARCHAR(20) DEFAULT 'free'", "api_key VARCHAR(64)", "email_verified TINYINT DEFAULT 0",
                "totp_secret VARCHAR(64)", "totp_enabled TINYINT DEFAULT 0", "referral_code VARCHAR(16)",
                "referred_by INT", "telegram_chat_id VARCHAR(64)", "display_name VARCHAR(100)", "bio TEXT",
                "avatar_url VARCHAR(500)", "bio_bg_color VARCHAR(20) DEFAULT '#0f0f1a'",
                "social_links TEXT", "theme VARCHAR(10) DEFAULT 'dark'"];
            foreach ($newUserDefs as $def) {
                $col = explode(' ', $def)[0];
                $chk = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch();
                if (!$chk) $pdo->exec("ALTER TABLE users ADD COLUMN $def");
            }
        }

        // --- clicks table new columns ---
        if ($isSQLite) {
            $clickCols = array_column($pdo->query("PRAGMA table_info(clicks)")->fetchAll(), 'name');
            $newClickCols = [
                'country'      => "ALTER TABLE clicks ADD COLUMN country VARCHAR(64) DEFAULT NULL",
                'country_code' => "ALTER TABLE clicks ADD COLUMN country_code VARCHAR(4) DEFAULT NULL",
                'city'         => "ALTER TABLE clicks ADD COLUMN city VARCHAR(64) DEFAULT NULL",
                'device_type'  => "ALTER TABLE clicks ADD COLUMN device_type VARCHAR(20) DEFAULT NULL",
            ];
            foreach ($newClickCols as $col => $sql) {
                if (!in_array($col, $clickCols)) $pdo->exec($sql);
            }
        } else {
            foreach (['country VARCHAR(64)', 'country_code VARCHAR(4)', 'city VARCHAR(64)', 'device_type VARCHAR(20)'] as $def) {
                $col = explode(' ', $def)[0];
                $chk = $pdo->query("SHOW COLUMNS FROM clicks LIKE '$col'")->fetch();
                if (!$chk) $pdo->exec("ALTER TABLE clicks ADD COLUMN $def");
            }
        }

        // --- New tables ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_groups (
            id INTEGER PRIMARY KEY $AI,
            user_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(20) DEFAULT '#6366f1',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ip_blacklist (
            id INTEGER PRIMARY KEY $AI,
            ip_address VARCHAR(45) UNIQUE NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS url_blacklist (
            id INTEGER PRIMARY KEY $AI,
            domain VARCHAR(255) UNIQUE NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY $AI,
            ip_address VARCHAR(45) NOT NULL,
            action VARCHAR(50) NOT NULL,
            hit_count INTEGER DEFAULT 1,
            window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY $AI,
            setting_key   VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT DEFAULT NULL
        )");

        // --- Seed default settings ---
        $cnt = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        if ($cnt == 0) {
            $defaults = [
                ['site_name',           'Click'],
                ['site_description',    'Hızlı ve güvenilir link kısaltıcı'],
                ['site_tagline',        'Uzun bağlantılarınızı tek tıkla akıllı hale getirin'],
                ['guest_link_days',     '15'],
                ['allow_guest',         '1'],
                ['ad_interstitial',     '0'],
                ['ad_code',             ''],
                ['rate_limit_per_min',  '5'],
                ['telegram_bot_token',  ''],
                ['smtp_host',           ''],
                ['smtp_user',           ''],
                ['smtp_pass',           ''],
                ['smtp_port',           '587'],
                ['safe_browsing_key',   ''],
                ['allow_registration',  '1'],
                ['site_url',            ''],
            ];
            $ins = $pdo->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            foreach ($defaults as $d) $ins->execute($d);
        }

        // --- Seed referral codes for existing users ---
        $usersWithoutRef = $pdo->query("SELECT id FROM users WHERE referral_code IS NULL OR referral_code = ''")->fetchAll();
        foreach ($usersWithoutRef as $u) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $u['id']]);
        }

    } catch (PDOException $e) { /* silent */ }

    // --- Auto-purge expired guest links ---
    try {
        $expireSQL = $isSQLite
            ? "DELETE FROM links WHERE expires_at IS NOT NULL AND expires_at < datetime('now')"
            : "DELETE FROM links WHERE expires_at IS NOT NULL AND expires_at < NOW()";
        $pdo->exec($expireSQL);
    } catch (PDOException $e) {}

    // --- IP ban check (except install/banned page) ---
    if (!in_array($currentScript, ['install.php', 'banned.php', 'api.php'])) {
        try {
            $visitorIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $banCheck  = $pdo->prepare("SELECT id FROM ip_blacklist WHERE ip_address = ?");
            $banCheck->execute([$visitorIp]);
            if ($banCheck->fetch()) {
                header("Location: banned.php");
                exit;
            }
        } catch (PDOException $e) {}
    }
}

// =========================================================
// SECURITY HELPERS
// =========================================================

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

function enforce_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF Güvenlik Doğrulaması Başarısız.");
        }
    }
}

/**
 * Rate limiting: returns true if the limit is exceeded.
 * @param string $action  e.g. 'shorten', 'login'
 * @param int    $limit   max hits allowed
 * @param int    $windowSec  window in seconds
 */
function check_rate_limit($action, $limit = 5, $windowSec = 60) {
    global $pdo;
    if (!$pdo) return false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        // Delete stale windows
        $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action = ? AND CAST(strftime('%s','now') AS INTEGER) - CAST(strftime('%s', window_start) AS INTEGER) > ?")
            ->execute([$ip, $action, $windowSec]);
        $row = $pdo->prepare("SELECT hit_count FROM rate_limits WHERE ip_address = ? AND action = ?");
        $row->execute([$ip, $action]);
        $hit = $row->fetch();
        if (!$hit) {
            $pdo->prepare("INSERT INTO rate_limits (ip_address, action, hit_count) VALUES (?,?,1)")->execute([$ip, $action]);
            return false;
        }
        if ($hit['hit_count'] >= $limit) return true;
        $pdo->prepare("UPDATE rate_limits SET hit_count = hit_count + 1 WHERE ip_address = ? AND action = ?")->execute([$ip, $action]);
        return false;
    } catch (PDOException $e) { return false; }
}

function is_ip_banned($ip = null) {
    global $pdo;
    if (!$pdo) return false;
    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    try {
        $s = $pdo->prepare("SELECT id FROM ip_blacklist WHERE ip_address = ?");
        $s->execute([$ip]);
        return (bool)$s->fetch();
    } catch (PDOException $e) { return false; }
}

function is_domain_blacklisted($url) {
    global $pdo;
    if (!$pdo) return false;
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $host = preg_replace('/^www\./', '', $host);
    try {
        $s = $pdo->prepare("SELECT id FROM url_blacklist WHERE domain = ?");
        $s->execute([$host]);
        return (bool)$s->fetch();
    } catch (PDOException $e) { return false; }
}

function get_device_type($ua = null) {
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) return 'tablet';
    if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) return 'mobile';
    return 'desktop';
}

function get_ip_country($ip) {
    if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'])) return ['country' => 'Localhost', 'country_code' => 'LH', 'city' => 'Local'];
    $cacheKey = 'geo_' . md5($ip);
    if (isset($_SESSION[$cacheKey])) return $_SESSION[$cacheKey];
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents("http://ip-api.com/json/{$ip}?fields=country,countryCode,city", false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if ($data && isset($data['country'])) {
                $result = ['country' => $data['country'], 'country_code' => $data['countryCode'], 'city' => $data['city'] ?? ''];
                $_SESSION[$cacheKey] = $result;
                return $result;
            }
        }
    } catch (Exception $e) {}
    return ['country' => 'Bilinmiyor', 'country_code' => '??', 'city' => ''];
}

function generate_api_key() {
    return 'ck_' . bin2hex(random_bytes(24));
}

// =========================================================
// PASSWORD / HASH HELPERS
// =========================================================

function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function clean($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

// =========================================================
// URL / CODE HELPERS
// =========================================================

function get_base_url() {
    $siteUrl = get_setting('site_url', '');
    if (!empty($siteUrl)) {
        return rtrim($siteUrl, '/');
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir      = dirname($_SERVER['SCRIPT_NAME']);
    $dir      = ($dir === DIRECTORY_SEPARATOR) ? '' : $dir;
    $dir      = str_replace('\\', '/', $dir);
    return $protocol . $host . $dir;
}

function is_valid_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function generate_short_code($length = 6) {
    global $pdo;
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $stmt  = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code = ?");
    do {
        $code = '';
        for ($i = 0; $i < $length; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn() > 0);
    return $code;
}

function is_code_taken($code) {
    global $pdo;
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $code)) return true;
    $s = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code = ?");
    $s->execute([$code]);
    return $s->fetchColumn() > 0;
}

// =========================================================
// AUTH HELPERS
// =========================================================

function is_logged_in() { return isset($_SESSION['user_id']); }

function get_current_user_data() {
    global $pdo;
    if (!is_logged_in() || !$pdo) return null;
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch() ?: null;
}

function require_login() {
    if (!is_logged_in()) { header("Location: login.php"); exit; }
}

function require_admin() {
    require_login();
    $u = get_current_user_data();
    if (!$u || intval($u['is_admin']) !== 1) { header("Location: dashboard.php"); exit; }
}

// =========================================================
// SETTINGS HELPERS
// =========================================================

function get_setting($key, $default = '') {
    global $pdo;
    if (!$pdo) return $default;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false) ? $v : $default;
    } catch (PDOException $e) { return $default; }
}

function set_setting($key, $value) {
    global $pdo;
    if (!$pdo) return false;
    try {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value")
            ->execute([$key, $value]);
        return true;
    } catch (PDOException $e) {
        try {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$key, $value]);
            return true;
        } catch (PDOException $e2) { return false; }
    }
}

// =========================================================
// NOTIFICATION HELPERS
// =========================================================

/**
 * Send a Telegram message via Bot API
 */
function send_telegram($chatId, $message) {
    $token = get_setting('telegram_bot_token', '');
    if (empty($token) || empty($chatId)) return false;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query(['chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML']),
        'timeout' => 3,
    ]]);
    @file_get_contents($url, false, $ctx);
    return true;
}

// =========================================================
// SPAM CHECK HELPER (Google Safe Browsing v4)
// =========================================================
function is_url_safe($url) {
    $apiKey = get_setting('safe_browsing_key', '');
    if (empty($apiKey)) return true; // skip if not configured
    $endpoint = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key={$apiKey}";
    $payload = json_encode([
        'client'     => ['clientId' => 'click-shortener', 'clientVersion' => '2.0'],
        'threatInfo' => [
            'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
            'platformTypes'    => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries'    => [['url' => $url]],
        ],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 3,
    ]]);
    $response = @file_get_contents($endpoint, false, $ctx);
    if (!$response) return true;
    $data = json_decode($response, true);
    return empty($data['matches']);
}
