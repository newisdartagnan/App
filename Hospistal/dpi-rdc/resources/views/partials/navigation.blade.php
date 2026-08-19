@php
    // Les routes labo.* servent les deux domaines (labo & imagerie) : le
    // surlignage du menu suit le domaine réel de l'examen affiché.
    $examenNav = request()->route('examen');
    $domaineNav = $examenNav?->domaine ?? request()->query('domaine');
    $navImagerie = request()->routeIs('imagerie.*') || $domaineNav === 'imagerie';
    $navLabo = ! $navImagerie && (request()->routeIs('labo.*') || $domaineNav === 'labo');

    $notifsNonLues = auth()->check()
        ? app(\App\Services\NotificationService::class)->nonLuesPour(auth()->user())
        : 0;
@endphp
<nav class="bg-blue-900 text-white border-t border-blue-700" style="background-color:#1e3a8a;color:#fff;">
    <div class="max-w-7xl mx-auto px-4 flex flex-wrap gap-1 py-2 text-sm">
        <a href="{{ route('dashboard') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('dashboard') ? 'bg-blue-700 font-semibold' : '' }}">Accueil</a>
        <a href="{{ route('patients.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('patients.*') ? 'bg-blue-700 font-semibold' : '' }}">Patients</a>
        <a href="{{ route('consultations.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('consultations.*') ? 'bg-blue-700 font-semibold' : '' }}">Consultations</a>
        <a href="{{ route('urgences.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('urgences.*') ? 'bg-blue-700 font-semibold' : '' }}">Urgences</a>
        <a href="{{ route('visites.index', ['type' => 'hospitalisation']) }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('visites.*') ? 'bg-blue-700 font-semibold' : '' }}">Hospitalisation</a>
        <a href="{{ route('services.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('services.*') ? 'bg-blue-700 font-semibold' : '' }}">Services</a>
        <a href="{{ route('labo.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ $navLabo ? 'bg-blue-700 font-semibold' : '' }}">Laboratoire</a>
        <a href="{{ route('imagerie.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ $navImagerie ? 'bg-blue-700 font-semibold' : '' }}">Imagerie</a>
        <a href="{{ route('bloc.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('bloc.*') ? 'bg-blue-700 font-semibold' : '' }}">Bloc op.</a>
        <a href="{{ route('maternite.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('maternite.*') ? 'bg-blue-700 font-semibold' : '' }}">Maternité</a>
        <a href="{{ route('pharmacie.dashboard') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('pharmacie.*') || request()->routeIs('officines.*') ? 'bg-blue-700 font-semibold' : '' }}">Pharmacie</a>
        <a href="{{ route('agenda.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('agenda.*') ? 'bg-blue-700 font-semibold' : '' }}">Agenda</a>
        <a href="{{ route('caisse.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('caisse.*') ? 'bg-blue-700 font-semibold' : '' }}">Caisse</a>
        <a href="{{ route('conventions.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('conventions.*') ? 'bg-blue-700 font-semibold' : '' }}">Conventions</a>
        <a href="{{ route('dialyse.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('dialyse.*') ? 'bg-blue-700 font-semibold' : '' }}">Dialyse</a>
        <a href="{{ route('diete.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('diete.*') ? 'bg-blue-700 font-semibold' : '' }}">Diète</a>
        <a href="{{ route('statistiques.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('statistiques.*') ? 'bg-blue-700 font-semibold' : '' }}">Statistiques</a>
        <a href="{{ route('equipements.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('equipements.*') ? 'bg-blue-700 font-semibold' : '' }}">Équipements</a>
        <a href="{{ route('notifications.index') }}" title="Notifications" style="color:#fff;display:inline-block;padding:6px 12px;margin-left:auto;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('notifications.*') ? 'bg-blue-700 font-semibold' : '' }}">
            🔔@if($notifsNonLues > 0)<span style="background:#dc2626;color:#fff;border-radius:9999px;padding:1px 6px;margin-left:4px;font-size:11px;font-weight:bold;">{{ $notifsNonLues }}</span>@endif
        </a>
    </div>
</nav>
