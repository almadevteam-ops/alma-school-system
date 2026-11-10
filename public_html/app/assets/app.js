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
    if (!el || el._bound) return; el._bound = true; required = required || el.required;
    var msg = document.createElement('span'); msg.className = 'fe'; msg.hidden = true; el.parentNode.appendChild(msg);
    var init = el.defaultValue;
    function chk() {
      var v = el.value.trim(), m = '';
      var empty = false;
      if (el.disabled || el.readOnly && !required) m = '';
      else if (required && v === '') empty = !(el.name === 'addr' && /^\s*(דירה|פנימיי?ה)(\s|$)/.test((document.querySelector('[name=city]') || {}).value || ''));   // שדה חובה ריק: רק צביעה באדום, בלי הודעה
      else if (v !== '' && v !== init) m = test ? test(v, el) : '';
      el.classList.toggle('bad', !!m || empty); msg.textContent = m; msg.hidden = !m; return !m && !empty;
    }
    el._chk = chk; bound.push(el);
    el.addEventListener('input', function () { if (el.classList.contains('bad')) chk(); });
    el.addEventListener('change', chk); el.addEventListener('blur', chk);
  }
  window.almaBind = bind;
  var firstBad = document.querySelector('form.dirty .bad'); if (firstBad) { firstBad.scrollIntoView({ block: 'center' }); }
  document.querySelectorAll('input[data-digits]').forEach(function (i) {
    i.addEventListener('input', function () { var v = i.value.replace(/\D/g, '').slice(0, 9); if (v !== i.value) i.value = v; });
    bind(i, function (v) { return idOk(v) ? '' : 'יש להקליד מספר זהות תקין'; });
  });
  ['phone', 'f_phone', 'm_phone', 'home_phone'].forEach(function (n) { bind(document.querySelector('input[name=' + n + ']'), function (v) { return phoneOk(v) ? '' : 'יש להקליד מספר טלפון תקין (למשל 050-1234567)'; }); });
  bind(document.querySelector('input[name=email]'), function (v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? '' : 'יש להקליד כתובת מייל תקינה'; });
  bind(document.querySelector('input[name=house_no]'), function (v) { return /^\d{1,4}\s?[א-תA-Za-z]?(\s?[\/\-]\s?\d{1,4})?$/.test(v) ? '' : 'מספר בית לא תקין (למשל 12 או 12א)'; });
  document.querySelectorAll('form.dirty [required]').forEach(function (el) { bind(el, null, true); });
  document.querySelectorAll('form').forEach(function (f) {
    if (!f.querySelector('.dirty, input[data-digits], input[name=phone]') && !f.classList.contains('dirty')) return;
    f.addEventListener('submit', function (e) {
      var first = null;   // כל השדות הלא תקינים נצבעים יחד באדום; הפוקוס לראשון
      bound.forEach(function (el) { if (f.contains(el) && el._chk && !el._chk() && !first) first = el; });
      if (first) { e.preventDefault(); first.focus(); first.scrollIntoView({ block: 'center' }); }
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
  // תצוגה מקדימה של חלוקת תשלום בין החודשים
  (function () {
    var box = document.getElementById('pvbox'); if (!box) return;
    var f = box.previousElementSibling; if (!f || f.tagName !== 'FORM') return;
    var amt = f.querySelector('[name=amount]'), tg = f.querySelector('[name=target]'), fm = f.querySelector('[name=for_month]'), tmr = null;
    function fmt(n) { return Number(n).toLocaleString('he-IL', { maximumFractionDigits: 2 }) + ' ₪'; }
    function run() {
      if (tg.value !== 'tuition') { box.textContent = ''; return; }
      fetch(box.getAttribute('data-url') + '&amount=' + encodeURIComponent(amt.value) + '&for_month=' + encodeURIComponent(fm.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (!j.rows.length && !j.credit) { box.textContent = ''; return; }
          var t = 'החלוקה: ' + j.rows.map(function (x) { return x.m + ' ' + fmt(x.a) + (x.f ? ' (מראש)' : ''); }).join(' · ');
          if (j.surplus > 0.004) t += ' · יתרת זכות מעבר לשנה ' + fmt(j.surplus);
          box.textContent = t;
        }).catch(function () { box.textContent = ''; });
    }
    function sched() { clearTimeout(tmr); tmr = setTimeout(run, 250); }
    amt.addEventListener('input', sched); tg.addEventListener('change', sched); fm.addEventListener('change', sched);
  })();
  // דיוור: ספירת נמענים חיה בטופס, ושליחה בהדרגה עם התקדמות
  (function () {
    var f = document.getElementById('mailform');
    if (f) {
      var out = document.getElementById('mailn'), tmr = null;
      var run = function () {
        var p = new URLSearchParams();
        f.querySelectorAll('input[name="g[]"]:checked,input[name="c[]"]:checked').forEach(function (i) { p.append(i.name, i.value); });
        p.append('debt', f.elements.debt.value);
        fetch(f.getAttribute('data-url') + '&' + p.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
          out.textContent = 'ההודעה תישלח ל-' + j.n + ' כתובות מייל' + (j.nomail ? ' (' + j.nomail + ' תלמידות בלי מייל תקין בכרטיס)' : '') + '.';
        }).catch(function () { out.textContent = ''; });
      };
      f.addEventListener('change', function () { clearTimeout(tmr); tmr = setTimeout(run, 200); }); run();
    }
    var box = document.getElementById('mailrun'); if (!box) return;
    var go = false, busy = false, pg = box.parentNode.querySelector('progress');
    var set = function (k, v) { var el = box.querySelector('[data-k=' + k + ']'); if (el) el.textContent = v; };
    var post = function (a) { var b = new URLSearchParams({ act: a, id: box.getAttribute('data-id'), _t: box.getAttribute('data-t') }); return fetch('mailing.php', { method: 'POST', credentials: 'same-origin', body: b }); };
    var step = function () {
      if (!go || busy) return; busy = true;
      post('step').then(function (r) { return r.json(); }).then(function (j) {
        busy = false; set('sent', j.sent); set('failed', j.failed); set('pending', j.pending);
        if (pg) pg.value = j.sent + j.failed;
        if (j.pending > 0 && go) { set('st', 'בשליחה'); setTimeout(step, 300); }
        else { go = false; set('st', j.pending > 0 ? 'נעצר' : 'הסתיים'); if (!j.pending) box.querySelectorAll('button[data-a]').forEach(function (x) { x.hidden = true; }); set('note', j.pending > 0 ? '' : 'הסתיים. ' + (j.failed ? 'חלק מהכתובות נכשלו – רענני את הדף לרשימה ולניסיון חוזר.' : 'כל ההודעות נשלחו.')); }
      }).catch(function () { busy = false; go = false; set('note', 'התקשורת נפסקה. לחצי "התחלת / המשך שליחה" כדי להמשיך מהמקום שבו עצרנו.'); });
    };
    box.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-a]'); if (!b) return;
      if (b.getAttribute('data-a') === 'start') { go = true; step(); }
      else { go = false; post('stop').then(function () { location.reload(); }); }
    });
    if (box.getAttribute('data-go') === '1') { go = true; step(); }
  })();
  // ===== קריאת ספח: פרסר (פונקציות טהורות; נבדק גם ב-node) =====
  /*SPACH:BEGIN*/
  var SP_MALE = /^(זכר|זבר|זכד|זר)$/, SP_FEM = /^(נקבה|נקבת|נקבא|נקב)$/;
  var SP_MONTHS = ['תשרי', 'חשון', 'חשוון', 'מרחשון', 'כסלו', 'טבת', 'שבט', 'אדר', 'ניסן', 'אייר', 'סיון', 'סיוון', 'תמוז', 'אב', 'אלול'];
  function spClean(t) { return String(t || '').replace(/[‎‏‪-‮]/g, '').trim(); }
  function spHebWord(t) { var h = t.replace(/[.,:;|!()\[\]_*~]/g, ''); return /^[א-ת'"׳״\-]+$/.test(h) ? h : ''; }
  function spDate(t) {
    var m = /(\d{1,2})\s?[.\/\-]\s?(\d{1,2})\s?[.\/\-]\s?(\d{4})/.exec(t); if (!m) return '';
    var d = +m[1], mo = +m[2], y = +m[3], dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d || y < 1985 || dt > new Date()) return '';
    return y + '-' + ('0' + mo).slice(-2) + '-' + ('0' + d).slice(-2);
  }
  function spIsNameWord(h) {
    if (h.length < 2 || SP_MALE.test(h) || SP_FEM.test(h)) return false;
    if (/מעמד|ישראלית|אזרחות|התש|תשע|תשפ/.test(h) || /['"׳״]/.test(h) && h.length <= 5) return false;
    if (SP_MONTHS.indexOf(h.replace(/^ב/, '')) >= 0) return false;
    return true;
  }
  function spLev(a, b) { var p = [], i, j; for (j = 0; j <= b.length; j++) p[j] = j; for (i = 1; i <= a.length; i++) { var c = [i]; for (j = 1; j <= b.length; j++) c[j] = Math.min(p[j] + 1, c[j - 1] + 1, p[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)); p = c; } return p[b.length]; }
  function spHamming(a, b) { if (a.length !== b.length) return 99; var n = 0; for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) n++; return n; }
  // words: [{t,x0,y0,x1,y1}] (קואורדינטות של התמונה), W/H: מידות התמונה, exclude: ת"ז שלא נכללות (התלמידה/ההורים)
  function spParse(words, W, H, exclude) {
    exclude = exclude || [];
    var ws = [];
    words.forEach(function (w) { var t = spClean(w.t); if (t) ws.push({ t: t, x0: w.x0, y0: w.y0, x1: w.x1, y1: w.y1, cx: (w.x0 + w.x1) / 2, cy: (w.y0 + w.y1) / 2 }); });
    // שורות לפי גובה
    var hs = ws.map(function (w) { return w.y1 - w.y0; }).sort(function (a, b) { return a - b; }), mh = hs.length ? hs[Math.floor(hs.length / 2)] : 20;
    ws.sort(function (a, b) { return a.cy - b.cy; });
    var lines = [];
    ws.forEach(function (w) { var L = lines[lines.length - 1]; if (L && Math.abs(w.cy - L.cy) <= mh * 0.6) { L.w.push(w); L.cy = (L.cy * (L.w.length - 1) + w.cy) / L.w.length; } else lines.push({ cy: w.cy, w: [w] }); });
    var cands = [];
    lines.forEach(function (L) {
      var toks = L.w.sort(function (a, b) { return a.x0 - b.x0; }).filter(function (w) {
        var t = w.t.replace(/^[^\d]+|[^\d]+$/g, ''); return t !== '' && /^\d+$/.test(t) && !spDate(w.t);
      }).map(function (w) { return { d: w.t.replace(/\D/g, ''), w: w }; });
      var found = [];
      for (var i = 0; i < toks.length; i++) {
        var acc = '';
        for (var j = i; j < toks.length; j++) {
          if (j > i && toks[j].w.x0 - toks[j - 1].w.x1 > mh * 4) break;
          acc += toks[j].d; if (acc.length >= 9) break;
        }
        if (acc.length === 9) found.push({ s: acc, i: i, j: j, valid: idOk(acc) && !/^0+$/.test(acc) });
      }
      found.forEach(function (f) {
        var a = toks[f.i].w, b = toks[f.j].w;
        if (!f.valid && found.some(function (g) { return g.valid && !(g.j < f.i || g.i > f.j); })) return;   // מתחפפת עם תקינה
        cands.push({ s: f.s, valid: f.valid, x0: a.x0, x1: b.x1, y0: Math.min(a.y0, b.y0), y1: Math.max(a.y1, b.y1), cx: (a.x0 + b.x1) / 2, cy: (a.cy + b.cy) / 2 });
      });
    });
    var markers = [];
    ws.forEach(function (w) {
      if (!/מעמד/.test(w.t)) return;
      if (!markers.some(function (m) { return Math.abs(m.cy - w.cy) < H * 0.02 && Math.abs(m.cx - w.cx) < W * 0.25; })) markers.push(w);
    });
    var anyMarker = ws.filter(function (w) { return /מעמד|ישראלית|אזרחות/.test(w.t); });
    function hasStatus(c) { return anyMarker.some(function (m) { var dy = m.cy - c.cy; return dy >= -H * 0.012 && dy <= H * 0.085 && Math.abs(m.cx - c.cx) < W * 0.45; }); }
    var children = [], bad = [], seen = {};
    cands.forEach(function (c) {
      if (c.valid) {
        if (exclude.indexOf(c.s) >= 0 || seen[c.s] || !hasStatus(c)) return;
        seen[c.s] = 1;
        var inX = function (w) { return w.cx >= c.x0 - W * 0.05 && w.cx <= c.x0 + W * 0.42; };
        var nameWs = ws.filter(function (w) { return inX(w) && w.cy >= c.cy - H * 0.075 && w.cy <= c.cy + H * 0.012; }).map(function (w) { return { h: spHebWord(w.t), w: w }; }).filter(function (o) { return o.h && spIsNameWord(o.h); });
        var nl = []; nameWs.sort(function (a, b) { return a.w.cy - b.w.cy; }).forEach(function (o) { var L = nl[nl.length - 1]; if (L && Math.abs(o.w.cy - L.cy) <= H * 0.012) L.t.push(o); else nl.push({ cy: o.w.cy, t: [o] }); });
        var strs = nl.map(function (L) { return L.t.sort(function (a, b) { return b.w.x1 - a.w.x1; }).map(function (o) { return o.h; }).join(' '); });
        var g = ''; ws.forEach(function (w) { if (g || !inX(w) || w.cy < c.cy - H * 0.06 || w.cy > c.cy + H * 0.03) return; var h = spHebWord(w.t); if (SP_MALE.test(h)) g = 'm'; else if (SP_FEM.test(h)) g = 'f'; });
        var dob = '', bd = 1e9; ws.forEach(function (w) { if (!inX(w) || w.cy < c.cy - H * 0.012 || w.cy > c.cy + H * 0.07) return; var d = spDate(w.t); if (d && Math.abs(w.cy - c.cy) < bd) { bd = Math.abs(w.cy - c.cy); dob = d; } });
        children.push({ idn: c.s, lines: strs, g: g, dob: dob, y: c.cy, x: c.cx });
      } else if (hasStatus(c) && !cands.some(function (v) { return v.valid && Math.abs(v.cx - c.cx) < W * 0.05 && Math.abs(v.cy - c.cy) < H * 0.02; })) {
        bad.push({ s: c.s, x: c.cx, y: c.cy });
      }
    });
    var fam = {}; ws.forEach(function (w) { var h = spHebWord(w.t); if (h && spIsNameWord(h) && h.length >= 3) fam[h] = (fam[h] || 0) + 1; });
    children.sort(function (a, b) { return a.y - b.y || b.x - a.x; });
    return { fam: fam, children: children, bad: bad, markers: markers.length, anyValid: cands.filter(function (c) { return c.valid && exclude.indexOf(c.s) < 0; }).map(function (c) { return c.s; }) };
  }
  // מאחדת תוצאות של כמה ניסיונות קריאה (גרסאות עיבוד/עמודים) בהצבעה לכל שדה. ok = אפשר לשמור בלי התערבות.
  function spMerge(list) {
    var by = {}, order = [], M = 0, famAll = {};
    function vote(o, k, v) { if (!v) return; o.v[k] = o.v[k] || {}; o.v[k][v] = (o.v[k][v] || 0) + 1; }
    function top(m) { var best = '', n = 0; for (var k in (m || {})) if (m[k] > n) { n = m[k]; best = k; } return best; }
    list.forEach(function (r) {
      M = Math.max(M, r.markers);
      for (var w in r.fam) famAll[w] = (famAll[w] || 0) + r.fam[w];
    });
    var famTop = '', fn_ = 0; for (var w in famAll) if (famAll[w] > fn_) { fn_ = famAll[w]; famTop = w; }
    if (fn_ < 3 * list.length) famTop = '';   // שם משפחה משותף מוכר רק אם חוזר מספיק (כותרת + בלוקים)
    list.forEach(function (r) {
      r.children.forEach(function (c) {
        var o = by[c.idn]; if (!o) { o = by[c.idn] = { idn: c.idn, v: {} }; order.push(c.idn); }
        var lines = c.lines.map(function (l) { return famTop ? l.split(' ').filter(function (x) { return x !== famTop; }).join(' ').trim() : l; }).filter(function (l) { return l; });
        if (famTop && c.lines.join(' ').split(' ').indexOf(famTop) < 0 && c.lines.length >= 2) lines = c.lines.slice(1);
        vote(o, 'fn', famTop ? lines.join(' ') : (c.lines.length >= 2 ? c.lines.slice(1).join(' ') : c.lines.join(' ')));
        if (!famTop && c.lines.length >= 2) vote(o, 'ln', c.lines[0]);
        vote(o, 'g', c.g); vote(o, 'dob', c.dob);
      });
    });
    var kids = order.map(function (k) { var o = by[k]; return { idn: k, ln: famTop || top(o.v.ln), fn: top(o.v.fn), g: top(o.v.g), dob: top(o.v.dob) }; });
    var bad = [];
    list.forEach(function (r) { r.bad.forEach(function (b) { if (!order.some(function (s) { return spHamming(s, b.s) <= 2 || spLev(s, b.s) <= 3; }) && bad.indexOf(b.s) < 0) bad.push(b.s); }); });
    return { children: kids, bad: bad, markers: M, ok: kids.length > 0 && M > 0 && bad.length === 0 && kids.length >= M };
  }
  // מאחדת תוצאות של עמודים שונים לאותה גרסת עיבוד
  function spCombine(list) {
    var o = { fam: {}, children: [], bad: [], markers: 0, anyValid: [] };
    list.forEach(function (r) { o.children = o.children.concat(r.children); o.bad = o.bad.concat(r.bad); o.markers += r.markers; o.anyValid = o.anyValid.concat(r.anyValid); for (var w in r.fam) o.fam[w] = (o.fam[w] || 0) + r.fam[w]; });
    return o;
  }
  /*SPACH:END*/
  // ===== קריאת ספח: הרצת OCR בדפדפן (ספריות מקומיות, בלי שירות חיצוני) + ממשק =====
  var SP_BASE = (document.currentScript && document.currentScript.src ? document.currentScript.src.replace(/app\.js(\?.*)?$/, '') : 'assets/');
  var spLoaded = {}, spWorker = null;
  function spLoad(src) {
    if (spLoaded[src]) return spLoaded[src];
    return spLoaded[src] = new Promise(function (res, rej) {
      var s = document.createElement('script'); s.src = SP_BASE + src; s.onload = res; s.onerror = function () { rej(new Error('טעינת ' + src + ' נכשלה')); }; document.head.appendChild(s);
    });
  }
  function spGetWorker(say) {
    if (spWorker) return spWorker;
    return spWorker = spLoad('vendor/tess/tesseract.min.js').then(function () {
      say('טוען את מנוע הקריאה (בפעם הראשונה לוקח כמה שניות)…');
      return window.Tesseract.createWorker('heb+eng', 1, {
        workerPath: SP_BASE + 'vendor/tess/worker.min.js', corePath: SP_BASE + 'vendor/tess/', langPath: SP_BASE + 'vendor/tess/lang/', gzip: false, workerBlobURL: false
      });
    }).then(function (w) { return w.setParameters({ tessedit_pageseg_mode: '11', preserve_interword_spaces: '1' }).then(function () { return w; }); });
  }
  function spFit(src, sw, sh, maxSide) {
    var k = Math.min(1, maxSide / Math.max(sw, sh)), c = document.createElement('canvas'); c.width = Math.round(sw * k); c.height = Math.round(sh * k);
    var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(src, 0, 0, c.width, c.height); return c;
  }
  // קובץ (תמונה או PDF) → רשימת קנבסים (עמודים)
  function spCanvases(blob, mime) {
    if (mime === 'application/pdf' || /\.pdf$/i.test(blob.name || '')) {
      return spLoad('vendor/pdf/pdf.min.js').then(function () {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = SP_BASE + 'vendor/pdf/pdf.worker.min.js';
        return blob.arrayBuffer();
      }).then(function (buf) { return window.pdfjsLib.getDocument({ data: buf, isEvalSupported: false }).promise; }).then(function (pdf) {
        var out = [], chain = Promise.resolve();
        for (var p = 1; p <= Math.min(pdf.numPages, 4); p++) (function (n) {
          chain = chain.then(function () { return pdf.getPage(n); }).then(function (pg) {
            var v1 = pg.getViewport({ scale: 1 }), v = pg.getViewport({ scale: 2400 / Math.max(v1.width, v1.height) });
            var c = document.createElement('canvas'); c.width = Math.round(v.width); c.height = Math.round(v.height);
            return pg.render({ canvasContext: c.getContext('2d'), viewport: v }).promise.then(function () { out.push(c); });
          });
        })(p);
        return chain.then(function () { return out; });
      });
    }
    return createImageBitmap(blob).then(function (bm) { var c = spFit(bm, bm.width, bm.height, 2400); if (bm.close) bm.close(); return [c]; });
  }
  function spVariant(c, T) {
    var o = document.createElement('canvas'); o.width = c.width; o.height = c.height; var x = o.getContext('2d'); x.drawImage(c, 0, 0);
    var im = x.getImageData(0, 0, o.width, o.height), d = im.data;
    for (var i = 0; i < d.length; i += 4) { var l = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]; var v = T ? (l > T ? 255 : 0) : l; d[i] = d[i + 1] = d[i + 2] = v; }
    x.putImageData(im, 0, 0); return o;
  }
  function spWordsOf(data) {
    var out = [];
    function add(w) { if (w && w.text && w.bbox) out.push({ t: w.text, x0: w.bbox.x0, y0: w.bbox.y0, x1: w.bbox.x1, y1: w.bbox.y1 }); }
    if (data.words && data.words.length) data.words.forEach(add);
    else (data.blocks || []).forEach(function (b) { (b.paragraphs || []).forEach(function (p) { (p.lines || []).forEach(function (l) { (l.words || []).forEach(add); }); }); });
    return out;
  }
  // קוראת ספח: מחזירה {children, bad, ok}. say(טקסט) לעדכון התקדמות.
  function spRead(blobs, exclude, say) {
    var tried = [];
    return Promise.all(blobs.map(function (b) { return spCanvases(b, b.type); })).then(function (arr) { return [].concat.apply([], arr); }).then(function (pages) {
      return spGetWorker(say).then(function (w) {
        var variants = [110, 90, 0, 130], chain = Promise.resolve(), merged = null;
        variants.forEach(function (T, vi) {
          chain = chain.then(function () {
            if (merged && merged.ok && merged.children.every(function (k) { return k.dob; })) return;
            say('קורא את הספח… (שלב ' + (vi + 1) + ' מתוך ' + variants.length + ')');
            var per = [], c2 = Promise.resolve();
            pages.forEach(function (pg) {
              c2 = c2.then(function () { var v = spVariant(pg, T); return w.recognize(v, {}, { blocks: true }); }).then(function (r) { per.push(spParse(spWordsOf(r.data), pg.width, pg.height, exclude)); });
            });
            return c2.then(function () { tried.push(spCombine(per)); merged = spMerge(tried); });
          });
        });
        return chain.then(function () { return merged; });
      });
    });
  }
  window.__spach = { parse: spParse, merge: spMerge, read: spRead };

  // ---- ממשק: אזור קריאת ספח בטופס תלמידה חדשה ובכרטיס ----
  function spEsc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function spInitBox(box) {
    var stat = box.querySelector('.spstat'), man = box.querySelector('.spman'), rowsEl = box.querySelector('.sprows'), hid = box.querySelector('.sphid') || document.createElement('div'), srcIn = box.querySelector('input[name=sp_src]');
    var form = box.closest('form') || document.querySelector('form[data-spform]');
    var mode = box.getAttribute('data-mode'), busy = false;
    function say(t, cls) { stat.className = 'spstat' + (cls ? ' ' + cls : ''); stat.textContent = t; }
    function exclude() {
      var ex = []; ['idn', 'f_id', 'm_id'].forEach(function (n) { var el = document.querySelector('form.dirty [name=' + n + ']'); if (el && el.value) ex.push(el.value.replace(/\D/g, '')); });
      (box.getAttribute('data-exclude') || '').split(',').forEach(function (x) { if (x) ex.push(x); }); return ex;
    }
    function rowHtml(i, k) {
      return '<tr class="sprow"><td><input name="sp[' + i + '][fn]" maxlength="60" placeholder="שם פרטי" value="' + spEsc(k.fn) + '" data-f="fn"></td>' +
        '<td><input name="sp[' + i + '][ln]" maxlength="60" placeholder="שם משפחה" value="' + spEsc(k.ln) + '" data-f="ln"></td>' +
        '<td><input name="sp[' + i + '][idn]" maxlength="9" inputmode="numeric" dir="ltr" placeholder="ת״ז" value="' + spEsc(k.idn) + '" data-f="idn"></td>' +
        '<td><input name="sp[' + i + '][dob]" type="date" dir="ltr" value="' + spEsc(k.dob) + '" data-f="dob"></td>' +
        '<td><select name="sp[' + i + '][g]" data-f="g"><option value="">—</option><option value="f"' + (k.g === 'f' ? ' selected' : '') + '>נקבה</option><option value="m"' + (k.g === 'm' ? ' selected' : '') + '>זכר</option></select></td>' +
        '<td><button type="button" class="btn sec" data-spdel>הסרה</button></td></tr>';
    }
    function renumber() {
      var n = 0; rowsEl.querySelectorAll('tr.sprow').forEach(function (tr) { tr.querySelectorAll('[data-f]').forEach(function (el) { el.name = 'sp[' + n + '][' + el.getAttribute('data-f') + ']'; }); n++; });
    }
    function showManual(rows, msg) {
      hid.textContent = ''; if (srcIn) srcIn.value = 'manual';
      rowsEl.innerHTML = ''; var tb = document.createElement('table'); tb.innerHTML = '<tbody>' + (rows.length ? rows : [{ fn: '', ln: '', idn: '', dob: '', g: '' }]).map(function (k, i) { return rowHtml(i, k); }).join('') + '</tbody>';
      rowsEl.appendChild(tb); man.hidden = false; if (msg) say(msg, 'er'); validateRows(false);
    }
    function validateRows(strict) {
      var ok = true;
      rowsEl.querySelectorAll('tr.sprow').forEach(function (tr) {
        var idn = tr.querySelector('[data-f=idn]'), fn = tr.querySelector('[data-f=fn]'), v = idn.value.replace(/\D/g, ''), any = v || fn.value.trim();
        var badId = any && !(v.length === 9 && idOk(v) && !/^0+$/.test(v)); var badFn = any && !fn.value.trim();
        if (strict || idn.classList.contains('bad') || v.length === 9) idn.classList.toggle('bad', !!badId);
        if (strict) fn.classList.toggle('bad', !!badFn);
        if (badId || badFn) ok = false;
      });
      return ok;
    }
    function applyResult(res) {
      var kids = res.children.map(function (k) { return k; });
      if (res.ok) {
        var names = kids.map(function (k) { return (k.fn + ' ' + k.ln).trim() || k.idn; }).join(', ');
        if (mode === 'card') {
          say('נקראו ' + kids.length + ' אחים/אחיות: ' + names + '. שומר…');
          var fd = new FormData(); fd.append('_t', box.getAttribute('data-t')); fd.append('student_id', box.getAttribute('data-sid')); fd.append('json', '1'); fd.append('src', 'ocr');
          kids.forEach(function (k, i) { ['idn', 'ln', 'fn', 'dob', 'g'].forEach(function (f) { fd.append('sp[' + i + '][' + f + ']', k[f]); }); });
          fetch('leads_save.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            say(j.msg, j.bad && j.bad.length ? 'er' : 'ok'); setTimeout(function () { location.href = 'student.php?id=' + box.getAttribute('data-sid') + '&open=leads,docs'; }, 1500);
          }).catch(function () { showManual(kids, 'שגיאה בקריאת הנתונים, נא לשלוח צילום ברור יותר או להקליד ידנית.'); });
        } else {
          hid.textContent = ''; if (srcIn) srcIn.value = 'ocr';
          kids.forEach(function (k, i) { ['idn', 'ln', 'fn', 'dob', 'g'].forEach(function (f) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'sp[' + i + '][' + f + ']'; h.value = k[f]; hid.appendChild(h); }); });
          say('נקראו ' + kids.length + ' אחים/אחיות: ' + names + '. יישמרו יחד עם התלמידה.', 'ok'); man.hidden = true;
        }
        return;
      }
      showManual(kids, 'שגיאה בקריאת הנתונים, נא לשלוח צילום ברור יותר או להקליד ידנית.');
    }
    function run(blobs) {
      if (busy) return; busy = true; man.hidden = true; hid.textContent = '';
      say('קורא את הספח…');
      spRead(blobs, exclude(), function (t) { say(t); }).then(applyResult).catch(function (e) {
        if (window.console) console.error(e); showManual([], 'שגיאה בקריאת הנתונים, נא לשלוח צילום ברור יותר או להקליד ידנית.');
      }).then(function () { busy = false; });
    }
    var fin = box.querySelector('input[type=file][data-spfile]');
    if (fin) fin.addEventListener('change', function () { if (fin.files && fin.files.length) run(Array.prototype.slice.call(fin.files)); else { hid.textContent = ''; say(''); } });
    (mode === 'card' ? document.querySelectorAll('[data-spach]') : []).forEach(function (b) {
      b.addEventListener('click', function () {
        say('מוריד את הקובץ…');
        fetch(b.getAttribute('data-spach'), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('http'); return r.blob(); }).then(function (bl) { run([bl.type ? bl : new Blob([bl], { type: b.getAttribute('data-mime') || '' })]); }).catch(function () { say('הורדת הקובץ נכשלה.', 'er'); });
      });
    });
    var opn = box.querySelector('[data-spopen]'); if (opn) opn.addEventListener('click', function () { showManual([], ''); opn.hidden = true; });
    var add = box.querySelector('[data-spadd]');
    if (add) add.addEventListener('click', function () {
      var tb = rowsEl.querySelector('tbody'); if (!tb) { showManual([], ''); return; }
      var tr = document.createElement('tbody'); tr.innerHTML = rowHtml(0, { fn: '', ln: '', idn: '', dob: '', g: '' }); tb.appendChild(tr.firstChild); renumber();
    });
    rowsEl.addEventListener('click', function (e) { var b = e.target.closest('[data-spdel]'); if (!b) return; b.closest('tr').remove(); renumber(); });
    rowsEl.addEventListener('input', function (e) { if (e.target.dataset.f === 'idn') e.target.value = e.target.value.replace(/\D/g, '').slice(0, 9); if (e.target.classList.contains('bad')) validateRows(false); });
    rowsEl.addEventListener('blur', function () { validateRows(true); }, true);
    // חסימת שליחה רק על שורות ידניות שהוקלדו ולא תקינות (שורה ריקה מתעלמים)
    var f2 = man.tagName === 'FORM' ? man : box.closest('form'); if (f2) f2.addEventListener('submit', function (e) {
      if (man.hidden) return;
      rowsEl.querySelectorAll('tr.sprow').forEach(function (tr) { var a = tr.querySelector('[data-f=idn]').value, b = tr.querySelector('[data-f=fn]').value; if (!a.trim() && !b.trim()) tr.remove(); }); renumber();
      if (!validateRows(true)) { e.preventDefault(); e.stopImmediatePropagation(); var first = rowsEl.querySelector('.bad'); if (first) { first.focus(); first.scrollIntoView({ block: 'center' }); } }
    }, true);
  }
  document.querySelectorAll('.spbox').forEach(spInitBox);
})();
