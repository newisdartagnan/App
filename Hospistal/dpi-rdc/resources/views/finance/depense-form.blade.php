@extends('layouts.app')
@section('title', 'Nouvelle dépense')
@section('content')
@include('finance._onglets')
<h3 class="text-xl font-semibold mb-3">Nouvelle dépense @if($reception)— paiement de la réception {{ $reception->numero }}@endif</h3>
<form method="POST" action="{{ route('finance.depenses.store') }}" enctype="multipart/form-data" class="bg-white rounded-xl shadow p-5 grid md:grid-cols-3 gap-3 max-w-4xl">
    @csrf
    @if($reception)<input type="hidden" name="reception_id" value="{{ $reception->id }}">@endif
    <div><label for="de-date" class="block text-sm font-semibold mb-1">Date <span class="text-red-500">*</span></label><input id="de-date" type="date" name="date_depense" value="{{ old('date_depense', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="de-rub" class="block text-sm font-semibold mb-1">Rubrique <span class="text-red-500">*</span></label>
        <select id="de-rub" name="rubrique_id" required class="w-full min-h-[44px] border rounded-lg px-3"><option value="">— Choisir —</option>@foreach($rubriques as $r)<option value="{{ $r->id }}" @selected(old('rubrique_id', $reception ? $rubriqueMedicaments : null) === $r->id)>{{ $r->libelle }}</option>@endforeach</select></div>
    <div><label for="de-srv" class="block text-sm font-semibold mb-1">Service concerné</label>
        <select id="de-srv" name="service_id" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">— Établissement —</option>@foreach($services as $s)<option value="{{ $s->id }}" @selected(old('service_id') === $s->id)>{{ $s->nom }}</option>@endforeach</select></div>
    <div class="md:col-span-2"><label for="de-lib" class="block text-sm font-semibold mb-1">Libellé <span class="text-red-500">*</span></label><input id="de-lib" name="libelle" required value="{{ old('libelle', $reception ? 'Règlement fournisseur — réception '.$reception->numero : '') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="de-ben" class="block text-sm font-semibold mb-1">Bénéficiaire</label><input id="de-ben" name="beneficiaire" value="{{ old('beneficiaire', $reception?->fournisseur?->nom) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="de-four" class="block text-sm font-semibold mb-1">Fournisseur</label>
        <select id="de-four" name="fournisseur_id" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">—</option>@foreach($fournisseurs as $f)<option value="{{ $f->id }}" @selected(old('fournisseur_id', $reception?->fournisseur_id) === $f->id)>{{ $f->nom }}</option>@endforeach</select></div>
    <div><label for="de-mt" class="block text-sm font-semibold mb-1">Montant <span class="text-red-500">*</span></label><input id="de-mt" name="montant" type="number" step="0.01" min="0.01" required value="{{ old('montant', $reception?->resteAPayer()) }}" class="w-full min-h-[44px] border rounded-lg px-3 text-right"></div>
    <div><label for="de-dev" class="block text-sm font-semibold mb-1">Devise</label><select id="de-dev" name="devise" class="w-full min-h-[44px] border rounded-lg px-3"><option value="CDF" @selected(old('devise', $reception?->devise) === 'CDF')>CDF</option><option value="USD" @selected(old('devise', $reception?->devise) === 'USD')>USD</option></select></div>
    <div><label for="de-mode" class="block text-sm font-semibold mb-1">Mode de paiement</label><select id="de-mode" name="mode_paiement" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">—</option>@foreach(\App\Models\CompteTresorerie::MODES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div><label for="de-ref" class="block text-sm font-semibold mb-1">N° de pièce (facture, reçu)</label><input id="de-ref" name="reference_piece" value="{{ old('reference_piece', $reception?->numero_facture_fournisseur) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div class="md:col-span-2"><label for="de-piece" class="block text-sm font-semibold mb-1">Pièce justificative (PDF, photo)</label><input id="de-piece" type="file" name="piece" accept=".pdf,image/*" class="w-full text-sm border rounded-lg"></div>
    <div class="md:col-span-3"><button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer — à faire approuver</button></div>
</form>
@endsection
