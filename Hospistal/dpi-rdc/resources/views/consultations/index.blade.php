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
    {{-- Sans ce bloc, les confirmations et refus renvoyés par le médecin
         (patient remis en file, patient déjà au cabinet…) n'atteignaient
         jamais l'écran. --}}
    @foreach(['success','error','info'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : ($t==='error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800') }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <livewire:consultations.consultation-list />
</div>
@endsection