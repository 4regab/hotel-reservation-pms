// Small progressive enhancements. Every page works without this file.
(function () {
  'use strict';

  // Landing page motion. The CSS only hides things when this class is present, so without JavaScript all content shows.
  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!calm && 'IntersectionObserver' in window) {
    document.documentElement.classList.add('js');
    var seen = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); seen.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -10% 0px' });
    document.querySelectorAll('[data-reveal]').forEach(function (el) { seen.observe(el); });

    // Story words light up as the paragraph moves up the screen. Screen readers still get the plain sentence.
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
  }

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
})();
