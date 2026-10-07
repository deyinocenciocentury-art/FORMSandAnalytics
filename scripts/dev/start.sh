#!/usr/bin/env bash
set -euo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DEV_DIR="${SOULMARKE_DEV_DIR:-/workspace/.soulmarke-dev}"
source "$SOURCE_DIR/images.env"
SOULMARKE_DEV_DIR="$DEV_DIR" "$SOURCE_DIR/install.sh"
docker network inspect soulmarke-dev >/dev/null 2>&1 || docker network create --label soulmarke.local-dev=true soulmarke-dev >/dev/null
for volume in soulmarke-db-data soulmarke-wp-data; do
    docker volume inspect "$volume" >/dev/null 2>&1 || docker volume create --label soulmarke.local-dev=true "$volume" >/dev/null
done
for container in soulmarke-db soulmarke-wp; do
    if docker container inspect "$container" >/dev/null 2>&1; then
        label="$(docker inspect --format '{{index .Config.Labels "soulmarke.local-dev"}}' "$container")"
        [[ "$label" == true ]] || { printf 'Refusing to reuse unrelated container: %s\n' "$container" >&2; exit 1; }
    fi
done
if docker container inspect soulmarke-db >/dev/null 2>&1; then
    docker start soulmarke-db >/dev/null
else
    docker run -d --name soulmarke-db --label soulmarke.local-dev=true --network soulmarke-dev \
        --env-file "$DEV_DIR/db.env" --mount type=volume,src=soulmarke-db-data,dst=/var/lib/mysql \
        --health-cmd='healthcheck.sh --connect --innodb_initialized' --health-interval=2s --health-timeout=5s --health-retries=30 \
        "$DB_IMAGE" >/dev/null
fi
ready=false
for ((attempt=0; attempt<60; attempt++)); do
    if [[ "$(docker inspect --format '{{.State.Health.Status}}' soulmarke-db)" == healthy ]]; then ready=true; break; fi
    sleep 1
done
[[ "$ready" == true ]] || { docker logs --tail 30 soulmarke-db; exit 1; }
if docker container inspect soulmarke-wp >/dev/null 2>&1; then
    docker start soulmarke-wp >/dev/null
else
    docker run -d --name soulmarke-wp --label soulmarke.local-dev=true --network soulmarke-dev \
        -p 127.0.0.1:8080:80 --env-file "$DEV_DIR/wp.env" \
        --mount type=volume,src=soulmarke-wp-data,dst=/var/www/html "$WP_IMAGE" >/dev/null
fi
ready=false
for ((attempt=0; attempt<60; attempt++)); do
    if docker exec soulmarke-wp test -s /var/www/html/wp-config.php; then ready=true; break; fi
    sleep 1
done
[[ "$ready" == true ]] || { docker logs --tail 30 soulmarke-wp; exit 1; }
docker cp "$DEV_DIR/wp-cli.phar" soulmarke-wp:/usr/local/bin/wp
docker exec soulmarke-wp chmod 755 /usr/local/bin/wp
docker exec soulmarke-wp mkdir -p /var/www/html/wp-content/mu-plugins
docker cp "$SOURCE_DIR/local-mail.php" soulmarke-wp:/var/www/html/wp-content/mu-plugins/soulmarke-local-mail.php
docker exec soulmarke-wp chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
docker exec soulmarke-wp chmod 644 /var/www/html/wp-content/mu-plugins/soulmarke-local-mail.php
if ! docker exec --user www-data soulmarke-wp wp core is-installed --path=/var/www/html >/dev/null 2>&1; then
    source "$DEV_DIR/secrets.env"
    docker cp "$SOURCE_DIR/install-wordpress.php" soulmarke-wp:/tmp/soulmarke-install-wordpress.php
    docker exec soulmarke-wp chmod 644 /tmp/soulmarke-install-wordpress.php
    printf '%s\n' "$WP_ADMIN_PASSWORD" | docker exec -i --user www-data soulmarke-wp php /tmp/soulmarke-install-wordpress.php
    docker exec soulmarke-wp rm /tmp/soulmarke-install-wordpress.php
    unset DB_ROOT_PASSWORD DB_PASSWORD WP_ADMIN_PASSWORD
fi
docker exec --user www-data soulmarke-wp wp core is-installed --path=/var/www/html
docker exec --user www-data soulmarke-wp wp config set WP_ENVIRONMENT_TYPE local --type=constant --path=/var/www/html
docker exec --user www-data soulmarke-wp wp config set WP_DEBUG_DISPLAY false --raw --type=constant --path=/var/www/html
docker exec --user www-data soulmarke-wp wp config set AUTOMATIC_UPDATER_DISABLED true --raw --type=constant --path=/var/www/html
"$SOURCE_DIR/sync-plugin.sh"
curl --fail --silent --show-error --output /dev/null http://127.0.0.1:8080/wp-login.php
if ! docker exec --user www-data soulmarke-wp wp plugin is-active soulmarke-forms --path=/var/www/html; then
    docker exec --user www-data soulmarke-wp wp plugin activate soulmarke-forms --path=/var/www/html
fi
page_id="$(docker exec --user www-data soulmarke-wp wp post list --post_type=page --name=practitioner-discovery-survey --field=ID --path=/var/www/html)"
if [[ -z "$page_id" ]]; then
    page_id="$(docker exec --user www-data soulmarke-wp wp post create --post_type=page --post_name=practitioner-discovery-survey --post_title='Practitioner Discovery Survey' --post_content='[soulmarke_form]' --post_status=publish --porcelain --path=/var/www/html)"
else
    [[ "$page_id" =~ ^[0-9]+$ ]] || { printf '%s\n' 'Ambiguous local survey page selection.' >&2; exit 1; }
    docker exec --user www-data soulmarke-wp wp post update "$page_id" --post_content='[soulmarke_form]' --post_status=publish --path=/var/www/html
fi
docker exec --user www-data soulmarke-wp wp rewrite structure '/%postname%/' --path=/var/www/html
curl --fail --silent --show-error http://127.0.0.1:8080/practitioner-discovery-survey/ | python3 -c 'import sys; html = sys.stdin.read(); assert "class=\"smf-form\"" in html, "Survey shortcode did not render"; print("Survey page rendered successfully; question sections:", html.count("class=\"smf-question\""))'
printf '%s\n' 'Local WordPress and MariaDB are ready. Outgoing mail is intercepted by the local development mu-plugin.'
