// קובץ סקריפט קטן (CSP מאפשר רק סקריפטים מהאתר עצמו, בלי inline).
// טפסי סינון עם class="auto": כל שינוי בשדה מחיל את הסינון מיד, בלי ללחוץ על כפתור.
(function () {
  'use strict';
  var KEY = 'alma_af';
  document.querySelectorAll('form.auto').forEach(function (f) {
    var t = null;
    function go(name) {
      try { sessionStorage.setItem(KEY, name || ''); } catch (e) {}
      if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
    }
    f.addEventListener('change', function (e) {
      var el = e.target;
      if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio') go('');
      else if (el.type === 'text' || el.type === 'search') { clearTimeout(t); go(el.name); }
    });
    f.addEventListener('input', function (e) {
      var el = e.target;
      if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'search')) {
        clearTimeout(t); t = setTimeout(function () { go(el.name); }, 450);
      }
    });
  });
  // אחרי טעינה מחדש: מחזירים את הסמן לשדה הטקסט שבו הקלידו
  try {
    var n = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY);
    if (n) {
      var el = document.querySelector('form.auto input[name="' + n.replace(/"/g, '') + '"]');
      if (el) { el.focus(); var v = el.value; el.value = ''; el.value = v; }
    }
  } catch (e) {}
})();
