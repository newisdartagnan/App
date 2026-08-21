@extends('layouts.app')
@section('title', 'Fiche obstétricale — '.$grossesse->patient->nom_complet)
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('maternite.index') }}" class="text-blue-700 hover:underline text-sm">← Maternité</a>
        <h2 class="text-2xl font-bold text-gray-800">👶 {{ $grossesse->patient->nom_complet }}</h2>
        <span class="px-2 py-0.5 rounded-full text-xs {{ $grossesse->estEnCours() ? 'bg-blue-100 text-blue-800' : 'bg-gray-200 text-gray-600' }}">
            {{ $grossesse->libelleStatut() }}
        </span>
        @if($grossesse->grossesse_a_risque)
        <span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-900">Grossesse à risque</span>
        @endif
        <a href="{{ route('maternite.fiche', $grossesse) }}" target="_blank"
           class="text-sm text-blue-700 hover:underline">🖨️ Fiche imprimable</a>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        {{ $grossesse->patient->dossier_number }} ·
        {{ $grossesse->formuleObstetricale() }} ·
        {{ $grossesse->enfants_vivants }} enfant(s) vivant(s)
        @if($grossesse->groupe_sanguin) · groupe {{ $grossesse->groupe_sanguin }} @endif
    </p>

    @include('partials._flash')

    {{-- Repères de la grossesse --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        @php $terme = $grossesse->termeSemaines(); @endphp
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-blue-800">{{ $terme !== null ? $terme.' SA' : '—' }}</p>
            <p class="text-xs text-gray-500 mt-1">Terme aujourd'hui</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-lg font-bold text-gray-800">
                {{ $grossesse->date_prevue_accouchement?->format('d/m/Y') ?? '—' }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Date prévue d'accouchement</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $grossesse->consultations->count() }}</p>
            <p class="text-xs text-gray-500 mt-1">Consultations prénatales</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-lg font-bold text-gray-800">
                {{ $grossesse->date_dernieres_regles?->format('d/m/Y') ?? '—' }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Dernières règles</p>
        </div>
    </div>

    @if($grossesse->serologiesPositives() !== [] || $grossesse->antecedents)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm text-amber-900">
        @if($grossesse->serologiesPositives() !== [])
        <p><strong>Sérologies positives :</strong> {{ implode(' · ', $grossesse->serologiesPositives()) }}</p>
        @endif
        @if($grossesse->antecedents)
        <p><strong>Antécédents :</strong> {{ $grossesse->antecedents }}</p>
        @endif
    </div>
    @endif

    {{-- Consultations prénatales --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Suivi prénatal</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-3 py-2">CPN</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2 text-center">Terme</th>
                        <th class="px-3 py-2 text-right">Poids</th>
                        <th class="px-3 py-2 text-center">Tension</th>
                        <th class="px-3 py-2 text-center">HU</th>
                        <th class="px-3 py-2 text-center">BCF</th>
                        <th class="px-3 py-2 text-center">Alb.</th>
                        <th class="px-3 py-2 text-center">Hb</th>
                        <th class="px-3 py-2">Alertes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($grossesse->consultations as $cpn)
                    @php $alertes = $cpn->alertes(); @endphp
                    <tr class="{{ $alertes !== [] ? 'bg-red-50/50' : '' }}">
                        <td class="px-3 py-2 font-semibold">{{ $cpn->numero }}</td>
                        <td class="px-3 py-2 text-xs whitespace-nowrap">{{ $cpn->date_consultation->format('d/m/Y') }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $cpn->terme_semaines ? $cpn->terme_semaines.' SA' : '—' }}</td>
                        <td class="px-3 py-2 text-right text-xs">{{ $cpn->poids_kg ? ($cpn->poids_kg + 0).' kg' : '—' }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $cpn->tension() ?? '—' }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $cpn->hauteur_uterine_cm ? ($cpn->hauteur_uterine_cm + 0).' cm' : '—' }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $cpn->bruits_coeur_foetal ?? '—' }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $cpn->albuminurie ?: '—' }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $cpn->hemoglobine ? ($cpn->hemoglobine + 0) : '—' }}</td>
                        <td class="px-3 py-2 text-xs text-red-700">{{ implode(' · ', $alertes) ?: '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">Aucune consultation enregistrée</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($grossesse->estEnCours())
        <details class="border-t">
            <summary class="px-5 py-3 cursor-pointer text-sm font-medium text-blue-700 select-none">
                ➕ Nouvelle consultation prénatale
            </summary>
            <form method="POST" action="{{ route('maternite.cpn', $grossesse) }}" class="grid md:grid-cols-4 gap-3 px-5 pb-5 pt-2">
                @csrf
                <div>
                    <label for="c-date" class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                    <input id="c-date" name="date_consultation" type="date" value="{{ now()->toDateString() }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="c-poids" class="block text-xs font-semibold text-gray-600 mb-1">Poids (kg)</label>
                    <input id="c-poids" name="poids_kg" type="number" step="0.1" min="20" max="200"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="c-tas" class="block text-xs font-semibold text-gray-600 mb-1">TA systolique</label>
                    <input id="c-tas" name="tension_systolique" type="number" min="50" max="300" placeholder="120"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="c-tad" class="block text-xs font-semibold text-gray-600 mb-1">TA diastolique</label>
                    <input id="c-tad" name="tension_diastolique" type="number" min="30" max="200" placeholder="80"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div>
                    <label for="c-hu" class="block text-xs font-semibold text-gray-600 mb-1">Hauteur utérine (cm)</label>
                    <input id="c-hu" name="hauteur_uterine_cm" type="number" step="0.5" min="5" max="60"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="c-bcf" class="block text-xs font-semibold text-gray-600 mb-1">BCF (bpm)</label>
                    <input id="c-bcf" name="bruits_coeur_foetal" type="number" min="40" max="220" placeholder="140"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="c-alb" class="block text-xs font-semibold text-gray-600 mb-1">Albuminurie</label>
                    <select id="c-alb" name="albuminurie" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        @foreach(['' => '—', 'negatif' => 'Négative', 'traces' => 'Traces', '+' => '+', '++' => '++', '+++' => '+++'] as $v => $l)
                        <option value="{{ $v }}">{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="c-hb" class="block text-xs font-semibold text-gray-600 mb-1">Hémoglobine (g/dL)</label>
                    <input id="c-hb" name="hemoglobine" type="number" step="0.1" min="2" max="25"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div>
                    <label for="c-oed" class="block text-xs font-semibold text-gray-600 mb-1">Œdèmes</label>
                    <select id="c-oed" name="oedemes" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        @foreach(['' => '—', 'absents' => 'Absents', '+' => '+', '++' => '++', '+++' => '+++'] as $v => $l)
                        <option value="{{ $v }}">{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="c-vat" class="block text-xs font-semibold text-gray-600 mb-1">VAT (dose)</label>
                    <input id="c-vat" name="vat_dose" type="number" min="1" max="5"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="c-rdv" class="block text-xs font-semibold text-gray-600 mb-1">Prochain rendez-vous</label>
                    <input id="c-rdv" name="prochain_rendez_vous" type="date"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div class="flex flex-col justify-end gap-1 pb-1 text-xs text-gray-700">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="fer_folates" value="1" class="rounded"> Fer + acide folique
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="sulfadoxine_pyrimethamine" value="1" class="rounded"> SP (paludisme)
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="moustiquaire_remise" value="1" class="rounded"> Moustiquaire remise
                    </label>
                </div>

                <div class="md:col-span-2">
                    <label for="c-obs" class="block text-xs font-semibold text-gray-600 mb-1">Observations</label>
                    <input id="c-obs" name="observations" maxlength="2000"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="c-cat" class="block text-xs font-semibold text-gray-600 mb-1">Conduite à tenir</label>
                    <input id="c-cat" name="conduite_a_tenir" maxlength="2000"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div class="md:col-span-4">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Enregistrer la consultation
                    </button>
                </div>
            </form>
        </details>
        @endif
    </div>

    {{-- Accouchement --}}
    @if($grossesse->accouchement)
    @php $acc = $grossesse->accouchement; @endphp
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Accouchement du {{ $acc->date_accouchement->format('d/m/Y à H:i') }}
        </div>
        <div class="p-5 grid md:grid-cols-3 gap-4 text-sm">
            <div>
                <p class="text-xs text-gray-500">Mode</p>
                <p class="font-medium">{{ $acc->libelleMode() }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Terme</p>
                <p class="font-medium">
                    {{ $acc->terme_semaines ? $acc->terme_semaines.' SA' : '—' }}
                    @if($acc->estPremature())
                    <span class="text-xs bg-amber-100 text-amber-900 px-1.5 py-0.5 rounded">prématuré</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Durée du travail</p>
                <p class="font-medium">{{ $acc->dureeTravail() ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Saignement</p>
                <p class="font-medium {{ $acc->estHemorragique() ? 'text-red-700' : '' }}">
                    {{ $acc->saignement_ml !== null ? $acc->saignement_ml.' ml' : '—' }}
                    @if($acc->estHemorragique()) — hémorragie de la délivrance @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Accoucheur</p>
                <p class="font-medium">{{ $acc->accoucheur?->nom_complet ?? $acc->sage_femme ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">État de la mère</p>
                <p class="font-medium">{{ \App\Models\Accouchement::ETATS_MERE[$acc->etat_mere] ?? $acc->etat_mere }}</p>
            </div>
        </div>

        <div class="border-t px-5 py-3">
            <p class="text-xs font-semibold text-gray-600 mb-2">
                Nouveau-né{{ $acc->nouveauNes->count() > 1 ? 's' : '' }}
                @if($acc->estMultiple())<span class="text-amber-800">— grossesse multiple</span>@endif
            </p>
            <div class="grid md:grid-cols-2 gap-3">
                @foreach($acc->nouveauNes as $enfant)
                <div class="border border-gray-200 rounded-lg p-3 text-sm {{ $enfant->estVivant() ? '' : 'bg-gray-50' }}">
                    <p class="font-medium">
                        Enfant {{ $enfant->rang }} — {{ $enfant->libelleSexe() }}
                        <span class="text-xs px-1.5 py-0.5 rounded {{ $enfant->estVivant() ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' }}">
                            {{ $enfant->libelleStatut() }}
                        </span>
                    </p>
                    <p class="text-xs text-gray-600">
                        {{ $enfant->poids_g ? $enfant->poids_g.' g' : '—' }}
                        @if($enfant->taille_cm) · {{ $enfant->taille_cm + 0 }} cm @endif
                        @if($enfant->perimetre_cranien_cm) · PC {{ $enfant->perimetre_cranien_cm + 0 }} cm @endif
                    </p>
                    <p class="text-xs text-gray-600">Apgar : {{ $enfant->apgar() }}</p>
                    @if($enfant->estPetitPoids())
                    <p class="text-xs text-amber-800">Petit poids de naissance</p>
                    @endif
                    @if($enfant->souffranceNeonatale())
                    <p class="text-xs text-red-700 font-semibold">Souffrance néonatale — surveillance rapprochée</p>
                    @endif
                    @if($enfant->patient)
                    <a href="{{ route('patients.show', $enfant->patient) }}" class="text-xs text-blue-700 hover:underline">
                        Dossier {{ $enfant->patient->dossier_number }} →
                    </a>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
    </div>

    @elseif($grossesse->estEnCours())
    @include('maternite._formulaire-accouchement')
    @endif
</div>
@endsection
