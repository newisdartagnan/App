{{--
    Recherche patient au fil de la frappe, en JavaScript pur (fetch + DOM).
    Aucune dépendance à Livewire/Alpine : fonctionne même si une CSP
    stricte bloque eval() — même approche que CSK prescriptions.php.
--}}
<div>
    <label for="recherche-patient" class="block text-sm font-medium text-gray-700 mb-1">Rechercher un patient</label>
    <input
        id="recherche-patient"
        name="recherche-patient"
        type="search"
        placeholder="Nom, prénom, n° dossier, téléphone… (2 lettres minimum)"
        autocomplete="off"
        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 text-base focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
    >
    <div id="recherche-patient-resultats" class="mt-4 space-y-2"></div>
</div>

<script>
(function () {
    var champ = document.getElementById('recherche-patient');
    var zone = document.getElementById('recherche-patient-resultats');
    if (!champ || !zone) return;

    var minuterie = null;
    var derniereRequete = 0;

    function echapper(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function afficher(patients, terme) {
        if (!patients.length) {
            zone.innerHTML = '<p class="text-gray-500 text-sm py-3">Aucun patient trouvé pour « ' + echapper(terme) + ' ».</p>';
            return;
        }
        var html = '';
        patients.forEach(function (p) {
            html += '<a href="' + p.url + '" class="flex items-center justify-between p-4 min-h-[44px] rounded-lg border border-gray-200 hover:border-blue-400 hover:bg-blue-50 transition">'
                + '<span><span class="font-semibold block">' + echapper(p.nom_complet) + '</span>'
                + '<span class="text-sm text-gray-600">' + echapper(p.dossier)
                + (p.date_naissance ? ' — ' + echapper(p.date_naissance) : '')
                + (p.telephone ? ' — ' + echapper(p.telephone) : '') + '</span></span>'
                + '<span class="ml-3 bg-blue-700 text-white text-xs font-semibold px-4 py-2 rounded-lg whitespace-nowrap">Ouvrir le dossier →</span>'
                + '</a>';
        });
        zone.innerHTML = html;
    }

    function chercher() {
        var terme = champ.value.trim();
        if (terme.length < 2) { zone.innerHTML = ''; return; }

        var id = ++derniereRequete;
        fetch('{{ route('patients.recherche') }}?q=' + encodeURIComponent(terme), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.ok ? r.json() : { patients: [] }; })
        .then(function (data) {
            if (id !== derniereRequete) return; // réponse obsolète
            afficher(data.patients || [], terme);
        })
        .catch(function () {
            zone.innerHTML = '<p class="text-red-600 text-sm py-3">Recherche indisponible — vérifier la connexion au serveur.</p>';
        });
    }

    champ.addEventListener('input', function () {
        clearTimeout(minuterie);
        minuterie = setTimeout(chercher, 250);
    });
})();
</script>
