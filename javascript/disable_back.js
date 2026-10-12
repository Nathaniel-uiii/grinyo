// Enforce the short retry countdown after repeated failed login attempts.
(function enforceLockRedirect(){
  try {
    var raw = null;
    try { raw = localStorage.getItem('lock_deadline'); } catch(e) { raw = null; }
    var deadline = raw ? parseInt(raw, 10) : 0;
    if (!deadline || isNaN(deadline) || deadline <= Date.now()) return;

    var path = ((window.location && window.location.pathname) || '').toLowerCase();
    if (!/login\.php$/.test(path)) {
      window.location.replace('./login.php');
    }
  } catch(e) {}
})();

