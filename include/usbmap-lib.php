<?php
// USB Map: read-only map of USB controllers, IOMMU groups, ports, hubs and devices.
// Copyright (c) 2026 ibigs, LLC. Author: RifleJock. GPL-3.0-or-later.
//
// Nothing here changes the system. The only file written is ports.json (port names)
// on the flash drive, and only when a name changes.

const USBMAP_NAME_MAX = 64;
const USBMAP_MAX_DEPTH = 8;

function usbmap_cfg_dir(): string
{
    $d = getenv('USBMAP_CFGDIR');
    return ($d !== false && $d !== '') ? rtrim($d, '/') : '/boot/config/plugins/usbmap';
}

function usbmap_ports_file(): string
{
    return usbmap_cfg_dir() . '/ports.json';
}

/**
 * Everything the collector reads goes through this class. USBMAP_SYSROOT (or the
 * constructor argument) prefixes every path, so a copy of sysfs can be read instead
 * of the live one.
 */
class UsbMapSysfs
{
    protected string $root;

    public function __construct(?string $root = null)
    {
        if ($root === null) {
            $env = getenv('USBMAP_SYSROOT');
            $root = $env === false ? '' : $env;
        }
        $this->root = rtrim($root, '/');
    }

    public function rooted(): bool
    {
        return $this->root !== '';
    }

    protected function path(string $p): string
    {
        return $this->root . $p;
    }

    protected function strip(string $real): string
    {
        if ($this->root !== '' && strncmp($real, $this->root . '/', strlen($this->root) + 1) === 0) {
            $real = substr($real, strlen($this->root));
        }
        return $real;
    }

    public function read(string $p): ?string
    {
        $f = $this->path($p);
        if (!@is_file($f)) {
            return null;
        }
        $v = @file_get_contents($f, false, null, 0, 65536);
        return $v === false ? null : trim($v);
    }

    public function real(string $p): ?string
    {
        $r = @realpath($this->path($p));
        return $r === false ? null : $this->strip($r);
    }

    public function ls(string $dir): array
    {
        $l = @scandir($this->path($dir));
        if ($l === false) {
            return [];
        }
        return array_values(array_filter($l, static fn($n) => $n !== '.' && $n !== '..'));
    }

    public function link_name(string $p): ?string
    {
        $l = @readlink($this->path($p));
        return $l === false ? null : basename($l);
    }

    public function usb_list(): array
    {
        return $this->ls('/sys/bus/usb/devices');
    }

    public function usb_attr(string $dev, string $attr): ?string
    {
        return $this->read("/sys/bus/usb/devices/$dev/$attr");
    }

    public function usb_real(string $dev): ?string
    {
        return $this->real("/sys/bus/usb/devices/$dev");
    }

