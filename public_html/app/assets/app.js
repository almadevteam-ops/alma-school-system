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
  // תיבות סימון שמגלות שדות נלווים (ועדה, רגישות, הנחה)
  document.querySelectorAll('input[data-tg]').forEach(function (c) {
    var k = c.getAttribute('data-tg');
    function t() { document.querySelectorAll('[data-g=' + k + ']').forEach(function (x) { x.hidden = !c.checked; }); }
    c.addEventListener('change', t); t();
  });
  document.querySelectorAll('select[name=status]').forEach(function (sel) {
    var w = document.getElementById('lmwrap'); if (!w) return;
    sel.addEventListener('change', function () { w.hidden = sel.value !== 'left'; });
  });
  // שינוי סטטוס: מציגים ודורשים שדה סיבה
  document.querySelectorAll('select[data-orig]').forEach(function (sel) {
    var box = document.getElementById('stnote'); if (!box) return;
    var inp = box.querySelector('input');
    function st() { var ch = sel.value !== sel.getAttribute('data-orig'); box.hidden = !ch; inp.required = ch; }
    sel.addEventListener('change', st); st();
  });
  // ולידציה מיידית בעזיבת שדה: הודעה אדומה מתחת לשדה, וחסימת שמירה עד לתיקון. נבדק רק ערך שהוקלד ושונה מהקיים.
  function idOk(v) {
    v = v.replace(/\D/g, ''); if (v.length !== 9 || /^0+$/.test(v)) return false;
    var s = 0; for (var k = 0; k < 9; k++) { var n = +v[k] * (k % 2 + 1); s += n > 9 ? n - 9 : n; }
    return s % 10 === 0;
  }
  function phoneOk(v) {
    var d = v.replace(/\D/g, '');
    if (d.indexOf('00972') === 0) d = '0' + d.slice(5); else if (d.indexOf('972') === 0 && d.length >= 11) d = '0' + d.slice(3);
    else if (d.length === 9 && /^[57]/.test(d)) d = '0' + d; else if (d.length === 8 && /^[23489]/.test(d)) d = '0' + d;
    return /^0(5\d{8}|7\d{8}|[2-489]\d{7})$/.test(d);
  }
  var bound = [];
  function bind(el, test, required) {
    if (!el || el._bound) return; el._bound = true;
    var msg = document.createElement('span'); msg.className = 'fe'; msg.hidden = true; el.parentNode.appendChild(msg);
    var init = el.defaultValue;
    function chk() {
      var v = el.value.trim(), m = '';
      if (el.disabled || el.readOnly && !required) m = '';
      else if (required && v === '') m = 'זהו שדה חובה';
      else if (v !== '' && v !== init) m = test ? test(v, el) : '';
      el.setCustomValidity(m); el.classList.toggle('bad', !!m); msg.textContent = m; msg.hidden = !m; return !m;
    }
    el._chk = chk; bound.push(el);
    el.addEventListener('input', function () { if (el.classList.contains('bad')) chk(); });
    el.addEventListener('change', chk); el.addEventListener('blur', chk);
  }
  window.almaBind = bind;
  document.querySelectorAll('input[data-digits]').forEach(function (i) {
    i.addEventListener('input', function () { var v = i.value.replace(/\D/g, '').slice(0, 9); if (v !== i.value) i.value = v; });
    bind(i, function (v) { return idOk(v) ? '' : 'יש להקליד מספר זהות תקין'; });
  });
  ['phone', 'f_phone', 'm_phone', 'home_phone'].forEach(function (n) { bind(document.querySelector('input[name=' + n + ']'), function (v) { return phoneOk(v) ? '' : 'יש להקליד מספר טלפון תקין (למשל 050-1234567)'; }); });
  bind(document.querySelector('input[name=email]'), function (v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? '' : 'יש להקליד כתובת מייל תקינה'; });
  bind(document.querySelector('input[name=house_no]'), function (v) { return /^\d{1,4}\s?[א-תA-Za-z]?(\s?[\/\-]\s?\d{1,4})?$/.test(v) ? '' : 'מספר בית לא תקין (למשל 12 או 12א)'; });
  bind(document.querySelector('form.dirty input[name=name]'), null, true);
  bind(document.querySelector('form.dirty select[name=grade]'), null, true);
  document.querySelectorAll('form').forEach(function (f) {
    if (!f.querySelector('.dirty, input[data-digits], input[name=phone]') && !f.classList.contains('dirty')) return;
    f.addEventListener('submit', function (e) {
      var first = null;
      bound.forEach(function (el) { if (f.contains(el) && el._chk && !el._chk() && !first) first = el; });
      if (first) { e.preventDefault(); first.focus(); if (first.reportValidity) first.reportValidity(); }
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
    var EXC = /^\s*(דירה|פנימיה|פנימייה)(\s|$)/;
    bind(c, function (v) { return cityList === null || EXC.test(v) || inList(cityList, v) ? '' : 'יש לבחור עיר מהרשימה'; });
    bind(st, function (v) { return cityList === null || EXC.test(v) || inList(streetList, v) ? '' : 'יש לבחור רחוב מהרשימה'; });
    sync();
  })();
  // כפתורים עם data-confirm: שואלים לפני מחיקה
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (b && !window.confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var c = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!c) return;
    var t = document.getElementById(c.getAttribute('data-copy'));
    if (!t) return;
    t.select();
    try { document.execCommand('copy'); c.textContent = '✓ הועתק'; } catch (x) { /* ignore */ }
  });
  function regLinks() {
    var w = document.getElementById('rwa'), m = document.getElementById('rma'), p = document.getElementById('rp'), r = document.getElementById('rm');
    if (!w || !m) return;
    function up() {
      var n = (p.value || '').replace(/\D/g, '');
      if (n.charAt(0) === '0') n = '972' + n.slice(1);
      w.href = n ? 'https://wa.me/' + n + '?text=' + w.getAttribute('data-wa') : '#';
      m.href = r.value ? 'mailto:' + encodeURIComponent(r.value) + '?subject=' + m.getAttribute('data-sub') + '&body=' + m.getAttribute('data-body') : '#';
    }
    p.addEventListener('input', up); r.addEventListener('input', up); up();
  }
  regLinks();
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-chk]') : null;
    if (!b) return;
    var on = b.getAttribute('data-on') === '1';
    document.querySelectorAll('input.' + b.getAttribute('data-chk')).forEach(function (c) { c.checked = on; });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-print]') : null;
    if (b) window.print();
  });
  (function () {
    var more = document.getElementById('more'), list = document.querySelector('.ut.stu');
    if (!more || !list || !('IntersectionObserver' in window)) return;
    var busy = false, url = more.getAttribute('data-url');
    more.className = 'more';
    more.innerHTML = '<span class="mu">טוען עוד תלמידות…</span>';
    function load() {
      if (busy || !url) return;
      busy = true;
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(function (r) {
        var m = r.headers.get('X-More') === '1';
        return r.text().then(function (t) { return [m, t]; });
      }).then(function (a) {
        var tmp = document.createElement('div');
        tmp.innerHTML = a[1];
        while (tmp.firstChild) list.appendChild(tmp.firstChild);
        if (a[0]) { url = url.replace(/p=(\d+)/, function (_, n) { return 'p=' + (parseInt(n, 10) + 1); }); busy = false; io.observe(more); }
        else { more.remove(); }
      }).catch(function () { busy = false; more.innerHTML = '<a class="btn sec" href="' + url.replace('&chunk=1', '') + '">שגיאת טעינה – לחצו לנסות שוב</a>'; });
    }
    var io = new IntersectionObserver(function (en) { if (en[0].isIntersecting) { io.unobserve(more); load(); } }, { rootMargin: '600px' });
    io.observe(more);
  })();
  // הורים גרושים: מציגים ודורשים צירוף מסמך חתום
  document.querySelectorAll('select[name=marital]').forEach(function (sel) {
    var box = document.querySelector('[data-g=div]'); if (!box) return;
    var inp = box.querySelector('input[type=file]');
    function t() { var d = sel.value === 'divorced'; box.hidden = !d; if (inp) inp.required = d; }
    sel.addEventListener('change', t); t();
  });
})();
