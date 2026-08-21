@php
    // Les routes labo.* servent les deux domaines (labo & imagerie) : le
    // surlignage du menu suit le domaine réel de l'examen affiché.
    $examenNav = request()->route('examen');
    $domaineNav = $examenNav?->domaine ?? request()->query('domaine');
    $navImagerie = request()->routeIs('imagerie.*') || $domaineNav === 'imagerie';
    $navLabo = ! $navImagerie && (request()->routeIs('labo.*') || $domaineNav === 'labo');

    // Une consultation reste une consultation, même si son URL commence par
    // /visites : le menu Hospitalisation ne doit pas s'allumer pour elle.
    $navConsultation = request()->routeIs('consultations.*')
        || request()->routeIs('visites.consulter')
        || request()->routeIs('visites.consultation.*')
        || request()->routeIs('prescriptions.*')
        || request()->routeIs('disponibilites.*');

    $navHospitalisation = ! $navConsultation && (
        request()->routeIs('visites.index')
        || request()->routeIs('visites.show')
        || request()->routeIs('visites.hospitaliser')
        || request()->routeIs('visites.sortir')
        || request()->routeIs('visites.facturer-sejour')
        || request()->routeIs('services.*')
        || request()->routeIs('infirmier.*')
        || request()->routeIs('mar.*')
        || request()->routeIs('bilan-hydrique.*')
        || request()->routeIs('diete.*')
    );

    $navPlateau = $navLabo || $navImagerie
        || request()->routeIs('bloc.*') || request()->routeIs('maternite.*')
        || request()->routeIs('dialyse.*') || request()->routeIs('examens-specialises.*')
        || request()->routeIs('banque-sang.*')
        || request()->routeIs('equipements.*');

    $navCaisse = request()->routeIs('caisse.*') || request()->routeIs('conventions.*')
        || request()->routeIs('acomptes.*') || request()->routeIs('forfaits.*');

    $notifsNonLues = auth()->check()
        ? app(\App\Services\NotificationService::class)->nonLuesPour(auth()->user())
        : 0;

    // Un groupe = un bouton de premier niveau + son volet déroulant.
    // Tout est en CSS (hover + focus-within) : aucun script, donc rien à
    // débloquer côté CSP sur les postes de l'hôpital.
    $groupes = [
        [
            'libelle' => 'Consultations',
            'actif' => $navConsultation,
            'liens' => [
                ['File d\'attente & consultations', route('consultations.index'), request()->routeIs('consultations.*')],
                ['Agenda des rendez-vous', route('agenda.index'), request()->routeIs('agenda.*')],
                ['Disponibilité des médecins', route('disponibilites.index'), request()->routeIs('disponibilites.*')],
            ],
        ],
        [
            'libelle' => 'Hospitalisation',
            'actif' => $navHospitalisation,
            'liens' => [
                ['Admissions & lits', route('visites.index', ['type' => 'hospitalisation']), request()->routeIs('visites.*')],
                ['Services d\'hospitalisation', route('services.index'), request()->routeIs('services.*')],
                ['Diète et ménage', route('diete.index'), request()->routeIs('diete.*')],
            ],
        ],
        [
            'libelle' => 'Plateau technique',
            'actif' => $navPlateau,
            'liens' => [
                ['Laboratoire', route('labo.index'), $navLabo],
                ['Imagerie', route('imagerie.index'), $navImagerie],
                ['Bloc — programme opératoire', route('bloc.programme'), request()->routeIs('bloc.programme')],
                ['Bloc — horaire des salles', route('bloc.horaire'), request()->routeIs('bloc.horaire')],
                ['Bloc — interventions à clôturer', route('bloc.interventions'), request()->routeIs('bloc.interventions')],
                ['Bloc — registre', route('bloc.registre'), request()->routeIs('bloc.registre')],
                ['Actes chirurgicaux', route('bloc.index'), request()->routeIs('bloc.index') || request()->routeIs('bloc.create')],
                ['Maternité — grossesses', route('maternite.index'), request()->routeIs('maternite.index') || request()->routeIs('maternite.show')],
                ['Maternité — registre des accouchements', route('maternite.registre'), request()->routeIs('maternite.registre')],
                ['Dialyse — calendrier', route('dialyse.index'), request()->routeIs('dialyse.index')],
                ['Dialyse — séances du jour', route('dialyse.seances'), request()->routeIs('dialyse.seances')],
                ['Dialyse — registre', route('dialyse.registre'), request()->routeIs('dialyse.registre')],
                ['Banque de sang', route('banque-sang.index'), request()->routeIs('banque-sang.*')],
                ['Équipements', route('equipements.index'), request()->routeIs('equipements.*')],
            ],
        ],
        [
            'libelle' => 'Caisse',
            'actif' => $navCaisse,
            'liens' => [
                ['Guichet & factures', route('caisse.index'), request()->routeIs('caisse.index') || request()->routeIs('caisse.show')],
                ['Acomptes de soins', route('acomptes.index'), request()->routeIs('acomptes.*')],
                ['Forfaits', route('forfaits.index'), request()->routeIs('forfaits.*')],
                ['Billetage de caisse', route('caisse.billetage'), request()->routeIs('caisse.billetage*')],
                ['Conventions & sociétés', route('conventions.index'), request()->routeIs('conventions.index')],
                ['Dettes à recouvrer', route('conventions.dettes'), request()->routeIs('conventions.dettes')],
            ],
        ],
    ];

