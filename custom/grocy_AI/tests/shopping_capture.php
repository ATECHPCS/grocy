<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../packages/autoload.php';
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService'] as $f)
{
	require_once __DIR__ . '/../src/' . $f . '.php';
}
foreach (['GrocyAiShoppingCaptureMigration', 'GrocyAiShoppingCaptureService'] as $f)
{
	if (is_file(__DIR__ . '/../src/' . $f . '.php'))
	{
		require_once __DIR__ . '/../src/' . $f . '.php';
	}
}
use GrocyAI\Services\GrocyAiShoppingCaptureService;

function shopCheck(bool $ok, string $m): void
{
	if (!$ok)
	{
		throw new RuntimeException($m);
	}
}
function shopReject(callable $f): void
{
	try
	{
		$f();
	}
	catch (InvalidArgumentException|RuntimeException|DomainException $e)
	{
		return;
	}throw new RuntimeException('Expected rejection');
}
function shopFixture(?PDO $d = null): PDO
{
	$d ??= new PDO('sqlite::memory:');
	$d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$d->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1,parent_product_id INTEGER,qu_id_purchase INTEGER DEFAULT 1,qu_id_stock INTEGER DEFAULT 1); CREATE TABLE product_barcodes(id INTEGER PRIMARY KEY,product_id INTEGER,barcode TEXT,amount REAL); CREATE TABLE shopping_lists(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE shopping_list(id INTEGER PRIMARY KEY,shopping_list_id INTEGER,product_id INTEGER,amount REAL,qu_id INTEGER,note TEXT,done INTEGER DEFAULT 0); CREATE TABLE quantity_units(id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1); CREATE TABLE quantity_unit_conversions_resolved(product_id INTEGER,from_qu_id INTEGER,to_qu_id INTEGER,factor REAL); CREATE TABLE stock(id INTEGER PRIMARY KEY); CREATE TABLE stock_log(id INTEGER PRIMARY KEY)');
	$d->exec("INSERT INTO products(id,name,parent_product_id) VALUES(1,'Milk',NULL),(2,'Brand milk',1),(3,'Bread',NULL); INSERT INTO product_barcodes(id,product_id,barcode) VALUES(1,2,'4006381333931'),(2,3,'96385074'); INSERT INTO shopping_lists VALUES(1,'Weekly'),(2,'Other'); INSERT INTO quantity_units VALUES(1,'can',1),(2,'kg',1); INSERT INTO shopping_list VALUES(1,1,1,3,1,'note',0),(2,1,3,1,1,NULL,0),(3,2,NULL,1,NULL,'Paper towels',0)");
	new GrocyAI\Services\GrocyAiCaptureService($d);
	$d->exec("INSERT INTO grocy_ai_capture_trips(id,created_by,status,module_version) VALUES(1,'1','open','test'),(2,'2','open','test'),(3,'1','committed','test'),(4,'1','reviewing','test')");
	return $d;
}
shopCheck(class_exists(GrocyAiShoppingCaptureService::class), 'Shopping capture service missing');
$d = shopFixture();
$s = new GrocyAiShoppingCaptureService($d);
$read = $s->Read(1, '1');
shopCheck(array_keys($read) === ['contract_version', 'selected_list_id', 'lists', 'items', 'operations', 'can_change_list'], 'read contract');
shopCheck($read['selected_list_id'] === null && count($read['lists']) === 2, 'empty list selection');
$s->SelectList(1, 1, '1');
$r = $s->Read(1, '1');
shopCheck(array_keys($r['items'][0]) === ['id', 'list_id', 'product_id', 'name', 'note', 'amount', 'qu_id', 'unit', 'done', 'version', 'collected_amount', 'remaining_amount', 'has_children'], 'item contract');
shopCheck((int)$d->query('SELECT count(*) FROM grocy_ai_capture_lines')->fetchColumn() === 0, 'read/select no scan');
for ($n = 1;$n <= 3;$n++)
{
	$r = $s->Read(1, '1');
	$op = $s->Scan(1, '1', 'scan-' . $n, '4006381333931', 1, $r['items'][0]['version']);
	shopCheck($op['state'] === 'settled', 'parent scan settles');
}
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn() === 1, 'three scans fulfill parent');
$s->Scan(1, '1', 'scan-3', '4006381333931', 1, $r['items'][0]['version']);
shopCheck((float)$d->query('SELECT quantity FROM grocy_ai_capture_lines')->fetchColumn() === 3.0, 'idempotent scan');
shopReject(fn () => $s->Scan(1, '1', 'scan-3', '96385074', 1, null));
$s->Undo(1, '1', 'scan-3');
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn() === 0, 'undo reopens own completion');
$s->Undo(1, '1', 'scan-3');
shopCheck((float)$d->query('SELECT quantity FROM grocy_ai_capture_lines')->fetchColumn() === 2.0, 'undo idempotent');
shopReject(fn () => $s->SelectList(1, 2, '1'));
$s->SelectList(1, 2, '1', true);
$row = $s->Read(1, '1')['items'][0];
$s->SetDone(1, '1', 'manual-1', 3, $row['version'], true);
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=3')->fetchColumn() === 1, 'free text manual done');
$s->Undo(1, '1', 'manual-1');
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=3')->fetchColumn() === 0, 'manual undo');
foreach ([2, 3, 4] as $trip)
{
	shopReject(fn () => $s->Scan($trip, '1', 'denied-' . $trip, '4006381333931', null, null));
}
shopReject(fn () => $s->Read(2, '1'));
$s->SelectList(1, 1, '1', true);
$old = $s->Read(1, '1')['items'][0]['version'];
$d->exec('UPDATE shopping_list SET done=1 WHERE id=1; UPDATE shopping_list SET done=0 WHERE id=1');
$conflict = $s->Scan(1, '1', 'aba', '4006381333931', 1, $old);
shopCheck($conflict['state'] === 'conflicted', 'ABA stale row conflict');
shopCheck((float)$d->query('SELECT quantity FROM grocy_ai_capture_lines WHERE resolved_product_id=2')->fetchColumn() === 3.0, 'conflicted collection retains saved scan');
$old = $s->Read(1, '1')['items'][0]['version'];
$d->exec('DELETE FROM shopping_list WHERE id=1; INSERT INTO shopping_list VALUES(1,1,1,3,1,NULL,0)');
shopCheck($s->Read(1, '1')['items'][0]['version'] !== $old, 'delete recreate version tombstone');
shopCheck($s->Read(1, '1')['items'][0]['collected_amount'] === 0.0, 'recreated entry must not inherit deleted-entry progress');
// Mismatch and manual unit confirmation.
$row = $s->Read(1, '1')['items'][0];
$wrong = $s->Scan(1, '1', 'wrong', '96385074', 1, $row['version']);
shopCheck($wrong['state'] === 'conflicted' && $wrong['error_code'] === 'product_mismatch', 'known owner protected');
$d->exec('UPDATE shopping_list SET qu_id=2 WHERE id=1');
$row = $s->Read(1, '1')['items'][0];
$pending = $s->Scan(1, '1', 'units', '4006381333931', 1, $row['version']);
shopCheck($pending['needs_amount'], 'missing conversion requires amount');
shopReject(fn () => $s->Settle(1, '1', 'units', 1, $row['version'], INF));
$s->Settle(1, '1', 'units', 1, $row['version'], 0.5);
// Unknown targeted scans have local drafts but cannot reserve external research.
$row = $s->Read(1, '1')['items'][0];
$unknown = $s->Scan(1, '1', 'unknown', '012345678905', 1, $row['version']);
shopCheck($unknown['state'] === 'awaiting_curation', 'unknown curation pending');
shopCheck((int)$d->query("SELECT count(*) FROM grocy_ai_capture_research_jobs WHERE canonical_gtin='00012345678905' AND state IN ('queued','leased','retryable_failure')")->fetchColumn() === 0, 'targeted UPC never queues provider');
// Exact preference and ambiguous rows.
$d->exec('INSERT INTO shopping_list VALUES(10,1,2,10,1,NULL,0)');
$auto = $s->Scan(1, '1', 'exact', '4006381333931', null, null);
shopCheck($auto['row_id'] === 10, 'exact match precedes parent');
$d->exec('INSERT INTO shopping_list VALUES(11,1,2,10,1,NULL,0)');
$amb = $s->Scan(1, '1', 'ambiguous', '4006381333931', null, null);
shopCheck($amb['state'] === 'pending_selection', 'ambiguous candidates require choice');
// Native note changes permit capture-only Undo when no shared reversal is needed.
$d->exec('UPDATE shopping_list SET note=\'another shopper\' WHERE id=10');
$s->Undo(1, '1', 'exact');
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=10')->fetchColumn() === 0, 'capture-only Undo preserves native row');
shopCheck((int)$d->query('SELECT count(*) FROM stock')->fetchColumn() === 0 && (int)$d->query('SELECT count(*) FROM stock_log')->fetchColumn() === 0, 'no stock writes');
// Later scans can fulfill a row after the original completion operation is undone.
$extra = shopFixture();
$es = new GrocyAiShoppingCaptureService($extra);
$es->SelectList(1, 1, '1');
for ($i = 1;$i <= 4;$i++)
{
	$rr = array_values(array_filter($es->Read(1, '1')['items'], fn ($row) => $row['id'] === 1))[0];
	$es->Scan(1, '1', 'later-' . $i, '4006381333931', 1, $rr['version']);
}
$es->Undo(1, '1', 'later-3');
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn() === 1, 'later scans still fulfill');
$es->Undo(1, '1', 'later-4');
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn() === 0, 'remaining contribution undo reopens own completion');
// Existing shared done is never owned by this trip.
$extra->exec('UPDATE shopping_list SET done=1 WHERE id=2');
$rr = $es->Read(1, '1')['items'][1];
$es->Scan(1, '1', 'predone', '96385074', 2, $rr['version']);
$es->Undo(1, '1', 'predone');
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=2')->fetchColumn() === 1, 'preserve pre-done shared row');
// Barcode package amounts are stock-unit amounts, not one purchase unit.
$extra->exec('UPDATE product_barcodes SET amount=6 WHERE product_id=2; UPDATE shopping_list SET amount=6,done=0 WHERE id=1');
$rr = array_values(array_filter($es->Read(1, '1')['items'], fn ($row) => $row['id'] === 1))[0];
$es->Scan(1, '1', 'six-pack', '4006381333931', 1, $rr['version']);
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn() === 1, 'barcode stock-unit multipack fulfills required quantity');
$extra->exec("INSERT INTO grocy_ai_capture_trip_cancellations(trip_id,actor) VALUES(1,'1')");
shopReject(fn () => $es->Scan(1, '1', 'cancelled', '96385074', null, null));
$researchDb = shopFixture();
$rs = new GrocyAiShoppingCaptureService($researchDb);
$rs->SelectList(1, 1, '1');
$rr = $rs->Read(1, '1')['items'][0];
$rs->Scan(1, '1', 'local-research', '012345678905', 1, $rr['version']);
$capture = new GrocyAI\Services\GrocyAiCaptureService($researchDb);
$newTrip = $capture->StartTrip('1');
$capture->ScanIntoTrip((int)$newTrip['id'], '012345678905', '1');
shopCheck(count((new GrocyAI\Services\GrocyAiCaptureResearchService($researchDb))->ClaimJobs(1, 'fixture-worker')) === 1, 'normal untargeted capture can research a local-only UPC');


