<?php
declare(strict_types=1);
?>
    </main>
  </div>
</div>

<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="passwordForm">
      <div class="modal-header">
        <h5 class="modal-title">Change password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Current password</label>
          <div class="password-field-wrap">
            <input class="form-control" type="password" id="pw_current" required autocomplete="current-password">
            <button type="button" class="password-toggle-btn" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
          </div>
        </div>
        <div class="mb-0">
          <label class="form-label">New password</label>
          <div class="password-field-wrap">
            <input class="form-control" type="password" id="pw_new" required minlength="8" autocomplete="new-password">
            <button type="button" class="password-toggle-btn" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
          </div>
          <div class="form-text">Minimum 8 characters.</div>
        </div>
        <div class="text-danger small mt-2 d-none" id="pwError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-accent">Update password</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/password-toggle.js')) ?>"></script>
<script src="<?= htmlspecialchars(app_url('assets/js/app.js')) ?>?v=20260823a"></script>
<script>
(function () {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  function close() {
    sidebar?.classList.remove('open');
    backdrop?.classList.remove('show');
  }
  toggle?.addEventListener('click', () => {
    sidebar?.classList.toggle('open');
    backdrop?.classList.toggle('show');
  });
  backdrop?.addEventListener('click', close);
})();
</script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
