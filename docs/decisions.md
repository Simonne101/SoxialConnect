# Journal des décisions (ADR)

Statuts : **Accepted** (imposé par le brief ou validé par le porteur de projet) · **Proposed** (recommandation de
l'architecte, à valider) · **Open** (décision en attente) · **Superseded**.
Format : Decision / Context / Reason / Consequences / Status / Date.

---

### ADR-001 — Monolithe modulaire + traitements asynchrones
- **Decision** : une application Laravel unique, domaines séparés dans `app/Domain/*`, workers de queue pour tout traitement lourd ou externe.
- **Reason** : la V1 ne justifie pas la complexité des microservices (déploiement, observabilité, cohérence des données).
- **Consequences** : frontières de domaines strictes (pas d'écriture croisée) ; extraction possible plus tard (par exemple du worker vidéo).
- **Status** : Accepted (brief) — 2026-09-28

### ADR-002 — Monorepo `backend/` + `frontend/`, SPA découplée
- **Decision** : deux applications dans un dépôt ; API Laravel JSON pure ; SPA React (Vite) servie sur le même site.
- **Reason** : la stack imposée (React, Vite, TanStack Query) correspond à une SPA ; un seul dépôt garde la documentation, la CI et les versions synchronisées.
- **Alternative écartée** : Inertia.js ou le kit React de Laravel (couplage plus fort, s'éloigne de la stack demandée).
- **Status** : Proposed — 2026-09-28

### ADR-003 — MySQL comme SGBD
- **Decision** : **MySQL**. En dev, MySQL 8.0.30 fourni par Laragon ; en production, MySQL 8.4 LTS.
- **Context** : l'architecte recommandait PostgreSQL (index partiels, `NULLS NOT DISTINCT`, jsonb). Le porteur de projet a demandé MySQL de Laragon.
- **Consequences** : émulation des index uniques partiels et nullables par colonnes générées `VIRTUAL` (database.md §1.1) ;
  `DATETIME` pour les dates métier ; `utf8mb4` obligatoire ; MySQL 8.0 arrive en fin de support → tests de compatibilité avec 8.4 en CI.
- **Status** : Accepted (porteur de projet) — 2026-09-28

### ADR-004 — UUID v7 comme clé primaire et identifiant public
- **Decision** : `HasUuids` de Laravel (UUID ordonnés) comme PK sur toutes les tables métier, stockés en `char(36)`.
- **Reason** : le brief exige des UUID publics ; une PK unique évite la double identification (id interne + uuid) ; l'ordre temporel limite la fragmentation InnoDB.
- **Consequences** : index plus volumineux qu'un `BIGINT` (acceptable à l'échelle V1) ; option `binary(16)` réévaluable si le volume l'exige.
- **Status** : Proposed — 2026-09-28

### ADR-005 — Authentification SPA par session (Sanctum) + Fortify
- **Decision** : cookie de session HttpOnly, Secure, SameSite + CSRF Sanctum ; Fortify pour les flux (vérification email, reset, 2FA).
- **Reason** : aucun token dans le navigateur (pas d'exposition XSS du localStorage) ; 2FA prête ; paquets officiels Laravel.
- **Consequences** : frontend et API sur le même site ; configuration stricte de `SANCTUM_STATEFUL_DOMAINS` et de CORS.
- **Status** : Proposed — 2026-09-28

### ADR-006 — Adaptateurs sociaux et matrice de capacités déclarative
- **Decision** : interface `SocialNetworkAdapter`, une classe par plateforme ; capacités déclarées dans le code avec un statut (`supported`, `requires_review`, `to_verify`…) et exposées au frontend.
- **Reason** : les plateformes n'ont pas les mêmes capacités ; aucune supposition n'est permise ; APIs officielles uniquement.
- **Status** : Accepted (brief) — 2026-09-28

### ADR-007 — V1 non-SaaS, propriété par `user_id`, prête pour les workspaces
- **Decision** : pas de tables de plans, d'abonnements ni de facturation. Propriété via `user_id` sur les tables racines, accès uniquement via Policies + scope `ownedBy()`.
- **Consequences** : l'ajout futur de `workspace_id` ne touche que les scopes et les Policies (database.md §1).
- **Status** : Accepted (brief) — 2026-09-28

### ADR-008 — Chiffrement des tokens OAuth par clé dédiée versionnée
- **Decision** : cast `EncryptedToken` avec un `Encrypter` AES-256-GCM et une clé `TOKEN_ENCRYPTION_KEY` distincte d'`APP_KEY`, plus `encryption_key_version`.
- **Reason** : rotation indépendante ; la compromission ou la rotation d'`APP_KEY` (sessions) ne doit pas rendre les tokens illisibles, ni l'inverse.
- **Status** : Proposed — 2026-09-28

### ADR-009 — Redis + queues séparées + Horizon (production)
- **Decision** : 7 queues (`publishing`, `media`, `video`, `ai`, `analytics`, `webhooks`, `notifications`), Horizon en production.
- **Consequences** : Horizon nécessite Linux ; en dev sous Laragon, `queue:work` sans application des timeouts.
- **Status** : Accepted (brief) pour les queues ; Proposed pour Horizon — 2026-09-28

### ADR-010 — Programmation interne uniforme en V1
- **Decision** : le scheduler Laravel déclenche toutes les publications, même quand la plateforme offre une programmation native.
- **Reason** : comportement homogène, annulation et modification simples, un seul chemin de code et de tests.
- **Consequences** : l'infrastructure doit être disponible à l'heure prévue (supervision du scheduler) ; la programmation native reste une option future.
- **Status** : Proposed — 2026-09-28

### ADR-011 — IA indépendante du fournisseur
- **Decision** : `AIServiceInterface` + fournisseurs interchangeables par configuration et par tâche ; DTO neutres ; prompts versionnés.
- **Open** : choix du fournisseur initial et du service de transcription.
- **Status** : Accepted (brief) ; fournisseur Open — 2026-09-28

### ADR-012 — Exposition des médias aux plateformes par URL signée courte
- **Decision** : pour les APIs qui récupèrent le média depuis une URL (Instagram, Threads, Pinterest, TikTok pull), génération d'une URL signée ≤ 1 h pour ce seul objet.
- **Reason** : garder le stockage privé tout en respectant ces APIs.
- **Consequences** : domaine de diffusion dédié (vérification de domaine TikTok) ; durée à ajuster aux délais de traitement constatés.
- **Status** : Proposed — 2026-09-28

### ADR-013 — Import vidéo par URL restreint
- **Decision** : seules les URLs directes vers un fichier dont l'utilisateur détient les droits sont acceptées ; **pas de téléchargement depuis YouTube, TikTok, etc.** (outils de type yt-dlp interdits).
- **Reason** : les conditions d'utilisation des plateformes l'interdisent (contournement = API non officielle) ; risque de droits d'auteur ; surface SSRF.
- **Status** : Proposed — 2026-09-28

### ADR-014 — Environnement de développement : Laragon (+ Docker ou WSL2 ciblé)
- **Decision** : Laragon (PHP 8.3.9, MySQL 8.0.30, Redis 5.0.14) pour le développement quotidien. Docker ou WSL2 pour ce que Windows ne couvre pas : ClamAV, worker FFmpeg isolé, Horizon, MinIO, tests de timeouts.
- **Consequences** : écarts dev/prod (Windows, Redis 5 sans ACL, MySQL 8.0) couverts par un environnement de staging Linux et par la CI Linux.
- **Status** : Accepted (MySQL Laragon, porteur de projet) / Proposed (le reste) — 2026-09-28

### ADR-015 — Nom du produit
- **Context** : la maquette dit « SocialPulse », le dossier « Sociopulse ».
- **Consequences** : conditionne les domaines, les noms d'applications OAuth (visibles dans les écrans de consentement) et les pages légales. À trancher avant la phase 3.
- **Status** : Open — 2026-09-28

### ADR-016 — Connexion sociale (Google / Facebook) hors V1
- **Decision** : ne pas implémenter « Continuer avec Google / Facebook » en V1, malgré la maquette.
- **Reason** : absent du brief ; ajoute une surface OAuth et des cas de fusion de comptes ; la connexion d'un compte Facebook pour publier n'implique pas une connexion à SocialPulse.
- **Status** : Proposed — 2026-09-28

### ADR-017 — Métriques absentes = NULL, jamais 0
- **Decision** : les colonnes de métriques normalisées sont nullables ; NULL = non fourni par la plateforme. Le taux d'engagement est calculé à la lecture.
- **Status** : Proposed — 2026-09-28

### ADR-018 — `.env.example` à la racine jusqu'au bootstrap
- **Decision** : le référentiel des variables vit à la racine en phase 0, puis est scindé en `backend/.env.example` et `frontend/.env.example` en phase 1.
- **Reason** : `composer create-project` exige un dossier cible vide ; ne pas créer `backend/` prématurément.
- **Status** : Accepted — 2026-09-28

### ADR-019 — Table `social_connections` (autorisation OAuth parente)
- **Context** : une autorisation OAuth peut ouvrir plusieurs comptes publiables (pages Facebook, chaînes). Le brief ne prévoit pas de table pour cette autorisation.
- **Options** : A = nouvelle table `social_connections` (recommandée) ; B = `social_tokens.token_kind` sans table supplémentaire.
- **Status** : Open — à trancher au début de la phase 3 — 2026-09-28

### ADR-020 — Programmation dans le fuseau de l'utilisateur, stockage UTC
- **Decision** : stockage UTC ; le fuseau IANA de l'utilisateur (et du post) sert à la saisie et à l'affichage ; les changements d'heure sont gérés par date-fns-tz côté client et Carbon côté serveur.
- **Status** : Proposed — 2026-09-28

---

## Phase 1 — Frontend V1 (2026-09-28)

### ADR-021 — Frontend d'abord, avec couche de données simulée
- **Decision** : construire toute l'interface avant le backend. Les pages consomment uniquement des hooks TanStack Query (`src/hooks/queries`) qui appellent `src/services/*` ; ces services utilisent un stockage en mémoire (`services/mockStore.ts`) alimenté par `src/mocks/*`.
- **Reason** : valider l'UX sans backend, puis brancher Laravel en ne modifiant que `services/` (mêmes contrats, mêmes types `src/types`, alignés sur database.md).
- **Status** : Accepted (porteur de projet) — 2026-09-28

### ADR-022 — Couleur primaire ajustée pour l'accessibilité AA
- **Decision** : `--color-brand-500` = `#5B5EED` au lieu de `#6366F1` (maquette). Texte blanc : 4,46:1 → 4,92:1. Bouton destructif : `#DC2626` au lieu de `#EF4444` (3,76:1 → 4,83:1).
- **Reason** : `#6366F1` échoue de peu au critère WCAG 1.4.3 (4,5:1). L'écart visuel est minime ; la teinte réellement rendue sur la maquette (`#5C6AF6`) est d'ailleurs plus proche de la nouvelle valeur.
- **Status** : Proposed (à valider par le porteur de projet / designer) — 2026-09-28

### ADR-023 — Routes applicatives
- **Decision** : routes du brief phase 1 (`/dashboard`, `/content`, `/content/create`, `/content/calendar`, `/content/drafts`, `/social-accounts`, `/media`, `/video-studio`, `/analytics`, `/ai`, `/notifications`, `/settings/:tab`) au lieu du préfixe `/app/*` proposé dans ux-ui.md. `/app` redirige vers `/dashboard`.
- **Status** : Accepted — 2026-09-28

### ADR-024 — Versions de l'outillage frontend
- **Decision** : versions stables courantes au moment du bootstrap : React 19.2, TypeScript 6.0, Vite 8 (Rolldown), Tailwind CSS 4, React Router 8, TanStack Query 5, Zod 4, React Hook Form 7, Recharts 3, shadcn/ui (style `radix-nova`, composants copiés dans `src/components/ui` et restylés).
- **Consequences** : bibliothèques React/Router et données regroupées en chunks vendor ; chaque page est un chunk chargé à la demande.
- **Status** : Accepted — 2026-09-28

### ADR-025 — Onglets-filtres accessibles
- **Decision** : les onglets utilisés comme filtres (statuts, types de média, vues du calendrier) sont des groupes de boutons `aria-pressed` (`FilterTabs`), et la navigation des paramètres utilise des liens (`NavTabs`, `aria-current`). Les onglets Radix sont réservés aux cas avec panneaux (Composer, Analytics, Video Studio).
- **Reason** : `aria-controls` invalide quand les onglets Radix n'ont pas de panneau (audit axe).
- **Status** : Accepted — 2026-09-28

### ADR-026 — Logos des plateformes
- **Decision** : tracés SVG issus de `simple-icons` (CC0) intégrés dans `src/components/brand/brand-paths.ts`, sans dépendance. LinkedIn (retiré de simple-icons) utilise le tracé historique.
- **Open** : vérifier la conformité aux chartes d'usage des marques de chaque plateforme avant la mise en production.
- **Status** : Accepted (technique) / Open (juridique) — 2026-09-28

### ADR-027 — Écrans d'authentification fixes (100dvh)
- **Decision** : `/login`, `/register` et `/forgot-password` utilisent `AuthSplitLayout` : grille pleine hauteur (`h-dvh`), formulaire à gauche, panneau visuel à droite (≥ 1024 px, visuel du hero + accroche). En dessous de 1024 px : une seule colonne, panneau visuel masqué. `/onboarding` garde `AuthLayout`.
- **Reason** : pas de défilement global de la page (demande du porteur de projet). Vérifié à 390×844, 768×1024, 1024×768, 1280×800 et 1440×900, y compris avec les messages d'erreur affichés.
- **Compactage selon la hauteur** : variantes Tailwind `short` (≤ 800 px) et `tiny` (≤ 700 px) qui resserrent espacements, champs (44 → 40 px), boutons et titre ; inscription sur deux colonnes dès 640 px de large. Aucun défilement, ni global ni interne, vérifié sur 12 tailles d'écran (de 360×640 à 1920×940, dont 1366×625 et 1536×730), avec et sans messages d'erreur.
- **Consequences** : en dessous d'environ 600 px de hauteur utile (zoom très fort, clavier mobile ouvert), seul le panneau du formulaire défile ; aucun contenu n'est masqué.
- **Status** : Accepted — 2026-09-29

---

## Phase 2 — Socle backend (2026-09-29)

### ADR-028 — Champs de profil « headline » et « bio » sur users
- **Decision** : ajout de `users.headline` (varchar 60, « Activité ») et `users.bio` (varchar 280, « Présentation »), nullables.
- **Reason** : ces champs figurent dans le frontend validé (Paramètres › Profil, barre latérale) mais pas dans le schéma initial.
- **Status** : Accepted — 2026-09-29

### ADR-029 — Fortify en mode API seule, routes sous /api/v1/auth
- **Decision** : `Fortify::ignoreRoutes()` ; les contrôleurs Fortify sont exposés sous `/api/v1/auth/*` avec nos limites (`login`, `auth-sensitive`). Réponses JSON au format de l'API. Seul le lien de vérification d'e-mail est une route web (`/email/verify/{id}/{hash}`), car il est ouvert depuis l'e-mail.
- **Details** : réponse « mot de passe oublié » identique que le compte existe ou non ; connexion refusée pour un compte suspendu avec le message générique ; double authentification désactivée (colonnes prêtes, phase 11) ; passkeys non installées.
- **Status** : Accepted — 2026-09-29

### ADR-030 — Tests sur une base MySQL dédiée
- **Decision** : `phpunit.xml` utilise la base `sociopulse_test` (MySQL), pas SQLite.
- **Reason** : les contraintes propres à MySQL (CHECK, colonnes générées, index multi-valués, FULLTEXT) doivent être réellement testées. Argon2id reste utilisé avec un coût réduit en test.
- **Status** : Accepted — 2026-09-29

### ADR-031 — Rôles et permissions en V1
- **Decision** : autorisation par propriété (Policies + scope `ownedBy`, refus en 404) et une Gate `admin` basée sur `ADMIN_EMAILS`. Pas de table de rôles en V1.
- **Reason** : V1 mono-utilisateur non-SaaS (ADR-007) ; les rôles et permissions arriveront avec les workspaces.
- **Status** : Accepted — 2026-09-29

### ADR-032 — Développement local : `php artisan serve` et OPcache
- **Context** : sous Windows, le serveur intégré de PHP traite une requête à la fois ; sans OPcache (non chargé dans `C:\Program Files\php\php.ini`), chaque requête prend 0,6 à 4 s.
- **Recommendation** : activer OPcache dans le php.ini local (`zend_extension=opcache`, `opcache.enable_cli=1`) ou servir l'API via l'Nginx/Apache de Laragon (PHP-CGI) sur `localhost:8000`. Aucun impact sur la production (PHP-FPM + OPcache).
- **Status** : Open (décision du porteur de projet : modification de la configuration PHP locale) — 2026-09-29

### ADR-033 — Médiathèque V1 : pipeline d'upload, stockage abstrait, antivirus absent
- **Decision** : upload via l'API (`POST /api/v1/media`, multipart) ; contrôle par magic bytes (finfo) avec liste blanche et cohérence d'extension (`config/media.php`) ; stockage privé sur le disque `media` via `MediaStorage` (clés `users/{user}/media/{media}/{variante}.{ext}`, jamais le nom d'origine) ; lecture par URL signée courte (route `api.v1.media.file`, 15 min) ou URL présignée du fournisseur si le disque la supporte (S3/MinIO) ; images réencodées par GD (EXIF/GPS supprimés) avec vignette WebP 480 px ; traitement synchrone par défaut, `ProcessMediaJob` (queue `media`) si `MEDIA_PROCESS_ASYNC=true` ; suppression logique puis purge physique (`media:purge-deleted`, 30 jours).
- **Antivirus** : abstraction `MediaSecurityScanner` ; aucun moteur installé (`NullMediaScanner`) → les médias restent `scan_status = pending` (« Non analysé »), jamais présentés comme sains. `MEDIA_SCAN_REQUIRED=true` (défaut en production) refuse les imports tant qu'aucun moteur n'est disponible (échec fermé, 503). Règle d'usage unique : `ScanPolicy` (publication, lecture des fichiers).
- **Tags** : colonne JSON indexée `media.tags` conformément à database.md §3.4 (pas de table de liaison) ; normalisés en minuscules.
- **Vidéo / audio** : sans FFmpeg, ni vignette ni durée (aucune miniature simulée) ; champs prêts pour la phase Video.
- **Status** : Accepted — 2026-09-29

### ADR-034 — Suppression des dossiers de médias côté application (pas de cascade MySQL)
- **Context** : en local (MySQL 8.0.30, Laragon), une suppression de dossier par cascade (`media_folders.parent_id` ON DELETE CASCADE, `media.folder_id` ON DELETE SET NULL) a arrêté le serveur MySQL. Les tables concernées ont des index sur colonnes générées virtuelles (`media_folders.parent_key`, index multi-valué de `media.tags`).
- **Decision** : `ManageFolders::delete()` remet d'abord les médias de la branche à la racine, puis supprime les dossiers du plus profond au plus haut : aucune action référentielle n'est déclenchée. Les contraintes restent en place (filet de sécurité).
- **Risque ouvert** : la suppression d'un compte utilisateur déclenche les mêmes cascades (`users` → `media`, `media_folders`). À traiter avec la fonctionnalité de suppression de compte (purge applicative ordonnée, ou mise à jour de MySQL au-delà de 8.0.30 après vérification).
- **Status** : Accepted — 2026-09-29

### ADR-035 — Développement local : GD et limites d'upload
- **Context** : le PHP système (`C:\Program Files\php\php.ini`) n'active pas GD et limite l'upload à 2 Mo ; sa modification demande les droits administrateur.
- **Recommendation** : dans ce php.ini (en administrateur) : `extension=gd`, `extension=exif`, `upload_max_filesize = 100M`, `post_max_size = 110M`, `memory_limit = 256M`. Sans GD, l'application fonctionne (pas de réencodage ni de vignette, signalé dans les métadonnées) et les tests dépendant de GD sont ignorés.
- **Status** : Open (décision du porteur de projet) — 2026-09-29

### ADR-036 — Content : variantes préservées à la modification, suppressions ordonnées
- **Context** : `SavePost` supprimait puis recréait toutes les variantes et tous les liens médias à chaque enregistrement : identifiants changés, historique `publication_attempts` perdu (cascade), et cascade vers `post_media` (colonne générée virtuelle indexée, cf. ADR-034).
- **Decision** : synchronisation sans recréation. Appariement par (compte social, format), puis par compte seul si le format change : la variante est mise à jour sur place et garde son identifiant. Seules les cibles retirées sont supprimées, via `OrderedDeletion::variants()` (médias propres à la variante → tentatives → variante), sans action référentielle. Les liens médias communs sont ajoutés, retirés ou réordonnés par requêtes ciblées (jamais de `save()` complet sur `post_media`, qui tenterait d'écrire `post_variant_key`). Changer de cycle (brouillon ↔ programmation) efface l'erreur courante de la variante ; l'historique reste dans les tentatives. Deux cibles identiques (compte + format) sont refusées en 422.
- **Limite** : la suppression définitive d'un post ou d'un compte utilisateur passerait encore par des cascades ; aucune fonctionnalité ne le fait aujourd'hui (posts : suppression logique). À traiter avec la purge et la suppression de compte.
- **Status** : Accepted — 2026-09-29

### ADR-037 — File d'attente : retry_after et after_commit
- **Decision** : connexions `redis` et `database` : `retry_after = 360` s (supérieur au timeout le plus long, `ProcessMediaJob` = 300 s ; sinon un job encore en cours est redonné à un autre worker et exécuté deux fois) et `after_commit = true` (un job envoyé dans une transaction n'est poussé qu'après sa validation). Surchargeables par `REDIS_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_AFTER_COMMIT` (et `DB_QUEUE_*`). Un test (`QueueSafetyTest`) vérifie la règle pour chaque job déclaré.
- **Exploitation** : les workers gardent le code chargé : `php artisan queue:restart` après chaque changement de code ou de configuration.
- **Status** : Accepted — 2026-09-29

### ADR-038 — Moteur de publication interne : variante, jeton de tentative, appel hors transaction
- **Context** : publier (maintenant, à l'heure prévue, en relance) sans double envoi, sans variante bloquée et sans succès inventé, avant toute intégration OAuth.
- **Decision** : un service unique (`PublishingService`) réutilisé par l'API, le job, le planificateur et une future CLI. Unité de publication : la variante ; statut du post agrégé. Transitions conditionnelles (`VariantStateMachine`) ; `post_variants.attempts_count` sert de jeton aux jobs (un job périmé ou dupliqué ne fait rien) ; verrous toujours post puis variante ; une ligne `publication_attempts` par envoi, jamais écrasée ; adaptateur appelé hors transaction. Job `$tries = 1`, `$timeout = 120`, `$failOnTimeout` : les nouveaux essais (erreurs temporaires uniquement, backoff 60/300/900 s, 3 au maximum) sont décidés par le moteur, jamais par la file. Résultat inconnu (exception, worker tué) : échec explicite, relance soumise à confirmation. Détails : [publishing.md](publishing.md).
- **Status** : Accepted — 2026-09-30

### ADR-039 — Adaptateur par défaut NotConfiguredPublisher
- **Decision** : `config/publishing.php` → `adapters` est vide. Toute plateforme sans adaptateur déclaré utilise `NotConfiguredPublisher` : aucun appel réseau, aucun token lu, résultat `NOT_CONFIGURED` (non relançable automatiquement), message « Rien n'a été publié ». Le frontend active « Publier maintenant » et « Réessayer », et la démonstration hors API ne simule plus de succès : elle reproduit ce même échec.
- **Conséquence** : aucun post ne peut atteindre `published` tant qu'un adaptateur officiel n'est pas livré (phase Social Connectors).
- **Status** : Accepted — 2026-09-30

### ADR-040 — Planificateur : SKIP LOCKED et reprise après incident
- **Decision** : `publishing:dispatch-due` chaque minute (`withoutOverlapping`, `onOneServer`) ; sélection des posts dus par lots `FOR UPDATE SKIP LOCKED` ; reprise des variantes bloquées en `publishing` (→ échec au résultat inconnu) ou en `queued` sans job (→ job renvoyé, déclencheur `recovery`). En local, `npm run dev` lance `schedule:work` et un worker `queue:work redis --queue=publishing,default,media`. Hors lanceur sous Windows : tâche planifiée `schedule:run` chaque minute + worker sous gestionnaire de services.
- **Status** : Accepted — 2026-09-30

### ADR-041 — YouTube : premier connecteur réel
- **Decision** :
  - OAuth Google côté serveur (authorization code + PKCE S256).
  - `state` aléatoire à usage unique (10 min), stocké en cache sous son empreinte et lié à l'utilisateur authentifié.
  - Connexion lancée par `POST /api/v1/social/youtube/connect` : session et CSRF de la SPA, réponse = URL Google.
  - Callback sur une **route web** `/oauth/youtube/callback` : la session y est disponible ; en local, relayée par Vite comme `/api`.
  - Tokens dans `social_tokens`, avec le chiffrement existant (ADR-008).
  - Pas de table `social_connections` : option B de l'ADR-019, déjà reflétée par le schéma. Une autorisation Google = une chaîne.
  - `YouTubePublisher` implémente `SocialPublisher` et est déclaré dans `config/publishing.php`.
  - `PublishRequest` reçoit un champ optionnel `title` ; le contrat `SocialPublisher` est inchangé.
  - Upload reprenable, lu en flux depuis le stockage privé. `privacyStatus` forcé à `private` pendant cette phase (remplacé par ADR-065 : visibilité choisie dans le Composer).
  - Timeout du job porté à 300 s (inférieur à `retry_after`).
- **Status** : Accepted — 2026-09-30 (test réel en attente des identifiants Google)

### ADR-042 — Fondations IA et Video Studio (phase 1)
- **Context** : l'IA et le Video Studio sont simulés côté frontend ; le schéma vidéo existant ne couvre que le clipping.
- **Decision** :
  - `video_projects` reçoit un type `kind` : `composition` (studio par scènes, par défaut) ou `clipping` (existant ; les lignes déjà présentes passent en `clipping`). Statuts et source dépendent du type (CHECK).
  - Nouvelles tables `video_scenes`, `video_tracks`, `video_render_jobs`. Les visuels et l'audio référencent `media` (pas de seconde médiathèque, RESTRICT).
  - Isolation en base quand elle est exprimable : FK composite rendu → (projet, propriétaire). Sinon, règles applicatives documentées (database.md §3.6).
  - Un seul rendu actif par projet (colonne générée unique) ; une piste au plus par type, soit 2 pistes.
  - `ai_generations` : tâche `adapt`, colonnes `cost_estimate_micros` et `input_hash` (suivi d'usage et cache, sans facturation).
  - `config/ai.php` : fournisseur et modèle par tâche depuis `.env`, budget, prix, cache. `config/video.php` : limites, formats, binaires FFmpeg, dossier temporaire.
  - Files : `ai` sur la connexion `redis` ; `video` sur une connexion dédiée `redis-video` (`retry_after` 1 900 s, supérieur au rendu maximal de 1 800 s). Workers optionnels : `npm run dev -- --workers=ai,video`.
- **Non implémenté** : fournisseurs IA, services, routes, jobs, rendu FFmpeg, interfaces. FFmpeg et FFprobe ne sont pas installés.
- **Status** : Accepted — 2026-10-06

### ADR-043 — OpenAI, fournisseur IA officiel (phase 1.5)
- **Context** : ADR-011 laissait le choix du fournisseur ouvert ; la phase 1 a préparé `config/ai.php` sans fournisseur.
- **Decision** :
  - SoxialConnect utilise **OpenAI** comme fournisseur IA officiel (`AI_DEFAULT_PROVIDER=openai`).
  - L'intégration utilise le **client HTTP Laravel existant** plutôt qu'un SDK OpenAI supplémentaire : aucune nouvelle dépendance, même modèle que les connecteurs LinkedIn, X et TikTok, tests avec `Http::fake`.
  - API « Responses » (`POST /v1/responses`), recommandée par OpenAI pour les nouvelles intégrations ; `store=false` (OpenAI ne conserve pas les réponses).
  - Architecture : `AiService` (`app/Domain/AI/AiService.php`) → contrat `AiProvider` (`app/Domain/AI/Contracts`) → `OpenAiProvider` (`app/Domain/AI/Providers`) → client HTTP Laravel. URL, authentification, format de requête et de réponse et codes d'erreur d'OpenAI restent dans `OpenAiProvider` ; le reste de l'application ne voit que `AiTextRequest`, `AiTextResult` et `AiProviderException`.
  - Clé `OPENAI_API_KEY` **uniquement côté backend**, dans `.env` : jamais dans Git, le frontend, les journaux, les messages d'erreur (le message brut d'OpenAI, qui peut citer une partie de la clé, n'est jamais repris) ni les résultats.
  - Modèle : `AI_DEFAULT_MODEL`, ou `AI_MODEL_<TÂCHE>` ; aucun modèle définitif imposé.
  - Test réel séparé des tests automatisés : `php artisan ai:check` (requête de 16 tokens au plus, aucune écriture en base). Sans clé : « OPENAI_API_KEY non configurée. Test réel non exécuté. » Sans `AI_DEFAULT_MODEL`, modèle de test `gpt-6-luna` (le plus économique de la liste officielle au 2026-10-06), réservé à ce test.
  - **FFmpeg et FFprobe** : requis uniquement pour la phase Rendu ; **non installés** pendant cette phase. Installation manuelle requise avant la phase Rendu (chemins dans `VIDEO_FFMPEG_BINARY` et `VIDEO_FFPROBE_BINARY`).
- **Non implémenté** : générations métier (idées, contenu, adaptation, script), enregistrement dans `ai_generations`, file `ai`, interfaces.
- **Status** : Accepted — 2026-10-06

### ADR-044 — Video Studio : timeline et API (phase 2)
- **Context** : la phase 1 a préparé le schéma ; l'éditeur doit gérer des pistes de tous types et des éléments placés dans le temps, sans seconde médiathèque.
- **Decision** :
  - **Modèle** : projet (format, résolution, cadence) → scènes (segments narratifs ordonnés) ; → assets (médias de la médiathèque rattachés, aucune copie) ; → pistes de timeline (`video`, `image`, `text`, `audio`, `overlay`, ordonnées en calques) → éléments (temps en **millisecondes** entières, `start_ms` / `duration_ms`, extrait `source_*`, propriétés en liste blanche) ; → rendus.
  - `video_tracks` évolue (table vide, migration réversible) : de « piste audio, une par type » (ADR-042) à « piste de timeline » ; le média, l'extrait et le volume passent dans les éléments. Une seconde table de pistes aurait introduit deux notions concurrentes.
  - Pas de renommage destructif : `title` (nom), `settings` (métadonnées), `finished_at`, statuts `rendering` / `succeeded` conservés.
  - Statuts de projet : `draft`, `rendering`, `ready`, `failed`. Rendus : `queued`, `rendering`, `succeeded`, `failed`, `cancelled`.
  - **Cohérence** : toute écriture passe par `VideoProjectLock` (verrou du projet + `lock_version`) dans une transaction ; services `VideoProjectService`, `VideoSceneService`, `VideoAssetService`, `VideoTimelineService`, `VideoRenderService`. `duration_ms` du projet = fin du dernier élément.
  - **Isolation** : politiques (404 pour la ressource d'un autre compte), FK composites (assets, rendus), contrôles de projet pour chaque référence (asset, scène, piste).
  - **Médiathèque** : seuls les médias utilisables du propriétaire sont rattachés ; un média utilisé par un rendu actif ne peut pas être supprimé (`ManageMedia::isInUse`) ; un média supprimé reste signalé indisponible.
  - **API** (`/api/v1/video`, adresse vérifiée) :
    - projets : `GET/POST /projects`, `GET/PATCH/DELETE /projects/{project}` ;
    - scènes : `GET/POST /projects/{project}/scenes`, `PATCH/DELETE /scenes/{scene}` ;
    - pistes : `GET/POST /projects/{project}/tracks`, `PATCH/DELETE /tracks/{track}` ;
    - timeline : `GET /projects/{project}/timeline`, `POST /tracks/{track}/items`, `PATCH/DELETE /timeline-items/{item}` (un seul PATCH déplace, redimensionne ou modifie) ;
    - médias du projet : `GET/POST /projects/{project}/assets`, `DELETE /assets/{asset}` ;
    - rendus : `GET/POST /projects/{project}/renders`, `GET /renders/{render}`, `POST /renders/{render}/cancel`.
  - **Rendu préparé, non implémenté** : la demande fige la timeline (`spec`, `soxial.render_spec.v1`, identifiants de médias seulement ; `spec_hash` sur la forme canonique) et pousse `RenderVideoJob` sur la file `video` (connexion `redis-video`). Le job termine le rendu en échec explicite : `RENDERER_UNAVAILABLE` (FFmpeg absent, détecté par `FfmpegBinary`) ou `RENDERER_NOT_IMPLEMENTED`. Aucun fichier n'est produit ni simulé.
  - **Frontend** : `VideoStudioPage` (projets réels) et `VideoProjectPage` (scènes, médias, aperçu indicatif, timeline, inspecteur, rendus). L'ancienne simulation de découpage n'est plus affichée ; ses fichiers restent en place, inutilisés.
- **État** : Phase 2 — timeline + API opérationnelles. Rendu : préparé mais non implémenté (FFmpeg non installé).
- **Status** : Accepted — 2026-10-06

### ADR-045 — Moteur de rendu vidéo FFmpeg (phase 3)
- **Context** : la phase 2 préparait les rendus sans produire de fichier ; FFmpeg 9.0.2 et FFprobe sont désormais installés (Windows, chemin absolu configuré).
- **Decision** :
  - **Pipeline** : `POST /renders` fige la timeline (`spec`, empreinte canonique) ; `RenderVideoJob` (file `video`, connexion `redis-video`, 1 essai, timeout 1 800 s < `retry_after` 1 900 s) passe le rendu `queued → rendering` sous verrou, puis `FfmpegVideoRenderer` : sources revérifiées côté serveur (propriétaire du rendu, rattachées au projet, utilisables, fichier lisible), analyse FFprobe (flux attendus, extrait dans la durée), commande construite par `FfmpegCommandBuilder`, exécution dans un dossier isolé (`video.temp_path/{render}`), validation FFprobe du MP4 (MP4, H.264, AAC si son, dimensions, durée ± max(250 ms, 2 images)), vignette ; `VideoRenderService::complete` publie le MP4 comme **média normal** de la médiathèque (analyse antivirus comme un envoi, stockage privé, URL signée) puis `succeeded`.
  - **Sécurité FFmpeg** : arguments en tableau (Symfony Process, aucun shell) ; entrées limitées au protocole `file` ; textes des utilisateurs dans des fichiers lus par `drawtext` (`textfile`, `expansion=none`) ; graphe de filtres lu depuis un fichier (`-/filter_complex`) ; fichiers annexes en chemins relatifs au dossier de travail. Aucun chemin local ni sortie brute de FFmpeg exposés (journal serveur seulement).
  - **Rendu** : fond noir W×H ; pistes de l'arrière-plan au premier plan ; image/vidéo mises à l'échelle (`cover` = recadrage, `contain` = bandes), fondus d'entrée/sortie ; texte (`drawtext` : taille, couleur, position, alignement, ombre) ; sons (extrait, volume, décalage, mixage `amix`) ; sortie H.264 yuv420p + AAC 48 kHz, `+faststart`.
  - **Progression** : indicative, lue sur `-progress` (temps encodé / durée), plafonnée à 99 % avant validation.
  - **Annulation réelle** : vérifiée chaque seconde pendant l'encodage ; FFmpeg est arrêté (arbre de processus) ; rien n'est publié (contrôle sous verrou avant publication) ; dossier de travail supprimé.
  - **Idempotence** : un job dupliqué ou relancé ne reprend jamais un rendu déjà pris ; une timeline identique (même empreinte) déjà rendue avec succès renvoie la vidéo existante sans recalcul.
  - **Nettoyage** : dossier de travail supprimé dans tous les cas ; `video:purge-temp` (planifiée) rattrape les dossiers abandonnés.
- **Limites** : pas de retour à la ligne automatique des textes, pas d'animation ni de transition entre plans au-delà des fondus, pas de fondu sur les textes, polices non choisies par l'utilisateur, posts longs > 90 s non pris en charge.
- **Status** : Accepted — 2026-10-06

### ADR-046 — AI Creative Studio : YouTube Clipper (≤ 60 s) et génération d'images
- **Context** : la création de contenu doit offrir trois flux distincts — composition vidéo (Video Studio, ADR-044/045, inchangé), clips courts tirés d'une vidéo YouTube, images générées par IA — sans second moteur vidéo ni seconde médiathèque.
- **Decision** :
  - **Interface** : `/studio` (AI Creative Studio) mène au Video Studio existant, au Clipper (`/studio/clipper`) et aux images (`/studio/images`).
  - **Source des clips** : aucune API officielle ne fournit le flux vidéo d'un lien YouTube, et les outils de téléchargement contournent les protections de YouTube. Le lien est donc **validé** (https, domaines officiels, identifiant de 11 caractères, forme canonique reconstruite) et **vérifié** par l'endpoint public oEmbed (titre, chaîne ; vidéo introuvable ou privée refusée), mais le fichier découpé est une **vidéo de la médiathèque de l'utilisateur** (par exemple exportée depuis YouTube Studio). Rien n'est téléchargé depuis YouTube.
  - **Analyse** (`AnalyzeClipSourceJob`, file `video` / connexion `redis-video`) : FFprobe (vidéo lisible, durée), audio extrait par FFmpeg (mono 16 kHz, AAC 24 kbit/s), transcription horodatée (`AiProvider::transcribe`, modèle `AI_MODEL_TRANSCRIPTION`), choix des passages par le modèle de texte (`video_analysis`). Chaque proposition est **revalidée côté serveur** : bornée à 60 s, dans la durée de la source, chevauchements écartés. Sans IA configurée, en cas d'erreur du fournisseur ou sans parole, l'analyse échoue proprement mais la durée est connue : l'utilisateur choisit le passage lui-même.
  - **Limite de 60 s** (`ClipLimits`, non configurable) vérifiée par la validation HTTP, le service, le job avant FFmpeg (depuis la base, jamais depuis la requête), FFprobe sur le fichier produit (au-delà : `OUTPUT_TOO_LONG`, rien n'est publié) et la base (CHECK `end_ms - start_ms <= 60000`).
  - **Rendu des clips** (`RenderClipJob`) : le moteur existant. `FfmpegVideoRenderer::produce` reçoit une spécification `soxial.render_spec.v1` à un seul élément vidéo (recadrage `cover` au format 9:16, 1:1 ou 16:9, son d'origine). Publication par `RenderedVideoPublisher`, extrait sans changement de `VideoRenderService::complete` et commun aux deux flux : antivirus, média vidéo normal, stockage privé, URL signée, métadonnées `source = youtube_clipper`.
  - **Images** (`GenerateAiImageJob`, file `ai`) : `AiProvider::generateImage` (modèle `AI_MODEL_IMAGE`, taille carrée, portrait ou paysage), recadrage centré au ratio exact (1:1, 4:5, 9:16, 16:9) par GD, puis import par `UploadMedia` — le chemin normal de la médiathèque (contrôles, antivirus, stockage, traitement, vignettes). Métadonnées : fournisseur, modèle, format, dimensions réellement générées. Suivi dans `ai_generations` (tâche `image`).
  - **Modèles spécialisés** : `transcription` et `image` ne reprennent jamais `AI_DEFAULT_MODEL` (modèle de texte).
  - **Accès** : routes protégées (session, e-mail vérifié, limiteurs `ai` et `publish`) ; projet, clip ou génération d'un autre compte → 404 ; un projet de composition n'est pas visible par les routes du Clipper.
- **Limites** : analyse dépendante d'un fournisseur IA payant (compte OpenAI sans crédits au 2026-10-06 : tests réels de transcription et d'images impossibles, chaîne vérifiée avec des réponses simulées) ; pas de sous-titres incrustés ; pas de recadrage intelligent (centre de l'image) ; sources analysées limitées à `CLIPPER_MAX_SOURCE_MINUTES` ; images recadrées depuis les tailles du fournisseur.
- **Status** : Accepted — 2026-10-06

### ADR-047 — Creative Studio utilisable sans IA payante : transcription locale, analyse locale, sous-titres
- **Context** : le compte OpenAI n'a pas de crédits ; le Clipper doit rester pleinement utilisable sans API payante, l'IA externe devenant optionnelle. Poste de référence : i7-6600U (2 cœurs / 4 threads), 7,9 Go de RAM.
- **Decision** :
  - **Indispensable, sans API** : vidéo source → FFprobe → passage (manuel ou proposé) ≤ 60 s → FFmpeg (moteur unique) → FFprobe → médiathèque. Une analyse sans IA se termine en `ready` (et non `failed`) ; `failed` est réservé à une source inutilisable (`SOURCE_MISSING`, `SOURCE_INVALID`, `SOURCE_TOO_SHORT`) ou à l'absence de FFmpeg.
  - **Transcription interchangeable** (`Transcriber`) : `WhisperCppTranscriber` (local, `whisper-cli` + modèle ggml, WAV 16 kHz, aucun réseau) et `AiTranscriber` (fournisseur configuré). Choix `CLIPPER_TRANSCRIPTION=auto|local|ai|none` (auto = local s'il est installé, puis IA, puis aucune). Installation locale validée : whisper.cpp v1.9.4 (`whisper-bin-x64.zip`, 8,6 Mo) + `ggml-base.bin` (148 Mo, SHA-256 vérifiée), dans `storage/app/whisper` (hors dépôt) ; 61 s de parole française transcrites en 36 s sur le poste de référence.
  - **Analyse interchangeable** (`ClipAnalyzer`) : `AiClipAnalyzer` (TranscriptAnalyzer existant) et `LocalClipAnalyzer` (heuristique déterministe, **présentée comme « sans IA »**) : fenêtres de segments notées sur les phrases complètes, la continuité de la parole, le débit, l'accroche et la durée ; sans transcription, découpage sur les pauses (FFmpeg `silencedetect`) ; jamais de passage inventé sans structure mesurable. `CLIPPER_ANALYSIS=auto|ai|local|none` ; en `auto`, une IA en échec (quota, réseau) bascule sur l'analyse locale. Source des passages : `ai`, `auto` (analyse locale), `manual`.
  - **Sous-titres** : piste texte de la spécification de rendu, construite depuis la transcription (recalée, coupée en lignes et en blocs de deux lignes), dessinée par `drawtext` avec fond semi-transparent (propriété interne `box`, refusée par le Video Studio). Options : position (bas, centre, haut) et taille (petits, moyens, grands). Refusés (422) sans transcription.
  - **Étapes de rendu visibles** (`render_stage`) : préparation → rendu → validation → publication ; « Clip prêt » seulement après validation FFprobe et publication.
  - **Fournisseur par tâche** : `AiService::providerFor()` (`AI_TRANSCRIPTION_PROVIDER`, `AI_IMAGE_PROVIDER`), sans changer le contrat `AiProvider`.
  - **Remotion** : absent du projet ; non introduit. FFmpeg (drawtext, libass) couvre les besoins actuels (sous-titres, texte) ; Remotion ne serait envisagé que pour des modèles graphiques animés.
  - **Génération vidéo par IA** (prompt → vidéo) : fonctionnalité future, nécessitant un fournisseur spécialisé ; non implémentée et jamais simulée par FFmpeg.
  - **Génération d'images** : reste dépendante d'un fournisseur IA et présentée comme telle.
- **Status** : Accepted — 2026-10-07

### ADR-048 — Clipper URL-first, fournisseur Gemini, routage IA par tâche et par capacité
- **Context** : le Clipper demandait d'emblée une vidéo de la médiathèque, contradictoire avec un parcours « coller un lien YouTube ». Or aucune API officielle de YouTube ne fournit les octets d'une vidéo, et les outils de téléchargement contournent ses protections : FFmpeg ne peut pas découper une vidéo à partir d'un simple lien.
- **Decision** :
  - **Deux notions séparées** : *YouTubeSource* (lien canonique, identifiant, titre, chaîne, miniature filtrée sur `i.ytimg.com`, via l'endpoint public oEmbed) et *RenderableMediaSource* (`source_media_id` : vidéo de la médiathèque de l'utilisateur, seule matière découpable). Le projet est créé avec le lien seul (`POST /clipper/lookup` pour l'aperçu, `media_id` facultatif) ; la source rendable est jointe à tout moment (`PUT /clipper/projects/{id}/source`) et n'est exigée que pour le rendu (`409 SOURCE_REQUIRED`).
  - **Analyse depuis le lien** : avec un fournisseur ayant la capacité `VideoUrl` (Gemini — l'API Interactions accepte une URL YouTube publique, fonctionnalité en préversion de Google, 8 h/jour en offre gratuite), les passages sont proposés sans fichier. Leur durée réelle est inconnue : ils sont revalidés quand la source est jointe (FFprobe), ceux qui dépassent sa durée sont écartés. Sans fournisseur compatible : fin propre, note explicite, analyse locale dès la jonction de la source.
  - **Gemini** (`GeminiProvider`, API Interactions, en-tête `x-goog-api-key`) : texte (dont vidéo par URL, sortie JSON par schéma), images (format natif 1:1, 4:5, 9:16, 16:9). **Transcription non prise en charge** : la documentation ne garantit pas des horodatages de segments fiables ; whisper.cpp local et OpenAI `whisper-1` restent les moteurs de transcription.
  - **Capacités** (`AiCapability` : Text, Transcription, Image, VideoUrl) vérifiées avant tout appel (`AiProvider::supports`, `AiService::supportsFor`) ; OpenAI refuse une URL vidéo sans appel (`UNSUPPORTED_INPUT`).
  - **Routage par tâche** : `AI_VIDEO_ANALYSIS_PROVIDER`, `AI_TRANSCRIPTION_PROVIDER`, `AI_IMAGE_PROVIDER` (sinon `AI_DEFAULT_PROVIDER`) ; modèles Gemini propres (`GEMINI_MODEL_ANALYSIS`, `GEMINI_MODEL_IMAGE`), jamais un modèle d'un autre fournisseur.
  - **Erreurs normalisées** (`QUOTA_EXCEEDED`, `RATE_LIMITED`, `AUTHENTICATION_FAILED`, `MODEL_NOT_FOUND`, `INVALID_REQUEST`, `TIMEOUT`…) ; messages des fournisseurs jamais repris ; un quota épuisé n'interrompt pas le Clipper (repli local ou passage manuel).
  - **Diagnostic** : `php artisan ai:check --tasks` (configuration par tâche, aucun appel réseau) ; `--live` ajoute un appel texte minimal, jamais d'appel de transcription ni d'image.
- **Limites** : la correspondance entre le lien et le fichier joint n'est pas vérifiable automatiquement (responsabilité de l'utilisateur) ; l'analyse par URL dépend d'une fonctionnalité Google en préversion et des vidéos publiques ; Gemini non testé en réel (aucune clé configurée au 2026-10-07).
- **Validation réelle (2026-10-07)** : clé et réseau valides ; la requête exacte du Clipper (vidéo par URL + schéma JSON) a renvoyé deux réponses complètes et valides avec `gemini-3.5-flash` ; mais plusieurs modèles Flash répondaient ensuite HTTP 503 « high demand » (surcharge passagère côté Google), 429 (quota du projet) ou pas du tout (`gemini-3.8-flash`, > 150 s). D'où : **jusqu'à deux nouvelles tentatives sur 503 uniquement** (3 s puis 8 s ; jamais sur quota, 429 ou délai dépassé) ; `ai:check --live` affiche si la réponse est complète (les modèles « thinking » consomment le budget de 16 tokens). Aucun modèle n'est choisi par défaut : `GEMINI_MODEL_ANALYSIS` doit être renseigné avec un modèle de la liste du compte.
- **Status** : Accepted — 2026-10-07

### ADR-049 — Clipper URL-first : acquisition de source autorisée, recalage, aperçu YouTube
- **Context** : objectif produit « je colle un lien YouTube, SoxialConnect me sort des clips prêts à publier ». Audit (2026-10-07) : aucune API officielle de YouTube ne fournit les octets d'une vidéo (Developer Policies §III.E.1.a, conditions d'utilisation de YouTube) ; seule la Google Data Portability API exporte officiellement les vidéos **du propriétaire** (EEE, Royaume-Uni, Suisse ; scopes restreints soumis à l'approbation de Google et à une évaluation CASA). L'analyse depuis le lien (Gemini) est possible ; le rendu exige un fichier.
- **Decision** :
  - **Référence ≠ fichier** : nouvelle table `video_sources` (une par projet) — référence YouTube, propriété (`owned` / `not_owned` / `unverified`, vérifiée par videos.list avec l'OAuth YouTube déjà connecté), voie d'acquisition, statut (`pending → resolving → waiting_for_provider → ready | failed | unavailable | expired`), fichier (`media_id`), purge.
  - **Abstraction `VideoSourceProvider`** (begin / poll → `AcquisitionStep` : waiting, acquired, unavailable, failed) et registre ordonné `video.sources.providers`. Le Clipper ne dépend d'aucun fournisseur. **`DataPortabilityVideoSourceProvider`** : client conforme à la référence REST v1 (`portabilityArchive:initiate`, `archiveJobs/*/portabilityArchiveState`), URLs signées limitées à `storage.googleapis.com`, sans redirection ; **désactivé** (`DATA_PORTABILITY_ENABLED=false`) ; sans consentement → `CONSENT_REQUIRED` ; archive prête → `ARCHIVE_FORMAT_UNVERIFIED` tant que l'extraction de LA vidéo n'est pas validée sur un export réel. Un fournisseur externe sous contrat s'ajoute en implémentant l'interface (aucun n'est branché, aucune API tierce inventée).
  - **`AcquireVideoSourceJob`** (file `video`) : une étape par exécution ; attente d'un fournisseur par jobs différés bornés (`max_attempts`), jamais d'attente bloquante ; fichier obtenu importé par `UploadMedia` (inspection, antivirus), taille plafonnée (`CLIPPER_SOURCE_MAX_BYTES`), durée contrôlée (`max_source_minutes`), conservé `CLIPPER_SOURCE_RETENTION_DAYS` puis purgé (`clipper:expire-sources`, jamais un import de l'utilisateur ni un fichier en cours d'utilisation). Aucun fournisseur : `unavailable` + `PROVIDER_NOT_CONFIGURED` — jamais de faux succès.
  - **Source prête → pipeline automatique** (`ClipperService::linkSource`) : FFprobe, transcription (whisper.cpp, désormais `-ojf` : mots horodatés reconstitués octet par octet, accents coupés entre tokens compris), puis **recalage** (`ClipRecalibrator`) des passages proposés depuis le lien : bornes dans la durée réelle, début / fin calés sur les phrases (sinon segments, sinon mots), jamais au milieu d'un mot, ≤ 60 s, doublons supprimés. Les horodatages de l'IA ne sont jamais définitifs. Si aucun passage n'existe encore, Gemini est sollicité au moment où la source devient disponible.
  - **Relance** (`POST clipper/projects/{id}/reanalyze`) : même projet, seuls les passages proposés sont remplacés (jamais un clip généré ou manuel), transcription réutilisée tant que la source est la même (`settings.transcript_media_id`). **Relance d'acquisition** (`POST …/source/acquire`) sans doublon.
  - **Import autorisé** (`PUT …/source`) conservé comme **finalisation** quand aucune acquisition automatique n'aboutit ; retiré de la page d'entrée.
  - **Frontend** : lecteur YouTube intégré (youtube-nocookie, `start` / `end`) pour l'**aperçu du passage** — jamais présenté comme un MP4 ; étapes réelles du parcours ; état de la source ; « Générer le clip » actif seulement quand la source est prête ; sélection multiple ; Télécharger / Publier / Planifier (composer et scheduler existants).
  - **Gemini** : nouvelles tentatives bornées sur 503 uniquement (ADR-048) ; oEmbed ne suit plus aucune redirection.
- **Limites** : sans fournisseur activé, l'acquisition automatique n'existe pas encore : URL → analyse → propositions → aperçu est fonctionnel ; la génération des MP4 attend une source autorisée (fournisseur à activer, ou import en finalisation). Cadrage : recadrage centré conservé (aucune vision par ordinateur introduite) ; la position du sujet fournie par Gemini n'est pas exploitée faute de validation réelle.
- **Status** : Accepted — 2026-10-07

### ADR-050 — OpusClip comme fournisseur externe de clipping (URL YouTube → clips)
- **Context** : l'acquisition automatique de la source (ADR-049) n'a aucun fournisseur actif. Audit de l'API OpusClip le 2026-10-07 sur sa spécification OpenAPI publiée (help.opus.pro/api-reference/openapi.json) : `POST /api/clip-projects` accepte une **URL YouTube publique** (`videoUrl`) ; `GET /api/clip-projects/{id}` expose `stage` (PENDING → … → COMPLETE | STALLED) et `error` ; `GET /api/exportable-clips?q=findByProjectId&projectId=…` renvoie les clips (`uriForExport`, `durationMs`, `timeRanges`, `title`, `description`, `keywords`). Authentification `Authorization: Bearer <clé>` (+ `x-opus-org-id` facultatif). Limites : plans Pro (Beta) / Max / Business, 1 crédit = 1 minute, 10 crédits minimum par projet, 30 requêtes/min, 4 projets simultanés (429 `X-Cap-Reason: concurrent`), plafond mensuel 900 crédits → 403 `API_MONTHLY_CAP_REACHED`, vidéo ≤ 10 h / 30 Go, projets expirés après 30 jours. **Point juridique** : l'énumération `sourcePlatform` contient `YTDLP_LINK` (OpusClip récupère lui-même les vidéos) ; ses conditions d'utilisation laissent les droits sur le contenu à l'utilisateur et limitent l'usage « au bénéfice de tiers » — un usage multi-utilisateurs dans SoxialConnect relève d'un accord commercial avec OpusClip.
- **Decision** :
  - **`OpusClipVideoSourceProvider`** branché derrière `VideoSourceProvider` (aucune refonte du Clipper), **désactivé par défaut** (`OPUSCLIP_ENABLED=false`, clé `OPUSCLIP_API_KEY` lue depuis `.env` uniquement). Le contrat gagne `deliversClips()` et `downloadClip()` ; `AcquisitionStep::clips()` porte les clips livrés.
  - **Création idempotente** : identifiant du projet OpusClip noté sur la source ; marqueur posé avant l'appel → une création interrompue n'est jamais rejouée (`OPUSCLIP_STATE_UNKNOWN`, relance manuelle). Reprise / relance : le projet existant est suivi ; un projet `COMPLETE` n'est jamais refacturé (réimport seulement).
  - **Asynchrone** : `AcquireVideoSourceJob` (file `video` existante) interroge le projet toutes les `OPUSCLIP_POLL_SECONDS` (polling borné). **Pas de webhook** : le format du corps n'est pas documenté et un serveur local n'est pas joignable (la signature HMAC `X-Opus-Signature` est documentée, à brancher en production).
  - **Paramètres** : `clipDurations` limité aux plages documentées ≤ 60 s (`[0,30]`, `[30,60]`) ; `layoutAspectRatio` dérivé du format du projet (portrait / square / landscape).
  - **Import** : chaque clip est téléchargé côté serveur **sans la clé**, en HTTPS, depuis les seuls domaines `OPUSCLIP_DOWNLOAD_HOSTS` (exemple documenté `ext.cdn.opus.pro`), sans redirection, en flux plafonné (`OPUSCLIP_MAX_CLIP_BYTES`) ; validé par FFprobe via le moteur existant (`FfmpegVideoRenderer::adopt` : MP4, piste vidéo, **≤ 60 s**) ; publié par `RenderedVideoPublisher` (antivirus, stockage privé, vignette) ; rattaché au projet comme clip `external` prêt. Jamais deux fois (identifiants externes notés), clips rejetés jamais retéléchargés, fichiers temporaires supprimés.
  - **Gemini** : quand OpusClip prend la vidéo en charge, l'analyse Gemini n'est pas lancée (sélection en double et payante) ; elle reste le repli si OpusClip échoue ou n'est pas activé.
  - Erreurs normalisées (`OPUSCLIP_AUTH_FAILED`, `OPUSCLIP_QUOTA_EXCEEDED`, `OPUSCLIP_FORBIDDEN`, `OPUSCLIP_RATE_LIMITED`, `OPUSCLIP_BUSY`, `OPUSCLIP_SOURCE_REJECTED`, `OPUSCLIP_PROCESSING_FAILED`, `OPUSCLIP_NO_CLIPS`, `OPUSCLIP_DOWNLOAD_*`, `OPUSCLIP_TIMEOUT`…), jamais le corps brut ni la clé. Téléchargement, publication et programmation : services existants.
- **Limites** : non testé en réel (aucune clé OpusClip). L'expiration de `uriForExport` n'est pas documentée (un lien expiré déclenche un nouveau passage avec des liens frais). L'unité de `timeRanges` est incohérente dans la documentation (secondes annoncées, millisecondes dans l'exemple) : déduite de la durée. Aucun score de clip n'est exposé par l'API.
- **Status** : Superseded par l'ADR-051 — 2026-10-11 (intégration retirée du code : jamais activée ni testée en réel ; la valeur SQL `external` de `video_clips.source` reste autorisée, sans usage)


### ADR-051 — Moteur d'auto-clipping natif : Clip Intelligence Engine, planificateur, annulation, Remotion
- **Context** : SoxialConnect doit produire ses clips lui-même, sans OpusClip (ADR-050 remplacée), à partir du lien YouTube. Décisions validées le 2026-10-11 : aucun téléchargeur YouTube (yt-dlp exclu) — le rendu exige une source vidéo AUTORISÉE (VideoSourceProvider, ADR-049), le lien sert à identifier, analyser et prévisualiser ; transcription whisper.cpp ; analyse par le fournisseur IA routé (Gemini aujourd'hui, OpenAI sans crédits) ; Remotion pour la composition du Clipper uniquement (le Video Studio garde son moteur FFmpeg).
- **Decision** :
  - **Retrait d'OpusClip** : client, fournisseur, configuration, variables, test et interface supprimés ; la valeur SQL `external` de `video_clips.source` reste autorisée (aucune migration de nettoyage).
  - **Clip Intelligence Engine** (`Clipper/Intelligence`) : l'IA ne produit que des DÉCISIONS (quoi couper, pourquoi, accroche, confiance, note 0-100 par critère : hook, value, autonomy, emotion, rhythm, quality). `ClipCandidate::fromAi` valide strictement chaque élément (champs obligatoires, types, bornes, début < fin, ≤ 60 s — un passage plus long est REJETÉ, plus raccourci au milieu d'une idée —, dans la vidéo). `ClipScorer` calcule le score global (moyenne pondérée, poids dans `video.clipper.scoring.weights`, seul endroit où ils sont définis) ; le score éventuel du modèle est ignoré. `ClipDeduplicator` retire les doublons (chevauchement > `video.clipper.dedupe_overlap` du plus court, meilleur score conservé) après l'analyse et après le recalage. Prompt `clipper.v2` commun à l'analyse par transcription et par lien.
  - **Pipeline** : candidat IA → ClipScorer → ClipRecalibrator (fins de phrase, jamais au milieu d'un mot, ≤ 60 s, durée recalculée) → déduplication → `video_clips` (`hook`, `confidence`, `score_breakdown`).
  - **ClipPlanner** (`Clipper/Planning`) : passage + transcription → `RenderPlan` (schéma `soxial.clip_render_plan.v1` : bornes, format et dimensions, cadence, modèle, cadrage, légendes relatives regroupées par mots, accroche et sa durée). Ne connaît ni FFmpeg ni Remotion. Préréglages `RenderPreset` déclarés dans `video.clipper.presets` : V1 `clean-short` (30 i/s, recadrage centré, accroche 3 s) ; youtube-shorts / instagram-reels / tiktok s'ajouteront sans toucher à l'analyse. Aucune détection de visage simulée : cadrage `center` documenté.
  - **Annulation coopérative** (`POST /clipper/projects/{id}/cancel`) : projet `cancelled`, rendus en cours passés en échec `CANCELLED` (le moteur FFmpeg arrête réellement son processus), acquisition de source arrêtée ; chaque étape vérifie l'état avant de démarrer et avant d'enregistrer (analyse, recalage, acquisition) : un job retardataire n'a aucun effet ; une transcription whisper.cpp déjà lancée va à son terme mais son résultat est ignoré. Médias publiés conservés ; reprise par « Relancer l'analyse ». Statuts : les états existants couvrent la progression (`importing` ≈ téléchargement, suivi par `video_sources` ; `detecting` ≈ analyse) — `downloading` et `planning` n'ont pas été ajoutés : le plan est calculé au moment du rendu, pas comme étape du projet.
  - **Remotion** : paquet Node isolé `renderer/` (remotion, @remotion/bundler, @remotion/renderer 4.0.534 ; react / react-dom 19.2.8 ; Node 24), composition `YouTubeClip` (modèle `CleanShort` : vidéo du passage, accroche en haut, légende active en bas), lanceur `render.mjs` (validation stricte du plan : schéma, dimensions, cadence, ≤ 60 s, modèle, légendes ; source vidéo limitée au serveur local). Côté Laravel, `RemotionRenderer` (Symfony Process, arguments en tableau, Chrome local `REMOTION_BROWSER_EXECUTABLE`, fichiers temporaires dans le dossier de travail du rendu) puis validation FFprobe (`FfmpegVideoRenderer::adopt`, ≤ 60 s, dimensions du plan).
- **Limites** : la composition n'est pas encore branchée au rendu des clips (phase suivante : FFmpeg découpe et recadre sans sous-titres → Remotion compose sous-titres et accroche → FFprobe → médiathèque) ; licence Remotion gratuite jusqu'à 3 salariés, licence entreprise au-delà ; rendu Remotion lent sur ce poste (~1 min 30 pour 2 s, bundle compris).
- **Status** : Accepted — 2026-10-11

### ADR-052 — Rendu réel des clips : FFmpeg (découpe, recadrage) puis Remotion (composition)
- **Context** : l'ADR-051 a livré le planificateur (`RenderPlan`) et le paquet Remotion sans les brancher au rendu des clips. Phase 2 (2026-10-11) : produire de vrais MP4 composés (accroche, sous-titres) à partir d'une source autorisée, sans changer le Video Studio (FFmpeg seul) ni introduire de téléchargeur YouTube.
- **Decision** :
  - **Pipeline de `ClipRenderRunner`** : contrôles (clip en `rendering`, projet non annulé, bornes) → FFmpeg découpe et recadre le passage SANS sous-titres → `ClipPlanner` construit le `RenderPlan` (légendes relatives issues de la transcription prête, accroche du clip) → `RemotionRenderer` compose (`clean-short`) → validation FFprobe (`FfmpegVideoRenderer::adopt` : MP4 lisible, ≤ 60 s, dimensions égales au plan, écart de durée ≤ 120 ms, piste audio présente si la découpe en avait une ; sinon `OUTPUT_INVALID`) → `RenderedVideoPublisher` (antivirus, stockage privé, vignette) → statuts → suppression du dossier de travail.
  - **Configuration** : `video.clipper.composer` (`CLIPPER_COMPOSER`, `remotion` | `ffmpeg`) ; si Remotion n'est pas disponible (Node, paquet, Chrome), repli automatique sur le rendu FFmpeg complet (sous-titres incrustés par FFmpeg). `video.clipper.show_hook` (`CLIPPER_SHOW_HOOK`) règle l'affichage de l'accroche globalement — un réglage par clip demanderait une colonne (migration non faite, à décider séparément). `REMOTION_TIMEOUT` porté à 1500 s. Métadonnées du média : `composition` (`composer`, `template`, `hook`, `captions`, `crop`).
  - **Vidéo transmise à Remotion** : `render.mjs --video <passage.mp4> --root <dossier de travail>` ; le fichier doit être un `.mp4` réel DANS le dossier de travail (chemins réels vérifiés côté PHP et côté Node). Il est servi par un serveur HTTP éphémère sur 127.0.0.1, port aléatoire, chemin à jeton aléatoire, GET/HEAD seulement, requêtes Range prises en charge ; fermé en fin de rendu. Un `video_src` fourni dans le plan est refusé (`INVALID_PLAN`) : aucune URL arbitraire ne peut être chargée.
  - **Annulation et workers** : l'état est vérifié avant le rendu, entre FFmpeg et Remotion et avant la publication ; pendant Remotion, le processus est arrêté réellement (`stop`) et le clip passe en échec `CANCELLED`, sans média publié. Un job retardataire n'a aucun effet. L'étape `rendering` reste commune aux deux moteurs (contrainte SQL existante, pas de statut supplémentaire). Un worker interrompu est couvert par `tries=1`, `failed()` et `RENDER_ABORTED`. Les fichiers temporaires de Chrome/Remotion (`TEMP`, `TMP`) sont redirigés dans le dossier de travail, supprimé dans tous les cas (diagnostic `video.workspace_not_deleted` si un fichier reste verrouillé sous Windows).
  - **Frontend** : accroche, score arrondi et « Détail du score » (critères, confiance), bouton « Annuler le traitement », état annulé, lecture du MP4 privé (URL signée).
  - **Tests** : `phpunit.xml` force `CLIPPER_COMPOSER=ffmpeg` pour les tests historiques du moteur FFmpeg ; `ClipperRemotionPipelineTest` (groupe `remotion`) active Remotion et rend de vrais MP4 (9:16, 1:1, 16:9, avec et sans accroche ni transcription, échecs du moteur, annulation avant et pendant le rendu).
- **Limites** : rendu Remotion lent sur ce poste (≈ 4 min pour un clip de 6,6 s, bundle compris) ; cadrage centré seulement ; un seul modèle (`clean-short`).
- **Status** : Accepted — 2026-10-11

### ADR-053 — Source accessible sans import manuel : réutilisation locale, contrat d'acquisition, explications précises
- **Context** : audit du 2026-10-11. Le Clipper exige un fichier pour produire les MP4 (FFmpeg + Remotion travaillent sur des octets locaux) ; le lien YouTube sert à identifier, analyser et prévisualiser. Voies existantes : import depuis la médiathèque (réel) ; `DataPortabilityVideoSourceProvider` (export officiel Google, vidéos du propriétaire, désactivé : approbation Google et évaluation CASA, consentement non construit, format d'archive non vérifié) ; aucun fournisseur externe (OpusClip retiré). L'API YouTube Data v3 ne fournit aucun fichier vidéo et ses règles interdisent le téléchargement sans autorisation : aucune autre voie automatique n'existe. Constat : chaque nouveau projet sur une vidéo déjà fournie par l'utilisateur redemandait un import.
- **Decision** :
  - **`LibraryVideoSourceProvider`** (implémente `VideoSourceProvider`, méthode `library`) : réutilise, SANS copie ni import ni requête réseau, le média qu'une source PRÊTE du même utilisateur utilise déjà pour la même vidéo (même plateforme, même identifiant). Média vidéo du même propriétaire, non supprimé, analyse antivirus acceptée ; aucun rapprochement deviné (nom, durée). Appliqué dès la création du projet (`ClipperService::createProject`, limites de taille et de durée revérifiées) et, sinon, en tête de `VideoSourceService::acquire` avant la vérification de propriété et les fournisseurs externes. Désactivable : `CLIPPER_REUSE_LIBRARY`.
  - **Contrat** : `AcquisitionStep::reused(mediaId, purgeAt)` complète `acquired` (fichier à importer) ; la source réutilisée passe par `finalize` → `linkSource` (durée contrôlée, analyse relancée) puis par le pipeline existant (whisper.cpp, Clip Intelligence, FFmpeg + Remotion) : aucun moteur dupliqué.
  - **Conservation** : la source réutilisée hérite de la date de purge de sa source d'origine (jamais créée ni prolongée ; import = jamais purgé). La purge (`clipper:expire-sources`) ne supprime plus un média tant qu'une autre source prête ou un autre projet l'utilise.
  - **Indisponibilité expliquée** : `PROVIDER_NOT_CONFIGURED` / `NO_PROVIDER_FOR_SOURCE` portent un message précis (API YouTube sans fichier ; export officiel non activé ; vidéo d'une autre chaîne ; propriété non vérifiable → connecter la chaîne). L'interface propose l'import d'un fichier autorisé en remplacement.
  - Aucune migration : `video_sources.acquisition_method` est un `varchar` sans contrainte CHECK.
  - **Robustesse Windows** (observée pendant l'essai réel) : après un échec de Remotion, le profil Chrome reste verrouillé plus d'une seconde ; `RenderWorkspace::delete` retente désormais pendant ~4 s (aucun reliquat ensuite). Le journal `clipper.remotion_failed` conserve le début de la sortie d'erreur (message de Remotion) en plus de la fin (pile).
- **Limites** : sans fichier déjà fourni ni fournisseur officiel activé, la première génération exige toujours un import autorisé. La Data Portability reste désactivée (démarches Google à mener par l'éditeur). Remotion échoue de façon intermittente quand la mémoire libre est quasi nulle (« No frame found at position », puis délai `delayRender` dépassé ; 0,1 Go libre sur 7,9 Go lors de l'essai) : le clip passe en échec `REMOTION_RENDER_FAILED`, sans publication, et peut être relancé ; aucun repli automatique sur FFmpeg après un échec (décision produit à prendre).
- **Status** : Accepted — 2026-10-11

### ADR-054 — Clipper : récupération automatique depuis le lien par l'export officiel Google (Data Portability)
- **Context** : objectif « lien YouTube → Shorts MP4 sans import ». Audit du 2026-10-11 : l'analyse ne demande que le lien (Gemini), mais le rendu (FFmpeg puis Remotion) exige le fichier ; le seul fournisseur automatique (`DataPortabilityVideoSourceProvider`) était désactivé, sans parcours de consentement (`DataPortabilityConsents` renvoyait toujours null) ni exploitation de l'archive (arrêt `ARCHIVE_FORMAT_UNVERIFIED`) : d'où « Aucune acquisition automatique de la vidéo n'est activée sur ce serveur ». Vérifié auprès de Google le même jour : l'API YouTube ne fournit le fichier d'aucune vidéo ; la Data Portability API exporte les vidéos téléversées par le compte qui consent (« Original format / MP4 ») ; disponible pour les comptes de l'EEE, de Suisse et du Royaume-Uni (hors mineurs, comptes gérés, Protection avancée) ; projet Google Cloud en « Testing » : 100 utilisateurs de test, autorisation valable 7 jours ; publication : vérification Google et évaluation de sécurité (scopes restreints). Arborescence de l'archive et lien identifiant → fichier non documentés. Aucun téléchargeur non officiel (conditions YouTube, décision ADR-051).
- **Decision** :
  - **Consentement** : `POST /clipper/projects/{id}/source/consent` (vidéo dont la propriété est vérifiée sur une chaîne connectée) → URL Google (state à usage unique lié à l'utilisateur, au projet et au compte ; PKCE S256 ; scopes `dataportability.youtube.*` seuls ; `access_type=offline`). Retour `GET /oauth/data-portability/callback` (route web) : échange du code, groupes accordés vérifiés (aucun → révocation, rien d'enregistré), jeton chiffré (`social_tokens`, nouveau `token_kind` `portability`, migration `2026_10_11_155113` appliquée en dev avec accord), reprise automatique de la récupération du projet. `OAuthStateStore` accepte un contexte serveur facultatif.
  - **`DataPortabilityConsents`** : un jeton actif par compte YouTube ; renouvelé sous verrou ; refusé par Google → invalide, nouveau consentement demandé. Client OAuth : celui de YouTube par défaut (même projet Google Cloud).
  - **Fournisseur** : export des seuls groupes accordés (par défaut vidéos publiques et non répertoriées), suivi par relances espacées, puis **`DataPortabilityArchiveExtractor`** : téléchargement en flux des URL signées Cloud Storage uniquement (sans redirection, taille cumulée plafonnée, délai), extraction de LA vidéo par rapprochements sûrs à correspondance UNIQUE (identifiant dans le nom du fichier ; titre associé à l'identifiant dans un CSV de l'archive ; titre oEmbed ; seule vidéo de l'export), sinon `ARCHIVE_VIDEO_NOT_FOUND` / `ARCHIVE_VIDEO_AMBIGUOUS` ; liens expirés, archive invalide ou trop lourde, coupure réseau (nouvel essai) normalisés ; dossier de travail toujours supprimé. Le fichier passe ensuite par l'import existant (médiathèque, antivirus), la finalisation, puis le pipeline existant (whisper.cpp, Clip Intelligence, FFmpeg, Remotion, FFprobe).
  - **Robustesse** : `AcquireVideoSourceJob::failed()` → source `ACQUISITION_ABORTED` (jamais « en préparation » sans job) ; annulation vérifiée entre deux téléchargements.
  - **Interface** : l'import n'est plus présenté comme l'exigence : action adaptée à la cause (« Autoriser l'export Google de ma chaîne », « Connecter ma chaîne YouTube », « Relancer la récupération automatique »), message précis, progression de l'export ; « Importer le fichier » en solution de remplacement seulement.
- **Limites** : non validé avec Google réel (client OAuth à autoriser pour l'API Data Portability, compte de test d'une région couverte) ; rapprochement archive → vidéo à confirmer sur un export réel ; vidéos d'autres chaînes : aucune voie automatique légale (import d'un fichier autorisé) ; export de toute la chaîne (volumineux, délai Google de quelques minutes à quelques heures) ; publication publique conditionnée à la vérification Google.
- **Status** : Accepted — 2026-10-11

### ADR-055 — Clipper : causes distinguées, workers par défaut, analyse de transcription par OpenAI, repli FFmpeg
- **Context** : audit du 2026-10-11 d'un projet créé depuis un lien seul (vidéo d'une autre chaîne). Trois causes confondues : (1) aucune source autorisée (`PROVIDER_NOT_CONFIGURED` : vidéo d'un tiers, aucune chaîne connectée, export Google désactivé) — message correct ; (2) « L'IA n'a proposé aucun passage exploitable » alors que Gemini avait répondu (765 jetons) : la même vidéo rejouée donne 5 passages valides ; la réponse d'origine était inexploitable (drapeau « incomplète » ignoré, budget de 2 000 jetons partagé avec la réflexion des modèles Gemini 3.x, réponse brute non conservée) ; (3) `npm run dev` ne démarrait ni le worker `ai` ni le worker `video` : analyses, récupérations, rendus et images IA restaient « en attente ». Gemini gratuit : 20 requêtes par jour, vite épuisées par les analyses de transcription.
- **Decision** :
  - **Diagnostic de l'analyse** (`TranscriptAnalyzer::evaluate`, `ClipCandidate::rejectionReason`) : réponse interrompue → `INCOMPLETE_RESPONSE`, illisible → `INVALID_RESPONSE` (erreurs passagères, relance) ; passages proposés mais tous écartés → `NO_VALID_CLIPS` avec le détail (plus de 60 s, hors de la vidéo, incomplets, fin avant début) ; liste vide valide → « aucun passage pertinent ». Compteurs `proposed` / `retained` / `rejected` enregistrés dans `ai_generations.output`. Interface : « Réponse de l'IA inexploitable », « Passages de l'IA écartés », « Aucun passage proposé » distincts. Validation inchangée.
  - **Budget** de l'analyse : `AI_VIDEO_ANALYSIS_MAX_OUTPUT_TOKENS` (8 000 par défaut).
  - **Fournisseur de l'analyse par lien** séparé : `AI_VIDEO_URL_ANALYSIS_PROVIDER` (Gemini, seul capable de regarder une vidéo par son lien) ; les transcriptions suivent `AI_VIDEO_ANALYSIS_PROVIDER` (OpenAI en local). Paramètre facultatif `provider` d'`AiService` (rétrocompatible) ; aucune nouvelle tâche IA, aucune migration.
  - **Lanceur** : workers `ai` et `video` démarrés par défaut (`--workers=none` pour s'en passer).
  - **Repli FFmpeg** (`CLIPPER_REMOTION_FALLBACK`, actif par défaut) : un échec de Remotion (jamais une annulation) relance le rendu FFmpeg seul (sous-titres incrustés, sans accroche) ; métadonnées `composition = {composer: ffmpeg, fallback_from: remotion, fallback_reason}`.
- **Vérifié en réel** : clé OpenAI (texte `gpt-6-luna`, image `gpt-image-2.5-flare` → PNG 9:16 dans la médiathèque) ; lien seul + source autorisée déjà présente → transcription whisper.cpp → analyse OpenAI (4 passages proposés, 4 retenus) → FFmpeg + Remotion → MP4 1080×1920 h264/aac 6,613 s → médiathèque ; source sans parole → « Aucune parole détectée », aucun rendu lancé.
- **Limites** : sans fichier autorisé (vidéo d'un tiers), aucune récupération automatique légale ; export Google à configurer (ADR-054) ; quota Gemini gratuit pour l'analyse par lien.
- **Status** : Accepted — 2026-10-11

### ADR-056 — Finalisation du Clipper : suivi fiable de l'export Google, nettoyage, doublons, moteur de rendu visible
- **Context** : audits du 2026-10-11 et du 2026-10-12. Défauts démontrés : (C1) accès Google « une seule fois » déjà utilisé → 429 `RESOURCE_EXHAUSTED` traité comme limite passagère (relances jusqu'à `ACQUISITION_TIMEOUT`) ; (C2) attente bornée à 30 × 300 s alors qu'un export peut durer 7 jours (documentation Google) ; (C3) suppression de l'archive sans nouvelle tentative (verrou Windows intermittent, 2 tests en échec) ; (C4) `video:purge-temp` ignorait les restes d'acquisition ; (C7) export en échec jamais relancé ; (A1) worker `ai` coupé à 120 s alors que le job d'image prévoit 300 s ; (A2) repli FFmpeg invisible dans l'interface ; (A3) doubles projets possibles. Découvert en finalisation : après 24 h d'attente, la revérification de la propriété remettait la source en « resolving » et relançait un export en cours.
- **Decision** :
  - Client Data Portability : `CONSENT_EXHAUSTED` (429 « already been exported »), `resetAuthorization` (`POST /v1/authorization:reset`), `retry` (`POST /v1/archiveJobs/{id}:retry`). Fournisseur : accès épuisé → réinitialisation, jeton local expiré, nouveau consentement demandé (message conseillant 30 ou 180 jours) ; échéance de 7 jours (`deadline` dans les métadonnées, prioritaire sur `max_attempts`) avec intervalle croissant (réglage, puis 15 min après 1 h, 30 min après 24 h) ; une relance officielle d'un export en échec.
  - `VideoSourceService` : propriété jamais revérifiée pendant l'attente d'un fournisseur.
  - Extracteur : suppression de l'archive retentée (~4 s) et journalisée ; `video:purge-temp` purge aussi `portability-<source>` et `acquired-<source>.*` anciens (jamais une acquisition en cours).
  - Création de projet : même demande (utilisateur, lien, format, fichier) dans `CLIPPER_CREATE_DEDUPE_SECONDS` (10 s) → projet existant renvoyé, sous verrou.
  - Worker `ai` : `--timeout=300` (appel image 180 s < job 300 s ≤ worker < retry_after 360 s) ; test de cohérence du lanceur.
  - API du clip : champ `render` (`engine`, `fallback`, `hook`) issu des métadonnées du média, seulement pour un clip prêt et utilisable ; la carte du clip affiche « Rendu de secours FFmpeg — accroche non disponible » uniquement si le repli est confirmé.
- **Limites** : export Google non validé en réel (prérequis Google Cloud, compte et région de l'utilisatrice) ; format réel de l'archive à confirmer ; téléchargement d'archives très volumineuses limité par le délai du job (1 800 s) ; verrou Windows intermittent observé en test (cause probable : antivirus du poste, non prouvée).
- **Status** : Accepted — 2026-10-12

### ADR-057 — Remotion : décodage vidéo par `<Video>` de @remotion/media (fin des « No frame found at position »)
- **Context** : échecs intermittents de la composition (« No frame found at position N »), repli FFmpeg sans accroche. Diagnostic du 2026-10-12, journal détaillé du compositeur : `<OffthreadVideo>` passe par le cache d'images du compositeur Rust ; sous Windows, Remotion mesure la mémoire avec `os.freemem()` (mémoire strictement libre, 100 à 300 Mo constatés : « Available memory: 99.80 MiB ») et réduit ce cache en cours de rendu (160 → 80 → … → 5 Mo, « keeping 0 ») ; une image 1080×1920 décodée pèse ~6 Mo, la vidéo intermédiaire contient des B-frames (images décodées dans le désordre) : l'image demandée est évincée avant d'être lue. Preuves : échec à coup sûr avec un cache imposé de 12 Mo (« position 512 ») ; un cache explicite de 160 Mo ne suffit pas (3 échecs sur 4, la réduction automatique s'applique quand même). Écartés par mesure : image demandée hors durée (198 images, durée cohérente), B-frames seules (succès avec et sans).
- **Decision** :
  - Modèle `clean-short` : `<Video>` de `@remotion/media` (composant recommandé par Remotion, décodage WebCodecs dans Chrome, hors cache du compositeur), `objectFit="cover"`, `disallowFallbackToOffthreadVideo` (aucun retour silencieux au composant fautif). `@remotion/media@4.0.534` déclaré dans `renderer/package.json` (déjà présent, même version, aucun téléchargement).
  - Serveur vidéo local de `render.mjs` : la vidéo est désormais lue par la page de rendu → en-têtes CORS limités aux origines `http://localhost:<port>` et `http://127.0.0.1:<port>` (jamais « * »), pré-vérification `OPTIONS`, en-têtes exposés pour les requêtes Range ; serveur toujours sur 127.0.0.1, port aléatoire, chemin à jeton, lecture seule.
  - Délai de chaque étape Remotion explicite (`timeoutInMilliseconds` de `selectComposition` et `renderMedia`) : `REMOTION_STEP_TIMEOUT` (120 s, borné 10-600 s) au lieu des 30 s par défaut, dépassées pendant la suite de tests sur ce poste (« Timed out after 30000ms while setting up the headless browser », delayRender « Extracting frame » non résolu en 28 s). Durée totale toujours bornée par `REMOTION_TIMEOUT`.
  - Repli FFmpeg conservé tel quel (échec réel de Remotion seulement, cause journalisée, moteur exposé par l'API et l'interface).
- **Preuves réelles** : passage réel de 6,6 s rendu 3 fois de suite par Remotion (3/3, 198 images, 6,613 s, H.264 1080×1920 30 i/s, AAC 48 kHz stéréo, décodage intégral sans erreur, accroche et sous-titres visibles sur les images extraites) ; rendu de 57 s (1 710 images, 653 s, sous `REMOTION_TIMEOUT` 1 500 s, sous-titres visibles à 30 s et 55 s).
- **Limites** : rendu lent sur ce poste (~6,6 s de vidéo en 4-6 min, 57 s en 11 min) ; un clip de 60 s proche de la limite de délai sur une machine plus chargée.
- **Status** : Accepted — 2026-10-12

### ADR-058 — Clipping indépendant des comptes sociaux ; modèle de secours Gemini explicite
- **Context** : après l'activation de l'export Google (2026-10-12), tout lien d'une vidéo sans source affichait « Connectez la chaîne YouTube qui a publié cette vidéo » et un bouton principal « Connecter ma chaîne YouTube » (`SourceCard`), message repris par `VideoSourceService::unavailableReason` et la réponse 409 du consentement. Le moteur, lui, n'exigeait aucun compte social (lien, analyse, sélection, rendu, résultats) : le couplage était dans les messages et l'interface. Erreurs Gemini mesurées : `gemini-3.5-flash` → HTTP 429 `too_many_requests` (quota gratuit de 20 requêtes/jour épuisé), `gemini-3.1-flash-lite` → aucune réponse en 60 s ; clé valide, même modèle côté serveur web et workers.
- **Decision** :
  - Erreur d'ACQUISITION seule quand le fichier n'est pas accessible : « Le fichier de cette vidéo n'est pas accessible automatiquement… Importez un fichier dont vous détenez les droits », bouton principal « Importer le fichier » ; jamais de demande de connecter la chaîne qui a publié la vidéo. L'export Google n'est mentionné que comme voie facultative pour une vidéo de la PROPRE chaîne de l'utilisateur, et proposé en bouton seulement quand cette chaîne est connectée et la vidéo lui appartient (`CONSENT_REQUIRED`).
  - Comptes sociaux inchangés : connexion, publication sur le compte choisi dans le compositeur (le module de publication n'utilise jamais la chaîne d'origine du lien).
  - Analyse par lien : modèle de secours facultatif `AI_VIDEO_URL_ANALYSIS_FALLBACK_MODEL` (vide par défaut), essayé UNE fois si le principal échoue pour quota, surcharge, délai ou réseau ; appel distinct dans `ai_generations`, note « Analyse du lien réalisée avec le modèle de secours … » et `fallback_model` dans le rapport d'analyse.
- **Limites** : le niveau gratuit de Gemini reste le facteur limitant (activer la facturation du projet Google AI Studio) ; publication réelle non testée (compte YouTube de l'utilisatrice expiré : mode « Testing », autorisations de 7 jours).
- **Status** : Accepted — 2026-10-12

### ADR-059 — Récupération d'une vidéo YouTube publique par yt-dlp, après attestation de droits
- **Context** : audit du 2026-10-12 : sans fichier, un lien YouTube permet d'identifier et d'analyser la vidéo, jamais de générer les MP4 ; l'export Google (ADR-054) ne couvre que les vidéos de la propre chaîne de l'utilisateur. L'éditrice du produit a choisi l'option B : yt-dlp, dans un cadre strict. Les conditions d'utilisation de YouTube restreignent le téléchargement : la responsabilité de l'activation relève de l'éditrice ; la voie est désactivée par défaut.
- **Decision** :
  - Exécutable autonome officiel (github.com/yt-dlp/yt-dlp/releases, 2026.08.19, empreinte SHA-256 vérifiée) dans `storage/app/yt-dlp/` (ignoré par Git), chemin centralisé `YT_DLP_BINARY` ; vérification réelle `yt-dlp --version` (`YtDlpBinary`, mise en cache 5 min) et commande `php artisan clipper:ytdlp-check` (aucun accès réseau).
  - `YtDlpVideoSourceProvider` (`AcquisitionMethod::YtDlp`, `yt_dlp`), après l'export Google dans `video.sources.providers`, actif seulement si `CLIPPER_YTDLP_ENABLED=true` ET exécutable opérationnel.
  - Attestation de droits obligatoire : case « J'atteste détenir les droits ou l'autorisation d'utiliser cette vidéo » à la création (`rights_confirmed`) ou depuis la carte de source (`POST …/source/acquire` avec `rights_confirmed`) ; horodatée dans `video_sources.metadata` (`rights_attested`, `rights_attested_at`). Sans attestation : `RIGHTS_CONFIRMATION_REQUIRED`, yt-dlp n'est jamais lancé.
  - Garde-fous : URL reconstruite depuis l'identifiant validé (`https://www.youtube.com/watch?v=<id>`, aucune URL libre, aucun SSRF) ; `--ignore-config --no-cookies --no-cookies-from-browser --use-extractors youtube --no-playlist` ; aucun compte, aucun cookie, aucun contournement (anti-robot, âge, membres, région → erreur précise) ; métadonnées lues d'abord (`-J --skip-download`) : refus d'une vidéo non publique ni non répertoriée, à limite d'âge, en direct ou trop longue ; taille (`--max-filesize`, puis contrôle réel), délai (`YT_DLP_TIMEOUT`, 1 200 s ; métadonnées 120 s), espace disque (1,2 × taille max) ; H.264/AAC ≤ 1080p fusionné en MP4 par le FFmpeg du serveur ; processus surveillé, arrêté à l'annulation du projet ou au délai ; dossier propre à la tâche (`ytdlp-<source>`), supprimé dans tous les cas et purgé par `video:purge-temp` après un arrêt brutal ; seul un fichier réel, non vide, situé dans ce dossier, est remis à l'import (médiathèque, antivirus) puis à FFprobe.
  - Erreurs normalisées (`VIDEO_NOT_PUBLIC`, `AGE_RESTRICTED`, `LIVE_NOT_SUPPORTED`, `SOURCE_TOO_LONG`, `SOURCE_TOO_LARGE`, `BLOCKED_BY_YOUTUBE`, `GEO_RESTRICTED`, `VIDEO_UNAVAILABLE`, `RATE_LIMITED`, `NETWORK_ERROR` et `PROXY_UNAVAILABLE` temporaires, `DOWNLOAD_TIMEOUT`, `DOWNLOAD_INCOMPLETE`, `DOWNLOAD_FAILED`) : messages en français, jamais la sortie brute, un chemin ou un secret ; journaux `clipper.ytdlp_acquired` / `clipper.ytdlp_failed` (identifiant de source, code, durée, taille, licence déclarée).
  - Voie enregistrée avant l'étape (statut `resolving`, méthode `yt_dlp`) : l'interface affiche « Téléchargement de la vidéo » pendant la récupération. Sources restées `NO_PROVIDER_FOR_SOURCE` : relancées par `clipper:retry-unavailable-sources` quand une voie les prend désormais en charge.
  - Distinct de l'OAuth des comptes sociaux et de la publication : aucune chaîne connectée n'est requise ni utilisée.
- **Tests** : `YtDlpAcquisitionTest` (9) — yt-dlp SIMULÉ (faux exécutable qui enregistre ses arguments) ; processus, délai, annulation, MP4 FFmpeg, import, FFprobe et nettoyage RÉELS.
- **Correctif du 2026-10-12 (parcours depuis l'interface)** : cause du message générique « Le fichier de cette vidéo n'est pas accessible automatiquement… » lors d'un essai réel : `CLIPPER_YTDLP_ENABLED` absent du `.env` → voie désactivée → aucun fournisseur pour une vidéo d'un tiers → `NO_PROVIDER_FOR_SOURCE`, message identique à l'absence de toute voie (et case d'attestation masquée, d'où `rights_attested` absent). Corrigé : diagnostic distinct `VIDEO_DOWNLOAD_DISABLED` (voie désactivée) / `YTDLP_UNAVAILABLE` (exécutable absent), recalculé à l'affichage et relancé par `clipper:retry-unavailable-sources` dès l'activation ; les fournisseurs sont essayés dans l'ordre (une vidéo de sa propre chaîne sans consentement Google passe à yt-dlp) ; journaux `clipper.source_acquisition` (fournisseurs configurés et candidats), `clipper.source_provider_step`, `clipper.ytdlp_started`. Activation : ajouter `CLIPPER_YTDLP_ENABLED=true` au `.env` de l'environnement concerné ; en développement (`npm run dev`, `queue:listen` = un processus PHP neuf par job, configuration non mise en cache) aucun redémarrage n'est nécessaire ; si la configuration est en cache : `php artisan config:clear` (ou `config:cache`), puis `php artisan queue:restart` pour des workers `queue:work`. Contrôle : `php artisan config:show video.sources.yt_dlp` et `php artisan clipper:ytdlp-check`.
- **Essai réel (2026-10-12, accord de l'utilisatrice, `CLIPPER_YTDLP_ENABLED=true` ajouté au `.env` local)** : « Tears of Steel » (Blender, CC-BY, `R6MlUcmOul8`, 734 s), compte de l'utilisatrice, mêmes services et jobs que l'interface et les workers (file simulée dans le script pour exécuter chaque job dans le même processus). Deux blocages réels mis au jour puis corrigés : (1) fichier 1080p H.264 de 192,9 Mo refusé par la médiathèque, limitée à 100 Mo (`MEDIA_MAX_VIDEO_MB` du `.env`) → limite effective = min(`video.sources.max_bytes`, limite vidéo de la médiathèque), transmise à yt-dlp (`--max-filesize`), sélection de la meilleure qualité ≤ 1080p dont la taille annoncée tient dans la limite (flux HTTP directs, H.264/AAC préférés à résolution égale, `-S res,vcodec:h264,acodec:aac`), refus avant téléchargement si aucune qualité ne tient, raison précise de la médiathèque (`SOURCE_TOO_LARGE`) ; (2) refus ponctuels de YouTube sur les données vidéo (`HTTP Error 403: Forbidden`, 2 fois sur 5) classés « échec générique » → `BLOCKED_BY_YOUTUBE` temporaire (relance à 5 min), une nouvelle tentative immédiate (nouvelle extraction), extrait anonymisé de l'erreur dans le journal serveur. Résultat : format 399+140 (AV1 1920×800 + AAC, 83,9 Mo en 97,7 s), import médiathèque, FFprobe 734 215 ms, transcription whisper.cpp + analyse LOCALE (aucun crédit IA payant) → 5 passages, passage de 15 s rendu par Remotion (`clean-short`, 7 sous-titres, sans accroche en analyse locale) → MP4 H.264/AAC 1080×1920 30 i/s, 15 019 ms, 12,6 Mo dans la médiathèque, décodage intégral sans erreur, sous-titres vérifiés sur images extraites.
- **Incident du 2026-10-12 (lien de la propre vidéo de l'utilisatrice → YTDLP_UNAVAILABLE)** : le worker vidéo a jugé yt-dlp inutilisable (`clipper.source_acquisition` : `configured: ["data_portability"]`, aucun candidat) alors que l'outil fonctionnait. Cause : l'exécutable autonome se décompresse à chaque lancement — `yt-dlp --version` mesuré à 4-8 s à vide et 70-82 s sous forte charge (4 encodages FFmpeg sur 4 cœurs ; la suite de tests complète tournait à ce moment) — or le contrôle coupait à 20 s et mettait l'échec en cache PARTAGÉ 30 s (Redis), imposé à tous les processus. La raison exacte de l'échec n'était pas journalisée à l'époque (cause établie par mesure et par élimination ; l'hypothèse d'un serveur `artisan serve` privé de TEMP a été vérifiée puis écartée : `variables_order=GPCS`, seules les clés du `.env` sont retirées). Corrigé : trois états (`ok` mémorisé 7 jours pour ce fichier précis — chemin, taille, date ; `unverified` = démarrage plus lent que `YT_DLP_CHECK_TIMEOUT` (60 s), voie conservée, le téléchargement tranche ; `unavailable` = échec AVÉRÉ : fichier absent, code de sortie, sortie invalide) ; un état non `ok` n'est jamais partagé entre processus ; raison journalisée (`clipper.ytdlp_check`) ; délai des métadonnées porté à 300 s ; échec de démarrage de l'exécutable reconnu (`YTDLP_UNAVAILABLE`, temporaire) ; dossiers temporaire et de cache propres à l'application transmis à chaque lancement. Le parcours n'exige aucune connexion OAuth : la vérification de propriété est facultative et l'export Google ne s'applique qu'aux vidéos d'une chaîne connectée.
- **Limites** : YouTube peut bloquer les serveurs (vérification anti-robot) : l'import manuel reste la voie de remplacement ; yt-dlp doit être mis à jour régulièrement (même procédure, empreinte vérifiée).
- **Relais réseau (2026-10-28)** : en production (VPS Hostinger), YouTube refuse toute vidéo, même publique, depuis l'adresse de l'hébergeur (« Sign in to confirm you're not a bot », constaté le 2026-10-22 avec 2026.08.19 et les clients `tv`/`web_safari`) ; en local, la connexion résidentielle passait. Décision de l'éditrice : réglage facultatif `YT_DLP_PROXY` (`video.sources.yt_dlp.proxy`, http(s) ou socks5(h), identifiants admis) transmis par `--proxy` à chaque appel (métadonnées et téléchargement) ; vide = connexion directe ; valeur invalide jamais transmise (`clipper.ytdlp_proxy_invalid`, sans le contenu) ; toujours aucun cookie ni compte ; relais injoignable → `PROXY_UNAVAILABLE` temporaire ; toute adresse (`schéma://…`, donc les identifiants du relais) masquée dans les extraits d'erreur journalisés.
- **Status** : Accepted — 2026-10-12

### ADR-060 — Clipper : API sans exécution de binaires, suivi fiable, durées mesurées par étape
- **Context (2026-10-13)** : (1) message persistant « outil de récupération indisponible » ; (2) erreur « introuvable » pendant le rendu, disparaissant après actualisation ; (3) lenteurs sur tout le pipeline. Constats (journaux, base, chronomètres) :
  - le serveur web de `php artisan serve` (SAPI `cli-server`) ne lançait pas yt-dlp : une fois Laravel démarré, Symfony Process ne transmet à l'enfant que les variables présentes dans `getenv()` ET `$_SERVER`, soit celles du `.env` — ni `TEMP` ni `SystemRoot` → « Could not create temporary directory! » puis « _Py_HashRandomization_Init » (reproduit à l'identique ; corrige la conclusion de l'ADR-059, qui avait testé sans démarrer Laravel). L'API affichait donc « outil indisponible » et masquait la case d'attestation ;
  - « introuvable » : les requêtes de suivi (toutes les 3 s) exécutaient `ffmpeg -version` puis `ffprobe -version` (20 s de délai chacun) à l'expiration du cache ; sous la charge d'un rendu, `max_execution_time` (30 s) était dépassé → erreur fatale 500 (`Maximum execution time of 30 seconds exceeded`, 7 occurrences) ; la page affichait alors « Projet introuvable » pour TOUTE erreur, même avec des données déjà chargées (React Query garde `data` et passe `isError`), et le suivi reprenait seul à la requête suivante. Aucune erreur de chemin du système de fichiers dans les journaux ;
  - un délai dépassé était mis en cache PARTAGÉ comme « absent », pouvant faire échouer un rendu ou une acquisition du worker.
- **Decision** :
  - `ExecutableCheck` (FFmpeg, FFprobe, yt-dlp) : succès partagé 7 jours lié au fichier ; délai dépassé = « non vérifié » (jamais « absent ») ; échec avéré non partagé entre processus ; raison journalisée (`video.executable_check`). **Une requête web n'exécute jamais de binaire** (lecture du succès partagé, sinon « non vérifié ») ; vérification par les processus CLI et la commande planifiée `video:check-executables` (toutes les 10 min, quasi gratuite si déjà vérifié). yt-dlp reçoit explicitement les variables système nécessaires (`SystemRoot`, `PATH`…) et des dossiers temporaire/cache propres à l'application.
  - Interface : écran d'erreur seulement sans aucune donnée ; « Projet introuvable » réservé au 404 ; échec passager du suivi = bandeau « mise à jour momentanément interrompue » et nouvel essai automatique (2 essais, jamais sur 401/403/404) ; le suivi continue d'après le dernier état connu.
  - Progression réelle du rendu sans migration (la colonne `render_stage` a une contrainte CHECK) : sous-étape et pourcentage en cache (`video:clip-progress:<clip>`), exposés par l'API (`render_progress` : découpe FFmpeg, composition Remotion avec % réel issu de `onProgress`, secours FFmpeg, validation, publication) et affichés sur la carte du clip (barre de progression).
  - Journaux horodatés par étape : `clipper.analysis_timings` (attente en file, sonde, audio, pauses, transcription, IA lien, IA), `clipper.render_timings` (attente, source, découpe, composition, secours, publication, étape atteinte, issue), `clipper.remotion_timings` (bundle, sélection de la composition, images, encodage, concurrence, gl).
  - Appels inutiles supprimés : quota de l'analyse par lien épuisé (7 échecs Gemini identiques le même jour) → analyse par lien suspendue 30 min (`clipper.url_analysis_suspended`) au lieu d'un appel voué à l'échec à chaque projet.
  - Remotion : `REMOTION_CONCURRENCY` = 1 par défaut (mesures ci-dessous) ; `REMOTION_GL` facultatif (angle = GPU sous Windows), désactivé par défaut.
- **Mesures réelles (poste 4 cœurs, 8 Go, 0,2 à 0,9 Go libres)** — vidéo de 164 s de l'utilisatrice, par les vrais workers : acquisition yt-dlp 82,2 s (86 Mo) ; analyse 326 s dont transcription whisper.cpp 280,6 s (86 %), extraction audio 12,9 s, pauses 8,7 s, sonde 5,0 s, IA 12,3 s (OpenAI, après échec de quota Gemini 2,5 s), attente en file 7,7 s ; rendu d'un passage de 48 s (1 440 images) 23 min 06 s dont Remotion 1 232 s (images 1 119 s = 0,78 s/image, démarrage de Chrome et sélection 53 s, encodage 24 s), découpe FFmpeg 135 s, vérification de la source 15 s, attente 14 s, publication 2 s. MP4 H.264/AAC 1080×1920 30 i/s, 48,000 s, rendu par Remotion avec accroche. Banc d'essai Remotion (même passage de 4 s, ordre contrôlé) : 1 onglet 120,1 s ; 2 onglets 144,0 s ; 1 onglet + angle 110,9 s (premier passage : 2 onglets 270,5 s, 3 onglets 356,2 s, mémoire saturée).
- **Limites** : le rendu image par image de Remotion reste le poste dominant sur ce poste (mémoire limitée, CPU sans accélération) ; la transcription locale ≈ 1,7× la durée de la vidéo ; aucun test automatisé du frontend (le projet n'a pas d'outillage de test frontend).
- **Status** : Accepted — 2026-10-13

### ADR-061 — Son des clips : volume corrigé et contrôle audio obligatoire avant publication
- **Context (2026-10-13)** : clips générés « muets », y compris le MP4 téléchargé. Mesure (FFprobe + volumedetect) : source −14,8 dB de moyenne (crête −2,0 dB), clip −54,9 dB (crête −42,0 dB) — une piste AAC présente mais atténuée de 40 dB, donc acceptée par le contrôle existant (« a une piste audio »). Cause : `ClipRenderRunner::spec` transmettait `'volume' => 1` alors que tout le moteur (FfmpegCommandBuilder, Video Studio, contrainte SQL) exprime le volume en POURCENTAGE (0-100) → filtre `volume=0.01` (−40 dB) dès la découpe FFmpeg ; Remotion conservait ensuite fidèlement ce son déjà atténué. Seule occurrence de cette erreur d'échelle dans le code.
- **Decision** :
  - `volume` = 100 (son original intact) ; aucune piste ajoutée, aucun remplacement.
  - Contrôle audio obligatoire à l'étape « validation », AVANT toute publication (`AudioCheck`) : si la source a une piste audio, le clip doit en avoir une (`AUDIO_MISSING`), la durée audio doit égaler celle de l'image à 250 ms près (`AUDIO_DESYNC`), et son niveau moyen ne doit pas être inférieur de plus de 6 dB à celui du MÊME passage de la source, ni silencieux (crête ≤ −50 dBFS) alors que la source ne l'est pas (`AUDIO_SILENT`, message avec les niveaux mesurés) ; mesure impossible → `AUDIO_CHECK_FAILED`. Clip refusé = jamais publié. Source sans piste audio ou passage réellement muet : publié et signalé (métadonnée `audio.status` = `source_without_audio` | `source_silent`, exposée par l'API et affichée sur la carte du clip). Niveaux mesurés conservés dans les métadonnées du média (`audio`) et journalisés en cas de perte (`clipper.audio_lost`).
- **Preuves réelles** (vidéo de l'utilisatrice, passage de 6 s, worker vidéo réel, rendu Remotion) : source −16,5 dB / crête −2,3 dB → découpe FFmpeg −16,5 / −2,3 (6 000 ms audio et image) → composition Remotion −16,6 / −2,3 (audio 6 016 ms, image 6 000 ms) → MP4 de la médiathèque −16,6 / −2,3 → fichier téléchargé depuis le serveur de l'application (HTTP 200, `attachment`) : AAC stéréo 48 kHz, −16,6 dB / −2,3 dB, forme d'onde continue sur les deux canaux. Test de régression vérifié en réintroduisant l'ancienne valeur : clip refusé (« −64,1 dB au lieu de −21,1 dB »).
- **Clips antérieurs** : 25 des 26 clips rendus avant ce correctif n'ont pas de contrôle audio ; mesuré sur l'un d'eux (`01a13515…`, 11:46 UTC) : −57,5 dB (inaudible). Le clip rendu après correction (`01a13583…`) : −16,6 dB.
- **Attente de 1 131 s en file (même jour)** : clip demandé à 13:24:25 UTC, pris à 13:43:16. Base : aucun autre job vidéo actif dans l'intervalle ; Redis : aucune file ni job réservé. Processus : `dev.mjs` relancé à 13:42:36 UTC, les trois `queue:listen` à 13:42:53, le `queue:work --once` qui a rendu le clip à 13:43:12. Clés de verrou du planificateur (durée de vie 1 h, une par minute d'exécution) : présentes de 13:44 à 14:29 sans interruption, absentes entre 13:31 et 13:43 alors qu'elles y seraient encore → la pile de développement (planificateur ET workers) ne tournait pas avant sa relance ; aucun worker ne pouvait prendre le job. Ajouté : `queued_ms` (attente réelle d'un rendu non encore pris) exposé par l'API et affiché sur la carte du clip, avec un avertissement explicite au-delà de 5 min (« aucun worker vidéo ne semble traiter la file ») ; `worker_pid` et `queue` dans `clipper.render_timings` / `clipper.analysis_timings`.
- **Audit par étape avec un téléchargement yt-dlp NEUF** (« Big Buck Bunny », CC-BY, `YE7VzlLtp-4`, passage 60-70 s) : 1. sortie yt-dlp AV1 + AAC stéréo 44,1 kHz, audio 596 521 ms pour 596 458 ms d'image, passage −39,7 dB (crête −17,1) ; 2. source en médiathèque identique ; 3. audio de transcription WAV 16 kHz mono 596 521 ms, −31,8 dB ; 4. découpe FFmpeg (= entrée de Remotion) H.264 + AAC 48 kHz, 10 000 ms audio et image, −39,7 dB — l'hypothèse « yt-dlp télécharge sans le son » est réfutée ; le son n'était perdu qu'à la découpe (volume 1 %). Étape Remotion de cet audit non mesurée : FFprobe a dépassé 30 s sur le fichier produit (machine saturée : rendu de l'utilisatrice, suite de tests et audit simultanés) → délai FFprobe porté à 120 s (`VIDEO_PROBE_TIMEOUT`). Remotion et MP4 final vérifiés sur les rendus réels des workers : clip 6 s (−16,6 dB, source −16,5), clips de l'utilisatrice 30 s (−12,7 / −12,6) et 51 s (−12,1 / −12,1), tous rendus par Remotion ; confirmation d'écoute de l'utilisatrice (« le son est intégré »).
- **Régénération des clips antérieurs** : `php artisan clipper:regenerate-silent-clips` (simulation par défaut ; `--execute`, `--limit=N`) mesure chaque clip publié sans contrôle audio face au même passage de la source et, pour « son perdu », lance un NOUVEAU rendu du même passage (format, sous-titres ; titre suivi de « (son rétabli) ») — original jamais modifié ni supprimé, aucun doublon en cas de relance, nouveau clip soumis au contrôle audio. Mesure du 2026-10-13 : 19 clips « son perdu » (≈ −43 dB sous la source) dans des projets existants ; 6 autres dans 3 projets supprimés par l'utilisatrice (non traités). Pilote (`01a1134b…` « Clip 30 s ») : nouveau clip `01a13660…` rendu par le worker, −24,1 dB (source mono −21,1 dB ; −3 dB par canal = conversion mono → stéréo à puissance constante), 30 s audio et image, décodage sans erreur, original conservé.
- **Limites** : les clips produits avant ce correctif restent inaudibles dans la médiathèque (18 restent à régénérer, ≈ 2 h de calcul sur le poste de développement) ; la vérification d'écoute humaine reste à faire par l'utilisatrice ; la raison de l'arrêt de la pile de développement avant 13:42 (fermeture du terminal, plantage de `dev.mjs`) n'est pas journalisée.
- **Status** : Accepted — 2026-10-13

### ADR-062 — Étape 7, P0 « vérité des écrans » : aucune statistique simulée en mode API
- **Context (2026-10-13)** : audit de l'étape 7 — tables `analytics` / `analytics_snapshots` vides, aucune collecte ; en mode API, le tableau de bord affichait des chiffres simulés (`getDashboardSummary` local) sans appeler `GET /dashboard/summary`, la page Analytics servait un jeu simulé (`mocks/analytics.ts`) et le catalogue des plateformes annonçait les statistiques « Disponible » (YouTube, Instagram, Pinterest, Threads) sans adaptateur.
- **Decision** :
  - Tableau de bord : en mode API, `GET /dashboard/summary` (comptages réels de l'utilisateur ; impressions, portée, engagement « Non disponible ») ; variations non affichées (non calculées) ; graphique remplacé par « Aucune statistique synchronisée ». Périodes : `today`, `7d`, `30d` seulement (le serveur refuse les autres).
  - Page Analytics : en mode API, état vide explicite « Aucune statistique synchronisée », réseaux réellement connectés et métriques prévues avec leur condition (« Collecte à venir »), sans sélecteur de période ; jeu simulé réservé au mode démonstration (sans API), signalé en tête de page.
  - Catalogue (`SocialPlatformSeeder`, `lib/platforms.ts`) : `account_analytics` / `post_analytics` « to_verify » partout tant qu'aucun adaptateur de collecte n'existe ; aucune autre capacité modifiée (vérifié par comparaison avant application à la base de développement).
- **Tests** : `DashboardSummaryTest` (contrat, périodes, isolation entre utilisateurs, période refusée, catalogue).
- **Status** : Accepted — 2026-10-13 (P0 seulement ; P1 et suivants à venir)

### ADR-063 — Étape 7, P1 : socle de synchronisation des statistiques
- **Context (2026-10-13)** : schéma `analytics` / `analytics_snapshots` présent mais aucune collecte ; impossible de distinguer une collecte échouée d'une métrique à 0 ou non fournie, ni de tracer la dernière synchronisation.
- **Decision** :
  - Migration additive (validée) : table `analytics_sync_states` (une ligne par compte ou publication : statut, dernière tentative, dernière réussite, code et message d'erreur nettoyés, échecs consécutifs, prochaine tentative ; unicité compte + publication). `analytics` et `analytics_snapshots` inchangées.
  - Contrat `MetricsAdapter` (statistiques de compte sur une période, statistiques d'une publication) et registre `config/analytics.php → adapters` (vide : aucun réseau collecté à ce jour) ; sans adaptateur, état `unsupported` / `NO_ADAPTER`, rien n'est simulé.
  - `MetricsNormalizer` : liste blanche des métriques, NULL = non fournie, 0 = 0, valeurs incohérentes refusées (`INVALID_METRICS`, rien d'écrit), métriques inconnues dans `extra`.
  - `AnalyticsSyncService` : verrou par compte ou publication, réponse brute + upsert des métriques en transaction (idempotent), échec = état `failed` sans toucher aux dernières valeurs valides, nouvelles tentatives différées et bornées (300 s, 30 min, 2 h, puis attente d'une synchronisation manuelle), compte déconnecté ou de démonstration jamais appelé.
  - Jobs `SyncAccountMetricsJob` / `SyncPostMetricsJob` (file `analytics`, une exécution, uniques dans la file) ; commande `analytics:sync` toutes les 15 min (collectes dues) ; `analytics:purge` quotidienne (réponses brutes > 13 mois ; métriques normalisées conservées, choix validé) ; worker de développement : file `analytics` ajoutée en dernière priorité.
  - API : `GET /api/v1/analytics` (from, to ≤ 400 jours, social_account_id, platform, post_variant_id, scope, granularity ; points + état de collecte de chaque compte ; taux d'engagement calculé à la lecture) et `POST /api/v1/analytics/sync` (compte de l'utilisateur, connecté, réseau doté d'un adaptateur ; limite `analytics-sync`). Isolation : comptes et publications d'un autre utilisateur → 404.
- **Tests** : `AnalyticsSyncTest` (12), `AnalyticsApiTest` (6) — adaptateur simulé, écritures et contraintes MySQL réelles.
- **Status** : Accepted — 2026-10-13 (socle prêt ; adaptateurs YouTube, X, LinkedIn : P2, P3)

### ADR-064 — Étape 7, P2 : statistiques YouTube (Data API v3)
- **Context (2026-10-14)** : socle P1 en place sans adaptateur ; chaîne YouTube reconnectée (scopes `youtube.upload` + `youtube.readonly`). Les séries journalières (API YouTube Analytics) exigeraient le scope `yt-analytics.readonly`, non demandé : hors périmètre, flux OAuth inchangé.
- **Decision** :
  - `App\Domain\Analytics\Adapters\YouTubeMetricsAdapter`, déclaré dans `config/analytics.php → adapters.youtube`. Lecture seule : `videos.list` (part `statistics,snippet`, champs limités) pour une publication, `channels.list?mine=true` (part `statistics`) pour le compte. Coût : 1 unité de quota par appel.
  - La Data API ne fournit que des cumuls : un point `lifetime` daté du jour de collecte (UTC) ; une collecte le même jour met le point à jour (upsert P1), le lendemain crée un nouveau point → série de cumuls quotidiens. Aucune métrique journalière dérivée ou estimée.
  - Correspondance : vidéo → `views` (viewCount), `likes` (likeCount), `comments` (commentCount) ; chaîne → `followers` (subscriberCount, null si `hiddenSubscriberCount`), `views` (viewCount), `extra.video_count` (vidéos publiques). Compteur absent (J'aime masqués, commentaires désactivés) → null, jamais 0 ; impressions, portée, engagements, clics, partages, enregistrements : non fournis → null. `subscriberCount` est arrondi par YouTube (3 chiffres significatifs).
  - Isolation : la vidéo (`snippet.channelId`) ou la chaîne (`id`) doit être celle du compte connecté (`external_account_id`), sinon `VIDEO_NOT_OWNED` / `CHANNEL_MISMATCH` sans écriture ; la publication doit appartenir au compte (`ACCOUNT_MISMATCH`).
  - Jeton : `YouTubeTokenManager` (renouvellement sous verrou ; refus de Google → compte « à reconnecter », plus aucun appel). 401 : un seul renouvellement forcé puis un seul nouvel essai, sinon `TOKEN_INVALID` définitif.
  - Erreurs → contrat P1 : `quotaExceeded`/`dailyLimitExceeded` → `QUOTA_EXCEEDED` temporaire, nouvel essai après minuit heure du Pacifique ; 429/`rateLimitExceeded` → `RATE_LIMITED` (Retry-After) ; 5xx, réseau, réponse inattendue → temporaire ; 403 → `PERMISSION_DENIED` définitif (reconnexion puis synchronisation manuelle) ; vidéo introuvable → `unsupported` / `VIDEO_NOT_FOUND` (revérifiée chaque jour) ; 400 → `INVALID_REQUEST`.
  - Réponse brute conservée dans `analytics_snapshots` (`api_version` = `youtube-data-v3`) : corps Google uniquement, jamais de jeton ; messages d'erreur sans corps brut.
- **Validation réelle (2026-10-14)** : collecte planifiée (`analytics:sync` → file `analytics` → worker) puis manuelle (`analytics:sync --account`) sur la chaîne connectée : compte + 2 vidéos `succeeded`, valeurs stockées identiques aux réponses Google, aucun jeton dans les journaux, réponses ou états. Les deux vidéos ont été créées en `private` par SoxialConnect (valeur alors imposée, voir ADR-065). Statut réel lu le 2026-10-14 (lecture seule, sans modification) : `WG2SrvOvrm4` `private` ; `pT88Xdj3M4k` `public`, rendue publique hors de SoxialConnect après sa création. Les valeurs collectées évoluent en conséquence (première collecte : 0 partout ; seconde : 1 vue, 1 J'aime, 1 commentaire, 1 abonné).
- **Tests** : `YouTubeMetricsTest` (11) — API Google simulée (`Http::fake`, aucun appel réel).
- **Status** : Accepted — 2026-10-14

### ADR-065 — Visibilité YouTube choisie dans le Composer
- **Context (2026-10-14)** : `YouTubePublisher` imposait `privacyStatus = private` (ADR-041, phase 1.5) et ignorait l'option `youtubeVisibility`, pourtant proposée par le Composer (« Publique » par défaut), validée et enregistrée. Constat réel : `pT88Xdj3M4k` demandée `public`, créée `private` (réponse YouTube conservée), sans avertissement.
- **Decision** :
  - `privacyStatus` = `youtubeVisibility` validée par liste blanche stricte (`public`, `unlisted`, `private`, comparaison exacte) ; absente ou invalide → `private` (`YouTubePublisher::DEFAULT_VISIBILITY`), jamais public implicite. Même chemin pour la publication immédiate et programmée (options lues à l'exécution : la dernière valeur enregistrée avant l'échéance part).
  - Visibilité appliquée par YouTube (`status.privacyStatus` de la ressource créée) comparée à la demande : `response_excerpt` = `privacy_requested`, `privacy_status`, `privacy_mismatch`. Écart ou visibilité non confirmée : la publication reste réussie, avertissement `VISIBILITY_MISMATCH` (clé `type` dans l'extrait, la clé `code` étant masquée par `SecretRedactor`), journal `publishing.youtube_visibility_mismatch` (identifiant de variante, valeurs demandée/appliquée, aucun jeton).
  - API : `PublicationAttemptResource.warnings` (code + message seulement, jamais l'extrait brut) ; frontend : avertissement dans « Historique des envois », aide sous le sélecteur « Visibilité », texte du catalogue corrigé.
  - Aucune migration ; aucune vidéo existante modifiée ; les autres réseaux ne sont pas concernés. Une ancienne variante non publiée (ex. échec) relancée part désormais avec sa visibilité enregistrée (souvent « Publique », défaut du Composer).
- **Limite Google** : les vidéos envoyées par `videos.insert` depuis un projet Google Cloud non audité (créé après le 28 juillet 2020) sont limitées à « Privée ». Dans ce cas YouTube renvoie `private` : l'écart est signalé, pas corrigé. Seuls l'audit du projet (YouTube API Services) ou une modification dans YouTube Studio (si la vidéo n'est pas verrouillée) rendent la vidéo publique. Non vérifié en réel : aucune publication publique de test n'a été faite (accord distinct requis).
- **Tests** : `YouTubePublishingTest` (24, dont 8 nouveaux : trois visibilités, absente/invalide, madeForKids, écart et non-confirmation, programmation avec modification avant échéance, anciennes tentatives).
- **Status** : Accepted — 2026-10-14

### ADR-066 — Publication des vidéos depuis la médiathèque
- **Context (2026-10-14)** : le Clipper menait au Composer avec la vidéo du clip ; la page Médias ne proposait qu'un bouton « Utiliser dans une publication » dans l'aperçu, rien dans la sélection. Le Composer publiait sans récapitulatif et le suivi par réseau n'apparaissait qu'en rouvrant la publication.
- **Decision** :
  - Aucun second moteur : Médias (barre de sélection et aperçu, « Publier sur les réseaux ») ouvre le Composer avec `state.mediaIds`, comme le Clipper. Sélection publiable (`lib/mediaPublishing.ts`) : images et vidéos prêtes hors quarantaine, 20 au plus (limite serveur), ordre conservé ; sinon bouton désactivé avec la raison. Le serveur revérifie propriété, statut et analyse (`SavePostRequest`).
  - Composer : récapitulatif obligatoire avant « Publier maintenant » et « Programmer » (`PublishRecapDialog`, `composer/recap.ts`) — réseaux, comptes, média (lecteur vidéo pour une vidéo seule), texte par réseau, visibilité **réellement transmise** (YouTube sans choix : « Privée (par défaut) », comme le serveur ; le sélecteur affiche désormais `private` au lieu de `public` quand l'option est absente). Garde synchrone contre le double clic (`useRef`) en plus des verrous serveur (version du post, 409).
  - Après confirmation : la liste (publication) ou le calendrier (programmation) s'ouvre sur le détail de la publication (`useOpenedPost`) : état par réseau, rafraîchi pendant l'envoi, « Réessayer » ne relance que les variantes en échec.
  - Tests frontend sans nouvelle dépendance : `npm test` = `node --test` (TypeScript natif de Node 24) sur la logique pure (`frontend/tests/`).
- **Tests** : `MediaLibraryPublishingTest` (8), `frontend/tests` (14).
- **Status** : Accepted — 2026-10-14

### ADR-067 — Étape 7, P3 : statistiques X et LinkedIn
- **Context (2026-10-14)** : socle P1 + YouTube (P2). X : scopes `tweet.read` / `users.read` déjà accordés, mais lectures facturées (paiement à l'usage) et compte développeur ayant répondu 402 à la publication. LinkedIn : statistiques de profil via `memberCreatorPostAnalytics`, scope `r_member_postAnalytics` (produit Community Management API, approbation LinkedIn requise) absent du jeton (`w_member_social` seul).
- **Decision** (choix validés par l'utilisatrice) :
  - `XMetricsAdapter` (API v2, lecture seule) : compte `GET /2/users/me` (`followers_count` → followers ; abonnements, posts, listes dans `extra`) ; post `GET /2/tweets/{id}` (`public_metrics` : impressions, J'aime, réponses → comments, reposts → shares, signets → saves, citations dans `extra` ; `non_public_metrics` pour un post de moins de 30 jours : clics de lien → clicks, engagements, clics de profil dans `extra`). Auteur ≠ compte connecté → `POST_NOT_OWNED` ; 402 / `client-not-enrolled` → `API_ACCESS_DENIED` définitif ; 401 → un renouvellement forcé puis `TOKEN_INVALID` (compte à reconnecter) ; 429 → nouvel essai après `x-rate-limit-reset`.
  - **X inactif par défaut** : déclaré seulement si `ANALYTICS_X_ENABLED=true` ; sinon `NO_ADAPTER`, aucun appel ni manuel ni planifié. Activation après accord et vérification des crédits X.
  - `LinkedInMetricsAdapter` (déclaré) : vérifie d'abord le scope du jeton ; sans `r_member_postAnalytics`, `unsupported` / `PERMISSION_REQUIRED` **sans aucun appel**, revérifié chaque jour. Avec le scope : Rest.li 2.0, `Linkedin-Version` = `ANALYTICS_LINKEDIN_API_VERSION` (202609), une requête par métrique en agrégation TOTAL — publication (`q=entity`, `share`/`ugc`) : impressions, membres atteints → reach, réactions → likes, commentaires, partages, enregistrements, clics ; compte (`q=me`) : impressions et reach seulement (réactions/commentaires/partages agrégés signalés incohérents par LinkedIn) ; abonnés : autre autorisation → null. 403 → `PERMISSION_REQUIRED` ; 426 / `NONEXISTENT_VERSION` → `API_VERSION_UNSUPPORTED`.
  - Connexions OAuth X et LinkedIn **inchangées** ; publisher YouTube inchangé. Cumuls : point `lifetime` daté du jour, valeur absente → null.
- **Validation réelle (2026-10-14)** : configuration effective `x` = null (aucun état, aucun appel), LinkedIn → compte et publication `unsupported` / `PERMISSION_REQUIRED` déterminés localement (aucun appel). Aucune collecte réelle X ni LinkedIn : accès externes requis (crédits X ; approbation LinkedIn puis reconnexion).
- **Tests** : `XMetricsTest` (9), `LinkedInMetricsTest` (7) — API simulées.
- **Status** : Accepted — 2026-10-14 (collecte réelle en attente des accès X et LinkedIn)

### ADR-068 — Étape 7, P4 : page Analytics alimentée par les données réelles
- **Context (2026-10-14)** : en mode API, la page Analytics affichait un état d'attente (P0). Données réelles disponibles : points YouTube (chaîne + vidéos) via `GET /analytics`, état de collecte par compte. Manque constaté et signalé avant modification : un point de publication ne portait que `post_variant_id` (ni titre, ni lien, ni date).
- **Decision** :
  - Contrat backend **additif** (validé par l'utilisatrice, sans migration) : `GET /analytics` renvoie aussi `posts` — pour chaque publication présente dans les points : `post_variant_id`, `post_id`, `social_account_id`, `platform`, `title` (titre propre au réseau, sinon titre de la publication, sinon sa première ligne), `external_url`, `published_at`. Limité aux comptes de l'utilisateur ; champs existants inchangés.
  - Frontend (mode API) : `LiveAnalytics` + `useLiveAnalytics` → `GET /analytics` uniquement ; filtres réellement pris en charge par l'API : période de collecte (7, 30, 90 jours → `from`/`to`) et réseau (`platform`) ; actualisation manuelle (relecture, aucune collecte déclenchée) ; chargement, erreur avec message serveur, états vides explicites.
  - Affichage : dernier relevé cumulé par compte et par publication (date du relevé indiquée), colonnes limitées aux métriques fournies par au moins un relevé (YouTube : vues, J'aime, commentaires ; chaîne : vues, abonnés) ; null → « Non disponible », jamais 0 ; aucune courbe, variation ni pourcentage inventé.
  - État par compte déduit **uniquement** de l'état serveur (`lib/analyticsView.ts`) : `succeeded` → Données disponibles ; `pending`/`running` → Collecte en attente / en cours ; codes jeton/connexion → Reconnexion requise ; `PERMISSION_REQUIRED`/`PERMISSION_DENIED` → Autorisation requise ; autre `failed` → Erreur de synchronisation (message serveur, dernières valeurs conservées) ; `NO_ADAPTER` → Collecte non disponible (X : « Collecte désactivée », lecture payante) ; `DEMO_ACCOUNT` → Compte de démonstration.
  - Mode démonstration conservé (jeu simulé signalé) ; le module simulé n'est plus importé statiquement : chargé à la demande, jamais en mode API. `AnalyticsNotSynced` (P0) retiré.
- **Limites** : états de collecte exposés par compte seulement (l'erreur d'une publication isolée, ex. `VIDEO_NOT_FOUND`, n'apparaît qu'avec le filtre `post_variant_id`) ; pas de bouton « synchroniser maintenant » (collecte externe non demandée) ; le tableau de bord garde ses métriques d'audience « Non disponible » (hors périmètre).
- **Tests** : `AnalyticsApiTest` (+2 : bloc `posts` et isolation, YouTube null + filtre réseau ; `FakeMetricsAdapter` sorti dans son propre fichier pour que le test passe seul), `frontend/tests/analyticsView.test.ts` (13).
- **Validation navigateur (2026-10-14)** : Chrome réel piloté (protocole DevTools, profil temporaire, connexion faite par l'utilisatrice) sur la base de dev : 5 relevés YouTube, titres, dates et liens conformes à la base ; états YouTube / LinkedIn / X / Telegram conformes ; filtres période et réseau (bornes envoyées vérifiées) ; « Actualiser » = un seul GET, aucune synchronisation ; états chargement, erreur (requête bloquée) + « Réessayer », vide ; 390 px sans débordement (tableau défilant). Défaut corrigé : libellé de période tronqué (libellés raccourcis, sélecteur à largeur automatique). Aucune erreur JavaScript ; module simulé Analytics jamais chargé.
- **Status** : Accepted — 2026-10-14

### ADR-069 — Publication multi-comptes et multi-destinations
- **Context (2026-10-18)** : audit. Le serveur gérait déjà plusieurs comptes par réseau (unicité utilisateur + plateforme + identifiant externe ; actions `Connect*Account` qui retrouvent ou créent le compte, jamais d'écrasement ; Google `prompt=consent select_account`) et une publication vers plusieurs destinations (`targets[]` ≤ 20, une variante par compte : statut, erreurs, tentatives, identifiant distant, job et verrou propres ; relance limitée aux échecs ; planificateur par variante). Le Composer, lui, ne gardait **qu'un compte par réseau** (`VariantDraft.accountId`) et `fromPost` fusionnait les variantes d'un même réseau : modifier une publication visant deux chaînes en supprimait une à l'enregistrement.
- **Decision** (aucune migration) :
  - Composer : `VariantDraft.accountIds` (cases à cocher par compte, « Tout sélectionner » / « Aucun », comptes à reconnecter affichés et nommés) ; texte, format et options partagés par réseau ; `toDraftInput` crée une destination par compte coché ; `fromPost` conserve tous les comptes d'un réseau et `divergentPlatforms` prévient si leurs contenus diffèrent ; validation par destination (aucun compte coché, compte introuvable, compte non connecté nommé) ; onglet « ×N » ; récapitulatif d'une ligne par destination.
  - Détail d'une publication : « Statut par destination » avec le nom du compte, lien vers la publication distante en mode API, compte de chaque tentative.
  - « Ajouter un autre compte » : consigne propre au réseau (Google : sélecteur de compte et de chaîne ; LinkedIn, X, TikTok : compte ouvert dans le navigateur, à changer ou fenêtre privée) ; un compte déjà connecté qui revient est mis à jour, jamais remplacé.
  - Serveur : publication immédiate refusée en **nommant** les comptes non connectés (au lieu de « un des comptes »).
- **Limites** : un contenu par réseau (pas de texte différent par compte d'un même réseau) ; LinkedIn = profils personnels seulement (pages d'entreprise : Community Management API) ; quotas fournisseurs partagés par tous les comptes d'un même projet (YouTube : 100 `videos.insert` par jour et par projet) ; X 402 ; TikTok non audité : 5 utilisateurs / 24 h et contenu privé.
- **Tests** : `MultiDestinationPublishingTest` (6), `YouTubeOAuthTest` + `LinkedInOAuthTest` (+1 chacun), `frontend/tests/composerDestinations.test.ts` (11), `publishRecap.test.ts` adapté.
- **Status** : Accepted — 2026-10-18

### ADR-070 — Étape 8, lot 1 : expurgation des journaux et isolement des logs de test
- **Context (2026-10-18)** : audit de sécurité. Un jeton de bot Telegram figurait en clair dans `sociopulse.json-2026-10-05.log` (URL de la Bot API dans le message d'une exception, version du client corrigée depuis). Cause de fond : `SecretRedactor` ignorait les objets `Throwable` du contexte (le formateur écrivait leur message et leur pile en clair) et ne connaissait pas les jetons placés dans un chemin d'URL ; `failed_jobs.exception` n'était pas expurgé ; plusieurs canaux n'avaient pas le processeur ; la suite de tests écrivait dans `laravel.log` (107 Mo, sans rotation).
- **Decision** :
  - `SecretRedactor` : exceptions (et jusqu'à 3 précédentes) converties en tableau expurgé ; nouveaux motifs (bot Telegram, jetons Google, JSON sérialisé — sans masquer les codes d'erreur d'API —, en-têtes secrets, signatures d'URL).
  - `RedactingFailedJobProvider` (décorateur de `queue.failer`) : exception expurgée avant écriture, payload intact (rejouable).
  - `config/logging.php` : `daily` par défaut ; processeur sur tous les canaux qui écrivent ; canal `testing` (fichier séparé, 3 jours) utilisé par `phpunit.xml`.
- **Non résolu (restrictions de l'utilisatrice)** : le jeton du bot reste valide et présent dans l'ancien fichier de log (ni révocation ni suppression) ; `laravel.log` (anciens logs de tests) conservé ; le canal `emergency` de Laravel (secours si la journalisation échoue) n'a pas de processeur.
- **Tests** : `tests/Unit/SecretRedactorTest.php` (+3), `tests/Feature/Security/LogRedactionTest.php` (5 : fichiers texte et JSON réellement écrits, rapport d'exception, client Telegram, failed_jobs, canaux).
- **Status** : Accepted — 2026-10-18

### ADR-071 — Étape 8, lot 4 : quotas de stockage, de clipping et garde d'espace disque
- **Context (2026-10-18)** : audit de sécurité — aucun quota par utilisateur (uploads de 1 Go à 30/h, acquisitions jusqu'à 4 Go, archives Google jusqu'à 20 Go, projets de clipping illimités) ; seule vérification d'espace disque : yt-dlp.
- **Decision** (aucune migration ; état dérivé de la base + réservations en cache Redis, sous verrous) :
  - `StorageQuota` : occupation = originaux + dérivés de tous les médias de l'utilisateur (quarantaine, traitement, générés — clips, images IA, sources acquises —, supprimés logiquement tant que la purge n'a pas libéré les fichiers) + réservations en cours. Vérification + réservation sous verrou par utilisateur ; libération à l'enregistrement du média ou en cas d'échec ; expiration automatique (240 min) d'une réservation oubliée. Appliqué dans `UploadMedia` (uploads, sources acquises, images IA) et au démarrage des rendus.
  - `ClippingQuota` : projets actifs déduits de l'état réel (import, analyse, transcription, détection, rendu, ou prêt avec un clip en rendu ; un projet sans activité depuis 240 min ne compte plus) — échec, annulation ou fin libèrent la place sans compteur ; créations sur 24 h glissantes (suppressions comprises). Vérifié sous verrou par utilisateur à la création (avant oEmbed et tout job) et aux relances (rendu de clip, réanalyse, import d'un fichier, nouvelle acquisition) ; un projet déjà actif ne consomme pas de place supplémentaire.
  - `DiskGuard` : réservation globale sous verrou ; l'opération n'est lancée que s'il reste `min_free_mb` après elle et les opérations en cours, avec `margin_percent` de marge. Appliqué aux uploads, acquisitions (2 × taille maximale), archives Google (taille annoncée, sinon 4 Go), analyses (512 Mo) et rendus (2 Go).
  - Acquisition : quota restant ≥ 50 Mo exigé avant tout fournisseur ; taille maximale téléchargée (yt-dlp `--max-filesize`) plafonnée par le quota restant ; erreurs de quota à l'import → source en échec explicite.
  - Traitements (`ClipRenderRunner`, `ClipAnalysisRunner`, `RenderVideoJob`) : réservations prises AVANT FFmpeg, whisper, Remotion ; sinon échec propre (`STORAGE_QUOTA_EXCEEDED`, `DISK_SPACE_LOW`) ; libération dans `finally`.
  - HTTP : 413, 429 (Retry-After), 507 (Retry-After) ; le SPA affiche le message serveur d'une 507 (seule modification d'interface).
- **Limites** : quota identique pour tous les utilisateurs (pas d'offres) ; les fichiers temporaires hors médias (dossiers de travail) relèvent de la garde disque, pas du quota individuel ; la taille réelle d'un rendu n'est connue qu'à la fin (réservation forfaitaire de 300 Mo) ; la concurrence est protégée par des verrous Redis (un seul Redis — en multi-serveurs, tous doivent partager le même cache) ; tests de concurrence simulés par réservations (PHPUnit ne lance pas de requêtes parallèles).
- **Tests** : `tests/Feature/Security/QuotaTest.php` (17).
- **Status** : Accepted — 2026-10-18

### ADR-072 — Étape 8, lot 3 : contrôle de configuration de production
- **Context (2026-10-18)** : audit de sécurité — `SESSION_SECURE_COOKIE` sans valeur par défaut (cookie de session sans `Secure` si la variable est oubliée), aucun contrôle automatisé de la configuration de production, GD absente du PHP CLI du poste (images stockées sans réencodage : EXIF/GPS conservés), et `TRUSTED_PROXIES` lu par `env()` dans `bootstrap/app.php` — après `php artisan config:cache`, `env()` n'y renvoie plus rien : derrière un reverse proxy, IP clientes et HTTPS ne seraient plus reconnus.
- **Decision** :
  - `config/session.php` : `secure` vaut `true` par défaut quand `APP_ENV=production` (la variable reste prioritaire).
  - Proxys de confiance : `config/security.php → trusted_proxies` + middleware `TrustConfiguredProxies` (remplace `TrustProxies`, lu à chaque requête, compatible `config:cache`, « * » toujours refusé).
  - `php artisan security:check-production [--strict] [--json]` (`ProductionReadiness`) : 28 contrôles — APP_ENV, APP_DEBUG, APP_KEY, niveau de journal, HTTPS (APP_URL, FRONTEND_URL), cookie de session (Secure, HttpOnly, SameSite, chiffrement, stockage), CORS (origines HTTPS explicites, sans `*` ni localhost), Sanctum (pas de localhost ni de tunnel, domaine du SPA), proxys, clé des jetons (32 octets, distincte d'APP_KEY), secret du webhook Telegram, files, cache Redis, e-mails, antivirus (obligatoire et disponible), GD et **réencodage réel** d'un JPEG contenant un bloc EXIF. Code 1 en cas d'échec ; aucune valeur secrète affichée ; aucune modification.
  - `backend/.env.production.example` : modèle de production sans secret.
- **Limites** : la commande vérifie le PHP **CLI** ; PHP-FPM peut avoir un autre `php.ini` (GD à vérifier aussi côté serveur web) ; l'antivirus reste en échec tant que le pilote ClamAV (lot 6) n'existe pas ; sur le poste de dev, GD est désactivée dans `C:\Program Files\php\php.ini` (`;extension=gd`, ligne 941) — non modifié.
- **Tests** : `tests/Feature/Security/ProductionCheckTest.php` (9).
- **Status** : Accepted — 2026-10-18

### ADR-073 — Étape 8, lots 5, 6 et 7 : authentification, antivirus, défense en profondeur (et fin du lot 1)
- **Context (2026-10-18)** : audit de sécurité — 2FA non activée alors que l'application détient des jetons OAuth ; connexion limitée par adresse + IP seulement (credential stuffing freiné par la seule limite générale) ; aucun antivirus réel (`NullMediaScanner` : en production, `MEDIA_SCAN_REQUIRED` refuserait tous les imports) ; `-protocol_whitelist file` absent de 4 appels FFmpeg/FFprobe ; aucune limite de taille des corps JSON ; tâches planifiées à portée globale sans `onOneServer` ; journal de secours (`emergency`) non expurgé ; test anti-doublon du Clipper dépendant de la vitesse de la machine.
- **Decision** :
  - **Lot 5 — authentification** :
    - 2FA TOTP de Fortify (confirmée par un premier code). Routes redéclarées sous `/api/v1/auth` (défi `two-factor-challenge`, gestion `user/two-factor-*`, `user/confirm-password`, `user/confirmed-password-status`).
    - Toute la gestion de la 2FA exige une confirmation du mot de passe de moins de 3 h (`password.confirm` → 423).
    - Codes TOTP non rejouables, codes de secours à usage unique, secrets chiffrés au repos. Aucune migration : les colonnes existaient.
    - SPA : étape « code » à la connexion et panneau Sécurité (QR code affiché comme image, clé manuelle, codes de secours, désactivation).
  - **Lot 5 — limites de connexion** :
    - par adresse + IP : 5/min et 20/h (verrouillage progressif) ;
    - par IP, toutes adresses confondues : 20/min et 100/h (credential stuffing) ;
    - code 2FA : 5/min.
    - Pas de limite par adresse seule : un tiers pourrait bloquer la victime.
    - Hachage factice quand l'adresse est inconnue : le temps de réponse ne révèle pas l'existence du compte.
  - **Lot 6 — antivirus** :
    - `ClamAvScanner` : démon clamd, protocole INSTREAM natif (TCP ou socket Unix), aucune dépendance. Pilote `MEDIA_SCAN_DRIVER=clamav`, réglages `CLAMAV_*`.
    - Échec fermé : démon injoignable, réponse inattendue, délai, limite de taille ou fichier illisible donnent `error`, jamais `clean`.
    - Analyse avant tout stockage ; fichier infecté refusé, audité, jamais stocké.
    - `media:scan-pending [--dry-run]` analyse les médias importés sans moteur. Rien n'est supprimé : un média infecté reste stocké, marqué `infected`, donc jamais servi ni publiable.
  - **Lot 7 — défense en profondeur** :
    - `-protocol_whitelist file` sur toutes les exécutions FFmpeg/FFprobe (vérifié par analyse du code, et en réel avec une playlist HLS piégée : aucune connexion).
    - `LimitJsonPayload` : 413 au-delà de `API_MAX_JSON_KB` (1 Mo), sans lire le corps quand `Content-Length` l'annonce.
    - `onOneServer` sur les tâches à portée globale. Les purges de fichiers temporaires et la vérification des binaires restent locales à chaque serveur.
  - **Lot 1 (fin)** :
    - `RedactingLogManager` : le journal de secours passe lui aussi par l'expurgation, dans un fichier séparé `storage/logs/emergency.log`.
    - Tests ajoutés pour les erreurs du client HTTP (URL, en-têtes, corps) et pour la rotation.
  - **Lot 3 (compléments)** : `security:check-production` compte désormais 33 contrôles, dont la version PHP, les extensions requises, Argon2id, et exif et OPcache en avertissement. `.env.production.example` passe à predis (dépendance du projet ; plus de `phpredis` absent) et prend en compte ClamAV.
  - **Tests** :
    - `ClipperCreateDedupeTest` : horloge figée, fenêtre réelle de 10 s inchangée ; vérification que le verrou est réellement pris (attente simulée avec `Sleep::fake`).
    - Les tests GD s'exécutent avec `PHP_INI_SCAN_DIR=backend/dev` (`php-dev.ini`, sans modifier le `php.ini` système).
- **Limites** :
  - L'énumération par l'inscription (422 « adresse déjà utilisée ») est conservée. La neutraliser changerait le parcours d'inscription (connexion immédiate) ; elle est freinée par la limite `auth-sensitive` (3/min, 20/h par IP).
  - `password.confirm` ne protège que la 2FA. La déconnexion d'un réseau, la suppression de compte et le changement d'adresse ne sont pas encore concernés (suppression et changement d'adresse : non implémentés).
  - Le test EICAR contre un vrai clamd ne s'exécute que si `CLAMAV_TEST_HOST` est défini (CI, Docker). En local, le faux démon parle le protocole réel.
  - L'antivirus du poste de développement a mis en quarantaine deux versions intermédiaires des fichiers de test qui contenaient la chaîne EICAR encodée ; elle n'apparaît plus dans aucun fichier du dépôt.
  - clamd refuse un fichier chiffré ou trop gros pour être analysé entièrement (`AlertEncrypted`, `AlertExceedsMax`) : un PDF protégé par mot de passe est refusé.
- **Tests** :
  - `Auth/TwoFactorAuthenticationTest` (6) et `Auth/LoginProtectionTest` (4) ;
  - `Security/ClamAvScannerTest` (7, dont 1 avec un vrai clamd) ;
  - `Security/FfmpegProtocolWhitelistTest` (2) et `Security/DefenseInDepthTest` (4) ;
  - `Security/LogRedactionTest` (+3) ;
  - `Clipper/ClipperCreateDedupeTest` (4, réécrit).
- **Status** : Accepted — 2026-10-18

### ADR-074 — Préparation du déploiement Docker (non déployé)
- **Context (2026-10-18)** : la cible de production est Docker sur un VPS. Aucun fichier de déploiement n'existait.
- **Decision** (`deploy/docker/`, procédure détaillée dans `docs/deployment-docker.md`) :
  - **Images** : `backend.Dockerfile` en deux cibles.
    - `app` : PHP-FPM 8.3. L'API n'exécute jamais de binaire (ADR-060).
    - `worker` : PHP CLI, FFmpeg et FFprobe (Debian), yt-dlp 2026.08.19 (empreinte vérifiée), whisper.cpp v1.9.4 compilé avec le modèle `base` (empreinte vérifiée), Node 24, renderer Remotion et navigateur headless téléchargé à la construction.
    - `web.Dockerfile` : SPA compilée (`VITE_API_URL=same-origin`) servie par Nginx sans privilèges, qui relaie l'API en FastCGI sur le **même domaine**.
  - **Services** (`compose.yaml`) :
    - `web` est le seul à publier des ports (80/443) ;
    - `app`, puis 3 workers (`default`, `ai`, `video` : un rendu à la fois, 3 Go, `shm` 1 Go) et `scheduler`, qui utilise l'image worker pour vérifier les binaires locaux ;
    - `mysql` 8.4, `redis` 7.4 (mot de passe, AOF, `noeviction`) et `clamav` 1.4, sur un réseau `internal` sans accès Internet. ClamAV a aussi une sortie pour ses mises à jour.
  - **Isolation** : utilisateur `www-data`, système de fichiers en lecture seule, `cap_drop: ALL`, `no-new-privileges`.
  - **Données** : volumes persistants (`storage/app`, journaux, MySQL, Redis, base ClamAV). Le `.env` Laravel est monté en lecture seule, jamais copié dans une image. Les caches de configuration sont construits au démarrage.
  - **Arrêt et santé** : arrêt propre des workers (SIGTERM et `pcntl` ; 31 min pour un rendu en cours) ; healthchecks (FPM ping, Nginx, MySQL, Redis, clamd).
  - **Sauvegarde** : `scripts/backup.sh` (`mysqldump --single-transaction` puis fichiers, chiffrés avec age, rétention) et `scripts/restore.sh`, avec confirmation et vérification des empreintes.
  - **Nginx** : TLS 1.2/1.3, HSTS, CSP stricte du SPA, corps limités à 2 Mo (1,1 Go pour `/api/v1/media`), limites de débit en amont, en-têtes `X-Forwarded-*` du client neutralisés.
- **Validé** :
  - `docker compose config`, avec des valeurs factices : 9 services ; variables obligatoires manquantes refusées ; seuls 80/443 publiés ; réseau `internal` sans accès Internet.
- **Non validé** :
  - construction des images et démarrage des conteneurs : moteur Docker non démarré sur le poste (0,2 Go de RAM libre) ;
  - existence des étiquettes d'images figées (php 8.3.35, composer 2.10.3, node 24.22.0, nginx 1.28.2, mysql 8.4.11, redis 7.4.11, clamav 1.4.6) : présence vérifiée sur Docker Hub le 2026-10-19 (les étiquettes initiales étaient anciennes et `clamav 1.4.2` n'existait plus). La construction reste à faire (la CI construit `app` et `web`).
- **Ressources** :
  - au repos : ≈ 5 à 6 Go ;
  - en pointe : ≈ 10 Go de limites cumulées (ClamAV ≈ 1,5 Go, Chrome de Remotion ≈ 2 Go par rendu) ;
  - VPS conseillé : 4 vCPU et 16 Go (8 Go au strict minimum, sans marge pour un rendu et l'antivirus en même temps).
- **Status** : Accepted (préparation) — 2026-10-18

### ADR-075 — Intégration continue et détection de secrets
- **Decision** : `.github/workflows/ci.yml`, qui échoue dès qu'une étape obligatoire échoue.
  - **secrets** : gitleaks 8.28.0 (empreinte vérifiée), sur tout l'historique et l'arbre de travail ; contrôle des modèles `.env`.
  - **backend** : MySQL, Redis et ClamAV réels, FFmpeg, PHP 8.3 avec les extensions de l'image ; `composer audit` ; PHPUnit hors groupes `remotion` et `whisper` (binaires absents) ; EICAR réel.
  - **frontend** : `tsc`, lint (au plus l'avertissement existant), build, `npm test`, `npm audit --omit=dev`.
  - **renderer** : `npm audit --omit=dev`.
  - **docker** : `compose config` et construction des images `app` et `web`.
  - `.gitleaks.toml` : règles par défaut ; exemption par chemin pour les tests (faux secrets volontaires) et les modèles `.env`, ces derniers vérifiés à part. La syntaxe `targetRules` n'est pas appliquée par gitleaks 8.28 (constaté).
- **Dépendances** :
  - correction non cassante (`npm audit fix`, sans `--force`) : `source-map-js` 1.2.1 → 1.2.2 et `shadcn` 4.21.0 → 4.22.1 (CLI de développement) ;
  - non corrigé : `braces` (toutes versions vulnérables) via la CLI `shadcn`, outil de génération jamais embarqué dans le bundle. Seul `--force` le supprimerait, en rétrogradant shadcn en 1.0.0.
- **Status** : Accepted — 2026-10-18

### ADR-076 — Rotation contrôlée de TOKEN_ENCRYPTION_KEY et revue de préproduction
- **Context (2026-10-19)** : `TOKEN_ENCRYPTION_KEY` du développement a été exposée (ADR-070). La procédure de rotation reposait sur une commande de rechiffrement absente (lot 2 de l'audit). Les étiquettes d'images Docker n'avaient pas été vérifiées.
- **Decision** :
  - **Commande `security:reencrypt-tokens [--dry-run]`** :
    - comptage par version et vérification que tout est déchiffrable, sans modification ;
    - rechiffrement ligne par ligne sous le verrou `social-token-refresh:{compte}`, avec une garde optimiste sur la valeur stockée ;
    - aucune valeur affichée ;
    - code 1 si un jeton est illisible ou a été modifié pendant l'opération.
  - **Procédure** : sauvegarde, compatibilité par `TOKEN_ENCRYPTION_PREVIOUS_KEYS`, rechiffrement, validation, retrait différé de l'ancienne clé, retour arrière. Elle est décrite dans `docs/secrets-remediation.md` et n'a pas été exécutée sur des données réelles.
  - **Images Docker** : passage aux dernières versions correctives présentes sur Docker Hub : php 8.3.35, composer 2.10.3, node 24.22.0, nginx-unprivileged 1.28.2, mysql 8.4.11, redis 7.4.11. `clamav/clamav:1.4.2` n'existe plus et devient 1.4.6.
  - **Documentation** : avertissement contre `docker compose down -v` et la suppression des volumes. Le renouvellement TLS par le volume `acme-challenge` reste à outiller.
  - **Empreintes** : toutes les images de base et de service sont figées par `étiquette@sha256` ; liste dans `deploy/docker/image-digests.txt`. `scripts/check-image-digests.sh` signale une étiquette republiée ou une empreinte disparue ; il est exécuté en CI.
  - **CI Docker** : construction des **trois** images (`app`, `worker`, `web`) ; vérification des extensions PHP, de FFmpeg, FFprobe, Node, yt-dlp, whisper et du renderer, de l'utilisateur non root ; `nginx -t` avec un certificat auto-signé jetable.
  - **TLS** : service `certbot` v5.8.0 (renouvellement webroot toutes les 12 h, hook de permissions pour Nginx, uid 101). Nginx lit `LETSENCRYPT_DIR` en lecture seule et se recharge toutes les 6 h. Première émission manuelle (`docs/deployment-docker.md` §5). Aucune validation n'a été faite sur un vrai domaine.
  - **Procédures** : migration et retour arrière (§11), webhook Telegram de production (§12), inventaire et stockage des secrets (§13).
- **Tests** : `Security/TokenRotationTest` (4) : rotation complète, clé oubliée détectée sans aucune modification, ligne en cours de rafraîchissement laissée intacte puis reprise idempotente, retour arrière.
- **Status** : Accepted — 2026-10-19

### ADR-077 — Compatibilité FFmpeg 5.1 à 9 et images Docker sur Debian trixie
- **Context (2026-10-19)** : première CI (commit `b934e79`) en échec.
  - **Backend** : 16 tests de rendu réel échouent (`FFMPEG_FAILED`). Le moteur passe `-/filter_complex fichier`, syntaxe apparue avec FFmpeg 7.0, alors que le runner Ubuntu 24.04 fournit FFmpeg 6.1.
  - **Docker** : l'image `worker` (Debian bookworm) embarque FFmpeg 5.1.9, que confirme le journal de la CI. La production n'aurait donc pu rendre aucune vidéo ; `text_align` (sous-titres centrés) demande en plus FFmpeg 6.1.
  - Le contrôle du contenu des images masquait par ailleurs la sortie de `whisper-cli`, ce qui empêchait d'identifier la vérification en échec.
  - FFmpeg 9 (poste de développement) a **supprimé** `-filter_complex_script` : aucune syntaxe unique ne convient à toutes les versions.
- **Decision** :
  - **B — Code** :
    - `FfmpegBinary::version()` lit la ligne de version déjà vérifiée et mémorisée par `ExecutableCheck` ;
    - `FfmpegCommandBuilder::forVersion()` choisit `-/filter_complex` à partir de FFmpeg 7 et `-filter_complex_script` en dessous, et n'ajoute `text_align` qu'à partir de la 6.1 ;
    - version illisible ou inconnue : syntaxe actuelle ;
    - `FfmpegVideoRenderer` transmet la version au constructeur. Rien d'autre ne change dans la commande (test dédié).
  - **A — Docker** :
    - bases `php:8.3.35-{fpm,cli}-trixie`, `debian:trixie-slim` et `node:24.22.0-trixie-slim`, figées par empreinte : FFmpeg 7.1.5 ;
    - dépendances de Chrome renommées dans trixie (`libatk1.0-0t64`, `libatk-bridge2.0-0t64`, `libcups2t64`, `libasound2t64`), noms vérifiés sur packages.debian.org ;
    - `install-extensions.sh` conserve les bibliothèques d'exécution d'après les dépendances réelles des extensions (méthode des images officielles), sans nom propre à une version de Debian, et échoue si une extension ne se charge pas.
  - **D — CI** : `scripts/check-image-contents.sh`, lançable aussi sur le serveur. Chaque vérification affiche « OK » ou « ÉCHEC » avec la sortie réelle de la commande, toutes sont exécutées, et le script rend le code 1 s'il y a au moins un échec. Vérifications :
    - **app** : extensions, Argon2id, GD, `php-fpm -t`, uid 33 ;
    - **worker** : FFmpeg 7 ou plus, encodeurs et filtres, mini-rendu réel avec la syntaxe du code, Node, yt-dlp, bibliothèques et démarrage de `whisper-cli`, modèle, renderer, Chrome headless (bibliothèques et `--version`), propriété du bundle, uid 33 ;
    - **web** : Nginx, utilisateur non root.

    Un essai à blanc local (faux `docker`) a révélé deux faux « OK » dans la première version du script : `ldd` en échec non détecté, code de retour de `head` au lieu de la commande. Ils sont corrigés.
- **Tests** :
  - `Video/FfmpegCommandBuilderTest` (+9) : syntaxe pour 8 lignes de version (5.1.9, 6.0, 6.1.1, 7.1.5, n7.1, 9.0.2, compilation N-…, inconnue) ; seule la syntaxe varie ;
  - `Video/FfmpegVersionCompatibilityTest` (2, FFmpeg réel) : la version réelle produit une vidéo valide ; une version 6.1 annoncée bascule sur `-filter_complex_script`, que FFmpeg 9 rejette (message réel journalisé).
  - La syntaxe ancienne est **exécutée** par la CI (Ubuntu, FFmpeg 6.1). La syntaxe actuelle l'est sur le poste (FFmpeg 9) et dans l'image `worker` (FFmpeg 7.1.5, mini-rendu du script).
- **Status** : Accepted — 2026-10-19
