#!/bin/sh

set -eu

main_repo=$(CDPATH= cd -- "$(dirname -- "$0")/../../../" && pwd)
stable_repo=/Users/ian/Documents/Repos/grocy-atech-release
companion_repo=/Users/ian/Documents/Repos/grocy-mcp

fail()
{
	echo "FAIL: $1" >&2
	exit 1
}

pass()
{
	echo "PASS: $1"
}

usage()
{
	echo "Usage: $0 candidate|predeploy|evidence <release-manifest>" >&2
	exit 2
}

field()
{
	key=$1
	file=$2
	count=$(sed -n "s/^${key}: //p" "$file" | awk 'END { print NR }')
	[ "$count" -eq 1 ] || fail "manifest_${key}_single"
	sed -n "s/^${key}: //p" "$file"
}

block()
{
	name=$1
	file=$2
	awk -v start="${name}_begin" -v finish="${name}_end" '
		$0 == start { active = 1; next }
		$0 == finish { active = 0; found = 1; next }
		active { print }
		END { if (!found) exit 2 }
	' "$file" || fail "manifest_${name}_block"
}

is_full_sha()
{
	value=$1
	case "$value" in
		????????????????????????????????????????) ;;
		*) return 1 ;;
	esac
	case "$value" in
		*[!0-9a-f]*) return 1 ;;
	esac
}

assert_commit()
{
	repo=$1
	sha=$2
	name=$3
	is_full_sha "$sha" || fail "${name}_full_sha"
	git -C "$repo" cat-file -e "${sha}^{commit}" 2>/dev/null || fail "${name}_commit_exists"
	resolved=$(git -C "$repo" rev-parse "${sha}^{commit}")
	[ "$resolved" = "$sha" ] || fail "${name}_exact_commit"
	pass "${name}_commit"
}

