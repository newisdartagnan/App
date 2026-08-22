{{--
    La page servie quand le réseau est coupé.

    Elle ne porte aucun formulaire et ne nomme aucun patient : c'est la seule
    page de l'application que le service worker garde en réserve, et tout ce
    qui s'y trouve est visible par quiconque se met devant le poste.

    Elle ne dépend d'aucun fichier extérieur — ni feuille de style, ni image :
    hors connexion, un fichier manquant laisserait une page blanche.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e40af">
    <title>Pas de connexion — DPI-RDC</title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f8fafc; color: #1f2937; padding: 1.5rem;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .carte {
            background: #fff; border-radius: 1rem; max-width: 34rem; width: 100%;
            padding: 2.5rem 2rem; text-align: center; box-shadow: 0 4px 20px rgba(15, 23, 42, .08);
        }
        .pastille { font-size: 3rem; line-height: 1; }
        h1 { font-size: 1.5rem; margin: 1rem 0 .5rem; color: #1e40af; }
        p { line-height: 1.6; margin: .75rem 0; }
        .discret { color: #6b7280; font-size: .875rem; }
        ul { text-align: left; display: inline-block; margin: 1rem 0 0; padding-left: 1.25rem; line-height: 1.8; }
        .bouton {
            display: inline-block; margin-top: 1.5rem; background: #1d4ed8; color: #fff;
            text-decoration: none; font-weight: 600; padding: .75rem 1.75rem; border-radius: .5rem;
            min-height: 44px; line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="carte">
        <div class="pastille">📡</div>
        <h1>Pas de connexion au serveur</h1>

        <p>
            Le poste n'atteint plus le serveur de l'hôpital. Rien n'est perdu :
            les dossiers sont sur le serveur, pas sur ce poste.
        </p>

        <p class="discret">À vérifier, dans cet ordre :</p>
        <ul>
            <li>le câble réseau ou le Wi-Fi du poste ;</li>
            <li>l'onduleur et le serveur de la salle informatique ;</li>
            <li>le routeur, si tout le service est coupé.</li>
        </ul>

        <p class="discret" style="margin-top:1.5rem">
            Notez sur papier ce qui a été fait pendant la coupure : la saisie
            reprendra dès le retour du réseau.
        </p>

        <a class="bouton" href="{{ url('/') }}">Réessayer</a>
    </div>
</body>
</html>
