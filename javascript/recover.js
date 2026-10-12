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
  var questionNumber = fieldId.replace('a', '');
  div.textContent = isCorrect ? 'Question ' + questionNumber + ': correct answer' : 'Question ' + questionNumber + ': wrong answer';
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
    } else if (error === 'blocked') {
      showErrorMessage('idnum', 'Your account is blocked. Contact the administrator.');
    } else if (error === 'mail') {
      showErrorMessage('idnum', 'We could not send the PIN. Check the server email configuration and try again.');
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

  if (step === 'otp') {
    var otpInput = document.getElementById('otp');
    var otpForm = document.getElementById('recover-otp-form');
    if (otpInput) {
      if (error === 'otp_invalid') showErrorMessage('otp', 'Invalid PIN. Please try again.');
      if (error === 'otp_expired') showErrorMessage('otp', 'This PIN has expired. Start recovery again.');
      if (error === 'otp_locked') showErrorMessage('otp', 'Too many attempts. Start recovery again.');
      if (error === 'otp_session') showErrorMessage('otp', 'Your recovery session expired. Start again.');
      otpInput.addEventListener('input', function(){
        otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
        clearError('otp');
      });
    }
    if (otpForm) {
      otpForm.addEventListener('submit', function(e){
        if (!/^\d{6}$/.test(otpInput ? otpInput.value : '')) {
          e.preventDefault();
          showErrorMessage('otp', 'Enter the six-digit PIN.');
        }
      });
    }
  }

  if (step === 'questions') {
    if (error === 'empty') {
      var missing = params.get('missing') || '';
      ['q1', 'q2', 'q3', 'a1', 'a2', 'a3'].forEach(function(id, index){
        if (missing.charAt(index) === '1') {
          showErrorMessage(id, 'This field is required');
        }
      });
    }
    if (error === 'mail') {
      showAnswersBlockError('Security questions verified, but we could not send the PIN. Check the server email configuration and try again.');
    }

    if (error === 'question_selection') {
      var selectionStatus = params.get('status') || '';
      ['q1', 'q2', 'q3'].forEach(function(id, index) {
        if (selectionStatus.charAt(index) === '0') {
          showErrorMessage(id, 'Question ' + (index + 1) + ' was not set for this account. Select one of your saved questions.');
        }
      });
      if (selectionStatus === '111') {
        showAnswersBlockError('Choose each saved security question only once.');
      }
    }

    // Render per-field correctness if provided by server via status=xyz (x,y,z in {0,1})
    var status = params.get('status');
    if (error !== 'question_selection' && status && /^[01]{3}$/.test(status)) {
      // Clear any block-level banner if present
      var be = document.getElementById('answers-error');
      if (be && be.parentNode) be.parentNode.removeChild(be);
      var ids = ['a1','a2','a3'];
      for (var i = 0; i < ids.length; i++) {
        var bit = status.charAt(i) === '1';
        showAnswerStatus(ids[i], bit);
      }
    }
    // Disable chosen questions in other dropdowns
    var qSelects = ['q1', 'q2', 'q3'];
    function syncQuestionOptions() {
      var selected = qSelects.map(function(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
      });
      qSelects.forEach(function(id, i) {
        var el = document.getElementById(id);
        if (!el) return;
        Array.from(el.options).forEach(function(opt) {
          if (opt.value === '') return;
          var usedElsewhere = selected.indexOf(opt.value) !== -1 && selected[i] !== opt.value;
          opt.disabled = usedElsewhere;
        });
      });
    }
    qSelects.forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', syncQuestionOptions);
    });
    syncQuestionOptions();

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
