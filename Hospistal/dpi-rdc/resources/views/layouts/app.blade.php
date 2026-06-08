<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e40af">
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
                <livewire:sync-status />
            @endauth
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        @yield('content')
    </main>

    @livewireScripts
</body>
</html>
