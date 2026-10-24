#!/bin/sh
# Sauvegarde PostgreSQL (format custom, compressé). Variables : PGHOST PGUSER PGPASSWORD PGDATABASE (standard libpq),
#   BACKUP_DIR (défaut /backups), BACKUP_RETENTION_DAYS (défaut 14),
#   BACKUP_PASSPHRASE_FILE (optionnel : chiffre la sauvegarde en AES-256 avec openssl).
# Écrit dans un fichier temporaire puis renomme : une sauvegarde interrompue ne ressemble jamais à une sauvegarde valide.
set -eu

DIR="${BACKUP_DIR:-/backups}"
KEEP="${BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$DIR/${PGDATABASE:?PGDATABASE requis}-$STAMP.dump"

mkdir -p "$DIR"
umask 077
pg_dump --format=custom --no-owner --no-privileges --file="$OUT.part"

if [ -n "${BACKUP_PASSPHRASE_FILE:-}" ]; then
  command -v openssl >/dev/null 2>&1 || { echo "openssl introuvable : chiffrement impossible" >&2; rm -f "$OUT.part"; exit 1; }
  openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$BACKUP_PASSPHRASE_FILE" -in "$OUT.part" -out "$OUT.enc.part"
  rm -f "$OUT.part"
  mv "$OUT.enc.part" "$OUT.enc"
  OUT="$OUT.enc"
else
  mv "$OUT.part" "$OUT"
fi

# Vérification minimale : le fichier n'est pas vide.
[ -s "$OUT" ] || { echo "sauvegarde vide : $OUT" >&2; exit 1; }
find "$DIR" -name "${PGDATABASE}-*" -type f -mtime "+$KEEP" -delete
echo "sauvegarde OK : $OUT ($(wc -c < "$OUT") octets)"
