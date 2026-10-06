<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GrocyAiGtin.php';
require_once __DIR__ . '/../src/GrocyAiCaptureMigration.php';
require_once __DIR__ . '/../src/GrocyAiReceiptMigration.php';
require_once __DIR__ . '/../src/GrocyAiCaptureResearchMigration.php';
require_once __DIR__ . '/../bin/capture-quota-recovery.php';

function checkQuota(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function refuseQuota(callable $action): void
{
	try { $action(); } catch (RuntimeException|InvalidArgumentException $expected) { return; }
	throw new RuntimeException('Expected recovery refusal');
}
function quotaFixture(): PDO
{
	$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$db->exec('PRAGMA foreign_keys = ON');
	$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT); CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
	GrocyAI\Services\GrocyAiCaptureResearchMigration::Bootstrap($db);
	$db->exec("INSERT INTO grocy_ai_capture_trips (id,status,module_version) VALUES(12,'reviewing','test'); INSERT INTO products VALUES(1,'untouched'); INSERT INTO stock_log VALUES(1)");
	$codes = [1 => '00020184302530', 2 => '00000002198842', 3 => '04061459264494', 9 => '00256695212482', 10 => '00209933211518', 13 => '08246160465262', 15 => '08062461396193'];
	$seq = 0;
	foreach ($codes as $id => $canonical)
	{
		$seq++;
		$raw = $canonical;
		$db->prepare("INSERT INTO grocy_ai_capture_lines (id,trip_id,seq,scanned_barcode,canonical_gtin,status,selected) VALUES(?,12,?,?,?,'unknown',1)")->execute([$id,$seq,$raw,$canonical]);
		$db->prepare("INSERT INTO grocy_ai_capture_research_jobs (id,canonical_gtin,state,attempts,result_revision) VALUES(?,?,'needs_input',2,2)")->execute([$id,$canonical]);
		$result = json_encode(['contract_version'=>2,'canonical_gtin'=>$canonical,'outcome'=>'miss','name_candidates'=>[],'name_candidate_sources'=>[],'brand'=>null,'package'=>null,'categories'=>[],'sources'=>[],'web_evidence'=>[]], JSON_THROW_ON_ERROR);
		$db->prepare("INSERT INTO grocy_ai_capture_research_drafts (job_id,trip_id,line_id,scanned_barcode,outcome,suggested_json,selected_json,user_edits_json,result_revision) VALUES(?,12,?,?,'needs_input',?,'{\"name\":\"receipt edit\"}','{\"name\":true}',2)")->execute([$id,$id,$raw,$result]);
		$db->prepare("INSERT INTO grocy_ai_capture_web_search_reservations (job_id,canonical_gtin,utc_day,actor,retry_generation) VALUES(?,?,date('now'),'research-worker',0)")->execute([$id,$canonical]);
	}
	return $db;
}

