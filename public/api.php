<?php
declare(strict_types=1);
require __DIR__ . '/../lib/app.php';

function out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function body(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST required'], 405);
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) out(['error' => 'JSON required'], 415);
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : [];
}

function require_auth(): void
{
    if (!is_authed()) out(['error' => 'Not logged in'], 401);
}

function require_sync_token(): void
{
    $given = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
    if ($given === '' || !hash_equals(sync_token(), $given)) out(['error' => 'Bad sync token'], 401);
}

/** Simple brute-force brake: 5 failures lock logins for 5 minutes. */
function check_throttle(): void
{
    [$fails, $at] = array_map('intval', explode(':', setting('login_fails') ?? '0:0'));
    if ($fails >= 5 && time() - $at < 300) out(['error' => 'Too many attempts. Try again in a few minutes.'], 429);
}

try {
    switch ($_GET['a'] ?? '') {
        case 'setup':
            $b = body();
            if (!needs_setup()) out(['error' => 'Already set up'], 403);
            if (strlen((string) ($b['password'] ?? '')) < 8) out(['error' => 'Use at least 8 characters'], 422);
            set_setting('password_hash', password_hash($b['password'], PASSWORD_DEFAULT));
            start_session();
            out(['ok' => true]);

        case 'login':
            $b = body();
            check_throttle();
            if (!password_verify((string) ($b['password'] ?? ''), setting('password_hash') ?? '')) {
                [$fails, $at] = array_map('intval', explode(':', setting('login_fails') ?? '0:0'));
                set_setting('login_fails', (time() - $at < 300 ? $fails + 1 : 1) . ':' . time());
                out(['error' => 'Wrong password'], 401);
            }
            set_setting('login_fails', '0:0');
            start_session();
            out(['ok' => true]);

        case 'logout':
            body();
            end_session();
            out(['ok' => true]);

        case 'list':
            require_auth();
            out(['entries' => list_entries()]);

        case 'save':
            $b = body();
            require_auth();
            save_entry(clean_entry($b));
            out(['ok' => true]);

        case 'delete':
            $b = body();
            require_auth();
            if (!valid_date((string) ($b['date'] ?? ''))) out(['error' => 'Invalid date'], 422);
            db()->prepare('UPDATE entries SET deleted = 1, updated_at = ? WHERE date = ?')->execute([time(), $b['date']]);
            out(['ok' => true]);

        case 'token':
            require_auth();
            out(['token' => sync_token()]);

        case 'export':
            require_auth();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="sleep-tight.csv"');
            $f = fopen('php://output', 'w');
            $cols = ['date', 'hours', 'breaks', 'quality', 'clothing', 'day', 'evening', 'specifics', 'note'];
            fputcsv($f, $cols, escape: '');
            foreach (list_entries() as $e) {
                fputcsv($f, array_map(fn($c) => is_array($e[$c]) ? implode('|', $e[$c]) : $e[$c], $cols), escape: '');
            }
            exit;

        // --- token-authenticated endpoints used by bin/sync and bin/migrate --remote ---
        case 'sync_export':
            require_sync_token();
            out(['entries' => raw_entries()]);

        case 'sync_import':
            $b = body();
            require_sync_token();
            out(['written' => upsert_rows(is_array($b['entries'] ?? null) ? $b['entries'] : [])]);

        case 'migrate':
            $b = body();
            require_sync_token();
            $applied = empty($b['dry']) ? migrate(db()) : [];
            out(['applied' => $applied] + migration_status(db()));

        default:
            out(['error' => 'Unknown action'], 404);
    }
} catch (InvalidArgumentException $e) {
    out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log((string) $e);
    out(['error' => 'Server error'], 500);
}
