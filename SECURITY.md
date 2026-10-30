# Security

USB Map is read-only. It reads the system and writes one file: the port names.

## What it reads

- `/sys/bus/usb/devices/*`: bus and device numbers, speed, USB version, vendor and product IDs and strings, hub port count, and the port directories (`connect_type`, `location`, `peer`, `firmware_node/physical_location`).
- `/sys/kernel/iommu_groups/*/devices` and `/sys/bus/pci/devices/<bdf>/{vendor,device,class,driver}`.
- `/sys/bus/thunderbolt/devices`: domains, router names, security level.
- `lspci -nnDmm` for device names. It is run without user input.
- `/proc/self/mounts`, `/sys/class/block/*`, and, on a ZFS boot device, `zpool list -vHPL <pool>` (pool name checked and passed with `escapeshellarg`) to find the boot device.
- `/boot/config/vfio-pci.cfg` (read only; never changed).
- As a last fallback for the boot device, `flashGUID` from `/var/local/emhttp/var.ini` and USB serial numbers. Serial numbers are not shown or exported.

It does not run `smartctl`, open block devices, or do anything that spins up disks.

## What it writes

- `/boot/config/plugins/usbmap/ports.json`: port names keyed by port id. Written only when a name changes, through a temp file and a rename. The flash drive has no per-file permissions; anyone with flash access can read it. It holds only the names you typed.

## Endpoints

- `include/usbmap-get.php` (GET, read-only): `a=map` (JSON map), `a=watch` (topology hash and newest device; used by Identify port about every 1.5 s, only while it is on and the page is visible), `a=export&fmt=text|json` (download). Other methods are refused.
- `include/usbmap-save.php` (POST only): `action=set|clear`, `id`, `name`. Unraid checks the `csrf_token` on every POST. The port id must be in the live map (clear also accepts an id that is saved). Names are cut to 64 characters, and control and text-direction characters are removed.

## Output

- Names and device strings are escaped everywhere: `textContent` in the page script, `htmlspecialchars` in PHP, and JSON with `<`, `>`, `&` and quotes escaped inside the page.
- The export holds the board name, PCI and USB IDs, device names, IOMMU groups and your port names. Check it before posting it publicly.

Report issues privately through [GitHub security advisories](https://github.com/ibigsnet/UsbMap/security/advisories) or as a [GitHub issue](https://github.com/ibigsnet/UsbMap/issues) for non-sensitive problems.
