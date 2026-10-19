#!/bin/sh
# Extensions PHP réellement utilisées par SoxialConnect (contrôlées par `php artisan security:check-production`) :
# pdo_mysql (MySQL), intl (Normalizer), zip (archives d'export Google), gd + exif (réencodage des images, EXIF/GPS
# supprimés, orientation), bcmath, pcntl (arrêt propre des workers sur SIGTERM), opcache.
# Déjà présentes dans l'image officielle : mbstring, openssl, curl, ctype, dom, xml, tokenizer, fileinfo, sodium.
set -eu

apt-get update
apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev
docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype
docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd exif bcmath pcntl opcache
# Bibliothèques d'exécution seulement : les en-têtes de compilation sont retirés.
apt-get purge -y --auto-remove libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev
apt-get install -y --no-install-recommends libicu72 libzip4 libpng16-16 libjpeg62-turbo libwebp7 libfreetype6
rm -rf /var/lib/apt/lists/*

php -m | grep -qi '^gd$'
php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'
