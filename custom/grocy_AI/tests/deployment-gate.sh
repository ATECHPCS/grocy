#!/bin/sh

set -eu

main_repo=$(CDPATH= cd -- "$(dirname -- "$0")/../../../" && pwd)
phase_dir="$main_repo/.planning/phases/02-enrichment-contract-barcode-handoff-secure-media"
baseline="$phase_dir/02-PHASE1-BASELINE.sha256"
release_gate="$main_repo/custom/grocy_AI/tests/release-gate.sh"
ssh_target=${GROCY_AI_DEPLOY_SSH:-root@10.10.0.156}
grocy_container=${GROCY_AI_GROCY_CONTAINER:-grocy}
companion_container=${GROCY_AI_COMPANION_CONTAINER:-grocy-mcp}
grocy_base=${GROCY_AI_GROCY_BASE_URL:-http://10.10.0.156:9283}

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
	echo "Usage: $0 candidate|predeploy <release-manifest>" >&2
	echo "       $0 postdeploy-companion|postdeploy-stable|postsmoke|final <release-manifest> <deployment-evidence>" >&2
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

evidence_field()
{
	key=$1
	file=$2
	count=$(sed -n "s/^${key}: //p" "$file" | awk 'END { print NR }')
	[ "$count" -eq 1 ] || fail "evidence_${key}_single"
	sed -n "s/^${key}: //p" "$file"
}

is_sha256()
{
	value=$1
	case "$value" in sha256:????????????????????????????????????????????????????????????????) ;;
		*) return 1 ;;
	esac
	case "${value#sha256:}" in *[!0-9a-f]*) return 1 ;; esac
}

validate_runtime_names()
{
	case "$ssh_target" in *[!A-Za-z0-9._@-]*) fail ssh_target_safety ;; esac
	case "$grocy_container" in *[!A-Za-z0-9._-]*) fail grocy_container_safety ;; esac
	case "$companion_container" in *[!A-Za-z0-9._-]*) fail companion_container_safety ;; esac
}

verify_baseline()
{
	[ -f "$baseline" ] || fail phase1_baseline_exists
	[ "$(awk 'END { print NR }' "$baseline")" -eq 2 ] || fail phase1_baseline_count
	expected=$(printf '%s\n' '.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md' '.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/phone-timings.jsonl')
	actual=$(awk '{print $2}' "$baseline" | LC_ALL=C sort)
	[ "$actual" = "$expected" ] || fail phase1_baseline_scope
	(cd "$main_repo" && shasum -a 256 -c "$baseline" >/dev/null 2>&1) || fail phase1_baseline_bytes
	grep -Fq 'SKIPPED — NOT ACCEPTED' "$main_repo/.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md" || fail phase1_not_accepted_semantics
	if grep -q '[^[:space:]]' "$main_repo/.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/phone-timings.jsonl"; then
		fail phase1_timing_evidence_unchanged
	fi
	pass phase1_byte_baseline
}

assert_repo_identities()
{
	main_sha=$(field main_candidate_sha "$manifest")
	companion_sha=$(field companion_candidate_sha "$manifest")
	stable_sha=$(field stable_adapter_sha "$manifest")
	stable_portable=$(field stable_portable_sha "$manifest")
	git -C "$main_repo" merge-base --is-ancestor "$main_sha" HEAD || fail main_candidate_ancestor
	[ "$(git -C /Users/ian/Documents/Repos/grocy-mcp rev-parse HEAD)" = "$companion_sha" ] || fail companion_candidate_head
	[ "$(git -C /Users/ian/Documents/Repos/grocy-atech-release rev-parse HEAD)" = "$stable_sha" ] || fail stable_adapter_head
	[ "$(git -C /Users/ian/Documents/Repos/grocy-atech-release rev-parse "${stable_sha}^")" = "$stable_portable" ] || fail stable_adapter_parent
	pass immutable_repository_identities
}

remote_value()
{
	ssh "$ssh_target" "$1"
}

container_image_id()
{
	container=$1
	remote_value "docker inspect --format '{{.Image}}' '$container'"
}

