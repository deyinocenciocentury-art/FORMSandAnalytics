#!/usr/bin/env bash
set -euo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DEV_DIR="${SOULMARKE_DEV_DIR:-/workspace/.soulmarke-dev}"
mkdir -p "$DEV_DIR"
chmod 700 "$DEV_DIR"
source "$SOURCE_DIR/images.env"
python3 - "$DEV_DIR" <<'PY'
from pathlib import Path
import os, secrets, sys
base = Path(sys.argv[1])
secret = base / 'secrets.env'
if not secret.exists():
    values = {key: secrets.token_urlsafe(36) for key in ('DB_ROOT_PASSWORD', 'DB_PASSWORD', 'WP_ADMIN_PASSWORD')}
    secret.write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
values = dict(line.split('=', 1) for line in secret.read_text().splitlines() if line)
(base / 'db.env').write_text('MARIADB_DATABASE=soulmarke\nMARIADB_USER=soulmarke\n' +
    f'MARIADB_ROOT_PASSWORD={values["DB_ROOT_PASSWORD"]}\nMARIADB_PASSWORD={values["DB_PASSWORD"]}\n')
(base / 'wp.env').write_text('WORDPRESS_DB_HOST=soulmarke-db:3306\nWORDPRESS_DB_NAME=soulmarke\nWORDPRESS_DB_USER=soulmarke\n' +
    f'WORDPRESS_DB_PASSWORD={values["DB_PASSWORD"]}\nWORDPRESS_DEBUG=1\n')
for name in ('secrets.env', 'db.env', 'wp.env'):
    os.chmod(base / name, 0o600)
PY
for image in "$WP_IMAGE" "$DB_IMAGE" "$CLI_IMAGE"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        docker pull "$image"
    fi
done
if [[ ! -s "$DEV_DIR/wp-cli.phar" ]]; then
    container_id="$(docker create "$CLI_IMAGE")"
    trap 'docker rm -f "$container_id" >/dev/null 2>&1 || true' EXIT
    docker cp "$container_id:/usr/local/bin/wp" "$DEV_DIR/wp-cli.phar"
    docker rm "$container_id" >/dev/null
    trap - EXIT
    chmod 755 "$DEV_DIR/wp-cli.phar"
fi
printf '%s\n' 'Development dependencies installed; credentials remain in mode-0600 local files.'
