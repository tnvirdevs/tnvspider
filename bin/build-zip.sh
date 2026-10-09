#!/usr/bin/env bash
# Build the release zip from the committed HEAD, minus everything listed in
# .distignore, then check it: plugin header and version, built assets
# present, no development files, no secrets.
#
#   bin/build-zip.sh [--secret-file PATH]...
#
# --secret-file: a file holding a real key (never committed) whose value must
# not appear anywhere in the zip. Output: dist/wp-site-translator-<version>.zip
set -euo pipefail

cd "$(dirname "$0")/.."
root="$(pwd)"
slug="wp-site-translator"
secret_files=()
while [ $# -gt 0 ]; do
	case "$1" in
		--secret-file) secret_files+=("$2"); shift 2 ;;
		*) echo "Unknown argument: $1" >&2; exit 2 ;;
	esac
done

fail() { echo "build-zip: $*" >&2; exit 1; }

[ -z "$(git status --porcelain)" ] || fail "the working tree has uncommitted changes; the zip is built from HEAD."

# Built assets must match their sources.
if [ -d node_modules ]; then
	npm run --silent build >/dev/null
	git diff --quiet -- build || fail "build/ is out of date: commit the output of npm run build."
else
	fail "node_modules is missing: run npm install so the build can be checked."
fi

version="$(sed -n "s/.*public const VERSION *= *'\([^']*\)'.*/\1/p" src/Config.php)"
header="$(sed -n 's/^ \* Version: *//p' "$slug.php")"
[ -n "$version" ] && [ "$version" = "$header" ] || fail "version mismatch: Config::VERSION '$version', plugin header '$header'."

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/export" "$work/$slug"
git archive HEAD | tar -x -C "$work/export"
rsync -a --exclude-from=.distignore "$work/export/" "$work/$slug/"

# Contents checks.
for required in "$slug.php" uninstall.php readme.txt languages/$slug.pot src/Plugin.php build/admin.js build/admin.css build/admin.asset.php build/post-panel.js build/switcher-block.js assets/switcher.css blocks/switcher/block.json; do
	[ -f "$work/$slug/$required" ] || fail "missing from the package: $required"
done
forbidden="$(cd "$work/$slug" && find . \( -path ./tests -o -path ./bin -o -path ./docs -o -path ./assets-src -o -path ./node_modules -o -path ./vendor -o -path ./.tools -o -name '*.md' -o -name 'phpunit*.xml*' -o -name 'phpcs.xml*' -o -name 'phpstan.neon*' -o -name 'composer.*' -o -name 'package*.json' -o -name '.*' -o -name '*fixture*' -o -name '*-rtl.css' \) -print | grep -v '^\.$' || true)"
[ -z "$forbidden" ] || fail "development files in the package: $forbidden"
if grep -rIlE 'AIza[0-9A-Za-z_-]{30,}|sk-[A-Za-z0-9]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY' "$work/$slug" >/dev/null; then
	fail "something that looks like a key is in the package: $(grep -rIlE 'AIza[0-9A-Za-z_-]{30,}|sk-[A-Za-z0-9]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY' "$work/$slug")"
fi
for file in "${secret_files[@]+"${secret_files[@]}"}"; do
	secret="$(tr -d '\r\n' < "$file")"
	[ -n "$secret" ] || fail "secret file $file is empty."
	if grep -rqF -- "$secret" "$work/$slug"; then
		fail "the secret from $file is in the package."
	fi
done

mkdir -p dist
zip_path="$root/dist/$slug-$version.zip"
rm -f "$zip_path"
(cd "$work" && zip -qrX "$zip_path" "$slug")
echo "Built $zip_path ($(cd "$work/$slug" && find . -type f | wc -l | tr -d ' ') files, $(du -h "$zip_path" | cut -f1))."
