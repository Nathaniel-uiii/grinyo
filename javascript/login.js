  // Helper to cancel interactions while preserving hover/cursor
  function cancelInteract(el) {
    if (!el) return;
    var stopper = function(ev){ ev.preventDefault(); ev.stopImmediatePropagation(); return false; };
    // Store the handler so we can restore interactions later
    try { el.__stopperHandler = stopper; } catch(e) {}
    try { el.addEventListener('click', stopper, true); } catch(e) {}
    try { el.addEventListener('mousedown', stopper, true); } catch(e) {}
    try { el.addEventListener('mouseup', stopper, true); } catch(e) {}
    try { el.addEventListener('keydown', function(ev){ if (ev.key === 'Enter' || ev.key === ' ') stopper(ev); }, true); } catch(e) {}
  }

  // Helper to restore interactions that were disabled via cancelInteract
  function restoreInteract(el) {
    if (!el || !el.__stopperHandler) return;
    var stopper = el.__stopperHandler;
    try { el.removeEventListener('click', stopper, true); } catch(e) {}
    try { el.removeEventListener('mousedown', stopper, true); } catch(e) {}
    try { el.removeEventListener('mouseup', stopper, true); } catch(e) {}
    // keydown handler was anonymous when added, so we can't reliably remove it,
    // but click/mouse handlers are enough to restore routing behavior.
    try { delete el.__stopperHandler; } catch(e) { el.__stopperHandler = null; }
  }
// password show
var eyeicon = document.getElementById('eyeicon');
var password = document.getElementById('password');
if (eyeicon && password) {
  eyeicon.onclick = function () {
    if (password.type === 'password') {
      password.type = 'text';
      eyeicon.classList.remove('fa-eye-slash');
      eyeicon.classList.add('fa-eye');
    } else {
      password.type = 'password';
      eyeicon.classList.remove('fa-eye');
      eyeicon.classList.add('fa-eye-slash');
    }
  };
}

// Centralized Back-Button Guard helpers (apply/remove cleanly across cycles)
// Stores refs on window.__backGuard so we can remove listeners reliably.
(function initBackGuardHelpers(){
  if (window.__backGuardInitialized) return;
  window.__backGuardInitialized = true;

  window.__applyBackGuard = function(){
    try {
      // If already applied, do nothing
      if (window.__backGuard && window.__backGuard.applied) return;

      // Seed a sentinel state so popstate can be reliably detected across reloads
      var SENT = { lg: 1 };
      try { history.replaceState(SENT, document.title, window.location.href); } catch(e) {}

      var popHandler = function(ev){
        try {
          // If back is attempted while locked, force the browser to move forward
          // This prevents navigating to the previous page even after a reload.
          if (ev && ev.state && ev.state.lg === 1) {
            history.go(1);
          } else {
            // If state missing (some browsers), push a sentinel and try to neutralize
            history.pushState(SENT, document.title, window.location.href);
          }
        } catch(e) {}
      };
      var reapply = function(){
        try {
          // Ensure the current top entries carry the sentinel state
          window.history.pushState(SENT, document.title, window.location.href);
          window.history.pushState(SENT, document.title, window.location.href);
        } catch(e) {}
        window.onpopstate = popHandler;
      };
      // Initial push (twice) to create guard states
      try {
        window.history.pushState(SENT, document.title, window.location.href);
        window.history.pushState(SENT, document.title, window.location.href);
      } catch(e) {}
      // Attach listeners
      try { window.addEventListener('popstate', popHandler, true); } catch(e) {}
      try { window.addEventListener('pageshow', reapply, true); } catch(e) {}
      try {
        var kd = function(e){
          var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
          if (e.key === 'Backspace' && tag !== 'input' && tag !== 'textarea') e.preventDefault();
        };
        document.addEventListener('keydown', kd, true);
        // Hash-change safeguard to neutralize browser shortcuts/history gestures
        var hashHandler = function(){ try { history.pushState(null, document.title, window.location.href); } catch(e) {} };
        try { window.addEventListener('hashchange', hashHandler, true); } catch(e) {}
        window.__backGuard = { applied: true, popHandler: popHandler, reapply: reapply, keydown: kd, hashHandler: hashHandler };
      } catch(e) {
        window.__backGuard = { applied: true, popHandler: popHandler, reapply: reapply };
      }
      // Ensure reapply now
      reapply();
    } catch(e) {}
  };

  window.__removeBackGuard = function(){
    try {
      if (!window.__backGuard || !window.__backGuard.applied) return;
      var g = window.__backGuard;
      try { window.removeEventListener('popstate', g.popHandler, true); } catch(e) {}
      try { window.removeEventListener('pageshow', g.reapply, true); } catch(e) {}
      try { document.removeEventListener('keydown', g.keydown, true); } catch(e) {}
      try { window.removeEventListener('hashchange', g.hashHandler, true); } catch(e) {}
      try { window.onpopstate = null; } catch(e) {}
      window.__backGuard = { applied: false };
    } catch(e) {}
  };
})();

