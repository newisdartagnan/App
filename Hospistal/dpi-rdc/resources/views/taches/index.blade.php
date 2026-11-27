@extends('layouts.app')
@section('title', 'Mes tâches')
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Mes tâches</h2>
            <p class="text-sm text-gray-500">La liste se met à jour toute seule. Restez sur cette page : le nouveau travail apparaît ici.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500" id="taches-maj" aria-live="polite"></span>
            <button type="button" id="taches-bip"
                    class="min-h-[44px] px-4 rounded-lg border border-blue-300 text-blue-800 bg-white hover:bg-blue-50 text-sm font-semibold">
                🔔 Activer le bip
            </button>
        </div>
    </div>

    <div id="taches-zone" class="space-y-4" data-total="{{ $total }}" data-url="{{ route('taches.fragment') }}">
        @include('taches._sections', ['sections' => $sections])
    </div>
</div>

<script>
(function () {
    var zone = document.getElementById('taches-zone');
    var boutonBip = document.getElementById('taches-bip');
    var maj = document.getElementById('taches-maj');
    var total = parseInt(zone.dataset.total || '0', 10);
    var cleBip = 'dpi-taches-bip';
    var bipActif = false;
    try { bipActif = localStorage.getItem(cleBip) === '1'; } catch (e) {}

    function afficherBouton() {
        boutonBip.textContent = bipActif ? '🔕 Désactiver le bip' : '🔔 Activer le bip';
        boutonBip.setAttribute('aria-pressed', bipActif ? 'true' : 'false');
    }
    afficherBouton();

    // Un bip court, produit par le navigateur : aucun fichier son à servir.
    function biper() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.35, 0.7].forEach(function (t) {
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
                g.gain.setValueAtTime(0.2, ctx.currentTime + t);
                o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.18);
            });
        } catch (e) {}
    }

    boutonBip.addEventListener('click', function () {
        bipActif = !bipActif;
        try { localStorage.setItem(cleBip, bipActif ? '1' : '0'); } catch (e) {}
        afficherBouton();
        if (bipActif) biper();
    });

    // Saisie rapide d'un résultat : Entrée envoie, la case passe au vert.
    zone.addEventListener('submit', function (ev) {
        var form = ev.target.closest('.js-resultat-rapide');
        if (!form) return;
        ev.preventDefault();
        var champ = form.querySelector('input[name="valeur"]');
        if (!champ.value.trim()) return;
        var donnees = new FormData(form);
        fetch(form.action, {
            method: 'POST', body: donnees, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
              champ.classList.remove('border-blue-300', 'border-red-500', 'bg-red-50');
              if (res.ok) {
                  champ.classList.add('border-green-500', 'bg-green-50');
                  champ.blur();
                  var suivant = zone.querySelectorAll('.js-resultat-rapide input[name="valeur"]');
                  for (var i = 0; i < suivant.length; i++) {
                      if (suivant[i] === champ && suivant[i + 1]) { suivant[i + 1].focus(); break; }
                  }
              } else {
                  champ.classList.add('border-red-500', 'bg-red-50');
                  alert(res.j.message || 'Résultat non enregistré.');
              }
          }).catch(function () { alert('Serveur injoignable — résultat non enregistré.'); });
    });

    function enSaisie() {
        var actif = document.activeElement;
        return actif && zone.contains(actif) && actif.tagName === 'INPUT';
    }

    function rafraichir() {
        if (enSaisie()) return; // ne jamais effacer ce qu'on est en train de taper
        fetch(zone.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || enSaisie()) return;
                zone.innerHTML = d.html;
                if (d.total > total && bipActif) biper();
                total = d.total;
                maj.textContent = 'Mis à jour à ' + new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }).catch(function () { maj.textContent = 'Serveur injoignable — nouvel essai dans 15 s'; });
    }
    setInterval(rafraichir, 15000);
})();
</script>
@endsection
