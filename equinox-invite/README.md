# Équinoxe — invitation web personnalisée

Reproduction complète et déployable de l'architecture d'un site d'invitation
personnalisé (type `equinox-invite-magic.lovable.app` + Edge Function
`invite-preview`).

Chaque invité reçoit un **lien unique contenant un jeton (token)**. Le lien
ouvre d'abord une page d'**aperçu Open Graph** personnalisée (joli aperçu
lors du partage WhatsApp / SMS / réseaux), qui redirige ensuite vers la
**SPA** affichant l'invitation animée et **téléchargeable en PDF**.

```
Lien partagé
   │
   ▼
Edge Function  ── invite-preview/<token>?app=<front>
   │  (balises OG personnalisées + redirection)
   ▼
SPA (React/Vite)  ── /i/<token>
   │  get_invitation(token)  →  Supabase (Postgres)
   ▼
Invitation animée + téléchargement PDF
```

## Stack

| Couche      | Techno                                                    |
| ----------- | --------------------------------------------------------- |
| Front-end   | React 18 · Vite · TypeScript · Tailwind CSS               |
| Aperçu lien | Supabase Edge Function (Deno) `invite-preview`            |
| Données     | Supabase Postgres — table `invitations` + RPC verrouillée |
| PDF         | `html2canvas` + `jspdf` (téléchargement réel côté client) |

## Démarrage rapide (mode démo, sans backend)

```bash
cd equinox-invite
npm install
npm run dev
```

Ouvre <http://localhost:5173>. Sans variables Supabase, l'app tourne avec
une **invitation d'exemple**. Le nom est personnalisable par l'URL :

- `http://localhost:5173/?token=demo-boris`
- `http://localhost:5173/i/demo-ana`
- `http://localhost:5173/?name=...` n'existe plus ici : la source de vérité
  est le token (comme en production).

## Brancher un vrai Supabase

1. Crée un projet sur <https://supabase.com>.
2. Applique le schéma :
   ```bash
   supabase link --project-ref <ref>
   supabase db push        # applique supabase/migrations/0001_init.sql
   ```
   (ou colle le SQL dans le SQL Editor).
3. Renseigne le front — copie `.env.example` en `.env` :
   ```
   VITE_SUPABASE_URL=https://<ref>.supabase.co
   VITE_SUPABASE_ANON_KEY=<anon key>
   ```
4. Déploie l'Edge Function :
   ```bash
   supabase functions deploy invite-preview --no-verify-jwt
   supabase secrets set APP_URL=https://<ton-front> OG_IMAGE_URL=https://<ton-front>/og.png
   ```

Le lien à envoyer aux invités ressemble alors à :

```
https://<ref>.supabase.co/functions/v1/invite-preview/<token>?app=https://<ton-front>&v=<timestamp>
```

## Modèle de données

Table `public.invitations` (voir `supabase/migrations/0001_init.sql`).
La table est **fermée par RLS** : aucun accès direct. La seule lecture
passe par la fonction `get_invitation(p_token)` (`SECURITY DEFINER`), qui ne
renvoie que la ligne du token demandé — la table complète n'est jamais
exposée à la clé anon.

Ajouter un invité :

```sql
insert into public.invitations
  (token, honorific, guest_name, event_date, event_year, event_time,
   event_time_note, event_venue, event_venue_note, message, signature)
values
  ('<token-unique>', 'M.', 'Prénom Nom',
   'Sam. 21 sept.', '2026', '18 h 30', 'Accueil dès 18 h',
   'Domaine du Lac', 'Plan à suivre',
   'Votre présence sera pour nous un honneur…', 'Avec toute notre reconnaissance');
```

## Build de production

```bash
npm run build     # -> dist/
npm run preview   # sert dist/ localement
```

Déployable tel quel sur Netlify / Vercel / Cloudflare Pages / GitHub Pages.
Pour le routage `/i/<token>` en SPA, rediriger toutes les routes vers
`index.html` (ex. `netlify.toml` : `/* /index.html 200`).

## Notes

- **Téléchargement PDF** : `html2canvas` rend la carte en image puis `jspdf`
  produit le fichier. Un vrai téléchargement nécessite l'app déployée
  (les sandbox d'aperçu bloquent les téléchargements déclenchés par la page —
  d'où le repli `window.print()`).
- **Image OG** : place un visuel 1200×630 (`public/og.png`) et renseigne
  `OG_IMAGE_URL` pour un aperçu riche au partage.
- **Sécurité** : la fonction n'accepte le paramètre `app` que s'il est en
  `https` et sur un hôte de confiance (anti open-redirect).
