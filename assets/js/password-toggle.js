(function () {
  function initPasswordToggles(root) {
    (root || document).querySelectorAll('.password-field-wrap').forEach(function (wrap) {
      var input = wrap.querySelector('input[type="password"], input[data-password-input]');
      var btn = wrap.querySelector('.password-toggle-btn');
      if (!input || !btn || btn.dataset.bound === '1') {
        return;
      }
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        var icon = btn.querySelector('i');
        if (icon) {
          icon.classList.toggle('bi-eye', !show);
          icon.classList.toggle('bi-eye-slash', show);
        }
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { initPasswordToggles(); });
  } else {
    initPasswordToggles();
  }

  window.initPasswordToggles = initPasswordToggles;
})();
