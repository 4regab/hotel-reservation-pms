// Small progressive enhancements. Every page works without this file.
(function () {
  'use strict';

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