// Simplified showErrorMessage (adapted from register.js)
function showErrorMessage(fieldId, message) {
  var fieldInput = document.getElementById(fieldId);
  if (!fieldInput) return;

  var existing = document.getElementById(fieldId + '-error');
  if (existing) existing.remove();

  var errorDiv = document.createElement('div');
  errorDiv.id = fieldId + '-error';
  errorDiv.style.color = 'red';
  errorDiv.style.fontSize = '13px';
  errorDiv.style.marginTop = '1px';
  errorDiv.textContent = message;

  // Insert after the containing form-group to keep spacing consistent
  var parent = fieldInput.parentNode;
  // If the parent is a wrapper (like .password-wrapper), step up to its parent (.form-group)
  if (parent && parent.classList && parent.classList.contains('password-wrapper')) {
    parent = parent.parentNode;
  }
  if (parent && parent.parentNode) {
    parent.parentNode.insertBefore(errorDiv, parent.nextSibling);
  } else if (fieldInput.nextSibling) {
    fieldInput.parentNode.insertBefore(errorDiv, fieldInput.nextSibling);
  } else {
    fieldInput.parentNode.appendChild(errorDiv);
  }
}

function showPendingModal() {
  var modal = document.getElementById('pending-modal');
  var closeButton = document.getElementById('pending-modal-close');
  if (!modal) return;

  modal.hidden = false;
  document.body.classList.add('modal-open');
  if (closeButton) {
    closeButton.focus();
    closeButton.addEventListener('click', function closePendingModal() {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, window.location.pathname);
      }
    }, { once: true });
  }
}

// One-time success alert after password reset
(function handleResetSuccess() {
  var params = new URLSearchParams(window.location.search);
  var reset = params.get('reset');
  var flag = '0';
  try { flag = sessionStorage.getItem('reset_success') || '0'; } catch(e) { flag = '0'; }
  if (reset === 'success' || flag === '1') {
    try { alert('Password Changed Successfully'); } catch (e) {}
    // Clear the session flag if set
    try { sessionStorage.removeItem('reset_success'); } catch(e) {}
    // Strip the reset param from URL
    try {
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('reset');
        window.history.replaceState({}, document.title, url.pathname + (url.search ? ('?' + url.searchParams.toString()) : ''));
      }
    } catch(e) {}
  }
})();

