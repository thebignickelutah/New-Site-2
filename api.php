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
    $flag = sys_get_temp_dir() . '/bn-schema-v4-' . md5((string) json_encode(bn_config()['DB_DATABASE'] ?? bn_config()['BN_SQLITE'] ?? ''));
    if (is_file($flag)) {
        try { $db->query('SELECT 1 FROM bn_sessions LIMIT 1'); return; } catch (PDOException $e) { /* tables missing: create them */ }
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
        "CREATE TABLE IF NOT EXISTS bn_sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, data $text, expires BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_google (sub VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(40) NOT NULL, created BIGINT NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS bn_assets (id VARCHAR(40) NOT NULL PRIMARY KEY, user_id VARCHAR(40) NOT NULL, file VARCHAR(255) NOT NULL, mime VARCHAR(100) NOT NULL, size BIGINT NOT NULL, created BIGINT NOT NULL)$engine",
    ];
    foreach ($stmts as $s) $db->exec($s);
    foreach (['CREATE INDEX bn_docs_col ON bn_docs (col)', 'CREATE INDEX bn_docs_updated ON bn_docs (updated)', 'CREATE INDEX bn_attempts_k ON bn_attempts (k, at)'] as $s) {
        try { $db->exec($s); } catch (PDOException $e) { /* already exists */ }
    }
    @file_put_contents($flag, '1');
}

function bn_now(): int { return (int) floor(microtime(true) * 1000); }

/**
 * Sign-ins are kept in the database, not in the server's temp files: hosting companies clean temp
 * files out after about 24 minutes, which signed people out mid-visit even though their cookie said 60 days.
 */
final class BnSessions implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private const LIFE = 60 * 86400;
    private array $seen = [];
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    private function row(string $id): ?array {
        $st = bn_db()->prepare('SELECT data, expires FROM bn_sessions WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r && (int) $r['expires'] > time() ? $r : null;
    }
    public function read(string $id): string|false {
        $r = $this->row($id);
        $this->seen[$id] = $r ? [(string) $r['data'], (int) $r['expires']] : null;
        return $r ? (string) $r['data'] : '';
    }
    public function write(string $id, string $data): bool {
        $exp = time() + self::LIFE;
        $old = $this->seen[$id] ?? null;
        // skip the write when nothing changed and the expiry was pushed out within the last day
        if ($old && $old[0] === $data && $old[1] > $exp - 86400) return true;
        if ($data === '' && !$old) return true; // never store empty sessions for signed-out visitors
        $db = bn_db();
        $st = $db->prepare('UPDATE bn_sessions SET data = ?, expires = ? WHERE id = ?');
        $st->execute([$data, $exp, $id]);
        if ($st->rowCount() === 0 && !$old) {
            try { $db->prepare('INSERT INTO bn_sessions (id, data, expires) VALUES (?, ?, ?)')->execute([$id, $data, $exp]); } catch (PDOException $e) { /* written by a parallel request */ }
        }
        return true;
    }
    public function destroy(string $id): bool { bn_db()->prepare('DELETE FROM bn_sessions WHERE id = ?')->execute([$id]); return true; }
    public function gc(int $max): int|false { $st = bn_db()->prepare('DELETE FROM bn_sessions WHERE expires < ?'); $st->execute([time()]); return $st->rowCount(); }
    public function validateId(string $id): bool { return $this->row($id) !== null; }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id, $data); }
}

function bn_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_save_handler(new BnSessions(), true);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
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

/* ---------- money guard: members can't mark their own orders paid or feature listings for free ---------- */
const BN_DAY_MS = 86400000;
const BN_PW_FEAT = 25;          // featured listings per Power Seller month
const BN_PW_BUMP = 10;          // free bumps per Power Seller month
const BN_PW_DAYS = 30;

function bn_objs($v): array { return is_array($v) ? array_values(array_filter($v, 'is_object')) : []; }
function bn_clone($v) { return json_decode(json_encode($v)); }

