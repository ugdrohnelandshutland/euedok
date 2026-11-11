/* EÜDOK – PDF-Anzeige: alle Seiten als Canvas (pdf.js, lokal), Seiten erst beim Scrollen gerendert.
   window.EUEDOK.pdf.render(container, url) -> { zoomBy(faktor), destroy() }; genutzt vom Popup (app.js). */
(function () {
  'use strict';

  var lib = null;

  function loadLib() {
    var d = document.body.dataset;
    return lib || (lib = import(d.pdfLib).then(function (m) {
      m.GlobalWorkerOptions.workerSrc = d.pdfWorker;
      return m;
    }).catch(function (e) { lib = null; throw e; }));
  }

  function render(box, src) {
    var zoom = 1, doc = null, task = null, dead = false, gen = 0, resizeTimer;
    var pages = [];
    var state = document.createElement('p');
    state.className = 'muted pv-state';
    state.setAttribute('role', 'status');
    state.textContent = 'PDF wird geladen …';
    box.replaceChildren(state);

    function fail(text) {
      state.hidden = false;
      state.className = 'msg err pv-state';
      state.removeAttribute('role');
      state.textContent = '';
      var span = document.createElement('span');
      span.textContent = text + ' ';
      var a = document.createElement('a');
      a.href = src;
      a.textContent = 'PDF herunterladen';
      a.setAttribute('download', '');
      span.appendChild(a);
      state.appendChild(span);
    }

    function fitWidth() { return Math.max(200, box.clientWidth - 16); }

    var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) renderPage(e.target); });
    }, { rootMargin: '600px 0px' }) : null;

    function renderPage(el) {
      var p = el._pg;
      if (dead || !p || el._done === gen) return;
      el._done = gen;
      var dpr = window.devicePixelRatio || 1;
      var base = p.getViewport({ scale: 1 });
      // Canvas höchstens 16 Mio. Pixel: iOS-Safari zeichnet größere Canvas nicht
      var s = Math.min(el._scale * dpr, Math.sqrt(16e6 / (base.width * base.height)));
      var vp = p.getViewport({ scale: s });
      var c = document.createElement('canvas');
      c.width = Math.floor(vp.width);
      c.height = Math.floor(vp.height);
      c.style.width = el.style.width;
      c.style.height = el.style.height;
      p.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise.then(function () {
        if (dead || el._done !== gen) return;
        el.replaceChildren(c);
        state.hidden = true;
      }).catch(function () { if (!dead) { el._done = 0; if (!state.hidden) fail('Die Seite konnte nicht dargestellt werden.'); } });
    }

    // Seitenrahmen in Zielgröße anlegen; gerendert wird erst, wenn sie in die Nähe des Bildschirms kommen
    function layout() {
      gen++;
      pages.forEach(function (el) {
        var base = el._pg.getViewport({ scale: 1 });
        el._scale = fitWidth() / base.width * zoom;
        el.style.width = Math.floor(base.width * el._scale) + 'px';
        el.style.height = Math.floor(base.height * el._scale) + 'px';
        el.replaceChildren();
        if (io) { io.unobserve(el); io.observe(el); } else { renderPage(el); }
      });
    }

    function onResize() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { if (doc && !dead) layout(); }, 200);
    }
    window.addEventListener('resize', onResize);

    loadLib().then(function (l) {
      if (dead) return null;
      task = l.getDocument({ url: src });
      return task.promise;
    }).then(function (d) {
      if (!d) return null;
      doc = d;
      var loads = [];
      for (var n = 1; n <= d.numPages; n++) loads.push(d.getPage(n));
      return Promise.all(loads);
    }).then(function (list) {
      if (!list || dead) return;
      list.forEach(function (p) {
        var el = document.createElement('div');
        el.className = 'pv-page';
        el._pg = p;
        box.appendChild(el);
        pages.push(el);
      });
      layout();
    }).catch(function () {
      if (!dead) fail('Das PDF konnte nicht geladen werden.');
    });

    return {
      zoomBy: function (f) {
        zoom = Math.min(4, Math.max(0.5, zoom * f));
        if (doc) layout();
      },
      destroy: function () {
        dead = true;
        clearTimeout(resizeTimer);
        window.removeEventListener('resize', onResize);
        if (io) io.disconnect();
        pages.forEach(function (el) { el.querySelectorAll('canvas').forEach(function (c) { c.width = c.height = 0; }); });
        box.replaceChildren();
        pages = [];
        if (task) task.destroy();
      }
    };
  }

  (window.EUEDOK = window.EUEDOK || {}).pdf = { render: render };
})();
