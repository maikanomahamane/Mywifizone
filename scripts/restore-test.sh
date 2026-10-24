#!/bin/sh
# Test de restauration : restaure la dernière sauvegarde dans une base TEMPORAIRE, vérifie son contenu, puis la supprime.
# À planifier (ex. hebdomadaire) : une sauvegarde jamais restaurée n'est pas une sauvegarde.
# Usage : restore-test.sh [dossier_de_sauvegardes]   Variables : PGHOST PGUSER PGPASSWORD PGDATABASE, BACKUP_PASSPHRASE_FILE
set -eu

DIR="${1:-${BACKUP_DIR:-/backups}}"
LATEST="$(ls -1t "$DIR"/"${PGDATABASE:?PGDATABASE requis}"-* 2>/dev/null | head -n 1 || true)"
[ -n "$LATEST" ] || { echo "aucune sauvegarde dans $DIR" >&2; exit 1; }

TMPDB="restore_test_$(date -u +%s)"
trap 'psql -d postgres -qc "drop database if exists $TMPDB" >/dev/null 2>&1 || true' EXIT

"$(dirname "$0")/restore-postgres.sh" "$LATEST" "$TMPDB"

# Contrôles : tables clés présentes, migrations appliquées, contenu non vide là où il doit l'être.
for t in tenants users subscriptions sales vouchers payment_transactions audit_logs migrations; do
  psql -d "$TMPDB" -Atc "select 1 from information_schema.tables where table_name = '$t'" | grep -q 1 \
    || { echo "ÉCHEC : table $t absente après restauration" >&2; exit 1; }
done
ROLES="$(psql -d "$TMPDB" -Atc "select count(*) from roles")"
MIGS="$(psql -d "$TMPDB" -Atc "select count(*) from migrations")"
[ "$ROLES" -ge 1 ] && [ "$MIGS" -ge 1 ] || { echo "ÉCHEC : rôles=$ROLES migrations=$MIGS" >&2; exit 1; }

echo "test de restauration OK : $LATEST (rôles=$ROLES, migrations=$MIGS)"
