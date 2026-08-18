#!/usr/bin/env sh
set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIRECTORY=${PROJECT_DIRECTORY:-$(CDPATH= cd -- "$SCRIPT_DIRECTORY/.." && pwd)}
BACKUP_DIRECTORY=${BACKUP_DIRECTORY:-/var/backups/ToutDispo}
KEEP_SETS=${KEEP_SETS:-2}

case "$KEEP_SETS" in
    ''|*[!0-9]*|0) echo "KEEP_SETS must be a positive integer." >&2; exit 1 ;;
esac

cd "$PROJECT_DIRECTORY"
test -f .env.docker || { echo "Missing .env.docker." >&2; exit 1; }
docker compose --env-file .env.docker config --quiet

mkdir -p "$BACKUP_DIRECTORY"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
db_file="$BACKUP_DIRECTORY/db-$stamp.sql.gz"
storage_file="$BACKUP_DIRECTORY/storage-$stamp.tar.gz"
checksum_file="$BACKUP_DIRECTORY/SHA256SUMS-$stamp"
marker="$BACKUP_DIRECTORY/.complete-$stamp"

cleanup() {
    rm -f "$db_file" "$storage_file" "$checksum_file" "$marker"
}
trap cleanup INT TERM

echo "Creating MySQL backup: $db_file"
docker compose --env-file .env.docker exec -T mysql \
    sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --single-transaction --quick --routines --triggers -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
    | gzip -c > "$db_file"

echo "Creating application storage backup: $storage_file"
docker compose --env-file .env.docker run --rm --no-deps --entrypoint sh app \
    -c 'tar -czf - -C /var/www/html/storage/app .' > "$storage_file"

gzip -t "$db_file"
gzip -t "$storage_file"
sha256sum "$db_file" "$storage_file" > "$checksum_file"
touch "$marker"
trap - INT TERM

set -- "$BACKUP_DIRECTORY"/.complete-*
if [ -e "$1" ]; then
    complete_count=$#
    if [ "$complete_count" -gt "$KEEP_SETS" ]; then
        ls -1t "$BACKUP_DIRECTORY"/.complete-* | tail -n +$((KEEP_SETS + 1)) | while IFS= read -r old_marker; do
            old_stamp=${old_marker##*.complete-}
            rm -f "$BACKUP_DIRECTORY/db-$old_stamp.sql.gz" \
                "$BACKUP_DIRECTORY/storage-$old_stamp.tar.gz" \
                "$BACKUP_DIRECTORY/SHA256SUMS-$old_stamp" \
                "$old_marker"
        done
    fi
fi

echo "Backup completed successfully: $stamp"
ls -lh "$db_file" "$storage_file" "$checksum_file"
