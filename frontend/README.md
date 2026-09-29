# SoxialConnect — Frontend V1

SPA React qui reproduit la maquette (`../Images/maquette.png`), avec des **données simulées**. Aucun backend n'est
connecté : toutes les actions (publication, OAuth, upload, IA, clipping) sont simulées côté navigateur.

## Démarrer

```bash
npm ci
npm run dev        # http://localhost:5173
npm run build      # vérification TypeScript + build de production dans dist/
npm run preview    # sert le build de production
npm run lint       # oxlint
```

Node 24 recommandé (constaté sur le poste de développement : Node 24.16, npm 11).

## Stack

React 19 · TypeScript 6 · Vite 8 (Rolldown) · Tailwind CSS 4 · shadcn/ui (Radix) · React Router 8 ·
TanStack Query 5 · React Hook Form 7 + Zod 4 · Recharts 3 · date-fns 4 · lucide-react · sonner.

## Organisation

```text
src/
├── assets/images/        # Visuel du hero (PNG d'origine + versions WebP 640/1024/1520)
├── components/
│   ├── ui/               # Composants shadcn/ui, restylés selon le design system
│   ├── brand/            # Logo, icônes officielles des plateformes (SVG)
│   ├── shared/           # PageHeader, StatCard, StatusBadge, états vide/erreur/chargement, FilterTabs…
│   ├── navigation/       # Barre latérale et entrées de menu
│   ├── landing/          # Navbar fixe, Hero, sections de la landing
│   ├── dashboard/  content/  social/  media/  video/  analytics/  settings/
├── layouts/              # AppLayout (barre latérale), AuthLayout (pages centrées)
├── pages/                # Une page par route (chargée à la demande)
├── routes/               # Routeur et déclarations paresseuses
├── hooks/                # Hooks TanStack Query (hooks/queries/*) et utilitaires
├── services/             # Couche d'accès aux données — contrats de la future API Laravel
├── mocks/                # Données fictives (dates relatives au jour courant)
├── types/                # Types métier alignés sur docs/database.md
└── lib/                  # Formatage, plateformes (capacités, limites), statuts, utilitaires
```

**Brancher Laravel plus tard** : les pages n'utilisent que les hooks de `hooks/queries`, qui n'appellent que `services/*`.
Il suffira de remplacer le corps des fonctions de `services/` par des appels HTTP (`/api/v1/...`, cookie Sanctum) et de
supprimer `services/mockStore.ts` et `mocks/`. Les types de `types/` restent les mêmes.

## Design system

Tous les tokens sont centralisés dans `src/index.css` (`@theme`) : couleurs de marque, sémantiques, bleu nuit de la
barre latérale, surfaces, couleurs des plateformes, ombres, polices (Inter, Caveat pour l'accroche). Aucune couleur
hexadécimale ne doit apparaître dans les composants. Page de référence : `/design-system`.

## Routes

| Route | Écran |
|---|---|
| `/` | Landing (navbar fixe, Connexion / Inscription toujours visibles) |
| `/login`, `/register`, `/forgot-password` | Authentification (simulée) |
| `/onboarding` | Connecter → Personnaliser → Bienvenue |
| `/dashboard` | Tableau de bord |
| `/content` | Publications (toutes, filtres, statuts) |
| `/content/create`, `/content/:postId/edit` | Composer |
| `/content/calendar` | Calendrier éditorial (mois, semaine, jour, liste) |
| `/content/drafts` | Brouillons |
| `/social-accounts` | Réseaux sociaux |
| `/media` | Bibliothèque média |
| `/video-studio` | Clipping vidéo (Video Studio) |
| `/analytics` | Analyses |
| `/ai` | Assistant IA |
| `/notifications` | Notifications |
| `/settings/:tab` | Paramètres (profile, account, security, notifications, accounts, preferences) |
| `/design-system` | Référence du design system |
| `/legal/:doc` | Pages légales (à rédiger) |

## Sécurité

Aucun secret, clé d'API, token ou mot de passe dans le code ou les mocks. Seules les variables `VITE_*` sont exposées
au navigateur (voir `.env.example`) ; elles ne doivent jamais contenir de secret.
