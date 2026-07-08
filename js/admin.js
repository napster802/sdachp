/* ============================================================
   Bible Challenge Arena - Admin Gate
   Login posts the passcode to api/admin.php once; the server
   checks it against ADMIN_PASSCODE (never shipped to the client)
   and issues a session token, which is what every subsequent
   admin action sends instead of the passcode itself.
   ============================================================ */
const Admin = (function () {
  const TOKEN_KEY = 'bca_admin_token';

  let onSuccess = null;

  function getToken() {
    return sessionStorage.getItem(TOKEN_KEY) || '';
  }

  function isAuthenticated() {
    return !!getToken();
  }

  function requireAdmin(callback) {
    if (isAuthenticated()) {
      callback();
      return;
    }
    onSuccess = callback;
    openLoginOverlay();
  }

  function openLoginOverlay() {
    const overlay = document.getElementById('overlay-admin-login');
    const error = document.getElementById('admin-login-error');
    const passInput = document.getElementById('admin-password-input');
    if (error) error.textContent = '';
    if (passInput) passInput.value = '';
    if (overlay) overlay.style.display = 'flex';
    if (passInput) passInput.focus();
  }

  function closeLoginOverlay() {
    const overlay = document.getElementById('overlay-admin-login');
    if (overlay) overlay.style.display = 'none';
    onSuccess = null;
  }

  function attemptLogin() {
    const passInput = document.getElementById('admin-password-input');
    const error = document.getElementById('admin-login-error');
    const pass = passInput ? passInput.value : '';

    if (error) error.textContent = 'Signing in…';

    fetch('api/admin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'login', passcode: pass })
    }).then(r => r.json()).then(res => {
      if (!res.success) {
        if (error) error.textContent = res.error || 'Incorrect passcode.';
        return;
      }
      sessionStorage.setItem(TOKEN_KEY, res.token);
      const overlay = document.getElementById('overlay-admin-login');
      if (overlay) overlay.style.display = 'none';
      const callback = onSuccess;
      onSuccess = null;
      if (callback) callback();
    }).catch(err => {
      if (error) error.textContent = 'Could not reach the server: ' + err.message;
    });
  }

  function logout() {
    sessionStorage.removeItem(TOKEN_KEY);
  }

  return {
    isAuthenticated,
    requireAdmin,
    openLoginOverlay,
    closeLoginOverlay,
    attemptLogin,
    logout,
    getToken
  };
})();
