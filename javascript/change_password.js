// Password strength + match indicator
function strengthText(pw) {
  var s = (pw || '').replace(/\s+/g, ''); // ignore spaces for strength
  if (!s) return '';
  var types = 0;
  if (/[a-z]/.test(s)) types++;
  if (/[A-Z]/.test(s)) types++;
  if (/[0-9]/.test(s)) types++;
  if (/[^A-Za-z0-9]/.test(s)) types++;
  if (s.length < 8 || types < 2) return 'Weak';
  if (s.length >= 12 && types >= 4) return 'Strong';
  return 'Medium';
}

(function initChangePw(){
  var newpass = document.getElementById('newpass');
  var repass = document.getElementById('repass');
  var strength = document.getElementById('strength');
  var matchmsg = document.getElementById('matchmsg');
  var eye = document.getElementById('eyeicon-change');
  var saveBtn = document.getElementById('save-password-btn');
  if (!newpass || !repass) return;

  // Hide indicators initially (match register.js behavior)
  if (strength) { strength.textContent = ''; }
  if (matchmsg) { matchmsg.textContent = ''; matchmsg.style.color = '#888'; }
  // Ensure no error under password at start
  (function(){
    var el = document.getElementById('newpass-error');
    if (el && el.parentNode) el.parentNode.removeChild(el);
  })();

  // Helpers consistent with register.js
  function showErrorMessage(fieldId, message) {
    if (!message) return;
    var fieldInput = document.getElementById(fieldId);
    if (!fieldInput) return;
    var existing = document.getElementById(fieldId + '-error');
    if (existing) existing.remove();
    var errorDiv = document.createElement('div');
    errorDiv.id = fieldId + '-error';
    errorDiv.style.color = 'red';
    errorDiv.style.fontSize = '13px';
    errorDiv.style.marginTop = '5px';
    
    errorDiv.style.display = 'flex';
    errorDiv.style.justifyContent = 'flex-start';
    errorDiv.style.width = '100%';
    errorDiv.style.whiteSpace = 'normal';
    errorDiv.textContent = message;
    // Special handling for change password: place after the password group
    if (fieldId === 'newpass') {
      var parent = fieldInput.parentNode; // .password-wrapper
      // Try to find the nearest .form-group container
      var node = parent;
      var group = null;
      while (node) {
        if (node.classList && node.classList.contains('form-group')) { group = node; break; }
        node = node.parentNode;
      }
      // If strength small exists, prefer inserting after it
      var strengthSmall = document.getElementById('strength');
      if (strengthSmall && strengthSmall.parentNode) {
        strengthSmall.parentNode.insertBefore(errorDiv, strengthSmall.nextSibling);
        return;
      }
      // Otherwise insert after the group as a full-width row
      errorDiv.style.gridColumn = '1 / -1';
      errorDiv.style.display = 'flex';
      errorDiv.style.justifyContent = 'flex-start';
      errorDiv.style.width = '100%';
      if (group && group.parentNode) {
        group.parentNode.insertBefore(errorDiv, group.nextSibling);
      } else if (parent && parent.parentNode) {
        parent.parentNode.insertBefore(errorDiv, parent.nextSibling);
      } else if (fieldInput.parentNode) {
        fieldInput.parentNode.insertBefore(errorDiv, fieldInput.nextSibling);
      } else {
        document.body.appendChild(errorDiv);
      }
      return;
    } else {
      var parent = fieldInput.parentNode;
      if (parent && parent.parentNode) {
        parent.parentNode.insertBefore(errorDiv, parent.nextSibling);
      } else if (fieldInput.nextSibling) {
        fieldInput.parentNode.insertBefore(errorDiv, fieldInput.nextSibling);
      } else {
        fieldInput.parentNode.appendChild(errorDiv);
      }
    }
  }

  function clearErrorMessage(fieldId) {
    var el = document.getElementById(fieldId + '-error');
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  // Helper to color the strength label
  function setStrengthColor(sTxt) {
    if (!strength) return;
    if (!sTxt) { strength.style.color = ''; return; }
    if (sTxt === 'Weak') strength.style.color = 'red';
    else if (sTxt === 'Medium') strength.style.color = '#f59e0b';
    else strength.style.color = '#16a34a';
  }

  function hasSpace(str) {
    return /\s/.test(str || '');
  }

  function updateSaveDisabled() {
    // Keep button visually active; submission is blocked in submit handler
    if (!saveBtn) return;
    saveBtn.disabled = false;
    saveBtn.style.opacity = '';
    saveBtn.style.cursor = '';
  }

  // Debounced server-side password reuse check (same endpoint as register.js)
  var passDupTimeoutId;
  var reuseExists = null; // null=unknown, true=exists, false=not exists
  function checkPasswordExists(password) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../server/check_password.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
      if (xhr.readyState == 4 && xhr.status == 200) {
        try {
          var resp = JSON.parse(xhr.responseText || '{}');
          reuseExists = !!(resp && resp.exists === true);
          // Delegate UI updates to the existing input handler for consistency
          try { newpass.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        } catch (e) {
          // ignore parse errors
        }
      }
    };
    xhr.send('password=' + encodeURIComponent(password));
  }

  newpass.addEventListener('input', function(){
    var val = newpass.value || '';
    var sTxt = strengthText(val);
    // Strength label + color (match register.js wording)
    if (sTxt) {
      strength.textContent = sTxt + ' Password';
    } else {
      strength.textContent = '';
    }
    setStrengthColor(sTxt);

    // Granular validations (match register.js behavior)
    if (!val) {
      clearErrorMessage('newpass');
    } else if (hasSpace(val)) {
      showErrorMessage('newpass', 'Spaces are not allowed in password.');
    } else if (val.length < 8) {
      showErrorMessage('newpass', 'Password must be at least 8 characters long.');
    } else if (val.length > 50) {
      showErrorMessage('newpass', 'Password cannot exceed 50 characters.');
    } else if (!/[A-Z]/.test(val)) {
      showErrorMessage('newpass', 'Password must contain at least 1 uppercase letter.');
    } else if (!/[a-z]/.test(val)) {
      showErrorMessage('newpass', 'Password must contain at least 1 lowercase letter.');
    } else if (!/[0-9]/.test(val)) {
      showErrorMessage('newpass', 'Password must contain at least 1 number.');
    } else if (!/[^A-Za-z0-9]/.test(val)) {
      showErrorMessage('newpass', 'Password must contain at least 1 special character.');
    } else if (reuseExists === true) {
      showErrorMessage('newpass', 'This password is already used. Please choose a different password.');
    } else {
      clearErrorMessage('newpass');
    }

    // Debounced reuse check: only when basic rules pass
    clearTimeout(passDupTimeoutId);
    if (!hasSpace(val) && val.length >= 8 && val.length <= 50 && /[A-Z]/.test(val) && /[a-z]/.test(val) && /[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) {
      passDupTimeoutId = setTimeout(function(){ checkPasswordExists(val); }, 500);
    }

    // Hide indicators when empty
    if (!val) { strength.textContent = ''; strength.style.color = ''; reuseExists = null; }
    // Update match on every password change
    checkMatch();
    updateSaveDisabled();
  });

  function checkMatch(){
    if (!repass.value) { matchmsg.textContent = ''; return; }
    if (newpass.value === repass.value) {
      matchmsg.textContent = 'Password Matched';
      matchmsg.style.color = '#16a34a';
    } else {
      matchmsg.textContent = 'Password does not match';
      matchmsg.style.color = '#dc2626';
    }
    updateSaveDisabled();
  }

  repass.addEventListener('input', checkMatch);

  // Toggle visibility for both fields using a single eye icon on the first field
  if (eye) {
    eye.addEventListener('click', function(){
      var toType = (newpass.type === 'password') ? 'text' : 'password';
      newpass.type = toType;
      repass.type = toType;
      if (toType === 'text') {
        eye.classList.remove('fa-eye-slash');
        eye.classList.add('fa-eye');
      } else {
        eye.classList.remove('fa-eye');
        eye.classList.add('fa-eye-slash');
      }
    });
  }

  // Handle URL error flags from server and display consistent messages
  (function handleServerErrors(){
    var params = new URLSearchParams(window.location.search);
    var err = params.get('error');
    if (!err) return;
    if (err === 'empty') {
      showErrorMessage('newpass', 'Please fill in both password fields.');
      if (matchmsg) { matchmsg.textContent = 'Must match.'; matchmsg.style.color = '#888'; }
    } else if (err === 'mismatch') {
      // Use only the match hint line for mismatch to avoid duplicate messages
      if (matchmsg) { matchmsg.textContent = 'Password does not match'; matchmsg.style.color = '#dc2626'; }
    } else if (err === 'weak') {
      // Only show weak guidance if user has typed something
      if (newpass && newpass.value) {
        showErrorMessage('newpass', 'Password must be at least 8 characters long and contain a mix of letters, numbers, and/or special characters (e.g. ! @ # $ % ^ & * ( ) - _)');
      }
    }
    // Clean URL to prevent persistent flags
    if (window.history && window.history.replaceState) {
      var url = window.location.pathname;
      window.history.replaceState({}, document.title, url);
    }
    // After handling, if field is empty, ensure no weak message remains
    if (newpass && !newpass.value) {
      clearErrorMessage('newpass');
    }
    updateSaveDisabled();
  })();

  // Clear messages when inputs change or are cleared
  function onAnyInputChange(){
    // For new password: keep weak guidance visible while Weak; clear otherwise or when empty
    var sTxtNow = strengthText(newpass.value || '');
    if (!newpass.value || sTxtNow !== 'Weak') {
      clearErrorMessage('newpass');
    }
    // If any field contains spaces, ensure submit remains disabled and show message on newpass
    if (hasSpace(newpass.value) || hasSpace(repass.value)) {
      if (newpass.value) { showErrorMessage('newpass', 'Spaces are not allowed in password.'); }
    }
    // Ensure we rely on the match hint only; no field-level error for #repass
    clearErrorMessage('repass');
    // Hide indicators when both are empty
    if (!newpass.value) { if (strength) { strength.textContent = ''; strength.style.color = ''; } }
    if (!repass.value) { if (matchmsg) { matchmsg.textContent = ''; matchmsg.style.color = '#888'; } }
    updateSaveDisabled();
  }
  // Only repass needs generic clear; newpass logic handled in main handler above
  repass.addEventListener('input', onAnyInputChange);
  // Initial state
  updateSaveDisabled();

  // Clear only the generic required message while typing
  var form = document.querySelector('form');
  if (form) {
    form.addEventListener('input', function(ev){
      var t = ev.target;
      if (!t || (t.id !== 'newpass' && t.id !== 'repass')) return;
      var v = (t.value || '').trim();
      if (v !== '') {
        var errEl = document.getElementById(t.id + '-error');
        if (errEl && /this field is required/i.test(errEl.textContent || '')) {
          if (errEl.parentNode) errEl.parentNode.removeChild(errEl);
        }
      }
    }, true);

    // Submit handler: block refresh and show required/mismatch inline
    form.addEventListener('submit', function(e){
      var blocked = false;
      var np = (newpass.value || '').trim();
      var rp = (repass.value || '').trim();
      if (np === '') { showErrorMessage('newpass', 'This field is required'); blocked = true; }
      if (rp === '') { showErrorMessage('repass', 'This field is required'); blocked = true; }
      // Respect existing validations: spaces, weak, reuse
      var hasNewpassErr = !!document.getElementById('newpass-error');
      if (hasNewpassErr) blocked = true;
      if (np && rp && np !== rp) {
        if (matchmsg) { matchmsg.textContent = 'Password does not match'; matchmsg.style.color = '#dc2626'; }
        blocked = true;
      }
      if (blocked) {
        e.preventDefault();
        if (np === '') newpass.focus();
        else if (rp === '') repass.focus();
        else newpass.focus();
      }
    });
  }
})();
