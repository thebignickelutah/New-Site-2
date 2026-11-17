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
    if (str_contains($cur, '# BN-PROTECT')) return;
    $block = "\n# BN-PROTECT (added by The Big Nickel)\nDirectoryIndex index.html\nAddDefaultCharset UTF-8\nOptions -Indexes\n<FilesMatch \"^\\.|\\.(env|ini|log|sql|sqlite|bak)$|^config\\.local\\.php$\">\n  Require all denied\n</FilesMatch>\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.html$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n  <FilesMatch \"^api\\.php$\">\n    Header set Cache-Control \"no-store, private\"\n  </FilesMatch>\n</IfModule>\n";
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
    $flag = sys_get_temp_dir() . '/bn-schema-v1-' . md5((string) json_encode(bn_config()['DB_DATABASE'] ?? bn_config()['BN_SQLITE'] ?? ''));
    if (is_file($flag)) return;
    $sqlite = bn_is_sqlite($db);
    $text = $sqlite ? 'TEXT' : 'LONGTEXT';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $stmts = [
        "CREATE TABLE IF NOT EXISTS bn_docs (path VARCHAR(400) NOT NULL PRIMARY KEY, col VARCHAR(300) NOT NULL, data $text, updated BIGINT NOT NULL, deleted SMALLINT NOT NULL DEFAULT 0)$engine",
        "CREATE TABLE IF NOT EXISTS bn_users (id VARCHAR(40) NOT NULL PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, name VARCHAR(80) NOT NULL, pass VARCHAR(255) NOT NULL, is_admin SMALLINT NOT NULL DEFAULT 0, created BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_resets (token_hash CHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(40) NOT NULL, expires BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_attempts (k VARCHAR(255) NOT NULL, at BIGINT NOT NULL)$engine",
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

function bn_ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 64); }

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
    $out['ready'] = $out['php_ok'] && $out['database'] === 'connected' && $out['uploads_folder'] === 'writable';
    bn_json($out);

/* ---------------- accounts ---------------- */
case 'me':
    $me = bn_me();
    bn_json(['me' => $me ? ['id' => $me['id'], 'name' => $me['name'], 'email' => $me['email'], 'admin' => $me['is_admin']] : null, 'now' => bn_now()]);

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
    $db = bn_db();
    $st = $db->prepare('SELECT 1 FROM bn_users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetchColumn()) bn_fail('exists', 'There is already an account with that email. Try signing in instead.', 409);
    $first = !(int) $db->query('SELECT COUNT(*) FROM bn_users WHERE is_admin = 1')->fetchColumn();
    $id = bn_new_id('m');
    $now = bn_now();
    $db->prepare('INSERT INTO bn_users (id, email, name, pass, is_admin, created) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$id, $email, $name, password_hash($pass, PASSWORD_DEFAULT), $first ? 1 : 0, $now]);
    // member record for Admin -> Users, and a public profile with just the name
    bn_put_doc('users/' . $id, ['name' => $name, 'email' => $email, 'joined' => gmdate('Y-m-d'), 'status' => 'Active', 'role' => $first ? 'Admin' : 'Member', 'trusted' => $first, 'listings' => 0, 'sales' => 0, 'rating' => 0, 'verified' => ['email'], 'last' => 'now']);
    if (!bn_get_doc('profiles/' . $id)) bn_put_doc('profiles/' . $id, ['name' => $name]);
    bn_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    bn_json(['ok' => true, 'admin' => $first]);

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
    bn_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
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
        $from = $cfg['BN_MAIL_FROM'] ?? ('noreply@' . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $body = "Hi {$u['name']},\n\nSomeone asked to reset the password for your Big Nickel account. If that was you, open this link within an hour:\n\n$link\n\nIf it wasn't you, ignore this email and your password stays the same.\n\nThe Big Nickel Classifieds";
        if (function_exists('mail')) @mail($email, 'Reset your Big Nickel password', $body, "From: The Big Nickel <$from>\r\nContent-Type: text/plain; charset=UTF-8");
    }
    // same answer either way, so nobody can test which emails have accounts
    bn_json(['ok' => true]);

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
    bn_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    bn_json(['ok' => true]);

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
