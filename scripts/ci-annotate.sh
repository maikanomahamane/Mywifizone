#!/usr/bin/env bash
# Transforme la sortie d'un test/commande en annotations GitHub (visibles sans télécharger les journaux).
# Usage : scripts/ci-annotate.sh fichier.txt [nombre_max]
f="${1:?fichier}"; max="${2:-40}"
[ -s "$f" ] || exit 0
awk '/^[[:space:]]*FAILED[[:space:]]/{p=1} p&&NF' "$f" | grep -v -E "^#[0-9]+ " | sed -E 's/\x1b\[[0-9;]*m//g' | cut -c1-200 | head -300 | awk '{printf "%s%%0A", $0} NR%16==0{print ""} END{print ""}' | grep -v '^$' | head -10 | while IFS= read -r l; do echo "::error::BLOC $l"; done
