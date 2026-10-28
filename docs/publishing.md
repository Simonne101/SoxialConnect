# Moteur de publication interne

> État au 2026-09-30 : le moteur est complet et **YouTube est le premier réseau réel** (OAuth Google
> officiel + YouTube Data API v3, §11). Le premier test avec de vrais identifiants Google est **en
> attente**. Tous les autres réseaux utilisent `NotConfiguredPublisher` : échec explicite
> `NOT_CONFIGURED`, rien n'est publié. Le statut `published` n'apparaît qu'avec un adaptateur réel
> qui renvoie l'identifiant de publication du réseau (pour YouTube : le `videoId`).

## 1. Vue d'ensemble

```
API (publier, réessayer, annuler)      Planificateur (chaque minute)
          │                                      │ publishing:dispatch-due
          ▼                                      ▼
   PublishingService ── variantes « queued » + PublishPostVariantJob (file « publishing »)
          │
          ▼  worker : queue:work redis --queue=publishing,default,media
   PublishingService::execute()
     1. transaction : verrou post → variante, tentative créée, variante « publishing »
     2. hors transaction : PublisherRegistry → adaptateur → PublishResult
     3. transaction : résultat enregistré, variante et post mis à jour, audit
```

| Élément | Fichier |
|---|---|
| Service (point d'entrée unique : API, job, planificateur, future CLI) | `backend/app/Domain/Publishing/PublishingService.php` |
| Contrat d'adaptateur | `backend/app/Domain/Publishing/Contracts/SocialPublisher.php` |
| Demande / résultat | `backend/app/Domain/Publishing/Data/PublishRequest.php`, `PublishResult.php` |
| Adaptateur par défaut | `backend/app/Domain/Publishing/Adapters/NotConfiguredPublisher.php` |
| Choix de l'adaptateur | `backend/app/Domain/Publishing/PublisherRegistry.php` + `config/publishing.php` |
| Machine à états | `backend/app/Domain/Publishing/Support/VariantStateMachine.php` |
| Job | `backend/app/Jobs/PublishPostVariantJob.php` |
| Planificateur | `backend/app/Console/Commands/DispatchDuePublications.php`, `routes/console.php` |

**Unité de publication : la variante.** Il y a une variante par compte social et par format.
Le statut du post est toujours **agrégé** à partir de ses variantes (`PostStatusAggregator`).
Tant qu'une variante est en file ou en cours d'envoi, le post est « En cours » (`processing`).

**Points d'entrée côté interface : un seul parcours, le Composer** (`/content/create`). Médias
(barre de sélection ou aperçu → « Publier sur les réseaux ») et Clipper (« Publier » / « Planifier »
sur un clip prêt) y arrivent avec `state.mediaIds` : la vidéo de la médiathèque est **référencée**
(`post_media`), jamais copiée. Avant toute publication ou programmation, un **récapitulatif**
(réseaux et comptes, média, texte, visibilité réellement transmise) demande confirmation ; rien
n'est enregistré avant. Après confirmation, la liste (ou le calendrier) s'ouvre sur le détail de
la publication : état de chaque réseau, rafraîchi pendant l'envoi, « Réessayer » limité aux réseaux
en échec (ADR-066).

## 2. Cycle de vie d'une variante

```
draft / scheduled / cancelled ──► queued ──► publishing ──► published   (état final)
scheduled / queued ──► cancelled             publishing ──► failed ──► queued (Réessayer)
publishing ──► queued (erreur temporaire : nouvel essai automatique)
queued ──► failed (job abandonné avant l'envoi)
```

- Chaque transition est une mise à jour conditionnelle : `WHERE id = ? AND status = <attendu>`,
  plus `attempts_count = <attendu>` pour les transitions du job. Si deux processus se disputent
  la même variante, un seul l'emporte.
- `published` ne se quitte jamais. Une variante publiée n'est jamais renvoyée.
- Une variante `cancelled` n'est jamais exécutée : le job vérifie le statut sous verrou.
- Une variante `publishing` ne peut pas être prise par un second worker (verrou, statut et jeton).

Statuts du post, dans l'ordre d'évaluation :

| Variantes | Post |
|---|---|
| toutes en brouillon | `draft` |
| toutes annulées | `cancelled` |
| au moins une en file ou en cours d'envoi | `processing` |
| toutes publiées | `published` |
| toutes en échec | `failed` |
| publiées et en échec | `partially_published` |
| au moins une programmée | `scheduled` |
| publiées et annulées | `partially_published` |
| en échec et annulées | `failed` |

## 3. Planificateur

- `publishing:dispatch-due` est planifiée **chaque minute** (`withoutOverlapping`, `onOneServer`).
  La tâche `media:purge-deleted` (03:30) est inchangée.
- Sélection : posts `status = scheduled AND scheduled_at <= now()`, par lots
  (`publishing.dispatch_batch_size`, 50 par défaut), triés par date, avec
  `FOR UPDATE SKIP LOCKED`. Plusieurs planificateurs, ou un post en cours d'édition, ne se
  bloquent pas et ne traitent jamais deux fois la même publication. Les posts supprimés sont
  exclus.
- Chaque variante programmée arrivée à échéance passe à `queued` et reçoit un job
  (déclencheur `schedule`). Le job n'est poussé qu'après validation de la transaction
  (`after_commit`).
- **Reprise après incident**, à chaque passage :
  - Une variante `publishing` depuis plus de `stale_publishing_after_minutes` (15 min) a
    probablement un worker tué. Sa tentative devient `unknown` et la variante passe `failed`
    (`PUBLISH_OUTCOME_UNKNOWN`). Elle n'est **jamais relancée automatiquement**.
  - Une variante `queued` depuis plus de `stale_queued_after_minutes` (30 min) a probablement
    perdu son job (file vidée, worker arrêté). Le job est renvoyé avec le déclencheur
    `recovery`. Si le job d'origine existe encore, il ne s'exécutera pas en double grâce au
    jeton.

## 4. Job et worker

`PublishPostVariantJob` (file `publishing`) :

- `$tries = 1` : la file ne relance jamais un job à l'aveugle.
- `$timeout = 300` s (envoi d'une vidéo YouTube : 270 s maximum), inférieur à `retry_after = 360`
  (`QueueSafetyTest`).
- `$failOnTimeout = true` : un délai dépassé appelle `failed()`, qui appelle
  `PublishingService::abandon()`. La variante n'est jamais bloquée : elle passe en échec avec
  un résultat inconnu si l'envoi avait commencé, ou `PUBLISH_JOB_FAILED` sinon.
- Les données du job sont `variantId`, `trigger`, `triggeredBy`, `expectedAttempts` (le jeton)
  et `cycleAttempt`. Il ne contient aucun contenu ni secret.

Worker de **développement** (lancé par `npm run dev`) :
`php artisan queue:listen redis --queue=publishing,default,media --sleep=1 --timeout=300 --memory=256`.

- Chaque job s'exécute dans un processus PHP neuf (`queue:work --once`). Une modification du
  code ou du `.env` est donc prise en compte dès le job suivant, sans redémarrage.
- `--timeout` est réellement appliqué, y compris sous Windows.
- **Ne lancez pas `php artisan queue:restart` pendant `npm run dev`.** C'est inutile, et le
  lanceur arrêterait tout SoxialConnect en voyant son worker s'arrêter.

Worker de **production** : `queue:work` (ou Horizon), processus permanent, avec redémarrage
contrôlé au déploiement (`php artisan queue:restart`).

## 5. Idempotence et historique

- **Verrous** : toujours le post puis la variante (même ordre que `PostLock` dans le Composer),
  ce qui évite les interblocages.
- **Jeton** : `post_variants.attempts_count`. Un job ne s'exécute que si la variante est
  `queued` et que son compteur vaut `expectedAttempts`. Un job dupliqué, rejoué ou périmé ne
  fait rien.
- **Tentatives** : une ligne `publication_attempts` par envoi.
  - Unicité sur (`post_variant_id`, `attempt_number`) et sur `idempotency_key`
    (sha256 de `variant|n°`).
  - La clé est transmise à l'adaptateur, pour les réseaux qui acceptent une clé d'idempotence.
  - Une tentative conclue n'est jamais modifiée. Une relance crée une **nouvelle** tentative.
  - `trigger` (`schedule`, `manual`, `retry`, `auto_retry`, `recovery`) et `triggered_by`
    (utilisateur, ou null pour le système) indiquent l'origine.
- **Appel hors transaction** : aucun verrou n'est tenu pendant un appel réseau.
- **Pré-contrôles avant tout appel**, qui produisent une tentative en échec sans appel à
  l'adaptateur :
  - `ACCOUNT_MISSING` et `ACCOUNT_DISCONNECTED` : le compte n'existe plus, ou n'est plus
    connecté ;
  - `MEDIA_UNAVAILABLE` : média supprimé, pas prêt ou non autorisé par l'analyse ;
  - `EMPTY_CONTENT` : ni texte ni média.

  Un post supprimé entre-temps n'est jamais envoyé : sa variante passe `cancelled`, sans
  tentative.

## 6. Résultats et relances

| Résultat de l'adaptateur | Tentative | Variante | Nouvel essai automatique |
|---|---|---|---|
| `success` (avec identifiant externe) | `succeeded` | `published` | — |
| `temporary_failure` (429, indisponibilité) | `failed`, `retryable = true` | `queued`, puis `failed` après `max_auto_attempts` (3) | oui, différé (`backoff` : 60 s, 300 s, 900 s) |
| `permanent_failure` | `failed`, `retryable = false` | `failed` | non |
| `not_configured` | `failed`, `NOT_CONFIGURED` | `failed` | **non** (aucune boucle) |
| exception de l'adaptateur | `unknown`, `PUBLISH_OUTCOME_UNKNOWN` | `failed` | **non** |

**Réessayer** (`POST …/retry`) :

- Autorisé pour les posts `failed` ou `partially_published`.
- Seules les variantes en échec repassent en file, et une nouvelle tentative est créée.
- Si la dernière tentative a un résultat **inconnu**, la relance est refusée (409) tant que
  `confirm_unknown: true` n'est pas envoyé : l'utilisateur doit d'abord vérifier sur le réseau
  qu'aucun doublon n'a été publié. L'interface demande cette confirmation.

**Annuler** (`POST …/cancel`) :

- Autorisé pour les posts programmés ou en file.
- Les variantes `scheduled`, `draft` et `queued` passent à `cancelled`.
- Refusé (409) si une variante est en cours d'envoi.
- Les variantes déjà publiées le restent : le post devient alors `partially_published`.

## 7. API

Toutes les routes sont authentifiées, exigent une adresse e-mail vérifiée et passent par la
Policy du post (refus en 404 : protection IDOR).

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/v1/content/posts/{post}/publish` | Publier maintenant : brouillon, programmée ou annulée → en file. Corps optionnel : `lock_version`. Limite `throttle:publish`. |
| POST | `/api/v1/content/posts/{post}/retry` | Relancer les variantes en échec. Corps optionnel : `lock_version`, `confirm_unknown`. Limite `throttle:publish`. |
| POST | `/api/v1/content/posts/{post}/cancel` | Annuler une publication programmée ou en file (route existante, étendue). |
| GET | `/api/v1/content/posts/{post}/attempts` | Historique des tentatives, de la plus récente à la plus ancienne. Pas de clé d'idempotence, d'identifiant de job ni d'extrait de réponse. |

`PostVariantResource` expose aussi `queued_at`, `attempts_count` et `error_code`.
L'audit enregistre `POST_PUBLISH_REQUESTED`, `POST_PUBLISH_RETRIED`, `POST_PUBLISH_FAILED` et
`POST_PUBLISHED` (acteur `system` pour les résultats).

## 8. Adaptateurs et préparation OAuth

- Le contrat est `SocialPublisher::publish(PublishRequest): PublishResult`.
  - La demande ne contient **aucun token**. Un futur adaptateur obtiendra lui-même le jeton
    déchiffré au dernier moment (`TokenEncrypter`), puis le jettera.
  - Il doit classer chaque erreur (temporaire ou définitive) et ne renvoyer `success` qu'avec
    l'identifiant réel du réseau. Une exception signifie « résultat inconnu ».
- `config/publishing.php` → `adapters` ne contient que `youtube` → `YouTubePublisher` (§11). Pour les autres réseaux, rien n'est déclaré. Sans entrée pour une plateforme, le moteur
  utilise `NotConfiguredPublisher` : aucun appel, message clair, jamais de succès.
- Pour brancher un réseau (phase Social Connectors, après OAuth officiel et revue de la
  plateforme) :
  1. écrire l'adaptateur ;
  2. le déclarer dans `adapters` ;
  3. le tester avec `Http::fake()`.

  Le moteur, la file, l'historique et l'interface ne changent pas.
- Les tests utilisent un adaptateur factice (`tests/Feature/Publishing/Concerns/FakePublisher.php`),
  déclaré uniquement pendant le test.

## 9. Procédure locale

1. Laragon : MySQL et Redis démarrés.
2. `cd backend && php artisan migrate` (colonnes du moteur : migration `2026_09_30_100000`).
3. Depuis la racine : `npm run dev`. Cette commande démarre l'API, le **worker**
   (`[worker]`), le **planificateur** (`[cron]`, `schedule:work`) et le site. Ctrl + C arrête tout
   ce qu'elle a lancé.
   - Si SoxialConnect tourne déjà, l'instance existante est réutilisée : rien n'est lancé en double.
   - Les processus restés orphelins (fenêtre fermée, plantage) sont nettoyés au lancement suivant.
     Seuls ceux de ce projet sont concernés : processus enregistrés dans
     `backend/storage/framework/cache/dev-launcher.json`, ou serveurs reconnus à leur commande.
     Jamais d'autres processus PHP de la machine.
   - Autres commandes :
     - `npm run dev:status` : état, en lecture seule ;
     - `npm run dev:stop` : arrêt ;
     - `npm run dev:restart` : arrêt puis redémarrage ;
     - `npm run dev -- --no-browser` : démarrage sans ouvrir le navigateur.
4. Vérifier les files : `php artisan queue:monitor redis:publishing,redis:default,redis:media`.

**Windows sans `npm run dev`** (serveur ou poste sans terminal ouvert) :

- Le planificateur exige un appel de `php artisan schedule:run` **chaque minute**. Créez une
  tâche dans le Planificateur de tâches Windows :
  - déclencheur : « Au démarrage » ;
  - répétition : toutes les 1 minute, indéfiniment ;
  - action : `php.exe`, arguments `artisan schedule:run`, dossier de départ `A:\Sociopulse\backend` ;
  - « Exécuter même si l'utilisateur n'est pas connecté ».
- Le worker est un processus permanent. Utilisez un gestionnaire de services (NSSM, par exemple)
  qui lance `php artisan queue:work redis --queue=publishing,default,media --timeout=300` et le
  redémarre en cas d'arrêt.
- Ne lancez pas `schedule:work` en même temps qu'une tâche `schedule:run` : les deux feraient la
  même chose. `withoutOverlapping` et `onOneServer` évitent quand même les doubles passages.

## 10. Limites connues

- Il n'y a pas encore de **réconciliation** automatique des résultats inconnus : il faudra
  interroger le réseau par `idempotency_key` ou par identifiant externe, dans la phase Social
  Connectors. En attendant, la relance exige une confirmation de l'utilisateur.
- Les notifications de succès ou d'échec restent simulées (phase Notifications). Le résultat se
  lit dans la liste des publications et dans la fiche détail.
- Le planificateur recherche au niveau du post (`posts.scheduled_at`). Les dates par variante
  différentes de celle du post ne sont pas encore proposées par le Composer.
- Vidéo de la médiathèque : publiable sur YouTube (une seule vidéo), Telegram (MP4 ≤ 50 Mo) et
  TikTok (si un compte est connecté). LinkedIn et X : texte seul (le Composer bloque, le serveur
  refuse `MEDIA_NOT_SUPPORTED` sans appel). Facebook, Instagram, Pinterest, Threads : pas
  d'adaptateur de publication (une variante y échouerait en `NOT_CONFIGURED`, rien n'est envoyé).

## 11. YouTube (premier connecteur réel)

### Architecture

```
SPA « Connecter YouTube » ─► POST /api/v1/social/youtube/connect ─► URL de consentement Google
Google ─► GET /oauth/youtube/callback (route web, session) ─► tokens chiffrés ─► social_accounts
Composer « Publier maintenant » / planificateur ─► PublishingService ─► PublisherRegistry
   ─► YouTubePublisher ─► YouTube Data API v3 (videos.insert) ─► PublishResult(videoId)
   ─► publication_attempts.external_post_id + variante « published »
```

| Fichier | Rôle |
|---|---|
| `app/Domain/Social/OAuth/YouTubeOAuthProvider.php` | URL d'autorisation, échange du code (PKCE), refresh, lecture de la chaîne, révocation. Endpoints Google officiels, sans SDK. |
| `app/Domain/Social/OAuth/OAuthStateStore.php` | `state` : 64 caractères aléatoires, usage unique, 10 min, lié à l'utilisateur, avec le vérificateur PKCE (cache serveur). |
| `app/Domain/Social/OAuth/OAuthTokens.php`, `OAuthException.php` | Tokens en mémoire uniquement, et erreurs OAuth normalisées (sans secret). |
| `app/Domain/Social/Tokens/YouTubeTokenManager.php` | Jeton valide : réutilisé ou rafraîchi sous verrou par compte, puis rechiffré. En cas de refus de Google, compte « à reconnecter ». |
| `app/Domain/Social/Actions/ConnectYouTubeAccount.php` | Crée ou reconnecte la chaîne, avec un seul token actif et les permissions accordées. |
| `app/Domain/Social/Actions/DisconnectSocialAccount.php` | Révocation Google (au mieux), puis effacement des tokens. Le compte passe en `revoked`. |
| `app/Http/Controllers/OAuth/YouTubeCallbackController.php` | Callback : utilisateur, puis state, puis erreur Google, puis code, puis scopes, puis chaîne. |
| `app/Domain/Publishing/Adapters/YouTubePublisher.php` | Adaptateur `SocialPublisher`. |

### Scopes (minimaux)

- `https://www.googleapis.com/auth/youtube.upload` : mise en ligne de vidéos (`videos.insert`).
- `https://www.googleapis.com/auth/youtube.readonly` : identification de la chaîne connectée
  (`channels.list?mine=true`).

Si l'utilisateur décoche la mise en ligne, la connexion est refusée (`SCOPE_DENIED`) et
l'autorisation est révoquée.

### Sécurité

- Le state est vérifié avant tout traitement, y compris quand Google renvoie `error`. Il est à
  usage unique et émis pour l'utilisateur connecté.
- PKCE S256 et `access_type=offline`. `prompt=consent` garantit un refresh token lors d'une
  reconnexion.
- La redirect URI vient de la configuration, jamais du client. Elle doit correspondre exactement
  à celle déclarée chez Google.
- Les tokens sont stockés dans `social_tokens` :
  - chiffrement AES-256-GCM avec la clé dédiée `TOKEN_ENCRYPTION_KEY` (versionnée, ADR-008) ;
  - jamais dans une réponse, un log, un audit, le payload d'un job ou le frontend.
- Le navigateur ne reçoit que `?oauth=youtube&status=connected|error&code=<CODE>`.
- Limites : `throttle:oauth` (10/h) pour la connexion et la synchronisation, 20/min pour le
  callback.
- **Production : HTTPS obligatoire** pour la redirect URI et l'application (Google refuse les URI
  `http` hors `localhost`).

### Envoi d'une vidéo

1. **Contrôles** : identifiants Google présents (sinon `NOT_CONFIGURED`), compte YouTube,
   **exactement une vidéo**. Le fichier est lu **côté serveur** depuis le stockage privé : aucune
   URL publique.
2. **Jeton** : il est rafraîchi s'il expire dans moins de 5 minutes.
3. **Ouverture d'une session d'envoi reprenable** (`POST …/upload/youtube/v3/videos?uploadType=resumable`).
   - Aucune vidéo n'existe encore à ce stade : une erreur y est donc relançable sans risque.
   - Si YouTube répond 401 : un seul renouvellement forcé, puis un seul nouvel essai.
4. **Envoi du fichier en flux** (`PUT`, délai maximal de 270 s).
5. **Succès uniquement si** YouTube renvoie `kind = youtube#video` et un `id`. Ce `videoId` est
   enregistré dans `publication_attempts.external_post_id` et `post_variants.external_post_id` ;
   l'URL est `https://www.youtube.com/watch?v=…`.

**Métadonnées envoyées à YouTube :**

- titre : option « Titre » de la variante, sinon titre de la publication, sinon première ligne
  (100 caractères au maximum) ;
- description : option « Description », sinon texte de la publication ;
- tags : les hashtags, sans `#` ;
- catégorie : `YOUTUBE_DEFAULT_CATEGORY_ID` (22 par défaut) ;
- `selfDeclaredMadeForKids` : seulement si l'option est renseignée ;
- `<` et `>` sont retirés, car YouTube les refuse.

**`privacyStatus` = option « Visibilité » du Composer** (`youtubeVisibility` : `public`,
`unlisted` ou `private`, liste blanche stricte). Option absente ou invalide : `private` — jamais de
publication publique implicite. La visibilité renvoyée par YouTube est comparée à la demande
(`publication_attempts.response_excerpt` : `privacy_requested`, `privacy_status`,
`privacy_mismatch`). Un écart reste une **réussite** (la vidéo existe), mais il est journalisé
(`publishing.youtube_visibility_mismatch`) et affiché comme avertissement dans « Historique des
envois » (`warnings`, code `VISIBILITY_MISMATCH`). Cause connue : tant que le projet Google Cloud
n'est pas audité, YouTube peut limiter les vidéos envoyées par l'API à « Privée » (ADR-065).

**Erreurs.** Le moteur Phase 1 décide des relances ; il n'y a pas de logique de relance propre à
YouTube.

| Situation | Code | Nature |
|---|---|---|
| quota (`quotaExceeded`, `dailyLimitExceeded`) | `QUOTA_EXCEEDED` | temporaire |
| 429 / `rateLimitExceeded` | `RATE_LIMITED` | temporaire |
| 5xx, réseau avant l'envoi du fichier, envoi incomplet (308) | `PLATFORM_UNAVAILABLE`, `NETWORK_ERROR`, `UPLOAD_INCOMPLETE` | temporaire |
| refresh refusé (`invalid_grant`) | `TOKEN_REVOKED` (compte passé `expired`) | définitive |
| 401 persistant | `TOKEN_INVALID` | définitive |
| 403 permissions | `PERMISSION_DENIED` | définitive |
| pas de chaîne | `CHANNEL_REQUIRED` | définitive |
| 400 métadonnées | `INVALID_REQUEST` | définitive |
| trop volumineux | `UPLOAD_LIMIT_EXCEEDED` | définitive |
| fichier absent | `MEDIA_NOT_FOUND` | définitive |
| pas une vidéo unique | `MEDIA_INCOMPATIBLE` | définitive |
| vidéo refusée après envoi | `VIDEO_REJECTED` | définitive |
| délai dépassé ou coupure **pendant** l'envoi, réponse sans `id` | `PUBLISH_OUTCOME_UNKNOWN` | inconnue : jamais relancée automatiquement, confirmation demandée |

**Idempotence.** Toutes les protections du moteur Phase 1 s'appliquent : jeton `attempts_count`,
verrous, transitions conditionnelles. Une variante publiée n'est jamais renvoyée, et un job
dupliqué ne provoque pas de second envoi.

### Mise en place (Google Cloud)

1. Dans <https://console.cloud.google.com>, créez un projet (par exemple « SoxialConnect »).
2. Dans **API et services → Bibliothèque**, activez **YouTube Data API v3**.
3. Dans **Écran de consentement OAuth** :
   - type « Externe » ;
   - nom de l'application et e-mail d'assistance ;
   - scopes `youtube.upload` et `youtube.readonly` ;
   - ajoutez votre compte Google dans **Utilisateurs test** (mode « En test »).
4. Dans **Identifiants → Créer des identifiants → ID client OAuth** :
   - type « Application Web » ;
   - URI de redirection autorisée, **exacte** :
     - local : `http://localhost:5173/oauth/youtube/callback` ;
     - production : `https://<domaine>/oauth/youtube/callback`.
5. Dans `backend/.env` (jamais versionné) :

   ```
   YOUTUBE_CLIENT_ID=<ID client>
   YOUTUBE_CLIENT_SECRET=<secret client>
   YOUTUBE_REDIRECT_URI=http://localhost:5173/oauth/youtube/callback
   ```

   Puis lancez `php artisan config:clear` et redémarrez `npm run dev`. Le worker doit être
   redémarré pour relire la configuration.

En local, Vite relaie `/oauth` vers Laravel : le retour de Google arrive sur le même domaine que
l'application, avec le cookie de session.

### Test réel (manuel, jamais dans la suite automatisée)

1. Lancez `npm run dev`, connectez-vous, puis ouvrez **Réseaux sociaux → YouTube → Connecter →
   Continuer vers YouTube**.
2. Autorisez l'accès sur Google. Au retour, le message « YouTube est connecté » s'affiche et la
   chaîne apparaît.
3. Ajoutez une **courte vidéo** (MP4) à la médiathèque.
4. Dans le Composer, ciblez YouTube au format « Vidéo », puis cliquez sur **Publier maintenant**.
5. Dans la fiche de la publication :
   - la tentative doit être « Réussie » et le statut « Publiée » ;
   - `publication_attempts.external_post_id` contient le `videoId`.
6. Vérifiez dans YouTube Studio que la visibilité correspond au choix du Composer (sinon, l'historique
   des envois affiche un avertissement).

**Déconnexion** : Réseaux sociaux → YouTube → **Gérer → Déconnecter**. L'accès est révoqué chez
Google, puis les tokens sont effacés.

### Erreurs courantes

| Symptôme | Cause probable |
|---|---|
| `redirect_uri_mismatch` sur Google | URI déclarée différente de `YOUTUBE_REDIRECT_URI`, au caractère près (port, `/` final, http ou https). |
| `access_denied` / « application non validée » | Compte absent des utilisateurs test, ou consentement refusé. |
| Message « pas encore configurée » | Variables `YOUTUBE_*` vides, ou configuration en cache (`php artisan config:clear`). |
| « Demande expirée » (`INVALID_STATE`) | Plus de 10 minutes sur Google, onglet rejoué, ou connexion ouverte avec un autre utilisateur SoxialConnect. |
| `QUOTA_EXCEEDED` | Quota quotidien du projet atteint (10 000 unités par défaut ; un envoi en coûte environ 1 600). Réessayez le lendemain, ou demandez une extension. |
| Vidéo « privée » alors que « Publique » était choisie (avertissement `VISIBILITY_MISMATCH`) | Projet Google Cloud non audité : YouTube limite les vidéos envoyées par l'API à « Privée ». Demander l'audit de l'API YouTube Data, ou changer la visibilité dans YouTube Studio si elle n'est pas verrouillée. |

## 13. Telegram (Bot API, bot partagé)

Un seul bot pour toute la plateforme. Son token reste dans `backend/.env` (`TELEGRAM_BOT_TOKEN`) :
jamais en base, jamais dans le frontend, jamais dans les journaux. Il figure dans l'URL de chaque
appel ; `TelegramBotClient` ne journalise donc jamais cette URL et ne chaîne jamais l'exception
d'origine. Aucun token utilisateur, aucun OAuth.

### Liaison d'une chaîne ou d'un groupe

1. Réseaux sociaux › Telegram › Connecter : `POST /api/v1/social/telegram/link` renvoie
   `https://t.me/<bot>?start=<code>`. Le code fait 48 caractères, il est à usage unique, valable
   10 minutes et lié à l'utilisateur ; il est stocké en cache sous son empreinte SHA-256.
2. Dans Telegram, « Démarrer » envoie `/start <code>`. Une session de liaison est alors ouverte
   pour cette personne Telegram (10 minutes). Le bot répond avec deux boutons officiels
   (`KeyboardButtonRequestChat`) : « Choisir une chaîne » et « Choisir un groupe ».
   - Chaîne : la personne doit en être administratrice avec le droit de publier, et le bot y est
     ajouté comme administrateur avec `can_post_messages`.
   - Groupe : la personne doit en être administratrice, et le bot y est ajouté comme administrateur.
3. Telegram envoie le message `chat_shared`. Le serveur vérifie lui-même, par `getChat` et
   `getChatMember`, le type de conversation, les droits réels du bot et le statut de la personne
   (propriétaire ou administratrice), puis `ConnectTelegramChat` crée ou réactive la destination.
   Celle-ci est enregistrée dans `social_accounts` :
   - `external_account_id` = `chat_id` ;
   - `account_type` = `channel` ou `group` ;
   - `display_name` = titre, `username` = `@nom` si la conversation est publique.
4. La fenêtre de SoxialConnect relit la liste des comptes toutes les 3 secondes et affiche le résultat.

Un utilisateur peut relier plusieurs chaînes et groupes. Dans le Composer, il en choisit un par publication.

### Webhook

`POST /api/v1/webhooks/telegram` :

1. Contrôle de l'en-tête `X-Telegram-Bot-Api-Secret-Token`, comparé en temps constant à
   `TELEGRAM_WEBHOOK_SECRET`. Une requête sans secret valide est refusée (403) ; sans secret
   configuré, tout est refusé.
2. Dédoublonnage par `update_id` (`webhook_events`).
3. Réponse 200 immédiate, puis traitement en file `default` (`ProcessTelegramUpdate`).

Seules les données utiles sont gardées ; aucun contenu de conversation n'est conservé.
Mises à jour traitées :

- `message` : `/start`, `chat_shared`, et la migration d'un groupe en supergroupe (nouvel identifiant) ;
- `my_chat_member` : bot retiré ou rétrogradé (destination « à reconnecter »), puis rétabli
  (destination de nouveau connectée).

Une destination déconnectée par l'utilisateur n'est jamais réactivée automatiquement.

### Publication (`TelegramPublisher`, synchrone)

| Contenu | Méthode | Limites vérifiées avant tout envoi |
|---|---|---|
| Texte seul | `sendMessage` (texte brut, sans `parse_mode`) | 4096 caractères (unités UTF-16) |
| Une image JPEG, PNG ou WebP | `sendPhoto` | légende ≤ 1024, 10 Mo |
| Un GIF | `sendAnimation` | légende ≤ 1024, 50 Mo |
| Une vidéo | `sendVideo` | MP4 uniquement, légende ≤ 1024, 50 Mo |
| 2 à 10 images ou vidéos | `sendMediaGroup` (légende sur le premier élément) | 10 éléments au plus, sans GIF |

- Les fichiers sont envoyés en multipart depuis le stockage privé : aucune URL publique.
- Le nom d'origine du fichier n'est pas transmis.
- Les hashtags choisis sont ajoutés en fin de texte s'ils n'y figurent pas déjà.
- Succès : `external_post_id` = `message_id` (le premier message pour un album).
- Lien du message :
  - chaîne ou groupe public : `https://t.me/<nom>/<id>` ;
  - privé (`-100…`) : `https://t.me/c/<id interne>/<id>` ;
  - groupe simple : pas de lien.

Classement des erreurs :

| Erreur Telegram | Résultat |
|---|---|
| 429 (`retry_after`), connexion impossible avant l'envoi | Temporaire (nouvel essai automatique) |
| 403 (bot retiré), 400 « not enough rights », « chat not found » | Définitive `PERMISSION_DENIED` ; destination « à reconnecter » |
| 400 avec `migrate_to_chat_id` | Nouvel identifiant enregistré, puis nouvel essai automatique |
| 400 texte trop long, média refusé, 413, 401 (token invalide) | Définitive |
| 5xx, délai dépassé pendant l'envoi | Résultat inconnu : jamais relancé sans confirmation (Telegram n'a pas de clé d'idempotence) |

Débit : environ 1 message par seconde par conversation et 20 par minute dans un groupe. Un
dépassement renvoie 429 et donne lieu à un nouvel essai automatique. Programmation : assurée par
SoxialConnect, car les bots n'ont pas de publication programmée.

Déconnexion : seule la liaison est désactivée (statut `revoked`), et le bot reste dans la conversation.
Une reconnexion réactive la même ligne.

### Configuration

```dotenv
TELEGRAM_BOT_TOKEN=          # fourni par @BotFather (secret)
TELEGRAM_BOT_USERNAME=       # nom du bot, sans @
TELEGRAM_WEBHOOK_SECRET=     # 32 à 64 caractères [A-Za-z0-9_-]
TELEGRAM_WEBHOOK_URL=https://<domaine-du-tunnel>/api/v1/webhooks/telegram
```

En local, le tunnel HTTPS mène à Vite (5173), qui relaie `/api` vers Laravel (8000). Une fois les
variables renseignées, redémarrez `npm run dev` (le worker relit sa configuration), puis :

```bash
php artisan telegram:webhook --set   # enregistre l'URL et le secret auprès de Telegram
php artisan telegram:webhook         # état : bot, URL, mises à jour en attente, dernière erreur
```

Si l'adresse du tunnel change, mettez à jour `TELEGRAM_WEBHOOK_URL`, puis relancez `--set`.

## 14. LinkedIn (profil personnel, texte)

Produits LinkedIn activés sur l'application : **Sign In with LinkedIn using OpenID Connect** et
**Share on LinkedIn**. Sources officielles (learn.microsoft.com/linkedin) :
`shared/authentication/authorization-code-flow`,
`consumer/integrations/self-serve/sign-in-with-linkedin-v2` et
`consumer/integrations/self-serve/share-on-linkedin`.

### Variables d'environnement

```dotenv
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=      # secret : uniquement dans .env
LINKEDIN_REDIRECT_URI=https://<domaine-du-tunnel>/oauth/linkedin/callback
```

L'URI doit être **identique** à celle déclarée dans le Developer Portal (onglet Auth), en HTTPS. En
local, le tunnel mène à Vite (5173), qui relaie `/oauth` vers Laravel.

### Connexion (OAuth 2.0 « Authorization Code »)

1. Réseaux sociaux › LinkedIn › Connecter : `POST /api/v1/social/linkedin/connect`.
   - Le serveur crée un `state` (`OAuthStateStore` : usage unique, 10 minutes, lié à l'utilisateur).
   - Il renvoie `https://www.linkedin.com/oauth/v2/authorization`, avec les scopes
     `openid profile email w_member_social`. Aucune permission d'organisation n'est demandée.
2. Retour sur `GET /oauth/linkedin/callback` (`LinkedInCallbackController`, route web avec session),
   qui traite dans l'ordre :
   1. utilisateur connecté ;
   2. `state` ;
   3. erreur LinkedIn (`user_cancelled_login` et `user_cancelled_authorize` donnent `ACCESS_DENIED`) ;
   4. échange du code (`POST /oauth/v2/accessToken`, en formulaire) ;
   5. vérification de la permission `w_member_social` ;
   6. identité (`GET https://api.linkedin.com/v2/userinfo`).
3. `ConnectLinkedInAccount` crée ou met à jour le compte :
   - `external_account_id` = `sub`, et l'auteur des publications est `urn:li:person:{sub}` ;
   - `account_type` = `profile` ;
   - `display_name` = nom, `username` = e-mail (optionnel) ;
   - un seul token actif, chiffré ;
   - les permissions réellement accordées.
4. Retour vers `/social-accounts?oauth=linkedin&status=connected`, ou `status=error&code=…`.

**Token :** l'accès dure **60 jours**. Il n'y a pas de refresh token : les refresh tokens
programmatiques sont réservés à certains partenaires LinkedIn. À l'expiration, ou si LinkedIn
refuse le token (401), le compte passe « à reconnecter ». L'utilisateur refait alors la connexion,
et l'écran d'autorisation est souvent sauté s'il est toujours connecté à LinkedIn.

**Déconnexion :** les tokens sont effacés localement. Aucun endpoint de révocation n'est utilisé ;
l'utilisateur peut retirer l'accès depuis ses paramètres LinkedIn.

### Publication (`LinkedInPublisher`)

La V1 publie du **texte seul** via `POST https://api.linkedin.com/v2/ugcPosts`, avec l'en-tête
`X-Restli-Protocol-Version: 2.0.0` :

- `author` : `urn:li:person:{sub}` ;
- `lifecycleState` : `PUBLISHED` ;
- `shareCommentary` : le texte, suivi des hashtags choisis ;
- `shareMediaCategory` : `NONE` ;
- visibilité : `PUBLIC`.

Succès : `201 Created`. L'identifiant du post vient de l'en-tête `X-RestLi-Id` (`urn:li:share:…`) ;
le lien est `https://www.linkedin.com/feed/update/{urn}/`.

| Réponse LinkedIn | Résultat |
|---|---|
| 401 | Définitif `TOKEN_INVALID` ; compte « à reconnecter » |
| 403 | Définitif `PERMISSION_DENIED` |
| 422 « duplicate » | Définitif `DUPLICATE_CONTENT` |
| 400, autre 422 | Définitif `INVALID_REQUEST` |
| 429 | Temporaire (nouvel essai automatique). Quota documenté : 150 requêtes par membre et par jour |
| 5xx, délai dépassé pendant l'envoi | Résultat inconnu : jamais relancé sans confirmation (pas de clé d'idempotence) |
| Connexion impossible avant l'envoi | Temporaire |

Le jeton n'apparaît jamais dans les journaux, les tentatives ou les réponses de l'API : il est
envoyé en en-tête, jamais dans une URL.

### Limites actuelles

- **Images et vidéos :** refusées avant tout envoi (`MEDIA_NOT_SUPPORTED`), et bloquées aussi dans le
  Composer. Le flux d'upload documenté (`assets?action=registerUpload`, envoi du fichier, puis post)
  sera intégré après vérification. La documentation parle d'un POST vers `uploadUrl` mais donne un
  exemple en PUT, et ne dit rien de l'état de traitement de l'image.
- **Pages d'entreprise (`organization`) :** non prises en charge. Elles exigent l'accès
  « Community Management API », soumis à validation par LinkedIn.
- **API utilisée :** `ugcPosts` est l'API documentée pour le produit « Share on LinkedIn ». LinkedIn
  propose aussi la Posts API, versionnée (`/rest/posts`) : une migration sera à envisager si
  `ugcPosts` est retirée.

### Test réel

1. Ouvrez SoxialConnect **via l'adresse ngrok** (le cookie de session doit être présent au retour
   du callback).
