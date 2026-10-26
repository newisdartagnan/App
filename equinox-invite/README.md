# Équinoxe — invitation web personnalisée

Application d'invitation personnalisée par lien : carton animé, **QR code
d'entrée**, **assistant vocal**, **RSVP**, et diffusion des invitations par
**WhatsApp**.

Chaque invité reçoit un lien unique contenant un **jeton (token)**. Le lien
ouvre d'abord une page d'**aperçu Open Graph** personnalisée (joli aperçu au
partage), qui redirige vers la **SPA** affichant l'invitation.

```
WhatsApp (template approuvé)
   │  lien personnalisé
   ▼
Edge Function  invite-preview/<token>?app=<front>
   │  balises OG personnalisées + redirection
   ▼
SPA  /i/<token>
   │  get_invitation(token) ──► Supabase Postgres
   ├─ carton animé + QR code d'entrée
   ├─ RSVP  ──► set_rsvp(token, statut)
   ├─ téléchargement PDF (QR compris)
   └─ assistant vocal ──► Edge Function assistant ──► Claude
                                    ▲
WhatsApp entrant ──► whatsapp-webhook ──────────────┘
   (RSVP par mot-clé « OUI » / « NON », sinon réponse de l'assistant)
```

## Stack

| Couche       | Techno                                                       |
| ------------ | ------------------------------------------------------------ |
| Front-end    | React 18 · Vite · TypeScript · Tailwind CSS                  |
| Aperçu lien  | Supabase Edge Function (Deno) `invite-preview`               |
| Données      | Supabase Postgres — `invitations` + RPC verrouillées         |
| PDF          | `html2canvas` + `jspdf` (chargés à la demande)               |
| QR code      | `qrcode` (généré côté client, inclus dans le PDF)            |
| Assistant    | Claude (`claude-opus-5`) via Edge Function `assistant`       |
| Voix         | Web Speech API (synthèse + reconnaissance), 100 % navigateur |
| WhatsApp     | Meta Cloud API — `whatsapp-send` / `whatsapp-webhook`        |

## Démarrage rapide (mode démo, sans backend)

```bash
cd equinox-invite
npm install
npm run dev
```

<http://localhost:5173/?token=demo-boris> — ou `/i/demo-ana`.

Sans variables Supabase l'app tourne sur une invitation d'exemple :
le QR code, le PDF, le RSVP et l'assistant fonctionnent (l'assistant utilise
ses **réponses de repli locales** au lieu de Claude).

## Fonctionnalités

### QR code d'entrée
Généré côté client, il encode le lien personnalisé de l'invité
(`<APP_URL>/i/<token>`). Il est **inclus dans le PDF téléchargé**. Modules
sombres sur pastille ivoire : c'est le contraste qui rend un QR scannable.
Le check-in se fait via la fonction SQL `check_in(token)`, réservée au
`service_role` (jamais exposée à la clé anon).

### Assistant vocal
Panneau de conversation en bas de page. Il **parle** les réponses (synthèse
vocale) et accepte la **dictée** au micro là où le navigateur le permet ;
tout se dégrade proprement si l'API vocale est absente.

Côté serveur, l'Edge Function `assistant` interroge **Claude Opus 5** en lui
fournissant la fiche de l'invité, avec pour consigne stricte de ne rien
inventer. La clé API reste serveur — elle n'atteint jamais le navigateur.
Les réponses sont volontairement courtes (2–3 phrases) et l'effort est réglé
sur `low` pour la latence. Le repli automatique côté serveur
(`fallbacks: "default"`) est activé : si un refus de sécurité survient, la
requête est rejouée automatiquement sur un modèle de repli.

### RSVP
Boutons sur la page (`set_rsvp`), ou réponse « OUI » / « NON » /
« PEUT-ÊTRE » directement dans WhatsApp.

### Bot WhatsApp
- `whatsapp-send` — envoie les invitations via un **template approuvé**.
- `whatsapp-webhook` — reçoit les réponses : RSVP par mot-clé, sinon
  l'assistant répond.

