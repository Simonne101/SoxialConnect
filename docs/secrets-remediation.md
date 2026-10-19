# Remédiation des secrets exposés — procédure séparée

> **État au 2026-10-19**
> - **Jeton Telegram : renouvelé par la propriétaire du projet.** Vérification en lecture seule :
>   - `php artisan telegram:webhook` : le nouveau jeton répond (`getMe` OK), et le webhook est enregistré pour `message` et `my_chat_member`, sans erreur signalée ;
>   - `security:check-production` : le secret du webhook est présent.
>
>   La révocation de l'**ancien** jeton n'a pas pu être vérifiée par l'assistant (cela demanderait de lire ce jeton). Voir §2, étape 6.
> - **`TOKEN_ENCRYPTION_KEY` : NON renouvelée. Risque OUVERT.** La procédure ci-dessous (§3) est prête et testée (`TokenRotationTest`), mais elle n'a pas été exécutée.

## 1. Inventaire

| Secret | Où il a été exposé | Ce qu'il permet | Dans Git ? |
|---|---|---|---|
| Ancien jeton du bot Telegram | `backend/storage/logs/sociopulse.json-2026-10-05.log`, ligne 47 : URL de la Bot API dans une erreur réseau | S'il est encore valide : publier dans toutes les conversations liées, modifier le webhook | Non : les journaux sont ignorés par Git (vérifié, gitleaks sur tout l'historique) |
| `TOKEN_ENCRYPTION_KEY` (poste de développement) | Sortie d'un outil pendant l'audit de l'étape 8 (transcription de session) | Avec une copie de la base de dev : déchiffrer les jetons OAuth réels | Non : `.env` est ignoré par Git (vérifié) |

**Prévention déjà en place** : les journaux sont expurgés (ADR-070 et ADR-073), y compris le journal de secours et `failed_jobs`, et gitleaks tourne en CI (ADR-075).

## 2. Jeton du bot Telegram

