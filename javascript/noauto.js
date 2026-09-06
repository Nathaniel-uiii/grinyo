(function(){
  function suppress(el, opts) {
    if (!el) return;
    try { el.setAttribute('autocomplete', opts.autocomplete || 'off'); } catch(_) {}
    try { el.setAttribute('autocapitalize', 'off'); } catch(_) {}
    try { el.setAttribute('autocorrect', 'off'); } catch(_) {}
    try { el.setAttribute('spellcheck', 'false'); } catch(_) {}
    try { el.setAttribute('aria-autocomplete', 'none'); } catch(_) {}
    // Popular password manager ignore flags
    try { el.setAttribute('data-lpignore', 'true'); } catch(_) {}
    try { el.setAttribute('data-1p-ignore', 'true'); } catch(_) {}
  }

  function run() {
    var form = document.querySelector('form');
    if (form) {
      try { form.setAttribute('autocomplete', 'off'); } catch(_) {}
      try { form.setAttribute('spellcheck', 'false'); } catch(_) {}
    }

    var byId = function(id){ return document.getElementById(id); };

    // Text-like fields
    var textIds = ['id','fname','mname','lname','ename','email','user','street','brgy','city','province','country','zipcode'];
    for (var i=0;i<textIds.length;i++) {
      var el = byId(textIds[i]);
      suppress(el, { autocomplete: (textIds[i] === 'email' ? 'off' : 'nope') });
      if (el && el.type === 'number') {
        // numbers still get suggestions; force as text with inputmode if desired
        try { el.setAttribute('inputmode','numeric'); } catch(_) {}
      }
    }

    // Passwords: recommend new-password to suppress saved suggestions
    var pass = byId('pass');
    var repass = byId('repass');
    suppress(pass, { autocomplete: 'new-password' });
    suppress(repass, { autocomplete: 'new-password' });

    // Also disable for selects/date
    suppress(byId('bday'), { autocomplete: 'off' });
    suppress(byId('sex'), { autocomplete: 'off' });

    // Fallback: set a non-standard token to beat stubborn autofill
    var allInputs = document.querySelectorAll('input, select, textarea');
    for (var j=0;j<allInputs.length;j++) {
      var el2 = allInputs[j];
      if (!el2) continue;
      // If browser still shows suggestions, try a non-standard token
      if (el2.getAttribute('autocomplete') === 'off') {
        try { el2.setAttribute('autocomplete', 'new-'+(el2.name||el2.id||'input')); } catch(_) {}
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