    public function usb_ports(string $iface): array
    {
        $out = [];
        foreach ($this->ls("/sys/bus/usb/devices/$iface") as $n) {
            if (preg_match('/-port\d+$/', $n)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    public function usb_ifaces(string $dev): array
    {
        $out = [];
        $q = preg_quote($dev, '/');
        foreach ($this->ls("/sys/bus/usb/devices/$dev") as $n) {
            if (preg_match('/^' . $q . ':\d+\.\d+$/', $n)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    public function usb_iface_driver(string $iface): ?string
    {
        return $this->link_name("/sys/bus/usb/devices/$iface/driver");
    }

    public function port_attr(string $iface, string $port, string $attr): ?string
    {
        return $this->read("/sys/bus/usb/devices/$iface/$port/$attr");
    }

    public function port_peer(string $iface, string $port): ?string
    {
        return $this->real("/sys/bus/usb/devices/$iface/$port/peer");
    }

    public function port_phys(string $iface, string $port): array
    {
        $base = "/sys/bus/usb/devices/$iface/$port/firmware_node/physical_location";
        $out = [];
        foreach ($this->ls($base) as $k) {
            if (!preg_match('/^[a-z_]{1,32}$/', $k)) {
                continue;
            }
            $v = $this->read("$base/$k");
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function iommu_groups(): array
    {
        $out = [];
        foreach ($this->ls('/sys/kernel/iommu_groups') as $g) {
            if (ctype_digit($g)) {
                $out[(int)$g] = $this->ls("/sys/kernel/iommu_groups/$g/devices");
            }
        }
        ksort($out);
        return $out;
    }

    public function pci_list(): array
    {
        return $this->ls('/sys/bus/pci/devices');
    }

    public function pci_attr(string $bdf, string $attr): ?string
    {
        return $this->read("/sys/bus/pci/devices/$bdf/$attr");
    }

    public function pci_driver(string $bdf): ?string
    {
        return $this->link_name("/sys/bus/pci/devices/$bdf/driver");
    }

    public function pci_real(string $bdf): ?string
    {
        return $this->real("/sys/bus/pci/devices/$bdf");
    }

    public function lspci(): ?string
    {
        if ($this->root !== '') {
            return $this->read('/lspci.txt');
        }
        $out = [];
        $rc = 1;
        @exec('lspci -nnDmm 2>/dev/null', $out, $rc);
        return $rc === 0 ? implode("\n", $out) : null;
    }

    public function vfio_cfg(): ?string
    {
        return $this->read('/boot/config/vfio-pci.cfg');
    }

    public function tb_list(): array
    {
        return $this->ls('/sys/bus/thunderbolt/devices');
    }

    public function tb_real(string $name): ?string
    {
        return $this->real("/sys/bus/thunderbolt/devices/$name");
    }

    public function tb_attr(string $name, string $attr): ?string
    {
        return $this->read("/sys/bus/thunderbolt/devices/$name/$attr");
    }

    public function mounts(): ?string
    {
        return $this->read('/proc/self/mounts');
    }

    public function dev_real(string $path): ?string
    {
        return $this->real($path);
    }

    public function block_real(string $name): ?string
    {
        return $this->real("/sys/class/block/$name");
    }

    public function block_slaves(string $name): array
    {
        return $this->ls("/sys/class/block/$name/slaves");
    }

    public function zpool_devices(string $pool): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,254}$/', $pool)) {
            return [];
        }
        if ($this->root !== '') {
            $txt = (string)$this->read('/zpool-' . $pool . '.txt');
        } else {
            $out = [];
            $rc = 1;
            @exec('zpool list -vHPL ' . escapeshellarg($pool) . ' 2>/dev/null', $out, $rc);
            $txt = $rc === 0 ? implode("\n", $out) : '';
        }
        $devs = [];
        foreach (preg_split('/\R/', $txt) as $line) {
            foreach (preg_split('/\s+/', trim($line)) as $f) {
                if (strncmp($f, '/dev/', 5) === 0) {
                    $devs[] = $f;
                }
            }
        }
        return array_values(array_unique($devs));
    }

    public function flash_guid(): ?string
    {
        $ini = $this->read('/var/local/emhttp/var.ini');
        if ($ini !== null && preg_match('/^flashGUID="([^"]*)"/m', $ini, $m)) {
            return $m[1];
        }
        return null;
    }

    public function board(): array
    {
        return [
            'vendor' => (string)$this->read('/sys/class/dmi/id/board_vendor'),
            'name' => (string)$this->read('/sys/class/dmi/id/board_name'),
        ];
    }

    public function plugin_version(): string
    {
        $plg = $this->read('/var/log/plugins/usbmap.plg');
        if ($plg !== null && preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', $plg, $m)) {
            return $m[1];
        }
        return '';
    }
}

// ---------------------------------------------------------------- helpers

function usbmap_is_bdf(string $s): bool
{
    return (bool)preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/i', $s);
}

function usbmap_is_usbdev(string $s): bool
{
    return (bool)preg_match('/^(usb\d+|\d+-\d+(\.\d+)*)$/', $s);
}

function usbmap_hex4(?string $v): string
{
    $v = strtolower(trim((string)$v));
    if (strncmp($v, '0x', 2) === 0) {
        $v = substr($v, 2);
    }
    return preg_match('/^[0-9a-f]{4}$/', $v) ? $v : '';
}

/** Strip control and direction characters from text read from devices. */
function usbmap_text_clean(?string $s): string
{
    $s = (string)$s;
    if (!preg_match('//u', $s)) {
        $s = (string)preg_replace('/[\x80-\xff]/', '', $s);
    }
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    $s = (string)preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s);
    return trim($s);
}

function usbmap_unoct(string $s): string
{
    return (string)preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $s);
}

function usbmap_speed_num(?string $v): float
{
    $v = trim((string)$v);
    return is_numeric($v) ? (float)$v : 0.0;
}

/** 480 -> 480M, 5000 -> 5G, 20000 -> 20G x2 (sysfs reports Gen 2x2 as 20000). */
function usbmap_speed_label(float $mbps, int $lanes = 1): string
{
    if ($mbps <= 0) {
        return '?';
    }
    if ($mbps < 1000) {
        return ($mbps == 1.5 ? '1.5' : (string)(int)$mbps) . 'M';
    }
    $g = $mbps / 1000;
    $txt = (floor($g) == $g ? (string)(int)$g : (string)$g) . 'G';
    if ($mbps >= 20000 || $lanes > 1) {
        $txt .= ' x2';
    }
    return $txt;
}

function usbmap_speed_gen(float $mbps, int $lanes = 1): string
{
    if ($mbps <= 0) {
        return '';
    }
    if ($mbps <= 12) {
        return 'USB 1.1';
    }
    if ($mbps <= 480) {
        return 'USB 2.0';
    }
    if ($mbps <= 5000) {
        return 'USB 3.2 Gen 1';
    }
    if ($mbps <= 10000) {
        return $lanes > 1 ? 'USB 3.2 Gen 1x2' : 'USB 3.2 Gen 2';
    }
    return 'USB 3.2 Gen 2x2';
}

/** Highest speed a device can ask for, from its bcdUSB version. */
function usbmap_cap(string $ver): float
{
    $v = (float)$ver;
    if ($v >= 3.2) {
        return 20000;
    }
    if ($v >= 3.1) {
        return 10000;
    }
    if ($v >= 3.0) {
        return 5000;
    }
    return 480;
}

/** [flag, text]. 'slow' = USB 3 device on a USB 2 link; 'below' = may run faster. */
function usbmap_flag(string $version, float $speed, float $max): array
{
    $v = (float)$version;
    if ($v >= 3.0 && $speed > 0 && $speed <= 480 && $max >= 5000) {
        return ['slow', 'USB 3 device running at USB 2 speed'];
    }
    if ($v >= 3.1 && $speed >= 5000 && $speed < min($max, usbmap_cap($version))) {
        return ['below', 'Slower than this port allows'];
    }
    return ['', ''];
}

// ---------------------------------------------------------------- PCI

function usbmap_split_id(string $s): array
{
    if (preg_match('/^(.*?)\s*\[([0-9a-fA-F]{4})\]$/', $s, $m)) {
        return [$m[1], strtolower($m[2])];
    }
    return [$s, ''];
}

/** Parses `lspci -nnDmm` (and plain `lspci -nnD`) into bdf => fields. */
function usbmap_lspci_parse(?string $txt): array
{
    $out = [];
    foreach (preg_split('/\R/', (string)$txt) as $line) {
        $line = trim($line);
        if (!preg_match('/^((?:[0-9a-f]{4}:)?[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])\s+(.*)$/i', $line, $m)) {
            continue;
        }
        $bdf = strtolower(strlen($m[1]) === 7 ? '0000:' . $m[1] : $m[1]);
        $rest = $m[2];
        $row = ['class' => '', 'class_name' => '', 'vendor' => '', 'vid' => '', 'device' => '', 'did' => '', 'progif' => ''];
        if ($rest !== '' && $rest[0] === '"') {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $rest, $q);
            $f = $q[1];
            [$row['class_name'], $row['class']] = usbmap_split_id($f[0] ?? '');
            [$row['vendor'], $row['vid']] = usbmap_split_id($f[1] ?? '');
            [$row['device'], $row['did']] = usbmap_split_id($f[2] ?? '');
            if (preg_match('/\s-p([0-9a-f]{2})\b/i', $rest, $p)) {
                $row['progif'] = strtolower($p[1]);
            }
        } elseif (preg_match('/^(.*?)\s*\[([0-9a-f]{4})\]:\s*(.*?)\s*\[([0-9a-f]{4}):([0-9a-f]{4})\]/i', $rest, $p)) {
            $row['class_name'] = $p[1];
            $row['class'] = strtolower($p[2]);
            $row['device'] = $p[3];
            $row['vid'] = strtolower($p[4]);
            $row['did'] = strtolower($p[5]);
            if (preg_match('/\(prog-if ([0-9a-f]{2})/i', $rest, $pp)) {
                $row['progif'] = strtolower($pp[1]);
            }
        }
        $out[$bdf] = $row;
    }
    return $out;
}

function usbmap_vendor_short(string $v): string
{
    $v = trim($v);
    if (preg_match('/\[([^\]]+)\]\s*$/', $v, $m)) {
        return $m[1];
    }
    $w = preg_split('/[\s,]+/', $v);
    return (string)($w[0] ?? '');
}

function usbmap_pci_info(UsbMapSysfs $s, string $bdf, array $lspci, array &$cache): array
{
    if (isset($cache[$bdf])) {
        return $cache[$bdf];
    }
    $l = $lspci[$bdf] ?? null;
    $vid = usbmap_hex4($s->pci_attr($bdf, 'vendor'));
    $did = usbmap_hex4($s->pci_attr($bdf, 'device'));
    $class = '';
    $progif = '';
    $cls = (string)$s->pci_attr($bdf, 'class');
    if (preg_match('/^0x([0-9a-f]{4})([0-9a-f]{2})$/i', $cls, $m)) {
        $class = strtolower($m[1]);
        $progif = strtolower($m[2]);
    } elseif ($l) {
        $class = $l['class'];
        $progif = $l['progif'];
    }
    if ($vid === '' && $l) {
        $vid = $l['vid'];
    }
    if ($did === '' && $l) {
        $did = $l['did'];
    }
    $name = '';
    if ($l) {
        $name = trim(($l['vendor'] !== '' ? usbmap_vendor_short($l['vendor']) . ' ' : '') . $l['device']);
    }
    return $cache[$bdf] = [
        'bdf' => $bdf,
        'vid' => $vid,
        'did' => $did,
        'class' => $class,
        'progif' => $progif,
        'class_name' => usbmap_text_clean($l['class_name'] ?? ''),
        'name' => usbmap_text_clean($name),
        'driver' => (string)$s->pci_driver($bdf),
    ];
}

function usbmap_kind(string $class, string $progif): string
{
    if ($class !== '0c03') {
        return 'other';
    }
    switch ($progif) {
        case '00':
            return 'UHCI';
        case '10':
            return 'OHCI';
        case '20':
            return 'EHCI';
        case '30':
            return 'xHCI';
        case '40':
            return 'USB4 NHI';
    }
    return 'USB';
}

function usbmap_vfio_cfg_parse(?string $txt): array
{
    $out = [];
    if ($txt !== null && preg_match('/^BIND=(.*)$/m', $txt, $m)) {
        foreach (preg_split('/\s+/', trim($m[1], " \t\"'")) as $tok) {
            $bdf = strtolower(explode('|', $tok)[0]);
            if (strlen($bdf) === 7) {
                $bdf = '0000:' . $bdf;
            }
            if (usbmap_is_bdf($bdf)) {
                $out[$bdf] = true;
            }
        }
    }
    return $out;
}

/** BDF components of a sysfs path, outermost first. */
function usbmap_path_bdfs(string $path): array
{
    $out = [];
    foreach (explode('/', $path) as $c) {
        if (usbmap_is_bdf($c)) {
            $out[] = strtolower($c);
        }
    }
    return $out;
}

// ---------------------------------------------------------------- USB devices

function usbmap_usb_info(UsbMapSysfs $s, string $n): array
{
    $a = static fn($k) => $s->usb_attr($n, $k);
    $lanes = max((int)$a('rx_lanes'), (int)$a('tx_lanes'), 1);
    $drivers = [];
    if (strncmp($n, 'usb', 3) !== 0) {
        foreach ($s->usb_ifaces($n) as $if) {
            $d = $s->usb_iface_driver($if);
            if ($d !== null && $d !== '' && !in_array($d, $drivers, true)) {
                $drivers[] = $d;
            }
        }
    }
    $class = strtolower((string)$a('bDeviceClass'));
    $maxchild = (int)$a('maxchild');
    return [
        'dev' => $n,
        'bus' => (int)$a('busnum'),
        'devnum' => (int)$a('devnum'),
        'devpath' => (string)$a('devpath'),
        'speed' => usbmap_speed_num($a('speed')),
        'lanes' => $lanes,
        'version' => trim((string)$a('version')),
        'maxchild' => $maxchild,
        'vid' => usbmap_hex4($a('idVendor')),
        'pid' => usbmap_hex4($a('idProduct')),
        'manufacturer' => usbmap_text_clean($a('manufacturer')),
        'product' => usbmap_text_clean($a('product')),
        'class' => $class,
        'removable' => (string)$a('removable'),
        'hub' => $class === '09' || $maxchild > 0,
        'drivers' => $drivers,
    ];
}

/** Hub interface that holds the port directories. */
function usbmap_hub_iface(array $d): string
{
    if (strncmp($d['dev'], 'usb', 3) === 0) {
        return $d['bus'] . '-0:1.0';
    }
    return $d['dev'] . ':1.0';
}

/** Device name for port N of hub $d. */
function usbmap_child_name(array $d, int $num): string
{
    if (strncmp($d['dev'], 'usb', 3) === 0) {
        return $d['bus'] . '-' . $num;
    }
    return $d['dev'] . '.' . $num;
}

/** "3-0:1.0/usb3-port2" from a real path. */
function usbmap_port_key(string $real): string
{
    return basename(dirname($real)) . '/' . basename($real);
}

/** Device that owns a hub interface such as 6-5:1.0 or 6-0:1.0. */
function usbmap_iface_dev(string $iface): string
{
    $dev = preg_replace('/:\d+\.\d+$/', '', $iface);
    if (preg_match('/^(\d+)-0$/', (string)$dev, $m)) {
        return 'usb' . $m[1];
    }
    return (string)$dev;
}

/**
 * Builds the physical port slots below a set of hubs. $hubs are halves of the same
 * physical hub (or the root hubs of one controller): USB 2 and USB 3 ports joined by
 * their `peer` links make one slot.
 */
function usbmap_build_slots(UsbMapSysfs $s, array &$ctx, string $ckey, array $hubs, int $depth): array
{
    if ($depth > USBMAP_MAX_DEPTH) {
        return [];
    }
    $members = [];
    foreach ($hubs as $h) {
        $iface = usbmap_hub_iface($h);
        $root = strncmp($h['dev'], 'usb', 3) === 0;
        $role = $ctx['roles'][$h['bus']] ?? ($h['speed'] > 480 ? 'ss' : 'hs');
        foreach ($s->usb_ports($iface) as $p) {
            if (!preg_match('/-port(\d+)$/', $p, $m)) {
                continue;
            }
            $num = (int)$m[1];
            $path = $root ? (string)$num : $h['devpath'] . '.' . $num;
            $peer = $s->port_peer($iface, $p);
            $child = usbmap_child_name($h, $num);
            $key = "$iface/$p";
            $members[$key] = [
                'key' => $key,
                'iface' => $iface,
                'port' => $p,
                'bus' => $h['bus'],
                'role' => $role,
                'num' => $num,
                'path' => $path,
                'token' => $role . '-' . $path,
                'connect_type' => (string)$s->port_attr($iface, $p, 'connect_type'),
                'location' => (string)$s->port_attr($iface, $p, 'location'),
                'phys' => $s->port_phys($iface, $p),
                'peer' => $peer !== null ? usbmap_port_key($peer) : '',
                'max' => $h['speed'],
                'lanes' => $h['lanes'],
                'device' => isset($ctx['usb'][$child]) ? $child : '',
            ];
        }
    }

    // Join peers, and hub halves that sit on separate ports (an onboard hub wired to
    // an unpaired USB 2 port and an unpaired USB 3 port is still one hub).
    $parent = [];
    foreach ($members as $k => $_) {
        $parent[$k] = $k;
    }
    $find = static function ($k) use (&$parent) {
        while ($parent[$k] !== $k) {
            $parent[$k] = $parent[$parent[$k]];
            $k = $parent[$k];
        }
        return $k;
    };
    $union = static function ($a, $b) use (&$parent, $find) {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra !== $rb) {
            $parent[$rb] = $ra;
        }
    };
    $hub_at = [];
    foreach ($members as $k => $m) {
        if ($m['peer'] !== '' && isset($members[$m['peer']])) {
            $union($k, $m['peer']);
        }
        if ($m['device'] !== '' && $ctx['usb'][$m['device']]['hub']) {
            $hub_at[$m['device']] = $k;
        }
    }
    foreach ($hub_at as $dev => $k) {
        $other = usbmap_hub_peer_dev($s, $ctx['usb'][$dev]);
        if ($other !== '' && isset($hub_at[$other])) {
            $union($k, $hub_at[$other]);
        }
    }

    $groups = [];
    foreach ($members as $k => $m) {
        $groups[$find($k)][] = $m;
    }

    $slots = [];
    foreach ($groups as $list) {
        usort($list, static fn($a, $b) => [$a['role'] === 'hs' ? 0 : 1, $a['num']] <=> [$b['role'] === 'hs' ? 0 : 1, $b['num']]);
        $tokens = [];
        $max = 0.0;
        $lanes = 1;
        $ct = '';
        $phys = [];
        $devs = [];
        foreach ($list as $m) {
            $tokens[] = $m['token'];
            if ($m['max'] > $max) {
                $max = $m['max'];
                $lanes = $m['lanes'];
            }
            if ($ct === '' || $ct === 'unknown' || ($ct === 'not used' && $m['connect_type'] !== '' && $m['connect_type'] !== 'unknown')) {
                $ct = $m['connect_type'];
            }
            if (!$phys && $m['phys']) {
                $phys = $m['phys'];
            }
            if ($m['device'] !== '') {
                $devs[] = $m['device'];
            }
        }
        $id = $ckey . '|' . implode('+', $tokens);
        $slot = [
            'id' => $id,
            'name' => $ctx['names'][$id] ?? '',
            'members' => array_map(static fn($m) => [
                'port' => $m['port'],
                'iface' => $m['iface'],
                'bus' => $m['bus'],
                'role' => $m['role'],
                'num' => $m['num'],
                'token' => $m['token'],
                'connect_type' => $m['connect_type'],
                'location' => $m['location'],
                'max' => $m['max'],
            ], $list),
            'max' => $max,
            'max_label' => usbmap_speed_label($max, $lanes),
            'max_scope' => $depth === 0 ? 'bus' : 'hub',
            'connect_type' => $ct,
            'phys' => $phys,
            'sort' => [$list[0]['role'] === 'hs' ? 0 : 1, $list[0]['num']],
            'children' => [],
        ];
        $ctx['ids'][$id] = true;

        $hubs_here = [];
        foreach ($devs as $dev) {
            $d = $ctx['usb'][$dev];
            $ctx['dev_slot'][$dev] = $id;
            if ($d['hub']) {
                $hubs_here[] = $d;
                continue;
            }
            $slot['children'][] = usbmap_device_node($d, $max);
        }
        if ($hubs_here) {
            usort($hubs_here, static fn($a, $b) => $a['speed'] <=> $b['speed']);
            $slot['children'][] = usbmap_hub_node($s, $ctx, $ckey, $hubs_here, $max, $depth + 1);
        }
        $slots[] = $slot;
    }
    usort($slots, static fn($a, $b) => $a['sort'] <=> $b['sort']);
    foreach ($slots as &$sl) {
        unset($sl['sort']);
    }
    unset($sl);
    return $slots;
}

/** The other half of a USB 3 hub (USB 2 half <-> SuperSpeed half), via its port peers. */
function usbmap_hub_peer_dev(UsbMapSysfs $s, array $d): string
{
    $iface = usbmap_hub_iface($d);
    foreach ($s->usb_ports($iface) as $p) {
        $peer = $s->port_peer($iface, $p);
        if ($peer !== null) {
            $dev = usbmap_iface_dev(basename(dirname($peer)));
            if ($dev !== $d['dev']) {
                return $dev;
            }
        }
    }
    return '';
}

function usbmap_device_fields(array $d): array
{
    return [
        'dev' => $d['dev'],
        'bus' => $d['bus'],
        'devnum' => $d['devnum'],
        'vid' => $d['vid'],
        'pid' => $d['pid'],
        'manufacturer' => $d['manufacturer'],
        'product' => $d['product'],
        'class' => $d['class'],
        'version' => $d['version'],
        'speed' => $d['speed'],
        'speed_label' => usbmap_speed_label($d['speed'], $d['lanes']),
        'removable' => $d['removable'],
        'drivers' => $d['drivers'],
    ];
}

function usbmap_device_node(array $d, float $max): array
{
    [$flag, $text] = usbmap_flag($d['version'], $d['speed'], $max);
    return ['type' => 'device'] + usbmap_device_fields($d) + ['flag' => $flag, 'flag_text' => $text];
}

function usbmap_hub_node(UsbMapSysfs $s, array &$ctx, string $ckey, array $halves, float $max, int $depth): array
{
    $top = $halves[count($halves) - 1];
    $ver = '0';
    foreach ($halves as $h) {
        if ((float)$h['version'] > (float)$ver) {
            $ver = $h['version'];
        }
    }
    [$flag, $text] = usbmap_flag($ver, $top['speed'], $max);
    $node = ['type' => 'hub'] + usbmap_device_fields($top) + [
        'version' => $ver,
        'ports' => max(array_map(static fn($h) => $h['maxchild'], $halves)),
        'halves' => array_map(static fn($h) => [
            'dev' => $h['dev'],
            'bus' => $h['bus'],
            'speed' => $h['speed'],
            'speed_label' => usbmap_speed_label($h['speed'], $h['lanes']),
            'vid' => $h['vid'],
            'pid' => $h['pid'],
            'product' => $h['product'],
        ], $halves),
        'flag' => $flag,
        'flag_text' => $text,
    ];
    $node['slots'] = usbmap_build_slots($s, $ctx, $ckey, $halves, $depth);
    return $node;
}

// ---------------------------------------------------------------- boot device

/** Finds the device behind /boot and the controller that holds it. */
function usbmap_boot(UsbMapSysfs $s, array $usb): array
{
    $r = ['source' => '', 'fstype' => '', 'method' => '', 'blocks' => [], 'usb' => [], 'controllers' => [], 'pci' => []];
    foreach (preg_split('/\R/', (string)$s->mounts()) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 3 && usbmap_unoct($f[1]) === '/boot') {
            $r['source'] = usbmap_unoct($f[0]);
            $r['fstype'] = $f[2];
        }
    }
    $blocks = [];
    if ($r['fstype'] === 'zfs') {
        $blocks = $s->zpool_devices(explode('/', $r['source'])[0]);
    } elseif (strncmp($r['source'], '/dev/', 5) === 0) {
        $blocks[] = $r['source'];
    }
    if ($blocks) {
        $r['method'] = 'mount';
        usbmap_boot_walk($s, $blocks, $r);
    }
    if (!$r['usb'] && !$r['pci']) {
        $lab = $s->dev_real('/dev/disk/by-label/UNRAID');
        if ($lab !== null) {
            $r['method'] = 'label';
            usbmap_boot_walk($s, [$lab], $r);
        }
    }
    if (!$r['usb'] && !$r['pci']) {
        $g = strtoupper((string)preg_replace('/[^0-9A-Fa-f]/', '', (string)$s->flash_guid()));
        if (strlen($g) > 8) {
            foreach ($usb as $dev => $d) {
                if (strncmp($dev, 'usb', 3) === 0) {
                    continue;
                }
                $ser = strtoupper((string)preg_replace('/[^0-9A-Fa-f]/', '', (string)$s->usb_attr($dev, 'serial')));
                if (strtoupper($d['vid'] . $d['pid']) === substr($g, 0, 8) && $ser !== '' && str_ends_with($ser, substr($g, 8))) {
                    $r['method'] = 'guid';
                    usbmap_boot_path($s, (string)$s->usb_real($dev), $r);
                }
            }
        }
    }
    foreach (['blocks', 'usb', 'controllers', 'pci'] as $k) {
        $r[$k] = array_values(array_unique($r[$k]));
        sort($r[$k], SORT_STRING);
    }
    return $r;
}

