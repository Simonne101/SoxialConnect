# Architecture de sécurité

La sécurité est une exigence centrale. Chaque mesure décrite ici est **obligatoire** sauf mention « recommandé ».

---

## 1. Modèle de menaces

### 1.1 Actifs
| Actif | Criticité | Pourquoi |
|---|---|---|
| Tokens OAuth des réseaux sociaux | **Critique** | Permettent de publier au nom de l'utilisateur et de lire des données privées |
| Secrets des applications (client secrets, clé de chiffrement des tokens, APP_KEY, clés IA) | **Critique** | Compromission de tous les utilisateurs |
| Identifiants utilisateurs (hash, sessions) | Élevée | Prise de contrôle de compte |
| Médias privés et brouillons | Élevée | Contenus non publiés, données personnelles |
| Audit logs | Moyenne | Preuves en cas d'incident |
| Disponibilité de la publication | Moyenne | Publication manquée ou en double = préjudice d'image |

### 1.2 Acteurs de menace
Attaquant externe anonyme ; utilisateur authentifié malveillant (IDOR, escalade) ; attaquant sur la chaîne
d'approvisionnement (dépendances npm ou Composer) ; fichier piégé (upload) ; webhook forgé ; contenu hostile
dans les réponses des plateformes ou de l'IA (injection de prompt, XSS stockée).

### 1.3 Menaces principales (STRIDE résumé)
| Menace | Surface | Mesures (section) |
|---|---|---|
| Usurpation de compte | Login, reset | Argon2id, rate limit, vérification email, 2FA future, régénération de session (§3) |
| IDOR / contrôle d'accès cassé | Toutes les routes `{uuid}` | Policies + scopes de propriété + tests systématiques (§4) |
| Vol de tokens | Base, logs, frontend | Chiffrement dédié, masquage, jamais exposés (§5) |
| Détournement OAuth | Callback | `state` lié à la session, PKCE, redirect URI exacte (§6) |
| RCE par fichier | Upload, FFmpeg | Validation magic bytes, antivirus, FFmpeg isolé (§7, §8) |
| SSRF | Import vidéo par URL, avatars | Liste blanche de schémas, blocage des IP privées, pas de redirections non contrôlées (§9) |
| Webhook forgé ou rejoué | `/webhooks/*` | Signature HMAC, anti-rejeu, idempotence (§10) |
| XSS | Contenus, noms de fichiers, sorties IA | Échappement React, CSP stricte, pas de `dangerouslySetInnerHTML`, SVG interdit (§11) |
| CSRF | API à cookie | Sanctum CSRF, SameSite, CORS strict (§11) |
| Déni de service ou coût | IA, vidéo, uploads | Rate limiting à plusieurs niveaux, quotas, timeouts (§12) |
| Fuite par les logs | Logs, Sentry, failed_jobs | Expurgation centralisée (§13) |
| Perte de données | DB, S3 | Sauvegardes 3-2-1 testées (§14) |

## 2. Principes
Défense en profondeur ; moindre privilège (rôles SQL, IAM S3, utilisateurs système) ; refus par défaut ;
le serveur est la seule autorité ; aucun secret dans le code, Git, les logs, les URLs ou le frontend.

## 3. Authentification
- **Sanctum SPA** (session et cookie) + **Fortify** (flux headless : login, register, reset, vérification email, 2FA) — ADR-005.
- Cookie de session : `HttpOnly`, `Secure`, `SameSite=Lax`, préfixe `__Host-` si le domaine le permet, durée
  configurable ; « se souvenir de moi » = cookie remember distinct, révocable.
- CSRF : `/sanctum/csrf-cookie` + en-tête `X-XSRF-TOKEN` ; `SANCTUM_STATEFUL_DOMAINS` explicite.
- Hachage : **Argon2id** (`HASH_DRIVER=argon2id`), paramètres à calibrer (~250 ms). L'extension PHP est disponible sur le poste (constaté).
- Politique de mot de passe : ≥ 12 caractères, `Password::uncompromised()` (k-anonymity HIBP), pas de règles de composition arbitraires.
- Régénération de l'ID de session au login ; invalidation des autres sessions au changement de mot de passe.
- Messages d'erreur génériques (pas d'énumération d'emails), y compris au reset et à l'inscription.
- Vérification email obligatoire (`verified`) avant toute action sensible (connexion OAuth, upload, publication).
- **2FA TOTP** : colonnes prêtes, activation par Fortify ; codes de secours chiffrés ; confirmation par mot de passe (`password.confirm`) pour les actions sensibles.
- Écran Sécurité : sessions actives (driver de session `database`) et révocation.