2. Ouvrez Réseaux sociaux › LinkedIn › Connecter, autorisez l'application, puis vérifiez que le
   profil apparaît.
3. Dans le Composer, cochez LinkedIn, vérifiez le compte, saisissez un **texte** sans média, puis
   « Publier maintenant ».
4. Vérifiez le statut « Publié » et ouvrez le lien vers le post sur LinkedIn.

## 15. X (compte utilisateur, texte)

Sources officielles (docs.x.com) : `resources/fundamentals/authentication/oauth-2-0/authorization-code`,
`resources/fundamentals/authentication/oauth-2-0/user-access-token` et `x-api/users/get-my-user`.

### Variables d'environnement

```dotenv
X_CLIENT_ID=
X_CLIENT_SECRET=      # secret : uniquement dans .env
X_REDIRECT_URI=https://<domaine-du-tunnel>/oauth/x/callback
```

Dans la console développeur X (User authentication settings) :

- type d'application **Web App, Automated App or Bot** (client confidentiel) ;
- permissions **Read and write** ;
- Callback URI **identique** à `X_REDIRECT_URI`. X refuse `localhost` : en local, on passe par le
  tunnel, qui mène à Vite (5173), qui relaie `/oauth` vers Laravel.

L'API X est payante (facturation à l'usage) : sans offre ni crédits sur le compte développeur, X
répond 402 ou 403 et la connexion échoue avec `API_ACCESS_DENIED`.