function usbmap_boot_walk(UsbMapSysfs $s, array $devs, array &$r): void
{
    $seen = [];
    $queue = [];
    foreach ($devs as $dev) {
        $real = $s->dev_real($dev);
        $queue[] = basename($real ?? $dev);
    }
    while ($queue && count($seen) < 64) {
        $name = array_shift($queue);
        if ($name === '' || isset($seen[$name]) || !preg_match('/^[A-Za-z0-9_.:!-]+$/', $name)) {
            continue;
        }
        $seen[$name] = true;
        $slaves = $s->block_slaves($name);
        if ($slaves) {
            foreach ($slaves as $sl) {
                $queue[] = $sl;
            }
            continue;
        }
        $r['blocks'][] = $name;
        $real = $s->block_real($name);
        if ($real !== null) {
            usbmap_boot_path($s, $real, $r);
        }
    }
}

/** Records the USB device and controller (or the PCI device) on a sysfs path. */
function usbmap_boot_path(UsbMapSysfs $s, string $real, array &$r): void
{
    $comps = explode('/', $real);
    $root_at = -1;
    foreach ($comps as $i => $c) {
        if (preg_match('/^usb\d+$/', $c)) {
            $root_at = $i;
            break;
        }
    }
    if ($root_at > 0) {
        $usbdev = '';
        for ($i = $root_at + 1; $i < count($comps); $i++) {
            if (preg_match('/^\d+-\d+(\.\d+)*$/', $comps[$i])) {
                $usbdev = $comps[$i];
            }
        }
        if ($usbdev !== '') {
            $r['usb'][] = $usbdev;
        }
        $r['controllers'][] = strtolower($comps[$root_at - 1]);
        return;
    }
    $bdfs = usbmap_path_bdfs($real);
    if ($bdfs) {
        $r['pci'][] = $bdfs[count($bdfs) - 1];
    }
}