1. Dans BotFather : `/revoke`, puis sélectionner le bot. **Fait par la propriétaire.**
2. Placer le nouveau jeton dans `TELEGRAM_BOT_TOKEN` de chaque environnement :
   - développement : `backend/.env` ;
   - production : `backend.env` du serveur, monté par `deploy/docker/compose.yaml` ;
   - aucun autre fichier (le jeton n'est utilisé que par `TelegramBotClient`, via `config/services.php`).
3. Générer un **nouveau** `TELEGRAM_WEBHOOK_SECRET` (16 à 256 caractères `A-Z a-z 0-9 _ -`).
4. `php artisan config:clear` ; en Docker, redémarrer les conteneurs : les caches sont reconstruits au démarrage.
5. `php artisan telegram:webhook --set`. Le webhook pointe vers une URL **HTTPS publique** :
   - en développement, celle du tunnel ngrok, valable seulement tant que le tunnel tourne ;
   - en production, `https://<domaine>/api/v1/webhooks/telegram`. **À refaire au déploiement** : le webhook actuel désigne le tunnel de développement.
6. Vérifier :
   - `php artisan telegram:webhook` affiche le bot, l'URL et l'absence d'erreur (**fait le 2026-10-19 en développement**) ;
   - avec l'**ancien** jeton, un appel `getMe` doit répondre **401 Unauthorized**. À faire par la propriétaire : depuis son poste, sans coller le jeton dans un outil partagé ni dans une conversation.
7. Avec l'accord de la propriétaire, retirer la ligne compromise de `sociopulse.json-2026-10-05.log` ou supprimer ce fichier. Une fois le jeton révoqué, il n'a plus de valeur d'accès, mais le fichier reste sensible.

## 3. `TOKEN_ENCRYPTION_KEY` — rotation contrôlée

### 3.1 Ce qui dépend de la clé (vérifié dans le code)

- **Données chiffrées** : uniquement les colonnes `social_tokens.access_token_encrypted` et `refresh_token_encrypted` (cast `EncryptedToken`). Ni Telegram, ni les sessions, ni les médias ne sont concernés : ils dépendent d'`APP_KEY`.
- **Mécanisme** (`TokenEncrypter`) :
  - AES-256-GCM, avec une clé de 32 octets distincte d'`APP_KEY` ;
  - chaque valeur est préfixée par sa version (`v1:…`), et la colonne `encryption_key_version` reflète la dernière écriture ;
  - les anciennes clés restent lisibles tant qu'elles figurent dans `TOKEN_ENCRYPTION_PREVIOUS_KEYS` (`<version>:<clé>`, séparées par des virgules).
- **Processus concernés** : tous ceux qui lisent ou écrivent un jeton, et qui doivent donc tous avoir la même configuration.
  - API : rappels OAuth et synchronisation des comptes ;
  - workers : publication, statistiques, rafraîchissement des jetons ;
  - planificateur.
- **Base de développement au 2026-10-19** : 4 jetons, tous en v1. C'est un comptage par préfixe, sans aucun déchiffrement.
- **Si la clé est remplacée SANS conserver l'ancienne** : ces jetons deviennent illisibles. La publication et les statistiques de ces comptes échouent, et il faut les reconnecter. Rien n'est perdu côté plateformes.

### 3.2 Procédure (à exécuter par la propriétaire, ou avec son accord explicite)

1. **Sauvegarde** :
   - copie de la base (`mysqldump` de la table `social_tokens` au minimum) ;
   - copie de l'ancienne clé dans un gestionnaire de secrets.
2. **État initial** : `php artisan security:reencrypt-tokens --dry-run`. Le compte rendu donne le nombre de jetons par version, sans aucune valeur, et la commande doit réussir (« Illisibles : 0 »).
3. **Nouvelle clé, en compatibilité** :
   - `TOKEN_ENCRYPTION_KEY=<nouvelle clé>`, générée avec `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` ;
   - `TOKEN_ENCRYPTION_KEY_VERSION=2` ;
   - `TOKEN_ENCRYPTION_PREVIOUS_KEYS=1:<ancienne clé>`.

   Puis `php artisan config:clear` et le redémarrage de **tous** les processus : pile de développement ou conteneurs. Les jetons v1 restent lisibles, et toute nouvelle écriture se fait en v2.
4. **Vérification** : `security:reencrypt-tokens --dry-run`. « Illisibles : 0 » est attendu, et « À rechiffrer : N ».
5. **Rechiffrement** : `security:reencrypt-tokens`. La commande traite les lignes une par une, sous le verrou de rafraîchissement de chaque compte, et seulement si la valeur n'a pas changé entre-temps. En cas de « Modifiés pendant l'opération », la relancer.
6. **Validation** :
   - `--dry-run` doit indiquer « Déjà à la clé actuelle » égal au total ;
   - une synchronisation de compte (`analytics:sync` ou le bouton « Synchroniser ») doit réussir, **sans publication réelle**.
7. **Retrait de l'ancienne clé**, quelques jours plus tard : vider `TOKEN_ENCRYPTION_PREVIOUS_KEYS`, redémarrer, puis relancer `--dry-run`, qui doit réussir. Si elle échoue, remettre l'ancienne clé : rien n'a été perdu.
8. **Révocation côté plateformes** (recommandée) : révoquer les autorisations accordées pendant la période d'exposition, puis reconnecter les comptes. Le jeton déchiffrable avec l'ancienne clé est alors périmé.

### 3.3 Retour arrière

- **Avant l'étape 5** : remettre l'ancienne configuration (clé v1, sans `PREVIOUS_KEYS`) ; rien n'a été réécrit.
- **Après l'étape 5** : `TOKEN_ENCRYPTION_KEY=<ancienne>`, `TOKEN_ENCRYPTION_KEY_VERSION=1`, `TOKEN_ENCRYPTION_PREVIOUS_KEYS=2:<nouvelle>`. Tous les jetons restent lisibles (testé), et `security:reencrypt-tokens` peut alors les ramener en v1.
- **Dernier recours** : restaurer la sauvegarde de `social_tokens` (étape 1) avec l'ancienne clé.

**Production** : la clé de production est **générée sur le serveur** et ne reprend jamais celle du développement. La base de production ne contient aucun jeton chiffré avec la clé exposée, à condition qu'elle ne soit pas une copie de la base de dev.

## 4. Risques résiduels

- **Ancien jeton Telegram** : tant que sa révocation n'est pas confirmée (401 sur `getMe`), quiconque l'obtient, par l'ancien journal ou une copie du poste, peut agir au nom du bot.
- **`TOKEN_ENCRYPTION_KEY` exposée** : tant qu'elle n'est pas tournée et que les autorisations ne sont pas révoquées, la transcription de session **plus** une copie de la base de dev permettent de déchiffrer 4 jetons OAuth réels.
