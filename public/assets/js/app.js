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
    var rm = ev.target.closest('[data-remove-field]');
    if (rm) {
      var card = rm.closest('.ed-row');
      var t = card.querySelector('input[name$="[text]"]');
      if (t) t.value = ''; // leerer Text = Feld wird beim Speichern entfernt
      card.hidden = true;
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

  // Einsatzbestätigungen: Anzahl im Senden-Button, ohne Auswahl gesperrt
  var selSend = document.querySelector('[data-sel-send]');
  if (selSend) {
    var selForm = selSend.closest('form');
    var selLabel = selSend.querySelector('[data-sel-label]');
    var updateSel = function () {
      var c = selForm.querySelectorAll('input[name$="[sel]"]:checked').length;
      selLabel.textContent = c === 0 ? 'Bestätigungen senden' : c + (c === 1 ? ' Bestätigung senden' : ' Bestätigungen senden');
      // „nicht bereit“ (Vorlage fehlt o. ä.) bleibt immer gesperrt
      selSend.disabled = c === 0 || selSend.hasAttribute('data-not-ready');
    };
    selForm.addEventListener('change', updateSel);
    updateSel();
  }

  // PDF-Links (data-pdf): Popup über der Seite, pdf.js rendert direkt im <dialog>. pdf-view.js und pdf.js
  // werden erst beim ersten Klick nachgeladen. Strg/Meta/Shift/Mittelklick bleiben nativ.
  var pdfDlg = null;
  var pdfScript = null;

  function loadPdfJs() {
    return pdfScript || (pdfScript = new Promise(function (ok, fail) {
      if (window.EUEDOK.pdf) return ok();
      var s = document.createElement('script');
      s.src = document.body.dataset.pdfJs;
      s.onload = ok;
      s.onerror = function () { pdfScript = null; fail(); };
      document.head.appendChild(s);
    }));
  }

  function openPdf(a) {
    var sprite = document.body.dataset.sprite;
    var url = a.pathname + a.search;
    var name = a.getAttribute('data-pdf');
    if (!name) { try { name = decodeURIComponent(a.pathname.split('/').pop()); } catch (e) { name = 'PDF'; } }
    var dlg = document.createElement('dialog');
    var closing = false;
    var ctl = null;
    function ic(n) { return '<svg class="i" aria-hidden="true"><use href="' + sprite + '#i-' + n + '"/></svg>'; }
    function btn(attrs, label, icon) { return '<' + (attrs.indexOf('href') < 0 ? 'button type="button"' : 'a') + ' class="ib" ' + attrs + ' title="' + label + '" aria-label="' + label + '">' + ic(icon) + (attrs.indexOf('href') < 0 ? '</button>' : '</a>'); }
    dlg.className = 'pvd';
    dlg.setAttribute('aria-label', 'PDF');
    dlg.innerHTML = '<div class="tb"><div class="ttl"><b class="pv-name"></b></div>'
      + btn('data-z="0.8"', 'Verkleinern', 'minus') + btn('data-z="1.25"', 'Vergrößern', 'plus')
      + btn('data-dl href="#"', 'Herunterladen', 'download') + btn('data-nt href="#" target="_blank" rel="noopener"', 'In neuem Tab öffnen', 'external')
      + btn('data-x autofocus', 'Schließen', 'x') + '</div><div class="pv pvd-body"></div>';
    var body = dlg.querySelector('.pvd-body');
    dlg.querySelector('.pv-name').textContent = name;
    var dl = dlg.querySelector('[data-dl]');
    dl.href = url;
    dl.setAttribute('download', name);
    dlg.querySelector('[data-nt]').href = url;

    // Schließen läuft immer über den Verlauf: pushState beim Öffnen, history.back() beim Schließen
    function finish() {
      window.removeEventListener('popstate', finish);
      pdfDlg = null;
      if (ctl) ctl.destroy();
      dlg.close();
      dlg.remove();
      document.documentElement.style.overflow = '';
      a.focus({ preventScroll: true });
    }
    function requestClose() {
      if (closing || pdfDlg !== dlg) return;
      closing = true;
      if (history.state && history.state.pdf) history.back(); else finish();
    }
    dlg.addEventListener('click', function (ev) {
      var z = ev.target.closest('[data-z]');
      if (z) { if (ctl) ctl.zoomBy(parseFloat(z.getAttribute('data-z'))); }
      else if (ev.target.closest('[data-x]') || ev.target === dlg) requestClose();
    });
    dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); requestClose(); });
    dlg.addEventListener('close', requestClose);

    pdfDlg = dlg;
    document.body.appendChild(dlg);
    document.documentElement.style.overflow = 'hidden';
    history.pushState({ pdf: 1 }, '');
    window.addEventListener('popstate', finish);
    dlg.showModal();
    loadPdfJs().then(function () {
      if (pdfDlg === dlg) ctl = window.EUEDOK.pdf.render(body, url);
    }, function () {
      body.textContent = 'Die PDF-Anzeige konnte nicht geladen werden. Nutze „Herunterladen“ oder „In neuem Tab öffnen“.';
    });
  }

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a[data-pdf]');
    if (!a || pdfDlg || ev.defaultPrevented || ev.button || ev.ctrlKey || ev.metaKey || ev.shiftKey || a.origin !== location.origin) return;
    ev.preventDefault();
    openPdf(a);
  });

  window.EUEDOK = { api: api, toast: toast, csrf: csrf };
})();
