#!/bin/sh

set -eu

usage()
{
	echo "Usage: $0 --stable-sha <40-hex-commit>" >&2
	exit 2
}

if [ "$#" -ne 2 ] || [ "$1" != "--stable-sha" ]; then
	usage
fi

stable_sha=$2
case "$stable_sha" in
	????????????????????????????????????????) ;;
	*) echo "ERROR: --stable-sha must be one explicit 40-hex commit SHA" >&2; exit 2 ;;
esac
case "$stable_sha" in
	*[!0-9a-fA-F]*) echo "ERROR: --stable-sha must contain only hexadecimal characters" >&2; exit 2 ;;
esac

current_branch=$(git symbolic-ref --quiet --short HEAD 2>/dev/null || true)
if [ "$current_branch" != "atech-main" ]; then
	echo "ERROR: parity must run from the atech-main working tree; current branch is ${current_branch:-detached}" >&2
	exit 2
fi

if ! git cat-file -e "${stable_sha}^{commit}" 2>/dev/null; then
	echo "ERROR: --stable-sha does not resolve to a commit object: $stable_sha" >&2
	exit 2
fi
resolved_sha=$(git rev-parse "${stable_sha}^{commit}")
if [ "$resolved_sha" != "$stable_sha" ]; then
	echo "ERROR: --stable-sha must name the commit itself, not another object" >&2
	exit 2
fi

repo_root=$(git rev-parse --show-toplevel)
manifest="$repo_root/custom/grocy_AI/portable-files.txt"
if [ ! -f "$manifest" ]; then
	echo "ERROR: portable manifest is missing: $manifest" >&2
	exit 2
fi

temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/grocy-ai-parity.XXXXXX")
trap 'rm -rf "$temporary_root"' EXIT HUP INT TERM

identical=0
mismatched=0
missing=0

echo "Portable files compared against stable commit $stable_sha:"
while IFS= read -r path || [ -n "$path" ]; do
	case "$path" in
		"") continue ;;
		/*|*..*) echo "ERROR: unsafe portable path in manifest: $path" >&2; exit 2 ;;
	esac
	main_file="$repo_root/$path"
	stable_file="$temporary_root/stable"
	if [ ! -f "$main_file" ]; then
		echo "MISSING main: $path"
		missing=$((missing + 1))
		continue
	fi
	if ! git show "$stable_sha:$path" > "$stable_file" 2>/dev/null; then
		echo "MISSING stable: $path"
		missing=$((missing + 1))
		continue
	fi
	if cmp -s "$main_file" "$stable_file"; then
		echo "IDENTICAL: $path"
		identical=$((identical + 1))
	else
		echo "MISMATCH: $path"
		mismatched=$((mismatched + 1))
	fi
done < "$manifest"

cat <<'ADAPTERS'

Documented Plan 01-09 stable adapters (not byte-portable):
- custom/grocy_AI/src/GrocyAiApiController.php — stable controller namespace/base class
- custom/grocy_AI/routes.php — stable middleware/bootstrap syntax
- views/productform.blade.php — stable product-form integration hook
- custom/grocy_AI/version.json — independent stable cache-invalidation marker
- CUSTOMIZATIONS.md — stable branch/adaptation record
ADAPTERS

echo ""
echo "Summary: identical=$identical mismatched=$mismatched missing=$missing"
if [ "$mismatched" -ne 0 ] || [ "$missing" -ne 0 ]; then
	echo "FAIL: stable adaptation is incomplete for the supplied commit" >&2
	exit 1
fi

echo "PASS: every portable file is identical at the supplied stable commit"
