/* Présence en direct (bouton 📈 de PrintNest Web) : un signal par minute tant que la page est affichée.
   Seulement l'étape, le nombre de planches et le montant ; rien n'est gardé dans le navigateur.
   Le configurateur remplit window.etqLive = { step, items, cents } ; ailleurs, c'est l'accueil. */
(function () {
  'use strict';
  var last = '';
  function state() {
    var s = window.etqLive || { step: 'accueil', items: 0, cents: 0 };
    return JSON.stringify({ step: s.step, items: s.items || 0, cents: s.cents || 0 });
  }
  function send(force) {
    if (document.visibilityState !== 'visible' || !navigator.sendBeacon || window.etqLive === null) return;   // null : mode atelier, pas compté
    var body = state();
    if (!force && body === last) return;
    last = body;
    try { navigator.sendBeacon('/api/stats/live', new Blob([body], { type: 'text/plain' })); } catch (e) { /* sans conséquence */ }
  }
  setTimeout(function () { send(true); }, 1500);
  setInterval(function () { send(false); }, 3000);     // un changement d'étape part dans les 3 s
  setInterval(function () { send(true); }, 60000);     // signe de vie
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') send(true); });
})();
