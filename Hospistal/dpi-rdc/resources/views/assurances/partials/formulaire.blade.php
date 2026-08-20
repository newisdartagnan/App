{{-- Contrat d'une société conventionnée : création et modification --}}
<form method="POST" action="{{ $action }}" class="grid md:grid-cols-3 gap-3">
    @csrf
    <div class="md:col-span-2">
        <label for="a-nom-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Nom de la société <span class="text-red-500">*</span>
        </label>
        <input id="a-nom-{{ $assurance?->id ?? 'new' }}" name="nom" required maxlength="150"
               value="{{ old('nom', $assurance?->nom) }}"
               placeholder="SONAS, Mutuelle ASBL Santé, Gécamines…"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label for="a-code-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Code <span class="text-red-500">*</span>
        </label>
        <input id="a-code-{{ $assurance?->id ?? 'new' }}" name="code" required maxlength="30"
               value="{{ old('code', $assurance?->code) }}" placeholder="SONAS"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
    </div>

    <div>
        <label for="a-taux-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Taux de couverture (%) <span class="text-red-500">*</span>
        </label>
        <input id="a-taux-{{ $assurance?->id ?? 'new' }}" name="taux_couverture" type="number" step="0.01" min="0" max="100" required
               value="{{ old('taux_couverture', $assurance?->taux_couverture ?? 80) }}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label for="a-ticket-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Ticket modérateur (%)
        </label>
        <input id="a-ticket-{{ $assurance?->id ?? 'new' }}" name="ticket_moderateur" type="number" step="0.01" min="0" max="100"
               value="{{ old('ticket_moderateur', $assurance?->ticket_moderateur ?? 0) }}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <p class="text-xs text-gray-500 mt-1">Part toujours laissée au patient, déduite du taux.</p>
    </div>
    <div>
        <label for="a-delai-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Délai de règlement (jours) <span class="text-red-500">*</span>
        </label>
        <input id="a-delai-{{ $assurance?->id ?? 'new' }}" name="delai_reglement_jours" type="number" min="0" max="365" required
               value="{{ old('delai_reglement_jours', $assurance?->delai_reglement_jours ?? 30) }}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label for="a-mode-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Mode de règlement <span class="text-red-500">*</span>
        </label>
        <select id="a-mode-{{ $assurance?->id ?? 'new' }}" name="mode_reglement" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            @foreach($modes as $cle => $libelle)
            <option value="{{ $cle }}" @selected(old('mode_reglement', $assurance?->mode_reglement ?? 'virement') === $cle)>{{ $libelle }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="a-periode-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Périodicité de facturation <span class="text-red-500">*</span>
        </label>
        <select id="a-periode-{{ $assurance?->id ?? 'new' }}" name="periodicite_facturation" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            @foreach($periodicites as $cle => $libelle)
            <option value="{{ $cle }}" @selected(old('periodicite_facturation', $assurance?->periodicite_facturation ?? 'mensuelle') === $cle)>{{ $libelle }}</option>
            @endforeach
        </select>
    </div>
    <div></div>

    <div>
        <label for="a-pcdf-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Plafond annuel (CDF)
        </label>
        <input id="a-pcdf-{{ $assurance?->id ?? 'new' }}" name="plafond_annuel_cdf" type="number" step="1" min="0"
               value="{{ old('plafond_annuel_cdf', $assurance?->plafond_annuel_cdf) }}" placeholder="illimité"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label for="a-pusd-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Plafond annuel (USD)
        </label>
        <input id="a-pusd-{{ $assurance?->id ?? 'new' }}" name="plafond_annuel_usd" type="number" step="0.01" min="0"
               value="{{ old('plafond_annuel_usd', $assurance?->plafond_annuel_usd) }}" placeholder="illimité"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div></div>

    <div>
        <label for="a-cnom-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">Contact</label>
        <input id="a-cnom-{{ $assurance?->id ?? 'new' }}" name="contact_nom" maxlength="150"
               value="{{ old('contact_nom', $assurance?->contact_nom) }}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label for="a-ctel-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">Téléphone</label>
        <input id="a-ctel-{{ $assurance?->id ?? 'new' }}" name="contact_telephone" maxlength="50"
               value="{{ old('contact_telephone', $assurance?->contact_telephone) }}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label for="a-cmail-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">Courriel</label>
        <input id="a-cmail-{{ $assurance?->id ?? 'new' }}" name="contact_email" type="email" maxlength="150"
               value="{{ old('contact_email', $assurance?->contact_email) }}"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div class="md:col-span-3">
        <label for="a-notes-{{ $assurance?->id ?? 'new' }}" class="block text-xs font-semibold text-gray-600 mb-1">Notes du contrat</label>
        <textarea id="a-notes-{{ $assurance?->id ?? 'new' }}" name="notes" rows="2" maxlength="2000"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ old('notes', $assurance?->notes) }}</textarea>
    </div>

    <div class="md:col-span-3">
        <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
            {{ $assurance ? 'Enregistrer les modifications' : 'Créer la convention' }}
        </button>
    </div>
</form>
