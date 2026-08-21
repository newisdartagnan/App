{{-- Enregistrement de l'accouchement : il clôt la grossesse et ouvre le
     dossier de chaque enfant vivant. --}}
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="px-5 py-3 border-b font-semibold text-gray-700">Enregistrer l'accouchement</div>

    <form method="POST" action="{{ route('maternite.accouchement', $grossesse) }}" class="p-5">
        @csrf

        <div class="grid md:grid-cols-4 gap-3">
            <div>
                <label for="a-date" class="block text-xs font-semibold text-gray-600 mb-1">
                    Date et heure <span class="text-red-500">*</span>
                </label>
                <input id="a-date" name="date_accouchement" type="datetime-local" required
                       value="{{ old('date_accouchement', now()->format('Y-m-d\TH:i')) }}"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            </div>
            <div>
                <label for="a-travail" class="block text-xs font-semibold text-gray-600 mb-1">Début du travail</label>
                <input id="a-travail" name="debut_travail" type="datetime-local" value="{{ old('debut_travail') }}"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            </div>
            <div>
                <label for="a-mode" class="block text-xs font-semibold text-gray-600 mb-1">
                    Mode <span class="text-red-500">*</span>
                </label>
                <select id="a-mode" name="mode" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    @foreach($modes as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('mode') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-presentation" class="block text-xs font-semibold text-gray-600 mb-1">Présentation</label>
                <select id="a-presentation" name="presentation" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <option value="">—</option>
                    @foreach($presentations as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('presentation') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="a-delivrance" class="block text-xs font-semibold text-gray-600 mb-1">Délivrance</label>
                <select id="a-delivrance" name="delivrance" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <option value="">—</option>
                    @foreach($delivrances as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('delivrance') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-dechirure" class="block text-xs font-semibold text-gray-600 mb-1">Déchirure</label>
                <select id="a-dechirure" name="dechirure" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <option value="">—</option>
                    @foreach($dechirures as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('dechirure') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-saignement" class="block text-xs font-semibold text-gray-600 mb-1">
                    Saignement (ml)
                </label>
                <input id="a-saignement" name="saignement_ml" type="number" min="0" max="10000" step="50"
                       value="{{ old('saignement_ml') }}" placeholder="300"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">Au-delà de 500 ml : hémorragie.</p>
            </div>
            <div>
                <label for="a-etat" class="block text-xs font-semibold text-gray-600 mb-1">
                    État de la mère <span class="text-red-500">*</span>
                </label>
                <select id="a-etat" name="etat_mere" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    @foreach($etatsMere as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('etat_mere', 'bon') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="a-accoucheur" class="block text-xs font-semibold text-gray-600 mb-1">Accoucheur</label>
                <select id="a-accoucheur" name="accoucheur_id" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                    <option value="">—</option>
                    @foreach($accoucheurs as $soignant)
                    <option value="{{ $soignant->id }}" @selected(old('accoucheur_id') === $soignant->id)>
                        {{ $soignant->nom }} {{ $soignant->prenom }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-sf" class="block text-xs font-semibold text-gray-600 mb-1">Sage-femme</label>
                <input id="a-sf" name="sage_femme" maxlength="150" value="{{ old('sage_femme') }}"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            </div>
            <div class="md:col-span-2 flex flex-wrap gap-4 items-end pb-1">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="episiotomie" value="1" @checked(old('episiotomie')) class="rounded">
                    Épisiotomie
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="transfusion" value="1" @checked(old('transfusion')) class="rounded">
                    Transfusion
                </label>
            </div>

            <div class="md:col-span-2">
                <label for="a-compl" class="block text-xs font-semibold text-gray-600 mb-1">Complications</label>
                <input id="a-compl" name="complications" maxlength="2000" value="{{ old('complications') }}"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label for="a-obs" class="block text-xs font-semibold text-gray-600 mb-1">Observations</label>
                <input id="a-obs" name="observations" maxlength="2000" value="{{ old('observations') }}"
                       class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            </div>
        </div>

        {{-- Les enfants. Deux lignes suffisent au cas courant et aux jumeaux ;
             la troisième couvre les triplés. --}}
        <div class="mt-5 pt-4 border-t">
            <p class="text-sm font-semibold text-gray-700 mb-1">Nouveau-né(s)</p>
            <p class="text-xs text-gray-500 mb-3">
                Une ligne par enfant. Chaque enfant vivant reçoit son propre dossier de
                patient, ouvert au nom de sa mère. Un mort-né est déclaré ici, sans dossier.
            </p>

            @for($i = 0; $i < 3; $i++)
            <div class="grid md:grid-cols-8 gap-2 mb-2 items-end {{ $i > 0 ? 'opacity-90' : '' }}">
                <div>
                    <label for="e-sexe-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">
                        Enfant {{ $i + 1 }}
                    </label>
                    <select id="e-sexe-{{ $i }}" name="enfants[{{ $i }}][sexe]"
                            class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        <option value="">—</option>
                        <option value="F" @selected(old("enfants.$i.sexe") === 'F')>Fille</option>
                        <option value="M" @selected(old("enfants.$i.sexe") === 'M')>Garçon</option>
                    </select>
                </div>
                <div>
                    <label for="e-prenom-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">Prénom</label>
                    <input id="e-prenom-{{ $i }}" name="enfants[{{ $i }}][prenom]" maxlength="100"
                           value="{{ old("enfants.$i.prenom") }}" placeholder="à préciser"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="e-poids-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">Poids (g)</label>
                    <input id="e-poids-{{ $i }}" name="enfants[{{ $i }}][poids_g]" type="number" min="200" max="7000"
                           value="{{ old("enfants.$i.poids_g") }}" placeholder="3200"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="e-taille-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">Taille (cm)</label>
                    <input id="e-taille-{{ $i }}" name="enfants[{{ $i }}][taille_cm]" type="number" step="0.5" min="15" max="70"
                           value="{{ old("enfants.$i.taille_cm") }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="e-pc-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">PC (cm)</label>
                    <input id="e-pc-{{ $i }}" name="enfants[{{ $i }}][perimetre_cranien_cm]" type="number" step="0.5" min="15" max="60"
                           value="{{ old("enfants.$i.perimetre_cranien_cm") }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="e-a1-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">Apgar 1'</label>
                    <input id="e-a1-{{ $i }}" name="enfants[{{ $i }}][apgar_1]" type="number" min="0" max="10"
                           value="{{ old("enfants.$i.apgar_1") }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="e-a5-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">Apgar 5'</label>
                    <input id="e-a5-{{ $i }}" name="enfants[{{ $i }}][apgar_5]" type="number" min="0" max="10"
                           value="{{ old("enfants.$i.apgar_5") }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="e-statut-{{ $i }}" class="block text-xs font-semibold text-gray-600 mb-1">État</label>
                    <select id="e-statut-{{ $i }}" name="enfants[{{ $i }}][statut]"
                            class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        @foreach($statutsEnfant as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old("enfants.$i.statut", 'vivant') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @endfor
        </div>

        <div class="mt-4">
            <button class="bg-green-700 hover:bg-green-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                ✓ Enregistrer l'accouchement et clore la grossesse
            </button>
        </div>
    </form>
</div>
