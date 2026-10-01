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

Worker local : `php artisan queue:work redis --queue=publishing,default,media --sleep=1 --timeout=300`.
Il est lancé par `npm run dev`. Après un changement de code : `php artisan queue:restart`.

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
   (`[worker]`), le **planificateur** (`[cron]`, `schedule:work`) et le site.
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

**`privacyStatus` est toujours `private`** pendant cette phase, quelle que soit l'option
« Visibilité » du Composer. Rien n'est publié publiquement sans action manuelle dans YouTube
Studio.

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
6. Vérifiez la vidéo **privée** dans YouTube Studio.

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
| Vidéo verrouillée « privée » | Normal : c'est le choix de cette phase. Tant que le projet Google n'est pas audité, YouTube force aussi les vidéos en privé. |