### Connexion (OAuth 2.0 « Authorization Code » + PKCE)

1. Réseaux sociaux › X › Connecter : `POST /api/v1/social/x/connect`.
   - Le serveur crée un `state` et un vérificateur PKCE (`OAuthStateStore` : usage unique,
     10 minutes, lié à l'utilisateur ; le vérificateur reste côté serveur).
   - Il renvoie `https://x.com/i/oauth2/authorize` avec `code_challenge` (S256) et les scopes
     `users.read tweet.read tweet.write offline.access`.
2. Retour sur `GET /oauth/x/callback` (`XCallbackController`, route web avec session), qui traite
   dans l'ordre :
   1. utilisateur connecté ;
   2. `state` (restitue le vérificateur PKCE) ;
   3. erreur X (`access_denied` donne `ACCESS_DENIED`) ;
   4. échange du code (`POST https://api.x.com/2/oauth2/token`, formulaire, client en HTTP Basic,
      avec `code_verifier`) ;
   5. vérification de la permission `tweet.write` ;
   6. identité (`GET https://api.x.com/2/users/me?user.fields=profile_image_url`).
3. `ConnectXAccount` crée ou met à jour le compte :
   - `external_account_id` = identifiant X ;
   - `account_type` = `profile` ;
   - `display_name` = nom, `username` = `@identifiant`, photo dans `metadata.picture_url` ;
   - un seul token actif, chiffré ;
   - les permissions réellement accordées.
4. Retour vers `/social-accounts?oauth=x&status=connected`, ou `status=error&code=…`.

**Token (`XTokenManager`) :** l'accès dure **2 heures**. Le refresh token (scope `offline.access`)
est à usage unique : à chaque renouvellement, X en renvoie un nouveau, qui remplace l'ancien dans
la même écriture que le nouvel access token. Le renouvellement se fait sous verrou par compte, 10
minutes avant l'expiration. Refresh token absent ou refusé : compte « à reconnecter », sans boucle.

**Déconnexion :** révocation au mieux du refresh token puis de l'access token
(`POST https://api.x.com/2/oauth2/revoke`), puis effacement des tokens locaux.

### Publication (`XPublisher`)

La V1 publie du **texte seul** via `POST https://api.x.com/2/tweets` (« Create Post »), corps
`{ "text": … }`, avec le jeton de l'utilisateur (`Authorization: Bearer`, scope `tweet.write`)
fourni par `XTokenManager`. Celui-ci le renouvelle si besoin ; l'adaptateur ne gère ni OAuth, ni
refresh, ni chiffrement. Le texte envoyé est celui de la variante, suivi des hashtags choisis qui
n'y figurent pas déjà. Publication immédiate et programmée passent par le moteur commun
(`PublishPostVariantJob`, file `publishing`, `publishing:dispatch-due`).

Succès : `201 Created`, `{ "data": { "id", "text" } }`. L'identifiant (`data.id`) est enregistré
dans `external_post_id` ; le lien est `https://x.com/i/web/status/{id}` (lien permanent officiel,
indépendant de l'@identifiant). Sans identifiant valide dans la réponse : résultat inconnu.

**Longueur :** 280 caractères **selon le décompte de X** (NFC ; emoji et caractères CJK = 2 ;
chaque URL = 23), calculé par `XPostLength` et par le Composer (`frontend/src/lib/xText.ts`). Au-delà,
refus `TEXT_TOO_LONG` avant tout envoi : le texte n'est jamais tronqué. Les posts longs des
abonnés X Premium ne sont pas gérés.

| Réponse X | Résultat |
|---|---|
| Médias joints | Définitif `MEDIA_NOT_SUPPORTED`, aucun appel |
| Texte vide / trop long | Définitif `EMPTY_CONTENT` / `TEXT_TOO_LONG`, aucun appel |
| Refresh refusé par X | Définitif `TOKEN_REVOKED` ; compte « à reconnecter », tokens conservés |
| 400 | Définitif `INVALID_REQUEST` |
| 401 | Définitif `TOKEN_INVALID` ; compte « à reconnecter » |
| 402, 403 `client-not-enrolled` | Définitif `API_ACCESS_DENIED` (offre ou crédits du compte développeur X) |
| 403 « duplicate content » | Définitif `DUPLICATE_CONTENT` |
| Autre 403 | Définitif `PERMISSION_DENIED` |
| 429 | Temporaire `RATE_LIMITED` : nouvel essai selon `publishing.backoff` (60 s, 300 s), 3 tentatives au plus ; l'heure `x-rate-limit-reset` est conservée dans l'historique |
| Autre 4xx (409…) | Définitif `HTTP_ERROR` |
| 5xx, délai dépassé pendant l'envoi, réponse illisible | Résultat inconnu : jamais relancé sans confirmation (pas de clé d'idempotence) |
| Connexion impossible avant l'envoi | Temporaire `NETWORK_ERROR` |

L'historique (`publication_attempts.response_excerpt`) ne garde que `http_status`, `title`, `type`,
`reason` et `detail` (tronqué) : jamais de jeton, ni l'identifiant client renvoyé par certaines erreurs.

### Limites actuelles

- **Images, vidéos, GIF :** refusés avant tout envoi et bloqués dans le Composer. Ils exigeront
  l'envoi de médias (`/2/media/upload`) et le scope `media.write`, donc une reconnexion des comptes.
- **Fils, sondages, réponses, citations :** non pris en charge.
- **Limite de débit :** le moteur applique son propre backoff ; il ne patiente pas jusqu'à
  `x-rate-limit-reset` si la fenêtre X est plus longue que les 3 tentatives automatiques.

### Test réel

1. Corrigez `X_REDIRECT_URI` dans `.env`, puis ouvrez SoxialConnect **via l'adresse ngrok**.
2. Réseaux sociaux › X › Connecter, autorisez l'application, vérifiez le compte.
3. Dans le Composer, cochez X, saisissez un **texte** sans média, puis « Publier maintenant ».
4. Vérifiez le statut « Publié » et ouvrez le lien vers le post.

## 16. Facebook (Pages)

Sources officielles (developers.facebook.com/docs) : `facebook-login/guides/advanced/manual-flow`,
`facebook-login/guides/access-tokens/get-long-lived`, `pages-api/overview`, `graph-api/reference/page/feed`,
`graph-api/reference/page/photos`, `graph-api/reference/page/videos`.

### Variables d'environnement

```dotenv
META_APP_ID=                 # ID de l'app Meta (Paramètres de l'app → Général)
META_APP_SECRET=             # secret : uniquement dans .env
FACEBOOK_REDIRECT_URI=https://<domaine>/oauth/facebook/callback
FACEBOOK_LOGIN_CONFIG_ID=    # facultatif : configuration « Facebook Login for Business »
META_GRAPH_VERSION=v23.0
```

Dans l'app Meta : cas d'usage **« Gérer tout sur votre Page »**, URI de redirection OAuth valide
**identique** à `FACEBOOK_REDIRECT_URI`. Tant que l'app est en mode développement, seules les personnes
ayant un rôle sur l'app (Admin, Développeur, Testeur) peuvent se connecter ; l'examen de l'app par Meta
ouvre la connexion à tous.

### Connexion (Facebook Login, authorization code)

1. Réseaux sociaux › Facebook › Connecter : `POST /api/v1/social/facebook/connect` → `state` à usage unique
   (`OAuthStateStore`) et URL `https://www.facebook.com/{version}/dialog/oauth` avec les permissions
   `pages_show_list, pages_manage_posts, pages_read_engagement, read_insights` (ou `config_id` si
   `FACEBOOK_LOGIN_CONFIG_ID` est renseigné).
2. Retour sur `GET /oauth/facebook/callback` (`FacebookCallbackController`) : utilisateur connecté → `state`
   → erreur Facebook (`access_denied` → `ACCESS_DENIED`) → échange du code (`POST /oauth/access_token`)
   → jeton utilisateur **longue durée** (`fb_exchange_token`, 60 jours) → permissions réellement accordées
   (`GET /me/permissions`) : sans `pages_show_list` et `pages_manage_posts`, rien n'est enregistré
   (`PUBLISH_SCOPE_DENIED`) → identité (`GET /me`) → Pages (`GET /me/accounts`, jeton de chaque Page).
3. `ConnectFacebookPages` enregistre **un compte par Page** où la personne peut publier (tâche
   `CREATE_CONTENT`) : `account_type` = `page`, jeton **de Page** chiffré (`token_kind` = `page`), sans
   expiration (issu d'un jeton utilisateur longue durée), permissions accordées. Le jeton utilisateur n'est
   jamais conservé. Aucune Page : `NO_PAGES` ; aucune Page publiable : `NO_PUBLISHABLE_PAGE`.
4. Retour vers `/social-accounts?oauth=facebook&status=connected&count=<nombre de Pages>`.

Sécurité : chaque appel porte `appsecret_proof` (HMAC-SHA256 du jeton par la clé secrète) ; les jetons
passent par l'en-tête `Authorization`, la clé secrète par le corps des requêtes : jamais dans une URL.

**Jeton (`FacebookTokenManager`) :** aucun renouvellement. Refusé par Facebook (code 190 : accès retiré,
mot de passe changé, rôle perdu) → Page « à reconnecter », plus aucun appel. **Déconnexion :** effacement
des jetons locaux (la personne peut aussi retirer l'accès dans Facebook › Paramètres › Intégrations
professionnelles).

### Publication (`FacebookPublisher`)

| Contenu | Appel |
|---|---|
| Texte seul | `POST /{page-id}/feed` (`message`) |
| 1 photo (JPEG, PNG, GIF) | `POST /{page-id}/photos` (`url`, `caption`) → identifiant `post_id` |
| 2 à 10 photos | chaque photo `published=false`, puis `POST /{page-id}/feed` avec `attached_media` |
| 1 vidéo (MP4, MOV) | `POST /{page-id}/videos` (`file_url`, `description`) |

Facebook télécharge les fichiers par une **URL signée de courte durée** (`MediaStorage::temporaryUrl`) :
le serveur doit être joignable en HTTPS. Refusés avant tout envoi : mélange photo + vidéo
(`MIXED_MEDIA_NOT_SUPPORTED`), WebP et autres formats (`MEDIA_TYPE_NOT_SUPPORTED`), plus de 10 photos,
format Reel (`FORMAT_NOT_SUPPORTED`, API distincte). Premier commentaire : non pris en charge
(permission `pages_manage_engagement`), noté `first_comment: not_supported` dans le résultat.

Erreurs : 190 → `TOKEN_INVALID` (Page à reconnecter) ; 4, 17, 32, 613, 80001 → `RATE_LIMITED` temporaire ;
10, 200-299 → `PERMISSION_DENIED` ; 506 → `DUPLICATE_CONTENT` ; 368 → `POLICY_BLOCKED` ; fichier
illisible → `MEDIA_REJECTED` ; 5xx ou codes 1, 2 après envoi → résultat inconnu, jamais relancé.

Tests : `FacebookOAuthTest` (10), `FacebookPublishingTest` (9) — API Graph SIMULÉE.

## 17. Instagram (comptes professionnels)

Sources officielles (developers.facebook.com/docs/instagram-platform) :
`instagram-api-with-instagram-login/business-login`, `reference/access_token`,
`reference/refresh_access_token`, `content-publishing`, `reference/ig-user/media`,
`reference/ig-user/media_publish`, `reference/ig-container`.

### Variables d'environnement

```dotenv
INSTAGRAM_APP_ID=        # ID de l'app INSTAGRAM (différent de META_APP_ID)
INSTAGRAM_APP_SECRET=    # secret : uniquement dans .env
INSTAGRAM_REDIRECT_URI=https://<domaine>/oauth/instagram/callback
INSTAGRAM_GRAPH_VERSION=v23.0
```

Dans l'app Meta : cas d'usage **« Gérer la messagerie et le contenu sur Instagram »**, configuration de
l'**API avec connexion Instagram** (qui fournit l'ID et la clé secrète de l'app Instagram), URI de
redirection **identique** à `INSTAGRAM_REDIRECT_URI`.

### Connexion

1. `POST /api/v1/social/instagram/connect` → URL `https://www.instagram.com/oauth/authorize` avec
   `instagram_business_basic, instagram_business_content_publish, instagram_business_manage_insights`.
2. `GET /oauth/instagram/callback` (`InstagramCallbackController`) : `state` → erreur → échange du code
   (`POST https://api.instagram.com/oauth/access_token`, le suffixe `#_` éventuel est retiré) → jeton
   **longue durée** (`ig_exchange_token`, 60 jours) → permissions de base et de publication exigées
   (`PUBLISH_SCOPE_DENIED` sinon) → profil (`GET /me` : `user_id`, `username`, `account_type`).
3. `ConnectInstagramAccount` : compte personnel refusé (`NOT_PROFESSIONAL_ACCOUNT`) ; sinon un compte
   par identifiant Instagram (`business` ou `creator`), un seul jeton actif chiffré, permissions accordées.

**Jeton (`InstagramTokenManager`) :** 60 jours, renouvelé sous verrou (`ig_refresh_token`) quand il expire
dans moins de 10 jours et a au moins 24 h ; échec passager → jeton actuel conservé ; expiré ou refusé →
compte « à reconnecter ». L'échange et le renouvellement n'admettent que des paramètres d'URL
(documentation officielle) : ces URL ne sont jamais journalisées.

### Publication (`InstagramPublisher`, deux temps)

1. **Conteneur** `POST /{ig-user-id}/media` : photo (`image_url`, `caption`), Reel (`media_type=REELS`,
   `video_url`, `share_to_feed`, `cover_url` facultatif), story (`media_type=STORIES`), carrousel (un
   conteneur `is_carousel_item` par photo, puis `media_type=CAROUSEL` + `children`).
2. **Publication** `POST /{ig-user-id}/media_publish` (`creation_id`) → identifiant du média, puis lien
   public (`permalink`).

Photos : publiées aussitôt (média pas encore prêt, code 9007 → suivi). **Vidéos** (Reel, story vidéo) :
`PublishResult::processing(conteneur)` puis `checkStatus()` (`GET /{conteneur}?fields=status_code`) :
`IN_PROGRESS` → nouvelle vérification ; `FINISHED` → `media_publish` ; `ERROR` → `MEDIA_PROCESSING_FAILED` ;
`EXPIRED` → `CONTAINER_EXPIRED` ; `PUBLISHED` ou statut inattendu → résultat inconnu.

Refusés avant tout envoi : aucun média (`MEDIA_REQUIRED`), photo non JPEG (`MEDIA_TYPE_NOT_SUPPORTED`,
exigence d'Instagram), Reel sans exactement une vidéo, carrousel de moins de 2 ou plus de 10 photos, ou
avec vidéo (V1), légende de plus de 2 200 caractères ou 30 hashtags. Premier commentaire : non pris en
charge (permission distincte).

Erreurs : 190 → `TOKEN_INVALID` ; code 9 / sous-code 2207042 → `PUBLISH_LIMIT_REACHED` temporaire (100
publications par 24 h) ; 4, 17, 32, 613 → `RATE_LIMITED` ; 10, 200-299 → `PERMISSION_DENIED` ; 36003 et
sous-codes 2207xxx → `MEDIA_REJECTED` ; 5xx ou codes 1, 2 → résultat inconnu.

Tests : `InstagramOAuthTest` (9), `InstagramPublishingTest` (7) — API SIMULÉE.

### Test réel (Facebook et Instagram)

1. Renseignez les variables ci-dessus et déclarez les deux URI de retour dans l'app Meta.
2. Tant que l'app est en mode développement : utilisez un compte ayant un rôle sur l'app (Admin, ou
   Testeur / Testeur Instagram ajouté dans Rôles de l'app).
3. Réseaux sociaux › Facebook › Connecter : cochez la Page ; Réseaux sociaux › Instagram › Connecter.
4. Composer : texte + photo vers la Page ; photo JPEG puis Reel vers Instagram ; vérifiez « Publié » et
   les liens.
