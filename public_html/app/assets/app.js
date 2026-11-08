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

  // טופס עריכה: כפתור "שמירה" פעיל רק אחרי שינוי באחד השדות
  document.querySelectorAll('form.dirty').forEach(function (f) {
    var btn = f.querySelector('button.sv'); if (!btn) return;
    function snap() { var a = []; new FormData(f).forEach(function (v, k) { if (k !== '_t') a.push(k + '=' + v); }); return a.join('&'); }
    var base = snap();
    function upd() { btn.disabled = (snap() === base); }
    f.addEventListener('input', upd); f.addEventListener('change', upd);
    window.addEventListener('pageshow', upd);
    upd();
  });
  // שינוי סטטוס: מציגים ודורשים שדה סיבה
  document.querySelectorAll('select[data-orig]').forEach(function (sel) {
    var box = document.getElementById('stnote'); if (!box) return;
    var inp = box.querySelector('input');
    function st() { var ch = sel.value !== sel.getAttribute('data-orig'); box.hidden = !ch; inp.required = ch; }
    sel.addEventListener('change', st); st();
  });
  // שדות מספר זהות: ספרות בלבד, עד 9
  document.querySelectorAll('input[data-digits]').forEach(function (i) {
    i.addEventListener('input', function () { var v = i.value.replace(/\D/g, '').slice(0, 9); if (v !== i.value) i.value = v; });
  });
  // הערות: שמירה פעילה רק כשיש טקסט, ומונה תווים
  document.querySelectorAll('form.nform').forEach(function (f) {
    var ta = f.querySelector('textarea'), btn = f.querySelector('button.ns'), c = f.querySelector('.cnt');
    if (!ta || !btn) return;
    function u() { btn.disabled = ta.value.trim() === ''; if (c) { var m = c.getAttribute('data-max'); c.textContent = ta.value.length + ' / ' + m + ' תווים'; c.classList.toggle('er', ta.value.length >= +m); } }
    ta.addEventListener('input', u); window.addEventListener('pageshow', u); u();
  });
  // העברה לארכיון: הכפתור פעיל רק אחרי סימון האישור
  document.querySelectorAll('form.arch').forEach(function (f) {
    var cb = f.querySelector('input[type=checkbox]'), b = f.querySelector('button');
    if (cb && b) { cb.addEventListener('change', function () { b.disabled = !cb.checked; }); b.disabled = !cb.checked; }
  });

  // השלמת עיר ורחוב מהספרייה (רק כשהספרייה נטענה והשדות מסומנים)
  (function () {
    var c = document.querySelector('input[data-geo=city]'), st = document.querySelector('input[data-geo=street]');
    if (!c || !st) return;
    function fill(id, arr) {
      var dl = document.getElementById(id); if (!dl) return; dl.textContent = '';
      arr.forEach(function (n) { var o = document.createElement('option'); o.value = n; dl.appendChild(o); });
    }
    function get(url, cb) { fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : []; }).then(cb).catch(function () {}); }
    var loaded = false, last = null;
    function cities() { if (!loaded) { loaded = true; get('/app/geo_lookup.php?t=cities', function (a) { fill('geo-cities', a); }); } }
    function streets() {
      var v = c.value.trim(); if (v === last) return; last = v;
      if (!v) { fill('geo-streets', []); return; }
      get('/app/geo_lookup.php?t=streets&city=' + encodeURIComponent(v), function (a) { fill('geo-streets', a); });
    }
    c.addEventListener('focus', cities); c.addEventListener('change', streets); c.addEventListener('blur', streets);
    st.addEventListener('focus', streets);
    cities(); streets();
  })();
})();
