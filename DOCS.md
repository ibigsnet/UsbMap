# USB Map

A block diagram of how the USB ports on your server hang off their controllers, in the spirit of a motherboard block diagram: PCIe root port → USB controller → physical port → hub → device. Use it to find which controller to pass through to a VM, and which physical port belongs to which controller.

Tools → USB Map. Nothing on the system is changed. The only file written is your port names.

## The diagram

Each column is one step down the tree:

- **PCIe root port** (or the root complex, for controllers built into the CPU or chipset).
- **Controller**: name, PCI address (BDF) and `vendor:device` ID, type (xHCI, EHCI, USB4 NHI), driver, IOMMU group, its root hubs (for example `Bus 3 480M · Bus 4 20G x2`), and the bridges in between.
- **Port**: one physical connector. A USB 3 connector has a USB 2 half and a USB 3 half on two different buses; the kernel links them as `peer`s and the map shows them as one port, for example `usb3-port2 + usb4-port2`. It shows the maximum speed of the bus (root ports) or hub (hub ports), the connection type (`hotplug` = a connector, `hardwired` = an onboard device, `not used`), and the panel position when the firmware provides it.
- **Hub**: a USB 3 hub is also two devices (USB 2 half and USB 3 half). They are shown as one hub with their ports paired the same way.
- **Device**: product, maker, `vendor:product` ID, link speed against the port maximum, USB version, bus device name (`3-2`) and drivers.

Speeds: `12M` full speed, `480M` USB 2.0, `5G` USB 3.2 Gen 1, `10G` Gen 2, `20G x2` Gen 2x2. A USB 3 bus max is the fastest the controller offers; a single connector on that bus may be slower.

**Show unused ports** adds ports the firmware marks `not used`. Hover a box for its full text.

### Speed flags

- **USB 3 device running at USB 2 speed**: the device reports USB 3, the port allows 5G or more, and the link came up at 480M or less. Usually a USB 2 cable, a USB 2 extension, a USB 2 hub in between, or a dirty connector.
- **Slower than this port allows**: a USB 3.1/3.2 device linked at 5G or 10G on a faster port. Many devices report 3.1/3.2 but only run Gen 1, so treat this as a hint.

USB 2 devices are never flagged; a keyboard at 12M is normal.

## Passthrough

Each controller says one of:

- **Safe to pass through**: its IOMMU group holds only this controller, other USB functions of the same chip, or PCI bridges.
- **Shares group with …**: the other devices in the group. Passing the controller means passing those too (or an ACS override, which is not a security boundary).
- **Holds the Unraid boot device. Do not pass through.** Unraid loses `/boot` when that controller leaves the host. Also shown when the boot device's controller is in the same IOMMU group.
- **No IOMMU group**: IOMMU is off in the BIOS or kernel.

It also shows **vfio-pci**: bound now (the driver is `vfio-pci`) and/or listed in `/boot/config/vfio-pci.cfg` (bound at boot). A controller bound to vfio-pci has no USB bus on the host, so its ports are not in the map; their names are kept and listed under the diagram.

To bind a controller, use Tools → System Devices. USB Map does not change bindings.

### How the boot device is found

1. `/boot` in `/proc/self/mounts`. For a `/dev/...` source the block device (and any `dm`/`md` layers) is followed through `/sys/class/block` to its sysfs path. On a ZFS boot pool, the pool's disks come from `zpool list -vHPL`.
2. If that finds nothing, the `UNRAID` volume label.
3. Last, the flash GUID matched against USB vendor, product and serial number.

On the sysfs path, the USB device sits under its root hub (`usbN`), and the PCI device just above it is the controller. A boot device on NVMe or SATA is shown as "not USB"; a USB controller in the same IOMMU group is still marked.

## Naming ports

Click a port, type a name (up to 64 characters), Save. Clear removes it.

**Identify port**: click it, then plug any device into the port you want to name. Within about two seconds the port is highlighted and the name field opens. It checks only while the page is visible, and stops after 10 minutes or when you click **Stop identify**.

Names are stored in `/boot/config/plugins/usbmap/ports.json`, keyed by a **port id**:

```
0000:6a:00.0|hs-2+ss-2
```

- The controller's PCI address.
- One token per half of the connector: `hs` for the USB 2 root hub, `ss` for the USB 3 root hub, then the port path (`2` for root port 2, `3.1` for port 1 of the hub on root port 3).

Bus numbers are not part of the id, so names survive bus renumbering (for example after another controller is bound to vfio-pci). A firmware update or a board change can change the PCI address; names then show under "Saved names for ports that are not in the map now", where they can be cleared.

## Export

**Export text** downloads a plain-text map for a forum post. **Export JSON** downloads the data the page uses. In a terminal:

```
usbmap          # text, unused ports left out
usbmap all      # text, every port
usbmap json     # JSON
```

(`/usr/local/emhttp/plugins/UsbMap/scripts/usbmap`.) Serial numbers are never exported.

## Thunderbolt and USB4

Domains under `/sys/bus/thunderbolt` are shown under their NHI controller with their security level and routers. The xHCI of the same Thunderbolt or USB4 chip is linked to its NHI ("Same Thunderbolt/USB4 chip"). Devices behind a Thunderbolt dock's own USB controller show up as that controller.

## Limits

- Port positions come from ACPI `physical_location` only when the firmware fills it in; many boards do not.
- A port that is wired but never reported by the firmware cannot be shown.
- The bus maximum is per root hub, not per connector.
- A controller bound to vfio-pci shows no ports.

## Uninstall

Remove from Plugins. `/boot/config/plugins/usbmap/ports.json` stays for a reinstall.
