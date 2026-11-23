#!/usr/bin/env bash
# Sestavení distribučního ZIPu + SHA-256 pro GitHub Release.
# Použití: ./build.sh            → dist/bombora-cart-popup.zip + .sha256
# Vyžaduje: zip, sha256sum, npx (terser, clean-css-cli).
set -euo pipefail

cd "$(dirname "$0")"
SLUG="bombora-cart-popup"
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' ${SLUG}.php | sed -E 's/.*Version:\s*//; s/\s+$//')"
CONST="$(grep -m1 "define( 'BCP_VERSION'" ${SLUG}.php | sed -E "s/.*'BCP_VERSION', '([^']+)'.*/\1/")"

if [[ "$VERSION" != "$CONST" ]]; then
	echo "Chyba: hlavička Version ($VERSION) ≠ BCP_VERSION ($CONST)" >&2
	exit 1
fi
if ! grep -q "^## \[$VERSION\]" CHANGELOG.md; then
	echo "Chyba: CHANGELOG.md neobsahuje sekci [$VERSION]" >&2
	exit 1
fi

# Minifikace (zdrojové soubory zůstávají; SCRIPT_DEBUG je načte).
npx --yes terser@5 assets/js/popup.js -c -m --comments '/^!/' -o assets/js/popup.min.js
npx --yes clean-css-cli@5 -O1 -o assets/css/popup.min.css assets/css/popup.css

# Kontrola syntaxe PHP.
find . -name '*.php' -not -path './dist/*' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l >/dev/null

rm -rf dist build
mkdir -p "build/${SLUG}" dist
# Kopie bez souborů z .distignore (bez závislosti na rsync).
tar --exclude-from=.distignore --exclude=./build --exclude=./dist -cf - . | tar -xf - -C "build/${SLUG}"
( cd build && zip -qr9X "../dist/${SLUG}.zip" "${SLUG}" )
( cd dist && sha256sum "${SLUG}.zip" > "${SLUG}.zip.sha256" )
rm -rf build

echo "Hotovo: dist/${SLUG}.zip (v${VERSION})"
cat "dist/${SLUG}.zip.sha256"
