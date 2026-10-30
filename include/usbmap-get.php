<?php
// USB Map: read-only data endpoint (GET).
//   a=map     full map as JSON
//   a=watch   topology hash and the newest device (identify mode)
//   a=export  fmt=text|json, as a download
require_once __DIR__ . '/usbmap-lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    echo "GET only\n";
    exit;
}

$src = new UsbMapSysfs();
$a = (string)($_GET['a'] ?? 'map');

if ($a === 'watch') {
    header('Content-Type: application/json; charset=utf-8');
    echo usbmap_json(usbmap_watch($src));
    exit;
}

if ($a === 'export') {
    $fmt = (string)($_GET['fmt'] ?? 'json') === 'text' ? 'text' : 'json';
    if ($fmt === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="usbmap.txt"');
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="usbmap.json"');
    }
    echo usbmap_export($src, $fmt);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo usbmap_json(usbmap_collect($src));
