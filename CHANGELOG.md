# Changelog

## 2026.10.30aa

- First release. **Tools → USB Map** draws every USB host controller (xHCI, EHCI, Thunderbolt/USB4) under its PCIe root port, with its IOMMU group, root hubs, physical ports, hubs and devices.
- **Passthrough check** per controller: safe to pass through, shares an IOMMU group (listed), or holds the Unraid boot device (never pass it through). Shows when a controller is bound to `vfio-pci` now or listed in `vfio-pci.cfg`.
- One physical connector is one port: the USB 2 and USB 3 halves are joined through the kernel's port `peer` links, also for USB 3 hubs.
- Devices show their link speed against the port's maximum. A USB 3 device running at USB 2 speed is flagged.
- **Port names**, saved in `/boot/config/plugins/usbmap/ports.json`. **Identify port**: plug a device in and its port is highlighted with the name field open.
- Thunderbolt/USB4 domains shown under their controller. **Export text** and **Export JSON** for forum posts. `usbmap` prints the same map in a terminal.
