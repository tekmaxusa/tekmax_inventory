<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_admin();

$pageTitle = 'Users';
$pageSubtitle = 'Create accounts and assign admin or staff access';
$currentPage = 'users';
ob_start();
?>
<button type="button" class="btn btn-accent" id="btnAddUser" data-bs-toggle="modal" data-bs-target="#userModal">
  <i class="bi bi-plus-lg me-1"></i> Add User
</button>
<?php
$topbarActions = ob_get_clean();
require __DIR__ . '/includes/header.php';
?>

<div class="table-panel">
  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="usersBody"><tr><td colspan="5" class="empty-state">Loading…</td></tr></tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="userForm">
      <div class="modal-header">
        <h5 class="modal-title" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="user_id">
        <div class="mb-3">
          <label class="form-label">Name *</label>
          <input class="form-control" id="user_name" required maxlength="100">
        </div>
        <div class="mb-3">
          <label class="form-label">Email *</label>
          <input class="form-control" type="email" id="user_email" required maxlength="150">
        </div>
        <div class="mb-3">
          <label class="form-label">Role *</label>
          <select class="form-select" id="user_role">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
          </select>
          <div class="form-text">Staff can count, stock in/out, and edit items. Only admins manage users, categories, deletes, and closing audits.</div>
        </div>
        <div class="mb-3" id="userActiveWrap">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="user_is_active" checked>
            <label class="form-check-label" for="user_is_active">Active</label>
          </div>
        </div>
        <div class="mb-0">
          <label class="form-label" id="userPasswordLabel">Password *</label>
          <div class="password-field-wrap">
            <input class="form-control" type="password" id="user_password" minlength="8" autocomplete="new-password">
            <button type="button" class="password-toggle-btn" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
          </div>
          <div class="form-text" id="userPasswordHelp">Minimum 8 characters.</div>
        </div>
        <div class="text-danger small mt-2 d-none" id="userError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-accent">Save</button>
      </div>
    </form>
  </div>
</div>
<div class="toast-wrap" id="toastWrap"></div>

<?php
ob_start();
?>
<script>
const API = (window.APP_BASE || '/inventory-system') + '/api/users.php';
const modal = new bootstrap.Modal('#userModal');
let editing = false;

function toast(msg, type='ok') {
  const el = document.createElement('div');
  el.className = 'app-toast ' + type;
  el.textContent = msg;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => el.remove(), 2500);
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

async function load() {
  const res = await fetch(API);
  const data = await res.json();
  const body = document.getElementById('usersBody');
  if (!data.ok || !data.users.length) {
    body.innerHTML = '<tr><td colspan="5" class="empty-state">No users yet.</td></tr>';
    return;
  }
  body.innerHTML = data.users.map(u => {
    const role = u.role === 'admin'
      ? '<span class="badge-pill badge-blue">Admin</span>'
      : '<span class="badge-pill badge-gray">Staff</span>';
    const status = u.is_active
      ? '<span class="badge-pill badge-green">Active</span>'
      : '<span class="badge-pill badge-red">Disabled</span>';
    return `<tr>
      <td><strong>${esc(u.name)}</strong></td>
      <td>${esc(u.email)}</td>
      <td>${role}</td>
      <td>${status}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-ghost btn-edit" data-id="${u.user_id}"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-ghost text-danger btn-del" data-id="${u.user_id}" ${u.is_active ? '' : 'disabled'}><i class="bi bi-person-x"></i></button>
      </td>
    </tr>`;
  }).join('');
  window.__users = data.users;
}

document.getElementById('btnAddUser').addEventListener('click', () => {
  editing = false;
  document.getElementById('userModalTitle').textContent = 'Add User';
  document.getElementById('user_id').value = '';
  document.getElementById('user_name').value = '';
  document.getElementById('user_email').value = '';
  document.getElementById('user_role').value = 'staff';
  document.getElementById('user_is_active').checked = true;
  document.getElementById('user_password').value = '';
  document.getElementById('user_password').required = true;
  document.getElementById('userPasswordLabel').textContent = 'Password *';
  document.getElementById('userPasswordHelp').textContent = 'Minimum 8 characters.';
  document.getElementById('userError').classList.add('d-none');
});

document.getElementById('usersBody').addEventListener('click', async (e) => {
  const edit = e.target.closest('.btn-edit');
  const del = e.target.closest('.btn-del');
  if (edit) {
    const u = (window.__users || []).find(x => Number(x.user_id) === Number(edit.dataset.id));
    if (!u) return;
    editing = true;
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('user_id').value = u.user_id;
    document.getElementById('user_name').value = u.name;
    document.getElementById('user_email').value = u.email;
    document.getElementById('user_role').value = u.role;
    document.getElementById('user_is_active').checked = !!u.is_active;
    document.getElementById('user_password').value = '';
    document.getElementById('user_password').required = false;
    document.getElementById('userPasswordLabel').textContent = 'New password';
    document.getElementById('userPasswordHelp').textContent = 'Leave blank to keep the current password.';
    document.getElementById('userError').classList.add('d-none');
    modal.show();
  }
  if (del) {
    if (!confirm('Disable this user? They will no longer be able to sign in.')) return;
    const res = await fetch(API, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({_method:'DELETE', user_id: Number(del.dataset.id)})
    });
    const data = await res.json();
    if (data.ok) { toast('User disabled'); load(); } else toast(data.error||'Failed','err');
  }
});

document.getElementById('userForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = document.getElementById('userError');
  err.classList.add('d-none');
  const payload = {
    user_id: document.getElementById('user_id').value || undefined,
    name: document.getElementById('user_name').value.trim(),
    email: document.getElementById('user_email').value.trim(),
    role: document.getElementById('user_role').value,
    is_active: document.getElementById('user_is_active').checked ? 1 : 0,
    password: document.getElementById('user_password').value,
  };
  if (editing) payload._method = 'PUT';
  if (!editing && payload.password.length < 8) {
    err.textContent = 'Password must be at least 8 characters.';
    err.classList.remove('d-none');
    return;
  }
  const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  const data = await res.json();
  if (!data.ok) { err.textContent = data.error||'Failed'; err.classList.remove('d-none'); return; }
  modal.hide(); toast('Saved'); load();
});

load();
</script>
<?php
$pageScripts = ob_get_clean();
require __DIR__ . '/includes/footer.php';