// ---------------------------------------------------------------- Thunderbolt

function usbmap_thunderbolt(UsbMapSysfs $s): array
{
    $domains = [];
    $list = $s->tb_list();
    foreach ($list as $n) {
        if (preg_match('/^domain(\d+)$/', $n, $m)) {
            $bdfs = usbmap_path_bdfs((string)$s->tb_real($n));
            $domains[$m[1]] = [
                'domain' => $n,
                'nhi' => $bdfs ? $bdfs[count($bdfs) - 1] : '',
                'security' => usbmap_text_clean($s->tb_attr($n, 'security')),
                'routers' => [],
            ];
        }
    }
    foreach ($list as $n) {
        if (preg_match('/^(\d+)-([0-9a-f]+)$/', $n, $m) && isset($domains[$m[1]])) {
            $domains[$m[1]]['routers'][] = [
                'name' => $n,
                'host' => $m[2] === '0',
                'vendor' => usbmap_text_clean($s->tb_attr($n, 'vendor_name')),
                'device' => usbmap_text_clean($s->tb_attr($n, 'device_name')),
                'generation' => usbmap_text_clean($s->tb_attr($n, 'generation')),
            ];
        }
    }
    ksort($domains);
    foreach ($domains as &$d) {
        usort($d['routers'], static fn($a, $b) => strnatcmp($a['name'], $b['name']));
    }
    unset($d);
    return array_values($domains);
}

