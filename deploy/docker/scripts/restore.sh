#!/bin/sh
# Restauration d'une sauvegarde produite par backup.sh — DESTRUCTIF pour la base et les fichiers ciblés.
#
#   AGE_IDENTITY=/chemin/cle-privee.txt deploy/docker/scripts/restore.sh /srv/soxialconnect/compose.env /srv/backups/soxialconnect/20261018T030000Z
#
# À exécuter d'abord sur un environnement de TEST (test de restauration mensuel, docs/security.md §14).
# Les workers, le planificateur et l'API sont arrêtés pendant l'opération ; rien n'est lancé automatiquement ensuite.
set -eu

ENV_FILE=${1:?Usage : restore.sh <compose.env> <dossier de sauvegarde>}
SRC=${2:?Usage : restore.sh <compose.env> <dossier de sauvegarde>}
: "${AGE_IDENTITY:?AGE_IDENTITY requis (clé privée age)}"
COMPOSE="docker compose --env-file ${ENV_FILE} -f $(dirname "$0")/../compose.yaml"

(cd "${SRC}" && sha256sum -c SHA256SUMS)

printf 'Restaurer %s ? La base et storage/app actuels seront REMPLACÉS. Taper RESTAURER : ' "${SRC}"
read -r answer
[ "${answer}" = "RESTAURER" ] || { echo "Annulé."; exit 1; }

echo "Arrêt de l'application (la base, Redis et ClamAV restent démarrés)…"
${COMPOSE} stop web app worker-default worker-ai worker-video scheduler

echo "Base de données…"
age -d -i "${AGE_IDENTITY}" "${SRC}/database.sql.gz.age" | gunzip \
    | ${COMPOSE} exec -T mysql sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

echo "Fichiers…"
age -d -i "${AGE_IDENTITY}" "${SRC}/storage-app.tar.gz.age" | gunzip \
    | ${COMPOSE} run --rm --no-deps -T --entrypoint sh scheduler -c 'tar -C /var/www/html/storage -xf -'

echo "Restauration terminée. Vérifier puis redémarrer : ${COMPOSE} up -d"
