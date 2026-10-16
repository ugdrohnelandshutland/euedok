/* EÜDOK – gemeinsame Helfer: Tag/Nacht, Anfragen, Meldungen, Listen-Editor */
(function () {
  'use strict';

  var root = document.documentElement;

  function effectiveTheme() {
    var t = root.getAttribute('data-theme');
    if (t) return t;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-theme-toggle]');
    if (!b) return;
    var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('euedok-theme', next); } catch (e) { /* egal */ }
  });

  var meta = document.querySelector('meta[name="csrf"]');
  var csrf = meta ? meta.content : '';

  /** POST als JSON. Liefert {ok,…}; bei Netzfehler {ok:false, offline:true}. */
  function api(action, payload, base) {
    var url = (base || document.body.getAttribute('data-api')) + '?action=' + encodeURIComponent(action);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unerwartete Antwort (' + r.status + ')' }; })
        .then(function (j) { j.httpStatus = r.status; return j; });
    }).catch(function () {
      return { ok: false, offline: true, error: 'Keine Verbindung' };
    });
  }

  var toastTimer;
  function toast(text, kind) {
    var el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.className = 'msg toast ' + (kind || 'ok');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 4000);
  }

  /* ---- Listen-Editor (Einstellungen): Zeile hinzufügen, verschieben ---- */
  document.addEventListener('click', function (ev) {
    var add = ev.target.closest('[data-add-row]');
    if (add) {
      var list = document.getElementById(add.getAttribute('data-add-row'));
      var tpl = document.getElementById(add.getAttribute('data-add-row') + '-tpl');
      if (list && tpl) {
        var idx = 'n' + Date.now();
        var html = tpl.innerHTML.replace(/__i__/g, idx);
        list.insertAdjacentHTML('beforeend', html);
        var first = list.lastElementChild && list.lastElementChild.querySelector('input[type=text],input:not([type])');
        if (first) first.focus();
      }
      return;
    }
    var up = ev.target.closest('[data-move]');
    if (up) {
      var row = up.closest('.ed-row');
      if (up.getAttribute('data-move') === 'up' && row.previousElementSibling && row.previousElementSibling.classList.contains('ed-row')) {
        row.parentNode.insertBefore(row, row.previousElementSibling);
      } else if (up.getAttribute('data-move') === 'down' && row.nextElementSibling) {
        row.parentNode.insertBefore(row.nextElementSibling, row);
      }
      return;
    }
    var confirmEl = ev.target.closest('[data-confirm]');
    if (confirmEl && !window.confirm(confirmEl.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });

  /* Auswahl-Felder in den Einstellungen: Optionen nur bei Typ „Auswahl“ */
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('select[data-field-type]');
    if (!sel) return;
    var row = sel.closest('.ed-row');
    var opt = row && row.querySelector('[data-options]');
    if (opt) opt.hidden = sel.value !== 'select';
  });

  /* Filter in der Übersicht ohne Absende-Knopf */
  document.addEventListener('change', function (ev) {
    var f = ev.target.closest('form[data-autosubmit]');
    if (f && ev.target.tagName === 'SELECT') f.submit();
  });

  /* Einstellungs-Menü am Handy: Scroll-Position behalten und aktiven Bereich sichtbar halten */
  var setNav = document.querySelector('.set-nav');
  if (setNav && setNav.scrollWidth > setNav.clientWidth) {
    var saved = null;
    try { saved = sessionStorage.getItem('euedok-setnav'); } catch (e) { /* egal */ }
    if (saved !== null) setNav.scrollLeft = Number(saved);
    var active = setNav.querySelector('a.on');
    if (active) {
      var left = active.getBoundingClientRect().left - setNav.getBoundingClientRect().left + setNav.scrollLeft;
      if (left < setNav.scrollLeft || left + active.offsetWidth > setNav.scrollLeft + setNav.clientWidth) {
        setNav.scrollLeft = left - (setNav.clientWidth - active.offsetWidth) / 2;
      }
    }
    var remember = function () { try { sessionStorage.setItem('euedok-setnav', String(setNav.scrollLeft)); } catch (e) { /* egal */ } };
    setNav.addEventListener('scroll', remember, { passive: true });
    window.addEventListener('pagehide', remember);
  }

  /* Ganze Zeile/Kachel klickbar (Übersicht), Knöpfe und Links darin behalten ihre Funktion */
  document.addEventListener('click', function (ev) {
    var row = ev.target.closest('[data-href]');
    if (!row || ev.target.closest('a,button,form,input,select,textarea,label')) return;
    if (ev.ctrlKey || ev.metaKey) window.open(row.getAttribute('data-href'), '_blank');
    else location.href = row.getAttribute('data-href');
  });

  /* Fußzeilen-Editor: Formatierung, Platzhalter, Vorschau (gleiche Regeln wie app/Footer.php) */
  function wrapSelection(ta, mark) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var sel = v.slice(s, e) || 'Text';
    ta.value = v.slice(0, s) + mark + sel + mark + v.slice(e);
    ta.focus();
    ta.setSelectionRange(s + mark.length, s + mark.length + sel.length);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-fmt]');
    if (!b) return;
    var ta = document.getElementById(b.getAttribute('data-fmt-target'));
    if (ta) wrapSelection(ta, b.getAttribute('data-fmt'));
  });
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('[data-insert-target]');
    if (!sel || !sel.value) return;
    var ta = document.getElementById(sel.getAttribute('data-insert-target'));
    var s = ta.selectionStart, v = ta.value;
    ta.value = v.slice(0, s) + sel.value + v.slice(ta.selectionEnd);
    ta.focus();
    ta.setSelectionRange(s + sel.value.length, s + sel.value.length);
    sel.value = '';
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  });
  var prev = document.getElementById('footer-preview');
  function footerHtml(t) {
    var h = t.replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    h = h.replace(/\*\*([\s\S]+?)\*\*/g, '<b>$1</b>').replace(/__([\s\S]+?)__/g, '<u>$1</u>')
      .replace(/(^|[^*\w])\*(?!\s)([\s\S]+?)(?<!\s)\*(?![*\w])/g, '$1<i>$2</i>');
    var unit = prev.getAttribute('data-unit') || '';
    h = h.replace(/\{seite\}/g, '1').replace(/\{seiten\}/g, '2').replace(/\{nummer\}/g, 'E-2026-001')
      .replace(/\{datum\}/g, new Date().toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }))
      .replace(/\{einheit\}/g, unit.replace(/[&<>]/g, ''));
    return h.replace(/\n/g, '<br>');
  }
  function renderPreview() {
    document.querySelectorAll('[data-footer-part]').forEach(function (ta) {
      var box = prev.querySelector('[data-prev="' + ta.getAttribute('data-footer-part') + '"]');
      if (box) box.innerHTML = footerHtml(ta.value);
    });
  }
  if (prev) {
    document.addEventListener('input', function (ev) { if (ev.target.matches('[data-footer-part]')) renderPreview(); });
    renderPreview();
  }

  window.EUEDOK = { api: api, toast: toast, csrf: csrf };
})();
