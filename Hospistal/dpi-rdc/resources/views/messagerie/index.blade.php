@extends('layouts.app')
@section('title', 'Chat')
@section('content')
<div class="grid md:grid-cols-3 gap-4" style="min-height: 70vh">
    <aside class="bg-white border rounded-xl shadow-sm overflow-hidden flex flex-col">
        <div class="px-4 py-3 border-b bg-gray-50"><h2 class="font-bold text-gray-800">💬 Conversations</h2></div>
        <div class="flex-1 overflow-y-auto divide-y">
            @forelse($conversations as $c)
            <a href="{{ route('messagerie.conversation', $c->agent) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-blue-50 {{ $avec?->id === $c->agent->id ? 'bg-blue-50' : '' }}">
                @include('compte._avatar', ['u' => $c->agent, 'taille' => 36])
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-sm truncate">{{ $c->agent->nom_complet }}</p>
                    <p class="text-xs text-gray-500 truncate">{{ $c->dernier?->supprime ? 'Message supprimé' : $c->dernier?->contenu }}</p>
                </div>
                @if($c->non_lus > 0)<span class="px-2 py-0.5 rounded-full bg-amber-400 text-amber-950 text-xs font-bold">{{ $c->non_lus }}</span>@endif
            </a>
            @empty
            <p class="px-4 py-6 text-sm text-gray-500">Aucune conversation pour l'instant.</p>
            @endforelse
        </div>
        <form method="GET" class="p-3 border-t bg-gray-50" onsubmit="if(this.agent.value){window.location=this.agent.value;}return false;">
            <label for="nv-agent" class="block text-xs font-semibold text-gray-600 mb-1">Nouveau message</label>
            <div class="flex gap-2">
                <select id="nv-agent" name="agent" class="flex-1 min-h-[40px] border rounded-lg px-2 text-sm">
                    <option value="">— Choisir un utilisateur —</option>
                    @foreach($agents as $a)<option value="{{ route('messagerie.conversation', $a) }}">{{ $a->nom_complet }} — {{ $a->libelleRoles() }}</option>@endforeach
                </select>
                <button class="min-h-[40px] px-3 bg-blue-700 text-white rounded-lg text-sm">→</button>
            </div>
        </form>
    </aside>

    <section class="md:col-span-2 bg-white border rounded-xl shadow-sm flex flex-col">
        @if($avec)
        <div class="px-4 py-3 border-b flex items-center gap-3">
            @include('compte._avatar', ['u' => $avec, 'taille' => 36])
            <div><p class="font-semibold">{{ $avec->nom_complet }}</p><p class="text-xs text-gray-500">{{ $avec->libelleRoles() }}</p></div>
        </div>
        <div id="fil" class="flex-1 overflow-y-auto p-4 space-y-2" style="max-height: 60vh" data-url="{{ route('messagerie.fil', $avec) }}">
            @include('messagerie._fil', ['messages' => $messages, 'moi' => auth()->user()])
        </div>
        <form id="form-message" method="POST" action="{{ route('messagerie.envoyer', $avec) }}" class="p-3 border-t flex gap-2">
            @csrf
            <label for="contenu" class="sr-only">Votre message</label>
            <textarea id="contenu" name="contenu" rows="2" required placeholder="Écrire un message… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)" class="flex-1 border rounded-lg px-3 py-2 text-sm"></textarea>
            <button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">Envoyer</button>
        </form>
        <script>
        (function () {
            var fil = document.getElementById('fil'), form = document.getElementById('form-message'), champ = document.getElementById('contenu');
            function enBas() { fil.scrollTop = fil.scrollHeight; }
            enBas();
            function relire() {
                fetch(fil.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d) return;
                        var enBasAvant = fil.scrollHeight - fil.scrollTop - fil.clientHeight < 40;
                        fil.innerHTML = d.html;
                        if (enBasAvant) enBas();
                    }).catch(function () {});
            }
            setInterval(relire, 5000);
            champ.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
            });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!champ.value.trim()) return;
                fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { if (r.ok) { champ.value = ''; relire(); setTimeout(enBas, 300); } else { alert('Message non envoyé.'); } })
                    .catch(function () { alert('Serveur injoignable — message non envoyé.'); });
            });
            fil.addEventListener('submit', function (e) {
                var f = e.target.closest('.js-supprimer'); if (!f) return;
                e.preventDefault();
                if (!confirm('Supprimer ce message ?')) return;
                fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(relire);
            });
        })();
        </script>
        @else
        <div class="flex-1 flex items-center justify-center p-8 text-center text-gray-500">
            <p>Choisissez une conversation, ou démarrez-en une nouvelle avec un utilisateur.</p>
        </div>
        @endif
    </section>
</div>
@endsection
