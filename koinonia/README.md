# Koinonia (nom provisoire)

Plateforme des groupes de jeunes catholiques : chaque groupe est rattaché à une
paroisse et à un mouvement, présente son charisme et son apostolat, et gère sa
vie interne (agenda, enseignements, présences, étapes). Premier horizon : les
JMJ de Séoul, du 3 au 8 août 2027.

## Maquettes

- `maquettes/vitrine.html` : vitrine publique (accueil, mouvements, annuaire,
  JMJ, formules). Le fichier est écrit pour la visionneuse des artefacts Claude,
  qui ajoute elle-même `<!doctype html>`, `<head>` et `<body>`. En local, on
  peut aussi l'ouvrir directement dans un navigateur après avoir cloné le dépôt.

## Deux rattachements par groupe

- Axe ecclésial : Diocèse → Paroisse → Groupe.
- Axe du mouvement : Mouvement → Coordination → Groupe.

Le mouvement fournit le modèle (charisme, patrons, étapes, enseignements de
référence). Le groupe en hérite et le complète avec sa vie paroissiale.

## Rôles

| Niveau     | Administré par                                   | Rôle dans l'application |
|------------|--------------------------------------------------|-------------------------|
| Mouvement  | Coordination nationale ou diocésaine             | Rédige le modèle, confirme l'appartenance des groupes |
| Paroisse   | Aumônier de la paroisse et coordinateurs         | Valide les groupes, nomme responsables et aumôniers de groupe, calendrier commun |
| Groupe     | Aumônier du groupe, responsables, formateurs     | Agenda, enseignements, présences, étapes, cotisations |
| Membre     | Le jeune, avec un parent pour les mineurs        | Consulte, suit les enseignements, signale ses absences |

Une même personne peut cumuler plusieurs rôles.

## Ordre des modèles de mouvement

1. JEFAC (Jeunesse Famille Chrétienne)
2. K.A (Kizito-Anuarite)
3. BYM (Bilenge ya Mwinda)

Plus un modèle « groupe libre » pour les groupes sans mouvement modélisé. Le
charisme, l'apostolat et les étapes de chaque mouvement restent à rédiger avec
sa coordination.
