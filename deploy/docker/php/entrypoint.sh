#!/bin/sh
# Démarrage d'un conteneur backend : caches de configuration et de routes construits à partir du .env MONTÉ
# (jamais copié dans l'image), puis exécution de la commande. Les migrations ne sont JAMAIS lancées ici : elles sont
# une étape explicite du déploiement (docs/deployment-docker.md).
set -eu
cd /var/www/html

if [ ! -f .env ]; then
    echo "Fichier .env absent : monter le fichier de production en lecture seule sur /var/www/html/.env." >&2
    exit 1
fi

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
# bootstrap/cache est un tmpfs propre au conteneur : manifeste des paquets, puis caches de cette configuration.
php artisan package:discover --no-interaction >/dev/null
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan event:cache --no-interaction >/dev/null

exec "$@"
