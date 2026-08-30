#!/bin/sh

set -eu

main_repo=$(CDPATH= cd -- "$(dirname -- "$0")/../../../" && pwd)
stable_repo=${GROCY_AI_STABLE_REPO:-/Users/ian/Documents/Repos/grocy-atech-release}
companion_repo=${GROCY_AI_COMPANION_REPO:-/Users/ian/Documents/Repos/grocy-mcp}

# Both maintained branches may live in one checkout (every immutable revision is reachable from it)
# or in two. Resolve the stable side once so the gate is identical on either layout: with a separate
# stable checkout the stable tree is its HEAD, otherwise it is the stable branch in this repository.
stable_ref=HEAD
if ! git -C "$stable_repo" rev-parse --git-dir > /dev/null 2>&1; then
	stable_repo=$main_repo
	stable_ref=${GROCY_AI_STABLE_REF:-atech-release}
fi

# The repository requires a specific PHP; the default `php` on a host is not always it.
php_runner=${GROCY_AI_PHP:-php}

sha256()
{
	if command -v sha256sum > /dev/null 2>&1; then
		sha256sum | awk '{print $1}'
	else
		shasum -a 256 | awk '{print $1}'
	fi
}

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

taxonomy_release_gate()
{
	temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/grocy-ai-release-gate.XXXXXX")
	trap 'rm -rf "$temporary_root"' EXIT HUP INT TERM
	required_portable_paths=$(printf '%s\n' \
		'custom/grocy_AI/bin/validate-inventory-taxonomy.php' \
		'custom/grocy_AI/src/GrocyAiTaxonomyMigration.php' \
		'custom/grocy_AI/src/GrocyAiTaxonomyService.php' \
		'custom/grocy_AI/tests/taxonomy.php' \
		'public/custom/grocy_AI/product-taxonomy.js')
	while IFS= read -r path; do
		grep -Fqx -- "$path" "$main_repo/custom/grocy_AI/portable-files.txt" || fail taxonomy_portable_manifest
		[ -f "$main_repo/$path" ] || fail taxonomy_portable_source
	done <<EOF
$required_portable_paths
EOF
	pass taxonomy_portable_manifest

	if grep -Eiq '(INSERT|UPDATE|DELETE)[[:space:]].*(product_groups|should_not_be_frozen)' \
		"$main_repo/custom/grocy_AI/src/GrocyAiTaxonomyMigration.php" \
		"$main_repo/custom/grocy_AI/src/GrocyAiTaxonomyService.php"; then
		fail taxonomy_storage_boundary
	fi
	pass taxonomy_storage_boundary

	dockerfile=$(git -C "$stable_repo" show "${stable_ref}:Dockerfile.atech" 2>/dev/null) || fail taxonomy_stable_dockerfile
	printf '%s\n' "$dockerfile" | grep -Fqx 'COPY custom/grocy_AI /app/www/custom/grocy_AI' || fail taxonomy_stable_module_overlay
	printf '%s\n' "$dockerfile" | grep -Fqx 'COPY public/custom/grocy_AI /app/www/public/custom/grocy_AI' || fail taxonomy_stable_asset_overlay
	pass taxonomy_stable_overlay

	run_quiet taxonomy_validation "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" taxonomy-validation
	run_quiet taxonomy_production_paths "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" taxonomy-production-paths
	run_quiet taxonomy_service_lint "$php_runner" -l "$main_repo/custom/grocy_AI/src/GrocyAiTaxonomyService.php"

	echo "RELEASE_GATE: PASS (taxonomy)"
}