/** What a member may change on one of their own existing records; everything else stays as stored. */
function bn_guard_existing(string $kind, object $old, object $new): object {
    $o = bn_clone($old);
    $now = bn_now();
    $moves = [
        'upgrades' => ['Awaiting payment' => ['Payment submitted', 'Cancelled'], 'Payment submitted' => ['Cancelled']],
        'shop' => ['Awaiting payment' => ['Payment submitted', 'Cancelled'], 'Payment submitted' => ['Cancelled'], 'Shipped' => ['Completed'], 'Ready for pickup' => ['Completed']],
        'orders' => [],
    ][$kind];
    $from = (string) ($old->status ?? '');
    $to = is_string($new->status ?? null) ? $new->status : $from;
    if ($to !== $from && in_array($to, $moves[$from] ?? [], true)) $o->status = $to;
    if (isset($new->payStarted) && (is_int($new->payStarted) || is_float($new->payStarted) || is_string($new->payStarted))) $o->payStarted = is_string($new->payStarted) ? substr($new->payStarted, 0, 40) : $new->payStarted;
    if ($kind === 'upgrades' && ($new->applied ?? false) === true && $from === 'Paid' && empty($old->applied)) { $o->applied = true; $o->appliedAt = $now; }
    if ($kind === 'shop') {
        if (isset($new->ship) && is_object($new->ship) && isset($new->ship->payStarted)) {
            if (!isset($o->ship) || !is_object($o->ship)) $o->ship = new stdClass();
            $o->ship->payStarted = (int) $new->ship->payStarted;
        }
        $om = bn_objs($old->msgs ?? []); $nm = bn_objs($new->msgs ?? []);
        foreach (array_slice($nm, count($om)) as $m) $om[] = (object) ['from' => 'buyer', 't' => mb_substr(strip_tags((string) ($m->t ?? '')), 0, 2000), 'at' => $now];
        $o->msgs = array_slice($om, -200);
    }
    return $o;
}

/** A record a member is creating: it always starts unpaid. */
function bn_guard_fresh(string $kind, object $r, object $doc): object {
    foreach (['paid', 'paidAt', 'paidAmount', 'payout', 'applied', 'appliedAt'] as $k) unset($r->$k);
    if ($kind === 'upgrades') { $r->status = 'Awaiting payment'; $r->applied = false; }
    if ($kind === 'shop') {
        $r->status = 'Awaiting payment';
        $r->ship = (object) [];
        foreach (bn_objs($r->msgs ?? []) as $m) $m->from = 'buyer';
    }
    if ($kind === 'orders') {
        $st = (string) ($r->status ?? '');
        if (!in_array($st, ['Quote requested', 'Awaiting payment', 'Pending review'], true)) $st = 'Awaiting payment';
        if (!empty($r->powerPerk)) {
            $pw = bn_power_window($doc);
            $ad = $doc->power->ad ?? null;
            $used = is_object($ad) && (int) ($ad->at ?? 0) >= $pw[0];
            if (!$pw[1] || $used) { unset($r->powerPerk); $st = 'Awaiting payment'; }
        }
        $pay = bn_get_doc('settings/paypal');
        if ($st === 'Pending review' && empty($r->powerPerk) && (float) ($r->total ?? 0) > 0 && $pay && !empty($pay->email)) $st = 'Awaiting payment';
        $r->status = $st;
    }
    return $r;
}

/** [start of this month's perks, active] for the member's paid Power Seller plan (1 month, or 3 months
 *  where perks reset every 30 days), from their stored admin-confirmed upgrades. */
function bn_power_window(object $doc): array {
    $now = bn_now(); $M = BN_PW_DAYS * BN_DAY_MS; $best = null;
    foreach (bn_objs($doc->upgrades ?? []) as $u) {
        if (($u->status ?? '') !== 'Paid') continue;
        $months = 0;
        foreach (bn_objs($u->items ?? []) as $i) { $k = $i->kind ?? ''; if ($k === 'power') $months = max($months, 1); if ($k === 'power3') $months = 3; }
        if (!$months) continue;
        $s = (int) ($u->paidAt ?? $u->at ?? 0); $e = $s + $months * $M;
        if ($s <= $now && $now < $e && (!$best || $s > $best[0])) $best = [$s, $e];
    }
    if (!$best) return [0, false];
    return [$best[0] + intdiv($now - $best[0], $M) * $M, true];
}