container_revision()
{
	container=$1
	remote_value "docker inspect --format '{{ index .Config.Labels \"org.opencontainers.image.revision\" }}' '$container'"
}

assert_container_running()
{
	container=$1
	name=$2
	[ "$(remote_value "docker inspect --format '{{.State.Running}}' '$container'")" = true ] || fail "${name}_running"
	pass "${name}_running"
}

assert_single_port()
{
	port=$1
	name=$2
	count=$(remote_value "docker ps --filter 'publish=$port' --format '{{.Names}}'" | awk 'NF { count++ } END { print count + 0 }')
	[ "$count" -eq 1 ] || fail "${name}_single_port"
	pass "${name}_single_port"
}

assert_mount()
{
	mount=$(remote_value "docker inspect --format '{{range .Mounts}}{{if eq .Destination \"/config\"}}{{.Source}}|{{.RW}}{{end}}{{end}}' '$grocy_container'")
	[ "$mount" = '/etc/komodo/grocy|true' ] || fail grocy_config_mount
	pass grocy_config_mount
}

collision_groups()
{
	ssh "$ssh_target" "docker exec -i '$grocy_container' php" <<'PHP'
<?php
$lengths = [8, 12, 13, 14];
$cases = [];
foreach ($lengths as $length)
{
	$terms = [];
	for ($position = 1; $position < $length; $position++)
	{
		$weight = (($length - 1 - $position) % 2 === 0) ? 3 : 1;
		$terms[] = $weight . ' * CAST(substr(barcode, ' . $position . ', 1) AS INTEGER)';
	}
	$cases[] = 'WHEN ' . $length . ' THEN (' . implode(' + ', $terms) . ')';
}
$expression = "CASE WHEN length(barcode) IN (8, 12, 13, 14) AND barcode NOT GLOB '*[^0-9]*'"
	. ' AND CAST(substr(barcode, -1, 1) AS INTEGER) = ((10 - ((CASE length(barcode) '
	. implode(' ', $cases) . " ELSE 0 END) % 10)) % 10) THEN substr('00000000000000' || barcode, -14, 14) ELSE NULL END";
$pdo = new PDO('sqlite:/config/data/grocy.db');
$pdo->exec('PRAGMA query_only = ON');
echo (int)$pdo->query('SELECT COUNT(*) FROM (SELECT ' . $expression . ' AS canonical_gtin FROM product_barcodes GROUP BY canonical_gtin HAVING canonical_gtin IS NOT NULL AND COUNT(*) > 1)')->fetchColumn(), PHP_EOL;
PHP
}

protected_fingerprint()
{
	ssh "$ssh_target" "docker exec -i '$grocy_container' php" <<'PHP'
<?php
$pdo = new PDO('sqlite:/config/data/grocy.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA query_only = ON');
$tables = ['product_barcodes', 'product_groups', 'products', 'quantity_unit_conversions', 'quantity_units', 'stock', 'stock_log'];
$context = hash_init('sha256');
foreach ($tables as $table)
{
	$exists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
	if ($exists !== 1) throw new RuntimeException('protected table unavailable');
	hash_update($context, $table . "\n");
	$statement = $pdo->query('SELECT * FROM "' . $table . '" ORDER BY rowid');
	while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false)
	{
		hash_update($context, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
	}
}
$root = '/config/data/storage/productpictures';
if (!is_dir($root)) throw new RuntimeException('picture tree unavailable');
$paths = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file)
{
	if ($file->isFile()) $paths[] = $file->getPathname();
}
sort($paths, SORT_STRING);
foreach ($paths as $path)
{
	$relative = substr($path, strlen($root) + 1);
	hash_update($context, $relative . '|' . filesize($path) . '|' . hash_file('sha256', $path) . "\n");
}
echo hash_final($context), PHP_EOL;
PHP
}