// Review regressions: manual completion must not override independently fulfilled scans.
foreach ([2, 3] as $count)
{
	$db = shopFixture();
	$svc = new GrocyAiShoppingCaptureService($db);
	$svc->SelectList(1, 1, '1');
	$row = array_values(array_filter($svc->Read(1, '1')['items'], fn ($r) => $r['id'] === 1))[0];
	$svc->SetDone(1, '1', 'manual-first', 1, $row['version'], true);
	for ($i = 0;$i < $count;$i++)
	{
		$row = array_values(array_filter($svc->Read(1, '1')['items'], fn ($r) => $r['id'] === 1))[0];
		$svc->Scan(1, '1', 'after-' . $i, '4006381333931', 1, $row['version']);
	}
	$svc->Undo(1, '1', 'manual-first');
	$svc->Undo(1, '1', 'manual-first');
	shopCheck((bool)$db->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn() === ($count === 3), 'manual Undo respects later scan fulfillment');
	if ($count === 3)
	{
		$svc->Undo(1, '1', 'after-2');
		shopCheck(!(bool)$db->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn(), 'undo later scan reopens transferred manual completion');
	}
}
$db = shopFixture();
$svc = new GrocyAiShoppingCaptureService($db);
$svc->SelectList(1, 1, '1');
$db->exec('UPDATE shopping_list SET amount=1e999 WHERE id=1');
$row = array_values(array_filter($svc->Read(1, '1')['items'], fn ($r) => $r['id'] === 1))[0];
shopCheck($row['amount'] === null, 'nonfinite target remains readable with confirmation');
$op = $svc->Scan(1, '1', 'invalid-target', '4006381333931', 1, $row['version']);
shopCheck($op['needs_amount'], 'invalid target requires explicit quantity');
$svc->Settle(1, '1', 'invalid-target', 1, $row['version'], 1);
shopCheck((bool)$db->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn(), 'explicit invalid-target confirmation');

