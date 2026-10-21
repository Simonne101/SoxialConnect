# syntax=docker/dockerfile:1.7
# =====================================================================================================================
# SoxialConnect — images du backend Laravel (ADR-074). Contexte de construction : racine du dépôt.
#
#   target « app »    : PHP-FPM (API HTTP). N'exécute JAMAIS de binaire vidéo (ADR-060) : ni FFmpeg, ni Node.
#   target « worker » : PHP CLI + FFmpeg/FFprobe + yt-dlp + whisper.cpp + Node/Remotion (Chrome headless) —
#                       workers des files et planificateur (qui vérifie les binaires locaux).
#
# Aucun secret dans l'image : le fichier .env de production est monté à l'exécution (voir compose.yaml).
# Versions figées ; à mettre à jour volontairement (et à reconstruire régulièrement : CVE de FFmpeg, Chrome, PHP).
# Base Debian trixie : FFmpeg 7.1 (bookworm ne fournit que FFmpeg 5.1 ; le code reste compatible de 5.1 à 9, ADR-077).
# =====================================================================================================================
ARG PHP_VERSION=8.3.35
ARG NODE_VERSION=24.22.0
ARG COMPOSER_VERSION=2.10.3
# Empreintes des images de base (deploy/docker/image-digests.txt, vérifiables par scripts/check-image-digests.sh).
ARG PHP_FPM_DIGEST=sha256:ed67b5fcd7600614dd651621a04214fbe16d373262cb31eb50bc224c18cf0e88
ARG PHP_CLI_DIGEST=sha256:aafe21201943a8a6e497ddfb471e2ce68ada76adede38c7d61fede8918b24319
ARG COMPOSER_DIGEST=sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac
ARG NODE_DIGEST=sha256:7f486cabe892bff8fef04c0f7da8dba84a560be3e4e2740b7a86afc57ebec315
ARG DEBIAN_DIGEST=sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f

FROM composer:${COMPOSER_VERSION}@${COMPOSER_DIGEST} AS composer

FROM node:${NODE_VERSION}-trixie-slim@${NODE_DIGEST} AS node

# --- Extensions PHP communes (compilées pour FPM et pour la CLI) --------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie@${PHP_FPM_DIGEST} AS php-fpm-base
COPY --chmod=0755 deploy/docker/php/install-extensions.sh /usr/local/bin/install-extensions
RUN install-extensions

FROM php:${PHP_VERSION}-cli-trixie@${PHP_CLI_DIGEST} AS php-cli-base
COPY --chmod=0755 deploy/docker/php/install-extensions.sh /usr/local/bin/install-extensions
RUN install-extensions