// Required-field validation for login (inline, no refresh)
document.addEventListener('DOMContentLoaded', function(){
  var form = document.querySelector('form');
  var u = document.getElementById('username');
  var p = document.getElementById('password');
  if (!form) return;

  // If we just came from logout, clear any persisted credentials so fields
  // are empty for security.
  try {
    var ref = document.referrer || '';
    if (ref.indexOf('logout.php') !== -1) {
      sessionStorage.removeItem('login_username');
      sessionStorage.removeItem('login_password');
    }
  } catch (e) {}

  // Restore previously entered credentials so the form feels "live" even
  // after a server round-trip. Fresh visits/reloads will only keep these
  // values until the error handler clears them when there is no error.
  try {
    var savedU = sessionStorage.getItem('login_username');
    if (savedU !== null && u) {
      u.value = savedU;
    }
    var savedP = sessionStorage.getItem('login_password');
    if (savedP !== null && p) {
      p.value = savedP;
    }
  } catch (e) {}

  // Clear only the generic required message while typing
  form.addEventListener('input', function(ev){
    var t = ev.target;
    if (!t || (t.id !== 'username' && t.id !== 'password')) return;
    var v = (t.value || '').trim();
    if (v !== '') {
      var errEl = document.getElementById(t.id + '-error');
      if (errEl && /this field is required/i.test(errEl.textContent || '')) {
        if (errEl.parentNode) errEl.parentNode.removeChild(errEl);
      }
    }
  }, true);

  form.addEventListener('submit', function(e){
    var blocked = false;
    if (u && (u.value || '').trim() === '') {
      showErrorMessage('username', 'This field is required.');
      blocked = true;
    }
    if (p && (p.value || '').trim() === '') {
      showErrorMessage('password', 'This field is required.');
      blocked = true;
    }
    if (blocked) {
      e.preventDefault();
      if (u && (u.value || '').trim() === '') u.focus();
      else if (p) p.focus();
    }
  });

  // Persist credentials on input so they survive refresh and failed attempts
  if (u) {
    u.addEventListener('input', function(){
      try { sessionStorage.setItem('login_username', u.value); } catch(e) {}
    });
  }
  if (p) {
    p.addEventListener('input', function(){
      try { sessionStorage.setItem('login_password', p.value); } catch(e) {}
    });
  }
});

// One-time success alert after registration (shown on login page)
(function handleRegistrationSuccess() {
  var params = new URLSearchParams(window.location.search);
  var reg = params.get('registered');
  if (reg === 'success') {
    try { alert('Account Created Successfully!'); } catch (e) {}
    if (window.history && window.history.replaceState) {
      var url = window.location.pathname;
      window.history.replaceState({}, document.title, url);
    }
  }
})();

// Map server error flags to UI messages and manage attempts/Forgot Password
(function handleLoginErrors() {
  var params = new URLSearchParams(window.location.search);
  var err = params.get('error');
  var remainParam = params.get('remain');
  var remainSeconds = remainParam ? parseInt(remainParam, 10) : 0;
  var forgot = document.getElementById('forgot-section');
  var orSep = document.getElementById('or-separator');
  var lockoutTimer = document.getElementById('lockout-timer');

  if (!err) {
    // When there is no error in the URL, only clear persisted credentials
    // if there is no active lockout. The attempt counter itself is kept in
    // localStorage so it can survive reloads and page changes.
    try {
      var dlRaw = localStorage.getItem('lock_deadline') || '0';
      var dlMs = parseInt(dlRaw, 10) || 0;
      var nowMs = Date.now();
      if (!dlMs || dlMs <= nowMs) {
        sessionStorage.removeItem('login_username');
        sessionStorage.removeItem('login_password');
      }
    } catch (e) {}
    if (forgot) forgot.style.display = 'none';
    if (orSep) orSep.style.display = 'none';
    return;
  }

  // Show error messages (non-locked states)
  switch (err) {
    case 'user':
    case 'pass':
      // Unified credential error goes in the lockout-timer area
      if (lockoutTimer) {
        lockoutTimer.style.display = 'block';
        lockoutTimer.textContent = 'Incorrect username or password.';
      }
      break;
    case 'inactive':
      // Inactive-account message stays as a field-level error on username
      showErrorMessage('username', 'Your account is inactive.');
      break;
    case 'pending':
      showPendingModal();
      break;
    case 'blocked':
      if (lockoutTimer) {
        lockoutTimer.style.display = 'block';
        lockoutTimer.textContent = 'Your account is blocked.';
      }
      break;
  }

  // Account-status messages are informational and must not trigger lockout.
  if (err === 'pending' || err === 'blocked') {
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }
    return;
  }

  // Track consecutive errors (any non-locked error counts as a failed attempt)
  var count = 0;
  try { count = parseInt(localStorage.getItem('login_err_count') || '0', 10); } catch (e) {}
  count = isNaN(count) ? 0 : count;
  count += 1;
  try { localStorage.setItem('login_err_count', String(count)); } catch (e) {}

  // If this is the 3rd (or higher) failed attempt, start a client-side lockout
  if (count >= 3) {
    // Escalating lockout durations: 0 -> 15s, 1 -> 30s, 2+ -> 60s
    var lockDurations = [15, 30, 60];
    var level = 0;
    try {
      level = parseInt(localStorage.getItem('lock_level') || '0', 10);
      if (isNaN(level) || level < 0) level = 0;
    } catch (e) { level = 0; }
    var idx = level;
    if (idx < 0) idx = 0;
    if (idx >= lockDurations.length) idx = lockDurations.length - 1;
    var lockSeconds = lockDurations[idx];

    // Persist deadline for the current lock
    try {
      var nowMs2 = Date.now();
      localStorage.setItem('lock_deadline', String(nowMs2 + lockSeconds * 1000));
    } catch (e) {}

    // Increase lock_level for next time, capping at the last index
    try {
      var nextLevel = level + 1;
      var maxLevel = lockDurations.length - 1;
      if (nextLevel > maxLevel) nextLevel = maxLevel;
      localStorage.setItem('lock_level', String(nextLevel));
    } catch (e) {}

    // Redirect to the clean login URL; lockout state will be derived from
    // localStorage lock_deadline instead of query parameters.
    try {
      if (window.location && window.location.pathname) {
        window.location.replace(window.location.pathname);
        return;
      }
    } catch (e) {}
  }

  // Show Forgot Password when there have been at least 2 failed attempts
  if (count >= 2) {
    if (orSep) {
      orSep.style.display = 'flex';
      orSep.style.justifyContent = 'center';
      orSep.style.alignItems = 'center';
      orSep.style.margin = '8px 0 6px';
      orSep.style.color = orSep.style.color || '#9aa3b2';
    }
    if (forgot) {
      forgot.style.display = 'block';
      forgot.innerHTML = 'Forgot Password? <a href="./recover.php">Reset Here</a>';
    }
  } else {
    if (forgot) forgot.style.display = 'none';
    if (orSep) orSep.style.display = 'none';
  }

  // Clean the URL so the message and query parameters don't persist on
  // refresh. Even during lockout the timer and state are driven by
  // localStorage, so we can show a bare login.php in the address bar.
  if (window.history && window.history.replaceState) {
    var clean = window.location.pathname;
    window.history.replaceState({}, document.title, clean);
  }
})();

