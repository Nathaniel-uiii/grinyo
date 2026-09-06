// Simple helpers adapted from register.js
function showErrorMessage(fieldId, message) {
  if (!message) return;
  var fieldInput = document.getElementById(fieldId);
  if (!fieldInput) return;
  var existing = document.getElementById(fieldId + '-error');
  if (existing) existing.remove();
  var errorDiv = document.createElement('div');
  errorDiv.id = fieldId + '-error';
  errorDiv.setAttribute('role', 'alert');
  // Compact inline style beneath the field
  errorDiv.style.color = 'red';
  errorDiv.style.fontSize = '.9em';
  errorDiv.style.marginTop = '2px';
  errorDiv.style.marginBottom = '0';
  errorDiv.style.textAlign = 'left';
  errorDiv.style.whiteSpace = 'normal';
  errorDiv.textContent = message;
  // Prefer inserting after password-wrapper if present; otherwise after the input
  var wrapper = fieldInput.closest ? fieldInput.closest('.password-wrapper') : null;
  var container = wrapper ? wrapper.parentNode : fieldInput.parentNode;
  var afterNode = wrapper || fieldInput;
  if (container && container.insertBefore) {
    container.insertBefore(errorDiv, afterNode.nextSibling);
  } else if (fieldInput.parentNode) {
    fieldInput.parentNode.appendChild(errorDiv);
  }
}

function clearError(fieldId) {
  var el = document.getElementById(fieldId + '-error');
  if (el && el.parentNode) el.parentNode.removeChild(el);
}

// Show per-field correctness status under the field
function showAnswerStatus(fieldId, isCorrect) {
  var fieldInput = document.getElementById(fieldId);
  if (!fieldInput) return;
  var existing = document.getElementById(fieldId + '-error');
  if (existing) existing.remove();
  var div = document.createElement('div');
  div.id = fieldId + '-error';
  div.setAttribute('role', 'status');
  div.style.fontSize = '0.9rem';
  div.style.marginTop = '2px';
  div.style.marginBottom = '0';
  div.style.textAlign = 'left';
  div.style.whiteSpace = 'normal';
  div.style.color = isCorrect ? 'green' : '#dc2626';
  div.textContent = isCorrect ? 'Correct answer' : 'Wrong answer';
  var wrapper = fieldInput.closest ? fieldInput.closest('.password-wrapper') : null;
  var container = wrapper ? wrapper.parentNode : fieldInput.parentNode;
  var afterNode = wrapper || fieldInput;
  if (container && container.insertBefore) {
    container.insertBefore(div, afterNode.nextSibling);
  } else if (fieldInput.parentNode) {
    fieldInput.parentNode.appendChild(div);
  }
}

function showAnswersBlockError(message) {
  var existing = document.getElementById('answers-error');
  if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
  var last = document.getElementById('a3');
  if (!last) return;
  // Place after the entire answers grid for better visual balance
  var formGroup = last.closest && last.closest('.form-group');
  var anchor = formGroup && formGroup.parentNode ? formGroup : null;
  if (!anchor) return;
  var div = document.createElement('div');
  div.id = 'answers-error';
  div.setAttribute('role', 'alert');
  // Compact, centered error styling to match other pages
  div.style.color = '#dc2626';
  div.style.fontSize = '12px';
  div.style.fontWeight = '500';
  div.style.marginTop = '6px';
  div.style.marginBottom = '2px';
  div.style.textAlign = 'center';
  div.style.width = '100%';
  div.style.whiteSpace = 'normal';
  div.textContent = message;
  anchor.parentNode.insertBefore(div, anchor.nextSibling);
}

(function initRecover() {
  var params = new URLSearchParams(window.location.search);
  var step = params.get('step') || 'email';
  var error = params.get('error');

  if (step === 'email') {
    // ID number stage (recover.php uses idnum now)
    var idInput = document.getElementById('idnum');
    var form = document.getElementById('recover-email-form');
    if (form) { try { form.setAttribute('novalidate','novalidate'); } catch(e) {} }

    if (error === 'empty_id') {
      showErrorMessage('idnum', 'This field is required');
    } else if (error === 'invalid_id') {
      showErrorMessage('idnum', 'Please enter ID in format: xxxx-xxxx');
    } else if (error === 'unknown_id') {
      showErrorMessage('idnum', 'ID not found');
    }

    if (idInput) {
      function updateIdRequired() {
        var raw = idInput.value || '';
        if (raw.trim() === '') {
          showErrorMessage('idnum', 'This field is required');
        } else {
          clearError('idnum');
        }
      }

      idInput.addEventListener('input', updateIdRequired);
      idInput.addEventListener('blur', updateIdRequired);
    }
  }

  if (step === 'questions') {
    if (error === 'empty') {
      var msg = 'This field is required';
      showErrorMessage('a1', msg);
      showErrorMessage('a2', msg);
      showErrorMessage('a3', msg);
    }

    // Render per-field correctness if provided by server via status=xyz (x,y,z in {0,1})
    var status = params.get('status');
    if (status && /^[01]{3}$/.test(status)) {
      // Clear any block-level banner if present
      var be = document.getElementById('answers-error');
      if (be && be.parentNode) be.parentNode.removeChild(be);
      var ids = ['a1','a2','a3'];
      for (var i = 0; i < ids.length; i++) {
        var bit = status.charAt(i) === '1';
        showAnswerStatus(ids[i], bit);
      }
    }
    // Intercept Verify: validate empties then AJAX to server for correctness without reload
    var ansForm = document.getElementById('recover-answers-form');
    if (ansForm && ansForm.addEventListener) {
      ansForm.addEventListener('submit', function(e){
        e.preventDefault();
        e.stopPropagation();
        var ids = ['a1','a2','a3'];
        var anyEmpty = false;
        for (var i = 0; i < ids.length; i++) {
          var el = document.getElementById(ids[i]);
          var v = (el && el.value) ? el.value.trim() : '';
          if (v === '') { showErrorMessage(ids[i], 'This field is required'); anyEmpty = true; }
        }
        if (anyEmpty) return;
        // Build form data
        var fd = new FormData();
        fd.append('stage', 'answers');
        fd.append('ajax', '1');
        fd.append('a1', (document.getElementById('a1').value || ''));
        fd.append('a2', (document.getElementById('a2').value || ''));
        fd.append('a3', (document.getElementById('a3').value || ''));
        fetch('./recover.php', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
          .then(function(r){ return r.json().catch(function(){ return {}; }); })
          .then(function(data){
            // Clear any block banner
            var be = document.getElementById('answers-error');
            if (be && be.parentNode) be.parentNode.removeChild(be);
            if (!data || data.ok === false) {
              // If server reported empty/no_session, keep required messages already shown
              return;
            }
            // Render per-field statuses
            if (Array.isArray(data.correct)) {
              var ids = ['a1','a2','a3'];
              for (var i = 0; i < ids.length; i++) {
                showAnswerStatus(ids[i], !!data.correct[i]);
              }
            }
            if (data.requiredMet && data.redirect) {
              window.location.href = data.redirect;
            }
          })
          .catch(function(){ /* ignore */ });
      });
    }
    ['a1','a2','a3'].forEach(function(id){
      var i = document.getElementById(id);
      if (i) i.addEventListener('input', function(){
        clearError(id);
        var be = document.getElementById('answers-error');
        if (be && be.parentNode) be.parentNode.removeChild(be);
      });
    });
  }
})();
