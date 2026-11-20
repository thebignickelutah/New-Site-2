<?php
/**
 * The Big Nickel backend, all in one file. Lives next to index.html.
 * Database settings come from the .env file Cloudways made, or from config.local.php next to this file.
 */
declare(strict_types=1);
const BN_SINGLE = true;
define('BN_UPLOADS', __DIR__ . '/u');

const BN_DOC_MAX_BYTES = 2_000_000;      // one stored document
const BN_UPLOAD_MAX_BYTES = 60_000_000;  // one uploaded file (videos)
const BN_IMAGE_MAX_BYTES = 15_000_000;   // one uploaded image
if (!defined('BN_UPLOADS')) define('BN_UPLOADS', dirname(__DIR__) . '/site/u');

/**
 * Writes the protective .htaccess files if they are missing, so nothing has to be
 * created by hand: uploads can never run as code, hidden files are never served.
 */
function bn_protect(): void {
    if (!is_dir(BN_UPLOADS)) @mkdir(BN_UPLOADS, 0755, true);
    $u = BN_UPLOADS . '/.htaccess';
    if (!is_file($u)) @file_put_contents($u, "# Uploaded photos and videos only: nothing in here may ever run as code.\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py\nRemoveType .php .phtml .php3 .php4 .php5 .php7 .phps\n<FilesMatch \"\\.(?i:php|phtml|php\\d|phps|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n<IfModule mod_headers.c>\n  Header set Cache-Control \"public, max-age=31536000, immutable\"\n  Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n");
    if (!defined('BN_SINGLE')) return;
    $r = __DIR__ . '/.htaccess';
    $cur = is_file($r) ? (string) @file_get_contents($r) : '';
    if (str_contains($cur, '# BN-PROTECT v4')) return;
    // drop an older version of our block (it always sat at the end of the file)
    $at = strpos($cur, "\n# BN-PROTECT");
    if ($at !== false) $cur = substr($cur, 0, $at);
    $block = <<<'HT'

# BN-PROTECT v4 (added by The Big Nickel)
DirectoryIndex index.html
AddDefaultCharset UTF-8
Options -Indexes
# never serve hidden, settings, log or backup files
<FilesMatch "^\.|\.(env|ini|log|sql|sqlite|bak|md|json|lock|yml|yaml|dist)$|^(config\.local\.php|owner-key\.php|artisan|composer\..*)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "camera=(), microphone=(), payment=(self), geolocation=(self)"
  Header always set Content-Security-Policy "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self' https://www.paypal.com https://www.sandbox.paypal.com"
  Header unset X-Powered-By
  <FilesMatch "\.html$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
  <FilesMatch "^api\.php$">
    Header set Cache-Control "no-store, private"
  </FilesMatch>
</IfModule>
# old links from the previous site (like /classifieds/15) open the new home page instead of an error
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_URI} !^/\.well-known/
  RewriteCond %{REQUEST_URI} !^/u/
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ / [R=302,L]
</IfModule>
# /BN-PROTECT
HT;
    @file_put_contents($r, $cur . $block);
}

function bn_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $env = is_readable(__DIR__ . '/.env') ? __DIR__ . '/.env' : dirname(__DIR__) . '/.env';
    if (is_readable($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
            $cfg[$k] = $v;
        }
    }
    foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'BN_SQLITE', 'BN_MAIL_FROM', 'BN_SITE_URL'] as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') $cfg[$k] = $v;
    }
    $local = __DIR__ . '/config.local.php';
    if (is_readable($local)) {
        $over = require $local;
        if (is_array($over)) $cfg = array_merge($cfg, $over);
    }
    return $cfg;
}

function bn_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = bn_config();
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (!empty($c['BN_SQLITE'])) {
        $pdo = new PDO('sqlite:' . $c['BN_SQLITE'], null, null, $opts);
        $pdo->exec('PRAGMA journal_mode=WAL');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $c['DB_HOST'] ?? 'localhost', $c['DB_PORT'] ?? '3306', $c['DB_DATABASE'] ?? '');
        $pdo = new PDO($dsn, $c['DB_USERNAME'] ?? '', $c['DB_PASSWORD'] ?? '', $opts);
    }
    bn_schema($pdo);
    return $pdo;
}

function bn_is_sqlite(PDO $db): bool { return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

/** Creates the tables the first time the backend runs (no command line needed on Cloudways). */
function bn_schema(PDO $db): void {
    $flag = sys_get_temp_dir() . '/bn-schema-v2-' . md5((string) json_encode(bn_config()['DB_DATABASE'] ?? bn_config()['BN_SQLITE'] ?? ''));
    if (is_file($flag)) {
        try { $db->query('SELECT 1 FROM bn_secrets LIMIT 1'); return; } catch (PDOException $e) { /* tables missing: create them */ }
    }
    $sqlite = bn_is_sqlite($db);
    $text = $sqlite ? 'TEXT' : 'LONGTEXT';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $stmts = [
        "CREATE TABLE IF NOT EXISTS bn_docs (path VARCHAR(400) NOT NULL PRIMARY KEY, col VARCHAR(300) NOT NULL, data $text, updated BIGINT NOT NULL, deleted SMALLINT NOT NULL DEFAULT 0)$engine",
        "CREATE TABLE IF NOT EXISTS bn_users (id VARCHAR(40) NOT NULL PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, name VARCHAR(80) NOT NULL, pass VARCHAR(255) NOT NULL, is_admin SMALLINT NOT NULL DEFAULT 0, created BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_resets (token_hash CHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(40) NOT NULL, expires BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_attempts (k VARCHAR(255) NOT NULL, at BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_totp (user_id VARCHAR(40) NOT NULL PRIMARY KEY, secret VARCHAR(64) NOT NULL, enabled SMALLINT NOT NULL DEFAULT 0, last_step BIGINT NOT NULL DEFAULT 0, recovery TEXT)$engine",
        "CREATE TABLE IF NOT EXISTS bn_phones (phone VARCHAR(20) NOT NULL PRIMARY KEY, user_id VARCHAR(40) NOT NULL, created BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_secrets (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT)$engine",
        "CREATE TABLE IF NOT EXISTS bn_assets (id VARCHAR(40) NOT NULL PRIMARY KEY, user_id VARCHAR(40) NOT NULL, file VARCHAR(255) NOT NULL, mime VARCHAR(100) NOT NULL, size BIGINT NOT NULL, created BIGINT NOT NULL)$engine",
    ];
    foreach ($stmts as $s) $db->exec($s);
    foreach (['CREATE INDEX bn_docs_col ON bn_docs (col)', 'CREATE INDEX bn_docs_updated ON bn_docs (updated)', 'CREATE INDEX bn_attempts_k ON bn_attempts (k, at)'] as $s) {
        try { $db->exec($s); } catch (PDOException $e) { /* already exists */ }
    }
    @file_put_contents($flag, '1');
}

function bn_now(): int { return (int) floor(microtime(true) * 1000); }

function bn_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('bn_sess');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 60, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 60));
    session_start();
}

