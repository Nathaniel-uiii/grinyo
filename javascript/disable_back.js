// Global lockout enforcement: redirect any protected page back to login
// while a lock_deadline is still active in localStorage.
(function enforceLockRedirect(){
  try {
    var raw = null;
    try { raw = localStorage.getItem('lock_deadline'); } catch(e) { raw = null; }
    var dl = raw ? parseInt(raw, 10) : 0;
    var now = Date.now();
    if (!dl || isNaN(dl) || dl <= now) {
      // No active lock; nothing to enforce on this page
      return;
    }

    var loc = window.location || {};
    var path = (loc.pathname || '').toLowerCase();

    // If we're not already on the login page, force redirect back to it.
    if (!/login\.php$/.test(path)) {
      try {
        // Hide content immediately to minimize any visible flicker
        if (document && document.documentElement) {
          document.documentElement.style.visibility = 'hidden';
        }
      } catch(e) {}
      try {
        // Use relative path so it works from views folder
        window.location.replace('./login.php');
      } catch(e) {}
    }
  } catch(e) {}
})();