conversions_release_gate()
{
	temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/grocy-ai-release-gate.XXXXXX")
	trap 'rm -rf "$temporary_root"' EXIT HUP INT TERM
	characterization="$main_repo/.planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md"
	[ -f "$characterization" ] || fail conversions_characterization_present

	# Every Phase 4 module artifact must be a declared portable file that actually exists.
	required_portable_paths=$(printf '%s\n' \
		'custom/grocy_AI/bin/activate-verified-conversion-ruleset.php' \
		'custom/grocy_AI/bin/validate-conversion-rules.php' \
		'custom/grocy_AI/src/GrocyAiConversionController.php' \
		'custom/grocy_AI/src/GrocyAiConversionMigration.php' \
		'custom/grocy_AI/src/GrocyAiConversionService.php' \
		'custom/grocy_AI/tests/browser/fixtures/quantityunitconversionform.html' \
		'custom/grocy_AI/tests/browser/fixtures/quantityunitconversionsresolved.html' \
		'custom/grocy_AI/tests/browser/specs/conversions.spec.js' \
		'custom/grocy_AI/tests/conversion-characterization.php' \
		'custom/grocy_AI/tests/conversions.php' \
		'custom/grocy_AI/tests/fixtures/conversion-characterization-main.json' \
		'custom/grocy_AI/tests/fixtures/conversion-characterization-stable.json' \
		'public/custom/grocy_AI/conversion-coverage.js' \
		'public/custom/grocy_AI/conversion-coverage.test.js' \
		'public/custom/grocy_AI/conversion-explanations.js' \
		'public/custom/grocy_AI/conversion-explanations.test.js')
	manifest="$main_repo/custom/grocy_AI/portable-files.txt"
	while IFS= read -r path; do
		grep -Fqx -- "$path" "$manifest" || fail conversions_portable_manifest
		[ -f "$main_repo/$path" ] || fail conversions_portable_source
	done <<EOF
$required_portable_paths
EOF
	manifest_paths=$(sed '/^$/d' "$manifest")
	manifest_sorted=$(printf '%s\n' "$manifest_paths" | LC_ALL=C sort -u)
	[ "$manifest_paths" = "$manifest_sorted" ] || fail conversions_portable_manifest_sorted_unique
	case "$manifest_paths" in
		/*|*'/../'*|../*|*'/..') fail conversions_portable_manifest_safe_paths ;;
	esac
	while IFS= read -r path; do
		[ -n "$path" ] || continue
		[ -f "$main_repo/$path" ] || fail conversions_portable_manifest_source
	done <<EOF
$manifest_paths
EOF
	pass conversions_portable_manifest

	# The immutable dual-branch revisions are read from the characterization document, never guessed.
	main_sha=$(sed -n 's/^| main | `\([0-9a-f]\{40\}\)` |$/\1/p' "$characterization")
	stable_sha=$(sed -n 's/^| stable | `\([0-9a-f]\{40\}\)` |$/\1/p' "$characterization")
	[ "$(printf '%s\n' "$main_sha" | awk 'END { print NR }')" -eq 1 ] || fail conversions_main_revision_single
	[ "$(printf '%s\n' "$stable_sha" | awk 'END { print NR }')" -eq 1 ] || fail conversions_stable_revision_single
	[ "$main_sha" != "$stable_sha" ] || fail conversions_distinct_branch_revisions
	assert_commit "$main_repo" "$main_sha" conversions_main
	assert_commit "$stable_repo" "$stable_sha" conversions_stable

	# The characterized resolver/cache migrations must be byte-equal on both immutable revisions and
	# equal to the hash the document recorded. Any drift fails closed.
	migration_lines=$(sed -n 's/^- `\(migrations\/[0-9]\{4\}\.sql\)` SHA-256: `\([0-9a-f]\{64\}\)` on both branches\.$/\1 \2/p' "$characterization")
	[ -n "$migration_lines" ] || fail conversions_migration_evidence_present
	migration_count=0
	while IFS=' ' read -r migration_path migration_hash; do
		[ -n "$migration_path" ] || continue
		git -C "$main_repo" cat-file -e "${main_sha}:${migration_path}" 2>/dev/null || fail conversions_main_migration_blob
		git -C "$stable_repo" cat-file -e "${stable_sha}:${migration_path}" 2>/dev/null || fail conversions_stable_migration_blob
		main_hash=$(git -C "$main_repo" show "${main_sha}:${migration_path}" | sha256)
		stable_hash=$(git -C "$stable_repo" show "${stable_sha}:${migration_path}" | sha256)
		[ "$main_hash" = "$stable_hash" ] || fail conversions_migration_branch_parity
		[ "$main_hash" = "$migration_hash" ] || fail conversions_migration_evidence_hash
		migration_count=$((migration_count + 1))
	done <<EOF
$migration_lines
EOF
	[ "$migration_count" -ge 2 ] || fail conversions_migration_evidence_count
	pass conversions_immutable_branch_evidence

	# The characterized trigger/cache adapter contract must still exist in the pinned cache migration.
	cache_index=$(sed -n 's/^.*`\(ix_cache__[a-z0-9_]*\)` for the cache key `\(([^`]*)\)` on both branches;.*$/\1/p' "$characterization")
	cache_key=$(sed -n 's/^.*`\(ix_cache__[a-z0-9_]*\)` for the cache key `\(([^`]*)\)` on both branches;.*$/\2/p' "$characterization")
	[ "$(printf '%s\n' "$cache_index" | awk 'END { print NR }')" -eq 1 ] || fail conversions_cache_index_single
	[ -n "$cache_key" ] || fail conversions_cache_key_present
	for branch_repo_sha in "$main_repo:$main_sha" "$stable_repo:$stable_sha"; do
		branch_repo=${branch_repo_sha%:*}
		branch_sha=${branch_repo_sha##*:}
		cache_sql=$(git -C "$branch_repo" show "${branch_sha}:migrations/0225.sql")
		printf '%s\n' "$cache_sql" | grep -Fq 'CREATE TABLE cache__quantity_unit_conversions_resolved' || fail conversions_cache_table_contract
		printf '%s\n' "$cache_sql" | grep -Fq "CREATE INDEX $cache_index" || fail conversions_cache_index_contract
		for trigger in quantity_unit_conversions_INS quantity_unit_conversions_UPD quantity_unit_conversions_DEL; do
			printf '%s\n' "$cache_sql" | grep -Fq "CREATE TRIGGER $trigger" || fail conversions_cache_trigger_contract
		done
	done
	pass conversions_characterized_cache_adapter

	# The document must prove every protected consumer on both branches before anything may activate.
	protected_count=$(sed -n 's/^| \([a-z][a-z-]*\) | \([0-9][0-9.]*\) | `\(\/[0-9\/]*\)` |$/\1/p' "$characterization" | LC_ALL=C sort -u | awk 'END { print NR }')
	[ "$protected_count" -eq 8 ] || fail conversions_protected_consumer_evidence
	pass conversions_protected_consumer_evidence

	# The activation evidence ledger contract, and the single write authority that owns it.
	migration_source="$main_repo/custom/grocy_AI/src/GrocyAiConversionMigration.php"
	service_source="$main_repo/custom/grocy_AI/src/GrocyAiConversionService.php"
	grep -Fq 'CREATE TABLE IF NOT EXISTS grocy_ai_conversion_activation_evidence' "$migration_source" || fail conversions_evidence_ledger_table
	grep -Fq 'CREATE TABLE IF NOT EXISTS grocy_ai_conversion_rule_revisions' "$migration_source" || fail conversions_rule_revision_table
	for column in main_commit stable_commit characterization_sha256 selected_adapter cache_key_schema query_plan_sha256 protected_outputs_sha256 evidence_hash; do
		grep -Fq "$column" "$migration_source" || fail conversions_evidence_ledger_columns
	done
	grep -Fq 'public function ActivateVerifiedRuleset' "$service_source" || fail conversions_activation_authority
	[ "$(grep -c "UPDATE grocy_ai_conversion_rule_revisions SET status = 'active'" "$service_source")" -eq 1 ] || fail conversions_single_activation_statement
	if grep -Eq "(INSERT|UPDATE|DELETE|REPLACE)[^;']*cache__quantity_unit_conversions_resolved" "$service_source"; then
		fail conversions_no_adhoc_cache_sql
	fi
	if grep -Eq '\bDROP[[:space:]]+(TABLE|TRIGGER|INDEX)\b' "$service_source"; then
		fail conversions_no_phase6_cleanup
	fi
	pass conversions_activation_ledger_contract

	# The promotion command is the sole operational path, and it must stay a thin delegate.
	command_source="$main_repo/custom/grocy_AI/bin/activate-verified-conversion-ruleset.php"
	[ -f "$command_source" ] || fail conversions_promotion_command_present
	[ "$(grep -c -- '->ActivateVerifiedRuleset(' "$command_source")" -eq 1 ] || fail conversions_promotion_single_delegate
	grep -Fq "PHP_SAPI !== 'cli'" "$command_source" || fail conversions_promotion_cli_only
	if grep -Eq '\b(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER)\b' "$command_source"; then
		fail conversions_promotion_no_sql
	fi
	if grep -Eq 'quantity_unit_conversions|cache__|grocy_ai_conversion_' "$command_source"; then
		fail conversions_promotion_no_relation_names
	fi
	if grep -Eq -- '--(adapter|factor|cache|sql|projection|path)\b' "$command_source"; then
		fail conversions_promotion_no_generic_option
	fi
	# No browser or HTTP surface may reach promotion, and no alternative command may exist.
	if grep -Eqi 'activate|promot' "$main_repo/custom/grocy_AI/routes.php"; then
		fail conversions_promotion_no_http_route
	fi
	if grep -Eqi 'ActivateVerifiedRuleset|activate-verified' "$main_repo/custom/grocy_AI/src/GrocyAiApiController.php"; then
		fail conversions_promotion_no_api_path
	fi
	alternative_commands=$(find "$main_repo/custom/grocy_AI/bin" -type f -name '*.php' -exec grep -l -- '->ActivateVerifiedRuleset(' {} + | LC_ALL=C sort)
	[ "$alternative_commands" = "$command_source" ] || fail conversions_promotion_sole_command
	# The maintainer secret is deployment-owned: no path or secret may be committed.
	if grep -Eq '/etc/[A-Za-z0-9_/.-]*(auth|secret)' "$command_source"; then
		fail conversions_promotion_no_committed_secret_path
	fi
	if grep -Eq '[0-9a-f]{64}' "$command_source"; then
		fail conversions_promotion_no_committed_secret
	fi
	pass conversions_sole_promotion_command

	run_quiet conversions_activation_command "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-activation-command
	run_quiet conversions_release_gate_cases "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-release-gate
	run_quiet conversions_post_activation_bypass "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-post-activation-bypass
	run_quiet conversions_native_save_hook "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-native-save-hook
	run_quiet conversions_rules "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-rules
	run_quiet conversions_resolution "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-resolution
	run_quiet conversions_product_status "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-product-status
	run_quiet conversions_coverage "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-coverage
	run_quiet conversions_readonly_cli "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" conversion-readonly-cli
	run_quiet conversions_module_suite env GROCY_BLADE_AUTOLOAD="$main_repo/packages/autoload.php" "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php"
	run_quiet conversions_command_lint "$php_runner" -l "$command_source"
	run_quiet conversions_migration_lint "$php_runner" -l "$migration_source"
	run_quiet conversions_service_lint "$php_runner" -l "$service_source"
	run_quiet conversions_controller_lint "$php_runner" -l "$main_repo/custom/grocy_AI/src/GrocyAiConversionController.php"
	run_quiet conversions_tests_lint "$php_runner" -l "$main_repo/custom/grocy_AI/tests/conversions.php"
	run_quiet conversions_runner_lint "$php_runner" -l "$main_repo/custom/grocy_AI/tests/run.php"

	echo "RELEASE_GATE: PASS (conversions)"
}

bulk_release_gate()
{
	temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/grocy-ai-release-gate.XXXXXX")
	trap 'rm -rf "$temporary_root"' EXIT HUP INT TERM

	manifest="$main_repo/custom/grocy_AI/portable-files.txt"
	service_source="$main_repo/custom/grocy_AI/src/GrocyAiBulkService.php"
	migration_source="$main_repo/custom/grocy_AI/src/GrocyAiBulkMigration.php"
	controller_source="$main_repo/custom/grocy_AI/src/GrocyAiApiController.php"
	routes_source="$main_repo/custom/grocy_AI/routes.php"

	# Test 1 (manifest membership): every new Phase 5 module file under a manifest-tracked tree is a
	# declared portable file that exists, and the manifest stays LC_ALL=C sorted-unique with safe paths.
	# The bulk review Blade view is deliberately excluded — Blade views live in the core `views/` tree and
	# ship through the branch-adapter / changed-paths mechanism, matching the untracked conversion view.
	required_portable_paths=$(printf '%s\n' \
		'custom/grocy_AI/src/GrocyAiBulkController.php' \
		'custom/grocy_AI/src/GrocyAiBulkMigration.php' \
		'custom/grocy_AI/src/GrocyAiBulkService.php' \
		'custom/grocy_AI/tests/bulk.php' \
		'custom/grocy_AI/tests/fixtures/bulk-plan-cases.json' \
		'custom/grocy_AI/tests/fixtures/bulk-registry-cases.json' \
		'public/custom/grocy_AI/bulk-review.js' \
		'public/custom/grocy_AI/bulk-review.test.js')
	while IFS= read -r path; do
		grep -Fqx -- "$path" "$manifest" || fail bulk_portable_manifest
		[ -f "$main_repo/$path" ] || fail bulk_portable_source
	done <<EOF
$required_portable_paths
EOF
	manifest_paths=$(sed '/^$/d' "$manifest")
	manifest_sorted=$(printf '%s\n' "$manifest_paths" | LC_ALL=C sort -u)
	[ "$manifest_paths" = "$manifest_sorted" ] || fail bulk_portable_manifest_sorted_unique
	case "$manifest_paths" in
		/*|*'/../'*|../*|*'/..') fail bulk_portable_manifest_safe_paths ;;
	esac
	while IFS= read -r path; do
		[ -n "$path" ] || continue
		[ -f "$main_repo/$path" ] || fail bulk_portable_manifest_source
	done <<EOF
$manifest_paths
EOF
	# The bulk review Blade view is NOT a manifest-tracked path; assert it stays OUT of the manifest and
	# that the adapter-carried view file exists on disk so it ships on both branches.
	if grep -Fqx -- 'views/grocyai_bulkreview.blade.php' "$manifest"; then
		fail bulk_blade_view_not_manifest_tracked
	fi
	[ -f "$main_repo/views/grocyai_bulkreview.blade.php" ] || fail bulk_blade_view_present
	pass bulk_portable_manifest

	# Test 2 (named-operation-only apply, no ad-hoc native/cache write, append-only audit): the service
	# resolves durable writes only through the closed registry and its sole durable delegate,
	# ->AssignProductTaxonomy(. It issues no INSERT/UPDATE/DELETE/REPLACE against native product/taxonomy/
	# conversion/cache relations, and never UPDATE/DELETEs the append-only audit ledger.
	grep -Fq 'RegisteredOperations' "$service_source" || fail bulk_closed_registry
	grep -Fq -- '->AssignProductTaxonomy(' "$service_source" || fail bulk_named_delegate
	if grep -Eq "(INSERT|UPDATE|DELETE|REPLACE)[^;']*(products|grocy_ai_taxonomy_classifications|quantity_unit_conversions|cache__)" "$service_source"; then
		fail bulk_no_adhoc_native_write
	fi
	if grep -Eq "(UPDATE|DELETE|REPLACE)[^;']*grocy_ai_bulk_audit" "$service_source"; then
		fail bulk_append_only_audit
	fi
	pass bulk_named_operation_contract

	# Test 3 (no network primitive in the apply/rollback path): apply and rollback run entirely under a
	# BEGIN IMMEDIATE write lock and must never touch the network while it is held. Assert the service
	# contains zero network primitives anywhere — curl, an http(s) stream wrapper, a raw socket, or a
	# provider/companion client call. The local module-version.json read is not an http wrapper and is
	# intentionally not matched.
	if grep -Eq "curl_|file_get_contents\('http|fsockopen|stream_socket|new GrocyAiService|->EnrichByUpc\(|->FetchImage\(" "$service_source"; then
		fail bulk_no_network_primitive
	fi
	pass bulk_no_network_primitive

	# Test 4 (single guarded transaction + checksum idempotency): apply/rollback take the write lock with
	# a raw BEGIN IMMEDIATE, have a single COMMIT path, and bind the applied artifact to the reviewed one
	# with a hash_equals checksum gate before any write. The audit ledger is created in the migration.
	grep -Fq "\$this->Db->exec('BEGIN IMMEDIATE')" "$service_source" || fail bulk_begin_immediate
	grep -Fq "\$this->Db->exec('COMMIT')" "$service_source" || fail bulk_single_commit
	grep -Fq 'hash_equals(' "$service_source" || fail bulk_checksum_idempotency
	grep -Fq 'grocy_ai_bulk_audit' "$migration_source" || fail bulk_audit_table
	pass bulk_transaction_and_idempotency_contract

	# Test 5 (authority surface): the durable apply and rollback actions are permission-checked with
	# PERMISSION_MASTER_DATA_EDIT (grep only each method's own body per MISTAKES.md GREP-01), the export
	# read route is wired exactly once, and NO bin/ command applies or rolls back a bulk plan (no
	# maintainer CLI apply is added in Phase 5, per D-13).
	apply_body=$(awk '/public function BulkPlanApply\(/{f=1} f{print} f&&/^\t}$/{exit}' "$controller_source")
	printf '%s\n' "$apply_body" | grep -Fq 'User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT)' || fail bulk_apply_permission
	rollback_body=$(awk '/public function BulkPlanRollback\(/{f=1} f{print} f&&/^\t}$/{exit}' "$controller_source")
	printf '%s\n' "$rollback_body" | grep -Fq 'User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT)' || fail bulk_rollback_permission
	[ "$(grep -c -- 'ExportBulkPlan' "$routes_source")" -eq 1 ] || fail bulk_export_route_single
	cli_apply=$(find "$main_repo/custom/grocy_AI/bin" -type f -name '*.php' -exec grep -l -- '->ApplyPlan(\|->RollbackPlan(' {} + 2>/dev/null || true)
	[ -z "$cli_apply" ] || fail bulk_no_cli_apply
	pass bulk_authority_surface

	# Test 6 (fail-closed unit proof): run every bulk-* unit mode — including 05-01's contract/invariants/
	# schema so the RED contract is proven GREEN — then lint every new/changed PHP file through $php_runner.
	run_quiet bulk_contract "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-contract
	run_quiet bulk_invariants "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-invariants
	run_quiet bulk_schema "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-schema
	run_quiet bulk_generate "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-generate
	run_quiet bulk_registry "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-registry
	run_quiet bulk_selection "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-selection
	run_quiet bulk_conflict "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-conflict
	run_quiet bulk_apply "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-apply
	run_quiet bulk_audit "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-audit
	run_quiet bulk_rollback "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-rollback
	run_quiet bulk_export "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php" bulk-export
	run_quiet bulk_service_lint "$php_runner" -l "$service_source"
	run_quiet bulk_migration_lint "$php_runner" -l "$migration_source"
	run_quiet bulk_controller_lint "$php_runner" -l "$controller_source"
	run_quiet bulk_routes_lint "$php_runner" -l "$routes_source"
	run_quiet bulk_tests_lint "$php_runner" -l "$main_repo/custom/grocy_AI/tests/bulk.php"

	# Test 7 (frontend): the read-only bulk review surface unit suite.
	run_quiet bulk_frontend node --test "$main_repo/public/custom/grocy_AI/bulk-review.test.js"

	echo "RELEASE_GATE: PASS (bulk)"
}

if [ "$#" -eq 1 ] && [ "$1" = taxonomy ]; then
	taxonomy_release_gate
	exit 0
fi

if [ "$#" -eq 1 ] && [ "$1" = conversions ]; then
	conversions_release_gate
	exit 0
fi

if [ "$#" -eq 1 ] && [ "$1" = bulk ]; then
	bulk_release_gate
	exit 0
fi

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
	main_hash=$(git -C "$main_repo" show "${main_sha}:${path}" | sha256)
	stable_hash=$(git -C "$stable_repo" show "${stable_portable_sha}:${path}" | sha256)
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

actual_dependency_hash=$(LC_ALL=C sort "$companion_repo/constraints-phase2.txt" | sha256)
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

run_quiet main_php_contract env GROCY_BLADE_AUTOLOAD="$main_repo/packages/autoload.php" "$php_runner" "$main_repo/custom/grocy_AI/tests/run.php"
run_quiet main_barcode_handoff "$php_runner" "$main_repo/custom/grocy_AI/tests/barcode-handoff.php"
run_quiet stable_controller_lint "$php_runner" -l "$stable_repo/custom/grocy_AI/src/GrocyAiApiController.php"
run_quiet stable_routes_lint "$php_runner" -l "$stable_repo/custom/grocy_AI/routes.php"
run_quiet stable_migration_lint "$php_runner" -l "$stable_repo/migrations/0256.php"
run_quiet browser_release npm --prefix "$main_repo/custom/grocy_AI/tests/browser" run test:release
run_quiet companion_unittest sh -c 'cd "$1" && exec .venv/bin/python -m unittest discover -s tests' sh "$companion_repo"

echo "RELEASE_GATE: PASS ($mode)"
