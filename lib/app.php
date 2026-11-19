<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';
const DB_FILE = DATA_DIR . '/sleep.sqlite';
const MIGRATIONS_DIR = __DIR__ . '/../migrations';
const COOKIE = 'sleeptight';

/** Quick-select options. Edit freely; stored values are the slugs (keys). */
const TAGS = [
    'clothing' => [
        'tshirt' => 'T-shirt', 'naked' => 'Naked',
    ],
    'day' => [
        'sports' => 'Sports', 'stress' => 'Stressful', 'outdoors' => 'Outdoors', 'nap' => 'Nap', 'caffeine' => 'Late caffeine',
    ],
    'evening' => [
        'alcohol' => 'Alcohol', 'ate_a_lot' => 'Ate a lot', 'screen' => 'Screen', 'reading' => 'Reading',
        'sports' => 'Sports', 'late' => 'Late to bed', 'spirituality' => 'Spirituality', 'spouse' => 'Spouse', 'friends' => 'Friends', 'sex' => 'Sex',
    ],
    'specifics' => [
        'woke_early' => 'Woke very early', 'awake_night' => 'Awake during the night', 'no_sleep' => "Couldn't get to sleep",
    ],
    // Only asked (and only stored) after a rough night, see concerns_apply().
    'concerns' => [
        'work' => 'Work', 'personal' => 'Personal', 'dont_know' => "Don't know", 'kids' => 'Kids',
        'marriage' => 'Marriage', 'world' => 'State of world', 'ai' => 'AI',
    ],
];

/** "Main concerns" is relevant after less than 5 hours of sleep or more than one break. Mirrored in app.js. */
function concerns_apply(float $hours, int $breaks): bool
{
    return $hours < 5 || $breaks > 1;
}

/** Chip icons: group => slug => icon file in public/assets/icons/ (Phosphor names; custom-* are hand-drawn). */
const ICONS = [
    'clothing' => ['tshirt' => 't-shirt', 'naked' => 'custom-penis'],
    'day' => ['sports' => 'bicycle', 'stress' => 'lightning', 'outdoors' => 'tree', 'nap' => 'bed', 'caffeine' => 'coffee'],
    'evening' => [
        'alcohol' => 'wine', 'ate_a_lot' => 'fork-knife', 'screen' => 'device-mobile', 'reading' => 'book-open',
        'sports' => 'bicycle', 'late' => 'moon-stars', 'spirituality' => 'hands-praying', 'spouse' => 'heart', 'friends' => 'users-three', 'sex' => 'custom-sex',
    ],
    'specifics' => ['woke_early' => 'sun-horizon', 'awake_night' => 'eye', 'no_sleep' => 'hourglass'],
    'concerns' => [
        'work' => 'briefcase', 'personal' => 'user', 'dont_know' => 'question', 'kids' => 'baby',
        'marriage' => 'heart-break', 'world' => 'globe-hemisphere-west', 'ai' => 'robot',
    ],
];

/** Inline SVG sprite with one <symbol id="i-name"> per icon file. */
function icon_sprite(): string
{
    $out = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
    foreach (glob(__DIR__ . '/../public/assets/icons/*.svg') ?: [] as $file) {
        if (preg_match('~<svg[^>]*>(.*)</svg>~s', file_get_contents($file), $m)) {
            $out .= '<symbol id="i-' . basename($file, '.svg') . '" viewBox="0 0 256 256">' . $m[1] . '</symbol>';
        }
    }
    return $out . '</svg>';
}

const ENTRY_COLS = ['date', 'hours', 'breaks', 'quality', 'clothing', 'day', 'evening', 'specifics', 'concerns', 'note', 'updated_at', 'deleted'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fresh = !file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 3000');
    // A brand-new install bootstraps itself; later migrations are run explicitly (bin/migrate).
    if ($fresh) migrate($pdo);
    return $pdo;
}

/** @return array{applied: string[], pending: string[]} */
function migration_status(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
    $applied = $pdo->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $all = array_map('basename', glob(MIGRATIONS_DIR . '/*.sql') ?: []);
    sort($all);
    return ['applied' => $applied, 'pending' => array_values(array_diff($all, $applied))];
}

/** Applies pending migrations in order, each in its own transaction. @return string[] names applied */
function migrate(PDO $pdo): array
{
    $done = [];
    foreach (migration_status($pdo)['pending'] as $name) {
        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents(MIGRATIONS_DIR . '/' . $name));
            $pdo->prepare('INSERT INTO schema_migrations VALUES (?, ?)')->execute([$name, time()]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
        }
        $done[] = $name;
    }
    return $done;
}