// Strong back-button prevention specifically for login lockout, adapted
// from the BookFinder system. This is enabled only while the lockout
// countdown is active on the login page.
var loginBackPreventionListener = null;

function enableLoginBackPrevention() {
  try {
    if (loginBackPreventionListener) return; // already enabled

    if (!(window.history && window.history.pushState)) return;

    // Replace current history entry and then create a buffer of states so
    // rapid Back clicks cannot escape the login page while locked.
    try {
      history.replaceState({ page: 'login-lock' }, '', window.location.href);
      for (var i = 0; i < 50; i++) {
        history.pushState({ page: 'login-lock' }, '', window.location.href);
      }
    } catch (e) {}

    loginBackPreventionListener = function (event) {
      try {
        // While active, any back navigation immediately pushes a new state,
        // effectively pinning the user on the current page.
        history.pushState({ page: 'login-lock' }, '', window.location.href);
      } catch (e) {}
    };

    try {
      window.addEventListener('popstate', loginBackPreventionListener, true);
    } catch (e) {}
  } catch (e) {}
}

function disableLoginBackPrevention() {
  try {
    if (!loginBackPreventionListener) return;
    try {
      window.removeEventListener('popstate', loginBackPreventionListener, true);
    } catch (e) {}
    loginBackPreventionListener = null;
  } catch (e) {}
}