// ---------------------------------------------------------------- names

function usbmap_clean_name($s): string
{
    if (!is_string($s) || !preg_match('//u', $s)) {
        return '';
    }
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    $s = (string)preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s);
    $s = trim($s);
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, USBMAP_NAME_MAX, 'UTF-8');
    } elseif (preg_match('/^.{0,' . USBMAP_NAME_MAX . '}/us', $s, $m)) {
        $s = $m[0];
    }
    return trim($s);
}

function usbmap_valid_id_shape(string $id): bool
{
    return $id !== '' && strlen($id) <= 256 && (bool)preg_match('/^[\x21-\x7e]+\|(hs|ss)[\x21-\x7e]*$/', $id);
}

/** ['ok' => bool, 'names' => [id => name]]. ok is false when the file is unreadable. */
function usbmap_names_load(): array
{
    $f = usbmap_ports_file();
    if (!is_file($f)) {
        return ['ok' => true, 'names' => []];
    }
    $raw = @file_get_contents($f);
    if ($raw === false) {
        return ['ok' => false, 'names' => []];
    }
    if (trim($raw) === '') {
        return ['ok' => true, 'names' => []];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'names' => []];
    }
    $out = [];
    $ports = isset($j['ports']) && is_array($j['ports']) ? $j['ports'] : [];
    foreach ($ports as $k => $v) {
        if (is_array($v)) {
            $v = $v['name'] ?? '';
        }
        $k = (string)$k;
        $n = usbmap_clean_name(is_string($v) ? $v : '');
        if ($n !== '' && usbmap_valid_id_shape($k)) {
            $out[$k] = $n;
        }
    }
    return ['ok' => true, 'names' => $out];
}

