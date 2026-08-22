@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-800">🔔 Notifications</h2>
        @if($compteurs['toutes'] > 0)
        <form method="POST" action="{{ route('notifications.tout-lu') }}">
            @csrf
            @if(\App\Models\NotificationInterne::estUnService($onglet))
            <input type="hidden" name="service" value="{{ $onglet }}">
            @endif
            <button class="min-h-[40px] px-4 py-1.5 border border-blue-700 text-blue-700 rounded-lg text-sm hover:bg-blue-50">✓ Tout marquer comme lu</button>
        </form>
        @endif
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4 text-sm">{{ session('success') }}</div>
    @endif

    {{-- Onglets par service (modèle CSK) --}}
    <div class="flex flex-wrap gap-1 border-b border-gray-300 mb-3 text-sm">
        @foreach(\App\Models\NotificationInterne::SERVICES as $cle => $libelle)
        <a href="{{ route('notifications.index', ['onglet' => $cle]) }}"
           class="px-4 py-2 rounded-t-lg border border-b-0 {{ $onglet === $cle ? 'bg-white font-semibold text-blue-800 border-gray-300' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100' }}">
            {{ $libelle }}
            @if($compteurs[$cle] > 0)<span class="ml-1 bg-red-600 text-white text-xs rounded-full px-1.5 py-0.5">{{ $compteurs[$cle] }}</span>@endif
        </a>
        @endforeach
    </div>

    <div class="flex gap-2 mb-4 text-xs">
        <a href="{{ route('notifications.index', ['onglet' => $onglet]) }}"
           class="px-3 py-1.5 rounded-lg border {{ ! $nonLuesSeulement ? 'bg-blue-700 text-white border-blue-700' : 'text-blue-700 border-blue-300' }}">Toutes</a>
        <a href="{{ route('notifications.index', ['onglet' => $onglet, 'lu' => 0]) }}"
           class="px-3 py-1.5 rounded-lg border {{ $nonLuesSeulement ? 'bg-blue-700 text-white border-blue-700' : 'text-blue-700 border-blue-300' }}">Non lues</a>
    </div>

    <div class="bg-white rounded-xl shadow divide-y divide-gray-100">
        @forelse($notifications as $notif)
        @php
            [$couleur, $badge] = \App\Models\NotificationInterne::COULEURS[$notif->service]
                ?? ['border-gray-400', 'bg-gray-100 text-gray-600'];
            $icone = match($notif->type) {
                'prescription_recue' => '📋', 'resultat_pret' => '✅',
                'medicament_delivre' => '💊', 'poche_delivree' => '🩸',
                'incident_transfusionnel' => '🚨', 'demande_refusee' => '🚫',
                'transfert_service' => '🛏️', 'alerte_soins' => '⚠️',
                'alerte' => '⚠️', default => '🔔',
            };
        @endphp
        <div class="flex items-start gap-3 px-4 py-3 {{ $notif->lu ? 'bg-gray-50/60' : 'border-l-4 ' . $couleur }}">
            <span class="text-xl mt-0.5">{{ $icone }}</span>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-0.5">
                    <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded {{ $badge }}">{{ $notif->libelleService() }}</span>
                    @if($notif->priorite === 'urgente')<span class="text-[10px] font-bold bg-red-600 text-white px-1.5 py-0.5 rounded">URGENT</span>
                    @elseif($notif->priorite === 'haute')<span class="text-[10px] font-bold bg-amber-400 text-amber-950 px-1.5 py-0.5 rounded">HAUTE</span>@endif
                    @unless($notif->lu)<span class="text-[10px] font-bold bg-blue-600 text-white px-1.5 py-0.5 rounded">NOUVEAU</span>@endunless
                </div>
                <p class="text-sm {{ $notif->lu ? 'text-gray-600' : 'font-semibold text-gray-900' }}">{{ $notif->titre }}</p>
                <p class="text-xs text-gray-500">{{ $notif->message }}</p>
                @if($notif->lien())
                <form method="POST" action="{{ route('notifications.lue', $notif) }}" class="mt-1.5"
                      @if($notif->lienEstDocument()) target="_blank" @endif>
                    @csrf
                    <input type="hidden" name="ouvrir" value="1">
                    <button class="text-xs text-blue-700 border border-blue-300 rounded px-2 py-1 hover:bg-blue-50">
                        @if($notif->lienEstDocument())
                            📄 Ouvrir le {{ $notif->service === 'imagerie' ? 'compte rendu' : 'bulletin' }}
                            {{ $notif->code_reference }}
                        @else
                            → Voir {{ $notif->code_reference ?: 'le détail' }}
                        @endif
                    </button>
                </form>
                @endif
            </div>
            <div class="text-right shrink-0">
                <p class="text-[11px] text-gray-400 mb-1">{{ $notif->created_at->format('d/m H:i') }}</p>
                <div class="flex gap-1 justify-end">
                    @unless($notif->lu)
                    <form method="POST" action="{{ route('notifications.lue', $notif) }}">@csrf<button title="Marquer comme lu" class="text-green-700 border border-green-300 rounded px-1.5 py-0.5 text-xs hover:bg-green-50">✓</button></form>
                    @endunless
                    <form method="POST" action="{{ route('notifications.archiver', $notif) }}">@csrf<button title="Archiver" class="text-gray-500 border border-gray-300 rounded px-1.5 py-0.5 text-xs hover:bg-gray-100">🗂</button></form>
                </div>
            </div>
        </div>
        @empty
        <p class="px-4 py-12 text-center text-gray-400 text-sm">🔕 Aucune notification</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
</div>
@endsection