/** adorders/{uid} written by its member: keep admin decisions, allow only buyer-side steps. */
function bn_guard_adorders(object $new, ?object $old): object {
    $old = $old ?? new stdClass();
    $perkAd = null;
    foreach (['upgrades', 'shop', 'orders'] as $kind) {
        if (!property_exists($new, $kind) && !property_exists($old, $kind)) continue;
        $byId = [];
        foreach (bn_objs($old->$kind ?? []) as $r) $byId[(string) ($r->id ?? '')] = $r;
        $out = []; $seen = [];
        foreach (bn_objs($new->$kind ?? []) as $r) {
            $id = (string) ($r->id ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = isset($byId[$id]) ? bn_guard_existing($kind, $byId[$id], $r) : bn_guard_fresh($kind, $r, $old);
        }
        // records the admin has acted on can't be erased by the member
        foreach ($byId as $id => $r) if (!isset($seen[$id]) && !in_array((string) ($r->status ?? ''), ['Awaiting payment', 'Cancelled', 'Quote requested'], true)) $out[] = $r;
        $new->$kind = $out;
        if ($kind === 'orders') foreach ($out as $r) if (!isset($byId[(string) $r->id]) && !empty($r->powerPerk)) $perkAd = (string) $r->id;
    }
    // Power Seller usage: only while the plan is paid up, never more than the monthly allowance, never erased
    [$start, $active] = bn_power_window($old);
    $op = isset($old->power) && is_object($old->power) ? $old->power : (object) ['feat' => [], 'ad' => null];
    $np = isset($new->power) && is_object($new->power) ? $new->power : new stdClass();
    $uses = function (string $k, int $cap) use ($op, $np, $start, $active): array {
        $list = bn_objs($op->$k ?? []);
        $have = [];
        foreach ($list as $f) $have[(int) ($f->lid ?? 0) . ':' . (int) ($f->at ?? 0)] = true;
        $n = count(array_filter($list, fn($f) => (int) ($f->at ?? 0) >= $start));
        foreach (bn_objs($np->$k ?? []) as $f) {
            $lid = (int) ($f->lid ?? 0);
            if ($lid <= 0 || isset($have[$lid . ':' . (int) ($f->at ?? 0)])) continue;
            if (!$active || $n >= $cap) continue;
            $list[] = (object) ['lid' => $lid, 'at' => bn_now()]; $n++;
        }
        return array_slice($list, -200);
    };
    $feat = $uses('feat', BN_PW_FEAT);
    $bump = $uses('bump', BN_PW_BUMP);
    $ad = isset($op->ad) && is_object($op->ad) ? $op->ad : null;
    // the free banner week is used up the moment its order is placed
    if ($active && !($ad && (int) ($ad->at ?? 0) >= $start) && ($perkAd !== null || (isset($np->ad) && is_object($np->ad)))) $ad = (object) ['at' => bn_now(), 'id' => substr($perkAd ?? (string) ($np->ad->id ?? ''), 0, 80)];
    if ($feat || $bump || $ad || isset($old->power) || isset($new->power)) $new->power = (object) ['feat' => $feat, 'bump' => $bump, 'ad' => $ad];
    return $new;
}

/* Per-member ledger the browser never sees: seller-reward progress and which paid perks were used. */
function bn_ent_get(string $uid): object {
    $d = json_decode(bn_secret('ent:' . $uid), false);
    $d = is_object($d) ? $d : new stdClass();
    $d->ids = is_array($d->ids ?? null) ? $d->ids : [];
    $d->rw = is_array($d->rw ?? null) ? $d->rw : [];
    $d->fu = isset($d->fu) && is_object($d->fu) ? $d->fu : new stdClass();
    $d->bu = isset($d->bu) && is_object($d->bu) ? $d->bu : new stdClass();
    return $d;
}
function bn_promo(): array {
    $p = bn_get_doc('settings/promo') ?? new stdClass();
    $r = in_array($p->reward ?? '', ['choice', 'feature', 'bump'], true) ? $p->reward : 'choice';
    return ['on' => ($p->on ?? true) !== false, 'n' => max(2, min(20, (int) round((float) ($p->n ?? 5)) ?: 5)), 'reward' => $r];
}
function bn_rewards_status(string $uid): array {
    $e = bn_ent_get($uid); $p = bn_promo();
    $earned = intdiv(count($e->ids), $p['n']);
    return ['count' => count($e->ids), 'earned' => $earned, 'used' => count($e->rw), 'left' => max(0, $earned - count($e->rw)), 'n' => $p['n'], 'on' => $p['on'], 'reward' => $p['reward']];
}

/** userposts/{uid} written by its member: "featured" and "bumped" only with a paid perk or an earned reward. */
function bn_guard_userposts(object $new, ?object $old, string $uid): object {
    if (!property_exists($new, 'listings') || !is_array($new->listings)) return $new;
    $now = bn_now();
    $ent = bn_ent_get($uid); $promo = bn_promo();
    $ao = bn_get_doc('adorders/' . $uid) ?? new stdClass();
    // perks granted per listing: admin-confirmed upgrades, plus Power Seller featured picks
    $grant = ['feature' => [], 'bump' => []];
    foreach (bn_objs($ao->upgrades ?? []) as $u) if (($u->status ?? '') === 'Paid')
        foreach (bn_objs($u->items ?? []) as $i) { $k = $i->kind ?? ''; $l = (int) ($i->lid ?? 0); if (isset($grant[$k]) && $l) $grant[$k][$l] = ($grant[$k][$l] ?? 0) + 1; }
    foreach (bn_objs($ao->power->feat ?? []) as $f) { $l = (int) ($f->lid ?? 0); if ($l) $grant['feature'][$l] = ($grant['feature'][$l] ?? 0) + 1; }
    foreach (bn_objs($ao->power->bump ?? []) as $f) { $l = (int) ($f->lid ?? 0); if ($l) $grant['bump'][$l] = ($grant['bump'][$l] ?? 0) + 1; }
    $prev = [];
    foreach (bn_objs($old->listings ?? []) as $l) $prev[(int) ($l->id ?? 0)] = $l;
    $left = fn() => max(0, intdiv(count($ent->ids), $promo['n']) - count($ent->rw));
    $take = function (int $lid, string $kind) use (&$grant, $ent, $promo, $left, $now): bool {
        $used = (int) ($ent->{$kind === 'feature' ? 'fu' : 'bu'}->$lid ?? 0);
        if (($grant[$kind][$lid] ?? 0) > $used) { $ent->{$kind === 'feature' ? 'fu' : 'bu'}->$lid = $used + 1; return true; }
        if ($promo['on'] && in_array($promo['reward'], ['choice', $kind], true) && $left() > 0) { $ent->rw[] = (object) ['lid' => $lid, 'kind' => $kind, 'at' => $now]; return true; }
        return false;
    };
    foreach ($new->listings as $l) {
        if (!is_object($l)) continue;
        $lid = (int) ($l->id ?? 0);
        if ($lid <= 0) continue;
        if ($promo['on'] && !in_array($lid, $ent->ids, true) && count($ent->ids) < 5000) $ent->ids[] = $lid;
        $o = $prev[$lid] ?? null;
        // featured
        $curOn = $o && ($o->featured ?? false) === true;
        $curUntil = $curOn ? (isset($o->featUntil) ? (int) $o->featUntil : PHP_INT_MAX) : 0;
        if (($l->featured ?? false) === true) {
            $want = (int) ($l->featUntil ?? 0);
            $asks = $curUntil <= $now || ($curUntil !== PHP_INT_MAX && $want > $curUntil + BN_DAY_MS);
            if ($asks && $take($lid, 'feature')) $curUntil = max($curUntil, $now) + 7 * BN_DAY_MS;
            if ($curUntil > $now) { $l->featured = true; if ($curUntil === PHP_INT_MAX) unset($l->featUntil); else $l->featUntil = $curUntil; }
            else { $l->featured = false; unset($l->featUntil); }
        } elseif (isset($l->featUntil) && !$curOn) unset($l->featUntil);
        // bumped
        $ob = (int) ($o->bumps ?? 0);
        if ((int) ($l->bumps ?? 0) > $ob && $take($lid, 'bump')) { $l->bumps = $ob + 1; $l->bumped = $now; $l->posted = 0; }
        else {
            if ($o && isset($o->bumped)) $l->bumped = $o->bumped; else unset($l->bumped);
            if ($ob) $l->bumps = $ob; else unset($l->bumps);
        }
    }
    bn_secret_set('ent:' . $uid, json_encode($ent));
    return $new;
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

/* ---------- Sign in with Google (Google Identity Services ID tokens, checked against Google's public keys) ---------- */
function bn_google_client(): string { return bn_secret('google_on') === '1' ? bn_secret('google_client_id') : ''; }
function bn_b64url(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
/** DER-encodes an RSA public key (modulus n, exponent e) as a PEM that openssl understands. */
function bn_rsa_pem(string $n, string $e): string {
    $len = function (int $l): string { if ($l < 128) return chr($l); $b = ltrim(pack('N', $l), "\0"); return chr(0x80 | strlen($b)) . $b; };
    $int = function (string $x) use ($len): string { if (ord($x[0]) > 0x7f) $x = "\0" . $x; return "\x02" . $len(strlen($x)) . $x; };
    $rsa = $int($n) . $int($e); $rsa = "\x30" . $len(strlen($rsa)) . $rsa;
    $bits = "\x03" . $len(strlen($rsa) + 1) . "\0" . $rsa;
    $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = "\x30" . $len(strlen($alg . $bits)) . $alg . $bits;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/** Google's current signing keys, cached for 6 hours (refreshed early if Google rotated them). */
function bn_google_keys(bool $fresh = false): array {
    $c = json_decode(bn_secret('g_jwks'), true);
    if (!$fresh && is_array($c) && time() - (int) ($c['at'] ?? 0) < 21600) return $c['keys'] ?? [];
    if (getenv('BN_GOOGLE_JWKS')) $body = @file_get_contents(getenv('BN_GOOGLE_JWKS'));
    else {
        $ch = curl_init('https://www.googleapis.com/oauth2/v3/certs');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch); curl_close($ch);
    }
    $j = json_decode((string) $body, true);
    if (!is_array($j) || empty($j['keys'])) return is_array($c) ? ($c['keys'] ?? []) : [];
    bn_secret_set('g_jwks', json_encode(['at' => time(), 'keys' => $j['keys']]));
    return $j['keys'];
}
/** Checks a Google ID token. Returns its claims, or null if it isn't a genuine, current token for this site. */
function bn_google_verify(string $jwt, string $clientId): ?array {
    $p = explode('.', $jwt);
    if (count($p) !== 3 || $clientId === '') return null;
    $h = json_decode(bn_b64url($p[0]), true); $c = json_decode(bn_b64url($p[1]), true);
    if (!is_array($h) || !is_array($c) || ($h['alg'] ?? '') !== 'RS256') return null;
    $kid = (string) ($h['kid'] ?? '');
    $find = function (array $keys) use ($kid) { foreach ($keys as $k) if (($k['kid'] ?? '') === $kid && ($k['kty'] ?? '') === 'RSA') return $k; return null; };
    $k = $find(bn_google_keys());
    if (!$k && time() - (int) (json_decode(bn_secret('g_jwks'), true)['at'] ?? 0) > 300) $k = $find(bn_google_keys(true));
    if (!$k) return null;
    if (openssl_verify($p[0] . '.' . $p[1], bn_b64url($p[2]), bn_rsa_pem(bn_b64url($k['n']), bn_b64url($k['e'])), OPENSSL_ALGO_SHA256) !== 1) return null;
    $now = time();
    if (!in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
    if (($c['aud'] ?? '') !== $clientId || (int) ($c['exp'] ?? 0) < $now - 60 || (int) ($c['iat'] ?? 0) > $now + 300) return null;
    if (empty($c['sub']) || empty($c['email']) || ($c['email_verified'] ?? false) !== true && ($c['email_verified'] ?? '') !== 'true') return null;
    return $c;
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

/* ---------- live weather ticker: National Weather Service (public domain), cached for everyone ---------- */
const BN_WX_TOWNS = [
    ['Ogden', 41.2230, -111.9738], ['Layton', 41.0602, -111.9711], ['Logan', 41.7370, -111.8338], ['Brigham City', 41.5102, -112.0155],
    ['Huntsville', 41.2602, -111.7683], ['Morgan', 41.0361, -111.6769], ['Tremonton', 41.7116, -112.1655], ['Salt Lake City', 40.7608, -111.8910],
    ['Park City', 40.6461, -111.4980], ['Snowbasin', 41.2160, -111.8570],
];
// Box Elder, Cache, Davis, Morgan, Rich, Salt Lake, Summit, Tooele, Utah, Wasatch, Weber
const BN_WX_COUNTIES = ['049003', '049005', '049011', '049029', '049033', '049035', '049043', '049045', '049049', '049051', '049057'];

/** Fetches several URLs at once. Returns [url => decoded JSON or null]. */
function bn_http_json_many(array $urls): array {
    $fx = getenv('BN_WX_FIXTURE');
    if ($fx) { $all = json_decode((string) @file_get_contents($fx), true) ?: []; $o = []; foreach ($urls as $u) $o[$u] = $all[$u] ?? null; return $o; }
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $u) {
        $h = curl_init($u);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/geo+json', 'User-Agent: TheBigNickelClassifieds (service@thebignickel.biz)']]);
        curl_multi_add_handle($mh, $h); $hs[$u] = $h;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $o = [];
    foreach ($hs as $u => $h) {
        $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
        $o[$u] = $code === 200 ? json_decode((string) curl_multi_getcontent($h), true) : null;
        curl_multi_remove_handle($mh, $h); curl_close($h);
    }
    curl_multi_close($mh);
    return $o;
}

function bn_wx_ticker(): array {
    $cache = json_decode(bn_secret('wx_cache'), true) ?: [];
    $age = time() - (int) ($cache['at'] ?? 0);
    if ($cache && $age < 900) return $cache;
    // only one visitor refreshes at a time; the rest get the last copy
    if ($cache && time() - (int) bn_secret('wx_lock') < 60) return $cache;
    bn_secret_set('wx_lock', (string) time());
    $grid = json_decode(bn_secret('wx_grid'), true) ?: [];
    $need = [];
    foreach (BN_WX_TOWNS as [$n, $la, $lo]) if (empty($grid[$n])) $need[$n] = sprintf('https://api.weather.gov/points/%.4f,%.4f', $la, $lo);
    if ($need) {
        $r = bn_http_json_many(array_values($need));
        foreach ($need as $n => $u) { $h = $r[$u]['properties']['forecastHourly'] ?? ''; if (is_string($h) && str_starts_with($h, 'https://api.weather.gov/')) $grid[$n] = $h; }
        bn_secret_set('wx_grid', json_encode($grid));
    }
    $urls = array_values(array_filter(array_map(fn($t) => $grid[$t[0]] ?? null, BN_WX_TOWNS)));
    $alertsUrl = 'https://api.weather.gov/alerts/active?area=UT';
    $r = bn_http_json_many(array_merge($urls, [$alertsUrl]));
    $old = []; foreach ($cache['towns'] ?? [] as $t) $old[$t['n']] = $t;
    $towns = [];
    foreach (BN_WX_TOWNS as [$n]) {
        $p = $r[$grid[$n] ?? '']['properties']['periods'][0] ?? null;
        if (is_array($p) && isset($p['temperature'])) {
            $towns[] = ['n' => $n, 't' => (int) $p['temperature'], 'f' => mb_substr((string) ($p['shortForecast'] ?? ''), 0, 60),
                'w' => mb_substr(trim(($p['windDirection'] ?? '') . ' ' . ($p['windSpeed'] ?? '')), 0, 24), 'd' => !empty($p['isDaytime'])];
        } elseif (isset($old[$n])) $towns[] = $old[$n];
    }
    $alerts = [];
    if (is_array($r[$alertsUrl]['features'] ?? null)) {
        foreach ($r[$alertsUrl]['features'] as $f) {
            $a = $f['properties'] ?? [];
            if (($a['status'] ?? '') !== 'Actual' || array_intersect($a['geocode']['SAME'] ?? [], BN_WX_COUNTIES) === []) continue;
            $ends = strtotime((string) ($a['ends'] ?? $a['expires'] ?? '')) ?: 0;
            if ($ends && $ends < time()) continue;
            $alerts[] = ['e' => mb_substr((string) ($a['event'] ?? 'Alert'), 0, 60), 'a' => mb_substr((string) ($a['areaDesc'] ?? ''), 0, 120),
                'end' => $ends * 1000, 'sev' => in_array($a['severity'] ?? '', ['Extreme', 'Severe'], true) ? 'hi' : 'lo'];
        }
        usort($alerts, fn($x, $y) => strcmp($x['sev'], $y['sev']));
        $alerts = array_slice($alerts, 0, 6);
    } else $alerts = $cache['alerts'] ?? [];
    $out = ['at' => time(), 'towns' => $towns, 'alerts' => $alerts];
    if ($towns || $alerts) bn_secret_set('wx_cache', json_encode($out));
    else if ($cache) return $cache;
    return $out;
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

case 'rewards':
    $me = bn_me();
    if (!$me) bn_fail('unauthenticated', 'Please sign in first.', 401);
    bn_json(bn_rewards_status($me['id']));

case 'wx_ticker':
    try { bn_json(bn_wx_ticker()); } catch (Throwable $e) { error_log('[bn] wx: ' . $e->getMessage()); bn_json(['at' => 0, 'towns' => [], 'alerts' => []]); }

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
        'sms_signup' => bn_sms_ready(), 'google' => bn_google_client() ?: null, 'now' => bn_now()]);

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

case 'google_login':
    if ($method !== 'POST') bn_fail('method', 'Use POST.', 405);
    bn_throttle('glogin:' . bn_ip(), 20, 900);
    $cid = bn_google_client();
    if ($cid === '') bn_fail('off', 'Google sign-in isn\'t turned on.', 400);
    $c = bn_google_verify((string) (bn_body()['credential'] ?? ''), $cid);
    if (!$c) bn_fail('bad_google', 'Google sign-in didn\'t work. Please try again.', 401);
    $sub = substr((string) $c['sub'], 0, 64); $email = strtolower(trim((string) $c['email']));
    $db = bn_db(); $new = false;
    $st = $db->prepare('SELECT user_id FROM bn_google WHERE sub = ?'); $st->execute([$sub]);
    $uid = (string) ($st->fetchColumn() ?: '');
    if ($uid === '') {
        // same verified email as an existing account: link them, so both ways sign in to one account
        $st = $db->prepare('SELECT id FROM bn_users WHERE email = ?'); $st->execute([$email]);
        $uid = (string) ($st->fetchColumn() ?: '');
        if ($uid === '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) bn_fail('invalid', 'That Google account has no usable email address.');
            bn_throttle('reg:' . bn_ip(), 8, 3600);
            $name = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($c['name'] ?? ''))));
            if (mb_strlen($name) < 2) $name = ucfirst(explode('@', $email)[0]);
            $uid = bn_create_account(mb_substr($name, 0, 60), $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), null);
            $new = true;
        }
        $db->prepare('INSERT INTO bn_google (sub, user_id, created) VALUES (?, ?, ?)')->execute([$sub, $uid, bn_now()]);
    }
    if (in_array(bn_member_status($uid), ['Deactivated', 'Banned'], true)) bn_fail('blocked', 'This account is turned off. Contact the site team for help.', 403);
    bn_login_finish($uid, ['new' => $new]);

