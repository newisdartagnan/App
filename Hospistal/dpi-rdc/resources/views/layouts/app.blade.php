<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1e40af">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>@yield('title', 'DPI-RDC') — {{ config('dpi.establishment_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-white text-gray-900 min-h-screen">
    <div id="offline-banner" class="hidden bg-amber-500 text-white text-center py-2 text-sm font-medium">
        Mode hors ligne — les données seront synchronisées à la reconnexion
    </div>

    <header class="bg-blue-800 text-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-bold">DPI-RDC</h1>
                <p class="text-xs text-blue-200">{{ config('dpi.establishment_name') }}</p>
            </div>
            @auth
            <div class="flex items-center gap-4">
                @include('partials.sync-status')

                {{--
                    Qui est connecté, et par où l'on s'en va.
                    Un poste d'hôpital passe de main en main toute la journée :
                    sans cette ligne, l'infirmière de nuit signe encore sous le
                    nom du médecin de garde parti à six heures.
                --}}
                <div class="text-right leading-tight">
                    <p class="text-sm font-semibold">{{ auth()->user()->nom_complet }}</p>
                    <p class="text-xs text-blue-200">{{ auth()->user()->libelleRoles() }}</p>
                </div>
                <a href="{{ route('parcours.moi') }}" title="Mon temps d'utilisation"
                   class="bg-blue-900 hover:bg-blue-950 text-white text-xs font-semibold rounded-lg px-3 py-2">
                    ⏱️ Mon temps
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="bg-blue-900 hover:bg-blue-950 text-white text-xs font-semibold rounded-lg px-3 py-2"
                            title="Fermer la session — le poste redevient disponible">
                        Se déconnecter
                    </button>
                </form>
            </div>
            @endauth
        </div>
    </header>

    @auth
        @include('partials.navigation')
    @endauth

    <main class="max-w-7xl mx-auto px-4 py-6">
        @auth
        <div class="max-w-7xl mx-auto">@include('partials._flash')</div>
        @endauth
        @yield('content')
    </main>

    @livewireScriptConfig
    @livewireScripts
</body>
</html>
