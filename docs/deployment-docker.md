# Déploiement Docker sur un VPS — procédure (préparée, non exécutée)

> **État au 2026-10-18** : les fichiers de `deploy/docker/` sont prêts. Leur syntaxe a été validée (`docker compose config`), mais **aucune image n'a été construite et rien n'a été déployé**. Avant toute mise en ligne, suivre la liste « Prérequis bloquants » ci-dessous. Décisions : ADR-074 (Docker) et ADR-075 (CI).

## 1. Architecture

```text
Internet ──443/80──▶ web (Nginx, non root : SPA + relais FastCGI)
                         │ réseau « edge »
                         ▼
                      app (PHP-FPM, API Laravel — n'exécute aucun binaire)
                         │ réseau « internal » (sans accès Internet)
     ┌──────────────┬────┴─────────┬──────────────┐
   mysql 8.4     redis 7.4      clamav 1.4    (volumes persistants)
     ▲               ▲              ▲
     └── worker-default · worker-ai · worker-video · scheduler (image « worker » : FFmpeg, yt-dlp,
         whisper.cpp, Node/Remotion) ── réseau « egress » : API des réseaux sociaux, IA, YouTube
```

| Service | Rôle | Limite mémoire |
|---|---|---|
| `web` | Nginx : TLS, HSTS, CSP, limites de corps et de débit | — |
| `app` | API (PHP-FPM, 12 processus au plus) | 1 Go |
| `worker-default` | publications, médias, statistiques | 768 Mo |
| `worker-ai` | appels aux fournisseurs IA | 768 Mo |
| `worker-video` | rendus FFmpeg, Remotion (Chrome), whisper : un à la fois | 3 Go (+ `shm` 1 Go) |
| `scheduler` | `schedule:work` (publications à échéance, purges, statistiques, contrôle des binaires) | 512 Mo |
| `mysql` | base de données | 1,5 Go |
| `redis` | cache, verrous, files (`noeviction`, AOF) | 640 Mo |
| `clamav` | antivirus (clamd + freshclam) | 2,5 Go |

**Serveur conseillé** : 4 vCPU, 16 Go de RAM et 100 Go de SSD minimum, à ajuster selon le volume de médias.

- **Au strict minimum, 8 Go**, mais sans marge : un rendu Remotion (≈ 2 Go en pointe) et ClamAV (≈ 1,5 Go de signatures) en même temps saturent la mémoire.
- **Mesuré sur le poste de développement** : un rendu Remotion 1080×1920 prend ≈ 0,6 à 0,8 s par image, et la transcription whisper ≈ 1,7 fois la durée de la vidéo.

## 2. Prérequis bloquants avant toute mise en production

1. **Secrets exposés** : faire tourner le jeton du bot Telegram et `TOKEN_ENCRYPTION_KEY` (procédure : [secrets-remediation.md](secrets-remediation.md)). Les valeurs de production doivent être **nouvelles**, jamais celles du poste de développement.
2. **Images** : construire une première fois et confirmer que les étiquettes figées existent (`php:8.3.21`, `composer:2.8.9`, `node:24.16.0`, `nginx-unprivileged:1.28.0`, `mysql:8.4.5`, `redis:7.4.3`, `clamav/clamav:1.4.2`). Si une étiquette n'existe pas, choisir la version corrective la plus proche, puis figer l'empreinte (`@sha256:…`).
3. **CI au vert** sur la branche à déployer : tests, audits et gitleaks.
4. **Contrôle de production** dans le conteneur, avec le vrai `.env` : `security:check-production --strict` doit réussir (étape 6).
5. **Restauration testée** d'une sauvegarde sur un serveur de test (étape 9).

## 3. Préparer le serveur

```sh
# Debian 12 / Ubuntu 24.04, utilisateur non root avec sudo, SSH par clé uniquement.
sudo apt-get update && sudo apt-get install -y ca-certificates curl ufw age
# Docker Engine + plugin compose : https://docs.docker.com/engine/install/
sudo ufw default deny incoming && sudo ufw allow OpenSSH && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
sudo mkdir -p /srv/soxialconnect/certs /srv/backups/soxialconnect && sudo chown -R "$USER" /srv/soxialconnect /srv/backups
git clone https://github.com/Simonne101/SoxialConnect.git /srv/soxialconnect/app
```

Docker publie ses ports **en contournant ufw** : seuls 80 et 443 sont publiés par `compose.yaml`, et aucun autre port ne doit l'être.

## 4. Secrets et configuration (fichiers du serveur, jamais dans Git)

```sh
cd /srv/soxialconnect
cp app/deploy/docker/compose.env.example compose.env        # mots de passe MySQL/Redis, domaine, version
cp app/backend/.env.production.example backend.env          # configuration Laravel
chmod 600 compose.env backend.env
```

- **`backend.env`** :
  - `APP_URL` et `FRONTEND_URL` reçoivent la même URL `https://<domaine>`, car Nginx sert le SPA et l'API sur un seul domaine ;
  - `SANCTUM_STATEFUL_DOMAINS=<domaine>`, `CORS_ALLOWED_ORIGINS=https://<domaine>` ;
  - `DB_HOST=mysql`, `REDIS_HOST=redis`, `CLAMAV_HOST=clamav`, `MEDIA_SCAN_DRIVER=clamav` ;
  - les mots de passe reprennent ceux de `compose.env` ;
  - les URI de retour OAuth pointent vers `https://<domaine>/oauth/<réseau>/callback`, à déclarer aussi chez chaque plateforme.
- **Générer les clés** de production, sans jamais réutiliser celles du développement :
  - `APP_KEY` : `docker compose … run --rm --no-deps app php artisan key:generate --show` ;
  - `TOKEN_ENCRYPTION_KEY` : `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"`.
