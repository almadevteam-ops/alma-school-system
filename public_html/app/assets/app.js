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
  // שדות מספר זהות: ספרות בלבד (עד 9), ובדיקת תקינות ברגע היציאה מהשדה (רק אם הוקלד ערך ושונה מהקיים)
  function idOk(v) {
    v = v.replace(/\D/g, ''); if (v.length !== 9) return false;
    var s = 0; for (var k = 0; k < 9; k++) { var n = +v[k] * (k % 2 + 1); s += n > 9 ? n - 9 : n; }
    return s % 10 === 0;
  }
  var ID_MSG = 'יש להקליד מספר זהות תקין';
  document.querySelectorAll('input[data-digits]').forEach(function (i) {
    var msg = document.createElement('span'); msg.className = 'fe'; msg.hidden = true; msg.textContent = ID_MSG; i.parentNode.appendChild(msg);
    function chk() {
      var v = i.value, init = i.getAttribute('data-init') || '';
      var bad = v !== '' && v !== init && !idOk(v);
      i.setCustomValidity(bad ? ID_MSG : ''); i.classList.toggle('bad', bad); msg.hidden = !bad; return !bad;
    }
    i._chk = chk;
    i.addEventListener('input', function () { var v = i.value.replace(/\D/g, '').slice(0, 9); if (v !== i.value) i.value = v; if (i.classList.contains('bad')) chk(); });
    i.addEventListener('blur', chk);
  });
  document.querySelectorAll('form').forEach(function (f) {
    if (!f.querySelector('input[data-digits]')) return;
    f.addEventListener('submit', function (e) {
      var first = null;
      f.querySelectorAll('input[data-digits]').forEach(function (i) { if (i._chk && !i._chk() && !first) first = i; });
      if (first) { e.preventDefault(); first.focus(); first.reportValidity(); }
    });
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

  // עיר ← רחוב ← מספר בית: כל שדה נפתח רק אחרי שהקודם נבחר מהרשימה (רק כשהספרייה נטענה)
  (function () {
    var c = document.querySelector('input[data-geo=city]'), st = document.querySelector('input[data-geo=street]'), h = document.querySelector('input[data-geo=house]');
    if (!c || !st) return;
    var cityList = null, streetList = [];
    function nm(x) { return (x || '').replace(/\s+/g, ' ').trim(); }
    function fill(id, arr) {
      var dl = document.getElementById(id); if (!dl) return; dl.textContent = '';
      arr.forEach(function (n) { var o = document.createElement('option'); o.value = n; dl.appendChild(o); });
    }
    function get(url, cb) { fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : []; }).then(cb).catch(function () {}); }
    function lock(el, on, why) { if (!el) return; el.readOnly = on; el.classList.toggle('locked', on); if (on && why) el.title = why; else el.removeAttribute('title'); }
    function inList(list, v) { v = nm(v); return list.some(function (x) { return nm(x) === v; }); }
    // שדה מלא אף פעם לא ננעל (כדי לא לאבד נתונים קיימים); ננעלים רק שדות ריקים שאין להם עדיין תנאי מוקדם
    function sync() {
      if (cityList === null) return;
      var cityOk = inList(cityList, c.value), streetOk = inList(streetList, st.value);
      lock(st, !cityOk && st.value === '', 'קודם בוחרים עיר מהרשימה');
      lock(h, !streetOk && h && h.value === '', 'קודם בוחרים רחוב מהרשימה');
    }
    function ping(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }
    var prevCity = nm(c.value), last = null;
    function loadStreets(cb) {
      var v = nm(c.value); if (v === last) { if (cb) cb(); return; } last = v;
      if (!v) { streetList = []; fill('geo-streets', []); sync(); if (cb) cb(); return; }
      get('/app/geo_lookup.php?t=streets&city=' + encodeURIComponent(v), function (a) { streetList = a; fill('geo-streets', a); sync(); if (cb) cb(); });
    }
    get('/app/geo_lookup.php?t=cities', function (a) { cityList = a; fill('geo-cities', a); loadStreets(sync); sync(); });
    c.addEventListener('input', sync);
    c.addEventListener('change', function () {
      var v = nm(c.value);
      if (inList(cityList || [], v) && v !== prevCity) { if (st.value !== '') { st.value = ''; ping(st); } if (h && h.value !== '') { h.value = ''; ping(h); } }
      prevCity = v; last = null; loadStreets();
    });
    st.addEventListener('input', sync); st.addEventListener('change', sync);
    sync();
  })();
  // כפתורים עם data-confirm: שואלים לפני מחיקה
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
