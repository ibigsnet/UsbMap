# Contributing

Bug reports, docs fixes, and pull requests are welcome.

1. Open an [issue](https://github.com/ibigsnet/UsbMap/issues) for discussion when the change is large.
2. Prefer focused PRs against `main`.
3. A map from your board helps: attach the **Export text** output (or `usbmap all`) to the issue.
4. The collector reads every path through `UsbMapSysfs`. Set `USBMAP_SYSROOT=<dir>` to point it at a copy of sysfs, and `USBMAP_CFGDIR=<dir>` to keep test names off the flash drive.
5. Keep it read-only: no writes outside `/boot/config/plugins/usbmap/ports.json`, and nothing that wakes disks.

Support: [GitHub Issues](https://github.com/ibigsnet/UsbMap/issues).

By contributing you license your work under the **GNU GPLv3 or later** (same as this project). Copyright **ibigs, LLC**.
