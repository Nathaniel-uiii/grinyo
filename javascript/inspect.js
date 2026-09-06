;(function(){
  if (typeof document === 'undefined') return;
  document.addEventListener('contextmenu', function(e){ e.preventDefault(); }, { capture: true });
  window.addEventListener('keydown', function(e){
    var k = (e.key || '').toLowerCase();
    var code = e.keyCode || e.which;
    if (code === 123 || k === 'f12') { e.preventDefault(); e.stopPropagation(); return false; }
    if (e.ctrlKey && e.shiftKey && (k === 'i' || k === 'j' || k === 'c')) { e.preventDefault(); e.stopPropagation(); return false; }
    // if (e.ctrlKey && (k === 'u')) { e.preventDefault(); e.stopPropagation(); return false; }
  }, true);
})();