function setting(string $key): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function sync_token(): string
{
    $t = setting('sync_token');
    if (!$t) set_setting('sync_token', $t = bin2hex(random_bytes(24)));
    return $t;
}

function needs_setup(): bool
{
    return setting('password_hash') === null;
}

function is_authed(): bool
{
    $t = $_COOKIE[COOKIE] ?? '';
    if (!is_string($t) || $t === '') return false;
    $st = db()->prepare('SELECT 1 FROM sessions WHERE token_hash = ?');
    $st->execute([hash('sha256', $t)]);
    return (bool) $st->fetchColumn();
}

function start_session(): void
{
    $t = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO sessions VALUES (?, ?)')->execute([hash('sha256', $t), time()]);
    send_cookie($t, time() + 400 * 86400);
}

/** Re-issues the cookie on each visit so the login keeps rolling forward (and old Strict cookies become Lax). */
function refresh_session(): void
{
    send_cookie((string) $_COOKIE[COOKIE], time() + 400 * 86400);
}

function end_session(): void
{
    if (!empty($_COOKIE[COOKIE])) {
        db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', (string) $_COOKIE[COOKIE])]);
    }
    send_cookie('', 1);
}

function send_cookie(string $value, int $expires): void
{
    // Lax, not Strict: the home-screen app is launched from outside the browser, and a Strict cookie
    // may be withheld on that first navigation, which would show the login again.
    setcookie(COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

/** Validates/normalises user input into a storable row. Throws InvalidArgumentException. */
function clean_entry(array $in): array
{
    $date = (string) ($in['date'] ?? '');
    if (!valid_date($date)) throw new InvalidArgumentException('Invalid date');
    $row = [
        'date' => $date,
        'hours' => round(max(0, min(24, (float) ($in['hours'] ?? 0))) * 4) / 4,
        'breaks' => max(0, min(30, (int) ($in['breaks'] ?? 0))),
        'quality' => max(1, min(100, (int) ($in['quality'] ?? 50))),
        'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 2000),
    ];
    foreach (TAGS as $group => $options) {
        $picked = is_array($in[$group] ?? null) ? $in[$group] : [];
        $row[$group] = json_encode(array_values(array_intersect(array_keys($options), $picked)));
    }
    if (!concerns_apply($row['hours'], $row['breaks'])) $row['concerns'] = '[]';
    return $row;
}

function valid_date(string $d): bool
{
    return (bool) preg_match('/^(\d{4})-(\d\d)-(\d\d)$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function save_entry(array $row): void
{
    $row += ['updated_at' => time(), 'deleted' => 0];
    upsert_rows([$row], true);
}

/** Entries for the app (tags decoded, tombstones hidden). */
function list_entries(): array
{
    $rows = db()->query('SELECT * FROM entries WHERE deleted = 0 ORDER BY date')->fetchAll();
    return array_map('decode_entry', $rows);
}

function decode_entry(array $r): array
{
    foreach (array_keys(TAGS) as $g) $r[$g] = json_decode($r[$g], true) ?: [];
    $r['hours'] = (float) $r['hours'];
    foreach (['breaks', 'quality', 'updated_at', 'deleted'] as $k) $r[$k] = (int) $r[$k];
    return $r;
}

/** Raw rows incl. tombstones, for sync. */
function raw_entries(?PDO $pdo = null): array
{
    return ($pdo ?? db())->query('SELECT * FROM entries ORDER BY date')->fetchAll();
}

/**
 * Inserts/updates raw rows. Unless $force, a row only wins when it is newer than what is stored.
 * @return int number of rows written
 */
function upsert_rows(array $rows, bool $force = false, ?PDO $pdo = null): int
{
    $pdo ??= db();
    $cols = ENTRY_COLS;
    $set = implode(', ', array_map(fn($c) => "$c = excluded.$c", array_slice($cols, 1)));
    $sql = 'INSERT INTO entries (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ")
            ON CONFLICT(date) DO UPDATE SET $set" . ($force ? '' : ' WHERE excluded.updated_at > entries.updated_at');
    $st = $pdo->prepare($sql);
    $n = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        if (!is_array($r) || !valid_date((string) ($r['date'] ?? ''))) continue;
        $params = [];
        // rows from an install that has not run 002 yet carry no concerns
        foreach ($cols as $c) $params[":$c"] = $r[$c] ?? ($c === 'concerns' ? '[]' : null);
        $st->execute($params);
        $n += $st->rowCount();
    }
    $pdo->commit();
    return $n;
}