- **Sauvegarder `TOKEN_ENCRYPTION_KEY`** à part, dans un gestionnaire de secrets. Sans elle, les jetons OAuth sauvegardés sont illisibles.
- **Ne pas définir `TRUSTED_PROXIES`** : Nginx transmet lui-même l'IP du client et `HTTPS=on` à PHP. Ne la remplir que si un autre proxy, comme un CDN, précède Nginx.

## 5. Certificat TLS

Exemple avec certbot en mode autonome, avant le premier démarrage. Les renouvellements passent ensuite par `/.well-known/acme-challenge/` ou un hook :

```sh
sudo certbot certonly --standalone -d <domaine>
sudo cp -L /etc/letsencrypt/live/<domaine>/{fullchain.pem,privkey.pem} /srv/soxialconnect/certs/
sudo chown -R 101:101 /srv/soxialconnect/certs && sudo chmod 600 /srv/soxialconnect/certs/privkey.pem
```

## 6. Construire, démarrer, migrer

```sh
cd /srv/soxialconnect/app
C="docker compose --env-file /srv/soxialconnect/compose.env -f deploy/docker/compose.yaml"
$C config -q                         # configuration valide
$C build                             # images : app, worker, web (plusieurs minutes : whisper.cpp est compilé)
$C up -d mysql redis clamav          # ClamAV : 5 à 10 min au premier démarrage (signatures)
$C run --rm app php artisan security:check-production --strict   # DOIT réussir
$C run --rm app php artisan migrate --force                      # étape explicite, après sauvegarde
$C run --rm worker-default php artisan media:scan-pending --dry-run   # base reprise d'un autre serveur seulement
$C up -d                             # web, app, workers, scheduler
$C ps                                # tous « healthy » / « running »
curl -fsS https://<domaine>/up       # 200
```

## 7. Vérifications après démarrage

- **En-têtes du site** : `curl -sI https://<domaine>/` doit montrer `Strict-Transport-Security`, `Content-Security-Policy`, `X-Content-Type-Options` et `X-Frame-Options`.
- **Taille des corps** : un JSON de plus de 1 Mo envoyé à l'API doit recevoir **413**.
- **Antivirus** :
  - depuis un poste sans antivirus local, un import contenant la chaîne de test EICAR doit être **refusé** (422, `infected`) ;
  - `docker compose … logs clamav` doit montrer l'analyse.
- **Binaires** : `$C exec scheduler php artisan video:check-executables` doit trouver FFmpeg, FFprobe et yt-dlp.
- **Journaux** : le volume `storage-logs` contient `sociopulse.json-AAAA-MM-JJ.log`, en JSON et expurgé ; `docker compose logs` limite chaque fichier à 20 Mo × 5.
- **Publication réelle** : à faire uniquement sur un compte de test, avec l'accord de la propriétaire.

## 8. Mettre à jour

```sh
git -C /srv/soxialconnect/app pull --ff-only
# SOXIAL_VERSION=<nouvelle version> dans compose.env
deploy/docker/scripts/backup.sh /srv/soxialconnect/compose.env /srv/backups/soxialconnect
$C build && $C run --rm app php artisan migrate --force && $C up -d
```

- **Arrêt des workers** : ils reçoivent SIGTERM et terminent leur job en cours. Un rendu vidéo dispose de 31 min (`stop_grace_period`).
- **Code** : il est figé dans l'image (`opcache.validate_timestamps=0`). Toute mise à jour passe donc par une nouvelle image, jamais par une copie de fichiers.

## 9. Sauvegarde et restauration

- **Sauvegarde quotidienne** :
  - `scripts/backup.sh`, via cron, avec `BACKUP_AGE_RECIPIENT=<clé publique age>` ;
  - contenu : dump MySQL cohérent (`--single-transaction`), puis archive de `storage/app`, chiffrés avec age, avec empreintes SHA-256 et 14 jours de rétention.
- **Copie hors site** : l'outil (rclone, restic) est à choisir ; la destination doit être chez un autre fournisseur, avec d'autres identifiants et un verrouillage objet.
- **Non sauvegardés par le script** : `backend.env`, `compose.env` et la clé `TOKEN_ENCRYPTION_KEY`. Ils vont dans un gestionnaire de secrets, **séparément** des dumps.
- **Restauration** : `AGE_IDENTITY=<clé privée> scripts/restore.sh <compose.env> <dossier>` ; la confirmation demande de taper `RESTAURER`. Elle est à tester **chaque mois** sur un serveur de test (RPO 24 h avec ce script ; pour viser 15 min, ajouter la journalisation binaire de MySQL en continu).

## 10. Limites connues

- **Redis** : un redémarrage sans AOF ferait perdre les réservations de quota en cours. L'AOF est actif, et une réservation oubliée expire au bout de 4 h.
- **Tailles inconnues** : la taille d'une vidéo yt-dlp ou d'une archive Google non annoncée est **estimée** (réserve de 4 Go).
- **Fichiers temporaires** (dossiers de rendu, `/tmp` du worker vidéo en tmpfs de 2 Go) : ils relèvent de la garde disque globale, pas du quota de l'utilisateur.
- **RAM** : elle n'est pas surveillée par l'application. Les limites Docker coupent un rendu qui dépasse 3 Go, et le job échoue proprement.
- **Un seul hôte** : le passage à plusieurs serveurs demande un stockage de médias partagé (S3) et un Redis commun. `onOneServer` est déjà en place sur les tâches globales.
- **ClamAV** : un fichier chiffré (PDF protégé) ou trop gros pour être analysé entièrement est refusé.
