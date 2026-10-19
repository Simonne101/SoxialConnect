#!/bin/sh
# Extensions PHP réellement utilisées par SoxialConnect (contrôlées par `php artisan security:check-production`) :
# pdo_mysql (MySQL), intl (Normalizer), zip (archives d'export Google), gd + exif (réencodage des images, EXIF/GPS
# supprimés, orientation), bcmath, pcntl (arrêt propre des workers sur SIGTERM), opcache.
# Déjà présentes dans l'image officielle : mbstring, openssl, curl, ctype, dom, xml, tokenizer, fileinfo, sodium.
#
# Bibliothèques d'exécution : conservées automatiquement d'après les dépendances réelles des extensions compilées
# (méthode des images PHP officielles) — aucun nom de paquet propre à une version de Debian (bookworm, trixie…).
set -eu

savedAptMark="$(apt-mark showmanual)"
apt-get update
apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype-dev
docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype
docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd exif bcmath pcntl opcache

apt-mark auto '.*' >/dev/null
[ -z "${savedAptMark}" ] || apt-mark manual ${savedAptMark} >/dev/null
find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
    | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
    | sort -u \
    | xargs -r dpkg-query --search \
    | cut -d: -f1 \
    | sort -u \
    | xargs -r apt-mark manual >/dev/null
apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false
rm -rf /var/lib/apt/lists/*

# Échec de la construction si une extension ne se charge pas (bibliothèque manquante, par exemple).
for extension in pdo_mysql intl zip gd exif bcmath pcntl; do
    php -m | grep -qi "^${extension}\$" || { echo "Extension PHP non chargée : ${extension}" >&2; exit 1; }
done
php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'
