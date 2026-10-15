<?php
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (!logged_in($cfg)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Not signed in']));
}
session_write_close(); // let the browser's parallel API calls run at the same time

$allowedDays = [7, 14, 28, 90, 365];
$days = (int) ($_GET['days'] ?? 28);
if (!in_array($days, $allowedDays, true)) $days = 28;

try {
    $ga = new GA($cfg);
    $rep = new Reports($ga, $cfg);
    $props = $ga->properties();
    $meta = ['demo' => $ga->demo, 'generated' => date('c'), 'propertyCount' => count($props)];

    switch ($_GET['action'] ?? '') {
        case 'properties':
            $out = ['properties' => $props];
            break;
        case 'realtime':
            $out = $rep->realtime($props);
            break;
        case 'summary':
            $out = $rep->summary($props, $days);
            break;
        case 'property':
            $id = preg_replace('/\D/', '', (string) ($_GET['id'] ?? ''));
            $p = null;
            foreach ($props as $x) if ($x['id'] === $id) $p = $x;
            if (!$p) {
                http_response_code(404);
                exit(json_encode(['error' => 'Unknown property']));
            }
            $out = $rep->property($p, $days);
            break;
        default:
            http_response_code(400);
            exit(json_encode(['error' => 'Unknown action']));
    }
    echo json_encode(['meta' => $meta] + $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
