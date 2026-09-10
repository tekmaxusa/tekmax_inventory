(function () {
  const origFetch = window.fetch;
  window.fetch = function (input, init) {
    init = init ? Object.assign({}, init) : {};
    const method = String(init.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS' && window.CSRF_TOKEN) {
      const headers = new Headers(init.headers || {});
      if (!headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-Token', window.CSRF_TOKEN);
      }
      init.headers = headers;
    }
    return origFetch.call(this, input, init);
  };

  document.getElementById('passwordForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const err = document.getElementById('pwError');
    err.classList.add('d-none');
    const res = await fetch((window.APP_BASE || '/inventory-system') + '/api/users.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'password',
        current_password: document.getElementById('pw_current').value,
        new_password: document.getElementById('pw_new').value,
      }),
    });
    const data = await res.json();
    if (!data.ok) {
      err.textContent = data.error || 'Update failed';
      err.classList.remove('d-none');
      return;
    }
    document.getElementById('passwordForm').reset();
    const modal = bootstrap.Modal.getInstance(document.getElementById('passwordModal'));
    modal?.hide();
    const wrap = document.getElementById('toastWrap') || document.body;
    const el = document.createElement('div');
    el.className = 'app-toast ok';
    el.textContent = 'Password updated';
    wrap.appendChild(el);
    setTimeout(function () { el.remove(); }, 2500);
  });
})();
