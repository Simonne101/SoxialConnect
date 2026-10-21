#!/bin/sh
# Contrôle des empreintes des images de base (deploy/docker/image-digests.txt) — lecture seule, aucune image modifiée.
#
#   deploy/docker/scripts/check-image-digests.sh
#
# Pour chaque image : (1) l'étiquette pointe-t-elle toujours vers l'empreinte figée ? Sinon, une version corrective a
# été republiée sous la même étiquette : à examiner, puis mettre à jour volontairement le fichier et les Dockerfiles ;
# (2) l'empreinte figée est-elle toujours téléchargeable ? Code 1 si une empreinte figée est introuvable.
set -eu

LIST="$(dirname "$0")/../image-digests.txt"
drift=0
missing=0

while read -r image digest; do
    case "${image}" in ''|'#'*) continue ;; esac
    repo=${image%:*}
    current=$(docker buildx imagetools inspect "${image}" --format '{{json .Manifest.Digest}}' 2>/dev/null | tr -d '"' || true)
    if docker buildx imagetools inspect "${repo}@${digest}" >/dev/null 2>&1; then
        pinned="disponible"
    else
        pinned="INTROUVABLE"
        missing=$((missing + 1))
    fi
    if [ "${current}" = "${digest}" ]; then
        echo "OK       ${image} (${pinned})"
    else
        echo "DÉRIVE   ${image} : étiquette → ${current:-inconnue}, figée → ${digest} (${pinned})"
        drift=$((drift + 1))
    fi
done < "${LIST}"

echo "Dérives : ${drift} ; empreintes figées introuvables : ${missing}"
[ "${missing}" -eq 0 ]
