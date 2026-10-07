#!/usr/bin/env bash
set -euo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_DIR="$(cd -- "$SOURCE_DIR/../.." && pwd)"
PLUGIN_DIR="${SOULMARKE_PLUGIN_DIR:-$REPOSITORY_DIR/soulmarke-forms}"
if [[ ! -d "$PLUGIN_DIR" ]]; then
    printf 'Plugin source directory is missing: %s\n' "$PLUGIN_DIR" >&2
    exit 1
fi
docker exec soulmarke-wp mkdir -p /var/www/html/wp-content/plugins/soulmarke-forms
tar -C "$PLUGIN_DIR" -cf - . | docker exec -i soulmarke-wp tar -C /var/www/html/wp-content/plugins/soulmarke-forms -xf -
docker exec soulmarke-wp chown -R www-data:www-data /var/www/html/wp-content/plugins/soulmarke-forms
docker exec soulmarke-wp find /var/www/html/wp-content/plugins/soulmarke-forms -type d -exec chmod 755 '{}' +
docker exec soulmarke-wp find /var/www/html/wp-content/plugins/soulmarke-forms -type f -exec chmod 644 '{}' +
docker exec soulmarke-wp php -r 'if(function_exists("opcache_reset")){opcache_reset();}'
printf '%s\n' 'Plugin source refreshed into the development container without changing the checkout.'
