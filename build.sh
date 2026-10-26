#!/usr/bin/env bash
# Produces dist/sigil-2fa-pro.zip: the Pro add-on on its own, top-level folder
# sigil-2fa-pro. It does not contain the free plugin; that installs separately.
#
# SIGIL_PRO_RELEASE_VERSION overrides the version written into the package.
# Run from anywhere: ./build.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
STAGE="$ROOT/dist/stage"
ZIP="$ROOT/dist/sigil-2fa-pro.zip"
MAIN_FILE="$ROOT/sigil-2fa-pro.php"

SOURCE_VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$MAIN_FILE" | head -1)"
RELEASE_VERSION="${SIGIL_PRO_RELEASE_VERSION:-${SOURCE_VERSION}}"
if [ -z "$RELEASE_VERSION" ]; then
	echo "Could not resolve a version from $MAIN_FILE" >&2
	exit 1
fi

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/sigil-2fa-pro"

# An explicit list, so a new development file can never ride along by accident.
cp "$ROOT/sigil-2fa-pro.php" "$ROOT/readme.txt" "$ROOT/uninstall.php" "$ROOT/LICENSE" "$STAGE/sigil-2fa-pro/"
rsync -a "$ROOT/includes/" "$STAGE/sigil-2fa-pro/includes/"

python3 - "$STAGE/sigil-2fa-pro/sigil-2fa-pro.php" "$RELEASE_VERSION" <<'PY'
import re, sys
path, version = sys.argv[1], sys.argv[2]
with open(path, 'r', encoding='utf-8') as f:
    main = f.read()
main = re.sub(r"(?m)^(\s*\*\s*Version:\s*).+$", r"\g<1>" + version, main, count=1)
main = re.sub(r"define\(\s*'SIGIL_PRO_VERSION',\s*'[^']+'\s*\);", f"define( 'SIGIL_PRO_VERSION', '{version}' );", main, count=1)
with open(path, 'w', encoding='utf-8') as f:
    f.write(main)
PY

# A package missing a feature file installs fine and then quietly does less.
REQUIRED=(
  "sigil-2fa-pro.php"
  "readme.txt"
  "uninstall.php"
  "LICENSE"
  "includes/loader.php"
  "includes/class-updater.php"
  "includes/class-settings-page.php"
  "includes/class-method-policy.php"
  "includes/class-branding.php"
  "includes/class-portability.php"
  "includes/class-password-reset.php"
  "includes/class-destinations.php"
  "includes/class-bypass.php"
  "includes/class-audit.php"
)
for file in "${REQUIRED[@]}"; do
	if [ ! -f "$STAGE/sigil-2fa-pro/$file" ]; then
		echo "Missing from the package: $file" >&2
		exit 1
	fi
done

# The add-on must not ship a second copy of the plugin it extends.
for unwanted in sigil-2fa.php tests .git .github dist build.sh phpunit.xml.dist composer.json vendor; do
	if [ -e "$STAGE/sigil-2fa-pro/$unwanted" ]; then
		echo "Development path leaked into the package: $unwanted" >&2
		exit 1
	fi
done

for f in "$STAGE"/sigil-2fa-pro/sigil-2fa-pro.php "$STAGE"/sigil-2fa-pro/includes/*.php; do
	php -l "$f" > /dev/null
done

cd "$STAGE"
zip -rqX "$ZIP" sigil-2fa-pro/
rm -rf "$STAGE"

echo "Built: $ZIP (version $RELEASE_VERSION)"
du -h "$ZIP" | awk '{print "Size:", $1}'