assert_migration_state()
{
	index_count=$(remote_value "docker exec '$grocy_container' php -r '\$p=new PDO(\"sqlite:/config/data/grocy.db\"); echo (int)\$p->query(\"SELECT COUNT(*) FROM sqlite_master WHERE type=\\\"index\\\" AND name=\\\"ix_product_barcodes_canonical_gtin\\\"\")->fetchColumn();'")
	[ "$index_count" -eq 1 ] || fail canonical_index
	[ "$(collision_groups)" -eq 0 ] || fail canonical_collision_groups
	remote_value "docker exec '$grocy_container' test -f /app/www/migrations/0256.php" >/dev/null || fail migration_file
	pass canonical_migration_state
}

assert_evidence_privacy()
{
	file=$1
	[ -f "$file" ] || fail deployment_evidence_exists
	if grep -Eiq '(https?://|cookie|authorization|csrf|api[_ -]?key|bearer|traceparent|thumbnail_handle|full_handle|gtin|barcode_value|product_name|response_body|request_body)' "$file"; then
		fail deployment_evidence_privacy
	fi
	pass deployment_evidence_privacy
}

assert_companion_deployment()
{
	expected_revision=$(field companion_candidate_sha "$manifest")
	expected_image=$(evidence_field companion_image_id "$evidence")
	is_sha256 "$expected_image" || fail companion_image_format
	assert_container_running "$companion_container" companion
	assert_single_port 3061 companion
	[ "$(container_revision "$companion_container")" = "$expected_revision" ] || fail companion_running_revision
	[ "$(container_image_id "$companion_container")" = "$expected_image" ] || fail companion_running_image
	remote_value "docker image inspect '$expected_image'" >/dev/null || fail companion_image_resolvable
	pass companion_deployment_identity
}

assert_stable_deployment()
{
	expected_revision=$(field stable_adapter_sha "$manifest")
	expected_image=$(evidence_field stable_image_id "$evidence")
	is_sha256 "$expected_image" || fail stable_image_format
	assert_container_running "$grocy_container" stable
	assert_single_port 9283 stable
	[ "$(container_revision "$grocy_container")" = "$expected_revision" ] || fail stable_running_revision
	[ "$(container_image_id "$grocy_container")" = "$expected_image" ] || fail stable_running_image
	remote_value "docker image inspect '$expected_image'" >/dev/null || fail stable_image_resolvable
	assert_mount
	cache_marker=$(field stable_cache_marker "$manifest")
	module_version=$(field stable_module_version "$manifest")
	running_cache=$(remote_value "docker exec '$grocy_container' cat /app/www/version.json" | sed -n 's/.*"Customization": "\([^"]*\)".*/\1/p')
	running_module=$(remote_value "docker exec '$grocy_container' cat /app/www/custom/grocy_AI/module-version.json" | sed -n 's/.*"module_version": "\([^"]*\)".*/\1/p')
	[ "$running_cache" = "$cache_marker" ] || fail running_cache_marker
	[ "$running_module" = "$module_version" ] || fail running_module_marker
	assert_migration_state
	pass stable_deployment_identity
}

assert_prior_images()
{
	prior_companion=$(evidence_field prior_companion_image_id "$evidence")
	prior_stable=$(evidence_field prior_stable_image_id "$evidence")
	is_sha256 "$prior_companion" || fail prior_companion_format
	is_sha256 "$prior_stable" || fail prior_stable_format
	remote_value "docker image inspect '$prior_companion'" >/dev/null || fail prior_companion_resolvable
	remote_value "docker image inspect '$prior_stable'" >/dev/null || fail prior_stable_resolvable
	pass prior_images_resolvable
}

assert_fingerprint_matches()
{
	expected=$(evidence_field predeploy_fingerprint "$evidence")
	case "$expected" in ????????????????????????????????????????????????????????????????) ;;
		*) fail predeploy_fingerprint_format ;;
	esac
	case "$expected" in *[!0-9a-f]*) fail predeploy_fingerprint_format ;; esac
	actual=$(protected_fingerprint)
	[ "$actual" = "$expected" ] || fail protected_fingerprint_match
	pass protected_fingerprint_match
}

