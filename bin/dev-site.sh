#!/usr/bin/env bash
# Builds a small development WordPress site with this plugin active, for live
# checks (provider tests, queue runs, browser checks). Idempotent.
#
#   bin/setup-env.sh          # first: database server and Composer packages
#   bin/dev-site.sh           # site in .dev/site (gitignored), database wst_site
#   bin/dev-site.sh --serve   # also start http://127.0.0.1:8899 in the background
#
# Then, with provider keys exported in the shell (never typed into files):
#   .dev/wp wst provider test microsoft
#   .dev/wp wst queue status
#
# The site uses WordPress core from Composer (johnpbloch/wordpress-core), WP-CLI
# from its official release (hash checked), DISABLE_WP_CRON (the queue only runs
# when asked: `.dev/wp wst queue run`), target language bn_BD and no provider.
# Dev-only credentials: admin / wst-dev-only-pass. Not for public servers.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
dev="$root/.dev"
site="$dev/site"
db_name="${WST_DEV_DB_NAME:-wst_site}"
db_user="${WST_TEST_DB_USER:-wst}"
db_pass="${WST_TEST_DB_PASSWORD:-wst}"
db_host="${WST_TEST_DB_HOST:-127.0.0.1}"
port="${WST_DEV_PORT:-8899}"
wpcli_version="2.12.0"
wpcli_sha512="be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832"
serve=0
[ "${1:-}" = "--serve" ] && serve=1

step() { printf '\n== %s\n' "$1"; }
fail() { echo "dev-site: $1" >&2; exit 1; }

[ -d "$root/vendor/johnpbloch/wordpress-core" ] || fail "WordPress core is missing; run bin/setup-env.sh first."
mkdir -p "$dev"

step "WP-CLI ${wpcli_version}"
if [ ! -f "$dev/wp-cli.phar" ] || ! echo "${wpcli_sha512}  $dev/wp-cli.phar" | sha512sum -c --status; then
	curl -sSL -o "$dev/wp-cli.phar" "https://github.com/wp-cli/wp-cli/releases/download/v${wpcli_version}/wp-cli-${wpcli_version}.phar"
fi
echo "${wpcli_sha512}  $dev/wp-cli.phar" | sha512sum -c --status || { rm -f "$dev/wp-cli.phar"; fail "WP-CLI hash mismatch."; }
cat > "$dev/wp" <<EOF
#!/usr/bin/env bash
exec php "$dev/wp-cli.phar" --path="$site" --allow-root "\$@"
EOF
chmod +x "$dev/wp"
wp() { "$dev/wp" "$@"; }

step "Database ${db_name}"
if [ "$(id -u)" -eq 0 ] && command -v mysql > /dev/null 2>&1 && mysqladmin ping > /dev/null 2>&1; then
	mysql -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`;
		GRANT ALL ON \`${db_name}\`.* TO '${db_user}'@'localhost';
		FLUSH PRIVILEGES;"
else
	echo "Not root or no local server: make sure ${db_user} can use database ${db_name} on ${db_host}."
fi

step "WordPress"
if [ ! -f "$site/wp-load.php" ]; then
	mkdir -p "$site"
	cp -R "$root/vendor/johnpbloch/wordpress-core/." "$site/"
	rm -f "$site/composer.json"
fi
if [ ! -f "$site/wp-config.php" ]; then
	wp config create --dbname="$db_name" --dbuser="$db_user" --dbpass="$db_pass" --dbhost="$db_host" --skip-check --quiet
	wp config set DISABLE_WP_CRON true --raw --quiet
	wp config set WP_DEBUG true --raw --quiet
	wp config set WP_DEBUG_LOG "$dev/debug.log" --quiet
	wp config set WP_DEBUG_DISPLAY false --raw --quiet
fi
if ! wp core is-installed > /dev/null 2>&1; then
	wp core install --url="http://127.0.0.1:${port}" --title="WST Dev" --admin_user=admin --admin_password=wst-dev-only-pass --admin_email=admin@example.test --skip-email --quiet
fi
wp option update home "http://127.0.0.1:${port}" --quiet
wp option update siteurl "http://127.0.0.1:${port}" --quiet
wp rewrite structure '/%postname%/' --quiet

step "Plugin"
ln -sfn "$root" "$site/wp-content/plugins/wp-site-translator"
wp plugin is-active wp-site-translator || wp plugin activate wp-site-translator --quiet
if [ -z "$(wp option get wst_settings --format=json 2>/dev/null || true)" ]; then
	wp option update wst_settings '{"target_language":"bn_BD"}' --format=json --quiet
fi
wp rewrite flush --quiet

cat > "$dev/router.php" <<EOF
<?php
\$root = '$site';
\$path = (string) parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== \$path && is_file( \$root . \$path ) && ! str_ends_with( \$path, '.php' ) ) {
	return false;
}
\$file = str_ends_with( \$path, '.php' ) && is_file( \$root . \$path ) ? \$root . \$path : \$root . '/index.php';
chdir( dirname( \$file ) );
require \$file;
EOF

if [ "$serve" = "1" ]; then
	step "Server"
	if [ -f "$dev/server.pid" ] && kill -0 "$(cat "$dev/server.pid")" 2> /dev/null; then
		echo "Already running (pid $(cat "$dev/server.pid"))."
	else
		# Provider keys exported in this shell reach the site through the environment.
		PHP_CLI_SERVER_WORKERS=4 nohup php -d opcache.enable_cli=1 -S "127.0.0.1:${port}" -t "$site" "$dev/router.php" > "$dev/server.log" 2>&1 &
		echo $! > "$dev/server.pid"
		sleep 1
	fi
	curl -s -o /dev/null -w "http://127.0.0.1:${port}/ -> %{http_code}\n" "http://127.0.0.1:${port}/"
fi

step "Done"
cat <<EOF
Site:   $site (http://127.0.0.1:${port}/, admin / wst-dev-only-pass)
WP-CLI: $dev/wp <command>
Next:   $dev/wp wst provider test microsoft   (with WST_AZURE_KEY / WST_AZURE_REGION exported)
        $dev/wp wst provider test gemini      (with WST_GEMINI_KEY exported)
EOF