// Lockout handling: show countdown, disable UI and back button
(function handleLockout() {
  var params = new URLSearchParams(window.location.search);
  var err = params.get('error');
  var remainStr = params.get('remain');
  var remain = remainStr ? parseInt(remainStr, 10) : 0;

  // Also look at localStorage lock_deadline so lockout can resume even when
  // error=locked is no longer present in the URL (e.g., after Back+redirect).
  var deadlineMsFromStorage = 0;
  try {
    deadlineMsFromStorage = parseInt((localStorage.getItem('lock_deadline')||'0'), 10) || 0;
  } catch(e) {
    deadlineMsFromStorage = 0;
  }
  var nowTs = Date.now();

  // If URL does not say "locked" but we still have an active lock_deadline,
  // treat this as a locked state and compute remaining seconds from deadline.
  if (err !== 'locked' && deadlineMsFromStorage && deadlineMsFromStorage > nowTs) {
    err = 'locked';
    remain = Math.max(1, Math.ceil((deadlineMsFromStorage - nowTs) / 1000));
  }
  var lockoutBox = document.getElementById('lockout');
  var form = document.querySelector('form');
  var navLinks = document.querySelectorAll('.nav-menu a, .logo-link');
  var registerLink = document.querySelector('.below a');
  var forgot = document.getElementById('forgot-section');
  var orSep = document.getElementById('or-separator');
  var lockoutTimer = document.getElementById('lockout-timer');

  // Proceed even if lockoutBox is missing (UI message removed), we still need to disable interactions
  if (err !== 'locked' || !form) {
    return;
  }

  // Disable form elements
  var inputs = form.querySelectorAll('input, button');
  inputs.forEach(function (el) {
    el.disabled = true;
    el.classList.add('disabled');
    if (el.tagName === 'BUTTON') {
      el.style.opacity = '0.6';
      el.style.cursor = 'not-allowed';
    }
  });

  // Make the password eye icon non-interactive while locked
  try {
    var eyeLock = document.getElementById('eyeicon');
    if (eyeLock) {
      eyeLock.style.pointerEvents = 'none';
      eyeLock.style.cursor = 'default';
    }
  } catch(e) {}

  // Ensure Forgot Password UI is visible while locked
  if (orSep) {
    orSep.style.display = 'flex';
    orSep.style.justifyContent = 'center';
    orSep.style.alignItems = 'center';
  }
  if (forgot) {
    forgot.style.display = 'block';
    // Disable the Forgot Password link while locked
    try {
      var fLink = forgot.querySelector('a');
      if (fLink) {
        fLink.setAttribute('aria-disabled', 'true');
        fLink.setAttribute('tabindex', '-1');
        // Preserve and remove navigations
        if (fLink.hasAttribute('href')) { fLink.dataset.href = fLink.getAttribute('href') || ''; fLink.removeAttribute('href'); }
        if (fLink.hasAttribute('onclick')) { fLink.dataset.onclick = fLink.getAttribute('onclick') || ''; fLink.removeAttribute('onclick'); }
        fLink.style.opacity = '0.6';
        fLink.style.cursor = 'not-allowed';
        cancelInteract(fLink);
      }
    } catch(e) {}
  }

  // Disable nav interactions
  navLinks.forEach(function (a) {
    if (!a) return;
    a.setAttribute('aria-disabled', 'true');
    a.setAttribute('tabindex', '-1');
    a.dataset.href = a.getAttribute('href') || '';
    a.removeAttribute('href');
    a.style.opacity = '0.6';
    a.style.cursor = 'not-allowed';
    cancelInteract(a);
  });

  // Disable the Register link under the form while locked (Forgot Password remains active)
  if (registerLink) {
    registerLink.setAttribute('aria-disabled', 'true');
    registerLink.setAttribute('tabindex', '-1');
    registerLink.dataset.href = registerLink.getAttribute('href') || '';
    registerLink.removeAttribute('href');
    registerLink.style.opacity = '0.6';
    registerLink.style.cursor = 'not-allowed';
    cancelInteract(registerLink);
  }

  // Back button mitigation while locked
  try { enableLoginBackPrevention(); } catch(e) {}

  // Determine remaining time using a persisted deadline if available
  var deadlineMs = 0;
  try { deadlineMs = parseInt((localStorage.getItem('lock_deadline')||'0'), 10) || 0; } catch(e) { deadlineMs = 0; }
  var now = Date.now();
  if (deadlineMs && deadlineMs > now) {
    // Recompute remain from persisted deadline (ignore URL remain)
    remain = Math.max(1, Math.ceil((deadlineMs - now) / 1000));
  } else {
    // Seed a new deadline from the URL remain only if none exists
    if (!remain || isNaN(remain) || remain <= 0) {
      return; // nothing to count down
    }
    try { localStorage.setItem('lock_deadline', String(now + (remain*1000))); } catch(e) {}
    deadlineMs = now + (remain*1000);
  }

  // While locked, strip the `remain` param to avoid reseeding on refresh
  try {
    if (window.history && window.history.replaceState) {
      var cleaned = new URL(window.location.href);
      cleaned.searchParams.delete('remain');
      window.history.replaceState({}, document.title, cleaned.pathname + (cleaned.search ? ('?' + cleaned.searchParams.toString()) : ''));
      // Push a new state so immediate back navigations stay on this page
      try {
        window.history.pushState(null, document.title, window.location.href);
        window.history.pushState(null, document.title, window.location.href);
      } catch(e) {}
    }
  } catch(e) {}

  // Update countdown in the dedicated timer container below the Register line
  function updateInlineCountdown(s) {
    var t = Math.max(0, s|0);
    if (lockoutTimer) {
      lockoutTimer.style.display = 'block';
      lockoutTimer.textContent = 'Too many failed attempts. Retry for ' + t + 's.';
    }
  }
  // Deadline already persisted above if needed
  // initialize inline countdown if error element exists
  updateInlineCountdown(remain);
  var timer = setInterval(function () {
    remain -= 1;
    updateInlineCountdown(remain);
    if (remain <= 0) {
      clearInterval(timer);
      try { localStorage.removeItem('lock_deadline'); } catch(e) {}
      try { disableLoginBackPrevention(); } catch(e) {}
      // When lockout fully ends, reset the attempt counter so the next
      // series of failures starts from 1st and 2nd attempt again.
      try {
        localStorage.setItem('login_err_count', '0');
        sessionStorage.setItem('login_err_count', '0');
      } catch(e) {}
      // Re-enable interactions in-place without reloading so any text
      // already typed in the fields is preserved.
      try {
        if (form) {
          var inputs2 = form.querySelectorAll('input, button');
          inputs2.forEach(function (el) {
            el.disabled = false;
            el.classList.remove('disabled');
            if (el.tagName === 'BUTTON') {
              el.style.opacity = '';
              el.style.cursor = '';
            }
          });
        }
        // Restore eye icon interactivity after lockout ends
        try {
          var eyeUnlock = document.getElementById('eyeicon');
          if (eyeUnlock) {
            eyeUnlock.style.pointerEvents = '';
            eyeUnlock.style.cursor = '';
          }
        } catch(e) {}
        // Re-enable nav and register links and restore their interactions
        navLinks.forEach(function (a) {
          if (!a) return;
          a.removeAttribute('aria-disabled');
          a.removeAttribute('tabindex');
          if (a.dataset && a.dataset.href) {
            a.setAttribute('href', a.dataset.href);
          }
          a.style.opacity = '';
          a.style.cursor = '';
          restoreInteract(a);
        });
        if (registerLink) {
          registerLink.removeAttribute('aria-disabled');
          registerLink.removeAttribute('tabindex');
          if (registerLink.dataset && registerLink.dataset.href) {
            registerLink.setAttribute('href', registerLink.dataset.href);
          }
          registerLink.style.opacity = '';
          registerLink.style.cursor = '';
          restoreInteract(registerLink);
        }
      } catch(e) {}
      // Hide timer and Forgot Password UI so the form looks fresh again
      try {
        if (lockoutTimer) { lockoutTimer.style.display = 'none'; }
        if (forgot) { forgot.style.display = 'none'; }
        if (orSep) { orSep.style.display = 'none'; }
      } catch(e) {}
    }
  }, 1000);
})();

// 1. Fast refresh when coming back: if the tab/window becomes visible again,
// reload so lockout state and timers are always up-to-date.
try {
  document.addEventListener('visibilitychange', function () {
    try {
      if (!document.hidden) {
        window.location.reload();
      }
    } catch(e) {}
  }, true);
} catch(e) {}

// 2. Backup check for browsers that use the back/forward cache (bfcache):
// if the page is restored from cache, force a reload.
try {
  window.addEventListener('pageshow', function (event) {
    try {
      if (event && event.persisted) {
        window.location.reload();
      }
    } catch(e) {}
  }, true);
} catch(e) {}