/** The signed-in member, or null. */
function bn_me(): ?array {
    static $me = false;
    if ($me !== false) return $me;
    bn_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return $me = null;
    $st = bn_db()->prepare('SELECT id, email, name, is_admin FROM bn_users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { unset($_SESSION['uid']); return $me = null; }
    $row['is_admin'] = (bool) $row['is_admin'];
    return $me = $row;
}

function bn_member_status(string $uid): string {
    $st = bn_db()->prepare('SELECT data FROM bn_docs WHERE path = ? AND deleted = 0');
    $st->execute(['users/' . $uid]);
    $d = json_decode((string) $st->fetchColumn(), true);
    return is_array($d) && isset($d['status']) ? (string) $d['status'] : 'Active';
}

function bn_blocked(string $uid): bool {
    return in_array(bn_member_status($uid), ['Deactivated', 'Suspended', 'Banned', 'Blocked'], true);
}

/* ---------- access rules: the same 23 rules the site ran on in the Claude preview ---------- */
const BN_RULES = [
    'adorders' => ['read' => 'admin', 'write' => 'admin'],
    'adorders/{self}' => ['read' => 'interact', 'write' => 'interact'],
    'adslive' => ['read' => 'view', 'write' => 'admin'],
    'businesses' => ['read' => 'view', 'write' => 'admin'],
    'users' => ['read' => 'admin', 'write' => 'admin'],
    'userposts' => ['read' => 'view', 'write' => 'admin'],
    'userposts/{self}' => ['read' => 'view', 'write' => 'interact'],
    'profiles' => ['read' => 'view', 'write' => 'admin'],
    'profiles/{self}' => ['read' => 'view', 'write' => 'interact'],
    'reviews' => ['read' => 'view', 'write' => 'admin'],
    'reviews/{self}' => ['read' => 'view', 'write' => 'interact'],
    'places' => ['read' => 'view', 'write' => 'admin'],
    'places/{self}' => ['read' => 'view', 'write' => 'interact'],
    'settings' => ['read' => 'view', 'write' => 'admin'],
    'gallery' => ['read' => 'view', 'write' => 'admin'],
    'gallery/{self}' => ['read' => 'view', 'write' => 'interact'],
    'greviews' => ['read' => 'view', 'write' => 'admin'],
    'greviews/{self}' => ['read' => 'view', 'write' => 'interact'],
    'social' => ['read' => 'view', 'write' => 'admin'],
    'social/{self}' => ['read' => 'view', 'write' => 'interact'],
    'dm' => ['read' => 'view', 'write' => 'admin'],
    'dm/{self}' => ['read' => 'view', 'write' => 'interact'],
    'newsfeed' => ['read' => 'view', 'write' => 'admin'],
];

function bn_role_ok(string $role, ?array $me): bool {
    if ($role === 'view') return true;
    if (!$me) return false;
    if ($role === 'admin') return $me['is_admin'];
    if ($role === 'interact') return $me['is_admin'] || !bn_blocked($me['id']);
    return false;
}

/**
 * Splits a document path into [collection, id].
 *   "col/id"                 shared data, governed by BN_RULES
 *   "data/users/<uid>/id"    a member's private data (their chat key): only that member, ever
 */
function bn_split(string $path): ?array {
    if (preg_match('~^(data/users/[\w.:-]{1,80})/([\w.:-]{1,120})$~', $path, $m)) return [$m[1], $m[2]];
    if (!preg_match('~^([a-z][a-z0-9_]{0,40})/([\w.:-]{1,120})$~', $path, $m)) return null;
    return [$m[1], $m[2]];
}

/** The owner's id if the path or collection is someone's private area, else null. */
function bn_private_owner(string $colOrPath): ?string {
    return preg_match('~^data/users/([\w.:-]{1,80})(/|$)~', $colOrPath, $m) ? $m[1] : null;
}

/** Documents only the admin may read (members never even receive them). */
const BN_ADMIN_ONLY_DOCS = ['settings/fbshare'];

/** Removes admin-only notes from documents everyone can read. */
function bn_redact(string $path, object $d, ?array $me): object {
    if ($me && $me['is_admin']) return $d;
    if ($path === 'settings/antispam') {
        // members' pages need the blocked IDs (to hide that content), never the admin's reasons
        if (isset($d->blocked) && is_object($d->blocked)) foreach (get_object_vars($d->blocked) as $k => $v) {
            if (is_object($v)) { unset($v->why); $d->blocked->$k = $v; }
        }
    }
    return $d;
}

function bn_can(string $op, string $path, ?array $me): bool {
    $p = bn_split($path);
    if (!$p) return false;
    [$col, $id] = $p;
    if (in_array($path, BN_ADMIN_ONLY_DOCS, true) && !($me && $me['is_admin'])) return false;
    $owner = bn_private_owner($col);
    if ($owner !== null) return $me !== null && $owner === $me['id'] && ($op === 'read' || $me['is_admin'] || !bn_blocked($me['id']));
    $base = BN_RULES[$col][$op] ?? null;
    if ($base !== null && bn_role_ok($base, $me)) return true;
    $self = BN_RULES[$col . '/{self}'][$op] ?? null;
    return $self !== null && $me && $id === $me['id'] && bn_role_ok($self, $me);
}

function bn_can_list(string $col, ?array $me): bool {
    $owner = bn_private_owner($col);
    if ($owner !== null) return $me !== null && $owner === $me['id'] && preg_match('~^data/users/[\w.:-]{1,80}$~', $col) === 1;
    if (!preg_match('~^[a-z][a-z0-9_]{0,40}$~', $col)) return false;
    $base = BN_RULES[$col]['read'] ?? null;
    if ($base !== null && bn_role_ok($base, $me)) return true;
    return isset(BN_RULES[$col . '/{self}']) && $me !== null;
}

/* ---------- responses ---------- */
function bn_json($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bn_fail(string $code, string $message, int $http = 400): never {
    bn_json(['error' => ['code' => $code, 'message' => $message]], $http);
}

/** Blocks requests coming from other websites (CSRF). The site always sends this header. */
function bn_require_same_site(): void {
    if (($_SERVER['HTTP_X_BN'] ?? '') !== '1') bn_fail('forbidden', 'Bad request origin.', 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (parse_url($origin, PHP_URL_HOST) !== explode(':', $host)[0]) bn_fail('forbidden', 'Bad request origin.', 403);
    }
}

function bn_body(): array {
    $raw = file_get_contents('php://input', false, null, 0, BN_DOC_MAX_BYTES + 4096);
    $d = json_decode((string) $raw, true);
    return is_array($d) ? $d : [];
}

/** Simple rate limit: at most $max events per key within $windowSec. */
function bn_throttle(string $key, int $max, int $windowSec): void {
    $db = bn_db();
    $now = bn_now();
    $db->prepare('DELETE FROM bn_attempts WHERE at < ?')->execute([$now - 86_400_000]);
    $st = $db->prepare('SELECT COUNT(*) FROM bn_attempts WHERE k = ? AND at > ?');
    $st->execute([$key, $now - $windowSec * 1000]);
    if ((int) $st->fetchColumn() >= $max) bn_fail('rate_limited', 'Too many tries. Please wait a few minutes and try again.', 429);
    $db->prepare('INSERT INTO bn_attempts (k, at) VALUES (?, ?)')->execute([$key, $now]);
}

/**
 * The visitor's address. On Cloudways requests pass through nginx and Varnish first, so the
 * direct address can be the server itself; then the address nginx recorded is used instead.
 */
function bn_ip(): string {
    $ra = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    $pub = fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    if (!$pub($ra)) {
        $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($pub($real)) return $real;
        // rightmost public entry = the one added by our own proxy, not one a visitor typed
        foreach (array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))) as $ip) if ($pub($ip)) return $ip;
    }
    return substr($ra, 0, 64);
}

