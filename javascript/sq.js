(function(){
      var QUESTIONS = [
        'Who is your best friend in Elementary?',
        'What is the name of your favorite pet?',
        'Who is your favorite teacher in high school?',
        'What city were you born in?',
        'What is your mother\'s maiden name?',
        'What was the name of your first school?'
      ];
      function populate(sel){
        sel.innerHTML = '<option value="" disabled selected>-Select a question-</option>' + QUESTIONS.map(function(q){
          return '<option value="'+q.replace(/"/g,'&quot;')+'">'+q+'</option>';
        }).join('');
      }
      var s1 = document.getElementById('q1');
      var s2 = document.getElementById('q2');
      var s3 = document.getElementById('q3');
      [s1,s2,s3].forEach(function(s){ if (s) populate(s); });

      function refreshDisables(){
        // If this page doesn't have the select elements, skip safely
        if (!s1 || !s2 || !s3) return;
        var chosen = [s1.value, s2.value, s3.value].filter(function(v){ return v && v !== ''; });
        [s1,s2,s3].forEach(function(sel){
          if (!sel) return;
          Array.prototype.forEach.call(sel.options, function(opt){
            if (!opt.value) return;
            var shouldDisable = chosen.indexOf(opt.value) !== -1 && sel.value !== opt.value;
            opt.disabled = shouldDisable;
          });
        });
      }
      [s1,s2,s3].forEach(function(sel){ sel && sel.addEventListener('change', refreshDisables); });
      refreshDisables();

      // Answer show/hide toggles (match login/register behavior)
      function attachEye(eyeId, inputId){
        var eye = document.getElementById(eyeId);
        var inp = document.getElementById(inputId);
        if (!eye || !inp) return;
        eye.onclick = function(){
          if (inp.type === 'password') {
            inp.type = 'text';
            eye.classList.remove('fa-eye-slash');
            eye.classList.add('fa-eye');
          } else {
            inp.type = 'password';
            eye.classList.remove('fa-eye');
            eye.classList.add('fa-eye-slash');
          }
        };
      }
      attachEye('eyeicon-a1','a1');
      attachEye('eyeicon-a2','a2');
      attachEye('eyeicon-a3','a3');

      // --- Custom required validation (mirror register.js behavior) ---
      function getErrorEl(id){ return document.getElementById(id + '-error'); }
      function clearErrorMessage(fieldId){
        var el = getErrorEl(fieldId);
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
      function showErrorMessage(fieldId, message){
        var existing = getErrorEl(fieldId);
        if (existing) { existing.textContent = message; return; }
        var host = document.getElementById(fieldId);
        if (!host) return;
        var err = document.createElement('div');
        err.id = fieldId + '-error';
        err.className = 'error-message';
        err.textContent = message;
        // Style to match register.js
        err.style.color = 'red';
        err.style.fontSize = '.9em';
        err.style.marginTop = '0px';
        err.style.marginBottom = '0px';
        // Place below password-wrapper for answer fields to avoid overlapping icon
        var wrapper = (fieldId === 'a1' || fieldId === 'a2' || fieldId === 'a3')
          ? host.closest('.password-wrapper')
          : null;
        if (wrapper && wrapper.parentNode) {
          wrapper.parentNode.insertBefore(err, wrapper.nextSibling);
        } else if (host.parentNode) {
          host.parentNode.insertBefore(err, host.nextSibling);
        }
      }

      // Validate an individual answer field
      function validateAnswer(fieldId){
        var el = document.getElementById(fieldId);
        if (!el) return true;
        var raw = (el.value || '');
        // Leading/trailing spaces are not allowed
        if (/^\s|\s$/.test(raw)) {
          showErrorMessage(fieldId, 'No leading or trailing spaces');
          return false;
        }
        var v = raw.trim();
        // If empty, do not surface other errors yet
        if (v === '') { clearErrorMessage(fieldId); return false; }
        // Allowed characters should surface BEFORE min-length errors
        if (!/^[A-Za-z0-9 .'-]+$/.test(v)) {
          showErrorMessage(fieldId, "Only letters, numbers, spaces, periods (.), apostrophes ('), or hyphens (-) allowed.");
          return false;
        }
        if (v.length < 3) { showErrorMessage(fieldId, 'Must be at least 3 characters'); return false; }
        if (v.length > 50) { showErrorMessage(fieldId, 'Maximum of 50 characters only'); return false; }
        clearErrorMessage(fieldId);
        return true;
      }

      var form = document.querySelector('form');
      if (form) {
        form.setAttribute('novalidate','novalidate');
        // If server redirected with error=required, show required messages on all fields
        (function(){
          try {
            var params = new URLSearchParams(window.location.search || '');
            var err = params.get('error');
            if (err === 'required') {
              ['q1','a1','q2','a2','q3','a3'].forEach(function(fid){
                showErrorMessage(fid, 'This field is required');
              });
            }
          } catch(_) {}
        })();
        // Clear all generic required messages when user interacts with any SQ field (not the buttons)
        function maybeClearRequired(ev){
          var t = ev.target;
          if (!t) return;
          var order = ['q1','a1','q2','a2','q3','a3'];
          if (order.indexOf(t.id) === -1) return; // ignore clicks on buttons, etc.
          order.forEach(function(fid){
            var errEl = document.getElementById(fid + '-error');
            if (errEl && /this field is required/i.test(errEl.textContent || '')) {
              if (errEl.parentNode) errEl.parentNode.removeChild(errEl);
            }
          });
        }
        form.addEventListener('input', maybeClearRequired, true);
        form.addEventListener('change', maybeClearRequired, true);
        form.addEventListener('focus', maybeClearRequired, true);

        // Realtime validation for answers
        ['a1','a2','a3'].forEach(function(fid){
          var el = document.getElementById(fid);
          if (!el) return;
          var h = function(){ validateAnswer(fid); };
          el.addEventListener('input', h);
          el.addEventListener('blur', h);
          el.addEventListener('change', h);
        });

        // Step-by-step required check in a fixed order + answer field rules
        form.addEventListener('submit', function(e){
          var order = ['q1','a1','q2','a2','q3','a3'];
          // Remove stale required-only errors first
          order.forEach(function(fid){
            var el = document.getElementById(fid + '-error');
            if (el && /required/i.test(el.textContent || '')) {
              el.parentNode && el.parentNode.removeChild(el);
            }
          });
          // Required first: mark all empties at once
          var firstEmpty = null;
          for (var i = 0; i < order.length; i++) {
            var fid = order[i];
            var f = document.getElementById(fid);
            if (!f) continue;
            var val = (f.value || '').trim();
            if (f.tagName === 'SELECT') val = f.value;
            if (val === '') {
              showErrorMessage(fid, 'This field is required');
              if (!firstEmpty) firstEmpty = f;
            }
          }
          if (firstEmpty) {
            e.preventDefault();
            return;
          }
          // Then validate answers specifically
          var answerIds = ['a1','a2','a3'];
          for (var j = 0; j < answerIds.length; j++) {
            var aid = answerIds[j];
            if (!validateAnswer(aid)) {
              e.preventDefault();
              return;
            }
          }
        });
      }

    })();