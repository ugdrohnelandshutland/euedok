/* Große Zifferntastatur für die QR-PIN (4–6 Stellen) */
(function () {
  'use strict';
  var pad = document.getElementById('pinpad');
  if (!pad) return;
  var input = document.getElementById('pin');
  var dots = document.getElementById('pindots');
  var form = document.getElementById('pinform');
  var val = '';
  function draw() {
    var n = Math.max(4, val.length);
    var html = '';
    for (var i = 0; i < n; i++) html += '<i class="' + (i < val.length ? 'f' : '') + '"></i>';
    dots.innerHTML = html;
    input.value = val;
  }
  pad.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-d]');
    if (!b) return;
    var d = b.getAttribute('data-d');
    if (d === 'del') val = val.slice(0, -1);
    else if (val.length < 6) val += d;
    draw();
  });
  document.addEventListener('keydown', function (ev) {
    if (/^\d$/.test(ev.key) && val.length < 6) { val += ev.key; draw(); }
    else if (ev.key === 'Backspace') { val = val.slice(0, -1); draw(); }
    else if (ev.key === 'Enter' && val.length >= 4) form.submit();
  });
  form.addEventListener('submit', function (ev) { if (val.length < 4) ev.preventDefault(); });
  draw();
})();