function bn_put_doc(string $path, array|object $data): int {
    $db = bn_db();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > BN_DOC_MAX_BYTES) bn_fail('too_large', 'That is too much to save at once.', 413);
    [$col] = bn_split($path);
    $now = bn_now();
    if (bn_is_sqlite($db)) {
        $db->prepare('INSERT INTO bn_docs (path, col, data, updated, deleted) VALUES (?, ?, ?, ?, 0) ON CONFLICT(path) DO UPDATE SET data = excluded.data, updated = excluded.updated, deleted = 0')->execute([$path, $col, $json, $now]);
    } else {
        $db->prepare('INSERT INTO bn_docs (path, col, data, updated, deleted) VALUES (?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE data = VALUES(data), updated = VALUES(updated), deleted = 0')->execute([$path, $col, $json, $now]);
    }
    return $now;
}

/** Stored data as decoded JSON objects, so {} and [] stay exactly as the site saved them. */
function bn_get_doc(string $path): ?object {
    $st = bn_db()->prepare('SELECT data FROM bn_docs WHERE path = ? AND deleted = 0');
    $st->execute([$path]);
    $raw = $st->fetchColumn();
    if ($raw === false) return null;
    $d = json_decode((string) $raw, false);
    return is_object($d) ? $d : new stdClass();
}

/** The request body's "data" field, decoded as objects. */
function bn_body_data(): mixed {
    $raw = file_get_contents('php://input', false, null, 0, BN_DOC_MAX_BYTES + 4096);
    $d = json_decode((string) $raw, false);
    return is_object($d) && property_exists($d, 'data') ? $d->data : null;
}

function bn_new_id(string $prefix): string { return $prefix . bin2hex(random_bytes(10)); }


