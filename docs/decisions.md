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
  - Upload reprenable, lu en flux depuis le stockage privé. `privacyStatus` forcé à `private` pendant cette phase.
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
