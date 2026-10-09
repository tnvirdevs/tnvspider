#!/usr/bin/env bash
# Fetches the html5lib tokenizer tests (MIT) used by RoundTripTest.
# Pinned so results are reproducible.
set -euo pipefail

commit="c777c408b61078ea2eb4acefc2535f54dbc8b28a"
dest="$(cd "$(dirname "$0")/.." && pwd)/tests/fixtures/html5lib-tests"

rm -rf "$dest"
git clone -q https://github.com/html5lib/html5lib-tests "$dest"
git -C "$dest" -c advice.detachedHead=false checkout -q "$commit"
rm -rf "$dest/.git"
echo "html5lib-tests $commit -> $dest"
