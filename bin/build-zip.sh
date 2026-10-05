#!/usr/bin/env bash
#
# build-zip.sh — the single allowlisted build for the SenroFlux plugin.
#
# Unlike agent-safety's build-zip.sh (two-package layout, path-repo mirror),
# SenroFlux is one composer package with no runtime dependencies (composer.json
# `require` is just `php: >=8.1`), so the build is a straight DENYLIST copy
# driven by the same .distignore the plugin-check CI job already used inline
# (ci.yml's old `Assemble distribution copy` step) — this script replaces
# that inline step so release.yml and ci.yml can share exactly one build.
#
# Usage:
#   bin/build-zip.sh
#
# Exit codes:
#   0  clean build, verified zip
#   1  a required source file is missing, or the zip verification found
#      entries that .distignore should have excluded

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BUILD_DIR="$REPO_ROOT/dist"
STAGE_DIR="$BUILD_DIR/senroflux"

log() { echo "[build-zip] $*"; }
fail() {
	echo "[build-zip] ERROR: $*" >&2
	exit 1
}

command -v rsync >/dev/null 2>&1 || fail "rsync not found on PATH"
command -v zip >/dev/null 2>&1 || fail "zip not found on PATH"
command -v unzip >/dev/null 2>&1 || fail "unzip not found on PATH"

[ -f "$REPO_ROOT/senroflux.php" ] || fail "plugin header not found at $REPO_ROOT/senroflux.php"
[ -f "$REPO_ROOT/.distignore" ] || fail ".distignore not found at $REPO_ROOT/.distignore"

# ---------------------------------------------------------------------------
# 1. Version, from the plugin header (same field the release workflow
#    asserts the tag against).
# ---------------------------------------------------------------------------
VERSION=$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ {print $NF; exit}' "$REPO_ROOT/senroflux.php")
[ -n "$VERSION" ] || fail "could not read Version: from plugin header"
log "plugin header Version: $VERSION"

ZIP_PATH="$REPO_ROOT/senroflux-${VERSION}.zip"

# ---------------------------------------------------------------------------
# 2. Stage a distribution-shaped copy: everything EXCEPT .distignore's list
#    (dev tooling, tests, vendor — the plugin ships no vendor/ since it has
#    no runtime composer deps).
# ---------------------------------------------------------------------------
log "staging dist/senroflux/"
rm -rf "$BUILD_DIR" "$ZIP_PATH"
mkdir -p "$STAGE_DIR"
rsync -a --exclude-from="$REPO_ROOT/.distignore" "$REPO_ROOT/" "$STAGE_DIR/"

[ -f "$STAGE_DIR/senroflux.php" ] || fail "staged copy is missing senroflux.php — .distignore excluded a required file"

# ---------------------------------------------------------------------------
# 3. Zip it, top-level folder senroflux/.
# ---------------------------------------------------------------------------
log "zipping $ZIP_PATH"
(cd "$BUILD_DIR" && zip -rq -X "$ZIP_PATH" senroflux)

# ---------------------------------------------------------------------------
# 4. Independent verification: read the zip's own entry list back and fail,
#    naming the offending paths, if any .distignore-listed path slipped
#    through (a rerun of the same exclusion list against the ACTUAL zip
#    contents, not just trusting rsync's --exclude-from did the right
#    thing).
# ---------------------------------------------------------------------------
log "verifying zip entries against .distignore"

DENY_RE='(^|/)\.git(/|$)'
DENY_RE="$DENY_RE|(^|/)\.github(/|$)"
DENY_RE="$DENY_RE|(^|/)node_modules(/|$)"
DENY_RE="$DENY_RE|(^|/)[Tt]ests(/|$)"
DENY_RE="$DENY_RE|(^|/)vendor(/|$)"
DENY_RE="$DENY_RE|(^|/)dist(/|$)"
DENY_RE="$DENY_RE|(^|/)bin(/|$)"
DENY_RE="$DENY_RE|(^|/)assets/src(/|$)"
DENY_RE="$DENY_RE|/composer\.(json|lock)$"
DENY_RE="$DENY_RE|/phpcs\.xml\.dist$"
DENY_RE="$DENY_RE|/phpstan.*\.dist$|/phpstan-bootstrap\.php$"
DENY_RE="$DENY_RE|/phpunit\.xml\.dist$"
DENY_RE="$DENY_RE|/package(-lock)?\.json$"
DENY_RE="$DENY_RE|/playwright\.config\.js$"
DENY_RE="$DENY_RE|/webpack\.config\.js$"
DENY_RE="$DENY_RE|/\.wp-env\.json$"
DENY_RE="$DENY_RE|/CONTEXT\.md$|/README\.md$"
# Any hidden path segment (Plugin Check's hidden_files error).
DENY_RE="$DENY_RE|(^|/)\.[^/]+"

ENTRIES=()
while IFS= read -r line; do
	ENTRIES+=("$line")
done < <(unzip -Z1 "$ZIP_PATH")
[ "${#ENTRIES[@]}" -gt 0 ] || fail "zip has no entries — build produced nothing"

BAD=()
for entry in "${ENTRIES[@]}"; do
	if echo "$entry" | grep -Eq "$DENY_RE"; then
		BAD+=("$entry")
	fi
done

if [ "${#BAD[@]}" -gt 0 ]; then
	echo "[build-zip] ERROR: zip contains entries .distignore should have excluded:" >&2
	for entry in "${BAD[@]}"; do
		echo "  - $entry" >&2
	done
	exit 1
fi

log "verified: ${#ENTRIES[@]} entries, none denylisted"

# ---------------------------------------------------------------------------
# 5. Report (path + sha256, same shape release.yml's tag/header assert step
#    and the release job's checksum step both expect).
# ---------------------------------------------------------------------------
if command -v sha256sum >/dev/null 2>&1; then
	CHECKSUM=$(sha256sum "$ZIP_PATH" | awk '{print $1}')
else
	CHECKSUM=$(shasum -a 256 "$ZIP_PATH" | awk '{print $1}')
fi

echo "$ZIP_PATH"
echo "sha256: $CHECKSUM"
