# SocialPulse — Backend (Laravel)

API JSON `/api/v1` de SocialPulse : monolithe modulaire Laravel, authentification SPA par session
(Sanctum + Fortify), MySQL, Redis. Documentation de référence : [`../docs`](../docs).

## Prérequis (développement sous Windows / Laragon)

- PHP 8.3+ (extensions `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`), Composer 2
- MySQL 8.0.30 (Laragon) — production visée : MySQL 8.4 LTS
- Redis (Laragon, 127.0.0.1:6379) — client PHP `predis` (l'extension `redis` n'est pas requise)

## Installation

```bash
cd backend
composer install
cp .env.example .env          # puis renseigner DB_PASSWORD et générer les clés ci-dessous
php artisan key:generate
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"   # → TOKEN_ENCRYPTION_KEY
php artisan migrate --seed    # schéma docs/database.md + référentiel des 8 plateformes
php artisan serve --host=localhost --port=8000
```

Base de données : créer `sociopulse` (et `sociopulse_test` pour les tests) en `utf8mb4_unicode_ci`, avec un
utilisateur MySQL dédié qui n'a des droits que sur ces deux bases (jamais `root`).

Le frontend (`../frontend`) utilise l'API si `VITE_API_URL=http://localhost:8000` est défini dans
`frontend/.env.local`. Frontend et API doivent partager le même domaine (`localhost`) pour la session :
ouvrir l'application sur `http://localhost:5173`, pas `127.0.0.1`.

## Tests

```bash
php artisan test          # base MySQL « sociopulse_test » (contraintes réellement testées)
./vendor/bin/pint         # formatage
composer audit            # vulnérabilités connues des dépendances
```

## Organisation

```text
app/
├── Domain/            # logique métier par domaine (enums, actions, règles)
│   ├── Content/       # SavePost, exceptions
│   ├── Publishing/    # PostStatusAggregator
│   ├── Social/        # enums, chiffrement des tokens (TokenEncrypter, EncryptedToken)
│   ├── Media/ Video/ AI/ Analytics/ Users/   # enums (logique à venir dans les phases dédiées)
├── Http/
│   ├── Controllers/Api/V1/   # contrôleurs minces
│   ├── Requests/             # validation (FormRequest) + anti-IDOR sur les identifiants reçus
│   ├── Resources/            # sérialisation (aucun secret exposé)
│   ├── Responses/Auth/       # réponses JSON de Fortify
│   └── Middleware/           # X-Request-Id, en-têtes de sécurité, JSON invalide → 400
├── Models/            # Eloquent (UUID v7), trait BelongsToUser (scope ownedBy)
├── Policies/          # propriété des ressources, refus en 404
├── Services/Audit/    # AuditLogger + AuditAction (journal append-only)
├── Listeners/         # traces d'audit des événements d'authentification
└── Support/           # ApiResponse, SecretRedactor, contraintes MySQL
```

## Endpoints (phase 2)

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | Cookie CSRF de la SPA |
| POST | `/api/v1/auth/register` · `login` · `logout` | Inscription, connexion, déconnexion (session) |
| POST | `/api/v1/auth/forgot-password` · `reset-password` | Réinitialisation (réponse identique si le compte n'existe pas) |
| POST | `/api/v1/auth/email/verification-notification` | Renvoi de l'e-mail de vérification |
| PUT | `/api/v1/auth/password` | Changement de mot de passe |
| GET/PATCH | `/api/v1/users/me` | Utilisateur courant |
| GET | `/api/v1/social/platforms` · `social/accounts` | Référentiel et comptes (lecture seule, pas d'OAuth) |
| GET | `/api/v1/dashboard/summary` | Indicateurs (métriques d'audience : null tant qu'Analytics n'existe pas) |
| CRUD | `/api/v1/content/posts` | Publications (brouillon / programmation, sans publication réelle) |
| GET | `/api/v1/health` | Santé |

Format : succès `{ "data", "message", "meta" }`, erreur `{ "message", "errors" }`.
