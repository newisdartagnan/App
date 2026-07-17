<div>
    {{-- File d'attente médecin : consultations payées à la caisse --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4 border-l-4 border-green-500">
        <div class="px-4 py-3 border-b bg-green-50 flex items-center justify-between">
            <h3 class="font-semibold text-green-800 text-sm">🩺 File d'attente — consultations payées ({{ $fileAttente->count() }})</h3>
            <span class="text-xs text-green-700">Urgences en premier</span>
        </div>
        @forelse($fileParSpecialite as $specialite => $groupe)
        <div class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wide {{ $specialite === '🚨 Urgences' ? 'text-red-700' : ($maSpecialite && $specialite === $maSpecialite ? 'text-green-700' : 'text-gray-500') }}">
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
                    Arrivé à {{ $visit->date_entree->format('H:i') }}
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
                <a href="{{ route('visites.consulter', $visit) }}"
                   class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-2 rounded-lg whitespace-nowrap">
                    Consulter →
                </a>
            </div>
        </div>
        @endforeach
        @empty
        <div class="px-4 py-6 text-center text-gray-400 text-sm">Aucun patient en attente — la file se remplit dès que la caisse valide un paiement de consultation.</div>
        @endforelse
    </div>

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

    <div class="bg-white rounded-xl shadow p-4 mb-4">
        <div class="flex gap-3">
            <input id="search" name="search" wire:model.live.debounce.300ms="search" type="text"
                placeholder="Nom patient, n° dossier..."
                class="flex-1 min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            <select id="statut" name="statut" wire:model.live="statut"
                class="min-h-[44px] rounded-lg border border-gray-300 px-3 focus:border-blue-500">
                <option value="">Tous statuts</option>
                <option value="en_attente">En attente</option>
                <option value="en_cours">En cours</option>
                <option value="termine">Terminé</option>
            </select>
            <input id="date" name="date" wire:model.live="date" type="date"
                class="min-h-[44px] rounded-lg border border-gray-300 px-3 focus:border-blue-500">
        </div>
    </div>

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