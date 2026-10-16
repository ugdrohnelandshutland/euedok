/* EÜDOK – Berichtsformular: Auswahl per Antippen, Autosave (Server + Gerät), Divera, Abschließen */
(function () {
  'use strict';

  var cfgEl = document.getElementById('cfg');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent);
  var R = cfg.report;
  var api = function (a, p) { return window.EUEDOK.api(a, p, cfg.urls.api); };
  var toast = function (t, k) { window.EUEDOK.toast(t, k); };
  var $ = function (id) { return document.getElementById(id); };
  var LS_KEY = 'euedok-draft-' + cfg.number;
  var LS_PERSON = 'euedok-created-by';
  var wide = window.matchMedia('(min-width:1000px)');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function ic(name, cls) { return '<svg class="i ' + (cls || '') + '" aria-hidden="true"><use href="' + cfg.urls.sprite + '#i-' + name + '"/></svg>'; }
  function initials(name) {
    var p = String(name).split(/[\s,]+/).filter(Boolean);
    if (name.indexOf(',') > -1 && p.length > 1) return (p[1][0] + p[0][0]).toUpperCase();
    return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function localIso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function fmtDt(iso) { if (!iso) return ''; var d = new Date(iso); return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function isReq(f) { return cfg.required.indexOf(f) > -1; }

  /* ---------- Zustand ---------- */
  var data = {
    start: R.start, end: R.end, location: R.location || '', subject: R.subject || '',
    alert_source: R.alert_source, narrative: R.narrative || '', defects: R.defects || '',
    equipment: (R.equipment || []).map(function (q) { return q.id; }),
    personnel: (R.personnel || []).map(function (p) { return { id: p.id, name: p.name, divera_id: p.divera_id, fields: p.fields || {} }; }),
    lead: R.lead,
    vehicles: (R.vehicles || []).map(function (v) { return { id: v.id, label: v.label, source: v.source, fields: v.fields || {} }; }),
    custom_fields: R.custom_fields && !Array.isArray(R.custom_fields) ? R.custom_fields : {},
    created_by: R.created_by ? R.created_by.id : null
  };
  var rev = cfg.rev;
  var closed = false;

  var peopleById = {};
  cfg.lists.personnel.items.forEach(function (p) { peopleById[p.id] = p; });
  // Gleiche Namen (z. B. doppeltes Divera-Konto) mit Kennung unterscheidbar machen
  var nameCount = {};
  cfg.lists.personnel.items.forEach(function (p) { nameCount[p.name] = (nameCount[p.name] || 0) + 1; });
  function personLabel(p) {
    if ((nameCount[p.name] || 0) < 2) return esc(p.name);
    return esc(p.name) + ' <small class="pid">' + esc(p.divera_id ? '#' + p.divera_id : p.id) + '</small>';
  }
  data.personnel.forEach(function (p) { if (!peopleById[p.id]) peopleById[p.id] = { id: p.id, name: p.name, divera_id: p.divera_id, frequency: 0, gone: true }; });
  var vehById = {};
  cfg.lists.vehicles.items.forEach(function (v) { vehById[v.id] = v; });
  data.vehicles.forEach(function (v) { if (!vehById[v.id]) { vehById[v.id] = { id: v.id, label: v.label, source: v.source, radio_call_sign: '' }; cfg.lists.vehicles.items.push(vehById[v.id]); } });

  /* ---------- Banner ---------- */
  function banner(id, kind, html, btn) {
    var box = $('banners');
    var old = document.getElementById('bn-' + id);
    if (old) old.remove();
    if (!html) return;
    var d = document.createElement('div');
    d.className = 'banner ' + kind;
    d.id = 'bn-' + id;
    d.innerHTML = ic(kind === 'warn' ? 'alert' : 'cloud') + '<span>' + html + '</span>';
    if (btn) {
      btn.forEach(function (b) {
        var el = document.createElement('button');
        el.type = 'button';
        el.className = 'btn sm';
        el.textContent = b.text;
        el.addEventListener('click', b.fn);
        d.appendChild(el);
      });
    }
    box.appendChild(d);
  }

  /* ---------- Speichern ---------- */
  var saveTimer = null, saving = false, pending = false, retryTimer = null;
  function setSave(state, text) {
    var el = $('save-state');
    el.className = 'save' + (state ? ' ' + state : '');
    el.textContent = text;
  }
  function backup() {
    try { localStorage.setItem(LS_KEY, JSON.stringify({ at: new Date().toISOString(), rev: rev, data: data })); } catch (e) { /* voll */ }
  }
  function clearBackup() { try { localStorage.removeItem(LS_KEY); } catch (e) { /* egal */ } }

  function changed() {
    if (closed) return;
    backup();
    progress();
    if (cfg.correction) { setSave('dev', 'geändert – noch nicht abgeschlossen'); return; }
    setSave('busy', 'Speichert …');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 700);
  }

  function save() {
    if (cfg.correction || closed) return Promise.resolve(true);
    if (saving) { pending = true; return Promise.resolve(false); }
    saving = true;
    pending = false;
    return api('save', { n: cfg.number, rev: rev, data: data }).then(function (res) {
      saving = false;
      if (res.ok) {
        rev = res.rev;
        clearBackup();
        setSave('', 'Gespeichert');
        banner('offline');
        if (pending) save();
        return true;
      }
      if (res.offline) {
        setSave('dev', 'Nur auf Gerät gespeichert');
        banner('offline', 'warn', 'Keine Verbindung – alles bleibt auf diesem Gerät und wird automatisch nachgeschickt.');
        clearTimeout(retryTimer);
        retryTimer = setTimeout(save, 15000);
        return false;
      }
      if (res.conflict) {
        setSave('err', 'Nicht gespeichert');
        banner('conflict', 'warn', esc(res.error) + ' Deine Eingaben sind auf diesem Gerät gesichert.', [{ text: 'Neu laden', fn: function () { location.reload(); } }]);
        return false;
      }
      if (res.closed) {
        setSave('err', 'Abgeschlossen');
        banner('conflict', 'warn', 'Dieser Bericht wurde bereits abgeschlossen.', [{ text: 'Ansehen', fn: function () { location.href = cfg.urls.back; } }]);
        return false;
      }
      setSave('err', 'Fehler');
      toast(res.error || 'Speichern fehlgeschlagen', 'err');
      return false;
    });
  }
  window.addEventListener('online', function () { if (!cfg.correction) save(); });

  /* ---------- Statische Felder ---------- */
  function bindInput(id, key) {
    var el = $(id);
    if (!el) return;
    el.value = data[key] || '';
    el.addEventListener('input', function () { data[key] = el.value; if (key === 'start' || key === 'end') duration(); changed(); });
  }
  bindInput('f-start', 'start');
  bindInput('f-end', 'end');
  bindInput('f-location', 'location');
  bindInput('f-subject', 'subject');
  bindInput('f-narrative', 'narrative');
  bindInput('f-defects', 'defects');
  function openDefects() { var d = $('defects-box'); if (d && (data.defects || '').trim() !== '') d.open = true; }
  openDefects();

  function fixMidnight() {
    // Ende vor Beginn am selben Tag → über Mitternacht: Ende einen Tag später
    if (!data.start || !data.end) return;
    var s = new Date(data.start), e = new Date(data.end);
    if (e < s && data.start.slice(0, 10) === data.end.slice(0, 10)) {
      e.setDate(e.getDate() + 1);
      data.end = localIso(e);
      $('f-end').value = data.end;
      $('dur-hint').textContent = ' · Ende auf den Folgetag gesetzt';
    }
  }
  $('f-end').addEventListener('change', function () { fixMidnight(); duration(); changed(); });

  function duration() {
    var out = '–', hint = '';
    if (data.start && data.end) {
      var m = Math.round((new Date(data.end) - new Date(data.start)) / 60000);
      if (m >= 0) out = Math.floor(m / 60) + ':' + pad(m % 60) + ' h';
      else hint = ' · Ende liegt vor dem Beginn';
    }
    $('duration').textContent = out;
    if (hint) $('dur-hint').textContent = hint;
    else if ($('dur-hint').textContent.indexOf('Folgetag') < 0) $('dur-hint').textContent = '';
  }
  $('end-now').addEventListener('click', function () {
    data.end = localIso(new Date());
    $('f-end').value = data.end;
    $('dur-hint').textContent = '';
    duration();
    changed();
  });
  duration();

  var dl = $('subjects');
  (cfg.subjects || []).forEach(function (s) { var o = document.createElement('option'); o.value = s; dl.appendChild(o); });

  /* ---------- Erstellt durch (QR) ---------- */
  var cb = $('f-created_by');
  if (cb) {
    cfg.lists.personnel.items.slice().sort(function (a, b) { return a.name.localeCompare(b.name, 'de'); }).forEach(function (p) {
      var o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ((nameCount[p.name] || 0) > 1 ? ' (' + (p.divera_id ? '#' + p.divera_id : p.id) + ')' : ''); cb.appendChild(o);
    });
    if (!data.created_by) {
      try { var last = localStorage.getItem(LS_PERSON); if (last && peopleById[last]) { data.created_by = last; setTimeout(changed, 0); } } catch (e) { /* egal */ }
    }
    cb.value = data.created_by || '';
    cb.addEventListener('change', function () {
      data.created_by = cb.value || null;
      try { localStorage.setItem(LS_PERSON, cb.value); } catch (e) { /* egal */ }
      // wer den Bericht erstellt, war in der Regel dabei
      if (cb.value && !data.personnel.some(function (p) { return p.id === cb.value; }) && cfg.modules.personnel) togglePerson(cb.value, true);
      changed();
    });
  }

  /* ---------- Alarmierung ---------- */
  function renderAlert() {
    var box = $('alert-chips');
    if (!box) return;
    var cur = data.alert_source;
    var other = cur && !cur.id;
    var html = cfg.lists.alert_sources.map(function (s) {
      return '<button type="button" class="chip' + (cur && cur.id === s.id ? ' on' : '') + '" data-alert="' + esc(s.id) + '">' + esc(s.label) + '</button>';
    }).join('');
    html += '<button type="button" class="chip add' + (other ? ' on' : '') + '" data-alert="__other">' + ic('edit') + 'Andere …</button>';
    box.innerHTML = html;
    var inp = $('f-alert-other');
    inp.hidden = !other;
    if (other) inp.value = cur.label;
  }
  if ($('alert-chips')) {
    $('alert-chips').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-alert]');
      if (!b) return;
      var id = b.getAttribute('data-alert');
      if (id === '__other') {
        data.alert_source = { id: null, label: $('f-alert-other').value || '' };
        renderAlert();
        $('f-alert-other').focus();
      } else {
        data.alert_source = (data.alert_source && data.alert_source.id === id) ? null : { id: id, label: b.textContent };
        renderAlert();
      }
      changed();
    });
    $('f-alert-other').addEventListener('input', function () { data.alert_source = { id: null, label: this.value }; changed(); });
    renderAlert();
  }

  /* ---------- Zusatzfelder ---------- */
  function fieldControl(def, value, scope, owner) {
    var attrs = ' data-scope="' + scope + '" data-owner="' + esc(owner) + '" data-field="' + esc(def.id) + '"';
    var label = esc(def.label) + (def.required ? ' <span style="color:var(--fault)">*</span>' : '');
    if (def.type === 'number') {
      var icn = def.unit === 'min' ? 'drone' : (def.unit === 'km' ? 'truck' : 'sliders');
      return '<div class="stp"><span class="lb">' + ic(icn) + label + '</span>' +
        '<button type="button" class="b" data-step="-1"' + attrs + ' aria-label="weniger">' + ic('minus') + '</button>' +
        '<input class="v" type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*" value="' + (value == null ? '' : esc(value)) + '" placeholder="–"' + attrs + ' aria-label="' + esc(def.label) + '">' +
        '<button type="button" class="b" data-step="1"' + attrs + ' aria-label="mehr">' + ic('plus') + '</button>' +
        '<span class="unit">' + esc(def.unit || '') + '</span></div>';
    }
    if (def.type === 'checkbox') {
      return '<label class="toggle-row" style="border:0"><span class="tx"><b style="font-size:14px">' + label + '</b></span><span class="sw"><input type="checkbox"' + (value ? ' checked' : '') + attrs + '><span></span></span></label>';
    }
    if (def.type === 'select') {
      return '<label class="f">' + label + '<select class="in"' + attrs + '><option value="">–</option>' + (def.options || []).map(function (o) {
        return '<option' + (o === value ? ' selected' : '') + '>' + esc(o) + '</option>';
      }).join('') + '</select></label>';
    }
    var type = def.type === 'date' ? 'date' : 'text';
    return '<label class="f">' + label + '<input class="in" type="' + type + '" value="' + (value == null ? '' : esc(value)) + '"' + attrs + '></label>';
  }

  function fieldTarget(el) {
    var scope = el.getAttribute('data-scope'), owner = el.getAttribute('data-owner');
    if (scope === 'report') return data.custom_fields;
    var list = scope === 'person' ? data.personnel : data.vehicles;
    for (var i = 0; i < list.length; i++) if (list[i].id === owner) return list[i].fields;
    return null;
  }
  function parseNum(v) { v = String(v).replace(',', '.').trim(); if (v === '') return null; var n = Number(v); return isFinite(n) ? n : null; }

  document.addEventListener('input', function (ev) {
    var el = ev.target.closest('[data-field]');
    if (!el || el.tagName === 'BUTTON') return;
    var t = fieldTarget(el);
    if (!t) return;
    var f = el.getAttribute('data-field');
    if (el.type === 'checkbox') t[f] = el.checked;
    else if (el.classList.contains('v')) t[f] = parseNum(el.value);
    else t[f] = el.value === '' ? null : el.value;
    sums();
    changed();
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target.closest('[data-field]');
    if (el && el.type === 'checkbox') { var t = fieldTarget(el); if (t) { t[el.getAttribute('data-field')] = el.checked; changed(); } }
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-step]');
    if (!b) return;
    var t = fieldTarget(b);
    if (!t) return;
    var f = b.getAttribute('data-field');
    var inp = b.parentNode.querySelector('input.v');
    var unit = (b.parentNode.querySelector('.unit') || {}).textContent;
    var step = unit === 'min' ? 5 : 1;
    var cur = t[f] == null ? 0 : Number(t[f]);
    var next = Math.max(0, cur + step * Number(b.getAttribute('data-step')));
    if (unit === 'min' && cur % 5 !== 0) next = Number(b.getAttribute('data-step')) > 0 ? Math.ceil(cur / 5) * 5 : Math.floor(cur / 5) * 5;
    t[f] = next;
    inp.value = next;
    sums();
    changed();
  });

  function renderReportFields() {
    var defs = cfg.fields.report;
    var card = $('report-fields-card');
    if (!defs.length) return;
    card.hidden = false;
    $('report-fields').innerHTML = defs.map(function (d) { return '<div data-req="cf_' + esc(d.id) + '">' + fieldControl(d, data.custom_fields[d.id], 'report', '') + '</div>'; }).join('');
  }
  renderReportFields();

  /* ---------- Personal ---------- */
  var search = '';
  function togglePerson(id, on) {
    var idx = -1;
    data.personnel.forEach(function (p, i) { if (p.id === id) idx = i; });
    if (on === undefined) on = idx < 0;
    if (on && idx < 0) {
      var p = peopleById[id];
      var fields = {};
      cfg.fields.person.forEach(function (d) { fields[d.id] = null; });
      data.personnel.push({ id: p.id, name: p.name, divera_id: p.divera_id, fields: fields });
    } else if (!on && idx > -1) {
      data.personnel.splice(idx, 1);
      if (data.lead === id) data.lead = null;
    }
    renderPeople();
  }

  function renderPeople() {
    if (!$('people-chips')) return;
    var src = cfg.lists.personnel;
    $('people-src').innerHTML = src.source === 'divera' ? '<span class="src">Divera · ' + (src.stale ? 'Stand ' + esc(fmtDt(src.fetched_at)) : 'live') + '</span>' : '';
    var selected = {};
    data.personnel.forEach(function (p) { selected[p.id] = true; });
    var q = search.toLowerCase();
    var items = src.items.filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) > -1; });
    var limit = q ? 60 : 24;
    var html = items.slice(0, limit).map(function (p) {
      return '<button type="button" class="chip' + (selected[p.id] ? ' on' : '') + '" data-person="' + esc(p.id) + '" aria-pressed="' + (selected[p.id] ? 'true' : 'false') + '"><span class="av">' + esc(initials(p.name)) + '</span>' + personLabel(p) + '</button>';
    }).join('');
    if (items.length > limit) html += '<span class="hint" style="align-self:center">… ' + (items.length - limit) + ' weitere – Suche nutzen</span>';
    if (!items.length) html = '<span class="hint">Keine Person gefunden.</span>';
    $('people-chips').innerHTML = html;

    $('people-count').textContent = data.personnel.length;
    $('people-list').innerHTML = data.personnel.map(function (p) {
      var f = cfg.fields.person.map(function (d) { return fieldControl(d, p.fields[d.id], 'person', p.id); }).join('');
      return '<div class="card pc"><div class="hd"><div class="av2">' + esc(initials(p.name)) + '</div><b>' + personLabel(p) + '</b>' +
        '<button type="button" class="x" data-person-remove="' + esc(p.id) + '" aria-label="' + esc(p.name) + ' entfernen">' + ic('x') + '</button></div>' + f + '</div>';
    }).join('');
    renderLead();
    sums();
  }
  if ($('people-chips')) {
    $('people-chips').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-person]');
      if (!b) return;
      togglePerson(b.getAttribute('data-person'));
      changed();
    });
    $('people-list').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-person-remove]');
      if (!b) return;
      togglePerson(b.getAttribute('data-person-remove'), false);
      changed();
    });
    $('people-search').addEventListener('input', function () { search = this.value; renderPeople(); });
    if (cfg.lists.personnel.stale) {
      banner('stale', 'warn', 'Divera nicht erreichbar – Personal-Stand vom ' + esc(fmtDt(cfg.lists.personnel.fetched_at) || 'unbekannt') + '.');
    }
  }

  function renderLead() {
    var box = $('lead-chips');
    if (!box) return;
    box.innerHTML = data.personnel.map(function (p) {
      return '<button type="button" class="chip' + (data.lead === p.id ? ' on' : '') + '" data-lead="' + esc(p.id) + '"><span class="av">' + esc(initials(p.name)) + '</span>' + personLabel(p) + '</button>';
    }).join('');
    $('lead-hint').hidden = data.personnel.length > 0;
  }
  if ($('lead-chips')) {
    $('lead-chips').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-lead]');
      if (!b) return;
      var id = b.getAttribute('data-lead');
      data.lead = data.lead === id ? null : id;
      renderLead();
      changed();
    });
  }

  function sumBox(defs, list) {
    return defs.filter(function (d) { return d.type === 'number' && d.show_total; }).map(function (d) {
      var total = 0, any = false;
      list.forEach(function (x) { var v = x.fields[d.id]; if (v != null && v !== '') { total += Number(v); any = true; } });
      if (!any) return '';
      var icn = d.unit === 'min' ? 'drone' : (d.unit === 'km' ? 'truck' : 'sliders');
      var shown = Math.round(total * 10) / 10;
      var extra = d.unit === 'min' && total >= 60 ? ' <span style="opacity:.7;font-size:13px">(' + Math.floor(total / 60) + ':' + pad(total % 60) + ' h)</span>' : '';
      return '<div class="sum">' + ic(icn) + '<span>' + esc(d.label) + ' gesamt' + extra + '</span><b>' + String(shown).replace('.', ',') + ' ' + esc(d.unit || '') + '</b></div>';
    }).join('');
  }
  function sums() {
    if ($('people-sums')) $('people-sums').innerHTML = sumBox(cfg.fields.person, data.personnel);
    if ($('veh-sums')) $('veh-sums').innerHTML = data.vehicles.length > 1 ? sumBox(cfg.fields.vehicle, data.vehicles) : '';
  }

  /* ---------- Geräte ---------- */
  function renderEquipment() {
    var box = $('equip-chips');
    if (!box) return;
    box.innerHTML = cfg.lists.equipment.map(function (q) {
      var on = data.equipment.indexOf(q.id) > -1;
      return '<button type="button" class="chip' + (on ? ' on' : '') + '" data-equip="' + esc(q.id) + '" aria-pressed="' + on + '">' + (on ? ic('check') : '') + esc(q.label) + '</button>';
    }).join('') || '<span class="hint">Keine Geräte hinterlegt.</span>';
    $('equip-count').textContent = data.equipment.length ? data.equipment.length + ' gewählt' : '';
  }
  if ($('equip-chips')) {
    $('equip-chips').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-equip]');
      if (!b) return;
      var id = b.getAttribute('data-equip');
      var i = data.equipment.indexOf(id);
      if (i > -1) data.equipment.splice(i, 1); else data.equipment.push(id);
      renderEquipment();
      changed();
    });
  }

  /* ---------- Fahrzeuge ---------- */
  function renderVehicles() {
    var box = $('veh-list');
    if (!box) return;
    var vs = cfg.lists.vehicles;
    $('veh-src').innerHTML = vs.items.some(function (v) { return v.source === 'divera'; }) ? '<span class="src">Divera · ' + (vs.stale ? 'Stand ' + esc(fmtDt(vs.fetched_at)) : 'live') + '</span>' : '';
    var chosen = {};
    data.vehicles.forEach(function (v) { chosen[v.id] = v; });
    box.innerHTML = vs.items.map(function (v) {
      var c = chosen[v.id];
      var sub = [v.source === 'divera' ? 'Divera' : 'eigenes Fahrzeug', v.radio_call_sign, v.license_plate].filter(Boolean).join(' · ');
      var f = c ? cfg.fields.vehicle.map(function (d) { return fieldControl(d, c.fields[d.id], 'vehicle', v.id); }).join('') : '';
      return '<div class="veh' + (c ? ' on' : '') + '"><div class="top-row" data-veh="' + esc(v.id) + '" role="button" tabindex="0" aria-pressed="' + (c ? 'true' : 'false') + '"><span class="vck">' + ic('check') + '</span>' +
        '<div class="nm"><b>' + esc(v.label) + '</b><span>' + esc(sub) + '</span></div></div>' + f + '</div>';
    }).join('') || '<span class="hint">Keine Fahrzeuge hinterlegt.</span>';
    sums();
  }
  if ($('veh-list')) {
    var vehToggle = function (row) {
      var id = row.getAttribute('data-veh');
      var i = -1;
      data.vehicles.forEach(function (v, k) { if (v.id === id) i = k; });
      if (i > -1) data.vehicles.splice(i, 1);
      else {
        var fields = {};
        cfg.fields.vehicle.forEach(function (d) { fields[d.id] = null; });
        data.vehicles.push({ id: id, label: vehById[id].label, source: vehById[id].source, fields: fields });
      }
      renderVehicles();
      changed();
    };
    $('veh-list').addEventListener('click', function (ev) { var row = ev.target.closest('[data-veh]'); if (row) vehToggle(row); });
    $('veh-list').addEventListener('keydown', function (ev) {
      var row = ev.target.closest('[data-veh]');
      if (row && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); vehToggle(row); }
    });
    if (cfg.lists.vehicles.stale) banner('vstale', 'warn', 'Divera nicht erreichbar – Fahrzeug-Stand vom ' + esc(fmtDt(cfg.lists.vehicles.fetched_at) || 'unbekannt') + '.');
  }

  /* ---------- Divera-Bezug ---------- */
  function renderDiveraRef() {
    var d = R.divera;
    if (!d) return;
    $('divera-ref').hidden = false;
    $('divera-ref-text').innerHTML = (d.type === 'alarm' ? 'Alarm' : 'Termin') + ' Nr. ' + esc(d.id) + ' · <b>' + esc(d.title) + '</b>' + (d.text ? '<br><span class="muted">' + esc(d.text).replace(/\n/g, '<br>') + '</span>' : '');
    if ($('divera-btn')) $('divera-btn').hidden = true;
  }
  renderDiveraRef();

  /* ---------- Pflichtangaben ---------- */
  function missingList() {
    var m = [];
    var c = function (key, empty) { if (isReq(key) && empty) m.push(key); };
    c('start', !data.start);
    c('end', !data.end);
    c('location', !data.location.trim());
    c('subject', !data.subject.trim());
    c('narrative', !data.narrative.trim());
    if (cfg.modules.alert_source) c('alert_source', !data.alert_source || !(data.alert_source.id || (data.alert_source.label || '').trim()));
    if (cfg.modules.personnel) { c('personnel', !data.personnel.length); c('lead', !data.lead); }
    if (cfg.modules.equipment) c('equipment', !data.equipment.length);
    if (cfg.modules.vehicles) c('vehicles', !data.vehicles.length);
    cfg.fields.report.forEach(function (d) { if (d.required && data.custom_fields[d.id] == null) m.push('cf_' + d.id); });
    if (cfg.mode === 'qr' && !data.created_by) m.push('created_by');
    return m;
  }
  function totalRequired() {
    var n = cfg.required.filter(function (k) {
      if (k === 'type') return false;
      if (k === 'alert_source') return cfg.modules.alert_source;
      if (k === 'personnel' || k === 'lead') return cfg.modules.personnel;
      if (k === 'equipment') return cfg.modules.equipment;
      if (k === 'vehicles') return cfg.modules.vehicles;
      return true;
    }).length;
    n += cfg.fields.report.filter(function (d) { return d.required; }).length;
    if (cfg.mode === 'qr') n++;
    return n;
  }
  function paneOf(key) {
    var el = document.querySelector('[data-req="' + key + '"]');
    var pane = el && el.closest('.pane');
    return pane ? pane.getAttribute('data-pane') : 'basics';
  }
  function progress() {
    var miss = missingList();
    var total = totalRequired();
    var done = Math.max(0, total - miss.length);
    $('prog-text').textContent = 'Pflichtangaben ' + done + ' / ' + total;
    var bar = $('prog-bar');
    bar.querySelector('i').style.width = (total ? Math.round(done / total * 100) : 100) + '%';
    bar.classList.toggle('full', !miss.length);
    var missPanes = {};
    miss.forEach(function (k) { missPanes[paneOf(k)] = true; });
    document.querySelectorAll('#tabs button').forEach(function (b) {
      var p = b.getAttribute('data-pane');
      var label = b.textContent.trim();
      var done = !missPanes[p] && !b.classList.contains('on') && (visited[p] || wide.matches);
      b.classList.toggle('dn', done);
      b.innerHTML = (done ? ic('check') : '') + esc(label);
    });
    document.querySelectorAll('.miss-field').forEach(function (el) {
      var k = el.getAttribute('data-req');
      if (miss.indexOf(k) < 0) el.classList.remove('miss-field');
    });
    return miss;
  }

  /* ---------- Tabs ---------- */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('#tabs button'));
  function stickyOffset() {
    var h = 12;
    ['.tb', '#tabs'].forEach(function (sel) { var el = document.querySelector(sel); if (el && !el.hidden) h += el.getBoundingClientRect().height; });
    return h;
  }
  var visited = {};
  function showPane(name, scroll) {
    tabs.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pane') === name); });
    document.querySelectorAll('.pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-pane') === name); });
    var idx = tabs.findIndex(function (b) { return b.classList.contains('on'); });
    visited[name] = true;
    $('btn-next').hidden = wide.matches || idx >= tabs.length - 1;
    document.querySelector('.bb').classList.toggle('mid', idx < tabs.length - 1);
    progress();
    if (scroll !== false) {
      var pane = document.getElementById('pane-' + name);
      if (wide.matches && pane) {
        // unter Kopf- und Registerleiste (beide fixiert) landen, nicht dahinter
        var top = pane.getBoundingClientRect().top + window.pageYOffset - stickyOffset();
        window.scrollTo({ top: name === tabs[0].getAttribute('data-pane') ? 0 : Math.max(0, top), behavior: 'smooth' });
      } else window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }
  tabs.forEach(function (b) { b.addEventListener('click', function () { showPane(b.getAttribute('data-pane')); }); });
  $('btn-next').addEventListener('click', function () {
    var idx = tabs.findIndex(function (b) { return b.classList.contains('on'); });
    if (idx < tabs.length - 1) showPane(tabs[idx + 1].getAttribute('data-pane'));
  });
  wide.addEventListener ? wide.addEventListener('change', function () { showPane(tabs[0].getAttribute('data-pane'), false); }) : null;

  /* ---------- Dialog ---------- */
  function dialog(html) {
    var bg = document.createElement('div');
    bg.className = 'dlg-bg';
    bg.innerHTML = '<div class="dlg" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(bg);
    var first = bg.querySelector('button.pri') || bg.querySelector('button');
    if (first) first.focus();
    return { el: bg, close: function () { bg.remove(); } };
  }

  /* ---------- Divera ---------- */
  function diveraLookup(auto) {
    var isAlarm = cfg.divera.lookup === 'alarm';
    if (!auto) toast('Frage Divera ab …', 'info');
    api('divera_candidates', { n: cfg.number }).then(function (res) {
      if (!res.ok) {
        if (!auto || res.offline || res.unreachable) toast('Divera nicht erreichbar – bitte manuell ausfüllen.', 'warn');
        return;
      }
      if (!res.items.length) {
        if (!auto) toast(isAlarm ? 'Kein Alarm in den letzten ' + res.window + ' h.' : 'Kein Termin in den letzten ' + res.window + ' h.', 'info');
        return;
      }
      var sel = res.items[0].id;
      var list = res.items.map(function (c, i) {
        return '<button type="button" class="cand' + (i === 0 ? ' on' : '') + '" data-cand="' + esc(c.id) + '"><b>' + esc(c.title) + '</b>' +
          '<span>' + esc(fmtDt(c.start)) + (c.address ? ' · ' + esc(c.address) : '') + '</span>' +
          '<span>' + (isAlarm ? 'Rückmeldungen „eingesetzt“: ' : 'Zusagen: ') + c.attending + '</span>' +
          (c.imported_in ? '<span class="warn">' + ic('alert') + ' bereits in ' + esc(c.imported_in) + '</span>' : '') + '</button>';
      }).join('');
      var d = dialog('<h2>' + ic('radio') + (isAlarm ? 'Einsatz in Divera gefunden' : 'Übung in Divera gefunden') + '</h2>' +
        (res.items.length > 1 ? '<p class="muted small">Mehrere ' + (isAlarm ? 'Alarme' : 'Termine') + ' im Zeitfenster – bitte auswählen.</p>' : '') +
        '<div class="stack" style="gap:8px">' + list + '</div>' +
        '<p class="muted small">Übernommen werden Zeit, Stichwort, Ort' + (cfg.modules.personnel ? ', Personal' : '') + (isAlarm && cfg.modules.vehicles ? ', Fahrzeuge' : '') + '. Alles bleibt änderbar.</p>' +
        '<div class="acts"><button type="button" class="btn pri" data-act="take">' + ic('check') + 'Übernehmen</button><button type="button" class="btn" data-act="skip">Ohne Divera-Daten weiter</button></div>');
      d.el.addEventListener('click', function (ev) {
        var c = ev.target.closest('[data-cand]');
        if (c) {
          sel = c.getAttribute('data-cand');
          d.el.querySelectorAll('.cand').forEach(function (x) { x.classList.toggle('on', x === c); });
          return;
        }
        var a = ev.target.closest('[data-act]');
        if (!a) return;
        if (a.getAttribute('data-act') === 'skip') { d.close(); return; }
        a.disabled = true;
        save().then(function () {
          return api('divera_import', { n: cfg.number, source: cfg.divera.lookup, id: sel });
        }).then(function (r) {
          d.close();
          if (!r.ok) { toast(r.error || 'Übernahme fehlgeschlagen', 'err'); return; }
          applyImport(r.report, r.rev);
          toast('Aus Divera übernommen.', 'ok');
        });
      });
    });
  }
  function applyImport(rep, newRev) {
    rev = newRev;
    R = rep;
    ['start', 'end', 'location', 'subject'].forEach(function (k) { data[k] = rep[k] || ''; var el = $('f-' + k); if (el) el.value = data[k]; });
    data.alert_source = rep.alert_source;
    data.personnel = rep.personnel.map(function (p) {
      if (!peopleById[p.id]) peopleById[p.id] = { id: p.id, name: p.name, divera_id: p.divera_id, frequency: 0 };
      return { id: p.id, name: p.name, divera_id: p.divera_id, fields: p.fields || {} };
    });
    data.vehicles = (rep.vehicles || []).map(function (v) {
      if (!vehById[v.id]) { vehById[v.id] = { id: v.id, label: v.label, source: v.source }; cfg.lists.vehicles.items.push(vehById[v.id]); }
      return { id: v.id, label: v.label, source: v.source, fields: v.fields || {} };
    });
    duration(); renderAlert(); renderPeople(); renderVehicles(); renderDiveraRef(); progress();
    clearBackup();
  }
  if ($('divera-btn')) $('divera-btn').addEventListener('click', function () { diveraLookup(false); });

  /* ---------- Abschließen ---------- */
  function markMissing(miss) {
    miss.forEach(function (k) {
      var el = document.querySelector('[data-req="' + k + '"]');
      if (el) el.classList.add('miss-field');
    });
    var first = miss[0];
    showPane(paneOf(first), false);
    var el = document.querySelector('[data-req="' + first + '"]');
    if (el) {
      var y = el.getBoundingClientRect().top + window.pageYOffset - stickyOffset() - 8;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
      var f = el.matches('input,textarea,select') ? el : el.querySelector('input,textarea,select');
      if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 300);
    }
    var labels = miss.map(function (k) { return cfg.required_labels[k] || (k === 'created_by' ? 'Erstellt durch' : k.replace(/^cf_/, '')); });
    toast('Es fehlt noch: ' + labels.join(', '), 'warn');
  }

  $('btn-close').addEventListener('click', function () {
    var miss = missingList();
    if (miss.length) { markMissing(miss); return; }
    var d = dialog('<h2>' + ic('check') + (cfg.correction ? 'Korrektur abschließen?' : 'Bericht abschließen?') + '</h2>' +
      '<p>' + esc(cfg.type.title) + ' <b>' + esc(cfg.number) + '</b> wird als PDF und JSON gespeichert' + (cfg.correction ? ' und als Korrektur erneut versendet.' : ' und per Mail versendet.') + '</p>' +
      (cfg.mode === 'qr' ? '<p class="muted small">Danach kann der Bericht nur noch nach Anmeldung geändert werden.</p>' : '') +
      '<div class="acts"><button type="button" class="btn pri" data-act="ok">' + ic('check') + 'Abschließen</button><button type="button" class="btn" data-act="no">Weiter bearbeiten</button></div>');
    d.el.addEventListener('click', function (ev) {
      var a = ev.target.closest('[data-act]');
      if (!a) return;
      if (a.getAttribute('data-act') === 'no') { d.close(); return; }
      a.disabled = true;
      a.innerHTML = 'Wird abgeschlossen …';
      clearTimeout(saveTimer);
      api('close', { n: cfg.number, rev: cfg.correction ? null : rev, data: data }).then(function (res) {
        d.close();
        if (res.ok) { finish(res); return; }
        if (res.missing) { markMissing(Object.keys(res.missing)); return; }
        if (res.offline) { toast('Keine Verbindung – Abschließen braucht Netz. Deine Eingaben sind auf dem Gerät gesichert.', 'warn'); return; }
        if (res.conflict) { banner('conflict', 'warn', esc(res.error), [{ text: 'Neu laden', fn: function () { location.reload(); } }]); return; }
        toast(res.error || 'Abschließen fehlgeschlagen', 'err');
      });
    });
  });

  function finish(res) {
    closed = true;
    clearBackup();
    var mail = res.mail || {};
    var mailHtml = mail.status === 'sent' ? '<div class="msg ok">' + ic('send') + '<span>Per Mail versendet an ' + esc((mail.to || []).join(', ')) + '</span></div>'
      : mail.status === 'failed' ? '<div class="msg warn">' + ic('alert') + '<span>Bericht ist gespeichert, aber der Mailversand ist fehlgeschlagen' + (cfg.mode === 'qr' ? ' – die Leitung sieht das in der Übersicht.' : '. In der Übersicht „Erneut senden“.') + '</span></div>'
      : '<div class="msg info">' + ic('mail') + '<span>Mailversand ist ausgeschaltet – Bericht ist gespeichert.</span></div>';
    var acts = '<a class="btn pri block" href="' + esc(res.pdf_url || cfg.urls.pdf) + '" target="_blank" rel="noopener">' + ic('file') + 'PDF ansehen / herunterladen</a>';
    if (cfg.mode === 'qr') {
      acts += '<a class="btn block" href="' + esc(cfg.urls.restart) + '">' + ic('plus') + 'Weiteren Bericht erstellen</a>' +
        '<p class="muted small">Das PDF ist hier 24 Stunden auf diesem Gerät abrufbar.</p>';
    } else {
      acts += '<a class="btn block" href="' + esc(cfg.urls.home) + '">' + ic('home') + 'Zur Startseite</a>';
    }
    document.getElementById('tabs').hidden = true;
    document.querySelector('.bb').hidden = true;
    $('banners').innerHTML = '';
    setSave('', 'Abgeschlossen');
    $('form').innerHTML = '<div class="done" style="grid-column:1/-1"><div class="ok-ic">' + ic('check') + '</div><h1>' + esc(cfg.number) + ' abgeschlossen</h1>' +
      '<p class="muted">' + esc(cfg.type.title) + (res.correction ? ' · Korrektur ' + res.correction : '') + '</p>' +
      '<div class="stack" style="width:100%;max-width:440px">' + mailHtml + acts + '</div></div>';
    window.scrollTo(0, 0);
  }

  /* ---------- Start ---------- */
  renderPeople();
  renderEquipment();
  renderVehicles();
  showPane(tabs[0].getAttribute('data-pane'), false);

  // Auf diesem Gerät liegen neuere Eingaben, die den Server nicht erreicht haben?
  try {
    var bk = JSON.parse(localStorage.getItem(LS_KEY) || 'null');
    if (bk && bk.data && JSON.stringify(bk.data) !== JSON.stringify(data)) {
      banner('restore', 'info', 'Auf diesem Gerät gibt es noch nicht gespeicherte Eingaben vom ' + esc(fmtDt(bk.at)) + '.', [
        { text: 'Übernehmen', fn: function () { Object.keys(bk.data).forEach(function (k) { data[k] = bk.data[k]; });
          ['start', 'end', 'location', 'subject', 'narrative', 'defects'].forEach(function (k) { var el = $('f-' + k); if (el) el.value = data[k] || ''; });
          if (cb) cb.value = data.created_by || '';
          data.personnel.forEach(function (p) { if (!peopleById[p.id]) peopleById[p.id] = { id: p.id, name: p.name, frequency: 0 }; });
          duration(); renderAlert(); renderReportFields(); renderPeople(); renderEquipment(); renderVehicles(); openDefects(); banner('restore'); changed(); } },
        { text: 'Verwerfen', fn: function () { clearBackup(); banner('restore'); } }
      ]);
    } else if (bk) {
      clearBackup();
    }
  } catch (e) { /* egal */ }

  if (cfg.correction) setSave('busy', 'wird erst beim Abschließen gespeichert');
  if (cfg.divera.ask) diveraLookup(true);

  window.addEventListener('beforeunload', function (ev) {
    if (!closed && (saving || $('save-state').classList.contains('busy'))) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();
