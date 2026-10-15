/* Tag/Nacht früh setzen, damit nichts aufblitzt */
(function () {
  try {
    var t = localStorage.getItem('euedok-theme');
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
  } catch (e) { /* Speicher gesperrt – Gerät entscheidet */ }
})();
