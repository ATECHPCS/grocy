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

function shopCheck(bool $ok, string $m):void
{
	if (!$ok)
	{
		throw new RuntimeException($m);
	}
}
function shopReject(callable $f):void
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
function shopFixture():PDO
{
	$d=new PDO('sqlite::memory:');
	$d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$d->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1,parent_product_id INTEGER,qu_id_purchase INTEGER DEFAULT 1,qu_id_stock INTEGER DEFAULT 1); CREATE TABLE product_barcodes(id INTEGER PRIMARY KEY,product_id INTEGER,barcode TEXT,amount REAL); CREATE TABLE shopping_lists(id INTEGER PRIMARY KEY,name TEXT); CREATE TABLE shopping_list(id INTEGER PRIMARY KEY,shopping_list_id INTEGER,product_id INTEGER,amount REAL,qu_id INTEGER,note TEXT,done INTEGER DEFAULT 0); CREATE TABLE quantity_units(id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1); CREATE TABLE quantity_unit_conversions_resolved(product_id INTEGER,from_qu_id INTEGER,to_qu_id INTEGER,factor REAL); CREATE TABLE stock(id INTEGER PRIMARY KEY); CREATE TABLE stock_log(id INTEGER PRIMARY KEY)');
	$d->exec("INSERT INTO products(id,name,parent_product_id) VALUES(1,'Milk',NULL),(2,'Brand milk',1),(3,'Bread',NULL); INSERT INTO product_barcodes(id,product_id,barcode) VALUES(1,2,'4006381333931'),(2,3,'96385074'); INSERT INTO shopping_lists VALUES(1,'Weekly'),(2,'Other'); INSERT INTO quantity_units VALUES(1,'can',1),(2,'kg',1); INSERT INTO shopping_list VALUES(1,1,1,3,1,'note',0),(2,1,3,1,1,NULL,0),(3,2,NULL,1,NULL,'Paper towels',0)");
	new GrocyAI\Services\GrocyAiCaptureService($d);
	$d->exec("INSERT INTO grocy_ai_capture_trips(id,created_by,status,module_version) VALUES(1,'1','open','test'),(2,'2','open','test'),(3,'1','committed','test'),(4,'1','reviewing','test')");
	return $d;
}
shopCheck(class_exists(GrocyAiShoppingCaptureService::class), 'Shopping capture service missing');
$d=shopFixture();
$s=new GrocyAiShoppingCaptureService($d);
$read=$s->Read(1, '1');
shopCheck(array_keys($read)===['contract_version', 'selected_list_id', 'lists', 'items', 'operations', 'can_change_list'], 'read contract');
shopCheck($read['selected_list_id']===null && count($read['lists'])===2, 'empty list selection');
$s->SelectList(1, 1, '1');
$r=$s->Read(1, '1');
shopCheck(array_keys($r['items'][0])===['id', 'list_id', 'product_id', 'name', 'note', 'amount', 'qu_id', 'unit', 'done', 'version', 'collected_amount', 'remaining_amount', 'has_children'], 'item contract');
shopCheck((int)$d->query('SELECT count(*) FROM grocy_ai_capture_lines')->fetchColumn()===0, 'read/select no scan');
for ($n=1;$n<=3;$n++)
{
	$r=$s->Read(1, '1');
	$op=$s->Scan(1, '1', 'scan-' . $n, '4006381333931', 1, $r['items'][0]['version']);
	shopCheck($op['state']==='settled', 'parent scan settles');
}
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn()===1, 'three scans fulfill parent');
$s->Scan(1, '1', 'scan-3', '4006381333931', 1, $r['items'][0]['version']);
shopCheck((float)$d->query('SELECT quantity FROM grocy_ai_capture_lines')->fetchColumn()===3.0, 'idempotent scan');
shopReject(fn () =>$s->Scan(1, '1', 'scan-3', '96385074', 1, null));
$s->Undo(1, '1', 'scan-3');
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn()===0, 'undo reopens own completion');
$s->Undo(1, '1', 'scan-3');
shopCheck((float)$d->query('SELECT quantity FROM grocy_ai_capture_lines')->fetchColumn()===2.0, 'undo idempotent');
shopReject(fn () =>$s->SelectList(1, 2, '1'));
$s->SelectList(1, 2, '1', true);
$row=$s->Read(1, '1')['items'][0];
$s->SetDone(1, '1', 'manual-1', 3, $row['version'], true);
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=3')->fetchColumn()===1, 'free text manual done');
$s->Undo(1, '1', 'manual-1');
shopCheck((int)$d->query('SELECT done FROM shopping_list WHERE id=3')->fetchColumn()===0, 'manual undo');
foreach ([2, 3, 4] as $trip)
{
	shopReject(fn () =>$s->Scan($trip, '1', 'denied-' . $trip, '4006381333931', null, null));
}
shopReject(fn () =>$s->Read(2, '1'));
$s->SelectList(1, 1, '1', true);
$old=$s->Read(1, '1')['items'][0]['version'];
$d->exec('UPDATE shopping_list SET done=1 WHERE id=1; UPDATE shopping_list SET done=0 WHERE id=1');
$conflict=$s->Scan(1, '1', 'aba', '4006381333931', 1, $old);
shopCheck($conflict['state']==='conflicted', 'ABA stale row conflict');
shopCheck((float)$d->query('SELECT quantity FROM grocy_ai_capture_lines WHERE resolved_product_id=2')->fetchColumn()===3.0, 'conflicted collection retains saved scan');
$old=$s->Read(1, '1')['items'][0]['version'];
$d->exec('DELETE FROM shopping_list WHERE id=1; INSERT INTO shopping_list VALUES(1,1,1,3,1,NULL,0)');
shopCheck($s->Read(1, '1')['items'][0]['version']!==$old, 'delete recreate version tombstone');
shopCheck($s->Read(1, '1')['items'][0]['collected_amount'] === 0.0, 'recreated entry must not inherit deleted-entry progress');
// Mismatch and manual unit confirmation.
$row=$s->Read(1, '1')['items'][0];
$wrong=$s->Scan(1, '1', 'wrong', '96385074', 1, $row['version']);
shopCheck($wrong['state']==='conflicted'&&$wrong['error_code']==='product_mismatch', 'known owner protected');
$d->exec('UPDATE shopping_list SET qu_id=2 WHERE id=1');
$row=$s->Read(1, '1')['items'][0];
$pending=$s->Scan(1, '1', 'units', '4006381333931', 1, $row['version']);
shopCheck($pending['needs_amount'], 'missing conversion requires amount');
shopReject(fn () =>$s->Settle(1, '1', 'units', 1, $row['version'], INF));
$s->Settle(1, '1', 'units', 1, $row['version'], 0.5);
// Unknown targeted scans have local drafts but cannot reserve external research.
$row=$s->Read(1, '1')['items'][0];
$unknown=$s->Scan(1, '1', 'unknown', '012345678905', 1, $row['version']);
shopCheck($unknown['state']==='awaiting_curation', 'unknown curation pending');
shopCheck((int)$d->query("SELECT count(*) FROM grocy_ai_capture_research_jobs WHERE canonical_gtin='00012345678905' AND state IN ('queued','leased','retryable_failure')")->fetchColumn()===0, 'targeted UPC never queues provider');
// Exact preference and ambiguous rows.
$d->exec('INSERT INTO shopping_list VALUES(10,1,2,10,1,NULL,0)');
$auto=$s->Scan(1, '1', 'exact', '4006381333931', null, null);
shopCheck($auto['row_id']===10, 'exact match precedes parent');
$d->exec('INSERT INTO shopping_list VALUES(11,1,2,10,1,NULL,0)');
$amb=$s->Scan(1, '1', 'ambiguous', '4006381333931', null, null);
shopCheck($amb['state']==='pending_selection', 'ambiguous candidates require choice');
// Native changes prevent stale Undo; stock remains entirely untouched.
$d->exec('UPDATE shopping_list SET note=\'another shopper\' WHERE id=10');
shopReject(fn () =>$s->Undo(1, '1', 'exact'));
shopCheck((int)$d->query('SELECT count(*) FROM stock')->fetchColumn()===0&&(int)$d->query('SELECT count(*) FROM stock_log')->fetchColumn()===0, 'no stock writes');
// Later scans can fulfill a row after the original completion operation is undone.
$extra=shopFixture();
$es=new GrocyAiShoppingCaptureService($extra);
$es->SelectList(1, 1, '1');
for ($i=1;$i<=4;$i++)
{
	$rr=array_values(array_filter($es->Read(1, '1')['items'], fn ($row) =>$row['id']===1))[0];
	$es->Scan(1, '1', 'later-' . $i, '4006381333931', 1, $rr['version']);
}
$es->Undo(1, '1', 'later-3');
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn()===1, 'later scans still fulfill');
$es->Undo(1, '1', 'later-4');
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn()===0, 'remaining contribution undo reopens own completion');
// Existing shared done is never owned by this trip.
$extra->exec('UPDATE shopping_list SET done=1 WHERE id=2');
$rr=$es->Read(1, '1')['items'][1];
$es->Scan(1, '1', 'predone', '96385074', 2, $rr['version']);
$es->Undo(1, '1', 'predone');
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=2')->fetchColumn()===1, 'preserve pre-done shared row');
// Barcode package amounts are stock-unit amounts, not one purchase unit.
$extra->exec('UPDATE product_barcodes SET amount=6 WHERE product_id=2; UPDATE shopping_list SET amount=6,done=0 WHERE id=1');
$rr=array_values(array_filter($es->Read(1,'1')['items'],fn ($row) =>$row['id']===1))[0];
$es->Scan(1,'1','six-pack','4006381333931',1,$rr['version']);
shopCheck((int)$extra->query('SELECT done FROM shopping_list WHERE id=1')->fetchColumn()===1,'barcode stock-unit multipack fulfills required quantity');
$extra->exec("INSERT INTO grocy_ai_capture_trip_cancellations(trip_id,actor) VALUES(1,'1')");
shopReject(fn () =>$es->Scan(1,'1','cancelled','96385074',null,null));
$researchDb=shopFixture();$rs=new GrocyAiShoppingCaptureService($researchDb);$rs->SelectList(1,1,'1');
$rr=$rs->Read(1,'1')['items'][0];$rs->Scan(1,'1','local-research','012345678905',1,$rr['version']);
$capture=new GrocyAI\Services\GrocyAiCaptureService($researchDb);$newTrip=$capture->StartTrip('1');$capture->ScanIntoTrip((int)$newTrip['id'],'012345678905','1');
shopCheck(count((new GrocyAI\Services\GrocyAiCaptureResearchService($researchDb))->ClaimJobs(1,'fixture-worker'))===1,'normal untargeted capture can research a local-only UPC');
echo "shopping_capture: all checks passed\n";