@endphp
<nav class="dpi-nav" style="background-color:#1e3a8a;color:#fff;">
    <div class="dpi-nav-inner">
        <a href="{{ route('dashboard') }}" class="nav-lien {{ request()->routeIs('dashboard') ? 'est-actif' : '' }}">Accueil</a>
        <a href="{{ route('patients.index') }}" class="nav-lien {{ request()->routeIs('patients.*') ? 'est-actif' : '' }}">Patients</a>
        <a href="{{ route('urgences.index') }}" class="nav-lien {{ request()->routeIs('urgences.*') ? 'est-actif' : '' }}">Urgences</a>

        @foreach($groupes as $groupe)
        <div class="nav-groupe">
            <button type="button" class="nav-lien nav-bouton {{ $groupe['actif'] ? 'est-actif' : '' }}"
                    aria-haspopup="true" aria-expanded="false">
                {{ $groupe['libelle'] }}<span class="nav-chevron" aria-hidden="true">▾</span>
            </button>
            <div class="nav-volet">
                @foreach($groupe['liens'] as [$libelle, $url, $lienActif])
                <a href="{{ $url }}" class="nav-volet-lien {{ $lienActif ? 'est-actif' : '' }}">{{ $libelle }}</a>
                @endforeach
            </div>
        </div>
        @endforeach

        <a href="{{ route('pharmacie.dashboard') }}" class="nav-lien {{ request()->routeIs('pharmacie.*') || request()->routeIs('officines.*') ? 'est-actif' : '' }}">Pharmacie</a>
        <a href="{{ route('statistiques.index') }}" class="nav-lien {{ request()->routeIs('statistiques.*') ? 'est-actif' : '' }}">Statistiques</a>

        @if(auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'caissier']))
        <a href="{{ route('parametres.index') }}" title="Paramétrage"
           class="nav-lien {{ request()->routeIs('parametres.*') || request()->routeIs('utilisateurs.*') || request()->routeIs('assurances.*') || request()->routeIs('forfaits.*') ? 'est-actif' : '' }}">⚙️ Paramétrage</a>
        @endif

        <a href="{{ route('notifications.index') }}" title="Notifications"
           class="nav-lien nav-cloche {{ request()->routeIs('notifications.*') ? 'est-actif' : '' }}">
            🔔@if($notifsNonLues > 0)<span class="nav-pastille">{{ $notifsNonLues }}</span>@endif
        </a>
    </div>
</nav>
