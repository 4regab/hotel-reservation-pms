// Small progressive enhancements. Every page works without this file.
(function () {
  'use strict';

  // Landing page motion. The CSS only hides things when this class is present, so without JavaScript all content shows.
  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!calm && 'IntersectionObserver' in window) {
    document.documentElement.classList.add('js');

    // Story words turn from grey to ink as the paragraph moves up the screen. Screen readers still get the plain sentence.
    document.querySelectorAll('[data-words]').forEach(function (p) {
      var text = p.textContent.trim();
      p.textContent = '';
      var plain = document.createElement('span');
      plain.className = 'sr-only';
      plain.textContent = text;
      p.appendChild(plain);
      var words = text.split(/\s+/).map(function (w, i) {
        var s = document.createElement('span');
        s.className = 'w';
        s.setAttribute('aria-hidden', 'true');
        s.textContent = (i ? ' ' : '') + w;
        p.appendChild(s);
        return s;
      });
      var ticking = false;
      function paint() {
        ticking = false;
        var r = p.getBoundingClientRect();
        var h = window.innerHeight;
        var progress = Math.min(1, Math.max(0, (h * 0.85 - r.top) / (h * 0.55)));
        var lit = Math.round(progress * words.length);
        words.forEach(function (w, i) { w.classList.toggle('on', i < lit); });
      }
      window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(paint); } }, { passive: true });
      paint();
    });

    // Stay chapters: the section is pinned while the page scrolls four screens. Each next photograph is
    // uncovered from its foot over the last and settles from a slight zoom; the panel shows its chapter.
    // Screens under 600px tall keep the plain row of cards.
    document.querySelectorAll('[data-chapters]').forEach(function (root) {
      if (window.innerHeight < 600) return;
      var box = root.querySelector('.chapters-box');
      var list = root.querySelector('.chapters-list');
      var items = [].slice.call(root.querySelectorAll('.chapter'));
      var pics = items.map(function (li) { return li.querySelector('.chapter-pic'); });
      var n = items.length;
      var stage = document.createElement('div');
      stage.className = 'chapters-stage';
      var panel = document.createElement('div');
      panel.className = 'chapters-panel';
      var count = document.createElement('p');
      count.className = 'chapter-count';
      count.setAttribute('aria-hidden', 'true');
      panel.appendChild(count);
      pics.forEach(function (p) { stage.appendChild(p); });
      panel.appendChild(list);
      stage.appendChild(panel);
      box.appendChild(stage);
      root.classList.add('is-live');
      var on = -1;
      var pad = function (v) { return (v < 10 ? '0' : '') + v; };
      var ease = function (t) { return 1 - Math.pow(1 - t, 3); };
      function draw() {
        ticking2 = false;
        var r = root.getBoundingClientRect();
        var p = Math.min(1, Math.max(0, -r.top / Math.max(1, r.height - window.innerHeight)));
        var seg = 1 / n, k = 0;
        pics.forEach(function (pic, i) {
          var u = i === 0 ? 1 : Math.min(1, Math.max(0, (p - (i - 0.25) * seg) / (0.45 * seg)));
          var e = ease(u);
          pic.style.setProperty('--c', ((1 - e) * 100).toFixed(2) + '%');
          pic.style.setProperty('--z', (1.06 - 0.06 * e).toFixed(4));
          if (u >= 0.5) k = i;
        });
        if (k !== on) {
          on = k;
          items.forEach(function (li, i) { li.classList.toggle('is-on', i === k); });
          count.textContent = pad(k + 1) + ' / ' + pad(n);
        }
      }
      var ticking2 = false;
      window.addEventListener('scroll', function () { if (!ticking2) { ticking2 = true; requestAnimationFrame(draw); } }, { passive: true });
      draw();
    });
  }

  // Phone menu (a native <details>): Escape or a click outside closes it, and focus goes back to the menu button.
  document.querySelectorAll('[data-menu]').forEach(function (menu) {
    var btn = menu.querySelector('summary');
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && menu.open) { menu.open = false; btn.focus(); }
    });
    document.addEventListener('click', function (ev) { if (menu.open && !menu.contains(ev.target)) menu.open = false; });
  });

  // Keep check-out after check-in in date pickers.
  document.querySelectorAll('[data-date-pair]').forEach(function (form) {
    var inEl = form.querySelector('[data-checkin]');
    var outEl = form.querySelector('[data-checkout]');
    if (!inEl || !outEl) return;
    function nextDay(v) {
      var d = new Date(v + 'T00:00:00Z');
      d.setUTCDate(d.getUTCDate() + 1);
      return d.toISOString().slice(0, 10);
    }
    inEl.addEventListener('change', function () {
      if (!inEl.value) return;
      var min = nextDay(inEl.value);
      outEl.min = min;
      if (!outEl.value || outEl.value < min) outEl.value = min;
    });
  });

  // Hold countdown. Visible text updates every second; screen readers hear only a few milestones.
  document.querySelectorAll('[data-countdown]').forEach(function (box) {
    var end = Date.now() + parseInt(box.getAttribute('data-countdown'), 10) * 1000;
    var text = box.querySelector('[data-countdown-text]');
    var live = box.querySelector('[data-countdown-live]');
    var note = box.querySelector('[data-countdown-note]');
    var milestones = { 300: '5 minutes left to pay.', 120: '2 minutes left to pay.', 60: '1 minute left to pay.', 30: '30 seconds left.', 10: '10 seconds left.' };
    var said = {};
    var timer = setInterval(tick, 500);
    function tick() {
      var left = Math.max(0, Math.round((end - Date.now()) / 1000));
      if (text) text.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
      if (milestones[left] && !said[left] && live) {
        said[left] = true;
        live.setAttribute('aria-live', 'polite');
        live.textContent = milestones[left];
      }
      if (left === 0) {
        clearInterval(timer);
        if (note) note.textContent = 'Your hold has expired. Reloading.';
        if (live) { live.setAttribute('aria-live', 'assertive'); live.textContent = 'Your hold has expired.'; }
        document.querySelectorAll('form button').forEach(function (b) { if (!b.closest('header')) b.disabled = true; });
        setTimeout(function () { location.reload(); }, 1500);
      }
    }
    tick();
  });

  // Poll a status URL until the booking reaches the wanted state, then reload the page.
  document.querySelectorAll('[data-status-poll]').forEach(function (box) {
    var url = box.getAttribute('data-status-poll');
    var target = box.getAttribute('data-status-target');
    var textEl = box.querySelector('[data-status-text]');
    var tries = 0;
    var timer = setInterval(function () {
      tries++;
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (d && d.status && d.status !== 'HELD') { clearInterval(timer); location.reload(); }
          else if (tries >= 45) {
            clearInterval(timer);
            if (textEl) textEl.textContent = 'This is taking longer than usual. Your payment is safe. Check My bookings in a few minutes, or reload this page.';
          }
        })
        .catch(function () { /* keep trying */ });
    }, 2000);
  });

  // Ask before risky actions: <form data-confirm="Cancel this booking?">
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var msg = f && f.getAttribute && f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // Test checkout: card number spacing, brand, expiry slash, and checks before sending. The server checks again.
  document.querySelectorAll('[data-card-form]').forEach(function (form) {
    var num = form.querySelector('[data-card-number]');
    var exp = form.querySelector('[data-card-expiry]');
    var brand = form.querySelector('[data-card-brand]');
    function digits(v) { return v.replace(/\D+/g, ''); }
    function luhn(n) {
      var sum = 0;
      for (var i = 0; i < n.length; i++) {
        var d = +n[n.length - 1 - i];
        if (i % 2 === 1) { d *= 2; if (d > 9) d -= 9; }
        sum += d;
      }
      return n.length >= 12 && sum % 10 === 0;
    }
    num.addEventListener('input', function () {
      var n = digits(num.value).slice(0, 16);
      num.value = n.replace(/(\d{4})(?=\d)/g, '$1 ');
      brand.textContent = /^4/.test(n) ? 'Visa' : (/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/.test(n) ? 'Mastercard' : '');
    });
    exp.addEventListener('input', function () {
      var n = digits(exp.value).slice(0, 4);
      exp.value = n.length > 2 ? n.slice(0, 2) + '/' + n.slice(2) : n;
    });
    form.addEventListener('submit', function (ev) {
      var problems = [];
      var n = digits(num.value);
      var listed = (form.getAttribute('data-test-cards') || '').split(',');
      if (n.length !== 16 || (!luhn(n) && listed.indexOf(n) < 0)) problems.push([num, 'Enter a valid 16-digit card number.']);
      var m = /^(\d{2})\/(\d{2})$/.exec(exp.value);
      var now = new Date();
      if (!m || +m[1] < 1 || +m[1] > 12) problems.push([exp, 'Enter the expiry date as MM/YY.']);
      else if (new Date(2000 + +m[2], +m[1], 0, 23, 59) < now) problems.push([exp, 'This expiry date is in the past.']);
      var cvc = form.querySelector('[name=cardCvc]');
      if (!/^\d{3}$/.test(cvc.value.trim())) problems.push([cvc, 'Enter the 3-digit security code from the back of the card.']);
      var name = form.querySelector('[name=cardName]');
      if (!name.value.trim()) problems.push([name, 'Enter the name on the card.']);
      form.querySelectorAll('[data-client-error]').forEach(function (p) { p.remove(); });
      form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
      if (!problems.length) return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      problems.forEach(function (pr) {
        var p = document.createElement('p');
        p.className = 'mt-1.5 text-sm font-medium text-red-800';
        p.id = 'ce-' + pr[0].id;
        p.setAttribute('data-client-error', '');
        p.textContent = pr[1];
        (pr[0].closest('.relative') || pr[0]).insertAdjacentElement('afterend', p);
        pr[0].setAttribute('aria-invalid', 'true');
        pr[0].setAttribute('aria-describedby', p.id);
      });
      problems[0][0].focus();
    }, true);
  });

  // Test checkout: show a short "processing" state before the payment is sent, like a hosted checkout does.
  document.querySelectorAll('form[data-processing]').forEach(function (form) {
    var panel = document.querySelector('[data-processing-panel]');
    form.addEventListener('submit', function (ev) {
      if (form.dataset.sending || !panel) return;
      ev.preventDefault();
      form.dataset.sending = '1';
      var btn = ev.submitter;
      if (btn && btn.name) {
        var keep = document.createElement('input');
        keep.type = 'hidden'; keep.name = btn.name; keep.value = btn.value;
        form.appendChild(keep);
      }
      panel.classList.remove('hidden');
      panel.classList.add('flex');
      setTimeout(function () { form.submit(); }, 1200);
    });
  });

  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // Copy-to-clipboard helper for one-time secrets: <button data-copy="#id">
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.querySelector(b.getAttribute('data-copy'));
      if (el && navigator.clipboard) navigator.clipboard.writeText(el.textContent.trim()).then(function () { b.textContent = 'Copied'; });
    });
  });

  // ---- Pickers -------------------------------------------------------------------------------------------------------
  // A date-range calendar, +/- counters for guests and rooms, and a styled list for every other <select>.
  // Each sits on top of the real <input> or <select>, which keeps the value, so forms and visitors without script are unaffected.
  // On phones the calendar and lists open as a bottom sheet; on larger screens as a popover next to the field.
  var sheetQ = window.matchMedia('(max-width: 639.98px)');
  var wideQ = window.matchMedia('(min-width: 768px)');
  var shown = null, backdrop = null, seq = 0;
  var P = {
    cal: 'M6.75 3v2.25M17.25 3v2.25M3 8.25h18M4.5 5.25h15a1.5 1.5 0 011.5 1.5v12a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 18.75v-12a1.5 1.5 0 011.5-1.5z',
    left: 'M15 18l-6-6 6-6', right: 'M9 18l6-6-6-6', down: 'M6 9l6 6 6-6',
    check: 'M4.5 12.75l6 6 9-13.5', minus: 'M5 12h14', plus: 'M12 5v14M5 12h14'
  };
  function mk(tag, cls, html) { var n = document.createElement(tag); if (cls) n.className = cls; if (html) n.innerHTML = html; return n; }
  function svg(path, cls) { return '<svg class="' + (cls || 'size-4') + ' shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' + path + '"/></svg>'; }

  function closePop(restore) {
    if (!shown) return;
    var s = shown;
    shown = null;
    s.pop.hidden = true;
    backdrop.hidden = true;
    s.anchor.setAttribute('aria-expanded', 'false');
    if (restore) s.anchor.focus();
  }
  function place() {
    if (!shown) return;
    var pop = shown.pop, a = shown.anchor.getBoundingClientRect();
    pop.style.setProperty('--pw', a.width + 'px');
    if (sheetQ.matches) return;
    var w = pop.offsetWidth, h = pop.offsetHeight, vw = document.documentElement.clientWidth;
    var below = a.bottom + 8 + h <= window.innerHeight || a.top - 8 - h < 0;
    pop.style.setProperty('--px', (Math.min(Math.max(a.left, 8), vw - w - 8) + window.scrollX) + 'px');
    pop.style.setProperty('--py', ((below ? a.bottom + 8 : a.top - 8 - h) + window.scrollY) + 'px');
  }
  function openPop(pop, anchor) {
    if (shown && shown.pop === pop) {
      shown.anchor.setAttribute('aria-expanded', 'false');
      shown.anchor = anchor;
    } else {
      closePop(false);
      if (!backdrop) {
        backdrop = mk('div', 'pop-backdrop');
        backdrop.hidden = true;
        backdrop.addEventListener('click', function () { closePop(true); });
        document.body.appendChild(backdrop);
      }
      if (!pop.parentNode) document.body.appendChild(pop);
      pop.hidden = false;
      backdrop.hidden = false;
      shown = { pop: pop, anchor: anchor };
    }
    anchor.setAttribute('aria-expanded', 'true');
    place();
  }
  // Phones use the backdrop to close, so a tap outside never also clicks whatever is underneath.
  document.addEventListener('pointerdown', function (ev) {
    if (shown && !sheetQ.matches && !shown.pop.contains(ev.target) && !shown.anchor.contains(ev.target)) closePop(false);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && shown) { ev.preventDefault(); closePop(true); }
  });
  window.addEventListener('resize', place);

  // Date range: one calendar for check-in and check-out. The two real inputs become hidden and keep the values.
  document.querySelectorAll('[data-date-pair]').forEach(function (form) {
    var inEl = form.querySelector('[data-checkin]');
    var outEl = form.querySelector('[data-checkout]');
    if (!inEl || !outEl || inEl.type !== 'date') return;
    var min = inEl.min || '0000-01-01';
    var titleF = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    var longF = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
    var shortF = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
    function at(s, n) { var t = new Date(s + 'T00:00:00Z'); t.setUTCDate(t.getUTCDate() + (n || 0)); return t; }
    function ymd(t) { return t.toISOString().slice(0, 10); }
    function mi(s) { return +s.slice(0, 4) * 12 + (+s.slice(5, 7) - 1); }   // month number, so months compare as plain numbers
    var minMi = mi(min);

    function trigger(input) {
      var b = mk('button', 'input dp-trigger', '<span class="min-w-0 truncate"></span>' + svg(P.cal, 'size-4 opacity-70'));
      b.type = 'button';
      b.id = input.id;
      input.removeAttribute('id');
      input.type = 'hidden';
      b.setAttribute('aria-haspopup', 'dialog');
      b.setAttribute('aria-expanded', 'false');
      input.after(b);
      return b;
    }
    var tIn = trigger(inEl);
    var tOut = trigger(outEl);
    function sync() {
      tIn.firstChild.textContent = shortF.format(at(inEl.value));
      tOut.firstChild.textContent = shortF.format(at(outEl.value));
    }

    var pop = mk('div', 'pop dp');
    pop.hidden = true;
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', 'Choose your dates');
    pop.innerHTML = '<div class="pop-grip sm:hidden" aria-hidden="true"></div><div class="dp-body">' +
      '<button type="button" class="dp-nav dp-prev" aria-label="Previous month">' + svg(P.left) + '</button>' +
      '<button type="button" class="dp-nav dp-next" aria-label="Next month">' + svg(P.right) + '</button>' +
      '<div class="dp-months"></div></div>' +
      '<div class="dp-foot"><p class="dp-hint" role="status" aria-live="polite"></p><button type="button" class="btn btn-secondary btn-sm sm:hidden">Close</button></div>';
    var months = pop.querySelector('.dp-months');
    var prev = pop.querySelector('.dp-prev');
    var next = pop.querySelector('.dp-next');
    var hint = pop.querySelector('.dp-hint');
    var step = 'in', a = '', b = '', vi = 0;   // step: which date the next tap sets; a, b: the range shown; vi: first month shown

    function month(i) {
      var y = Math.floor(i / 12), m = i % 12, box = mk('div', 'dp-month'), grid = mk('div', 'dp-grid'), title = mk('p', 'dp-title');
      title.textContent = titleF.format(new Date(Date.UTC(y, m, 1)));
      box.appendChild(title);
      ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(function (w) {
        var s = mk('span', 'dp-dow');
        s.textContent = w;
        s.setAttribute('aria-hidden', 'true');
        grid.appendChild(s);
      });
      for (var pad = new Date(Date.UTC(y, m, 1)).getUTCDay(); pad > 0; pad--) grid.appendChild(mk('span'));
      for (var d = 1, n = new Date(Date.UTC(y, m + 1, 0)).getUTCDate(); d <= n; d++) {
        var s = ymd(new Date(Date.UTC(y, m, d))), c = 'dp-day';
        if (s === min) c += ' is-today';
        if (s === a) c += ' is-start';
        if (s === b) c += ' is-end';
        if (a && b && s > a && s < b) c += ' is-range';
        var day = mk('button', c);
        day.type = 'button';
        day.textContent = d;
        day.dataset.d = s;
        day.disabled = s < min;
        day.setAttribute('aria-label', longF.format(at(s)));
        if (s === a || s === b) day.setAttribute('aria-pressed', 'true');
        grid.appendChild(day);
      }
      box.appendChild(grid);
      return box;
    }
    function render() {
      months.textContent = '';
      months.appendChild(month(vi));
      months.appendChild(month(vi + 1));   // the second month is hidden by CSS below 768px
      prev.disabled = vi <= minMi;
      var n = a && b ? Math.round((at(b) - at(a)) / 864e5) : 0;
      hint.textContent = a && b ? n + (n === 1 ? ' night' : ' nights') + ': ' + shortF.format(at(a)) + ' to ' + shortF.format(at(b))
        : step === 'out' ? 'Now choose your check-out date' : 'Choose your check-in date';
    }
    function focusDay(s) {
      var t = months.querySelector('[data-d="' + s + '"]:not(:disabled)') || months.querySelector('.dp-day:not(:disabled)');
      if (t) t.focus();
    }
    function moveTo(s) {
      if (s < min) return;
      var i = mi(s), vis = wideQ.matches ? 2 : 1;
      if (i < vi) vi = i; else if (i >= vi + vis) vi = i - vis + 1;
      render();
      focusDay(s);
    }
    function pick(s) {
      if (step === 'in' || s <= a) {
        a = s; b = ''; step = 'out';
        inEl.value = s;
        if (outEl.value <= s) outEl.value = ymd(at(s, 1));   // the stay is always at least one night, even if the calendar is closed now
        sync(); render(); focusDay(s);
        inEl.dispatchEvent(new Event('change', { bubbles: true }));
      } else {
        b = s; step = 'in';
        outEl.value = s;
        sync();
        outEl.dispatchEvent(new Event('change', { bubbles: true }));
        closePop(true);
      }
    }
    months.addEventListener('click', function (e) {
      var t = e.target.closest('.dp-day');
      if (t && !t.disabled) pick(t.dataset.d);
    });
    months.addEventListener('mouseover', function (e) {   // while choosing check-out, shade the nights up to the pointer
      var t = e.target.closest('.dp-day');
      if (!t || step !== 'out' || b) return;
      months.querySelectorAll('.dp-day').forEach(function (x) { x.classList.toggle('is-preview', x.dataset.d > a && x.dataset.d <= t.dataset.d); });
    });
    months.addEventListener('mouseleave', function () {
      months.querySelectorAll('.is-preview').forEach(function (x) { x.classList.remove('is-preview'); });
    });
    months.addEventListener('keydown', function (e) {
      var t = e.target.closest('.dp-day');
      var n = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
      if (!t || (n === undefined && e.key !== 'PageUp' && e.key !== 'PageDown')) return;
      e.preventDefault();
      if (n !== undefined) { moveTo(ymd(at(t.dataset.d, n))); return; }
      var d = at(t.dataset.d);
      d.setUTCMonth(d.getUTCMonth() + (e.key === 'PageDown' ? 1 : -1));
      moveTo(ymd(d));
    });
    prev.addEventListener('click', function () { if (vi > minMi) { vi--; render(); } });
    next.addEventListener('click', function () { vi++; render(); });
    pop.querySelector('.dp-foot .btn').addEventListener('click', function () { closePop(true); });

    function openFor(which) {
      var btn = which === 'in' ? tIn : tOut;
      if (shown && shown.anchor === btn) { closePop(true); return; }
      step = which; a = inEl.value; b = outEl.value;
      vi = Math.max(minMi, mi(which === 'out' && !wideQ.matches ? b : a));
      render();
      openPop(pop, btn);
      focusDay(which === 'in' ? a : b);
    }
    tIn.addEventListener('click', function () { openFor('in'); });
    tOut.addEventListener('click', function () { openFor('out'); });
    sync();
  });

  // Guests and rooms: a - / + counter. The real <select> stays hidden and keeps the value.
  document.querySelectorAll('select[data-stepper]').forEach(function (sel) {
    var last = sel.options.length - 1, lo = +sel.options[0].value, hi = +sel.options[last].value;
    var one = sel.options[0].text.replace(/^\d+\s*/, ''), many = sel.options[last].text.replace(/^\d+\s*/, '');
    var label = document.querySelector('label[for="' + sel.id + '"]');
    var box = mk('div', 'stepper'), dec = mk('button', 'step-btn', svg(P.minus)), inc = mk('button', 'step-btn', svg(P.plus)), val = mk('span', 'step-val');
    dec.type = 'button'; inc.type = 'button';
    dec.setAttribute('aria-label', 'Fewer ' + many);
    inc.setAttribute('aria-label', 'More ' + many);
    val.setAttribute('aria-live', 'polite');
    box.setAttribute('role', 'group');
    if (label) {
      label.id = label.id || 'pk' + (++seq);
      label.removeAttribute('for');
      box.setAttribute('aria-labelledby', label.id);
    }
    box.append(dec, val, inc);
    sel.classList.add('sr-only');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    sel.after(box);
    function set(v, user) {
      v = Math.min(hi, Math.max(lo, v));
      sel.value = String(v);
      val.textContent = v + ' ' + (v === 1 ? one : many);
      dec.disabled = v <= lo;
      inc.disabled = v >= hi;
      if (user) {
        if (document.activeElement === document.body) (v <= lo ? inc : dec).focus();   // the button just locked had focus
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    dec.addEventListener('click', function () { set(+sel.value - 1, true); });
    inc.addEventListener('click', function () { set(+sel.value + 1, true); });
    set(+sel.value, false);
  });

  // Every other <select> (staff filters, role, room): the same white list, with arrow keys and type-ahead.
  document.querySelectorAll('select:not([data-stepper]):not([multiple])').forEach(function (sel) {
    var btn = mk('button', sel.className + ' sel-trigger', '<span class="min-w-0 truncate"></span>' + svg(P.down, 'size-4 opacity-70'));
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (sel.id) { btn.id = sel.id; sel.removeAttribute('id'); }
    ['aria-describedby', 'aria-invalid'].forEach(function (n) { if (sel.hasAttribute(n)) btn.setAttribute(n, sel.getAttribute(n)); });
    btn.disabled = sel.disabled;
    var lab = btn.id ? document.querySelector('label[for="' + btn.id + '"]') : null;
    var pop = mk('div', 'pop sel'), ul = mk('ul');
    pop.hidden = true;
    ul.setAttribute('role', 'listbox');
    if (lab) ul.setAttribute('aria-label', lab.textContent.trim());
    pop.append(mk('div', 'pop-grip sm:hidden'), ul);
    [].forEach.call(sel.options, function (o, i) {
      var li = mk('li', 'sel-opt', '<span class="min-w-0 flex-1"></span>' + svg(P.check, 'tick size-4'));
      li.firstChild.textContent = o.text;
      li.setAttribute('role', 'option');
      li.tabIndex = -1;
      li.dataset.i = i;
      if (o.disabled) li.setAttribute('aria-disabled', 'true');
      ul.appendChild(li);
    });
    function sync() {
      btn.firstChild.textContent = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '';
      [].forEach.call(ul.children, function (li, i) { li.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false'); });
    }
    function choose(li) {
      if (li.getAttribute('aria-disabled')) return;
      sel.selectedIndex = +li.dataset.i;
      sync();
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      closePop(true);
    }
    function toggle() {
      if (shown && shown.anchor === btn) { closePop(true); return; }
      openPop(pop, btn);
      (ul.children[sel.selectedIndex] || ul.firstChild).focus();
    }
    btn.addEventListener('click', toggle);
    btn.addEventListener('keydown', function (e) {
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !(shown && shown.anchor === btn)) { e.preventDefault(); toggle(); }
    });
    var typed = '', timer = 0;
    ul.addEventListener('keydown', function (e) {
      var items = [].filter.call(ul.children, function (li) { return !li.getAttribute('aria-disabled'); });
      var i = items.indexOf(document.activeElement), k = e.key;
      if (k === 'ArrowDown') i = Math.min(items.length - 1, i + 1);
      else if (k === 'ArrowUp') i = Math.max(0, i - 1);
      else if (k === 'Home') i = 0;
      else if (k === 'End') i = items.length - 1;
      else if (k === 'Enter' || k === ' ') { e.preventDefault(); if (i >= 0) choose(items[i]); return; }
      else if (k === 'Tab') { closePop(true); return; }
      else if (k.length === 1) {
        clearTimeout(timer);
        typed += k.toLowerCase();
        timer = setTimeout(function () { typed = ''; }, 600);
        var hit = items.filter(function (li) { return li.textContent.toLowerCase().indexOf(typed) === 0; })[0];
        if (hit) hit.focus();
        return;
      } else return;
      e.preventDefault();
      if (items[i]) items[i].focus();
    });
    ul.addEventListener('click', function (e) {
      var li = e.target.closest('.sel-opt');
      if (li) choose(li);
    });
    sel.classList.add('sr-only');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    sel.addEventListener('invalid', function () { btn.focus(); });   // a required list that is still empty
    sel.addEventListener('change', sync);
    if (sel.form) sel.form.addEventListener('reset', function () { setTimeout(sync); });
    sel.after(btn);
    sync();
  });
})();