# --- Dépendances Composer (sans les paquets de développement) ------------------------------------------------------
FROM php-cli-base AS vendor
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -rf storage/logs/* storage/framework/cache/data/* storage/framework/sessions/* storage/framework/views/*

# --- whisper.cpp (transcription locale du Clipper), compilé depuis une version figée ------------------------------
FROM debian:trixie-slim@${DEBIAN_DIGEST} AS whisper
ARG WHISPER_CPP_VERSION=v1.9.4
# Empreinte du modèle « base » publié par le projet whisper.cpp (vérifiée sur le poste de développement).
ARG WHISPER_MODEL_SHA256=60ed5bc3dd14eea856493d334349b405782ddcaf0028d4b5df4088345fba2efe
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates git cmake build-essential curl \
    && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 --branch "${WHISPER_CPP_VERSION}" https://github.com/ggml-org/whisper.cpp /src \
    && cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DWHISPER_BUILD_TESTS=OFF \
    && cmake --build /build --config Release --target whisper-cli -j"$(nproc)" \
    && install -D /build/bin/whisper-cli /opt/whisper/bin/whisper-cli \
    && curl -fsSL -o /opt/whisper/models/ggml-base.bin --create-dirs https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin \
    && echo "${WHISPER_MODEL_SHA256}  /opt/whisper/models/ggml-base.bin" | sha256sum -c - \
    && chmod -R a+rX /opt/whisper

# --- yt-dlp : exécutable officiel, empreinte vérifiée (désactivé par défaut : CLIPPER_YTDLP_ENABLED=false) ----------
FROM debian:trixie-slim@${DEBIAN_DIGEST} AS ytdlp
ARG YTDLP_VERSION=2026.08.19
ARG YTDLP_SHA256=58162f9bfdc27458ea47bfcb311cf47028f17d8154a8bf7d689861d46399230a
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -o /usr/local/bin/yt-dlp "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" \
    && echo "${YTDLP_SHA256}  /usr/local/bin/yt-dlp" | sha256sum -c - \
    && chmod 0755 /usr/local/bin/yt-dlp

# --- Renderer Remotion : dépendances verrouillées + navigateur headless de Remotion -----------------------------
FROM node AS renderer
WORKDIR /opt/renderer
COPY renderer/package.json renderer/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY renderer/ ./
# Dossier du bundle Remotion : présent dans l'image pour que le volume monté ici appartienne à www-data.
RUN mkdir -p .bundle

# =====================================================================================================================
# API (PHP-FPM)
# =====================================================================================================================
FROM php-fpm-base AS app
RUN apt-get update && apt-get install -y --no-install-recommends libfcgi-bin && rm -rf /var/lib/apt/lists/*
COPY deploy/docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY deploy/docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-soxialconnect.conf
COPY --chmod=0755 deploy/docker/php/entrypoint.sh /usr/local/bin/soxial-entrypoint
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1
ENTRYPOINT ["soxial-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

# =====================================================================================================================
# Workers et planificateur (PHP CLI + outils vidéo)
# =====================================================================================================================
FROM php-cli-base AS worker
# FFmpeg/FFprobe (Debian, mis à jour avec l'image), polices pour les sous-titres, OpenMP (whisper.cpp),
# bibliothèques de Chrome headless.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg fonts-dejavu-core ca-certificates libgomp1 \
        libnss3 libdbus-1-3 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 libgbm1 libasound2t64 libxrandr2 \
        libxkbcommon0 libxfixes3 libxcomposite1 libxdamage1 libpango-1.0-0 libcairo2 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=ytdlp /usr/local/bin/yt-dlp /usr/local/bin/yt-dlp
COPY --from=whisper /opt/whisper /opt/whisper
COPY --from=renderer --chown=www-data:www-data /opt/renderer /opt/renderer
COPY deploy/docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY deploy/docker/php/worker.ini /usr/local/etc/php/conf.d/zz-worker.ini
COPY --chmod=0755 deploy/docker/php/entrypoint.sh /usr/local/bin/soxial-entrypoint
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
ENV VIDEO_FFMPEG_BINARY=/usr/bin/ffmpeg \
    VIDEO_FFPROBE_BINARY=/usr/bin/ffprobe \
    VIDEO_FONT_FILE=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf \
    YT_DLP_BINARY=/usr/local/bin/yt-dlp \
    WHISPER_CPP_BINARY=/opt/whisper/bin/whisper-cli \
    WHISPER_CPP_MODEL=/opt/whisper/models/ggml-base.bin \
    REMOTION_RENDERER_PATH=/opt/renderer \
    REMOTION_NODE_BINARY=/usr/local/bin/node \
    HOME=/tmp
USER www-data
# Navigateur headless téléchargé à la construction (jamais pendant un rendu), dans le dossier du renderer.
RUN cd /opt/renderer && node -e "import('@remotion/renderer').then((r) => r.ensureBrowser())"
ENTRYPOINT ["soxial-entrypoint"]
CMD ["php", "artisan", "queue:work", "redis", "--queue=publishing,default,media,analytics", "--sleep=3", "--tries=3", "--max-time=3600"]
