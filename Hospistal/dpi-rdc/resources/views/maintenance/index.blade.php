@extends('layouts.app')
@section('title', 'Maintenance')
@section('content')
<div class="space-y-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">🧰 Maintenance</h2>
        <p class="text-sm text-gray-500">Superviser le serveur sans se déplacer jusqu'à la machine.</p>
    </div>

    <div class="grid md:grid-cols-2 gap-4 items-start">
        <section class="bg-white border rounded-xl shadow-sm p-4 space-y-3">
            <h3 class="font-semibold text-green-800">Adresse</h3>
            <p class="text-sm">Les postes du réseau local se connectent à :</p>
            <p class="font-mono text-lg font-bold text-blue-800 select-all">{{ $adresse }}</p>
            @if($appUrl && $appUrl !== $adresse)<p class="text-xs text-gray-500">Adresse configurée (APP_URL) : <span class="font-mono">{{ $appUrl }}</span></p>@endif
            <form method="POST" action="{{ route('maintenance.rafraichir') }}">
                @csrf
                <button class="min-h-[40px] px-4 rounded-lg border text-sm font-semibold hover:bg-gray-50">🔄 Vider les caches et relancer les files</button>
            </form>
            <p class="text-xs text-gray-500">Remède simple aux ralentissements, sans couper le serveur.</p>
        </section>

        <section class="bg-white border rounded-xl shadow-sm p-4 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-green-800">Utilisateurs connectés ({{ $connectes->pluck('user_id')->unique()->count() }})</h3>
                <a href="{{ route('maintenance.index', request()->only('mode')) }}" class="text-sm text-blue-700 hover:underline">⟳ Scanner</a>
            </div>
            @if(! $sessionsBase)
                <p class="text-sm text-amber-700">Les sessions ne sont pas gardées en base (SESSION_DRIVER ≠ database) : la liste n'est pas disponible.</p>
            @else
            <ul class="divide-y text-sm max-h-72 overflow-y-auto">
                @forelse($connectes as $s)
                <li class="py-2 flex items-center justify-between gap-2">
                    <span>{{ $utilisateurs[$s->user_id]?->nom_complet ?? '—' }} <span class="text-xs text-gray-400 font-mono">{{ $s->ip_address }}</span></span>
                    <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</span>
                </li>
                @empty
                <li class="py-2 text-gray-500">Personne d'autre n'est connecté.</li>
                @endforelse
            </ul>
            <form method="POST" action="{{ route('maintenance.deconnecter') }}" onsubmit="return confirm('Déconnecter tous les autres utilisateurs ?');">
                @csrf
                <button class="min-h-[40px] px-4 rounded-lg border border-red-300 text-red-700 text-sm font-semibold hover:bg-red-50">Déconnecter tous les utilisateurs</button>
            </form>
            @endif
        </section>

        <section class="bg-white border rounded-xl shadow-sm p-4 space-y-3 md:col-span-2">
            <h3 class="font-semibold text-green-800">Sauvegarder la base de données</h3>
            <p class="text-sm text-gray-600">Une sauvegarde est faite automatiquement chaque nuit ; les {{ config('dpi.sauvegardes_conservees') }} dernières sont gardées sur le serveur. La copie chiffrée vers le serveur central suit le script <span class="font-mono">backup.sh</span>.</p>
            <form method="POST" action="{{ route('maintenance.sauvegarder') }}">
                @csrf
                <button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">💾 Sauvegarder maintenant</button>
            </form>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Fichier</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Taille</th><th></th></tr></thead>
                <tbody class="divide-y">
                @forelse($sauvegardes as $s)
                    <tr>
                        <td class="px-3 py-2 font-mono text-xs">{{ $s['nom'] }}</td>
                        <td class="px-3 py-2">{{ $s['date']->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2">{{ number_format($s['taille'] / 1048576, 2, ',', ' ') }} Mo</td>
                        <td class="px-3 py-2 text-right"><a href="{{ route('maintenance.telecharger', $s['nom']) }}" class="text-blue-700 hover:underline">Télécharger</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-4 text-gray-500">Aucune sauvegarde sur ce serveur pour l'instant.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        @if($modeDieu)
        <section class="bg-red-50 border border-red-300 rounded-xl p-4 space-y-3 md:col-span-2">
            <h3 class="font-semibold text-red-800">⚠️ Vider la base de données</h3>
            <p class="text-sm text-red-800">Pour repartir d'une base vierge après une formation ou des essais. Les comptes, les rôles, les paramètres, les catalogues et le journal d'audit ne sont jamais proposés. <strong>Faites d'abord une sauvegarde.</strong></p>
            <form method="POST" action="{{ route('maintenance.vider') }}" class="space-y-3">
                @csrf
                <div class="grid sm:grid-cols-2 md:grid-cols-4 gap-1 max-h-80 overflow-y-auto bg-white border rounded-lg p-3">
                    @foreach($tables as $t)
                    <label class="flex items-center gap-2 text-xs font-mono"><input type="checkbox" name="tables[]" value="{{ $t }}"> {{ $t }}</label>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div><label for="m-conf" class="block text-xs font-semibold text-red-800 mb-1">Tapez VIDER pour confirmer</label>
                        <input id="m-conf" name="confirmation" autocomplete="off" class="min-h-[44px] border border-red-300 rounded-lg px-3 font-mono"></div>
                    <button class="min-h-[44px] px-5 bg-red-700 hover:bg-red-800 text-white rounded-lg text-sm font-semibold">Vider les tables sélectionnées</button>
                </div>
            </form>
        </section>
        @endif
    </div>
</div>
@endsection