/** Writes ports.json only when its content changes (temp file + rename). */
function usbmap_names_save(array $names): array
{
    $f = usbmap_ports_file();
    ksort($names, SORT_STRING);
    $json = json_encode(['version' => 1, 'ports' => (object)$names], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'changed' => false, 'error' => 'Could not encode the names.'];
    }
    $json .= "\n";
    if (is_file($f)) {
        if (@file_get_contents($f) === $json) {
            return ['ok' => true, 'changed' => false, 'error' => ''];
        }
    } elseif (!$names) {
        return ['ok' => true, 'changed' => false, 'error' => ''];
    }
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return ['ok' => false, 'changed' => false, 'error' => 'Could not create ' . $dir . '.'];
    }
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        return ['ok' => false, 'changed' => false, 'error' => 'Could not write ' . $f . '.'];
    }
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        return ['ok' => false, 'changed' => false, 'error' => 'Could not replace ' . $f . '.'];
    }
    return ['ok' => true, 'changed' => true, 'error' => ''];
}

/** Sets or clears one port name. The port must be in the live map (clear also takes a saved id). */
function usbmap_name_apply(UsbMapSysfs $s, string $action, string $id, $name): array
{
    $fail = static fn($e) => ['ok' => false, 'changed' => false, 'error' => $e, 'id' => '', 'name' => ''];
    if ($action !== 'set' && $action !== 'clear') {
        return $fail('Unknown action.');
    }
    if (!usbmap_valid_id_shape($id)) {
        return $fail('Unknown port.');
    }
    $loaded = usbmap_names_load();
    if (!$loaded['ok']) {
        return $fail('ports.json could not be read. Fix or remove it, then try again.');
    }
    $names = $loaded['names'];
    $live = usbmap_collect($s)['ids'];
    if ($action === 'set') {
        if (!in_array($id, $live, true)) {
            return $fail('That port is not in the current map. Refresh the page.');
        }
        $clean = usbmap_clean_name($name);
        if ($clean === '') {
            unset($names[$id]);
        } else {
            $names[$id] = $clean;
        }
    } else {
        if (!in_array($id, $live, true) && !isset($names[$id])) {
            return $fail('Unknown port.');
        }
        unset($names[$id]);
    }
    $w = usbmap_names_save($names);
    return ['ok' => $w['ok'], 'changed' => $w['changed'], 'error' => $w['error'], 'id' => $id, 'name' => $names[$id] ?? ''];
}

// ---------------------------------------------------------------- the map

/** Topology hash and the most recently connected device. Reads USB sysfs only. */
function usbmap_watch(UsbMapSysfs $s): array
{
    $keys = [];
    $newest = '';
    $best = null;
    foreach ($s->usb_list() as $n) {
        if (!preg_match('/^\d+-\d+(\.\d+)*$/', $n)) {
            continue;
        }
        $keys[] = $n . '@' . (int)$s->usb_attr($n, 'busnum') . '.' . (int)$s->usb_attr($n, 'devnum');
        $cd = $s->usb_attr($n, 'power/connected_duration');
        if ($cd !== null && ctype_digit($cd) && ($best === null || (int)$cd < $best)) {
            $best = (int)$cd;
            $newest = $n;
        }
    }
    sort($keys, SORT_STRING);
    return ['hash' => md5(implode("\n", $keys)), 'devices' => $keys, 'newest' => $newest, 'newest_ms' => $best];
}

function usbmap_safety_text(array $c): string
{
    if ($c['holds_boot']) {
        return 'Holds the Unraid boot device. Do not pass through.';
    }
    if ($c['group_boot']) {
        return 'Its IOMMU group holds the Unraid boot device. Do not pass through.';
    }
    if ($c['group'] === null) {
        return 'No IOMMU group (IOMMU is off or not supported).';
    }
    if ($c['shares']) {
        $names = array_map(static fn($m) => substr($m['bdf'], 5) . ($m['name'] !== '' ? ' ' . $m['name'] : ''), $c['shares']);
        return 'Shares group with ' . implode(', ', $names) . '.';
    }
    return 'Safe to pass through.';
}

