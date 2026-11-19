<?php
declare(strict_types=1);

// CLI helpers for talking to the deployed app (used by bin/sync and bin/migrate --remote).

const SYNC_CONFIG = __DIR__ . '/../.sync.json';

function c(string $text, string $code): string
{
    return stream_isatty(STDOUT) ? "\033[{$code}m$text\033[0m" : $text;
}

function ask(string $prompt, string $default = ''): string
{
    echo $prompt . ($default !== '' ? c(" [$default]", '2') : '') . ': ';
    $in = trim((string) fgets(STDIN));
    return $in === '' ? $default : $in;
}

/** @return array{url: string, token: string} */
function remote_config(bool $reconfigure = false): array
{
    $cfg = is_file(SYNC_CONFIG) ? (json_decode(file_get_contents(SYNC_CONFIG), true) ?: []) : [];
    if ($reconfigure || empty($cfg['url']) || empty($cfg['token'])) {
        echo "Remote setup (saved to .sync.json, git-ignored). The token is under Stats → Sync token on the live site.\n";
        $cfg['url'] = rtrim(ask('Live site URL', $cfg['url'] ?? ''), '/');
        $cfg['token'] = ask('Sync token', $cfg['token'] ?? '');
        file_put_contents(SYNC_CONFIG, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        chmod(SYNC_CONFIG, 0600);
    }
    return $cfg;
}

function remote_call(array $cfg, string $action, ?array $body = null): array
{
    $ch = curl_init($cfg['url'] . '/api.php?a=' . $action);
    $headers = ['X-Sync-Token: ' . $cfg['token']];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    // Local Herd sites use a self-signed CA; only relax TLS for .test hosts.
    if (str_ends_with((string) parse_url($cfg['url'], PHP_URL_HOST), '.test')) {
        curl_setopt_array($ch, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    }
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Request failed: ' . curl_error($ch));
    $json = json_decode($raw, true);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status !== 200 || !is_array($json)) {
        throw new RuntimeException("Remote said $status: " . ($json['error'] ?? substr($raw, 0, 120)));
    }
    return $json;
}