echo "shopping_capture: all checks passed\n";

$follow=shopFixture();$fs=new GrocyAI\Services\GrocyAiShoppingCaptureService($follow);$fs->SelectList(1,1,'1');
$rr=$fs->Read(1,'1')['items'][0];
$mis=$fs->Scan(1,'1','follow-mismatch','96385074',1,$rr['version']);
shopCheck($mis['state']==='conflicted' && $mis['error_code']==='product_mismatch','mismatch retains accepted scan');
shopCheck(is_string($mis['product_name']) && $mis['product_name']!=='','actual product name supplied');
shopCheck(!in_array(1,$mis['compatible_row_ids'],true),'mismatched target excluded from compatible choices');
$normal=$fs->KeepCapture(1,'1','follow-mismatch');
shopCheck($normal['state']==='captured' && $normal['row_id']===null,'keep normal capture detaches list association');
$fs->KeepCapture(1,'1','follow-mismatch');
shopReject(fn()=>$fs->Settle(1,'1','follow-mismatch',1,$rr['version']));
shopCheck((float)$follow->query('SELECT quantity FROM grocy_ai_capture_lines')->fetchColumn()===1.0,'detach never scans again');
$fs->Undo(1,'1','follow-mismatch');
shopCheck((int)$follow->query('SELECT count(*) FROM grocy_ai_capture_lines')->fetchColumn()===0,'normal capture can Undo its scan');
echo "shopping_capture: mismatch and detach passed\n";