assert_smoke_reads()
{
	auth_header=${GROCY_AI_AUTH_HEADER:-}
	smoke_gtin=${GROCY_AI_SMOKE_GTIN:-}
	[ -n "$auth_header" ] || fail authenticated_header_available
	case "$smoke_gtin" in 8|12|13|14) fail smoke_gtin_value ;; esac
	case "$smoke_gtin" in *[!0-9]*) fail smoke_gtin_value ;; esac
	length=${#smoke_gtin}
	case "$length" in 8|12|13|14) ;; *) fail smoke_gtin_value ;; esac

	status_code=$(curl -sS -o /dev/null -w '%{http_code}' "$grocy_base/api/grocy-ai/status")
	case "$status_code" in 401|403) ;; *) fail unauthenticated_status_denied ;; esac
	owner_code=$(curl -sS -o /dev/null -w '%{http_code}' "$grocy_base/api/grocy-ai/barcodes/resolve/$smoke_gtin")
	case "$owner_code" in 401|403) ;; *) fail unauthenticated_owner_denied ;; esac
	enrich_code=$(curl -sS -o /dev/null -w '%{http_code}' "$grocy_base/api/grocy-ai/products/enrich/upc/$smoke_gtin")
	case "$enrich_code" in 401|403) ;; *) fail unauthenticated_enrichment_denied ;; esac
	pass unauthenticated_reads_denied

	status_code=$(curl -sS -H "$auth_header" -o "$temporary_root/status.json" -w '%{http_code}' "$grocy_base/api/grocy-ai/status")
	[ "$status_code" = 200 ] || fail authenticated_status
	owner_code=$(curl -sS -H "$auth_header" -o "$temporary_root/owner.json" -w '%{http_code}' "$grocy_base/api/grocy-ai/barcodes/resolve/$smoke_gtin")
	[ "$owner_code" = 200 ] || fail authenticated_owner
	enrich_code=$(curl -sS -H "$auth_header" -o "$temporary_root/enrich.json" -w '%{http_code}' "$grocy_base/api/grocy-ai/products/enrich/upc/$smoke_gtin")
	[ "$enrich_code" = 200 ] || fail authenticated_enrichment
	php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); if (($d["contract_version"] ?? null) !== 2) exit(1);' "$temporary_root/enrich.json" || fail authenticated_contract_v2
	pass authenticated_contract_owner_reads

	form_code=$(curl -sS -H "$auth_header" -o "$temporary_root/product-form.html" -w '%{http_code}' "$grocy_base/product/new")
	[ "$form_code" = 200 ] || fail authenticated_product_form
	module_version=$(field stable_module_version "$manifest")
	grep -Fq "product-enrichment.js?v=$module_version" "$temporary_root/product-form.html" || fail served_asset_marker
	pass served_asset_marker

	handles=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $m=$d["media"][0] ?? null; if (is_array($m)) echo ($m["thumbnail_handle"] ?? ""), "\n", ($m["full_handle"] ?? "");' "$temporary_root/enrich.json")
	thumbnail=$(printf '%s\n' "$handles" | sed -n '1p')
	full=$(printf '%s\n' "$handles" | sed -n '2p')
	if [ -n "$thumbnail" ] && [ -n "$full" ]; then
		unauth_media=$(curl -sS -o /dev/null -w '%{http_code}' "$grocy_base/api/grocy-ai/images/thumbnail/$thumbnail")
		case "$unauth_media" in 401|403) ;; *) fail unauthenticated_media_denied ;; esac
		thumb_code=$(curl -sS -D "$temporary_root/thumb.headers" -H "$auth_header" -o "$temporary_root/thumb.bin" -w '%{http_code}' "$grocy_base/api/grocy-ai/images/thumbnail/$thumbnail")
		full_code=$(curl -sS -D "$temporary_root/full.headers" -H "$auth_header" -o "$temporary_root/full.bin" -w '%{http_code}' "$grocy_base/api/grocy-ai/images/full/$full")
		[ "$thumb_code" = 200 ] || fail authenticated_thumbnail
		[ "$full_code" = 200 ] || fail authenticated_full_media
		grep -Eiq '^cache-control:.*private.*no-store' "$temporary_root/thumb.headers" || fail thumbnail_private_no_store
		grep -Eiq '^cache-control:.*private.*no-store' "$temporary_root/full.headers" || fail full_private_no_store
		grep -Eiq '^content-type: image/(jpeg|png|webp)' "$temporary_root/thumb.headers" || fail thumbnail_content_type
		grep -Eiq '^content-type: image/(jpeg|png|webp)' "$temporary_root/full.headers" || fail full_content_type
		[ -s "$temporary_root/thumb.bin" ] || fail thumbnail_bytes
		[ -s "$temporary_root/full.bin" ] || fail full_media_bytes
		pass authenticated_secure_media
	else
		pass authenticated_secure_media_no_candidate
	fi
}

