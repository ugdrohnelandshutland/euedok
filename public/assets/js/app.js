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

  window.EUEDOK = { api: api, toast: toast, csrf: csrf };
})();
