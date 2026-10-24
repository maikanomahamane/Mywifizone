#!/bin/sh
# Restaure une sauvegarde dans UNE BASE CIBLE. Usage : restore-postgres.sh <fichier.dump|.dump.enc> <base_cible> [--force]
# Refuse de restaurer dans une base qui contient déjà des tables, sauf --force (qui la vide d'abord : DESTRUCTIF).
# Variables : PGHOST PGUSER PGPASSWORD, BACKUP_PASSPHRASE_FILE si la sauvegarde est chiffrée.
set -eu

FILE="${1:?fichier de sauvegarde requis}"
TARGET="${2:?base cible requise}"
FORCE="${3:-}"

[ -f "$FILE" ] || { echo "fichier introuvable : $FILE" >&2; exit 1; }

EXISTS="$(psql -d postgres -Atc "select 1 from pg_database where datname = '$TARGET'")"
if [ "$EXISTS" != "1" ]; then
  createdb "$TARGET"
else
  TABLES="$(psql -d "$TARGET" -Atc "select count(*) from information_schema.tables where table_schema = 'public'")"
  if [ "$TABLES" != "0" ]; then
    [ "$FORCE" = "--force" ] || { echo "la base $TARGET contient déjà $TABLES tables : relancer avec --force pour l'écraser" >&2; exit 2; }
    psql -d "$TARGET" -v ON_ERROR_STOP=1 -c "drop schema public cascade; create schema public;"
  fi
fi

SRC="$FILE"
case "$FILE" in
  *.enc)
    : "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE requis pour une sauvegarde chiffrée}"
    SRC="$(mktemp)"
    trap 'rm -f "$SRC"' EXIT
    openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$BACKUP_PASSPHRASE_FILE" -in "$FILE" -out "$SRC"
    ;;
esac

pg_restore --no-owner --no-privileges --exit-on-error --dbname="$TARGET" "$SRC"
echo "restauration OK dans $TARGET"
