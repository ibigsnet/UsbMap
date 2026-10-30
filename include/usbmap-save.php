<?php
// USB Map: save or clear a port name (POST only).
// Unraid checks the csrf_token on every POST before this file runs.
require_once __DIR__ . '/usbmap-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo usbmap_json(['ok' => false, 'error' => 'POST only']);
    exit;
}

$res = usbmap_name_apply(
    new UsbMapSysfs(),
    (string)($_POST['action'] ?? ''),
    (string)($_POST['id'] ?? ''),
    $_POST['name'] ?? ''
);
if (!$res['ok']) {
    http_response_code(400);
}
echo usbmap_json($res);