/* ---------- private settings (never sent to any browser) ---------- */
function bn_secret(string $k): string {
    $st = bn_db()->prepare('SELECT v FROM bn_secrets WHERE k = ?');
    $st->execute([$k]);
    return (string) ($st->fetchColumn() ?: '');
}
function bn_secret_set(string $k, string $v): void {
    $db = bn_db();
    $db->prepare('DELETE FROM bn_secrets WHERE k = ?')->execute([$k]);
    $db->prepare('INSERT INTO bn_secrets (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

/* ---------- two-step sign-in (authenticator app codes, RFC 6238) ---------- */
function bn_b32_encode(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function bn_b32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function bn_totp_at(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('J', $step), bn_b32_decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}
function bn_mfa_row(string $uid): ?array {
    $st = bn_db()->prepare('SELECT * FROM bn_totp WHERE user_id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}
function bn_mfa_on(string $uid): bool { $r = bn_mfa_row($uid); return $r !== null && (int) $r['enabled'] === 1; }
/** Checks an app code (each code works once) or a one-time recovery code. */
function bn_mfa_check(string $uid, string $code, bool $allowPendingSecret = false): bool {
    $r = bn_mfa_row($uid);
    if (!$r || (!$allowPendingSecret && (int) $r['enabled'] !== 1)) return false;
    $code = strtoupper(preg_replace('/[\s-]+/', '', $code));
    if (preg_match('/^\d{6}$/', $code)) {
        $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($step > (int) $r['last_step'] && hash_equals(bn_totp_at($r['secret'], $step), $code)) {
                bn_db()->prepare('UPDATE bn_totp SET last_step = ? WHERE user_id = ?')->execute([$step, $uid]);
                return true;
            }
        }
        return false;
    }
    if (preg_match('/^[A-Z0-9]{10}$/', $code) && (int) $r['enabled'] === 1) {
        $list = json_decode((string) $r['recovery'], true) ?: [];
        $h = hash('sha256', $code);
        $i = array_search($h, $list, true);
        if ($i === false) return false;
        array_splice($list, $i, 1);
        bn_db()->prepare('UPDATE bn_totp SET recovery = ? WHERE user_id = ?')->execute([json_encode($list), $uid]);
        return true;
    }
    return false;
}
/** Password was right: sign in now, or ask for the second step first. */
function bn_login_finish(string $uid, array $extra = []): never {
    bn_session();
    session_regenerate_id(true);
    unset($_SESSION['uid'], $_SESSION['mfa_uid'], $_SESSION['mfa_at']);
    if (bn_mfa_on($uid)) {
        $_SESSION['mfa_uid'] = $uid;
        $_SESSION['mfa_at'] = time();
        bn_json(['ok' => true, 'need_2fa' => true] + $extra);
    }
    $_SESSION['uid'] = $uid;
    bn_json(['ok' => true] + $extra);
}

/* ---------- text-message codes for new sign-ups (Twilio Verify) ---------- */
function bn_sms_ready(): bool {
    if (getenv('BN_TEST_SMS') === '1') return bn_secret('sms_on') === '1';
    return bn_secret('sms_on') === '1' && bn_secret('tw_sid') !== '' && bn_secret('tw_token') !== '' && bn_secret('tw_verify') !== '';
}
/** US and Canada mobile numbers only, as +1XXXXXXXXXX (keeps texting costs and fraud down). */
function bn_phone_norm(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    if (!preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $d)) return null;
    if (preg_match('/^(\d)\1{9}$/', $d) || substr($d, 3, 3) === '555') return null;
    return '+1' . $d;
}
function bn_phone_mask(string $e164): string { return '(***) ***-' . substr($e164, -4); }
/** Calls Twilio. Returns [httpStatus, decodedJson]. */
function bn_twilio(string $method, string $path, array $params = [], array $cred = []): array {
    $sid = $cred['sid'] ?? bn_secret('tw_sid');
    $tok = $cred['token'] ?? bn_secret('tw_token');
    $ch = curl_init('https://verify.twilio.com/v2/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERPWD => $sid . ':' . $tok, CURLOPT_CUSTOMREQUEST => $method]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $body, true) ?: []];
}
function bn_sms_send(string $phone): bool {
    if (getenv('BN_TEST_SMS') === '1') return true;
    [$c, $j] = bn_twilio('POST', 'Services/' . rawurlencode(bn_secret('tw_verify')) . '/Verifications', ['To' => $phone, 'Channel' => 'sms']);
    if ($c >= 300) error_log('[bn] twilio send ' . $c . ' ' . ($j['message'] ?? ''));
    return $c < 300 && ($j['status'] ?? '') === 'pending';
}
function bn_sms_check(string $phone, string $code): bool {
    if (getenv('BN_TEST_SMS') === '1') return $code === '123456';
    [$c, $j] = bn_twilio('POST', 'Services/' . rawurlencode(bn_secret('tw_verify')) . '/VerificationCheck', ['To' => $phone, 'Code' => $code]);
    return $c < 300 && ($j['status'] ?? '') === 'approved';
}

/** Creates the member: login row, Admin -> Users record and a public profile with just the name. */
function bn_create_account(string $name, string $email, string $hash, ?string $phone): string {
    $db = bn_db();
    $first = !(int) $db->query('SELECT COUNT(*) FROM bn_users WHERE is_admin = 1')->fetchColumn();
    $id = bn_new_id('m');
    $now = bn_now();
    $db->prepare('INSERT INTO bn_users (id, email, name, pass, is_admin, created) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $email, $name, $hash, $first ? 1 : 0, $now]);
    if ($phone) $db->prepare('INSERT INTO bn_phones (phone, user_id, created) VALUES (?, ?, ?)')->execute([$phone, $id, $now]);
    bn_put_doc('users/' . $id, ['name' => $name, 'email' => $email, 'joined' => gmdate('Y-m-d'), 'status' => 'Active', 'role' => $first ? 'Admin' : 'Member', 'trusted' => $first, 'listings' => 0, 'sales' => 0, 'rating' => 0, 'verified' => $phone ? ['email', 'phone'] : ['email'], 'phone' => $phone ? bn_phone_mask($phone) : '', 'last' => 'now']);
    if (!bn_get_doc('profiles/' . $id)) bn_put_doc('profiles/' . $id, ['name' => $name]);
    return $id;
}

/* ---------- removing the unused Laravel starter app Cloudways installed next to the site ---------- */
const BN_LARAVEL_LEFTOVERS = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'storage', 'tests', 'vendor', 'node_modules',
    'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'vite.config.js', 'postcss.config.js', 'tailwind.config.js',
    'README.md', 'server.php', '.env.example', '.editorconfig', '.gitattributes', '.styleci.yml'];
/** Only works in the 2-file setup (api.php sits in the web folder); never touches .env, the site, uploads or settings. */
function bn_leftovers(): array {
    if (!defined('BN_SINGLE')) return [];
    $root = realpath(__DIR__);
    // only when it really is a Laravel skeleton: artisan + composer.json naming laravel
    $cj = @file_get_contents($root . '/composer.json');
    if (!is_file($root . '/artisan') && !($cj && str_contains($cj, 'laravel'))) return [];
    return array_values(array_filter(BN_LARAVEL_LEFTOVERS, fn($n) => file_exists($root . '/' . $n) || is_link($root . '/' . $n)));
}
function bn_rm(string $path, string $root): void {
    if (is_link($path)) { @unlink($path); return; }
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return;
    if (is_dir($real)) {
        foreach (scandir($real) ?: [] as $e) if ($e !== '.' && $e !== '..') bn_rm($real . DIRECTORY_SEPARATOR . $e, $root);
        @rmdir($real);
    } else @unlink($real);
}

/* ================= request handler ================= */

set_exception_handler(function (Throwable $e) {
    error_log('[bn] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    bn_fail('server_error', 'Something went wrong on our end. Please try again.', 500);
});

bn_protect();
$route = (string) ($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') bn_require_same_site();

switch ($route) {

/* ---------------- setup check (safe to share: shows no secrets) ---------------- */
case 'cleanup':
    $me = bn_me();
    if (!$me || !$me['is_admin']) bn_fail('permission_denied', 'Admins only.', 403);
    if ($method === 'POST') {
        bn_throttle('cleanup:' . $me['id'], 5, 3600);
        $root = realpath(__DIR__);
        foreach (bn_leftovers() as $n) bn_rm($root . '/' . $n, $root);
        error_log('[bn] cleanup by ' . $me['id']);
    }
    bn_json(['left' => bn_leftovers(), 'single' => defined('BN_SINGLE')]);

case 'health':
    $out = ['php' => PHP_VERSION, 'php_ok' => version_compare(PHP_VERSION, '8.0', '>='), 'settings_found' => !empty(bn_config()['DB_DATABASE']) || !empty(bn_config()['BN_SQLITE'])];
    try {
        $db = bn_db();
        $out['database'] = 'connected';
        $out['members'] = (int) $db->query('SELECT COUNT(*) FROM bn_users')->fetchColumn();
    } catch (Throwable $e) {
        error_log('[bn] health: ' . $e->getMessage());
        $out['database'] = 'not connected';
    }
    $up = BN_UPLOADS;
    $out['uploads_folder'] = is_dir($up) && is_writable($up) ? 'writable' : 'not writable';
    $out['leftover_files'] = count(bn_leftovers());
    $out['ready'] = $out['php_ok'] && $out['database'] === 'connected' && $out['uploads_folder'] === 'writable';
    bn_json($out);

/* ---------------- accounts ---------------- */
case 'me':
    $me = bn_me();
    bn_session();
    bn_json(['me' => $me ? ['id' => $me['id'], 'name' => $me['name'], 'email' => $me['email'], 'admin' => $me['is_admin'], 'mfa' => bn_mfa_on($me['id'])] : null,
        'pending_2fa' => !$me && !empty($_SESSION['mfa_uid']) && time() - (int) ($_SESSION['mfa_at'] ?? 0) < 600,
        'sms_signup' => bn_sms_ready(), 'now' => bn_now()]);

case 'register':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_throttle('reg:' . bn_ip(), 8, 3600);
    $b = bn_body();
    $name = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($b['name'] ?? ''))));
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $pass = (string) ($b['password'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) bn_fail('invalid', 'Enter your name (2 to 60 letters).');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) bn_fail('invalid', 'Enter a valid email address.');
    if (strlen($pass) < 8 || strlen($pass) > 200) bn_fail('invalid', 'Use a password with at least 8 characters.');
    $st = bn_db()->prepare('SELECT 1 FROM bn_users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetchColumn()) bn_fail('exists', 'There is already an account with that email. Try signing in instead.', 409);
    $first = !(int) bn_db()->query('SELECT COUNT(*) FROM bn_users WHERE is_admin = 1')->fetchColumn();
    // the very first account (the site owner) never needs a text code
    if (!$first && bn_sms_ready()) {
        $phone = bn_phone_norm((string) ($b['phone'] ?? ''));
        if (!$phone) bn_fail('invalid_phone', 'Enter a US or Canadian mobile number, like 801-555-0123.');
        $st = bn_db()->prepare('SELECT 1 FROM bn_phones WHERE phone = ?');
        $st->execute([$phone]);
        if ($st->fetchColumn()) bn_fail('phone_used', 'That phone number is already on another account. Try signing in instead.', 409);
        bn_throttle('smsip:' . bn_ip(), 6, 86400);
        bn_throttle('smsph:' . $phone, 4, 86400);
        if (!bn_sms_send($phone)) bn_fail('sms_failed', 'We couldn\'t text that number. Check it and try again.', 502);
        bn_session();
        $_SESSION['reg'] = ['name' => $name, 'email' => $email, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'phone' => $phone, 'at' => time(), 'sent' => time(), 'tries' => 0, 'sends' => 1];
        bn_json(['ok' => true, 'need_sms' => true, 'phone' => bn_phone_mask($phone)]);
    }
    $id = bn_create_account($name, $email, password_hash($pass, PASSWORD_DEFAULT), null);
    bn_login_finish($id, ['admin' => $first]);

case 'register_verify':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_session();
    $r = $_SESSION['reg'] ?? null;
    if (!$r || time() - (int) $r['at'] > 1800) bn_fail('expired', 'That sign-up timed out. Please start again.', 400);
    if ((int) $r['tries'] >= 6) { unset($_SESSION['reg']); bn_fail('expired', 'Too many wrong codes. Please start again.', 400); }
    $_SESSION['reg']['tries'] = (int) $r['tries'] + 1;
    $code = preg_replace('/\D+/', '', (string) (bn_body()['code'] ?? ''));
    if (strlen($code) < 4 || !bn_sms_check($r['phone'], $code)) bn_fail('bad_code', 'That code isn\'t right. Check the text and try again.', 400);
    unset($_SESSION['reg']);
    $st = bn_db()->prepare('SELECT 1 FROM bn_users WHERE email = ?');
    $st->execute([$r['email']]);
    if ($st->fetchColumn()) bn_fail('exists', 'There is already an account with that email. Try signing in instead.', 409);
    $id = bn_create_account($r['name'], $r['email'], $r['hash'], $r['phone']);
    bn_login_finish($id, ['admin' => false]);

case 'register_resend':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_session();
    $r = $_SESSION['reg'] ?? null;
    if (!$r || time() - (int) $r['at'] > 1800) bn_fail('expired', 'That sign-up timed out. Please start again.', 400);
    if (time() - (int) $r['sent'] < 60) bn_fail('wait', 'Please wait a minute before asking for another code.', 429);
    if ((int) $r['sends'] >= 3) bn_fail('wait', 'We\'ve sent a few codes already. Please start again later.', 429);
    bn_throttle('smsip:' . bn_ip(), 6, 86400);
    bn_throttle('smsph:' . $r['phone'], 4, 86400);
    if (!bn_sms_send($r['phone'])) bn_fail('sms_failed', 'We couldn\'t send a new code. Try again in a minute.', 502);
    $_SESSION['reg']['sent'] = time();
    $_SESSION['reg']['sends'] = (int) $r['sends'] + 1;
    bn_json(['ok' => true]);

case 'login':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $b = bn_body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    bn_throttle('login:' . bn_ip(), 20, 900);
    bn_throttle('loginmail:' . $email, 8, 900);
    $st = bn_db()->prepare('SELECT id, pass FROM bn_users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify((string) ($b['password'] ?? ''), $u['pass'])) bn_fail('bad_login', 'That email and password don\'t match.', 401);
    if (in_array(bn_member_status($u['id']), ['Deactivated', 'Banned'], true)) bn_fail('blocked', 'This account is turned off. Contact the site team for help.', 403);
    if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) bn_db()->prepare('UPDATE bn_users SET pass = ? WHERE id = ?')->execute([password_hash((string) $b['password'], PASSWORD_DEFAULT), $u['id']]);
    bn_login_finish($u['id']);

case 'login_2fa':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_session();
    $uid = (string) ($_SESSION['mfa_uid'] ?? '');
    if ($uid === '' || time() - (int) ($_SESSION['mfa_at'] ?? 0) > 600) bn_fail('expired', 'That took too long. Please sign in again.', 400);
    bn_throttle('mfa:' . $uid, 8, 900);
    bn_throttle('mfaip:' . bn_ip(), 20, 900);
    if (!bn_mfa_check($uid, (string) (bn_body()['code'] ?? ''))) bn_fail('bad_code', 'That code isn\'t right. Use the newest code in your authenticator app.', 400);
    session_regenerate_id(true);
    unset($_SESSION['mfa_uid'], $_SESSION['mfa_at']);
    $_SESSION['uid'] = $uid;
    bn_json(['ok' => true]);

case 'logout':
    bn_session();
    $_SESSION = [];
    session_destroy();
    bn_json(['ok' => true]);

case 'forgot':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_throttle('forgot:' . bn_ip(), 5, 3600);
    $email = strtolower(trim((string) (bn_body()['email'] ?? '')));
    $st = bn_db()->prepare('SELECT id, name FROM bn_users WHERE email = ?');
    $st->execute([$email]);
    if ($u = $st->fetch()) {
        $token = bin2hex(random_bytes(24));
        bn_db()->prepare('INSERT INTO bn_resets (token_hash, user_id, expires) VALUES (?, ?, ?)')->execute([hash('sha256', $token), $u['id'], bn_now() + 3600_000]);
        $cfg = bn_config();
        $site = rtrim($cfg['BN_SITE_URL'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? '')), '/');
        $link = $site . '/#reset=' . $token;
        $from = $cfg['BN_MAIL_FROM'] ?? 'service@thebignickel.biz';
        $body = "Hi {$u['name']},\n\nSomeone asked to reset the password for your Big Nickel account. If that was you, open this link within an hour:\n\n$link\n\nIf it wasn't you, ignore this email and your password stays the same.\n\nThe Big Nickel Classifieds";
        if (function_exists('mail')) @mail($email, 'Reset your Big Nickel password', $body, "From: The Big Nickel <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8", '-f' . $from);
    }
    // same answer either way, so nobody can test which emails have accounts
    bn_json(['ok' => true]);

/* ---------------- owner recovery: proves control of the site's files, then resets the admin login ---------------- */
case 'owner_reset':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_throttle('owner:' . bn_ip(), 6, 3600);
    $kf = null;
    foreach ([__DIR__ . '/owner-key.php', dirname(__DIR__) . '/owner-key.php'] as $f) if (is_file($f)) { $kf = $f; break; }
    if (!$kf) bn_fail('no_key', 'Owner recovery is off. Add the owner-key.php file first (see the steps), then try again.', 400);
    $key = @include $kf;
    if (!is_string($key) || strlen(trim($key)) < 16) bn_fail('no_key', 'The owner key in owner-key.php needs to be at least 16 characters.', 400);
    $b = bn_body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $pass = (string) ($b['password'] ?? '');
    if (!hash_equals(trim($key), trim((string) ($b['key'] ?? '')))) bn_fail('bad_key', 'That owner key doesn\'t match the one in owner-key.php.', 403);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) bn_fail('invalid', 'Enter a valid email address.');
    if (strlen($pass) < 10 || strlen($pass) > 200) bn_fail('invalid', 'Use a password with at least 10 characters.');
    $db = bn_db();
    $admin = $db->query('SELECT id FROM bn_users WHERE is_admin = 1 ORDER BY created ASC LIMIT 1')->fetchColumn();
    $st = $db->prepare('SELECT id FROM bn_users WHERE email = ? AND id <> ?');
    $st->execute([$email, (string) $admin]);
    if ($st->fetchColumn()) bn_fail('exists', 'Another member already uses that email. Choose a different one.', 409);
    if ($admin) {
        $db->prepare('UPDATE bn_users SET email = ?, pass = ? WHERE id = ?')->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $admin]);
        $db->prepare('DELETE FROM bn_totp WHERE user_id = ?')->execute([$admin]);
        $u = bn_get_doc('users/' . $admin);
        if ($u) { $u->email = $email; $u->status = 'Active'; bn_put_doc('users/' . $admin, $u); }
        $id = (string) $admin;
    } else {
        $id = bn_create_account('Site Owner', $email, password_hash($pass, PASSWORD_DEFAULT), null);
    }
    $db->prepare("DELETE FROM bn_attempts WHERE k LIKE 'login%' OR k LIKE 'mfa%'")->execute();
    $db->prepare('DELETE FROM bn_resets WHERE user_id = ?')->execute([$id]);
    @unlink($kf); // one use only
    error_log('[bn] owner recovery used for ' . $id);
    bn_session();
    session_regenerate_id(true);
    unset($_SESSION['mfa_uid'], $_SESSION['mfa_at']);
    $_SESSION['uid'] = $id;
    bn_json(['ok' => true, 'key_removed' => !is_file($kf)]);

