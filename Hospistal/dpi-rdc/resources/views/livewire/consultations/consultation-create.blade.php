<div>
    {{-- Barre de progression --}}
    <div class="flex items-center mb-6 bg-white rounded-xl shadow p-4">
        @foreach(['Motif & constantes', 'Anamnèse', 'Examen & diagnostic'] as $i => $label)
        <div class="flex items-center {{ $i > 0 ? 'flex-1' : '' }}">
            @if($i > 0)<div class="flex-1 h-0.5 {{ $etape > $i ? 'bg-blue-600' : 'bg-gray-200' }} mx-2"></div>@endif
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold
                    {{ $etape > $i + 1 ? 'bg-green-500 text-white' : ($etape === $i + 1 ? 'bg-blue-700 text-white' : 'bg-gray-200 text-gray-500') }}">
                    {{ $etape > $i + 1 ? '✓' : $i + 1 }}
                </div>
                <span class="text-sm {{ $etape === $i + 1 ? 'font-semibold text-blue-700' : 'text-gray-500' }}">{{ $label }}</span>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Alertes vitales --}}
    @if(count($alertes) > 0)
    <div class="bg-red-50 border border-red-300 rounded-xl p-4 mb-4">
        <h4 class="font-semibold text-red-800 mb-2">🚨 Valeurs critiques</h4>
        @foreach($alertes as $alerte)
        <p class="text-sm text-red-700">{{ $alerte }}</p>
        @endforeach
    </div>
    @endif

    {{-- ÉTAPE 1 : Motif & constantes --}}
    @if($etape === 1)
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Motif de consultation</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="block text-sm font-medium text-gray-700 mb-1">Type de visite</span>
                    <span class="inline-flex items-center min-h-[44px] px-4 py-2 rounded-lg text-sm font-semibold
                        {{ $type_visite === 'urgence' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ $type_visite === 'urgence' ? '🚨 Urgence' : ($type_visite === 'hospitalisation' ? '🛏️ Hospitalisation' : 'Ambulatoire') }}
                        <span class="ml-2 text-xs font-normal text-gray-500">(défini à l'accueil, payé en caisse)</span>
                    </span>
                </div>
                <div>
                    <label for="type_consultation" class="block text-sm font-medium text-gray-700 mb-1">Type consultation</label>
                    <select id="type_consultation" name="type_consultation" wire:model="type_consultation" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        <option value="initiale">Initiale</option>
                        <option value="suivi">Suivi</option>
                        <option value="urgence">Urgence</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label for="motif_consultation" class="block text-sm font-medium text-gray-700 mb-1">Motif <span class="text-red-500">*</span></label>
                    <input id="motif_consultation" name="motif_consultation" wire:model="motif_consultation" type="text"
                        placeholder="Ex: Fièvre depuis 3 jours, douleurs abdominales..."
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('motif_consultation') border-red-500 @enderror">
                    @error('motif_consultation')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="col-span-2">
                    <label for="symptomes_principaux" class="block text-sm font-medium text-gray-700 mb-1">Symptômes principaux</label>
                    <textarea id="symptomes_principaux" name="symptomes_principaux" wire:model="symptomes_principaux" rows="2"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Constantes vitales</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label for="poids_kg" class="block text-sm font-medium text-gray-700 mb-1">Poids (kg)</label>
                    <input id="poids_kg" name="poids_kg" wire:model="poids_kg" type="number" step="0.1"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="taille_cm" class="block text-sm font-medium text-gray-700 mb-1">Taille (cm)</label>
                    <input id="taille_cm" name="taille_cm" wire:model="taille_cm" type="number" step="0.1"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="temperature" class="block text-sm font-medium text-gray-700 mb-1">Température (°C)</label>
                    <input id="temperature" name="temperature" wire:model.live="temperature" type="number" step="0.1"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                        {{ $temperature && $temperature > 38.5 ? 'border-red-500 bg-red-50' : '' }}">
                </div>
                <div>
                    <label for="tension_systolique" class="block text-sm font-medium text-gray-700 mb-1">TA Systolique</label>
                    <input id="tension_systolique" name="tension_systolique" wire:model.live="tension_systolique" type="number"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                        {{ $tension_systolique && $tension_systolique > 140 ? 'border-red-500 bg-red-50' : '' }}">
                </div>
                <div>
                    <label for="tension_diastolique" class="block text-sm font-medium text-gray-700 mb-1">TA Diastolique</label>
                    <input id="tension_diastolique" name="tension_diastolique" wire:model.live="tension_diastolique" type="number"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="frequence_cardiaque" class="block text-sm font-medium text-gray-700 mb-1">Fréq. cardiaque (bpm)</label>
                    <input id="frequence_cardiaque" name="frequence_cardiaque" wire:model.live="frequence_cardiaque" type="number"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="frequence_respiratoire" class="block text-sm font-medium text-gray-700 mb-1">Fréq. respiratoire</label>
                    <input id="frequence_respiratoire" name="frequence_respiratoire" wire:model="frequence_respiratoire" type="number"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="saturation_o2" class="block text-sm font-medium text-gray-700 mb-1">SpO₂ (%)</label>
                    <input id="saturation_o2" name="saturation_o2" wire:model.live="saturation_o2" type="number" step="0.1"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                        {{ $saturation_o2 && $saturation_o2 < 95 ? 'border-red-500 bg-red-50' : '' }}">
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button wire:click="etapeSuivante" type="button"
                class="px-6 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg transition">
                Suivant →
            </button>
        </div>
    </div>

    {{-- ÉTAPE 2 : Anamnèse --}}
    @elseif($etape === 2)
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Anamnèse</h3>
            <div>
                <label for="histoire_maladie" class="block text-sm font-medium text-gray-700 mb-1">Histoire de la maladie</label>
                <textarea id="histoire_maladie" name="histoire_maladie" wire:model="histoire_maladie" rows="4"
                    placeholder="Décrivez l'évolution chronologique des symptômes..."
                    class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="antecedents_personnels" class="block text-sm font-medium text-gray-700 mb-1">Antécédents personnels</label>
                    <textarea id="antecedents_personnels" name="antecedents_personnels" wire:model="antecedents_personnels" rows="3"
                        placeholder="HTA, diabète, chirurgies antérieures..."
                        class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
                </div>
                <div>
                    <label for="antecedents_familiaux" class="block text-sm font-medium text-gray-700 mb-1">Antécédents familiaux</label>
                    <textarea id="antecedents_familiaux" name="antecedents_familiaux" wire:model="antecedents_familiaux" rows="3"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
                </div>
            </div>
            <div>
                <label for="allergies" class="block text-sm font-medium text-gray-700 mb-1">Allergies connues</label>
                <input id="allergies" name="allergies" wire:model="allergies" type="text"
                    placeholder="Pénicilline, arachides, latex..."
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
        </div>
        <div class="flex justify-between">
            <button wire:click="etapePrecedente" type="button"
                class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                ← Retour
            </button>
            <button wire:click="etapeSuivante" type="button"
                class="px-6 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg transition">
                Suivant →
            </button>
        </div>
    </div>

    {{-- ÉTAPE 3 : Examen & diagnostic --}}
    @elseif($etape === 3)
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Examen clinique</h3>
            <div>
                <label for="examen_general" class="block text-sm font-medium text-gray-700 mb-1">Examen général</label>
                <textarea id="examen_general" name="examen_general" wire:model="examen_general" rows="3"
                    placeholder="État général, conscience, coloration, hydratation..."
                    class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Diagnostics</h3>
            @if(count($diagnostics) > 0)
            <ul class="space-y-2 mb-4">
                @foreach($diagnostics as $i => $diag)
                <li class="flex items-center gap-3 p-2 bg-gray-50 rounded-lg text-sm">
                    @if($diag['code_cim10'])
                    <span class="font-mono text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">{{ $diag['code_cim10'] }}</span>
                    @endif
                    <span class="flex-1">{{ $diag['libelle'] }}</span>
                    <span class="text-xs px-2 py-1 rounded-full
                        {{ $diag['type'] === 'principal' ? 'bg-blue-100 text-blue-700' : 'bg-gray-200 text-gray-600' }}">
                        {{ $diag['type'] }}
                    </span>
                    <button wire:click="retirerDiagnostic({{ $i }})" type="button" class="text-red-500 hover:text-red-700 text-xs">✕</button>
                </li>
                @endforeach
            </ul>
            @endif
            <div class="grid grid-cols-4 gap-2">
                <input id="nouveau_diagnostic_code" name="nouveau_diagnostic_code" wire:model="nouveau_diagnostic_code" type="text" placeholder="CIM-10 (ex: J18)"
                    class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500">
                <input id="nouveau_diagnostic_libelle" name="nouveau_diagnostic_libelle" wire:model="nouveau_diagnostic_libelle" type="text" placeholder="Libellé du diagnostic"
                    class="col-span-2 min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500">
                <select id="nouveau_diagnostic_type" name="nouveau_diagnostic_type" wire:model="nouveau_diagnostic_type" class="min-h-[44px] rounded-lg border border-gray-300 px-3 text-sm">
                    <option value="principal">Principal</option>
                    <option value="associe">Associé</option>
                </select>
            </div>
            <button wire:click="ajouterDiagnostic" type="button"
                class="mt-2 px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition">
                + Ajouter ce diagnostic
            </button>
        </div>

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Conclusion</h3>
            <div>
                <label for="conclusion" class="block text-sm font-medium text-gray-700 mb-1">Conclusion clinique</label>
                <textarea id="conclusion" name="conclusion" wire:model="conclusion" rows="3"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
            </div>
            <div>
                <label for="conduite_a_tenir" class="block text-sm font-medium text-gray-700 mb-1">Conduite à tenir</label>
                <textarea id="conduite_a_tenir" name="conduite_a_tenir" wire:model="conduite_a_tenir" rows="3"
                    placeholder="Traitement prescrit, examens demandés, référence..."
                    class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
            </div>
        </div>

        <div class="flex justify-between pb-6">
            <button wire:click="etapePrecedente" type="button"
                class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                ← Retour
            </button>
            <button wire:click="save" type="button"
                class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition">
                ✓ Enregistrer la consultation
            </button>
        </div>
    </div>
    @endif
</div>