/* Google sign-in settings (admin only). The Client ID is public by design; it goes in the sign-in button. */
case 'google_settings':
    $me = bn_me();
    if (!$me || !$me['is_admin']) bn_fail('permission_denied', 'Admins only.', 403);
    if ($method === 'POST') {
        $b = bn_body();
        $id = trim((string) ($b['client_id'] ?? ''));
        if ($id !== '' && !preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id)) bn_fail('invalid', 'The Client ID ends in .apps.googleusercontent.com. Copy the whole thing from Google Cloud.');
        if ($id !== '') bn_secret_set('google_client_id', $id);
        $on = !empty($b['on']);
        if ($on && bn_secret('google_client_id') === '') bn_fail('invalid', 'Paste your Client ID before turning this on.');
        bn_secret_set('google_on', $on ? '1' : '0');
    }
    bn_json(['on' => bn_secret('google_on') === '1', 'client_id' => bn_secret('google_client_id'), 'origin' => 'https://' . ($_SERVER['HTTP_HOST'] ?? '')]);

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
        if (!$me['is_admin'] && str_starts_with($path, 'adorders/')) bn_fail('permission_denied', 'Ask the site admin to remove orders.', 403);
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
    if (!$me['is_admin']) {
        // members can't mark their own orders paid, or feature or bump listings they haven't paid for
        if (str_starts_with($path, 'adorders/')) $data = bn_guard_adorders($data, bn_get_doc($path));
        if (str_starts_with($path, 'userposts/')) $data = bn_guard_userposts($data, bn_get_doc($path), $me['id']);
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
