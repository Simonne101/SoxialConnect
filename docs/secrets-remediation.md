# Remédiation des secrets exposés — procédure séparée (NON exécutée)

> **Statut au 2026-10-18 : risque OUVERT.** Les deux secrets ci-dessous restent valides. Sur décision de la propriétaire du projet, ils n'ont été ni révoqués, ni remplacés, ni retirés des fichiers où ils figurent. Aucun des deux ne doit être considéré comme sécurisé tant que cette procédure n'a pas été menée à son terme.

## 1. Inventaire

| Secret | Où il a été exposé | Ce qu'il permet | Dans Git ? |
|---|---|---|---|
| Jeton du bot Telegram (bot partagé) | `backend/storage/logs/sociopulse.json-2026-10-05.log`, ligne 47 : URL de la Bot API dans une erreur réseau, avant correction du client | Publier dans toutes les chaînes et tous les groupes liés, lire les mises à jour du bot, modifier son webhook | Non : les journaux sont ignorés par Git (vérifié avant chaque commit) |
| `TOKEN_ENCRYPTION_KEY` (poste de développement) | Sortie d'un outil pendant l'audit de sécurité de l'étape 8 (transcription de la session) | Avec une copie de la base de dev, déchiffrer les jetons OAuth réels (YouTube, LinkedIn, X, TikTok) | Non : `.env` est ignoré par Git (vérifié) |

**Mesures déjà en place** : ces mesures évitent toute **nouvelle** fuite, mais ne corrigent pas les expositions passées.
- Les journaux sont expurgés (ADR-070 et ADR-073), y compris le journal de secours et `failed_jobs`.
- Gitleaks tourne en CI (ADR-075).

## 2. Jeton du bot Telegram

1. Dans BotFather : `/revoke`, puis sélectionner le bot. **Le jeton actuel cesse immédiatement de fonctionner.**
2. Mettre le nouveau jeton dans `TELEGRAM_BOT_TOKEN`, dans le `.env` de chaque environnement concerné.
3. Générer un **nouveau** `TELEGRAM_WEBHOOK_SECRET` (32 octets aléatoires).
4. `php artisan config:clear`, puis `php artisan telegram:webhook --set` pour réenregistrer le webhook avec le nouveau secret.
5. Vérifier : `php artisan telegram:webhook`, sans option, affiche l'état du webhook (URL attendue, aucune erreur). Faire ensuite une publication de test dans une conversation de test.
6. **Avec l'accord de la propriétaire**, retirer la ligne compromise de `sociopulse.json-2026-10-05.log`, ou supprimer le fichier, après en avoir archivé une copie chiffrée si elle sert de preuve. Une fois le jeton révoqué, il n'a plus de valeur, mais le fichier reste une donnée sensible.

Les conversations déjà liées restent liées : elles sont rattachées à l'identifiant de la conversation, pas au jeton.

## 3. `TOKEN_ENCRYPTION_KEY`

**Option A, recommandée, sans nouveau code** : changer de clé et reconnecter les comptes.
1. Sauvegarder la base.
2. Générer une nouvelle clé : `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"`.
3. Placer l'**ancienne** clé dans `TOKEN_ENCRYPTION_PREVIOUS_KEYS`, au format `<version>:<clé>` (par exemple `1:base64:…`), et la nouvelle dans `TOKEN_ENCRYPTION_KEY`, en incrémentant `TOKEN_ENCRYPTION_KEY_VERSION`. Les jetons existants restent lisibles, et chaque jeton rafraîchi est réécrit avec la nouvelle clé.
4. Révoquer côté plateformes les autorisations accordées pendant la période d'exposition, puis reconnecter chaque compte depuis l'application : les nouveaux jetons sont chiffrés avec la nouvelle clé.
5. Quand plus aucun jeton n'utilise l'ancienne version, retirer l'ancienne clé de `TOKEN_ENCRYPTION_PREVIOUS_KEYS`. Une requête SQL en lecture sur `social_tokens.encryption_key_version` permet de le vérifier.

**Option B** : rechiffrer en masse avec la commande `security:reencrypt-tokens` (lot 2 de l'audit). Cette commande **n'existe pas encore**, et l'option ne peut donc pas être utilisée aujourd'hui.

**Production** : la clé de production doit être **générée sur le serveur**, et ne jamais reprendre celle du développement.

## 4. Risques résiduels tant que la procédure n'est pas faite

- Toute personne qui obtient le jeton du bot, par l'ancien fichier de log ou par une copie du poste, peut publier au nom du service dans les conversations liées.
- Toute personne qui obtient la transcription de session **et** une copie de la base de développement peut déchiffrer les jetons OAuth de développement, qui sont de vrais comptes.
- Ces deux secrets sont propres au poste de développement : ils ne doivent **jamais** être repris en production.
