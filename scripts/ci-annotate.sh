#!/usr/bin/env bash
# Transforme la sortie d'un test/commande en annotations GitHub (visibles sans télécharger les journaux).
# Usage : scripts/ci-annotate.sh fichier.txt [nombre_max]
f="${1:?fichier}"; max="${2:-40}"
[ -s "$f" ] || exit 0
{
  grep -E "^\s*(FAIL|⨯|✕)|Tests:|SQLSTATE|Exception|Error|Failed asserting|Unable|not found|Undefined|Call to|Class \"|Could not" "$f" \
    | sed -E 's/^\s+//; s/[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9:.]+Z? ?//' | cut -c1-280 | sort | uniq -c | sort -rn | head -n "$max"
} | while IFS= read -r l; do echo "::error::${l//%/%25}"; done