function usbmap_collect(UsbMapSysfs $s): array
{
    $lspci = usbmap_lspci_parse($s->lspci());
    $pcache = [];
    $groups = $s->iommu_groups();
    $group_of = [];
    foreach ($groups as $g => $devs) {
        foreach ($devs as $d) {
            $group_of[strtolower($d)] = $g;
        }
    }
    $vfio_cfg = usbmap_vfio_cfg_parse($s->vfio_cfg());
    $loaded = usbmap_names_load();

    $usb = [];
    foreach ($s->usb_list() as $n) {
        if (usbmap_is_usbdev($n)) {
            $usb[$n] = usbmap_usb_info($s, $n);
        }
    }

    // Controllers: parents of root hubs, plus USB-class PCI devices with no bus (vfio, NHI).
    $ctrl = [];
    foreach ($usb as $n => $d) {
        if (strncmp($n, 'usb', 3) !== 0) {
            continue;
        }
        $real = (string)$s->usb_real($n);
        $parent = dirname($real);
        $key = basename($parent);
        if ($real === '' || $key === '' || $key === '.') {
            $key = $n;
        }
        $key = usbmap_is_bdf($key) ? strtolower($key) : $key;
        $ctrl[$key]['path'] = $parent;
        $ctrl[$key]['roots'][] = $d;
    }
    $pcis = $s->pci_list();
    if (!$pcis) {
        $pcis = array_keys($lspci);
    }
    foreach ($pcis as $bdf) {
        $bdf = strtolower($bdf);
        if (isset($ctrl[$bdf]) || !usbmap_is_bdf($bdf)) {
            continue;
        }
        $info = usbmap_pci_info($s, $bdf, $lspci, $pcache);
        if ($info['class'] === '0c03') {
            $ctrl[$bdf] = ['path' => (string)$s->pci_real($bdf), 'roots' => []];
        }
    }
    ksort($ctrl, SORT_STRING);

    $boot = usbmap_boot($s, $usb);
    $ctx = ['usb' => $usb, 'names' => $loaded['names'], 'ids' => [], 'dev_slot' => [], 'roles' => []];
    $tb = usbmap_thunderbolt($s);

    $out = [];
    foreach ($ctrl as $key => $c) {
        $is_pci = usbmap_is_bdf($key);
        $info = $is_pci ? usbmap_pci_info($s, $key, $lspci, $pcache) : [
            'bdf' => '', 'vid' => '', 'did' => '', 'class' => '', 'progif' => '', 'class_name' => '', 'name' => $key, 'driver' => '',
        ];
        $real = $is_pci ? (string)($s->pci_real($key) ?? $c['path']) : $c['path'];
        $chain = usbmap_path_bdfs($real);
        if ($is_pci && ($chain === [] || $chain[count($chain) - 1] !== $key)) {
            $chain[] = $key;
        }
        $root = '';
        if (preg_match('#/(pci[0-9a-f]{4}:[0-9a-f]{2})/#', $real . '/', $m)) {
            $root = $m[1];
        }
        $upstream = array_slice($chain, 0, -1);
        $chain_named = [];
        foreach ($upstream as $b) {
            $bi = usbmap_pci_info($s, $b, $lspci, $pcache);
            $chain_named[] = ['bdf' => $b, 'name' => $bi['name']];
        }

        $roots = $c['roots'];
        usort($roots, static fn($a, $b) => [$a['speed'], $a['bus']] <=> [$b['speed'], $b['bus']]);
        $buses = [];
        $seen_role = [];
        foreach ($roots as $r) {
            $role = $r['speed'] > 480 ? 'ss' : 'hs';
            if (isset($seen_role[$role])) {
                $role .= $r['bus'];
            }
            $seen_role[$role] = true;
            $ctx['roles'][$r['bus']] = $role;
            $buses[] = [
                'bus' => $r['bus'],
                'role' => $role,
                'speed' => $r['speed'],
                'label' => usbmap_speed_label($r['speed'], $r['lanes']),
                'gen' => usbmap_speed_gen($r['speed'], $r['lanes']),
                'version' => $r['version'],
                'ports' => $r['maxchild'],
            ];
        }
        $slots = $roots ? usbmap_build_slots($s, $ctx, $key, $roots, 0) : [];

        $group = $is_pci && isset($group_of[$key]) ? $group_of[$key] : null;
        $members = [];
        $shares = [];
        if ($group !== null) {
            foreach ($groups[$group] as $mb) {
                $mb = strtolower($mb);
                if ($mb === $key) {
                    continue;
                }
                $mi = usbmap_pci_info($s, $mb, $lspci, $pcache);
                $role = 'other';
                if (in_array($mi['class'], ['0600', '0604', '0609'], true)) {
                    $role = 'bridge';
                } elseif (substr($mb, 0, 10) === substr($key, 0, 10) && $mi['class'] === '0c03') {
                    $role = 'function';
                }
                $row = ['bdf' => $mb, 'name' => $mi['name'], 'class' => $mi['class'], 'class_name' => $mi['class_name'], 'driver' => $mi['driver'], 'role' => $role];
                $members[] = $row;
                if ($role === 'other') {
                    $shares[] = $row;
                }
            }
        }
        $holds_boot = in_array($key, $boot['controllers'], true);
        $group_boot = false;
        if ($group !== null && !$holds_boot) {
            foreach ($groups[$group] as $mb) {
                $mb = strtolower($mb);
                if ($mb !== $key && (in_array($mb, $boot['pci'], true) || in_array($mb, $boot['controllers'], true))) {
                    $group_boot = true;
                }
            }
        }
        $kind = $is_pci ? usbmap_kind($info['class'], $info['progif']) : 'USB';
        if ($is_pci && $kind === 'other' && $roots) {
            $kind = 'USB';
        }
        $usb4 = $kind === 'USB4 NHI' || (bool)preg_match('/thunderbolt|usb4/i', $info['name']);
        $status = 'safe';
        if ($holds_boot || $group_boot) {
            $status = 'boot';
        } elseif ($group === null) {
            $status = 'nogroup';
        } elseif ($shares) {
            $status = 'shared';
        }
        $entry = [
            'key' => $key,
            'bdf' => $is_pci ? $key : '',
            'vid' => $info['vid'],
            'did' => $info['did'],
            'name' => $info['name'] !== '' ? $info['name'] : ($info['class_name'] !== '' ? $info['class_name'] : 'USB controller'),
            'kind' => $kind,
            'usb4' => $usb4,
            'driver' => $info['driver'],
            'root' => $root,
            'root_port' => $upstream ? $upstream[0] : '',
            'chain' => $chain_named,
            'group' => $group,
            'group_members' => $members,
            'shares' => $shares,
            'holds_boot' => $holds_boot,
            'group_boot' => $group_boot,
            'status' => $status,
            'vfio' => ['bound' => $info['driver'] === 'vfio-pci', 'cfg' => $is_pci && isset($vfio_cfg[$key])],
            'buses' => $buses,
            'slots' => $slots,
            'tb' => [],
            'tb_peer' => [],
            '_chain' => $chain,
        ];
        $entry['safety_text'] = usbmap_safety_text($entry);
        $out[] = $entry;
    }

    // Thunderbolt domains hang off their NHI. Link an NHI and the xHCI of the same chip.
    foreach ($out as &$c) {
        foreach ($tb as $d) {
            if ($d['nhi'] !== '' && $d['nhi'] === $c['bdf']) {
                $c['tb'][] = $d;
            }
        }
    }
    unset($c);
    foreach ($out as $i => $a) {
        if ($a['kind'] !== 'USB4 NHI') {
            continue;
        }
        foreach ($out as $j => $b) {
            if ($i === $j || $b['kind'] !== 'xHCI' || $a['vid'] !== $b['vid']) {
                continue;
            }
            $ca = $a['_chain'];
            $cb = $b['_chain'];
            $same_parent = count($ca) >= 2 && count($cb) >= 2 && $ca[count($ca) - 2] === $cb[count($cb) - 2];
            $same_switch = count($ca) >= 3 && count($cb) >= 3 && $ca[count($ca) - 3] === $cb[count($cb) - 3];
            if ($same_parent || $same_switch) {
                $out[$i]['tb_peer'][] = $b['bdf'];
                $out[$j]['tb_peer'][] = $a['bdf'];
                $out[$j]['usb4'] = true;
            }
        }
    }
    foreach ($out as &$c) {
        unset($c['_chain']);
    }
    unset($c);

    $ids = array_keys($ctx['ids']);
    $stale = [];
    foreach ($loaded['names'] as $id => $nm) {
        if (!isset($ctx['ids'][$id])) {
            $stale[] = ['id' => $id, 'name' => $nm];
        }
    }
    $boot_text = usbmap_boot_text($boot, $usb, $out);
    $w = usbmap_watch($s);
    return [
        'format' => 1,
        'plugin_version' => $s->plugin_version(),
        'board' => array_map('usbmap_text_clean', $s->board()),
        'iommu' => $groups !== [],
        'hash' => $w['hash'],
        'boot' => $boot + ['text' => $boot_text],
        'controllers' => $out,
        'thunderbolt' => $tb,
        'ids' => $ids,
        'dev_slot' => $ctx['dev_slot'],
        'stale_names' => $stale,
        'names_error' => $loaded['ok'] ? '' : 'ports.json could not be read. Names are not shown.',
    ];
}

