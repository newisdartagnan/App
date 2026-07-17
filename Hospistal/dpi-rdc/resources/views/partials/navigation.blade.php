<nav class="bg-blue-900 text-white border-t border-blue-700" style="background-color:#1e3a8a;color:#fff;">
    <div class="max-w-7xl mx-auto px-4 flex flex-wrap gap-1 py-2 text-sm">
        <a href="{{ route('dashboard') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('dashboard') ? 'bg-blue-700 font-semibold' : '' }}">Accueil</a>
        <a href="{{ route('patients.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('patients.*') ? 'bg-blue-700 font-semibold' : '' }}">Patients</a>
        <a href="{{ route('consultations.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('consultations.*') ? 'bg-blue-700 font-semibold' : '' }}">Consultations</a>
        <a href="{{ route('visites.index', ['type' => 'urgence']) }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800">Urgences</a>
        <a href="{{ route('visites.index', ['type' => 'hospitalisation']) }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('visites.*') ? 'bg-blue-700 font-semibold' : '' }}">Hospitalisation</a>
        <a href="{{ route('labo.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('labo.*') ? 'bg-blue-700 font-semibold' : '' }}">Laboratoire</a>
        <a href="{{ route('imagerie.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('imagerie.*') ? 'bg-blue-700 font-semibold' : '' }}">Imagerie</a>
        <a href="{{ route('bloc.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('bloc.*') ? 'bg-blue-700 font-semibold' : '' }}">Bloc op.</a>
        <a href="{{ route('maternite.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('maternite.*') ? 'bg-blue-700 font-semibold' : '' }}">Maternité</a>
        <a href="{{ route('pharmacie.dashboard') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('pharmacie.*') ? 'bg-blue-700 font-semibold' : '' }}">Pharmacie</a>
        <a href="{{ route('caisse.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('caisse.*') ? 'bg-blue-700 font-semibold' : '' }}">Caisse</a>
        <a href="{{ route('equipements.index') }}" style="color:#fff;display:inline-block;padding:6px 12px;" class="px-3 py-1.5 rounded hover:bg-blue-800 {{ request()->routeIs('equipements.*') ? 'bg-blue-700 font-semibold' : '' }}">Équipements</a>
    </div>
</nav>
