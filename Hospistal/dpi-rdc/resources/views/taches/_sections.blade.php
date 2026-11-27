{{-- Les sections de « Mes tâches » : relues toutes les 15 s par la page. --}}
@if(empty($sections))
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-6">
    Aucune tâche n'est rattachée à votre rôle. Si vous pensez que c'est une erreur, demandez à l'administrateur de vérifier vos rôles.
</div>
@endif

@foreach($sections as $cle => $section)
<section class="bg-white border rounded-xl shadow-sm overflow-hidden" data-section="{{ $cle }}">
    <header class="flex items-center justify-between px-4 py-3 bg-green-50 border-b">
        <h3 class="font-semibold text-green-800">{{ $section['icone'] }} {{ $section['titre'] }}</h3>
        <span class="text-sm font-semibold text-gray-700">{{ $section['elements']->count() }} patient{{ $section['elements']->count() > 1 ? 's' : '' }}</span>
    </header>

    @if($section['elements']->isEmpty())
        <p class="px-4 py-4 text-sm text-gray-500">Rien en attente.</p>
    @elseif($cle === 'consultations')
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-2">Dossier</th><th class="text-left px-4 py-2">Patient</th>
                <th class="text-left px-4 py-2">Priorité</th><th class="text-left px-4 py-2">Lieu / spécialité</th>
                <th class="text-left px-4 py-2">Attente</th><th></th>
            </tr></thead>
            <tbody class="divide-y">
            @foreach($section['elements'] as $t)
                <tr>
                    <td class="px-4 py-2 font-mono text-xs">{{ $t->patient?->dossier_number }}</td>
                    <td class="px-4 py-2"><a href="{{ route('patients.show', $t->patient) }}" class="font-semibold text-blue-700 hover:underline">{{ $t->patient?->nom_complet }}</a>
                        <div class="text-xs text-gray-500">{{ ['M' => 'Masculin', 'F' => 'Féminin'][$t->patient?->sexe] ?? '' }}, {{ $t->patient?->libelleAge() }}</div></td>
                    <td class="px-4 py-2">
                        <span class="px-2 py-1 rounded-full text-xs font-semibold {{ ['urgent' => 'bg-red-100 text-red-800', 'moyen' => 'bg-amber-100 text-amber-800', 'normal' => 'bg-gray-100 text-gray-700'][$t->priorite] }}">{{ ucfirst($t->priorite) }}</span>
                    </td>
                    <td class="px-4 py-2">{{ $t->lieu }}<div class="text-xs text-gray-500">{{ $t->specialite }}</div></td>
                    <td class="px-4 py-2 text-xs text-gray-600 whitespace-nowrap">{{ $t->depuis?->diffForHumans() }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ $t->url }}" class="inline-flex items-center min-h-[36px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-semibold">Gérer →</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @elseif(in_array($cle, ['labo', 'imagerie'], true))
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-2">Dossier</th><th class="text-left px-4 py-2">Patient</th>
                <th class="text-left px-4 py-2">Examens demandés</th><th class="text-left px-4 py-2">Service</th><th></th>
            </tr></thead>
            <tbody class="divide-y">
            @foreach($section['elements'] as $t)
                <tr class="align-top">
                    <td class="px-4 py-2 font-mono text-xs">{{ $t->patient?->dossier_number }}</td>
                    <td class="px-4 py-2"><a href="{{ route('patients.show', $t->patient) }}" class="font-semibold text-blue-700 hover:underline">{{ $t->patient?->nom_complet }}</a>
                        <div class="text-xs text-gray-500">{{ ['M' => 'Masculin', 'F' => 'Féminin'][$t->patient?->sexe] ?? '' }}, {{ $t->patient?->libelleAge() }}</div>
                        @if($t->examen->urgence)<span class="inline-block mt-1 px-2 py-0.5 rounded bg-red-100 text-red-800 text-xs font-semibold">Urgent</span>@endif
                    </td>
                    <td class="px-4 py-2">
                        <table class="w-full text-sm border">
                            <thead class="bg-gray-50"><tr><th class="text-left px-2 py-1">Désignation</th><th class="text-left px-2 py-1">Résultat</th></tr></thead>
                            <tbody>
                            @foreach($t->examen->resultats as $r)
                                <tr class="border-t">
                                    <td class="px-2 py-1">
                                        <div class="font-medium">{{ $r->typeExamen?->libelle }}</div>
                                        <div class="text-xs text-gray-500">{{ $t->examen->date_prescription?->format('d/m/Y H:i') }}, {{ $t->examen->date_prescription?->diffForHumans() }}</div>
                                    </td>
                                    <td class="px-2 py-1">
                                        @if(! $t->paye)
                                            <span class="text-xs text-amber-700">En attente de paiement au guichet</span>
                                        @else
                                        <form method="POST" action="{{ route('taches.resultat', $r) }}" class="js-resultat-rapide">
                                            @csrf
                                            <label for="res-{{ $r->id }}" class="sr-only">Résultat {{ $r->typeExamen?->libelle }}</label>
                                            <input id="res-{{ $r->id }}" name="valeur" value="{{ $r->valeur_brute }}" autocomplete="off"
                                                   placeholder="Entrer résultat puis Entrée"
                                                   class="w-full min-h-[40px] rounded-lg border px-3 {{ $r->valeur_brute ? 'border-green-500 bg-green-50' : 'border-blue-300' }}">
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </td>
                    <td class="px-4 py-2">{{ $t->service }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ $t->url }}" class="inline-flex items-center min-h-[36px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-semibold">Gérer →</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @elseif($cle === 'pharmacie')
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-2">Dossier</th><th class="text-left px-4 py-2">Patient</th>
                <th class="text-left px-4 py-2">Produits prescrits</th><th class="text-left px-4 py-2">Service</th><th class="text-left px-4 py-2">Depuis</th><th></th>
            </tr></thead>
            <tbody class="divide-y">
            @foreach($section['elements'] as $t)
                <tr class="align-top">
                    <td class="px-4 py-2 font-mono text-xs">{{ $t->patient?->dossier_number }}</td>
                    <td class="px-4 py-2 font-semibold">{{ $t->patient?->nom_complet }}</td>
                    <td class="px-4 py-2"><ul class="list-disc list-inside">
                        @foreach($t->prescription->lignes as $l)
                        <li>{{ $l->medicament?->denomination_commune ?? $l->libelle_externe ?? 'Produit' }} — {{ rtrim(rtrim(number_format((float) $l->quantite_totale, 2, ',', ''), '0'), ',') }}</li>
                        @endforeach
                    </ul></td>
                    <td class="px-4 py-2">{{ $t->service }}</td>
                    <td class="px-4 py-2 text-xs text-gray-600 whitespace-nowrap">{{ $t->depuis?->diffForHumans() }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ $t->url }}" class="inline-flex items-center min-h-[36px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-semibold">Distribuer →</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @elseif($cle === 'soins')
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="text-left px-4 py-2">Heure</th><th class="text-left px-4 py-2">Patient</th>
                <th class="text-left px-4 py-2">Produit</th><th class="text-left px-4 py-2">Service / lit</th><th></th>
            </tr></thead>
            <tbody class="divide-y">
            @foreach($section['elements'] as $t)
                <tr>
                    <td class="px-4 py-2 font-semibold {{ $t->en_retard ? 'text-red-700' : '' }}">{{ sprintf('%02d:00', $t->heure) }} @if($t->en_retard)<span class="text-xs">(en retard)</span>@endif</td>
                    <td class="px-4 py-2 font-semibold">{{ $t->patient?->nom_complet }}</td>
                    <td class="px-4 py-2">{{ $t->plan->libelle }}</td>
                    <td class="px-4 py-2">{{ $t->service }}{{ $t->lit ? ' — lit '.$t->lit : '' }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ $t->url }}" class="inline-flex items-center min-h-[36px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-semibold">Administrer →</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</section>
@endforeach
