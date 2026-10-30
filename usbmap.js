/* USB Map: draws the controller -> port -> hub -> device diagram as inline SVG.
 * Copyright (c) 2026 ibigs, LLC. Author: RifleJock. GPL-3.0-or-later.
 * All text is set with textContent. */
(function () {
  'use strict';

  var BASE = '/plugins/UsbMap/include/';
  var GET = BASE + 'usbmap-get.php';
  var SAVE = BASE + 'usbmap-save.php';
  var NS = 'http://www.w3.org/2000/svg';
  var POLL_MS = 1500;
  var IDENTIFY_MAX_MS = 10 * 60 * 1000;
  var WIDTH = { root: 190, ctrl: 380, slot: 230, device: 250, hub: 250, tb: 250, note: 230 };
  var GAP_X = 34;
  var GAP_Y = 8;
  var GAP_ROOT = 22;
  var LINE = 15;
  var PAD = 7;
  var CHAR = 6.6;

  var state = {
    map: null,
    showUnused: false,
    identify: false,
    timer: null,
    busy: false,
    known: null,
    hash: '',
    stopAt: 0,
    highlight: '',
    editing: ''
  };

  function $id(id) { return document.getElementById(id); }

  function msg(text) {
    var m = $id('usbmap-msg');
    if (m) m.textContent = text || '';
  }

  function svg(tag, attrs) {
    var e = document.createElementNS(NS, tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) { e.setAttribute(k, String(attrs[k])); });
    }
    return e;
  }

  function fit(text, width) {
    var max = Math.max(4, Math.floor((width - 2 * PAD - 6) / CHAR));
    text = String(text == null ? '' : text);
    return text.length > max ? text.slice(0, max - 1) + '\u2026' : text;
  }

  function short(bdf) { return String(bdf || '').replace(/^0000:/, ''); }

  function line(t, cls) { return { t: t, cls: cls || '' }; }

  // ------------------------------------------------------------ model -> nodes

  function deviceLines(n, slot) {
    var lines = [];
    var title = n.product || (n.vid + ':' + n.pid);
    lines.push(line((n.type === 'hub' ? 'Hub: ' : '') + title, 'um-t'));
    var who = [];
    if (n.manufacturer) who.push(n.manufacturer);
    if (n.product) who.push(n.vid + ':' + n.pid);
    if (!who.length) who.push('No name reported');
    lines.push(line(who.join(' \u00b7 '), 'um-d'));
    var sp = n.speed_label + ' on ' + slot.max_label + ' ' + (slot.max_scope === 'bus' ? 'bus' : 'hub') + ' max';
    lines.push(line(sp, n.flag === 'slow' ? 'um-warn' : (n.flag === 'below' ? 'um-warn' : '')));
    if (n.flag_text) lines.push(line(n.flag_text, 'um-warn'));
    if (n.type === 'hub') {
      lines.push(line(n.halves.map(function (h) { return h.dev + ' ' + h.speed_label; }).join(' + ') + ' \u00b7 ' + n.ports + ' ports', 'um-d'));
    } else {
      var tail = [n.dev, 'USB ' + n.version];
      if (n.drivers && n.drivers.length) tail.push(n.drivers.join(', '));
      lines.push(line(tail.join(' \u00b7 '), 'um-d'));
    }
    return lines;
  }

  function physText(p) {
    if (!p) return '';
    var bits = [];
    if (p.panel && p.panel !== 'unknown') bits.push(p.panel);
    var pos = [];
    if (p.vertical_position && p.vertical_position !== 'center') pos.push(p.vertical_position);
    if (p.horizontal_position && p.horizontal_position !== 'center') pos.push(p.horizontal_position);
    if (pos.length) bits.push(pos.join(' '));
    if (p.dock === 'yes') bits.push('dock');
    if (p.lid === 'yes') bits.push('lid');
    return bits.join(', ');
  }

  function slotLabel(sl) {
    return sl.members.map(function (m) { return m.port; }).join(' + ');
  }

  function slotNode(sl) {
    var lines = [];
    var num = sl.members.length ? sl.members[0].num : '';
    lines.push(sl.name ? line(sl.name, 'um-t') : line('Port ' + num + ' (unnamed)', 'um-d'));
    lines.push(line(slotLabel(sl), 'um-d'));
    var info = (sl.max_scope === 'bus' ? 'bus max ' : 'hub max ') + sl.max_label;
    if (sl.connect_type) info += ' \u00b7 ' + sl.connect_type;
    lines.push(line(info, ''));
    var ph = physText(sl.phys);
    if (ph) lines.push(line(ph, 'um-d'));
    var node = {
      kind: 'slot', lines: lines, children: [], slot: sl,
      status: sl.id === state.highlight ? 'hl' : '',
      unused: sl.connect_type === 'not used' && !sl.children.length
    };
    sl.children.forEach(function (n) {
      var dn = { kind: n.type === 'hub' ? 'hub' : 'device', lines: deviceLines(n, sl), children: [], status: n.flag ? 'slow' : '' };
      if (n.type === 'hub') dn.children = slotNodes(n.slots);
      node.children.push(dn);
    });
    return node;
  }

  function slotNodes(slots) {
    var out = [];
    slots.forEach(function (sl) {
      var unused = sl.connect_type === 'not used' && !sl.children.length;
      if (unused && !state.showUnused) return;
      out.push(slotNode(sl));
    });
    return out;
  }

  function ctrlNode(c) {
    var lines = [];
    lines.push(line(c.name, 'um-t'));
    lines.push(line((c.bdf || c.key) + (c.vid ? ' [' + c.vid + ':' + c.did + ']' : '') + ' \u00b7 ' + c.kind + (c.usb4 && c.kind !== 'USB4 NHI' ? ' (USB4/TB)' : ''), ''));
    lines.push(line('Driver ' + (c.driver || 'none') + ' \u00b7 IOMMU group ' + (c.group === null ? '-' : c.group), 'um-d'));
    if (c.buses.length) {
      lines.push(line(c.buses.map(function (b) { return 'Bus ' + b.bus + ' ' + b.label; }).join(' \u00b7 '), ''));
    }
    if (c.chain.length > 1) {
      lines.push(line('via ' + c.chain.slice(1).map(function (b) { return short(b.bdf); }).join(' \u203a '), 'um-d'));
    }
    var cls = c.status === 'safe' ? 'um-ok' : (c.status === 'boot' ? 'um-bad' : (c.status === 'shared' ? 'um-warn' : 'um-d'));
    if (c.status === 'shared') {
      lines.push(line('Shares IOMMU group with:', cls));
      c.shares.slice(0, 4).forEach(function (m) {
        lines.push(line('  ' + short(m.bdf) + ' ' + (m.name || m.class_name), cls));
      });
      if (c.shares.length > 4) lines.push(line('  and ' + (c.shares.length - 4) + ' more', cls));
    } else {
      lines.push(line(c.safety_text, cls));
    }
    if (c.vfio.bound || c.vfio.cfg) {
      var v = [];
      if (c.vfio.bound) v.push('bound to vfio-pci');
      if (c.vfio.cfg) v.push('in vfio-pci.cfg');
      lines.push(line('vfio-pci: ' + v.join(', '), 'um-info'));
    }
    if (c.tb_peer.length) lines.push(line('Same Thunderbolt/USB4 chip: ' + c.tb_peer.map(short).join(', '), 'um-d'));
    var status = c.status === 'boot' ? 'bad' : (c.status === 'shared' ? 'warn' : (c.status === 'safe' ? 'ok' : ''));
    if (c.vfio.bound && c.status !== 'boot') status = 'info';
    var node = { kind: 'ctrl', lines: lines, children: [], status: status, title: c.safety_text };
    c.tb.forEach(function (d) {
      var tl = [line('Thunderbolt ' + d.domain, 'um-t')];
      if (d.security) tl.push(line('Security: ' + d.security, 'um-d'));
      d.routers.forEach(function (r) {
        tl.push(line(r.name + (r.host ? ' host' : '') + ' ' + [r.vendor, r.device].join(' ').trim(), r.host ? 'um-d' : ''));
      });
      node.children.push({ kind: 'tb', lines: tl, children: [], status: 'info' });
    });
    if (!c.buses.length && c.kind !== 'USB4 NHI') {
      node.children.push({
        kind: 'note', children: [], status: '',
        lines: [line('No USB bus here', 'um-t'), line(c.vfio.bound ? 'Bound to vfio-pci' : (c.driver ? 'Driver ' + c.driver : 'No driver bound'), 'um-d')]
      });
    }
    node.children = node.children.concat(slotNodes(c.slots));
    return node;
  }

  function buildTree(map) {
    var roots = {};
    var order = [];
    map.controllers.forEach(function (c) {
      var key = c.root_port || c.root || 'other';
      if (!roots[key]) {
        var lines;
        if (c.root_port) {
          var first = c.chain[0] || {};
          lines = [line('PCIe root port', 'um-d'), line(short(c.root_port), 'um-t'), line(first.name || '', 'um-d')];
        } else {
          lines = [line('PCI root complex', 'um-d'), line(c.root || 'Platform', 'um-t')];
        }
        roots[key] = { kind: 'root', lines: lines, children: [], status: '' };
        order.push(key);
      }
      roots[key].children.push(ctrlNode(c));
    });
    return order.map(function (k) { return roots[k]; });
  }

  // ------------------------------------------------------------ layout + draw

  function measure(n) {
    n.w = WIDTH[n.kind] || 240;
    n.h = PAD * 2 + n.lines.length * LINE;
    var total = 0;
    n.children.forEach(function (c, i) {
      measure(c);
      total += c.sub + (i ? GAP_Y : 0);
    });
    n.childTotal = total;
    n.sub = Math.max(n.h, total);
  }

  function place(n, x, y) {
    n.x = x;
    n.y = y + (n.sub - n.h) / 2;
    var cy = y + (n.sub - n.childTotal) / 2;
    n.children.forEach(function (c) {
      place(c, x + n.w + GAP_X, cy);
      cy += c.sub + GAP_Y;
    });
  }

  function extent(n, acc) {
    acc.w = Math.max(acc.w, n.x + n.w);
    acc.h = Math.max(acc.h, n.y + n.h);
    n.children.forEach(function (c) { extent(c, acc); });
  }

  function drawEdges(g, n) {
    n.children.forEach(function (c) {
      var x1 = n.x + n.w;
      var y1 = n.y + n.h / 2;
      var x2 = c.x;
      var y2 = c.y + c.h / 2;
      var xm = x1 + GAP_X / 2;
      g.appendChild(svg('path', { 'class': 'um-edge', d: 'M' + x1 + ' ' + y1 + 'H' + xm + 'V' + y2 + 'H' + x2 }));
      drawEdges(g, c);
    });
  }

  function drawNode(g, n) {
    var cls = 'um-node um-' + n.kind;
    if (n.status) cls += ' um-s-' + n.status;
    if (n.unused) cls += ' um-unused';
    var ng = svg('g', { 'class': cls, transform: 'translate(' + n.x + ',' + n.y + ')' });
    var title = svg('title');
    title.textContent = n.title || n.lines.map(function (l) { return l.t; }).join('\n');
    ng.appendChild(title);
    ng.appendChild(svg('rect', { 'class': 'um-box', x: 0.5, y: 0.5, width: n.w - 1, height: n.h - 1, rx: 6 }));
    if (n.status) ng.appendChild(svg('rect', { 'class': 'um-accent', x: 0.5, y: 0.5, width: 4, height: n.h - 1, rx: 2 }));
    n.lines.forEach(function (l, i) {
      var t = svg('text', { x: PAD + 4, y: PAD + LINE * (i + 1) - 4, 'class': l.cls });
      t.textContent = fit(l.t, n.w);
      ng.appendChild(t);
    });
    if (n.kind === 'slot') {
      ng.setAttribute('tabindex', '0');
      ng.setAttribute('role', 'button');
      ng.setAttribute('data-slot', n.slot.id);
      ng.addEventListener('click', function () { openEdit(n.slot); });
      ng.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openEdit(n.slot); }
      });
    }
    g.appendChild(ng);
    n.children.forEach(function (c) { drawNode(g, c); });
  }

  function draw() {
    var wrap = $id('usbmap-wrap');
    if (!wrap || !state.map) return;
    var roots = buildTree(state.map);
    var y = 4;
    var acc = { w: 0, h: 0 };
    roots.forEach(function (r) {
      measure(r);
      place(r, 4, y);
      y += r.sub + GAP_ROOT;
      extent(r, acc);
    });
    var W = Math.ceil(acc.w + 6);
    var H = Math.ceil(acc.h + 6);
    var s = svg('svg', { 'class': 'usbmap-svg', width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img' });
    var label = svg('title');
    label.textContent = 'USB controllers, ports, hubs and devices';
    s.appendChild(label);
    var edges = svg('g');
    var nodes = svg('g');
    roots.forEach(function (r) { drawEdges(edges, r); drawNode(nodes, r); });
    s.appendChild(edges);
    s.appendChild(nodes);
    while (wrap.firstChild) wrap.removeChild(wrap.firstChild);
    if (!roots.length) {
      wrap.textContent = 'No USB controller found.';
    } else {
      wrap.appendChild(s);
    }
    drawStale();
    var b = $id('usbmap-boot');
    if (b && state.map.boot) b.textContent = state.map.boot.text;
  }

  function drawStale() {
    var box = $id('usbmap-stale');
    if (!box) return;
    while (box.firstChild) box.removeChild(box.firstChild);
    var list = state.map.stale_names || [];
    box.hidden = !list.length;
    if (!list.length) return;
    var h = document.createElement('p');
    h.textContent = 'Saved names for ports that are not in the map now (controller unbound or bound to vfio-pci):';
    box.appendChild(h);
    list.forEach(function (st) {
      var row = document.createElement('div');
      var t = document.createElement('span');
      t.textContent = st.name + ' (' + st.id + ') ';
      var btn = document.createElement('input');
      btn.type = 'button';
      btn.value = 'Clear';
      btn.addEventListener('click', function () { post('clear', st.id, ''); });
      row.appendChild(t);
      row.appendChild(btn);
      box.appendChild(row);
    });
  }

  // ------------------------------------------------------------ naming

  function findSlot(id) {
    var hit = null;
    function walk(slots) {
      slots.forEach(function (sl) {
        if (hit) return;
        if (sl.id === id) { hit = sl; return; }
        sl.children.forEach(function (n) { if (n.type === 'hub') walk(n.slots); });
      });
    }
    state.map.controllers.forEach(function (c) { walk(c.slots); });
    return hit;
  }

  function openEdit(sl) {
    var box = $id('usbmap-edit');
    if (!box) return;
    state.editing = sl.id;
    var ctrl = sl.id.split('|')[0];
    $id('usbmap-edit-port').textContent = slotLabel(sl) + ' on ' + ctrl + ':';
    var input = $id('usbmap-edit-name');
    input.value = sl.name || '';
    box.hidden = false;
    input.focus();
    input.select();
  }

  function closeEdit() {
    var box = $id('usbmap-edit');
    if (box) box.hidden = true;
    state.editing = '';
  }

  function post(action, id, name) {
    if (!id) return;
    msg('Saving\u2026');
    $.post(SAVE, { action: action, id: id, name: name }, function (r) {
      if (r && r.ok) {
        msg(r.changed ? 'Saved.' : 'No change.');
        closeEdit();
        refresh();
      } else {
        msg((r && r.error) || 'Not saved.');
      }
    }, 'json').fail(function (x) {
      var e = x && x.responseJSON && x.responseJSON.error;
      msg(e || 'Not saved.');
    });
  }

  // ------------------------------------------------------------ data

  function refresh(after) {
    $.ajax({ url: GET, data: { a: 'map' }, dataType: 'json', cache: false }).done(function (m) {
      state.map = m;
      state.hash = m.hash;
      draw();
      if (after) after();
    }).fail(function () { msg('Could not load the map.'); });
  }

  function focusSlot(id) {
    var g = document.querySelector('#usbmap-wrap [data-slot="' + (window.CSS && CSS.escape ? CSS.escape(id) : id.replace(/["\\]/g, '\\$&')) + '"]');
    if (g && g.scrollIntoView) g.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
  }

  function onNewDevice(dev) {
    refresh(function () {
      var id = state.map.dev_slot[dev];
      if (!id) { msg('New device ' + dev + ': port not found.'); return; }
      state.highlight = id;
      draw();
      focusSlot(id);
      var sl = findSlot(id);
      if (sl) openEdit(sl);
      msg('Device ' + dev + ' found. Name its port.');
    });
  }

  function poll() {
    state.timer = null;
    if (!state.identify) return;
    if (Date.now() > state.stopAt) { setIdentify(false); msg('Identify stopped after 10 minutes.'); return; }
    if (document.hidden) return;
    if (state.busy) { schedule(); return; }
    state.busy = true;
    $.ajax({ url: GET, data: { a: 'watch' }, dataType: 'json', cache: false }).done(function (w) {
      var now = {};
      w.devices.forEach(function (k) { now[k] = true; });
      if (state.known === null) {
        state.known = now;
        state.hash = w.hash;
        return;
      }
      if (w.hash === state.hash) return;
      var added = w.devices.filter(function (k) { return !state.known[k]; });
      state.known = now;
      state.hash = w.hash;
      if (!added.length) { refresh(); return; }
      var pick = added[0];
      added.forEach(function (k) { if (k.split('@')[0] === w.newest) pick = k; });
      onNewDevice(pick.split('@')[0]);
    }).always(function () {
      state.busy = false;
      schedule();
    });
  }

  function schedule() {
    if (state.timer || !state.identify || document.hidden) return;
    state.timer = setTimeout(poll, POLL_MS);
  }

  function setIdentify(on) {
    state.identify = on;
    var b = $id('usbmap-identify');
    if (b) b.value = on ? 'Stop identify' : 'Identify port';
    if (state.timer) { clearTimeout(state.timer); state.timer = null; }
    if (on) {
      state.known = null;
      state.stopAt = Date.now() + IDENTIFY_MAX_MS;
      msg('Plug a device into the port you want to name.');
      poll();
    } else {
      state.highlight = '';
      draw();
    }
  }

  function exportAs(fmt) {
    window.location.href = GET + '?a=export&fmt=' + encodeURIComponent(fmt);
  }

  function init() {
    state.map = window.usbmapInitial || null;
    if (state.map) state.hash = state.map.hash;
    draw();
    $id('usbmap-identify').addEventListener('click', function () {
      setIdentify(!state.identify);
      if (!state.identify) msg('');
    });
    $id('usbmap-refresh').addEventListener('click', function () { refresh(); });
    $id('usbmap-unused').addEventListener('change', function (ev) {
      state.showUnused = ev.target.checked;
      draw();
    });
    $id('usbmap-export-text').addEventListener('click', function () { exportAs('text'); });
    $id('usbmap-export-json').addEventListener('click', function () { exportAs('json'); });
    $id('usbmap-edit-save').addEventListener('click', function () {
      post('set', state.editing, $id('usbmap-edit-name').value);
    });
    $id('usbmap-edit-clear').addEventListener('click', function () { post('clear', state.editing, ''); });
    $id('usbmap-edit-cancel').addEventListener('click', closeEdit);
    $id('usbmap-edit-name').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); post('set', state.editing, ev.target.value); }
      if (ev.key === 'Escape') closeEdit();
    });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) schedule();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