[ "$#" -ge 2 ] || usage
mode=$1
manifest_input=$2
case "$mode" in
	candidate|predeploy) [ "$#" -eq 2 ] || usage ;;
	postdeploy-companion|postdeploy-stable|postsmoke|final) [ "$#" -eq 3 ] || usage ;;
	*) usage ;;
esac

manifest=$(CDPATH= cd -- "$(dirname -- "$manifest_input")" && pwd)/$(basename -- "$manifest_input")
[ -f "$manifest" ] || fail release_manifest_exists
temporary_root=$(mktemp -d "${TMPDIR:-/tmp}/grocy-ai-deployment-gate.XXXXXX")
trap 'rm -rf "$temporary_root"' EXIT HUP INT TERM

verify_baseline
assert_repo_identities

if [ "$mode" = candidate ]; then
	GROCY_AI_RELEASE_GATE_CANDIDATE_STAGE=deployment "$release_gate" candidate "$manifest" || fail candidate_release_gate
	sh -n "$0" || fail deployment_gate_syntax
	echo 'DEPLOYMENT_GATE: PASS (candidate)'
	exit 0
fi

validate_runtime_names
assert_container_running "$grocy_container" stable
assert_container_running "$companion_container" companion

if [ "$mode" = predeploy ]; then
	[ -z "$(git -C "$main_repo" status --porcelain --untracked-files=all)" ] || fail predeploy_clean_main
	[ -z "$(git -C /Users/ian/Documents/Repos/grocy-atech-release status --porcelain --untracked-files=all)" ] || fail predeploy_clean_stable
	[ -z "$(git -C /Users/ian/Documents/Repos/grocy-mcp status --porcelain --untracked-files=all)" ] || fail predeploy_clean_companion
	pass predeploy_clean_worktrees
	[ "$(collision_groups)" -eq 0 ] || fail canonical_collision_groups
	assert_mount
	prior_companion=$(container_image_id "$companion_container")
	prior_stable=$(container_image_id "$grocy_container")
	is_sha256 "$prior_companion" || fail prior_companion_format
	is_sha256 "$prior_stable" || fail prior_stable_format
	remote_value "docker image inspect '$prior_companion'" >/dev/null || fail prior_companion_resolvable
	remote_value "docker image inspect '$prior_stable'" >/dev/null || fail prior_stable_resolvable
	pass prior_images_resolvable
	echo "PREDEPLOY_FINGERPRINT: $(protected_fingerprint)"
	echo "PRIOR_COMPANION_IMAGE_ID: $prior_companion"
	echo "PRIOR_STABLE_IMAGE_ID: $prior_stable"
	echo 'DEPLOYMENT_GATE: PASS (predeploy)'
	exit 0
fi

evidence_input=$3
evidence=$(CDPATH= cd -- "$(dirname -- "$evidence_input")" && pwd)/$(basename -- "$evidence_input")
assert_evidence_privacy "$evidence"
assert_prior_images

case "$mode" in
	postdeploy-companion)
		assert_companion_deployment
		;;
	postdeploy-stable)
		assert_stable_deployment
		assert_fingerprint_matches
		;;
	postsmoke)
		assert_companion_deployment
		assert_stable_deployment
		assert_smoke_reads
		assert_fingerprint_matches
		;;
	final)
		assert_companion_deployment
		assert_stable_deployment
		assert_fingerprint_matches
		;;
esac

echo "DEPLOYMENT_GATE: PASS ($mode)"
