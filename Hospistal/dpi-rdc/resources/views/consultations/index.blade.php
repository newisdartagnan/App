@extends('layouts.app')
@section('title', 'Consultations')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Consultations</h2>
        <a href="{{ route('patients.index') }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouvelle consultation
        </a>
    </div>
    {{-- File d'attente médecin : consultations payées à la caisse --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4 border-l-4 border-green-500">
        <div class="px-4 py-3 border-b bg-green-50 flex items-center justify-between flex-wrap gap-2">
            <h3 class="font-semibold text-green-800 text-sm">🩺 File d'attente — consultations payées ({{ $fileAttente->count() }})</h3>
            <div class="flex items-center gap-2">
                {{-- Un formulaire GET : le filtre marche sans script, et
                     l'adresse porte la spécialité choisie. --}}
                <form method="GET" class="flex items-center gap-2">
                    @foreach(['recherche' => $recherche, 'statut' => $statut, 'date' => $date] as $cle => $valeur)
                        @if($valeur !== '')<input type="hidden" name="{{ $cle }}" value="{{ $valeur }}">@endif
                    @endforeach
                    <label for="filtre-specialite" class="text-xs text-green-800">Spécialité</label>
                    <select id="filtre-specialite" name="specialite"
                            class="border border-green-300 rounded-lg px-2 py-1 text-xs bg-white">
                        <option value="">Toutes ({{ $fileAttente->count() }})</option>
                        @foreach($specialitesEnFile as $s)
                        <option value="{{ $s }}" @selected($specialite === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <button class="bg-green-700 hover:bg-green-800 text-white rounded-lg px-3 py-1 text-xs">Filtrer</button>
                </form>
            </div>
        </div>
        @forelse($fileParSpecialite as $specialite => $groupe)
        <div class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wide {{ $maSpecialite && $specialite === $maSpecialite ? 'text-green-700' : 'text-gray-500' }}">
            {{ $specialite }} ({{ $groupe->count() }})
            @if($maSpecialite && $specialite === $maSpecialite) — votre spécialité @endif
        </div>
        @foreach($groupe as $visit)
        <div class="px-4 py-3 border-b last:border-0 flex items-center justify-between hover:bg-gray-50">
            <div>
                <p class="font-medium text-sm">
                    {{ $visit->patient->nom_complet }}
                    <span class="text-xs text-gray-400 font-normal">— {{ $visit->patient->dossier_number }}</span>
                    @if($visit->typeConsultation)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">{{ $visit->typeConsultation->libelle }} · {{ $visit->typeConsultation->prix_usd + 0 }} $</span>
                    @endif
                    @if($visit->gratuite)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">🆓 Contrôle gratuit</span>
                    @endif
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    Arrivé le {{ $visit->date_entree->format('d/m/Y à H:i') }}
                    — {{ $visit->estTriee() ? '✓ trié' : '⏳ à trier (infirmier)' }}
                    @if($visit->motif_consultation) — {{ \Illuminate\Support\Str::limit($visit->motif_consultation, 45) }} @endif
                </p>
            </div>
            <div class="flex gap-2">
                @if(! $visit->estTriee())
                <a href="{{ route('visites.triage', $visit) }}"
                   class="bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold px-3 py-2 rounded-lg whitespace-nowrap">
                    🩹 Trier
                </a>
                @endif
                @can('consultation.create')
                <a href="{{ route('visites.consulter', $visit) }}"
                   class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-2 rounded-lg whitespace-nowrap">
                    Consulter →
                </a>
                @endcan
            </div>
        </div>
        @endforeach
        @empty
        <div class="px-4 py-6 text-center text-gray-400 text-sm">Aucun patient en attente — la file se remplit dès que la caisse valide un paiement de consultation.</div>
        @endforelse
    </div>

    {{-- Patients déjà au cabinet : hors file, pour qu'un confrère ne les rappelle pas --}}
    @if($auCabinet->count() > 0)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4 border-l-4 border-blue-500">
        <div class="px-4 py-3 border-b bg-blue-50">
            <h3 class="font-semibold text-blue-800 text-sm">🚪 Au cabinet ({{ $auCabinet->count() }})</h3>
        </div>
        @foreach($auCabinet as $visit)
        <div class="px-4 py-2.5 border-b last:border-0 flex items-center justify-between text-sm flex-wrap gap-2">
            <span>
                {{ $visit->patient->nom_complet }}
                @if($visit->typeConsultation)
                <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">{{ $visit->typeConsultation->libelle }}</span>
                @endif
                <span class="text-xs text-gray-500">
                    avec {{ $visit->medecinConsultant?->nom_complet ?? 'un médecin' }}
                    depuis {{ $visit->consultation_debutee_at->format('H:i') }}
                </span>
            </span>
            <div class="flex gap-2">
                @can('consultation.create')
                <a href="{{ route('visites.consulter', $visit) }}" class="text-blue-700 hover:underline text-xs font-medium">Reprendre →</a>
                <form method="POST" action="{{ route('visites.liberer', $visit) }}">
                    @csrf
                    <button class="text-gray-500 hover:text-gray-700 hover:underline text-xs">Remettre en file</button>
                </form>
                @endcan
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Patients envoyés à la caisse, paiement en attente --}}
    @if($enAttentePaiement->count() > 0)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4 border-l-4 border-amber-400">
        <div class="px-4 py-3 border-b bg-amber-50">
            <h3 class="font-semibold text-amber-800 text-sm">⏳ En attente de paiement à la caisse ({{ $enAttentePaiement->count() }})</h3>
        </div>
        @foreach($enAttentePaiement as $visit)
        <div class="px-4 py-2.5 border-b last:border-0 flex items-center justify-between text-sm">
            <span>
                {{ $visit->patient->nom_complet }}
                <span class="text-xs text-gray-400">— envoyé à {{ $visit->date_entree->format('H:i') }}</span>
            </span>
            @php $fact = $visit->factures->firstWhere('statut', 'emise'); @endphp
            @if($fact)
            <a href="{{ route('caisse.show', $fact) }}" class="text-amber-700 hover:underline text-xs font-medium">Caisse →</a>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4">
        @if($specialite !== '')<input type="hidden" name="specialite" value="{{ $specialite }}">@endif
        <div class="flex flex-wrap gap-3">
            <label for="recherche" class="sr-only">Rechercher un patient</label>
            <input id="recherche" name="recherche" value="{{ $recherche }}" type="text"
                placeholder="Nom patient, n° dossier..."
                class="flex-1 min-w-64 min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            <label for="statut" class="sr-only">Statut</label>
            <select id="statut" name="statut"
                class="min-h-[44px] rounded-lg border border-gray-300 px-3 focus:border-blue-500">
                <option value="">Tous statuts</option>
                @foreach(['en_attente' => 'En attente', 'en_cours' => 'En cours', 'termine' => 'Terminé'] as $cle => $libelle)
                <option value="{{ $cle }}" @selected($statut === $cle)>{{ $libelle }}</option>
                @endforeach
            </select>
            <label for="date" class="sr-only">Jour</label>
            <input id="date" name="date" value="{{ $date }}" type="date"
                class="min-h-[44px] rounded-lg border border-gray-300 px-3 focus:border-blue-500">
            <button class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 rounded-lg min-h-[44px]">
                Rechercher
            </button>
            @if($recherche !== '' || $statut !== '' || $date !== '')
            <a href="{{ route('consultations.index') }}"
               class="text-sm text-gray-500 hover:underline self-center">Tout afficher</a>
            @endif
        </div>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Patient</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Type</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Motif</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($visits as $visit)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $visit->patient->nom_complet }}</p>
                        <p class="text-xs text-gray-400">{{ $visit->patient->dossier_number }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $visit->date_entree->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs
                            {{ $visit->type === 'urgence' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' }}">
                            {{ $visit->type === 'urgence' ? '🚨 Urgence' : 'Consultation' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 max-w-xs truncate">{{ $visit->motif_consultation }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs
                            {{ $visit->statut === 'termine' ? 'bg-green-100 text-green-700' :
                               ($visit->statut === 'en_cours' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ ucfirst(str_replace('_', ' ', $visit->statut)) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @if($visit->consultations->first())
                        <a href="{{ route('consultations.show', $visit->consultations->first()) }}"
                           class="text-blue-700 hover:underline text-xs font-medium">Voir</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucune consultation trouvée</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $visits->links() }}</div>
    </div>
</div>
@endsection