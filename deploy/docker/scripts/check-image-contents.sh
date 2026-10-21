#!/bin/sh
# Contenu des images construites (CI, puis serveur avant mise en service) — lecture seule, aucun conteneur conservé.
#
#   deploy/docker/scripts/check-image-contents.sh <image app> <image worker> <image web>
#
# Chaque vérification affiche son résultat (OK / ÉCHEC) et, en cas d'échec, le message réel de la commande :
# rien n'est masqué. Toutes les vérifications sont exécutées ; code 1 à la fin si l'une d'elles a échoué.
set -u

APP=${1:?Usage : check-image-contents.sh <image app> <image worker> <image web>}
WORKER=${2:?Usage : check-image-contents.sh <image app> <image worker> <image web>}
WEB=${3:?Usage : check-image-contents.sh <image app> <image worker> <image web>}
failures=0

# check <libellé> <image> <commande shell exécutée dans l'image>
check() {
    label=$1
    image=$2
    script=$3
    if output=$(docker run --rm --network none --entrypoint sh "${image}" -c "${script}" 2>&1); then
        echo "OK      ${label}${output:+ — $(printf '%s' "${output}" | head -1)}"
    else
        failures=$((failures + 1))
        echo "ÉCHEC   ${label}"
        printf '%s\n' "${output}" | tail -20 | sed 's/^/        | /'
    fi
}

echo "== Image app (${APP})"
check "extensions PHP requises" "${APP}" \
    'php -r "foreach ([\"pdo_mysql\",\"intl\",\"zip\",\"gd\",\"exif\",\"bcmath\",\"pcntl\",\"mbstring\",\"openssl\",\"curl\",\"fileinfo\",\"Zend OPcache\"] as \$e) { if (!extension_loaded(\$e)) { echo \"extension absente : \$e\", PHP_EOL; exit(1); } } echo \"toutes présentes\", PHP_EOL;"'
check "hachage Argon2id" "${APP}" 'php -r "exit(defined(\"PASSWORD_ARGON2ID\") ? 0 : 1);" && echo disponible'
check "réencodage GD (JPEG)" "${APP}" 'php -r "\$i = imagecreatetruecolor(8, 8); ob_start(); imagejpeg(\$i); exit(strlen(ob_get_clean()) > 0 ? 0 : 1);" && echo OK'
check "configuration PHP-FPM" "${APP}" 'php-fpm -t 2>&1'
check "utilisateur non root (www-data)" "${APP}" 'id; id -u | grep -qx 33'

echo "== Image worker (${WORKER})"
check "FFmpeg 7 ou plus" "${WORKER}" \
    'v=$(ffmpeg -hide_banner -version | head -1); echo "$v"; echo "$v" | grep -Eq "version n?(7|8|9|[1-9][0-9])\."'
check "FFprobe" "${WORKER}" 'out=$(ffprobe -hide_banner -version 2>&1) || { echo "$out"; exit 1; }; echo "$out" | head -1'
check "encodeurs et filtres utilisés (libx264, aac, drawtext)" "${WORKER}" \
    'ffmpeg -hide_banner -encoders | grep -q libx264 && ffmpeg -hide_banner -encoders | grep -q " aac " && ffmpeg -hide_banner -filters | grep -q drawtext && echo présents'
check "mini-rendu réel (syntaxe du code : -/filter_complex, drawtext text_align)" "${WORKER}" \
    'cd /tmp && printf "Bonjour" > t.txt && printf "color=c=black:s=320x240:r=10:d=1,drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:textfile=t.txt:expansion=none:fontsize=24:fontcolor=white:box=1:boxcolor=black@0.5:boxborderw=8:text_align=C:x=(w-tw)/2:y=(h-th)/2,format=yuv420p[vout]" > f.txt && ffmpeg -hide_banner -nostdin -loglevel error -y -/filter_complex f.txt -map "[vout]" -c:v libx264 -t 1 o.mp4 && test -s o.mp4 && echo "MP4 produit"'
check "Node.js" "${WORKER}" 'node --version'
check "yt-dlp" "${WORKER}" 'yt-dlp --version'
check "whisper-cli : bibliothèques" "${WORKER}" \
    'test -x /opt/whisper/bin/whisper-cli || { echo "exécutable absent : /opt/whisper/bin/whisper-cli"; exit 1; }; libs=$(ldd /opt/whisper/bin/whisper-cli 2>&1) || { echo "$libs"; exit 1; }; missing=$(echo "$libs" | grep "not found" || true); [ -z "$missing" ] || { echo "$missing"; exit 1; }; echo "toutes trouvées"'
check "whisper-cli : démarrage" "${WORKER}" \
    'out=$(/opt/whisper/bin/whisper-cli -h 2>&1); code=$?; echo "$out" | grep -qi "usage" && echo "aide affichée (code $code)" || { echo "code $code"; echo "$out"; exit 1; }'
check "modèle whisper (ggml-base)" "${WORKER}" \
    'size=$(stat -c %s /opt/whisper/models/ggml-base.bin) && [ "$size" -gt 100000000 ] && echo "$size octets"'
check "renderer Remotion (dépendances)" "${WORKER}" \
    'test -f /opt/renderer/render.mjs || { echo "absent : /opt/renderer/render.mjs"; exit 1; }; test -d /opt/renderer/node_modules/@remotion/renderer || { echo "absent : /opt/renderer/node_modules/@remotion/renderer"; exit 1; }; echo présent'
check "Chrome headless de Remotion" "${WORKER}" \
    'bin=$(find /opt/renderer/node_modules/.remotion -type f -name "chrome-headless-shell" 2>/dev/null | head -1); [ -n "$bin" ] || { echo "navigateur introuvable sous /opt/renderer/node_modules/.remotion"; exit 1; }; libs=$(ldd "$bin" 2>&1) || { echo "$libs"; exit 1; }; missing=$(echo "$libs" | grep "not found" || true); [ -z "$missing" ] || { echo "$missing"; exit 1; }; out=$("$bin" --version 2>&1) || { echo "$out"; exit 1; }; echo "$out" | head -1'
check "dossier du bundle Remotion (propriété www-data)" "${WORKER}" 'test -d /opt/renderer/.bundle || { echo "absent : /opt/renderer/.bundle"; exit 1; }; owner=$(stat -c "%U" /opt/renderer/.bundle); echo "propriétaire : $owner"; [ "$owner" = www-data ]'
check "utilisateur non root (www-data)" "${WORKER}" 'id; id -u | grep -qx 33'

echo "== Image web (${WEB})"
check "Nginx" "${WEB}" 'out=$(nginx -v 2>&1) || { echo "$out"; exit 1; }; echo "$out"'
check "utilisateur non root" "${WEB}" 'id; [ "$(id -u)" != 0 ]'

echo "== Résultat : ${failures} échec(s)"
[ "${failures}" -eq 0 ]
