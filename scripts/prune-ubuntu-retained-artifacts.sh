#!/usr/bin/env sh
set -eu

# This host-level helper never touches application data. It is dry-run by default.
MODE=${1:---dry-run}
APP_ROOT=${APP_ROOT:-/var/www/passion}
RELEASES_DIR=${RELEASES_DIR:-"${APP_ROOT}/releases"}
RELEASE_KEEP=${RELEASE_KEEP:-5}
BACKUPS_ROOT=${BACKUPS_ROOT:-/var/backups/passion}

case "$MODE" in
  --dry-run|--apply) ;;
  *) echo "Usage: $0 [--dry-run|--apply]" >&2; exit 64 ;;
esac

remove_path() {
  target=$1
  printf '%s %s\n' "$MODE" "$target"
  [ "$MODE" = "--dry-run" ] || rm -rf -- "$target"
}

current=$(readlink -f "${APP_ROOT}/current" 2>/dev/null || true)
previous=$(readlink -f "${APP_ROOT}/previous" 2>/dev/null || true)

if [ -d "$RELEASES_DIR" ]; then
  find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' |
    sort -nr |
    awk "NR > ${RELEASE_KEEP} {sub(/^[^ ]+ /, \"\"); print}" |
    while IFS= read -r release; do
      resolved=$(readlink -f "$release" 2>/dev/null || true)
      [ "$resolved" = "$current" ] && continue
      [ "$resolved" = "$previous" ] && continue
      remove_path "$release"
    done
fi

for tier in daily:7 weekly:28 monthly:186; do
  directory=${tier%%:*}
  days=${tier##*:}
  backup_dir="${BACKUPS_ROOT}/${directory}"
  [ -d "$backup_dir" ] || continue
  find "$backup_dir" -type f -mtime "+${days}" -print | while IFS= read -r backup; do
    remove_path "$backup"
  done
done