// Existing deployed manual records have no shape: current versions still prove ownership.
foreach ([false,true] as $nullTarget) {
 $old=shopFixture();$os=new GrocyAiShoppingCaptureService($old);$os->SelectList(1,1,'1');
 if ($nullTarget) $old->exec('UPDATE shopping_list SET qu_id=2 WHERE id=1');
 $row=fn()=>array_values(array_filter($os->Read(1,'1')['items'],fn($r)=>$r['id']===1))[0];
 if ($nullTarget) { $r=$row();$os->Scan(1,'1','old-scan','4006381333931',1,$r['version']);$os->Settle(1,'1','old-scan',1,$r['version'],3.0); }
 $os->SetDone(1,'1','old-manual',1,$row()['version'],true);
 $old->exec("UPDATE grocy_ai_shopping_operations SET row_shape=NULL WHERE operation_id='old-manual'");
 if ($nullTarget) {
  $os->Undo(1,'1','old-scan');shopCheck((bool)$old->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn(),'legacy null manual confirmation retains completion');
  $os->Undo(1,'1','old-manual');
 } else {
  for($i=1;$i<=3;$i++) $os->Scan(1,'1','old-later-'.$i,'4006381333931',1,$row()['version']);
  $os->Undo(1,'1','old-manual');$os->Undo(1,'1','old-later-3');
 }
 shopCheck(!(bool)$old->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn(),'legacy manual ownership transfers safely');
}
$old=shopFixture();$os=new GrocyAiShoppingCaptureService($old);$os->SelectList(1,1,'1');$r=$os->Read(1,'1')['items'][0];
$os->Scan(1,'1','unit-change','4006381333931',1,$r['version']);$old->exec('UPDATE shopping_list SET qu_id=2 WHERE id=1');
shopCheck($os->Undo(1,'1','unit-change')['state']==='undone','unit change allows capture-only Undo');
// A missing trigger is repaired without taking a write lock on ordinary reads.
$old->exec('DROP TRIGGER grocy_ai_shopping_version_insert;INSERT INTO shopping_list VALUES(30,1,1,1,1,NULL,0)');
new GrocyAiShoppingCaptureService($old);
shopCheck((int)$old->query('SELECT count(*) FROM grocy_ai_shopping_versions WHERE row_id=30')->fetchColumn()===1,'missing native trigger repairs version backfill');
$race=shopFixture();$rs=new GrocyAiShoppingCaptureService($race);$rs->SelectList(1,1,'1');$row=$rs->Read(1,'1')['items'][0];$rs->Scan(1,'1','race-mismatch','96385074',1,$row['version']);
$race->exec('DROP TRIGGER grocy_ai_shopping_version_insert;INSERT INTO shopping_list VALUES(30,1,1,1,1,NULL,0)');
shopCheck(count($rs->Read(1,'1')['items'])===2,'one unversioned candidate never breaks operation DTO or whole panel');

