// Disable browser back navigation while on the dashboard (logged-in state)
(function disableBackOnDashboard(){
  try {
    // On a successful login, the user lands on the dashboard. Reset any
    // client-side login lock escalation so the next lock starts at 15s.
    try {
      localStorage.setItem('lock_level', '0');
      localStorage.removeItem('lock_deadline');
      // Reset both transient and persistent login attempt counters on success
      sessionStorage.setItem('login_err_count', '0');
      localStorage.setItem('login_err_count', '0');
    } catch(e) {}

    if (!(window.history && window.history.pushState)) return;

    // Handler that triggers only on actual back navigation
    var backHandler = function(){
      try { history.pushState(null, document.title, window.location.href); } catch(e) {}
      try { window.location.replace(window.location.pathname); } catch(e) {}
    };

    // Re-apply guard without redirect (used on refresh/tab visibility changes)
    var reapplyGuard = function(){
      try {
        window.history.pushState(null, document.title, window.location.href);
        window.history.pushState(null, document.title, window.location.href);
      } catch(e) {}
      window.onpopstate = backHandler;
    };

    reapplyGuard();
    try { window.addEventListener('popstate', backHandler, true); } catch(e) {}
    try { window.addEventListener('pageshow', reapplyGuard, true); } catch(e) {}
    try { document.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'visible') reapplyGuard(); }, true); } catch(e) {}

    // Prevent Backspace from navigating back when not typing
    try {
      document.addEventListener('keydown', function(e){
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (e.key === 'Backspace' && tag !== 'input' && tag !== 'textarea') {
          e.preventDefault();
        }
      }, true);
    } catch(e) {}
  } catch(e) {}
})();

(function initDashboardAnchors(){
  try {
    var navLinks = document.querySelectorAll('.customer-nav-link');
    var sections = document.querySelectorAll('section[id]');

    function setActiveLink(targetId){
      navLinks.forEach(function(link){
        var href = link.getAttribute('href');
        var isActive = href === '#' + targetId;
        link.classList.toggle('active', isActive);
      });
    }

    navLinks.forEach(function(link){
      var targetId = (link.getAttribute('href') || '').replace('#', '');
      if (!targetId) return;

      link.addEventListener('click', function(event){
        var target = document.getElementById(targetId);
        if (!target) return;

        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setActiveLink(targetId);
        if (window.history && window.history.pushState) {
          history.pushState(null, '', '#' + targetId);
        }
      });
    });

    var hash = window.location.hash;
    if (hash && document.querySelector(hash)) {
      setTimeout(function(){
        document.querySelector(hash).scrollIntoView({ behavior: 'smooth', block: 'start' });
        setActiveLink(hash.replace('#', ''));
      }, 50);
    }

    sections.forEach(function(section){
      section.style.scrollMarginTop = '24px';
    });
  } catch (error) {
    console.warn('Dashboard anchor init failed:', error);
  }
})();

(function initProfileModal(){
  try {
    var button = document.getElementById('view-profile-btn');
    var modal = document.getElementById('profile-modal');
    var closeButton = document.getElementById('close-profile-modal');

    if (!button || !modal) return;

    var openModal = function(){
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
    };

    var closeModal = function(){
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
    };

    button.addEventListener('click', openModal);
    closeButton && closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function(event){
      if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function(event){
      if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });
  } catch (error) {
    console.warn('Profile modal failed to initialize:', error);
  }
})();