function usbmap_boot_text(array $boot, array $usb, array $ctrls): string
{
    $parts = [];
    foreach ($boot['usb'] as $dev) {
        $d = $usb[$dev] ?? null;
        $label = $dev;
        if ($d) {
            $label .= ' ' . trim($d['manufacturer'] . ' ' . $d['product']);
        }
        $parts[] = trim($label);
    }
    $txt = '';
    if ($parts) {
        $txt = 'USB ' . implode(', ', $parts) . ' on ' . implode(', ', $boot['controllers']);
    } elseif ($boot['controllers']) {
        $txt = 'On USB controller ' . implode(', ', $boot['controllers']);
    } elseif ($boot['pci']) {
        $txt = implode(', ', $boot['blocks']) . ' on ' . implode(', ', $boot['pci']) . ' (not USB)';
    } else {
        return 'Not found' . ($boot['source'] !== '' ? ' (' . $boot['source'] . ')' : '');
    }
    return $txt;
}

// ---------------------------------------------------------------- export

function usbmap_json(array $data, bool $pretty = false): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    return (string)json_encode($data, $flags);
}

/** JSON safe to place inside a <script> element. */
function usbmap_json_script(array $data): string
{
    return (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function usbmap_export(UsbMapSysfs $s, string $fmt): string
{
    $m = usbmap_collect($s);
    if ($fmt === 'json') {
        return usbmap_json($m, true) . "\n";
    }
    return usbmap_text($m);
}

function usbmap_slot_label(array $slot): string
{
    return implode(' + ', array_map(static fn($m) => $m['port'], $slot['members']));
}

function usbmap_phys_text(array $p): string
{
    $bits = [];
    if (($p['panel'] ?? '') !== '' && $p['panel'] !== 'unknown') {
        $bits[] = $p['panel'];
    }
    $pos = trim((($p['vertical_position'] ?? '') !== 'center' ? ($p['vertical_position'] ?? '') : '') . ' ' . (($p['horizontal_position'] ?? '') !== 'center' ? ($p['horizontal_position'] ?? '') : ''));
    if ($pos !== '') {
        $bits[] = $pos;
    }
    if (($p['dock'] ?? '') === 'yes') {
        $bits[] = 'dock';
    }
    if (($p['lid'] ?? '') === 'yes') {
        $bits[] = 'lid';
    }
    return implode(', ', $bits);
}

function usbmap_text_slots(array $slots, string $ind, array &$o, bool $all): void
{
    foreach ($slots as $sl) {
        if (!$all && $sl['connect_type'] === 'not used' && !$sl['children']) {
            continue;
        }
        $line = $ind . 'Port ' . usbmap_slot_label($sl);
        if ($sl['name'] !== '') {
            $line .= ' "' . $sl['name'] . '"';
        }
        $line .= ', ' . $sl['max_scope'] . ' max ' . $sl['max_label'];
        if ($sl['connect_type'] !== '') {
            $line .= ', ' . $sl['connect_type'];
        }
        $ph = usbmap_phys_text($sl['phys']);
        if ($ph !== '') {
            $line .= ', ' . $ph;
        }
        $o[] = $line;
        foreach ($sl['children'] as $n) {
            $what = trim($n['manufacturer'] . ' ' . $n['product']);
            $line = $ind . '  ' . ($n['type'] === 'hub' ? 'Hub ' : '') . implode('+', $n['type'] === 'hub' ? array_column($n['halves'], 'dev') : [$n['dev']])
                . ' ' . $n['vid'] . ':' . $n['pid'] . ($what !== '' ? ' ' . $what : '')
                . ', ' . $n['speed_label'] . ($n['version'] !== '' ? ' (USB ' . $n['version'] . ')' : '');
            if ($n['flag_text'] !== '') {
                $line .= ' ! ' . $n['flag_text'];
            }
            $o[] = $line;
            if ($n['type'] === 'hub') {
                usbmap_text_slots($n['slots'], $ind . '    ', $o, $all);
            }
        }
    }
}

function usbmap_text(array $m, bool $all = false): string
{
    $o = [];
    $o[] = 'USB Map' . ($m['plugin_version'] !== '' ? ' ' . $m['plugin_version'] : '');
    $board = trim($m['board']['vendor'] . ' ' . $m['board']['name']);
    if ($board !== '') {
        $o[] = 'Board: ' . $board;
    }
    $o[] = 'IOMMU: ' . ($m['iommu'] ? 'on' : 'off');
    $o[] = 'Boot device: ' . $m['boot']['text'];
    foreach ($m['controllers'] as $c) {
        $o[] = '';
        $o[] = ($c['bdf'] !== '' ? $c['bdf'] : $c['key']) . ($c['vid'] !== '' ? ' [' . $c['vid'] . ':' . $c['did'] . ']' : '') . ' ' . $c['name'] . ' (' . $c['kind'] . ')';
        $o[] = '  Driver: ' . ($c['driver'] !== '' ? $c['driver'] : 'none') . '. IOMMU group ' . ($c['group'] ?? '-') . ': ' . $c['safety_text'];
        if ($c['vfio']['bound'] || $c['vfio']['cfg']) {
            $o[] = '  vfio-pci: ' . implode(', ', array_filter([$c['vfio']['bound'] ? 'bound now' : '', $c['vfio']['cfg'] ? 'in vfio-pci.cfg' : '']));
        }
        if ($c['chain']) {
            $o[] = '  Path: ' . implode(' > ', array_map(static fn($b) => substr($b['bdf'], 5), $c['chain']));
        }
        if ($c['tb_peer']) {
            $o[] = '  Same Thunderbolt/USB4 chip: ' . implode(', ', $c['tb_peer']);
        }
        if ($c['buses']) {
            $o[] = '  Buses: ' . implode(', ', array_map(static fn($b) => $b['bus'] . ' (' . $b['gen'] . ', ' . $b['label'] . ', ' . $b['ports'] . ' ports)', $c['buses']));
        } elseif ($c['kind'] !== 'USB4 NHI') {
            $o[] = '  No USB bus' . ($c['vfio']['bound'] ? ' (bound to vfio-pci)' : '');
        }
        foreach ($c['tb'] as $d) {
            $o[] = '  Thunderbolt ' . $d['domain'] . ($d['security'] !== '' ? ', security ' . $d['security'] : '');
            foreach ($d['routers'] as $r) {
                $o[] = '    ' . $r['name'] . ($r['host'] ? ' host' : '') . ' ' . trim($r['vendor'] . ' ' . $r['device']);
            }
        }
        usbmap_text_slots($c['slots'], '  ', $o, $all);
    }
    if ($m['stale_names']) {
        $o[] = '';
        $o[] = 'Saved names not in this map:';
        foreach ($m['stale_names'] as $st) {
            $o[] = '  ' . $st['id'] . ' "' . $st['name'] . '"';
        }
    }
    return implode("\n", $o) . "\n";
}