class ShoppingCountingPdo extends PDO {
 public int $prepares=0;
 public function prepare(string $query,array $options=[]): PDOStatement|false { $this->prepares++;return parent::prepare($query,$options); }
}
$counting=new ShoppingCountingPdo('sqlite::memory:');shopFixture($counting);$cs=new GrocyAiShoppingCaptureService($counting);$cs->SelectList(1,1,'1');
for($i=10;$i<30;$i++) $counting->exec("INSERT INTO shopping_list VALUES($i,1,1,100,1,NULL,0)");
for($i=0;$i<20;$i++) $cs->Scan(1,'1','perf-'.$i,'96385074',1,'1');
$counting->prepares=0;$cs->Read(1,'1');
shopCheck($counting->prepares<200,'poll queries must not grow as operations times list rows');
$repair=shopFixture();$ps=new GrocyAiShoppingCaptureService($repair);$ps->SelectList(1,1,'1');$pr=$ps->Read(1,'1')['items'][0];$ps->Scan(1,'1','before-rebuild','4006381333931',1,$pr['version']);
$repair->exec('DROP TRIGGER grocy_ai_shopping_version_insert;DELETE FROM shopping_list WHERE id=1;INSERT INTO shopping_list VALUES(1,1,1,3,1,NULL,0)');
$ps=new GrocyAiShoppingCaptureService($repair);$pr=array_values(array_filter($ps->Read(1,'1')['items'],fn($r)=>$r['id']===1))[0];
shopCheck($pr['collected_amount']===0.0,'trigger repair invalidates unobservable row incarnations');
$manual=shopFixture();$ms=new GrocyAiShoppingCaptureService($manual);$ms->SelectList(1,2,'1');$mr=$ms->Read(1,'1')['items'][0];$mo=$ms->SetDone(1,'1','manual-label',3,$mr['version'],true);
shopCheck($mo['kind']==='manual' && $mo['row_name']==='Paper towels','manual operation DTO identifies action and row');
