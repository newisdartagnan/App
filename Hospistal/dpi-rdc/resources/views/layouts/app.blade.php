<!DOCTYPE html>
<html lang="fr" data-theme="{{ \App\Http\Controllers\ApparenceController::theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1e40af">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    {{-- Le coupe-circuit du service worker, réglable dans le .env. --}}
    <meta name="sw-actif" content="{{ config('dpi.service_worker') ? '1' : '0' }}">
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

    <header class="bg-blue-800 text-white shadow dpi-sans-impression">
        {{-- Le bandeau s'enroule : son bloc de droite faisait 455 px de large
             et ne se réduisait jamais, si bien que sur un téléphone de 390 px
             il poussait toute la page de 134 px vers la droite. --}}
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="min-w-0">
                <h1 class="text-lg font-bold">DPI-RDC</h1>
                <p class="text-xs text-blue-200 truncate">{{ config('dpi.establishment_name') }}</p>
            </div>
            @auth
            {{-- On ne pouvait chercher un patient que depuis l'écran Patients :
                 il fallait quitter ce qu'on faisait, chercher, revenir. --}}
            <form method="GET" action="{{ route('recherche') }}" class="flex-1 max-w-md mx-4 hidden md:flex">
                <label for="q-entete" class="sr-only">Chercher un patient, une facture, un bon</label>
                <input id="q-entete" name="q" value="{{ request()->routeIs('recherche') ? request()->query('q') : '' }}"
                       placeholder="Nom, dossier, facture, bon d'examen…"
                       class="w-full rounded-l-lg border border-blue-900 px-3 py-1.5 text-sm text-gray-900">
                <button class="bg-blue-900 hover:bg-blue-950 border border-blue-900 rounded-r-lg px-3 text-sm">🔎</button>
            </form>
            <div class="flex items-center gap-2 sm:gap-4 min-w-0">
                @include('partials.sync-status')

                {{--
                    Qui est connecté, et par où l'on s'en va.
                    Un poste d'hôpital passe de main en main toute la journée :
                    sans cette ligne, l'infirmière de nuit signe encore sous le
                    nom du médecin de garde parti à six heures. Elle reste donc
                    sur téléphone — tronquée s'il le faut, jamais supprimée.
                --}}
                <div class="text-right leading-tight min-w-0 max-w-[9rem] sm:max-w-none">
                    <p class="text-sm font-semibold truncate">{{ auth()->user()->nom_complet }}</p>
                    <p class="text-xs text-blue-200 truncate">{{ auth()->user()->libelleRoles() }}</p>
                </div>
                <a href="{{ route('apparence.index') }}" title="Apparence — choisir un thème"
                   class="bg-blue-900 hover:bg-blue-950 text-white text-xs font-semibold rounded-lg px-3
                          inline-flex items-center justify-center min-h-[44px] min-w-[44px] shrink-0">
                    🎨
                </a>
                {{-- Sur téléphone les libellés tombent, les pictogrammes restent :
                     la cible garde ses 44 px, le bandeau tient sur une ligne. --}}
                <a href="{{ route('parcours.moi') }}" title="Mon temps d'utilisation"
                   class="bg-blue-900 hover:bg-blue-950 text-white text-xs font-semibold rounded-lg px-3
                          inline-flex items-center justify-center min-h-[44px] min-w-[44px] shrink-0">
                    ⏱️<span class="hidden sm:inline">&nbsp;Mon temps</span>
                </a>
                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button class="bg-blue-900 hover:bg-blue-950 text-white text-xs font-semibold rounded-lg px-3
                                   inline-flex items-center justify-center min-h-[44px] min-w-[44px]"
                            title="Fermer la session — le poste redevient disponible">
                        <span class="hidden sm:inline">Se déconnecter</span>
                        <span class="sm:hidden" aria-hidden="true">⏻</span>
                        <span class="sr-only sm:hidden">Se déconnecter</span>
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
        <div class="max-w-7xl mx-auto dpi-sans-impression">@include('partials._flash')</div>
        @endauth
        @yield('content')
    </main>

    @livewireScriptConfig
    @livewireScripts
</body>
</html>