case 'reset':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_throttle('reset:' . bn_ip(), 10, 3600);
    $b = bn_body();
    $pass = (string) ($b['password'] ?? '');
    if (strlen($pass) < 8 || strlen($pass) > 200) bn_fail('invalid', 'Use a password with at least 8 characters.');
    $db = bn_db();
    $st = $db->prepare('SELECT user_id FROM bn_resets WHERE token_hash = ? AND expires > ?');
    $st->execute([hash('sha256', (string) ($b['token'] ?? '')), bn_now()]);
    $uid = $st->fetchColumn();
    if (!$uid) bn_fail('expired', 'That reset link has expired. Ask for a new one.', 400);
    $db->prepare('UPDATE bn_users SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
    $db->prepare('DELETE FROM bn_resets WHERE user_id = ?')->execute([$uid]);
    bn_login_finish((string) $uid);

/* ---------------- two-step sign-in settings (for the signed-in member) ---------------- */
case 'mfa_setup':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $me = bn_me();
    if (!$me) bn_fail('unauthenticated', 'Please sign in first.', 401);
    if (bn_mfa_on($me['id'])) bn_fail('already', 'Two-step sign-in is already on.', 409);
    $secret = bn_b32_encode(random_bytes(20));
    bn_db()->prepare('DELETE FROM bn_totp WHERE user_id = ?')->execute([$me['id']]);
    bn_db()->prepare('INSERT INTO bn_totp (user_id, secret, enabled, last_step, recovery) VALUES (?, ?, 0, 0, ?)')->execute([$me['id'], $secret, '[]']);
    $label = rawurlencode('The Big Nickel:' . $me['email']);
    bn_json(['secret' => $secret, 'uri' => "otpauth://totp/$label?secret=$secret&issuer=" . rawurlencode('The Big Nickel') . '&digits=6&period=30']);

case 'mfa_enable':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $me = bn_me();
    if (!$me) bn_fail('unauthenticated', 'Please sign in first.', 401);
    bn_throttle('mfaset:' . $me['id'], 10, 900);
    if (bn_mfa_on($me['id'])) bn_fail('already', 'Two-step sign-in is already on.', 409);
    if (!bn_mfa_check($me['id'], (string) (bn_body()['code'] ?? ''), true)) bn_fail('bad_code', 'That code isn\'t right. Type the 6 numbers your app shows right now.', 400);
    $codes = [];
    for ($i = 0; $i < 10; $i++) $codes[] = substr(strtoupper(bn_b32_encode(random_bytes(10))), 0, 10);
    bn_db()->prepare('UPDATE bn_totp SET enabled = 1, recovery = ? WHERE user_id = ?')->execute([json_encode(array_map(fn($c) => hash('sha256', $c), $codes)), $me['id']]);
    bn_json(['ok' => true, 'recovery' => $codes]);

case 'mfa_disable':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $me = bn_me();
    if (!$me) bn_fail('unauthenticated', 'Please sign in first.', 401);
    bn_throttle('mfaset:' . $me['id'], 10, 900);
    $b = bn_body();
    $st = bn_db()->prepare('SELECT pass FROM bn_users WHERE id = ?');
    $st->execute([$me['id']]);
    if (!password_verify((string) ($b['password'] ?? ''), (string) $st->fetchColumn())) bn_fail('bad_login', 'That password isn\'t right.', 400);
    if (!bn_mfa_check($me['id'], (string) ($b['code'] ?? ''))) bn_fail('bad_code', 'That code isn\'t right.', 400);
    bn_db()->prepare('DELETE FROM bn_totp WHERE user_id = ?')->execute([$me['id']]);
    bn_json(['ok' => true]);

/* ---------------- text-message sign-up settings (admin only; keys never leave the server) ---------------- */
case 'sms_settings':
    $me = bn_me();
    if (!$me || !$me['is_admin']) bn_fail('permission_denied', 'Admins only.', 403);
    if ($method === 'POST') {
        $b = bn_body();
        $sid = trim((string) ($b['sid'] ?? ''));
        $tok = trim((string) ($b['token'] ?? ''));
        $ver = trim((string) ($b['verify'] ?? ''));
        if ($sid !== '' && !preg_match('/^AC[0-9a-f]{32}$/i', $sid)) bn_fail('invalid', 'The Account SID starts with AC followed by 32 letters and numbers.');
        if ($ver !== '' && !preg_match('/^VA[0-9a-f]{32}$/i', $ver)) bn_fail('invalid', 'The Verify Service SID starts with VA followed by 32 letters and numbers.');
        if ($tok !== '' && !preg_match('/^[0-9a-f]{32}$/i', $tok)) bn_fail('invalid', 'The Auth Token is 32 letters and numbers.');
        $nSid = $sid ?: bn_secret('tw_sid'); $nTok = $tok ?: bn_secret('tw_token'); $nVer = $ver ?: bn_secret('tw_verify');
        $on = !empty($b['on']);
        if ($on && getenv('BN_TEST_SMS') !== '1') {
            if (!$nSid || !$nTok || !$nVer) bn_fail('invalid', 'Fill in all three Twilio details before turning this on.');
            [$c] = bn_twilio('GET', 'Services/' . rawurlencode($nVer), [], ['sid' => $nSid, 'token' => $nTok]);
            if ($c !== 200) bn_fail('twilio', 'Twilio didn\'t accept those details. Check them and try again.', 400);
        }
        if ($sid !== '') bn_secret_set('tw_sid', $sid);
        if ($tok !== '') bn_secret_set('tw_token', $tok);
        if ($ver !== '') bn_secret_set('tw_verify', $ver);
        bn_secret_set('sms_on', $on ? '1' : '0');
    }
    $s2 = bn_secret('tw_sid');
    bn_json(['on' => bn_secret('sms_on') === '1', 'sid' => $s2 ? substr($s2, 0, 6) . '…' . substr($s2, -4) : '', 'token_set' => bn_secret('tw_token') !== '', 'verify' => bn_secret('tw_verify') ? '…' . substr(bn_secret('tw_verify'), -4) : '']);

/* ---------------- stored data (same shape as the preview's database) ---------------- */
case 'doc':
    $path = (string) ($_GET['path'] ?? '');
    $me = bn_me();
    if (!bn_can('read', $path, $me)) bn_fail('permission_denied', 'You don\'t have access to that.', 403);
    $d = bn_get_doc($path);
    bn_json(['exists' => $d !== null, 'data' => $d === null ? new stdClass() : bn_redact($path, $d, $me)]);

case 'col':
    $col = (string) ($_GET['path'] ?? '');
    $me = bn_me();
    if (!bn_can_list($col, $me)) bn_fail('permission_denied', 'You don\'t have access to that.', 403);
    $st = bn_db()->prepare('SELECT path, data FROM bn_docs WHERE col = ? AND deleted = 0');
    $st->execute([$col]);
    $docs = [];
    foreach ($st as $row) {
        if (!bn_can('read', $row['path'], $me)) continue;
        $d = json_decode((string) $row['data'], false);
        $docs[] = ['id' => bn_split($row['path'])[1], 'data' => is_object($d) ? bn_redact($row['path'], $d, $me) : new stdClass()];
    }
    bn_json(['docs' => $docs]);

case 'set':
case 'update':
case 'delete':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $b = bn_body();
    $path = (string) ($b['path'] ?? '');
    $me = bn_me();
    if (!bn_can('write', $path, $me)) bn_fail('permission_denied', $me ? 'You can\'t change that.' : 'Please sign in first.', $me ? 403 : 401);
    bn_throttle('w:' . ($me['id'] ?? bn_ip()), 240, 60);
    if ($route === 'delete') {
        $db = bn_db();
        $db->prepare('UPDATE bn_docs SET deleted = 1, data = NULL, updated = ? WHERE path = ?')->execute([bn_now(), $path]);
        bn_json(['ok' => true]);
    }
    $data = bn_body_data();
    if (!is_object($data)) bn_fail('invalid_argument', 'Data must be an object.');
    if ($route === 'update') {
        $cur = bn_get_doc($path);
        if ($cur === null) bn_fail('not_found', 'Nothing to update yet.', 404);
        foreach (get_object_vars($data) as $k => $v) $cur->$k = $v;
        $data = $cur;
    }
    bn_put_doc($path, $data);
    bn_json(['ok' => true]);

case 'sync':
    // paths changed since the given time, so open pages can refresh what they show
    $since = max(0, (int) ($_GET['since'] ?? 0));
    $me = bn_me();
    $st = bn_db()->prepare('SELECT path FROM bn_docs WHERE updated > ? ORDER BY updated LIMIT 500');
    $st->execute([$since]);
    $paths = [];
    foreach ($st as $row) if (bn_can('read', $row['path'], $me)) $paths[] = $row['path'];
    bn_json(['now' => bn_now(), 'changed' => $paths]);

/* ---------------- uploads ---------------- */
case 'upload':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $me = bn_me();
    if (!$me) bn_fail('unauthenticated', 'Please sign in to upload photos.', 401);
    if (!$me['is_admin'] && bn_blocked($me['id'])) bn_fail('blocked', 'This account can\'t upload right now.', 403);
    bn_throttle('up:' . $me['id'], 60, 600);
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_uploaded_file($f['tmp_name']) || $f['error'] !== UPLOAD_ERR_OK) bn_fail('invalid', 'The upload didn\'t come through. Please try again.');
    $size = (int) $f['size'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
    if (!isset($types[$mime])) bn_fail('invalid', 'Only photos (JPG, PNG, WebP, GIF) and videos (MP4, WebM, MOV) can be uploaded.');
    $isImage = str_starts_with($mime, 'image/');
    if ($size <= 0 || $size > ($isImage ? BN_IMAGE_MAX_BYTES : BN_UPLOAD_MAX_BYTES)) bn_fail('too_large', $isImage ? 'Photos can be up to 15 MB.' : 'Videos can be up to 60 MB.', 413);
    if ($isImage && @getimagesize($f['tmp_name']) === false) bn_fail('invalid', 'That file isn\'t a valid photo.');
    $id = bn_new_id('a');
    $sub = gmdate('Ym');
    $dir = BN_UPLOADS . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) bn_fail('server_error', 'Upload folder is missing.', 500);
    $name = $id . '.' . $types[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) bn_fail('server_error', 'Couldn\'t save the upload.', 500);
    @chmod("$dir/$name", 0644);
    bn_db()->prepare('INSERT INTO bn_assets (id, user_id, file, mime, size, created) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $me['id'], "$sub/$name", $mime, $size, bn_now()]);
    bn_json(['id' => $id, 'url' => "/u/$sub/$name", 'sizeBytes' => $size, 'contentType' => $mime]);

case 'asset_delete':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    $me = bn_me();
    if (!$me) bn_fail('unauthenticated', 'Please sign in first.', 401);
    $id = (string) (bn_body()['id'] ?? '');
    $st = bn_db()->prepare('SELECT user_id, file FROM bn_assets WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) bn_json(['ok' => true]);
    if ($a['user_id'] !== $me['id'] && !$me['is_admin']) bn_fail('permission_denied', 'You can\'t delete that file.', 403);
    $full = realpath(BN_UPLOADS . '/' . $a['file']);
    $root = realpath(BN_UPLOADS);
    if ($full && $root && str_starts_with($full, $root . DIRECTORY_SEPARATOR)) @unlink($full);
    bn_db()->prepare('DELETE FROM bn_assets WHERE id = ?')->execute([$id]);
    bn_json(['ok' => true]);

default:
    bn_fail('not_found', 'Unknown request.', 404);
}