> **Règles Meta, non contournables.** Initier une conversation exige un
> template approuvé ; le texte libre n'est autorisé que dans les **24 h**
> suivant un message de l'invité. `whatsapp-send` n'envoie qu'aux invités
> ayant donné leur **consentement** (`wa_opt_in = true`) : ce n'est pas un
> outil d'envoi en masse à des numéros non consentants.

## Brancher un vrai backend

1. Créer un projet sur <https://supabase.com>.
2. Appliquer le schéma :
   ```bash
   supabase link --project-ref <ref>
   supabase db push        # migrations 0001 + 0002
   ```
3. Front — copier `.env.example` en `.env` :
   ```
   VITE_SUPABASE_URL=https://<ref>.supabase.co
   VITE_SUPABASE_ANON_KEY=<anon key>
   ```
4. Déployer les fonctions :
   ```bash
   supabase functions deploy invite-preview   --no-verify-jwt
   supabase functions deploy whatsapp-webhook --no-verify-jwt
   supabase functions deploy whatsapp-send    --no-verify-jwt
   supabase functions deploy assistant
   ```
5. Renseigner les secrets (voir `.env.example` pour la liste complète) :
   ```bash
   supabase secrets set \
     APP_URL=https://<front> \
     ANTHROPIC_API_KEY=sk-ant-… \
     WA_PHONE_NUMBER_ID=… WA_ACCESS_TOKEN=… \
     WA_APP_SECRET=… WA_VERIFY_TOKEN=… \
     ADMIN_SECRET=…
   ```

### Configurer le webhook Meta
Dans l'app Meta → WhatsApp → Configuration :
- **URL de rappel** : `https://<ref>.supabase.co/functions/v1/whatsapp-webhook`
- **Token de vérification** : la valeur de `WA_VERIFY_TOKEN`
- S'abonner au champ **messages**

La signature `X-Hub-Signature-256` de chaque webhook est vérifiée en HMAC
sur le corps brut ; sans `WA_APP_SECRET` configuré, tout est **refusé**.

### Envoyer les invitations

```bash
curl -X POST https://<ref>.supabase.co/functions/v1/whatsapp-send \
  -H "x-admin-secret: <ADMIN_SECRET>" \
  -H "Content-Type: application/json" \
  -d '{"all": true}'          # ou {"tokens": ["demo-boris"]}
```

## Modèle de données

Table `public.invitations` — **fermée par RLS**, aucun accès direct. Les
seuls chemins de lecture/écriture sont des fonctions `SECURITY DEFINER` :

| Fonction                              | Ouverte à        | Rôle                          |
| ------------------------------------- | ---------------- | ----------------------------- |
| `get_invitation(token)`               | anon             | lit la ligne de ce token seul |
| `set_rsvp(token, statut, nb)`         | anon             | enregistre la réponse         |
| `check_in(token)`                     | service_role     | pointage à l'entrée (QR)      |

Ajouter un invité :

```sql
insert into public.invitations
  (token, honorific, guest_name, event_date, event_year, event_time,
   event_time_note, event_venue, event_venue_note, message, signature,
   phone, wa_opt_in)
values
  ('<token-unique>', 'M.', 'Prénom Nom',
   'Sam. 21 sept.', '2026', '18 h 30', 'Accueil dès 18 h',
   'Domaine du Lac', 'Plan à suivre',
   'Votre présence sera pour nous un honneur…', 'Avec toute notre reconnaissance',
   '+243812345678', true);
```

## Build de production

```bash
npm run build     # -> dist/
npm run preview
```

Déployable sur Netlify / Vercel / Cloudflare Pages. Pour le routage
`/i/<token>`, rediriger toutes les routes vers `index.html`
(ex. `netlify.toml` : `/* /index.html 200`).

## Notes

- **Téléchargement PDF** : nécessite l'app déployée ou lancée en local — les
  sandbox d'aperçu bloquent les téléchargements déclenchés par la page
  (repli `window.print()` prévu).
- **Voix** : la synthèse est largement supportée ; la reconnaissance vocale
  ne l'est pas partout (le bouton micro n'apparaît que si elle est
  disponible).
- **Image OG** : déposer un visuel 1200×630 et renseigner `OG_IMAGE_URL`.
