@extends('layouts.app')
@section('title', 'Patients')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Dossiers patients</h2>
        @can('patient.create')
        <a href="{{ route('patients.create') }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouveau patient
        </a>
        @endcan
    </div>
    <livewire:patients.patient-list />
</div>
@endsection