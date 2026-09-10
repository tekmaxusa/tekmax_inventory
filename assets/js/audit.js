(function () {
  const BASE = window.APP_BASE || '/inventory-system';
  const toastWrap = document.getElementById('toastWrap');

  function toast(msg, type) {
    if (!toastWrap) return;
    const el = document.createElement('div');
    el.className = 'app-toast ' + (type || 'ok');
    el.textContent = msg;
    toastWrap.appendChild(el);
    setTimeout(function () { el.remove(); }, 3000);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function money(n) {
    const v = Number(n || 0);
    const sign = v > 0 ? '+' : '';
    return sign + '$' + Math.abs(v).toFixed(2);
  }

  // —— Start audit (landing) ——
  const startForm = document.getElementById('startAuditForm');
  if (startForm) {
    startForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const err = document.getElementById('startError');
      err.classList.add('d-none');
      const res = await fetch(BASE + '/api/audit_sessions.php?action=start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'start',
          store_name: document.getElementById('store_name').value.trim(),
          audit_date: document.getElementById('audit_date').value,
        }),
      });
      const data = await res.json();
      if (!data.ok) {
        err.textContent = data.error || 'Failed';
        err.classList.remove('d-none');
        return;
      }
      window.location.href = BASE + '/audit.php?session_id=' + data.session.session_id;
    });

    (async function loadHistory() {
      const res = await fetch(BASE + '/api/audit_sessions.php');
      const data = await res.json();
      const body = document.getElementById('auditHistoryBody');
      if (!data.ok || !data.sessions.length) {
        body.innerHTML = '<tr><td colspan="5" class="empty-state">No audits yet.</td></tr>';
        return;
      }
      body.innerHTML = data.sessions.map(function (s) {
        const resume = (s.status === 'in_progress' || s.status === 'saved')
          ? '<a class="btn btn-sm btn-outline-accent" href="' + BASE + '/audit.php?session_id=' + s.session_id + '">Open</a>'
          : '<a class="btn btn-sm btn-ghost" href="' + BASE + '/api/audit_export.php?session_id=' + s.session_id + '&format=csv">CSV</a>';
        return '<tr>' +
          '<td>' + esc(s.store_name) + '</td>' +
          '<td>' + esc(s.audit_date) + '</td>' +
          '<td><span class="badge-pill badge-gray">' + esc(s.status) + '</span></td>' +
          '<td class="text-muted-sm">' + esc(s.created_at) + '</td>' +
          '<td class="text-end">' + resume + '</td></tr>';
      }).join('');
    })();
    return;
  }

  // —— Count screen ——
  const app = document.getElementById('auditApp');
  if (!app) return;

  const sessionId = Number(app.dataset.sessionId);
  let counts = [];
  let method = 'manual';
  let filter = 'all';
  let search = '';
  const saveTimers = {};

  const statusBadge = {
    exact: '<span class="badge-pill badge-green">Exact</span>',
    shortage: '<span class="badge-pill badge-red">Shortage</span>',
    overage: '<span class="badge-pill badge-blue">Overage</span>',
    not_counted: '<span class="badge-pill badge-gray">Not Counted</span>',
  };

  function thumb(url, name) {
    if (url) return '<img class="prod-thumb" src="' + esc(url) + '" alt="">';
    return '<div class="prod-thumb placeholder">' + esc((name || '?').charAt(0).toUpperCase()) + '</div>';
  }

  function computeLocal(row) {
    if (row.physical_qty === null || row.physical_qty === '') {
      row.diff_qty = null;
      row.diff_value = null;
      row.status = 'not_counted';
      return;
    }
    const phys = Number(row.physical_qty);
    const sys = Number(row.system_qty);
    const diff = phys - sys;
    row.diff_qty = diff;
    row.diff_value = Math.round(diff * Number(row.unit_cost) * 100) / 100;
    row.status = diff === 0 ? 'exact' : (diff < 0 ? 'shortage' : 'overage');
  }

  function updateKpis() {
    const total = counts.length;
    let counted = 0, withDiff = 0, unitDiff = 0, valueDiff = 0;
    counts.forEach(function (r) {
      if (r.physical_qty === null || r.physical_qty === '') return;
      counted++;
      unitDiff += Number(r.diff_qty || 0);
      valueDiff += Number(r.diff_value || 0);
      if (Number(r.diff_qty) !== 0) withDiff++;
    });
    document.getElementById('kpiCounted').textContent = counted + ' / ' + total;
    document.getElementById('kpiDiffItems').textContent = String(withDiff);
    const unitEl = document.getElementById('kpiUnitDiff');
    unitEl.textContent = (unitDiff > 0 ? '+' : '') + unitDiff;
    unitEl.className = 'kpi-value ' + (unitDiff < 0 ? 'text-danger' : unitDiff > 0 ? 'text-primary' : '');
    const valEl = document.getElementById('kpiValueDiff');
    valEl.textContent = money(valueDiff);
    valEl.className = 'kpi-value ' + (valueDiff < 0 ? 'text-danger' : valueDiff > 0 ? 'text-primary' : '');

    document.getElementById('badgeAll').textContent = String(total);
    document.getElementById('badgeNot').textContent = String(total - counted);
    document.getElementById('badgeCounted').textContent = String(counted);
  }

  function visibleRows() {
    const q = search.toLowerCase();
    return counts.filter(function (r) {
      if (filter === 'not_counted' && r.physical_qty !== null && r.physical_qty !== '') return false;
      if (filter === 'counted' && (r.physical_qty === null || r.physical_qty === '')) return false;
      if (!q) return true;
      const hay = [r.product_name, r.sku, r.barcode, r.rfid_tag, r.location_tag].join(' ').toLowerCase();
      return hay.indexOf(q) !== -1;
    });
  }

  function diffClass(diff) {
    if (diff === null || diff === undefined) return 'diff-muted';
    if (diff === 0) return 'diff-exact';
    if (diff < 0) return 'diff-shortage';
    return 'diff-overage';
  }

  function render() {
    const rows = visibleRows();
    const body = document.getElementById('auditBody');
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="6" class="empty-state">No matching products.</td></tr>';
      updateKpis();
      return;
    }
    body.innerHTML = rows.map(function (r) {
      const phys = r.physical_qty === null || r.physical_qty === '' ? '' : r.physical_qty;
      const diffTxt = r.diff_qty === null || r.diff_qty === undefined ? '—' : (r.diff_qty > 0 ? '+' : '') + r.diff_qty;
      return '<tr data-product-id="' + r.product_id + '">' +
        '<td><div class="prod-cell">' + thumb(r.image_url, r.product_name) +
        '<div><strong>' + esc(r.product_name) + '</strong>' +
        '<div class="text-muted-sm">' + esc(r.sku) + '</div></div></div></td>' +
        '<td><span class="loc-pill">' + esc(r.location_tag || '—') + '</span></td>' +
        '<td class="num">' + r.system_qty + '</td>' +
        '<td class="num"><input type="number" min="0" class="form-control form-control-sm phys-input" ' +
        'data-product-id="' + r.product_id + '" value="' + phys + '" ' +
        (method === 'manual' ? '' : 'readonly') + '></td>' +
        '<td class="num ' + diffClass(r.diff_qty) + '">' + diffTxt + '</td>' +
        '<td>' + (statusBadge[r.status] || statusBadge.not_counted) + '</td></tr>';
    }).join('');
    updateKpis();
  }

  async function loadCounts() {
    const res = await fetch(BASE + '/api/audit_counts.php?session_id=' + sessionId);
    const data = await res.json();
    if (!data.ok) {
      toast(data.error || 'Failed to load', 'err');
      return;
    }
    counts = data.counts;
    render();
  }

  async function persistCount(productId, physicalQty, countMethod, increment) {
    const payload = {
      session_id: sessionId,
      product_id: productId,
      count_method: countMethod || method,
    };
    if (increment) payload.increment = 1;
    else payload.physical_qty = physicalQty;

    const res = await fetch(BASE + '/api/audit_counts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) {
      toast(data.error || 'Save failed', 'err');
      return null;
    }
    const idx = counts.findIndex(function (c) { return Number(c.product_id) === Number(productId); });
    if (idx >= 0) {
      counts[idx].physical_qty = data.count.physical_qty;
      counts[idx].diff_qty = data.count.diff_qty;
      counts[idx].diff_value = data.count.diff_value;
      counts[idx].status = data.count.status;
      counts[idx].count_method = data.count.count_method;
      if (data.count.image_url) counts[idx].image_url = data.count.image_url;
    }
    return data.count;
  }

  document.getElementById('auditBody').addEventListener('input', function (e) {
    const input = e.target.closest('.phys-input');
    if (!input || method !== 'manual') return;
    const productId = Number(input.dataset.productId);
    const raw = input.value;
    const row = counts.find(function (c) { return Number(c.product_id) === productId; });
    if (!row) return;
    row.physical_qty = raw === '' ? null : Math.max(0, Number(raw));
    computeLocal(row);
    // update diff/status cells without full re-render
    const tr = input.closest('tr');
    if (tr) {
      const diffTd = tr.children[4];
      const statusTd = tr.children[5];
      const d = row.diff_qty;
      diffTd.className = 'num ' + diffClass(d);
      diffTd.textContent = d === null ? '—' : ((d > 0 ? '+' : '') + d);
      statusTd.innerHTML = statusBadge[row.status];
    }
    updateKpis();

    clearTimeout(saveTimers[productId]);
    saveTimers[productId] = setTimeout(async function () {
      await persistCount(productId, row.physical_qty, 'manual', false);
    }, 400);
  });

  // Method cards + barcode/RFID scanning
  const barcodePanel = document.getElementById('barcodePanel');
  const rfidPanel = document.getElementById('rfidPanel');
  const scanInput = document.getElementById('scanInput');
  const rfidScanInput = document.getElementById('rfidScanInput');
  const lastScan = document.getElementById('lastScan');
  const lastRfidScan = document.getElementById('lastRfidScan');
  let scanTimer = null;
  let rfidTimer = null;
  let lastHandledCode = '';
  let lastHandledAt = 0;

  function setMethod(next) {
    method = next;
    document.querySelectorAll('.method-card').forEach(function (c) {
      c.classList.toggle('active', c.dataset.method === method);
    });
    barcodePanel?.classList.toggle('d-none', method !== 'barcode');
    rfidPanel?.classList.toggle('d-none', method !== 'rfid');
    if (method !== 'rfid') {
      stopHidListen();
      disconnectSerial();
      setRfidStatus('idle', 'Waiting for USB reader', 'Tap a tag — HID readers type the EPC then Enter.');
    }
    render();
    if (method === 'barcode') {
      setTimeout(function () { scanInput?.focus(); }, 40);
      toast('Barcode mode — USB/Bluetooth scanner or type + Enter');
    } else if (method === 'rfid') {
      startHidListen();
      toast('RFID mode — USB reader listening. Connect serial if needed.');
    }
  }

  function normalizeCode(raw) {
    return String(raw || '').trim().replace(/\s+/g, '');
  }

  function findByCode(code) {
    const needle = normalizeCode(code).toLowerCase();
    if (!needle) return null;
    const needleNoZero = needle.replace(/^0+/, '');
    return counts.find(function (c) {
      const codes = [];
      if (Array.isArray(c.barcodes)) {
        c.barcodes.forEach(function (b) { codes.push(normalizeCode(b).toLowerCase()); });
      }
      codes.push(normalizeCode(c.barcode).toLowerCase());
      codes.push(normalizeCode(c.sku).toLowerCase());
      const tag = normalizeCode(c.rfid_tag).toLowerCase();
      if (tag) codes.push(tag);
      return codes.some(function (v) {
        return v && (v === needle || v.replace(/^0+/, '') === needleNoZero);
      });
    }) || null;
  }

  function showFeedback(el, ok, message, product) {
    if (!el) return;
    el.classList.remove('d-none', 'err', 'scan-flash');
    if (!ok) el.classList.add('err');

    if (!ok || !product) {
      el.innerHTML = '<div class="scan-result-meta">' + esc(message) + '</div>';
      return;
    }

    const img = product.image_url
      ? '<img class="scan-result-img" src="' + esc(product.image_url) + '" alt="">'
      : '<div class="scan-result-img placeholder">' + esc((product.product_name || '?').charAt(0).toUpperCase()) + '</div>';

    el.innerHTML =
      img +
      '<div class="scan-result-meta">' +
        '<strong>' + esc(product.product_name || '') + '</strong>' +
        '<div class="text-muted-sm">' + esc(product.sku || '') +
          (product.barcode ? ' · ' + esc(product.barcode) : '') +
        '</div>' +
        '<div class="scan-qty">Physical qty: ' + esc(String(product.physical_qty ?? '')) + '</div>' +
        '<div class="text-muted-sm">' + esc(message) + '</div>' +
      '</div>';

    // retrigger animation
    void el.offsetWidth;
    el.classList.add('scan-flash');
  }

  function highlightScannedRow(productId) {
    document.querySelectorAll('#auditBody tr.scan-hit').forEach(function (tr) {
      tr.classList.remove('scan-hit');
    });
    const tr = document.querySelector('#auditBody tr[data-product-id="' + productId + '"]');
    if (!tr) return;
    tr.classList.add('scan-hit');
    tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  let pendingScanFeedbackEl = null;
  let pendingScanInputEl = null;
  const quickAddModalEl = document.getElementById('quickAddModal');
  const quickAddModal = quickAddModalEl ? new bootstrap.Modal(quickAddModalEl) : null;
  const quickImageInput = document.getElementById('quick_image');
  const quickImagePreview = document.getElementById('quickImagePreview');
  const quickImagePlaceholder = document.getElementById('quickImagePlaceholder');

  function setQuickPreview(url) {
    if (!quickImagePreview || !quickImagePlaceholder) return;
    if (url) {
      quickImagePreview.src = url;
      quickImagePreview.classList.remove('d-none');
      quickImagePlaceholder.classList.add('d-none');
    } else {
      quickImagePreview.removeAttribute('src');
      quickImagePreview.classList.add('d-none');
      quickImagePlaceholder.classList.remove('d-none');
    }
  }

  function openQuickAddModal(code, source, feedbackEl, inputEl) {
    pendingScanFeedbackEl = feedbackEl;
    pendingScanInputEl = inputEl;
    document.getElementById('quickAddCodeLabel').textContent = code;
    document.getElementById('quickAddMethod').value = source || 'barcode';
    document.getElementById('quick_product_name').value = '';
    // Scan fills only the matching field — never auto-copy barcode into SKU
    document.getElementById('quick_sku').value = '';
    document.getElementById('quick_barcode').value = source === 'rfid' ? '' : code;
    const rfidField = document.getElementById('quick_rfid_tag');
    if (rfidField) rfidField.value = source === 'rfid' ? code : '';
    document.getElementById('quick_category_id').value = '';
    document.getElementById('quick_location_tag').value = '';
    document.getElementById('quick_unit_cost').value = '0';
    document.getElementById('quick_unit_price').value = '0';
    if (quickImageInput) quickImageInput.value = '';
    setQuickPreview(null);
    document.getElementById('quickAddError').classList.add('d-none');
    showFeedback(feedbackEl, false, 'Unknown code — add as new product with image');
    quickAddModal?.show();
    setTimeout(function () {
      document.getElementById('quick_product_name')?.focus();
    }, 250);
  }

  quickImageInput?.addEventListener('change', function () {
    const file = quickImageInput.files && quickImageInput.files[0];
    if (!file) {
      setQuickPreview(null);
      return;
    }
    setQuickPreview(URL.createObjectURL(file));
  });

  document.getElementById('quickAddForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const err = document.getElementById('quickAddError');
    err.classList.add('d-none');
    const file = quickImageInput?.files && quickImageInput.files[0];
    if (!file) {
      err.textContent = 'Product image is required.';
      err.classList.remove('d-none');
      return;
    }

    const skuVal = document.getElementById('quick_sku').value.trim();
    const barcodeVal = document.getElementById('quick_barcode').value.trim();
    if (!skuVal && !barcodeVal) {
      err.textContent = 'Provide a SKU and/or barcode.';
      err.classList.remove('d-none');
      return;
    }

    const fd = new FormData();
    fd.append('session_id', String(sessionId));
    fd.append('product_name', document.getElementById('quick_product_name').value.trim());
    fd.append('sku', skuVal);
    fd.append('barcode', barcodeVal);
    if (barcodeVal) fd.append('barcodes', JSON.stringify([barcodeVal]));
    fd.append('rfid_tag', (document.getElementById('quick_rfid_tag')?.value || '').trim());
    fd.append('category_id', document.getElementById('quick_category_id').value);
    fd.append('location_tag', document.getElementById('quick_location_tag').value.trim());
    fd.append('unit_cost', document.getElementById('quick_unit_cost').value || '0');
    fd.append('unit_price', document.getElementById('quick_unit_price').value || '0');
    fd.append('count_method', document.getElementById('quickAddMethod').value || 'barcode');
    fd.append('image', file);

    const btn = document.getElementById('quickAddSaveBtn');
    btn.disabled = true;
    try {
      const res = await fetch(BASE + '/api/audit_quick_add.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) {
        err.textContent = data.error || 'Create failed';
        err.classList.remove('d-none');
        return;
      }
      quickAddModal?.hide();
      await loadCounts();
      const productView = {
        product_name: data.count.product_name,
        sku: data.count.sku,
        barcode: data.count.barcode,
        physical_qty: data.count.physical_qty,
        image_url: data.count.image_url || '',
      };
      showFeedback(pendingScanFeedbackEl || lastScan, true, 'New product created & counted', productView);
      toast('Created: ' + productView.product_name);
      highlightScannedRow(data.count.product_id);
      pendingScanInputEl?.focus();
    } finally {
      btn.disabled = false;
    }
  });

  quickAddModalEl?.addEventListener('hidden.bs.modal', function () {
    pendingScanInputEl?.focus();
  });

  async function handleScanCode(rawCode, source, inputEl, feedbackEl) {
    const code = normalizeCode(rawCode);
    if (!code) return;

    const now = Date.now();
    if (code === lastHandledCode && now - lastHandledAt < 700) {
      if (inputEl) inputEl.value = '';
      return;
    }
    lastHandledCode = code;
    lastHandledAt = now;
    if (inputEl) inputEl.value = '';

    let row = findByCode(code);

    // Not in current audit — check product catalog
    if (!row) {
      try {
        const lookupRes = await fetch(BASE + '/api/products.php?code=' + encodeURIComponent(code));
        const lookup = await lookupRes.json();

        // Deleted product — show clear error, do NOT open quick-add (unique key would conflict)
        if (lookup.ok && lookup.deleted) {
          showFeedback(feedbackEl, false, lookup.error || 'This product was deleted. Restore it first.');
          toast(lookup.error || 'Product is deleted — restore it first.', 'err');
          inputEl?.focus();
          return;
        }

        if (lookup.ok && lookup.found && lookup.product) {
          // Attach existing product to this audit and +1
          const updated = await persistCount(lookup.product.product_id, null, source || 'barcode', true);
          if (updated) {
            await loadCounts();
            const productView = {
              product_name: updated.product_name,
              sku: updated.sku,
              barcode: updated.barcode,
              physical_qty: updated.physical_qty,
              image_url: updated.image_url || lookup.product.image_url || '',
            };
            showFeedback(feedbackEl, true, 'Added to audit via ' + (source || 'barcode') + ' — +1', productView);
            toast(productView.product_name + ' → ' + productView.physical_qty);
            highlightScannedRow(lookup.product.product_id);
          }
          inputEl?.focus();
          return;
        }
      } catch (e) {
        console.error(e);
      }

      // Brand-new unknown code → quick add modal
      openQuickAddModal(code, source || 'barcode', feedbackEl, inputEl);
      return;
    }

    const updated = await persistCount(row.product_id, null, source || 'barcode', true);
    if (updated) {
      const productView = {
        product_name: updated.product_name || row.product_name,
        sku: updated.sku || row.sku,
        barcode: updated.barcode || row.barcode,
        physical_qty: updated.physical_qty,
        image_url: updated.image_url || row.image_url || '',
      };
      showFeedback(
        feedbackEl,
        true,
        'Scanned via ' + (source || 'barcode') + ' — +1',
        productView
      );
      toast(productView.product_name + ' → ' + productView.physical_qty);
      render();
      highlightScannedRow(row.product_id);
    }
    inputEl?.focus();
  }

  function bindWedgeInput(inputEl, source, feedbackEl, timerRefName) {
    if (!inputEl) return;
    inputEl.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (timerRefName === 'scan') clearTimeout(scanTimer);
      if (timerRefName === 'rfid') clearTimeout(rfidTimer);
      handleScanCode(inputEl.value, source, inputEl, feedbackEl);
    });
    inputEl.addEventListener('input', function () {
      if (timerRefName === 'scan') clearTimeout(scanTimer);
      if (timerRefName === 'rfid') clearTimeout(rfidTimer);
      const val = normalizeCode(inputEl.value);
      if (val.length < 4) return;
      const t = setTimeout(function () {
        handleScanCode(inputEl.value, source, inputEl, feedbackEl);
      }, 160);
      if (timerRefName === 'scan') scanTimer = t;
      else rfidTimer = t;
    });
  }

  document.getElementById('methodCards').addEventListener('click', function (e) {
    const card = e.target.closest('.method-card');
    if (!card) return;
    setMethod(card.dataset.method);
  });

  document.getElementById('filterTabs').addEventListener('click', function (e) {
    const tab = e.target.closest('.filter-tab');
    if (!tab) return;
    filter = tab.dataset.filter;
    document.querySelectorAll('.filter-tab').forEach(function (t) {
      t.classList.toggle('active', t === tab);
    });
    render();
  });

  document.getElementById('auditSearch').addEventListener('input', function (e) {
    search = e.target.value.trim();
    render();
  });

  document.getElementById('btnScanFind').addEventListener('click', function () {
    setMethod('barcode');
  });

  document.getElementById('btnScanFocus')?.addEventListener('click', function () {
    setMethod('barcode');
    scanInput?.focus();
  });

  document.getElementById('btnRfidFocus')?.addEventListener('click', function () {
    setMethod('rfid');
    startHidListen();
    rfidScanInput?.focus();
    toast('RFID ready — tap a tag');
  });

  bindWedgeInput(scanInput, 'barcode', lastScan, 'scan');
  bindWedgeInput(rfidScanInput, 'rfid', lastRfidScan, 'rfid');

  let rfidListening = false;
  let rfidHidBuffer = '';
  let rfidHidLast = 0;
  let rfidHidFlush = null;
  let rfidSerialPort = null;
  let rfidSerialReader = null;
  let rfidSerialKeep = false;

  function cleanRfidCode(raw) {
    return String(raw || '')
      .replace(/[\x00-\x1F\x7F]/g, '')
      .replace(/^\uFEFF/, '')
      .replace(/^(EP:|EPC:|TID:|TAG:)/i, '')
      .trim();
  }

  function setRfidStatus(state, title, hint) {
    const badge = document.getElementById('rfidReadyBadge');
    const dot = document.getElementById('rfidStatusDot');
    const t = document.getElementById('rfidStatusTitle');
    const h = document.getElementById('rfidStatusHint');
    if (t) t.textContent = title;
    if (h) h.textContent = hint;
    if (badge) {
      badge.textContent = state === 'serial' ? 'Serial connected' : state === 'listening' ? 'Listening' : state === 'error' ? 'Reader error' : 'Reader idle';
      badge.className = 'badge-pill ' + (state === 'error' ? 'badge-red' : state === 'idle' ? 'badge-gray' : 'badge-green');
    }
    if (dot) {
      dot.className = 'rfid-status-dot ' + state;
    }
  }

  function appendRfidLog(code) {
    const log = document.getElementById('rfidLiveLog');
    if (!log) return;
    log.classList.remove('d-none');
    const row = document.createElement('div');
    row.className = 'rfid-log-row';
    row.textContent = new Date().toLocaleTimeString() + '  ' + code;
    log.prepend(row);
    while (log.children.length > 8) log.lastChild.remove();
  }

  function flushHidBuffer() {
    const code = cleanRfidCode(rfidHidBuffer);
    rfidHidBuffer = '';
    if (code.length >= 4) {
      appendRfidLog(code);
      handleScanCode(code, 'rfid', rfidScanInput, lastRfidScan);
    }
  }

  function startHidListen() {
    rfidListening = true;
    setRfidStatus('listening', 'USB RFID reader listening', 'Tap a tag. HID readers type the EPC then Enter.');
    setTimeout(function () { rfidScanInput?.focus(); }, 40);
  }

  function stopHidListen() {
    rfidListening = false;
    rfidHidBuffer = '';
    clearTimeout(rfidHidFlush);
  }

  async function disconnectSerial() {
    rfidSerialKeep = false;
    try { await rfidSerialReader?.cancel(); } catch (e) { /* ignore */ }
    rfidSerialReader = null;
    try { await rfidSerialPort?.close(); } catch (e) { /* ignore */ }
    rfidSerialPort = null;
  }

  async function connectSerial() {
    if (!('serial' in navigator)) {
      toast('Serial RFID needs Chrome or Edge on this computer (localhost is OK).', 'err');
      setRfidStatus('error', 'Serial not supported', 'Use Chrome/Edge, or a USB HID/keyboard RFID reader.');
      return;
    }
    try {
      await disconnectSerial();
      const port = await navigator.serial.requestPort();
      const baud = Number(document.getElementById('rfidBaud')?.value || 9600);
      await port.open({ baudRate: baud });
      rfidSerialPort = port;
      rfidSerialKeep = true;
      setRfidStatus('serial', 'Serial reader connected', baud + ' baud — tap tags to count +1');
      toast('Serial RFID connected at ' + baud + ' baud');

      const decoder = new TextDecoderStream();
      const readable = port.readable.pipeThrough(decoder);
      const reader = readable.getReader();
      rfidSerialReader = reader;
      let lineBuf = '';
      while (rfidSerialKeep) {
        const { value, done } = await reader.read();
        if (done) break;
        lineBuf += value || '';
        const parts = lineBuf.split(/\r\n|\r|\n/);
        lineBuf = parts.pop() || '';
        parts.forEach(function (part) {
          const code = cleanRfidCode(part);
          if (code.length >= 4) {
            appendRfidLog(code);
            handleScanCode(code, 'rfid', rfidScanInput, lastRfidScan);
          }
        });
      }
    } catch (err) {
      if (err && err.name === 'NotFoundError') return;
      console.error(err);
      setRfidStatus('error', 'Could not open serial port', 'Check the cable, close other apps using the COM port, then try again.');
      toast('Serial RFID failed to connect', 'err');
    }
  }

  document.addEventListener('keydown', function (e) {
    if (method !== 'rfid' || !rfidListening) return;
    const target = e.target;
    const inModal = !!(target.closest && target.closest('.modal.show'));
    if (inModal) return;
    const typingField = target && (target.id === 'rfidCsv' || target.id === 'auditSearch');
    const now = Date.now();
    const rapid = now - rfidHidLast < 50;

    if (e.key === 'Enter') {
      if (rfidHidBuffer.length >= 4) {
        e.preventDefault();
        clearTimeout(rfidHidFlush);
        flushHidBuffer();
        return;
      }
      return;
    }
    if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return;
    if (typingField && !rapid) {
      rfidHidBuffer = '';
      return;
    }
    if (!rapid) rfidHidBuffer = '';
    rfidHidBuffer += e.key;
    rfidHidLast = now;
    if (rfidScanInput && (target !== rfidScanInput || rapid)) {
      rfidScanInput.value = rfidHidBuffer;
    }
    if (rapid && target !== rfidScanInput && target.id !== 'rfidCsv') {
      e.preventDefault();
    }
    clearTimeout(rfidHidFlush);
    rfidHidFlush = setTimeout(function () {
      if (rfidHidBuffer.length >= 8) flushHidBuffer();
    }, 90);
  });

  document.getElementById('btnRfidListen')?.addEventListener('click', function () {
    setMethod('rfid');
    startHidListen();
    toast('Listening for USB RFID reader');
  });

  document.getElementById('btnRfidSerial')?.addEventListener('click', function () {
    setMethod('rfid');
    startHidListen();
    connectSerial();
  });

  // Keep focus while in scan modes
  document.addEventListener('click', function (e) {
    if (method === 'barcode') {
      if (e.target.closest('input, textarea, select, button, a, .modal')) return;
      scanInput?.focus();
    } else if (method === 'rfid') {
      if (e.target.closest('input, textarea, select, button, a, .modal')) return;
      rfidScanInput?.focus();
    }
  });

  document.getElementById('btnRfidSample')?.addEventListener('click', function () {
    const sample = [
      'C9-1B',
      'C9-1B',
      'MMO-4OZ',
      'MMO-4OZ',
      'MMO-4OZ',
      '803868451092,2',
      '748378001020,18',
    ].join('\n');
    document.getElementById('rfidCsv').value = sample;
    toast('Sample RFID dump loaded — click Import bulk');
  });

  document.getElementById('btnRfidImport').addEventListener('click', async function () {
    const fileInput = document.getElementById('rfidFile');
    const form = new FormData();
    form.append('session_id', String(sessionId));
    form.append('mode', 'set');
    if (fileInput.files && fileInput.files[0]) {
      form.append('file', fileInput.files[0]);
    } else {
      form.append('csv', document.getElementById('rfidCsv').value);
    }
    const res = await fetch(BASE + '/api/audit_bulk_import.php', { method: 'POST', body: form });
    const data = await res.json();
    const result = document.getElementById('rfidResult');
    if (!data.ok) {
      result.textContent = data.error || 'Import failed';
      toast(data.error || 'Import failed', 'err');
      return;
    }
    const skipN = (data.skipped || []).length;
    let html = '<div class="mb-1">Updated <strong>' + data.updated + '</strong> product(s)' +
      (skipN ? ', skipped ' + skipN : '') + '.</div>';

    if (data.details && data.details.length) {
      // Featured first product with large image (same style as live scan)
      const first = data.details[0];
      const firstImg = first.image_url
        ? '<img class="scan-result-img" src="' + esc(first.image_url) + '" alt="">'
        : '<div class="scan-result-img placeholder">' + esc((first.product_name || '?').charAt(0).toUpperCase()) + '</div>';
      html += '<div class="last-scan scan-result scan-flash mb-2">' +
        firstImg +
        '<div class="scan-result-meta">' +
          '<strong>' + esc(first.product_name || '') + '</strong>' +
          '<div class="text-muted-sm">' + esc(first.sku || '') + '</div>' +
          '<div class="scan-qty">Physical qty: ' + esc(String(first.physical_qty)) + '</div>' +
          '<div class="text-muted-sm">Imported via RFID bulk</div>' +
        '</div></div>';

      html += '<div class="scan-bulk-thumbs">';
      data.details.forEach(function (d) {
        const img = d.image_url
          ? '<img src="' + esc(d.image_url) + '" alt="">'
          : '<span>' + esc((d.product_name || '?').charAt(0)) + '</span>';
        html += '<div class="scan-bulk-thumb" title="' + esc(d.product_name) + ' → qty ' + d.physical_qty + '">' +
          img + '</div>';
      });
      html += '</div>';

      // Also show in the main RFID last-scan card
      showFeedback(lastRfidScan, true, 'Imported via RFID bulk', {
        product_name: first.product_name,
        sku: first.sku,
        barcode: first.barcode,
        physical_qty: first.physical_qty,
        image_url: first.image_url || '',
      });
      if (first.product_id) highlightScannedRow(first.product_id);
    }

    result.innerHTML = html;
    toast('RFID bulk import complete — images loaded');
    await loadCounts();
    if (data.details && data.details[0]) {
      highlightScannedRow(data.details[0].product_id);
    }
    rfidScanInput?.focus();
  });

  // Save / Close
  document.getElementById('btnSaveAudit')?.addEventListener('click', async function () {
    const res = await fetch(BASE + '/api/audit_sessions.php?action=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', session_id: sessionId }),
    });
    const data = await res.json();
    if (data.ok) toast(data.message || 'Audit saved — Stock In/Out unlocked');
    else toast(data.error || 'Save failed', 'err');
  });

  const closeModal = document.getElementById('closeModal')
    ? new bootstrap.Modal('#closeModal')
    : null;

  document.getElementById('btnCloseAudit')?.addEventListener('click', function () {
    closeModal?.show();
  });

  document.getElementById('confirmClose')?.addEventListener('click', async function () {
    const res = await fetch(BASE + '/api/audit_sessions.php?action=close', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'close', session_id: sessionId }),
    });
    const data = await res.json();
    if (!data.ok) {
      toast(data.error || 'Close failed', 'err');
      return;
    }
    closeModal?.hide();
    toast('Audit closed — stock reconciled');
    setTimeout(function () {
      window.location.href = BASE + '/audit.php';
    }, 800);
  });

  loadCounts();
})();
