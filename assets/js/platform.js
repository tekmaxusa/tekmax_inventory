(function () {
  const BASE = window.APP_BASE || '/inventory-system';
  const CSRF = window.CSRF_TOKEN || '';

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function toast(msg) {
    const wrap = document.getElementById('toastWrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'toast-msg';
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.classList.add('show'), 10);
    setTimeout(() => {
      el.classList.remove('show');
      setTimeout(() => el.remove(), 300);
    }, 3200);
  }

  async function api(action, payload) {
    const res = await fetch(BASE + '/api/platform.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(Object.assign({ action }, payload || {})),
    });
    return res.json();
  }

  async function loadOverview() {
    const res = await fetch(BASE + '/api/platform.php?view=overview');
    const data = await res.json();
    if (!data.ok) return;
    const o = data.overview;
    document.getElementById('kpiStores').textContent = o.stores_total;
    document.getElementById('kpiStoresActive').textContent = o.stores_active;
    document.getElementById('kpiUsers').textContent = o.users_active + ' / ' + o.users_total;
    document.getElementById('kpiProducts').textContent = o.products_total;
  }

  async function loadStores() {
    const res = await fetch(BASE + '/api/platform.php?view=stores');
    const data = await res.json();
    const body = document.getElementById('platformStoresBody');
    if (!data.ok || !data.stores.length) {
      body.innerHTML = '<tr><td colspan="7" class="empty-state">No stores yet.</td></tr>';
      return;
    }
    body.innerHTML = data.stores.map((s) => {
      const active = Number(s.is_active) === 1;
      const status = active
        ? '<span class="badge-pill badge-green">Active</span>'
        : '<span class="badge-pill badge-red">Suspended</span>';
      const owner = s.owner_name
        ? esc(s.owner_name) + '<div class="text-muted-sm">' + esc(s.owner_email || '') + '</div>'
        : '—';
      const toggleBtn = active
        ? '<button type="button" class="btn btn-sm btn-outline-warning" data-act="store_disable" data-id="' + s.store_id + '">Suspend</button>'
        : '<button type="button" class="btn btn-sm btn-outline-accent" data-act="store_enable" data-id="' + s.store_id + '">Reactivate</button>';
      return '<tr>' +
        '<td><strong>' + esc(s.store_name) + '</strong><div class="text-muted-sm">' + esc(s.business_type || '') + '</div></td>' +
        '<td>' + owner + '</td>' +
        '<td class="num">' + esc(s.user_count) + '</td>' +
        '<td class="num">' + esc(s.product_count) + '</td>' +
        '<td>' + status + '</td>' +
        '<td class="text-muted-sm">' + esc((s.created_at || '').slice(0, 10)) + '</td>' +
        '<td class="text-end"><div class="d-flex gap-1 justify-content-end flex-wrap">' +
          toggleBtn +
          '<button type="button" class="btn btn-sm btn-outline-danger" data-act="store_delete" data-id="' + s.store_id + '" data-name="' + esc(s.store_name) + '">Delete</button>' +
        '</div></td></tr>';
    }).join('');
  }

  async function loadUsers() {
    const res = await fetch(BASE + '/api/platform.php?view=users');
    const data = await res.json();
    const body = document.getElementById('platformUsersBody');
    if (!data.ok || !data.users.length) {
      body.innerHTML = '<tr><td colspan="6" class="empty-state">No users yet.</td></tr>';
      return;
    }
    body.innerHTML = data.users.map((u) => {
      const active = Number(u.is_active) === 1;
      const status = active
        ? '<span class="badge-pill badge-green">Active</span>'
        : '<span class="badge-pill badge-red">Disabled</span>';
      const toggleBtn = active
        ? '<button type="button" class="btn btn-sm btn-outline-warning" data-act="user_disable" data-id="' + u.user_id + '">Disable</button>'
        : '<button type="button" class="btn btn-sm btn-outline-accent" data-act="user_enable" data-id="' + u.user_id + '">Enable</button>';
      return '<tr>' +
        '<td><strong>' + esc(u.name) + '</strong></td>' +
        '<td>' + esc(u.email) + '</td>' +
        '<td class="text-muted-sm">' + esc(u.stores || '—') + '</td>' +
        '<td>' + esc(u.role) + '</td>' +
        '<td>' + status + '</td>' +
        '<td class="text-end"><div class="d-flex gap-1 justify-content-end flex-wrap">' +
          toggleBtn +
          '<button type="button" class="btn btn-sm btn-outline-danger" data-act="user_delete" data-id="' + u.user_id + '" data-name="' + esc(u.name) + '">Delete</button>' +
        '</div></td></tr>';
    }).join('');
  }

  document.getElementById('platformTabs').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    document.querySelectorAll('#platformTabs .filter-tab').forEach((t) => t.classList.remove('active'));
    btn.classList.add('active');
    const tab = btn.getAttribute('data-tab');
    document.getElementById('platformStoresPanel').classList.toggle('d-none', tab !== 'stores');
    document.getElementById('platformUsersPanel').classList.toggle('d-none', tab !== 'users');
  });

  document.body.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const act = btn.getAttribute('data-act');
    const id = Number(btn.getAttribute('data-id'));
    const name = btn.getAttribute('data-name') || '';
    let msg = 'Are you sure?';
    if (act === 'store_delete') msg = 'Permanently delete store "' + name + '" and ALL its inventory data?';
    if (act === 'user_delete') msg = 'Permanently delete user "' + name + '"?';
    if (!window.confirm(msg)) return;

    const payload = {};
    if (act.startsWith('store_')) payload.store_id = id;
    if (act.startsWith('user_')) payload.user_id = id;
    const res = await api(act, payload);
    if (!res.ok) {
      toast(res.error || 'Action failed');
      return;
    }
    toast(res.message || 'Done');
    await loadOverview();
    await loadStores();
    await loadUsers();
  });

  loadOverview();
  loadStores();
  loadUsers();
})();