## 4. Autorisation
- **Chaque modèle racine a une Policy** (`PostPolicy`, `MediaPolicy`, `SocialAccountPolicy`, `VideoProjectPolicy`…),
  appelée dans chaque `FormRequest::authorize()` ou contrôleur. `Gate::before` réservé à l'admin système (hors V1 côté UI).
- **Double barrière contre les IDOR** : (1) la Policy vérifie `owner == actor` ; (2) les requêtes de liste et de
  résolution passent par `Model::ownedBy($actor)`, et les ressources enfants par **scoped bindings**
  (`/posts/{post}/variants/{variant:id}` → la variante doit appartenir au post).
- Les identifiants reçus dans le corps (par exemple `media_ids[]` ou `social_account_ids[]`) sont **aussi**
  vérifiés : règle de validation `exists` restreinte au propriétaire, puis Policy.
- Pas d'assignation de masse : `$fillable` explicite, jamais `$guarded = []`. Les champs `user_id`, `status`,
  `external_*` et `published_at` ne sont jamais acceptés du client.
- Middleware : `auth:sanctum`, `verified`, `throttle:<niveau>`, `password.confirm` pour les actions critiques.
- Tests : pour chaque endpoint, un test « un autre utilisateur reçoit 404 ou 403 » (convention : **404** pour ne pas révéler l'existence de la ressource).

## 5. Tokens OAuth
- Chiffrement **côté serveur** avant écriture, dans un cast Eloquent `EncryptedToken` qui utilise une instance
  `Illuminate\Encryption\Encrypter` **dédiée** (`aes-256-gcm`), avec une clé distincte d'`APP_KEY`
  (`TOKEN_ENCRYPTION_KEY`), et `encryption_key_version` pour la rotation (ADR-008).
  Rotation : ajout d'une nouvelle clé, job de rechiffrement, retrait de l'ancienne.
- Jamais : dans une réponse API, dans le frontend, dans un log, dans une URL, dans un payload de job
  (le job reçoit `social_account_id` et déchiffre au dernier moment), dans une exception, dans `failed_jobs`, dans Git.
- Modèle `SocialToken` : `$hidden`, aucune `Resource`, `__toString` et `toArray` neutralisés ; les accès sont limités au domaine `Social`.
- Refresh préventif (avant `expires_at` − marge) sous **verrou Redis par compte**, pour éviter les refresh concurrents
  qui invalident un refresh token à usage unique. Un échec passe le compte à `expired` ou `error`, avec notification.
- Révocation distante à la déconnexion quand l'API le permet, puis effacement des valeurs chiffrées.
- Le réseau de la base de données est privé et ses sauvegardes sont chiffrées. La clé de chiffrement n'est **pas** stockée avec les sauvegardes.

## 6. OAuth (connexion des réseaux)
- Paramètre `state` aléatoire (≥ 32 octets), lié à la session et à l'utilisateur, usage unique, expiration 10 min.
- **PKCE (S256)** quand la plateforme le supporte (X l'exige ; autres `TO_VERIFY`).
- Redirect URI **exacte** et enregistrée chez chaque plateforme ; aucune redirection vers une URL fournie par le client (open redirect).
- Scopes minimaux, demandés par capacité utilisée. Les scopes réellement accordés sont relus (`social_permissions`), car l'utilisateur peut en décocher.
- Endpoints Meta obligatoires : **deauthorize callback** et **data deletion callback** (requête signée `signed_request` vérifiée).

## 7. Uploads
Pipeline obligatoire :

```text
Upload → Authentification → Autorisation (policy + quotas) → Taille (avant réception complète)
→ Extension (liste blanche) → MIME déclaré → Magic bytes (finfo) → Antivirus (ClamAV)
→ Stockage privé (nom généré) → Queue → Traitement (Intervention/FFmpeg) → Validation de la sortie
```

| Type | Formats autorisés (proposition) | Taille max (proposition, configurable) |
|---|---|---|
| image | JPEG, PNG, WebP, GIF | 20 Mo, 12 000 px de côté max (protection contre les bombes de décompression) |
| video | MP4 (H.264/H.265/AAC), MOV, WebM ; AVI **à confirmer** (la maquette l'annonce, format peu adapté au web) | 1 Go (maquette), durée max configurable |
| audio | MP3, AAC/M4A, WAV | 200 Mo |
| document | PDF | 25 Mo |

- **SVG, HTML, XML et exécutables interdits.** L'extension n'est jamais une preuve : les magic bytes font foi et doivent être cohérents avec l'extension.
- Les images sont **réencodées** (suppression des métadonnées EXIF et GPS, neutralisation des polyglottes).
- Fichier en quarantaine tant que `scan_status != clean`. Fichier infecté : suppression, audit `MEDIA_REJECTED`, notification.
- Limites en amont : `client_max_body_size` (Nginx), `post_max_size` et `upload_max_filesize` (PHP) ; en production, upload direct vers S3 par URL présignée **contrainte** (taille via `Content-Length`, type, durée ≤ 15 min, clé imposée).
- Servir les fichiers : URLs signées courtes, `Content-Disposition: attachment` pour les documents, `X-Content-Type-Options: nosniff`, domaine de fichiers distinct du domaine applicatif (recommandé).

## 8. FFmpeg (surface d'attaque)
Voir architecture.md §4.7. Exigences : conteneur dédié non root, **sans réseau**, système de fichiers en lecture
seule sauf `/tmp/job-{uuid}`, limites CPU et mémoire (cgroups), `timeout` Process + `-t`, tableau d'arguments
(pas de shell), `-protocol_whitelist file`, démuxeurs et codecs en liste blanche, `ffprobe` préalable, aucun paramètre
brut provenant de l'utilisateur, nettoyage garanti, version de FFmpeg suivie (CVE) et image reconstruite régulièrement.

## 9. SSRF (imports par URL)
L'import vidéo par URL est limité aux **URLs directes vers un fichier** dont l'utilisateur détient les droits (ADR-013).
Exigences : `https` uniquement ; résolution DNS puis blocage des plages privées, loopback, link-local et métadonnées cloud
(`169.254.169.254`), IPv4 et IPv6 ; connexion à l'IP validée (anti DNS rebinding) ; redirections limitées et revalidées ;
taille et durée de téléchargement bornées ; `Content-Type` contrôlé, puis pipeline d'upload complet. Téléchargement exécuté dans le worker, jamais dans la requête HTTP.

## 10. Webhooks
```text
Plateforme → Endpoint → Vérification de signature (HMAC, corps brut, hash_equals) → Horodatage / anti-rejeu
→ Clé d'idempotence (external_event_id ou hash) → INSERT webhook_events (contrainte unique) → 200 → Queue → Traitement
```
- Meta (Facebook, Instagram, Threads) : en-tête `X-Hub-Signature-256` = HMAC-SHA256 du corps avec l'app secret ;
  vérification d'abonnement `hub.mode` / `hub.verify_token` / `hub.challenge` (`TO_VERIFY` pour Threads).
- Autres plateformes : mécanisme propre, documenté dans api-integration.md (`TO_VERIFY`).
- Signature invalide : 401, **pas** de stockage du payload, compteur et alerte en cas de pic.
- Doublon (violation de contrainte unique) : 200 sans retraitement.
- Traitement idempotent : le job vérifie `status` avant d'agir.
- Endpoints exclus du CSRF, limités en débit par plateforme, taille de corps bornée (1 Mo).

## 11. Sécurité de l'API et du navigateur
Ordre de traitement de toute route : **Authentification → Autorisation → Validation → Logique métier**.

- **CORS** : `allowed_origins` = liste explicite (URL du frontend), `supports_credentials=true`, **jamais `*`**.
- **En-têtes** (middleware et proxy) : `Strict-Transport-Security: max-age=63072000; includeSubDomains` ;
  `Content-Security-Policy` stricte côté SPA (`default-src 'self'`, `script-src 'self'`, `img-src 'self' <bucket> data:`,
  `connect-src 'self' <api>`, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`) ;
  `X-Content-Type-Options: nosniff` ; `Referrer-Policy: strict-origin-when-cross-origin` ; `Permissions-Policy` minimale.
- HTTPS partout (redirection 301 au proxy), cookies `Secure`.
- Validation stricte par `FormRequest` : types, bornes, énumérations, tailles de tableaux ; les clés inconnues sont ignorées (`validated()` uniquement).
- Payload JSON limité (1 Mo sauf exceptions), pagination bornée.
- Gestion centralisée des erreurs (`bootstrap/app.php` → `withExceptions`) : pas de stack trace en production (`APP_DEBUG=false`), `request_id` renvoyé.
- XSS : React échappe par défaut ; `dangerouslySetInnerHTML` interdit (règle ESLint) ; les contenus des plateformes et de l'IA sont traités comme du texte brut ; liens externes en `rel="noopener noreferrer"`.
- **Injection de prompt** : les sorties IA ne déclenchent jamais d'action automatique (publication, suppression) ; elles sont validées par schéma et relues par l'utilisateur.

## 12. Rate limiting
Défini dans `AppServiceProvider` via `RateLimiter::for()`, stocké dans Redis. Valeurs **initiales**, configurables :

| Niveau | Clé | Limite initiale |
|---|---|---|
| `auth-login` | email + IP | 5/min, puis verrouillage progressif |
| `auth-sensitive` (register, reset, renvoi de vérification) | IP | 3/min, 20/h |
| `api` | user (ou IP si anonyme) | 120/min |
| `upload` | user | 30/h + quota de volume journalier |
| `publish` | user | 60/h (plus les limites propres à chaque plateforme, appliquées par l'adaptateur) |
| `ai` | user | 20/min, 300/jour |
| `clipping` | user | 5 projets simultanés, 20/jour |
| `analytics-sync` (manuel) | user + compte | 1 toutes les 15 min |
| `oauth-connect` | user | 10/h |
| `webhooks` | plateforme + IP | 600/min |

Les quotas des plateformes (limites de publication et d'API par 24 h) sont gérés **en plus**, par adaptateur, avec des compteurs Redis.

## 13. Logs et audit
- **Logs applicatifs** (Monolog JSON, canal `stderr` en conteneur) : erreurs, warnings, performance, exceptions, avec
  `request_id`, `user_id` et `job_uuid`.
- **Expurgation obligatoire** : un processeur Monolog `SecretRedactor` masque les clés `password`, `password_confirmation`,
  `access_token`, `refresh_token`, `token`, `secret`, `client_secret`, `api_key`, `authorization`, `cookie`, `session`,
  `signed_request`, `code` (OAuth), ainsi que les motifs de type Bearer ou JWT. Le même redacteur s'applique aux
  `audit_logs.metadata`, `publication_attempts.response_excerpt`, `webhook_events.payload` et au client HTTP sortant.
- `failed_jobs` : les payloads ne contiennent que des identifiants (voir architecture.md §4.4).
- **Audit logs** : table append-only (database.md §3.8), écrite par `AuditLogger` depuis des listeners d'événements. Rétention 1 an (proposition).
- Outil d'erreurs (Sentry ou équivalent, recommandé) avec `send_default_pii=false` et le même filtrage.

## 14. Sauvegardes (stratégie 3-2-1)
| Élément | Méthode | Fréquence | Rétention (proposition) |
|---|---|---|---|
| MySQL | sauvegarde managée + PITR (binlogs) ; `mysqldump --single-transaction` (ou snapshot physique) chiffré exporté | continue + quotidienne | 7 j PITR, 30 quotidiennes, 12 mensuelles |
| Médias S3 | versioning + réplication vers un autre compte ou fournisseur | continue | 30 j de versions |
| Configuration et secrets | gestionnaire de secrets avec historique | à chaque changement | selon l'outil |

- 3 copies, 2 supports, **1 hors de l'environnement principal** (autre compte cloud, avec d'autres identifiants et un verrouillage objet).
- Chiffrement au repos et en transit. La clé de chiffrement des tokens est sauvegardée **séparément** des dumps.
- **Test de restauration** mensuel documenté (RPO cible ≤ 15 min, RTO cible ≤ 4 h — à valider).

## 15. Réponse aux incidents
```text
Détection → Confinement → Investigation → Éradication → Rétablissement → Post-mortem
```
| Étape | Actions (commandes artisan à créer en phase 11) |
|---|---|
| Détection | Alertes : pics de 401/403/429, signatures de webhook invalides, échecs de refresh en masse, erreurs de jobs, accès Horizon |
| Confinement | `security:revoke-sessions {user?}`, `security:suspend-user`, `social:revoke-tokens {account?|--all}`, arrêt d'une queue ou d'un worker (`horizon:pause`), mode maintenance |
| Investigation | Audit logs (par utilisateur, IP, action), logs corrélés par `request_id`, historique des déploiements |
| Éradication | Correctif, rotation de `APP_KEY` (invalide les sessions), rotation de `TOKEN_ENCRYPTION_KEY` (rechiffrement), rotation des client secrets chez chaque plateforme, rotation des clés IA et des identifiants S3/DB/Redis |
| Rétablissement | Restauration de sauvegarde si nécessaire, reconnexion OAuth des comptes, surveillance renforcée |
| Post-mortem | Chronologie, cause racine, actions correctives ; notification CNIL sous 72 h si données personnelles concernées (RGPD art. 33) |

## 16. Chaîne d'approvisionnement et CI
Versions verrouillées (`composer.lock`, `package-lock.json`), `composer audit` et `npm audit` en CI, Dependabot ou
Renovate, scan de secrets (gitleaks) en pre-commit et en CI, installation minimale de paquets, revue de chaque nouvelle dépendance.

## 17. RGPD (à compléter avec un conseil juridique)
Registre des traitements, politique de confidentialité et CGU (exigées aussi par les revues d'applications des
plateformes), export et suppression des données, liste des sous-traitants (hébergeur, stockage, fournisseurs IA,
email), minimisation (pas d'EXIF GPS, rétention bornée des snapshots, webhooks et audit logs).
