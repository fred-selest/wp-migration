#!/bin/sh
# Crée wp-migration.zip, installable depuis Extensions > Ajouter > Téléverser.
set -e
cd "$(dirname "$0")/.."
VERSION=$(sed -n 's/^ \* Version: *//p' wp-migration.php)
OUT="${1:-$(pwd)/wp-migration-$VERSION.zip}"
TMP=$(mktemp -d)
mkdir "$TMP/wp-migration"
cp -R wp-migration.php uninstall.php readme.txt README.md CHANGELOG.md includes installer assets "$TMP/wp-migration/"
(cd "$TMP" && zip -qr "$OUT" wp-migration)
rm -rf "$TMP"
echo "$OUT"