assert_sorted_unique_block()
{
	name=$1
	file=$2
	values=$(block "$name" "$file")
	[ -n "$values" ] || fail "${name}_nonempty"
	sorted=$(printf '%s\n' "$values" | LC_ALL=C sort -u)
	[ "$values" = "$sorted" ] || fail "${name}_sorted_unique"
	case "$values" in
		/*|*'/../'*|../*|*'/..') fail "${name}_safe_paths" ;;
	esac
	pass "${name}_closed_paths"
}

assert_subset()
{
	actual=$1
	allowed=$2
	name=$3
	if [ -n "$actual" ]; then
		while IFS= read -r path; do
			[ -n "$path" ] || continue
			printf '%s\n' "$allowed" | grep -Fqx -- "$path" || fail "${name}_unexpected_path"
		done <<EOF
$actual
EOF
	fi
	pass "$name"
}

assert_exact_list()
{
	actual=$1
	expected=$2
	name=$3
	[ "$actual" = "$expected" ] || fail "$name"
	pass "$name"
}

run_quiet()
{
	name=$1
	shift
	if "$@" > "$temporary_root/${name}.log" 2>&1; then
		pass "$name"
	else
		fail "$name"
	fi
}

[ "$#" -eq 2 ] || usage
mode=$1
manifest_input=$2
case "$mode" in
	candidate|predeploy|evidence) ;;
	*) usage ;;
esac

manifest=$(CDPATH= cd -- "$(dirname -- "$manifest_input")" && pwd)/$(basename -- "$manifest_input")
[ -f "$manifest" ] || fail manifest_exists

temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/grocy-ai-release-gate.XXXXXX")
trap 'rm -rf "$temporary_root"' EXIT HUP INT TERM

main_branch=$(field main_branch "$manifest")
main_sha=$(field main_candidate_sha "$manifest")
companion_branch=$(field companion_branch "$manifest")
companion_sha=$(field companion_candidate_sha "$manifest")
stable_branch=$(field stable_branch "$manifest")
stable_portable_sha=$(field stable_portable_sha "$manifest")
stable_adapter_sha=$(field stable_adapter_sha "$manifest")
stable_adapter_parent_sha=$(field stable_adapter_parent_sha "$manifest")
stable_runtime_sha=$(field stable_runtime_sha "$manifest")
stable_runtime_parent_sha=$(field stable_runtime_parent_sha "$manifest")
stable_module_version=$(field stable_module_version "$manifest")
stable_cache_marker=$(field stable_cache_marker "$manifest")
dependency_hash=$(field dependency_constraints_sha256 "$manifest")

[ "$(git -C "$main_repo" symbolic-ref --quiet --short HEAD 2>/dev/null || true)" = "$main_branch" ] || fail main_branch
[ "$(git -C "$companion_repo" symbolic-ref --quiet --short HEAD 2>/dev/null || true)" = "$companion_branch" ] || fail companion_branch
[ "$(git -C "$stable_repo" symbolic-ref --quiet --short HEAD 2>/dev/null || true)" = "$stable_branch" ] || fail stable_branch
pass branches

assert_commit "$main_repo" "$main_sha" main_candidate
assert_commit "$companion_repo" "$companion_sha" companion_candidate
assert_commit "$stable_repo" "$stable_portable_sha" stable_portable
assert_commit "$stable_repo" "$stable_adapter_sha" stable_adapter
assert_commit "$stable_repo" "$stable_runtime_sha" stable_runtime

git -C "$main_repo" merge-base --is-ancestor "$main_sha" HEAD || fail main_candidate_ancestor
[ "$(git -C "$companion_repo" rev-parse HEAD)" = "$companion_sha" ] || fail companion_head
[ "$(git -C "$stable_repo" rev-parse HEAD)" = "$stable_runtime_sha" ] || fail stable_runtime_head
[ "$stable_adapter_parent_sha" = "$stable_portable_sha" ] || fail adapter_manifest_parent
[ "$(git -C "$stable_repo" rev-parse "${stable_adapter_sha}^")" = "$stable_portable_sha" ] || fail adapter_git_parent
[ "$stable_runtime_parent_sha" = "$stable_adapter_sha" ] || fail runtime_manifest_parent
[ "$(git -C "$stable_repo" rev-parse "${stable_runtime_sha}^")" = "$stable_adapter_sha" ] || fail runtime_git_parent
pass immutable_ancestry

assert_sorted_unique_block stable_adapter_paths "$manifest"
assert_sorted_unique_block stable_runtime_paths "$manifest"
assert_sorted_unique_block main_post_candidate_paths "$manifest"

portable_expected=$(LC_ALL=C sort "$main_repo/custom/grocy_AI/phase2-changed-paths.txt")
portable_actual=$(git -C "$stable_repo" diff-tree --no-commit-id --name-only -r "$stable_portable_sha" | LC_ALL=C sort)
assert_exact_list "$portable_actual" "$portable_expected" stable_portable_scope

adapter_expected=$(block stable_adapter_paths "$manifest")
adapter_actual=$(git -C "$stable_repo" diff-tree --no-commit-id --name-only -r "$stable_adapter_sha" | LC_ALL=C sort)
assert_exact_list "$adapter_actual" "$adapter_expected" stable_adapter_scope

runtime_expected=$(block stable_runtime_paths "$manifest")
runtime_actual=$(git -C "$stable_repo" diff-tree --no-commit-id --name-only -r "$stable_runtime_sha" | LC_ALL=C sort)
assert_exact_list "$runtime_actual" "$runtime_expected" stable_runtime_scope

portable_count=0
while IFS= read -r path || [ -n "$path" ]; do
	[ -n "$path" ] || continue
	case "$path" in /*|../*|*'/../'*|*'/..') fail portable_path_safety ;; esac
	git -C "$main_repo" cat-file -e "${main_sha}:${path}" 2>/dev/null || fail portable_main_blob
	git -C "$stable_repo" cat-file -e "${stable_portable_sha}:${path}" 2>/dev/null || fail portable_stable_blob
	main_hash=$(git -C "$main_repo" show "${main_sha}:${path}" | shasum -a 256 | awk '{print $1}')
	stable_hash=$(git -C "$stable_repo" show "${stable_portable_sha}:${path}" | shasum -a 256 | awk '{print $1}')
	[ "$main_hash" = "$stable_hash" ] || fail portable_blob_parity
	portable_count=$((portable_count + 1))
done < "$main_repo/custom/grocy_AI/portable-files.txt"
[ "$portable_count" -eq 12 ] || fail portable_path_count
pass portable_blob_parity

post_candidate=$(git -C "$main_repo" diff --name-only "${main_sha}..HEAD" | LC_ALL=C sort -u)
if [ -n "${GROCY_AI_RELEASE_GATE_EXTRA_COMMITTED_PATH:-}" ]; then
	post_candidate=$(printf '%s\n%s\n' "$post_candidate" "$GROCY_AI_RELEASE_GATE_EXTRA_COMMITTED_PATH" | sed '/^$/d' | LC_ALL=C sort -u)
fi
post_allowed=$(block main_post_candidate_paths "$manifest")
assert_subset "$post_candidate" "$post_allowed" main_post_candidate_scope

dirty_paths=$(
	{
		git -C "$main_repo" diff --name-only
		git -C "$main_repo" diff --cached --name-only
		git -C "$main_repo" ls-files --others --exclude-standard
	} | sed '/^$/d' | LC_ALL=C sort -u
)
if [ -n "${GROCY_AI_RELEASE_GATE_EXTRA_DIRTY_PATH:-}" ]; then
	dirty_paths=$(printf '%s\n%s\n' "$dirty_paths" "$GROCY_AI_RELEASE_GATE_EXTRA_DIRTY_PATH" | sed '/^$/d' | LC_ALL=C sort -u)
fi
case "$mode" in
	candidate)
		if [ "${GROCY_AI_RELEASE_GATE_CANDIDATE_STAGE:-release}" = deployment ]; then
			dirty_allowed=$(printf '%s\n' '.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-PHASE1-BASELINE.sha256' 'custom/grocy_AI/tests/deployment-gate.sh' 'custom/grocy_AI/tests/release-gate.sh')
		else
			dirty_allowed=$(printf '%s\n' '.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-RELEASE-MANIFEST.md' 'custom/grocy_AI/tests/release-gate.sh')
		fi
		assert_subset "$dirty_paths" "$dirty_allowed" candidate_dirty_scope
		;;
	predeploy)
		[ -z "$dirty_paths" ] || fail predeploy_clean_main
		[ -z "$(git -C "$stable_repo" status --porcelain --untracked-files=all)" ] || fail predeploy_clean_stable
		[ -z "$(git -C "$companion_repo" status --porcelain --untracked-files=all)" ] || fail predeploy_clean_companion
		pass predeploy_clean_worktrees
		;;
	evidence)
		dirty_allowed=$(printf '%s\n' '.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-DEPLOYMENT-EVIDENCE.md' '.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-PHASE-ACCEPTANCE.md')
		assert_subset "$dirty_paths" "$dirty_allowed" evidence_dirty_scope
		;;
esac

actual_dependency_hash=$(LC_ALL=C sort "$companion_repo/constraints-phase2.txt" | shasum -a 256 | awk '{print $1}')
[ "$actual_dependency_hash" = "$dependency_hash" ] || fail companion_dependency_constraints
[ "$(git -C "$companion_repo" diff --name-only "${companion_sha}..HEAD" | wc -l | tr -d ' ')" -eq 0 ] || fail companion_post_candidate_scope
pass companion_dependencies

main_module_version=$(sed -n 's/.*"module_version": "\([^"]*\)".*/\1/p' "$main_repo/custom/grocy_AI/module-version.json")
stable_blade_version=$(git -C "$stable_repo" show "${stable_runtime_sha}:views/productform.blade.php" | sed -n "s/.*\$grocyAiAssetVersion = '\([^']*\)'.*/\1/p" | head -1)
stable_commit_cache=$(git -C "$stable_repo" show "${stable_runtime_sha}:custom/grocy_AI/version.json" | sed -n 's/.*"Customization": "\([^"]*\)".*/\1/p')
[ "$main_module_version" = "$stable_module_version" ] || fail module_manifest_marker
[ "$stable_blade_version" = "$stable_module_version" ] || fail blade_module_marker
[ "$stable_commit_cache" = "$stable_cache_marker" ] || fail stable_cache_marker
pass synchronized_markers

selection_summary_call="data-selection-summary=\"{{ \$__t('%s changes selected', '%s') }}\""
grep -Fq "$selection_summary_call" "$main_repo/views/productform.blade.php" || fail main_selection_summary_localization
git -C "$stable_repo" show "${stable_runtime_sha}:views/productform.blade.php" | grep -Fq "$selection_summary_call" || fail stable_selection_summary_localization
pass selection_summary_localization

run_quiet main_php_contract env GROCY_BLADE_AUTOLOAD="$main_repo/packages/autoload.php" php "$main_repo/custom/grocy_AI/tests/run.php"
run_quiet main_barcode_handoff php "$main_repo/custom/grocy_AI/tests/barcode-handoff.php"
run_quiet stable_controller_lint php -l "$stable_repo/custom/grocy_AI/src/GrocyAiApiController.php"
run_quiet stable_routes_lint php -l "$stable_repo/custom/grocy_AI/routes.php"
run_quiet stable_migration_lint php -l "$stable_repo/migrations/0256.php"
run_quiet browser_release npm --prefix "$main_repo/custom/grocy_AI/tests/browser" run test:release
run_quiet companion_unittest sh -c 'cd "$1" && exec .venv/bin/python -m unittest discover -s tests' sh "$companion_repo"

echo "RELEASE_GATE: PASS ($mode)"
