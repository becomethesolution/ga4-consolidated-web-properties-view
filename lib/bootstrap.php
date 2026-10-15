<?php
declare(strict_types=1);

require __DIR__ . '/GA.php';
require __DIR__ . '/Demo.php';
require __DIR__ . '/Reports.php';

$root = dirname(__DIR__);
$cfg = is_file("$root/config.php") ? require "$root/config.php" : require "$root/config.sample.php";

// --- IP allow-list (optional). CF-Connecting-IP is only trusted when the config says
// the site is reachable exclusively through Cloudflare; otherwise it could be forged.
function client_ip(array $cfg): string
{
    if (!empty($cfg['trust_cloudflare_ip_header']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}
if (!empty($cfg['allowed_ips']) && !in_array(client_ip($cfg), $cfg['allowed_ips'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

// --- session login
session_name('gadash');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
]);
session_start();

function logged_in(array $cfg): bool
{
    return empty($cfg['password_hash']) || !empty($_SESSION['ok']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
