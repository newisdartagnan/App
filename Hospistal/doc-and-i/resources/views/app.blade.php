<!DOCTYPE html>
{{-- Thème choisi (clair, repos, sombre) : cookie de préférence d'affichage, lu ici pour
     éviter tout flash au chargement. Valeur hors liste = clair (rien d'autre n'est injecté). --}}
@php($theme = in_array(request()->cookie('medihome_theme'), ['clair', 'repos', 'sombre'], true) ? request()->cookie('medihome_theme') : 'clair')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0050b0">
    <title inertia>{{ config('app.name') }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/images/favicon-48.png" type="image/png" sizes="48x48">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="min-h-screen antialiased">
    @inertia
</body>
</html>
