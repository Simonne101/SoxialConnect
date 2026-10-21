#!/bin/sh
# Sauvegarde cohérente de SoxialConnect (Docker) : base MySQL puis fichiers (médias, sources, journaux).
#
#   deploy/docker/scripts/backup.sh /srv/soxialconnect/compose.env /srv/backups/soxialconnect
#
# - Base : mysqldump --single-transaction (instantané cohérent InnoDB, sans bloquer l'application).
# - Fichiers : archive du volume storage-app APRÈS le dump. Les médias sont immuables (nom généré, jamais réécrits) :
#   un fichier créé pendant la sauvegarde est au pire un orphelin sans ligne en base, jamais l'inverse.
# - Chiffrement : chaque archive est chiffrée avec age (clé publique BACKUP_AGE_RECIPIENT) si l'outil est présent ;
#   sinon le script s'arrête (aucune sauvegarde en clair hors du serveur). Copie hors site : à la charge de l'exploitant
#   (rclone, restic…), vers un autre fournisseur avec verrouillage objet.
# - La clé TOKEN_ENCRYPTION_KEY et le .env ne sont JAMAIS inclus : ils se sauvegardent à part (gestionnaire de secrets).
set -eu

ENV_FILE=${1:?Usage : backup.sh <compose.env> <dossier de destination>}
DEST=${2:?Usage : backup.sh <compose.env> <dossier de destination>}
RETENTION_DAYS=${RETENTION_DAYS:-14}
COMPOSE="docker compose --env-file ${ENV_FILE} -f $(dirname "$0")/../compose.yaml"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK="${DEST}/${STAMP}"

: "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT requis (clé publique age) : aucune sauvegarde non chiffrée}"
command -v age >/dev/null 2>&1 || { echo "age introuvable : installer age (https://age-encryption.org)." >&2; exit 1; }

umask 077
mkdir -p "${WORK}"

echo "[1/3] Base de données (instantané cohérent)…"
${COMPOSE} exec -T mysql sh -c 'exec mysqldump --single-transaction --quick --routines --triggers --hex-blob \
    -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip -9 | age -r "${BACKUP_AGE_RECIPIENT}" > "${WORK}/database.sql.gz.age"

echo "[2/3] Fichiers (storage/app : médias, sources, rendus)…"
${COMPOSE} run --rm --no-deps -T --entrypoint sh scheduler -c 'tar -C /var/www/html/storage -cf - app' \
    | gzip -1 | age -r "${BACKUP_AGE_RECIPIENT}" > "${WORK}/storage-app.tar.gz.age"

echo "[3/3] Empreintes et rétention (${RETENTION_DAYS} jours)…"
(cd "${WORK}" && sha256sum ./*.age > SHA256SUMS)
find "${DEST}" -mindepth 1 -maxdepth 1 -type d -mtime "+${RETENTION_DAYS}" -exec rm -rf {} +

echo "Sauvegarde terminée : ${WORK}"
