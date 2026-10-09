#!/usr/bin/env bash
# Captures front-end HTML from a local dev site into tests/fixtures/pages/
# for the round-trip and golden tests.
#
# Usage: WST_BASE=http://127.0.0.1:8899 WST_WP="wp --path=/path/to/site" bin/capture-fixtures.sh
#
# Needs the dev site prepared as described in HANDOVER.md (theme unit test
# data, Elementor and WooCommerce with sample products).
set -euo pipefail

: "${WST_BASE:?Set WST_BASE to the dev site URL}"
: "${WST_WP:?Set WST_WP to a wp-cli command for the dev site}"

out="$(cd "$(dirname "$0")/.." && pwd)/tests/fixtures/pages"
mkdir -p "$out"

capture() {
	local theme="$1"; shift
	$WST_WP theme activate "$theme" > /dev/null
	for path in "$@"; do
		local name
		name="$(echo "$path" | sed -E 's#^/##; s#/$##; s#[/?=&]+#-#g')"
		[ -z "$name" ] && name="home"
		local code
		code="$(curl -s -o "$out/$theme--$name.html" -w '%{http_code}' "$WST_BASE$path")"
		echo "$code $theme $path"
	done
}

capture twentytwentyone / /elementor-landing/ /elementor-bangla/ /shop/ /product/hoodie-with-logo/ \
	/product-category/clothing/ /cart/ /markup-html-tags-and-formatting/ /template-comments/ \
	/edge-case-nested-and-mixed-lists/ /title-with-special-characters/ /blocks-formatting/ \
	/greek/ '/?s=lorem' /category/markup/ /no-such-page-404/

capture twentytwentyfive / /shop/ /product/hoodie-with-logo/ /about/page-markup-and-formatting/ \
	/block-category-common/ /template-paginated/ /table/ /elementor-landing/

capture twentytwenty / /markup-image-alignment/ /post-format-gallery/ /template-password-protected/ \
	/custom-html/ /code/

$WST_WP theme activate twentytwentyone > /dev/null
