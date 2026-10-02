<?php
declare(strict_types=1);
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration'] as $file) require_once __DIR__ . '/../src/' . $file . '.php';
function checkRequeue(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
checkRequeue(is_file(__DIR__ . '/../bin/capture-web-search-requeue.php'), 'Missing bounded settled-job requeue CLI');
require_once __DIR__ . '/../bin/capture-web-search-requeue.php';
function fixtureRequeue(): PDO
{
	$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT); CREATE TABLE stock_log (id INTEGER PRIMARY KEY, note TEXT)');
	GrocyAI\Services\GrocyAiCaptureResearchMigration::Bootstrap($db);
	$db->exec("INSERT INTO products VALUES(1,'existing'); INSERT INTO product_barcodes VALUES(1,1,'existing'); INSERT INTO stock_log VALUES(1,'untouched')");
	// Exercise defensive deduplication with a legacy fixture lacking line coalescing.
	$db->exec('DROP INDEX grocy_ai_capture_lines_coalesce_idx');
	$db->exec("INSERT INTO grocy_ai_capture_trips (id,status,module_version) VALUES (12,'reviewing','test'),(13,'open','test'); INSERT INTO grocy_ai_capture_lines (id,trip_id,seq,scanned_barcode,canonical_gtin,status,selected) VALUES (1,12,1,'4006381333931','04006381333931','unknown',1),(2,12,2,'4006381333931','04006381333931','unknown',1); INSERT INTO grocy_ai_capture_research_jobs (id,canonical_gtin,state,attempts,result_revision) VALUES (1,'04006381333931','needs_input',3,1)");
	$db->exec("INSERT INTO grocy_ai_receipts(id,trip_id,image_id,mime_type,image_bytes) VALUES(1,12,'image','image/png',5)");
	$result = json_encode(['contract_version'=>1,'canonical_gtin'=>'04006381333931','outcome'=>'miss','name_candidates'=>[],'brand'=>null,'package'=>null,'categories'=>[],'sources'=>[]]);
	$insert = $db->prepare("INSERT INTO grocy_ai_capture_research_drafts (job_id,trip_id,line_id,scanned_barcode,outcome,suggested_json,selected_json,user_edits_json,receipt_evidence,result_revision) VALUES (1,12,?,'4006381333931','needs_input',?,'{\"name\":\"edited\"}','{\"name\":true}','private receipt',1)");
	foreach ([1,2] as $id) $insert->execute([$id,$result]);
	return $db;
}
function rejectRequeue(callable $f): void { try {$f();} catch (RuntimeException|InvalidArgumentException $e) {return;} throw new RuntimeException('Expected refusal'); }
$db = fixtureRequeue();
$p = captureWebSearchRequeuePreview($db,12);
checkRequeue($p['candidate_ids'] === [1] && $p['candidate_count'] === 1 && $p['max_chargeable_calls'] === 1, 'deduplicated eligibility and cap');
checkRequeue(!str_contains(json_encode($p),'400638') && !str_contains(json_encode($p),'private receipt'), 'preview excludes private values');
$db->exec('PRAGMA query_only=ON');
checkRequeue(captureWebSearchRequeuePreview($db,12) === $p,'stable read-only preview');
$db->exec('PRAGMA query_only=OFF');
foreach (["UPDATE grocy_ai_capture_lines SET selected=0", "UPDATE grocy_ai_capture_trips SET status='committed' WHERE id=12", "UPDATE grocy_ai_capture_research_jobs SET revision=revision+1", "UPDATE grocy_ai_capture_research_drafts SET selected_json='{}'", "INSERT INTO grocy_ai_capture_web_search_reservations(job_id,canonical_gtin,utc_day,actor,retry_generation) VALUES(1,'04006381333931',date('now'),'test',0)"] as $sql)
{
	$f=fixtureRequeue(); $before=captureWebSearchRequeuePreview($f,12); $f->exec($sql); rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$before['checksum']));
}
foreach (["UPDATE grocy_ai_capture_research_jobs SET state='ready'", "UPDATE grocy_ai_capture_research_jobs SET state='leased'", "UPDATE grocy_ai_capture_research_jobs SET safe_error_code='timeout'", "UPDATE grocy_ai_capture_research_jobs SET retry_generation=1", "UPDATE grocy_ai_capture_research_drafts SET final_product_id=5", "UPDATE grocy_ai_capture_lines SET selected=0", "UPDATE grocy_ai_capture_lines SET canonical_gtin='00000000000000'", "UPDATE grocy_ai_capture_research_drafts SET suggested_json=json_set(suggested_json,'$.error_code','timeout')", "INSERT INTO grocy_ai_capture_trip_cancellations(trip_id,actor) VALUES(12,'test')", "INSERT INTO grocy_ai_capture_research_drafts(job_id,trip_id,scanned_barcode) VALUES(1,13,'4006381333931')"] as $sql)
{
	$f=fixtureRequeue(); $f->exec($sql); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'blocker: '.$sql);
}
$preserved=[];
foreach (['grocy_ai_capture_trips','grocy_ai_capture_lines','grocy_ai_capture_research_drafts','products','product_barcodes','stock_log','grocy_ai_receipts','grocy_ai_receipt_lines'] as $table) $preserved[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
$a=captureWebSearchRequeueApply($db,12,$p['checksum']);
checkRequeue($a['requeued_ids']===[1] && $a['requeued_count']===1,'exact apply');
$j=$db->query('SELECT * FROM grocy_ai_capture_research_jobs')->fetch(PDO::FETCH_ASSOC);
checkRequeue($j['state']==='queued' && $j['attempts']===0 && $j['retry_generation']===0 && $j['revision']===2,'reset job only');
checkRequeue($db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_audit')->fetchColumn()===2,'audit each duplicate draft');
checkRequeue($db->query('SELECT COUNT(*) FROM grocy_ai_capture_web_search_reservations')->fetchColumn()===0,'no paid reservation');
foreach ($preserved as $table=>$rows) checkRequeue($db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)===$rows,'preserve '.$table);
rejectRequeue(fn()=>captureWebSearchRequeueApply($db,12,$p['checksum']));
$f=fixtureRequeue(); $f->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON grocy_ai_capture_research_audit BEGIN SELECT RAISE(ABORT,'test failure'); END"); $fp=captureWebSearchRequeuePreview($f,12); rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$fp['checksum'])); checkRequeue($f->query('SELECT state FROM grocy_ai_capture_research_jobs')->fetchColumn()==='needs_input','atomic rollback');
$path=tempnam(sys_get_temp_dir(),'requeue-'); unlink($path); $f=fixtureRequeue(); $f->exec('VACUUM INTO '.$f->quote($path));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/capture-web-search-requeue.php').' --trip=12 --db='.escapeshellarg($path);
$fixtureBytes=file_get_contents($path);
exec($cmd.' --dry-run 2>&1',$output,$status); $cli=json_decode(implode("\n",$output),true); checkRequeue($status===0 && $cli['candidate_ids']===[1],'CLI preview'); checkRequeue(file_get_contents($path)===$fixtureBytes,'CLI dry run leaves database bytes unchanged');
$output=[]; exec($cmd.' --apply --checksum='.escapeshellarg($cli['checksum']).' 2>&1',$output,$status); checkRequeue($status===0 && json_decode(implode("\n",$output),true)['requeued_ids']===[1],'CLI apply'); unlink($path);
// Daily reservations for unrelated jobs still consume the UTC budget and invalidate previews.
$f=fixtureRequeue(); $before=captureWebSearchRequeuePreview($f,12);
for ($i=2;$i<=22;$i++)
{
	$gtin=str_pad((string)$i,14,'0',STR_PAD_LEFT);
	$f->prepare("INSERT INTO grocy_ai_capture_research_jobs(id,canonical_gtin) VALUES(?,?)")->execute([$i,$gtin]);
	$f->prepare("INSERT INTO grocy_ai_capture_web_search_reservations(job_id,canonical_gtin,utc_day,actor,retry_generation) VALUES(?,?,date('now'),'test',0)")->execute([$i,$gtin]);
	if ($i===20) checkRequeue(captureWebSearchRequeuePreview($f,12)['remaining_slots']===1,'nineteen reservations leave one slot');
}
$cap=captureWebSearchRequeuePreview($f,12);
checkRequeue($cap['today_reservation_count']===21 && $cap['remaining_slots']===0 && $cap['max_chargeable_calls']===0,'daily ceiling clamps at zero');
rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$before['checksum']));
// Reconstruct yesterday's checksum input by overriding SQLite's clock in a fixture.
$f=fixtureRequeue(); $f->sqliteCreateFunction('date',fn($x)=>'2000-01-01'); $yesterday=captureWebSearchRequeuePreview($f,12); $f->sqliteCreateFunction('date',fn($x)=>'2000-01-02'); rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$yesterday['checksum']));
foreach (['0','7','invalid','-1','100001'] as $limit)
{
	putenv('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT='.$limit);
	if (ctype_digit($limit) && (int)$limit<=100000) checkRequeue(captureWebSearchRequeuePreview(fixtureRequeue(),12)['daily_limit']===(int)$limit,'configured limit');
	else rejectRequeue(fn()=>captureWebSearchRequeuePreview(fixtureRequeue(),12));
}
putenv('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT');
$f=fixtureRequeue(); $f->exec("UPDATE grocy_ai_capture_lines SET status='conflict'"); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'invalid status');
$f=fixtureRequeue(); $f->exec("UPDATE grocy_ai_capture_research_drafts SET outcome='approved'"); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'approved drafts');
$f=fixtureRequeue(); $f->exec("UPDATE grocy_ai_capture_lines SET applied_at=CURRENT_TIMESTAMP"); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'applied lines');
$f=fixtureRequeue(); $f->exec("UPDATE grocy_ai_capture_research_drafts SET suggested_json=json_set(suggested_json,'$.outcome','found')"); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'found result');
$f=fixtureRequeue(); $before=captureWebSearchRequeuePreview($f,12); $f->exec("UPDATE grocy_ai_capture_research_drafts SET suggested_json=json_set(suggested_json,'$.brand','changed')"); rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$before['checksum']));
// Generate distinct checksum-valid GTINs for the apply bound.
$f=fixtureRequeue();
for ($i=2;$i<=21;$i++)
{
	$base=str_pad((string)$i,13,'0',STR_PAD_LEFT); $sum=0; for($n=0;$n<13;$n++) $sum+=(int)$base[$n]*($n%2===0?3:1); $gtin=$base.((10-$sum%10)%10);
	$f->prepare("INSERT INTO grocy_ai_capture_lines(id,trip_id,seq,scanned_barcode,canonical_gtin,status,selected) VALUES(?,12,?,?,?,'unknown',1)")->execute([$i+1,$i+1,$gtin,$gtin]);
	$f->prepare("INSERT INTO grocy_ai_capture_research_jobs(id,canonical_gtin,state,result_revision) VALUES(?,?,'needs_input',1)")->execute([$i,$gtin]);
	$result=json_encode(['contract_version'=>1,'canonical_gtin'=>$gtin,'outcome'=>'miss','name_candidates'=>[],'brand'=>null,'package'=>null,'categories'=>[],'sources'=>[]]);
	$f->prepare("INSERT INTO grocy_ai_capture_research_drafts(job_id,trip_id,line_id,scanned_barcode,outcome,suggested_json) VALUES(?,12,?,?,'needs_input',?)")->execute([$i,$i+1,$gtin,$result]);
}
$overflow=captureWebSearchRequeuePreview($f,12); checkRequeue($overflow['candidate_count']===21,'21 eligible jobs'); rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$overflow['checksum'])); checkRequeue($f->query("SELECT COUNT(*) FROM grocy_ai_capture_research_jobs WHERE state='queued'")->fetchColumn()===0,'bounded refusal writes nothing');
for ($i=23;$i<=101;$i++) $f->prepare("INSERT INTO grocy_ai_capture_lines(trip_id,seq,scanned_barcode,status) VALUES(12,?,?,'unknown')")->execute([$i,'invalid-'.$i]);
rejectRequeue(fn()=>captureWebSearchRequeuePreview($f,12));

$f=fixtureRequeue(); $f->exec("UPDATE grocy_ai_capture_research_drafts SET suggested_json=json_set(suggested_json,'$.error_code',NULL)"); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'v1 miss excludes even null error field');
$f=fixtureRequeue(); $f->exec("UPDATE grocy_ai_capture_research_drafts SET suggested_json=json_remove(suggested_json,'$.brand')"); checkRequeue(captureWebSearchRequeuePreview($f,12)['candidate_count']===0,'malformed miss excluded');
$f=fixtureRequeue(); $before=captureWebSearchRequeuePreview($f,12); $f->exec("INSERT INTO grocy_ai_capture_web_search_reservations(job_id,canonical_gtin,utc_day,actor,retry_generation) VALUES(1,'04006381333931',date('now'),'test',1)"); rejectRequeue(fn()=>captureWebSearchRequeueApply($f,12,$before['checksum']));
$f=fixtureRequeue(); $f->beginTransaction(); try {captureWebSearchRequeueApply($f,12,captureWebSearchRequeuePreview($f,12)['checksum']); throw new RuntimeException('must refuse caller transaction');} catch (LogicException $expected) {} $f->rollBack();
echo "capture web search requeue: PASS\n";
