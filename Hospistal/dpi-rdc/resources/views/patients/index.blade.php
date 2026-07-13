@extends('layouts.app')
@section('title', 'Patients')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Dossiers patients</h2>
        <a href="{{ route('patients.create') }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouveau patient
        </a>
    </div>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
            {{ session('success') }}
        </div>
    @endif

    <livewire:patients.patient-list />

</div>
@endsection