$db = quotaFixture();
$preview = captureQuotaRecoveryPreview($db);
checkQuota($preview['candidate_ids'] === [1,2,3,9,10,13,15] && $preview['candidate_count'] === 7 && $preview['max_chargeable_calls'] === 7, 'exact seven jobs eligible');
checkQuota(!str_contains(json_encode($preview), 'receipt edit'), 'preview excludes private draft data');
$db->exec('PRAGMA query_only = ON');
checkQuota(captureQuotaRecoveryPreview($db) === $preview, 'preview is read-only and stable');
$db->exec('PRAGMA query_only = OFF');
foreach (["UPDATE grocy_ai_capture_lines SET selected=0 WHERE id=1", "UPDATE grocy_ai_capture_trips SET status='committed' WHERE id=12", "UPDATE grocy_ai_capture_research_drafts SET selected_json='{}' WHERE job_id=1", "INSERT INTO grocy_ai_capture_web_search_reservations(job_id,canonical_gtin,utc_day,actor,retry_generation) VALUES(1,'00020184302530',date('now'),'test',1)"] as $change)
{
	$f = quotaFixture(); $before = captureQuotaRecoveryPreview($f); $f->exec($change); refuseQuota(fn() => captureQuotaRecoveryApply($f, $before['checksum']));
}
foreach (["UPDATE grocy_ai_capture_research_jobs SET retry_generation=1 WHERE id=1", "UPDATE grocy_ai_capture_research_jobs SET state='ready' WHERE id=1", "UPDATE grocy_ai_capture_research_drafts SET outcome='approved' WHERE job_id=1", "UPDATE grocy_ai_capture_lines SET canonical_gtin='00000000000000' WHERE id=1", "UPDATE grocy_ai_capture_research_drafts SET suggested_json=json_set(suggested_json,'$.outcome','found') WHERE job_id=1", "INSERT INTO grocy_ai_capture_trip_cancellations(trip_id,actor) VALUES(12,'test')"] as $change)
{
	$f = quotaFixture(); $f->exec($change); checkQuota(captureQuotaRecoveryPreview($f)['candidate_count'] < 7, 'blocker excludes changed row');
}
$beforeData = [];
foreach (['grocy_ai_capture_trips','grocy_ai_capture_lines','grocy_ai_capture_research_drafts','grocy_ai_capture_web_search_reservations','products','stock_log'] as $table) $beforeData[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
$result = captureQuotaRecoveryApply($db, $preview['checksum']);
checkQuota($result['requeued_ids'] === [1,2,3,9,10,13,15], 'apply returns exact IDs');
checkQuota($db->query("SELECT COUNT(*) FROM grocy_ai_capture_research_jobs WHERE state='queued' AND attempts=0 AND retry_generation=1")->fetchColumn() == 7, 'one reserved generation per job');
checkQuota($db->query("SELECT COUNT(*) FROM grocy_ai_capture_research_audit WHERE action='grocy_ai:quota_recovery'")->fetchColumn() == 7, 'each job audited');
foreach ($beforeData as $table => $rows) checkQuota($db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) === $rows, 'preserve ' . $table);
refuseQuota(fn() => captureQuotaRecoveryApply($db, $preview['checksum']));
$f = quotaFixture(); $f->exec("CREATE TRIGGER fail_recovery_audit BEFORE INSERT ON grocy_ai_capture_research_audit BEGIN SELECT RAISE(ABORT,'test'); END"); $p = captureQuotaRecoveryPreview($f); refuseQuota(fn() => captureQuotaRecoveryApply($f,$p['checksum'])); checkQuota($f->query("SELECT COUNT(*) FROM grocy_ai_capture_research_jobs WHERE state='queued'")->fetchColumn() == 0, 'audit failure rolls back all jobs');
$f = quotaFixture(); $p = captureQuotaRecoveryPreview($f); $f->exec("INSERT INTO grocy_ai_capture_research_jobs (id,canonical_gtin) VALUES (30,'00000000000000')"); for ($i=30;$i<44;$i++) { if ($i>30) $f->prepare("INSERT INTO grocy_ai_capture_research_jobs (id,canonical_gtin) VALUES (?,?)")->execute([$i,str_pad((string)$i,14,'0',STR_PAD_LEFT)]); $f->prepare("INSERT INTO grocy_ai_capture_web_search_reservations (job_id,canonical_gtin,utc_day,actor,retry_generation) VALUES (?,(SELECT canonical_gtin FROM grocy_ai_capture_research_jobs WHERE id=?),date('now'),'test',0)")->execute([$i,$i]); } checkQuota(captureQuotaRecoveryPreview($f)['remaining_slots'] === 0, 'daily cap includes unrelated reservations'); refuseQuota(fn() => captureQuotaRecoveryApply($f,$p['checksum']));
$dir = sys_get_temp_dir() . '/quota-recovery-' . bin2hex(random_bytes(6)); mkdir($dir, 0700); $path = $dir . '/grocy.db'; $f = quotaFixture(); $f->exec('VACUUM INTO ' . $f->quote($path));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/capture-quota-recovery.php') . ' --db=' . escapeshellarg($path);
$bytes = file_get_contents($path); $output = []; exec($command . ' --dry-run 2>&1', $output, $status); $cli = json_decode(implode("\n", $output), true);
checkQuota($status === 0 && $cli['candidate_ids'] === [1,2,3,9,10,13,15] && file_get_contents($path) === $bytes, 'CLI dry run preserves database bytes');
$output = []; exec($command . ' --apply --checksum=' . escapeshellarg($cli['checksum']) . ' 2>&1', $output, $status); checkQuota($status === 0 && json_decode(implode("\n", $output), true)['requeued_ids'] === [1,2,3,9,10,13,15], 'CLI applies exact checksum');
unlink($path); rmdir($dir);
echo "capture quota recovery: PASS\n";
