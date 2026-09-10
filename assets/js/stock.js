(function () {
  const API = (window.APP_BASE || '/inventory-system') + '/api/stock_movements.php';

  function toast(msg, type) {
    const wrap = document.getElementById('toastWrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'app-toast ' + (type || 'ok');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.remove(); }, 2800);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  async function loadMovements() {
    const qs = new URLSearchParams();
    const product = document.getElementById('filterProduct').value;
    const type = document.getElementById('filterType').value;
    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    if (product) qs.set('product_id', product);
    if (type) qs.set('type', type);
    if (from) qs.set('from', from);
    if (to) qs.set('to', to);

    const res = await fetch(API + '?' + qs.toString());
    const data = await res.json();
    const body = document.getElementById('movementsBody');
    if (!data.ok || !data.movements.length) {
      body.innerHTML = '<tr><td colspan="7" class="empty-state">No movements found.</td></tr>';
      return;
    }
    body.innerHTML = data.movements.map(function (m) {
      const badge = m.movement_type === 'in'
        ? '<span class="badge-pill badge-green">IN</span>'
        : '<span class="badge-pill badge-red">OUT</span>';
      const when = new Date(m.created_at.replace(' ', 'T'));
      const whenStr = isNaN(when) ? m.created_at : when.toLocaleString();
      return '<tr>' +
        '<td><strong>' + esc(m.product_name) + '</strong><div class="text-muted-sm">' + esc(m.sku) + '</div></td>' +
        '<td>' + badge + '</td>' +
        '<td class="num">' + m.quantity + '</td>' +
        '<td>' + esc(m.reason || '—') + '</td>' +
        '<td>' + esc(m.reference_no || '—') + '</td>' +
        '<td>' + esc(m.performed_by_name || '—') + '</td>' +
        '<td class="text-muted-sm">' + esc(whenStr) + '</td>' +
        '</tr>';
    }).join('');
  }

  const form = document.getElementById('stockForm');
  if (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const err = document.getElementById('stockError');
      err.classList.add('d-none');
      const payload = {
        product_id: Number(document.getElementById('product_id').value),
        movement_type: document.querySelector('input[name="movement_type"]:checked').value,
        quantity: Number(document.getElementById('quantity').value),
        reason: document.getElementById('reason').value,
        reference_no: document.getElementById('reference_no').value.trim(),
      };
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!data.ok) {
        err.textContent = data.error || 'Failed';
        err.classList.remove('d-none');
        toast(data.error || 'Failed', 'err');
        return;
      }
      toast('Recorded — new qty: ' + data.system_qty);
      form.reset();
      document.getElementById('typeIn').checked = true;
      document.getElementById('quantity').value = 1;
      // refresh option label qty
      const opt = document.querySelector('#product_id option[value="' + payload.product_id + '"]');
      if (opt) {
        opt.dataset.qty = data.system_qty;
        opt.textContent = opt.textContent.replace(/qty \d+/, 'qty ' + data.system_qty);
      }
      loadMovements();
    });
  }

  document.getElementById('btnFilter')?.addEventListener('click', loadMovements);
  loadMovements();

  window.onBulkImportSuccess = function () {
    loadMovements();
    setTimeout(function () { window.location.reload(); }, 700);
  };
})();
