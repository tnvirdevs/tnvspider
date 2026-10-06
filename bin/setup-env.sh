#!/usr/bin/env bash
# Prepares a development/test environment for WP Site Translator.
# Idempotent: safe to run on every session start.
#
#   bin/setup-env.sh            # database, Composer tools, PHPStan, html5lib tests
#   bin/setup-env.sh --node     # also npm install (@wordpress/scripts, ~1 GB)
#
# Environment:
#   WST_TEST_DB_NAME/USER/PASSWORD/HOST  test database (defaults wst_tests/wst/wst/127.0.0.1)
#   WST_SKIP_DB=1                        use an existing MySQL server, do not install MariaDB
#
# Works without Docker (cloud containers) and on a normal machine. On machines
# where `composer install` can download every package, it is used as is; where
# the PHPStan package cannot be downloaded (GitHub API zipballs blocked), the
# official PHPStan release phar is used instead, checked against a pinned hash.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

db_name="${WST_TEST_DB_NAME:-wst_tests}"
db_user="${WST_TEST_DB_USER:-wst}"
db_pass="${WST_TEST_DB_PASSWORD:-wst}"
phpstan_version="2.3.0"
phpstan_sha256="64a1e7737c6ac24b798a3331a769241308d40af6504630fd7c9dc9b7f1bec83f"
with_node=0
[ "${1:-}" = "--node" ] && with_node=1

step() { printf '\n== %s\n' "$1"; }

# 1. Database ---------------------------------------------------------------
if [ "${WST_SKIP_DB:-0}" != "1" ]; then
	step "MariaDB"
	if ! command -v mysqld > /dev/null 2>&1; then
		if [ "$(id -u)" -ne 0 ]; then
			echo "MariaDB is not installed and this is not root. Install it, or set WST_SKIP_DB=1 and point WST_TEST_DB_* at a server." >&2
			exit 1
		fi
		apt-get update -q
		DEBIAN_FRONTEND=noninteractive apt-get install -y -q mariadb-server
	fi
	if ! mysqladmin ping > /dev/null 2>&1; then
		service mariadb start
	fi
	for _ in $(seq 1 30); do mysqladmin ping > /dev/null 2>&1 && break; sleep 1; done
	mysql -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`;
		CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';
		GRANT ALL ON \`${db_name}\`.* TO '${db_user}'@'localhost';
		FLUSH PRIVILEGES;"
	echo "Database ${db_name} ready for ${db_user}@localhost."
fi

# 2. Composer dev tools -----------------------------------------------------
step "Composer"
export COMPOSER_ALLOW_SUPERUSER=1
if composer install --no-interaction --no-progress > /tmp/wst-composer.log 2>&1; then
	echo "composer install: OK (PHPStan from Composer)."
else
	if ! grep -q "phpstan/phpstan" /tmp/wst-composer.log; then
		cat /tmp/wst-composer.log >&2
		exit 1
	fi
	echo "composer install could not download phpstan/phpstan; using the official release phar."
	# Container-only Composer file: everything except phpstan/phpstan, which the phar provides.
	php -r '
		$j = json_decode( file_get_contents( "composer.json" ), true );
		unset( $j["require-dev"]["phpstan/phpstan"] );
		$j["provide"] = array( "phpstan/phpstan" => $argv[1] );
		file_put_contents( "composer.local.json", json_encode( $j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	' "$phpstan_version"
	rm -f composer.local.lock
	COMPOSER=composer.local.json composer update --prefer-source --no-interaction --no-progress

	mkdir -p .tools
	if [ ! -f .tools/phpstan.phar ] || ! echo "${phpstan_sha256}  .tools/phpstan.phar" | sha256sum -c --status; then
		curl -sSL -o .tools/phpstan.phar "https://github.com/phpstan/phpstan/releases/download/${phpstan_version}/phpstan.phar"
	fi
	echo "${phpstan_sha256}  .tools/phpstan.phar" | sha256sum -c --status || { echo "PHPStan phar hash mismatch." >&2; rm -f .tools/phpstan.phar; exit 1; }
	echo "PHPStan ${phpstan_version} at .tools/phpstan.phar (hash verified)."
fi

# 3. html5lib inputs for RoundTripTest ---------------------------------------
step "html5lib tests"
if [ -d tests/fixtures/html5lib-tests/tokenizer ]; then
	echo "Already present."
else
	bin/fetch-html5lib-tests.sh
fi

# 4. Node (optional) -------------------------------------------------------
if [ "$with_node" = "1" ]; then
	step "npm"
	npm install --no-audit --no-fund
fi

step "Done"
cat <<EOF
Run:
  WST_TEST_DB_USER=${db_user} WST_TEST_DB_PASSWORD=${db_pass} composer test
  vendor/bin/phpcs
  $( [ -f .tools/phpstan.phar ] && echo "php .tools/phpstan.phar analyse --memory-limit=1G" || echo "composer analyse" )
EOF
