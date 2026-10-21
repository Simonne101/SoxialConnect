# syntax=docker/dockerfile:1.7
# =====================================================================================================================
# SoxialConnect — frontend React compilé, servi par Nginx (utilisateur non privilégié), qui relaie aussi l'API
# Laravel (PHP-FPM, service « app ») sur le MÊME domaine : pas de CORS, cookies de session « Secure » et SameSite=Lax.
# Contexte de construction : racine du dépôt. Aucune variable secrète : tout ce qui commence par VITE_ est public.
# =====================================================================================================================
ARG NODE_VERSION=24.22.0
ARG NGINX_VERSION=1.28.2
ARG NODE_DIGEST=sha256:a5f4366e834695d5f363b920c104953a535d3a9864d1bdf74566a0ebe01daee5
ARG NGINX_DIGEST=sha256:7377697a821c131a924a7105fafbe7414db4e9fcc77a6f08f776f33f141ec3f8

FROM node:${NODE_VERSION}-bookworm-slim@${NODE_DIGEST} AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
# « same-origin » : le navigateur appelle /api et /sanctum sur le domaine du site (relayés ci-dessous).
ENV VITE_API_URL=same-origin \
    VITE_APP_NAME=SoxialConnect
RUN npm run build

FROM nginxinc/nginx-unprivileged:${NGINX_VERSION}-alpine@${NGINX_DIGEST} AS web
COPY deploy/docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY deploy/docker/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY deploy/docker/nginx/laravel-fastcgi.conf /etc/nginx/snippets/laravel-fastcgi.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080 8443
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
