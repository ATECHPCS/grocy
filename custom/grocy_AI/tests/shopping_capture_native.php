<?php

declare(strict_types=1);
$path = sys_get_temp_dir() . '/grocy-shopping-native-' . bin2hex(random_bytes(8));
mkdir($path, 0700);
register_shutdown_function(static function () use ($path): void
{
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $f)
	{
		if ($f->isDir())
		{
			rmdir($f->getPathname());
		}
		else
		{
			unlink($f->getPathname());
		}
	}rmdir($path);
});
define('GROCY_MODE', 'production');
define('GROCY_DATAPATH', $path);
define('GROCY_USER_ID', 1);
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService', 'GrocyAiShoppingCaptureMigration', 'GrocyAiShoppingCaptureService', 'GrocyAiTaxonomyMigration', 'GrocyAiTaxonomyService', 'GrocyAiCaptureProductService'] as $f)
{
	require_once __DIR__ . '/../src/' . $f . '.php';
}
foreach (array_keys(getenv()) as $key)
{
	if (str_starts_with($key, 'GROCY_'))
	{
		putenv($key);
	}
}
define('GROCY_AI_SERVICE_URL', '');
define('GROCY_AI_SERVICE_API_KEY', '');
require dirname(__DIR__, 3) . '/config-dist.php';
(new Grocy\Services\DatabaseMigrationService())->MigrateDatabase();
$database = Grocy\Services\DatabaseService::GetInstance();
$database->GetDbConnection();
$db = $database->GetDbConnectionRaw();
$db->exec("INSERT OR IGNORE INTO locations(id,name,active) VALUES(1,'Fixture shelf',1); UPDATE locations SET active=1 WHERE id=1; INSERT INTO quantity_units(id,name,active) VALUES(901,'Fixture can',1);INSERT INTO products(id,name,location_id,qu_id_purchase,qu_id_stock,qu_id_consume,qu_id_price,parent_product_id) VALUES(10001,'Generic fixture milk',1,901,901,901,901,NULL),(10002,'Branded fixture milk',1,901,901,901,901,10001);INSERT INTO product_barcodes(product_id,barcode) VALUES(10002,'4006381333931');INSERT INTO shopping_lists(id,name) VALUES(770,'Fixture list');INSERT INTO shopping_list(id,shopping_list_id,product_id,amount,qu_id) VALUES(770,770,10001,3,901)");
$before = [];
foreach (['stock', 'stock_log'] as $t)
{
	$before[$t] = $db->query('SELECT * FROM ' . $t)->fetchAll(PDO::FETCH_ASSOC);
}
$capture = new GrocyAI\Services\GrocyAiCaptureService($db);
$trip = $capture->StartTrip('1');
$id = (int)$trip['id'];
$s = new GrocyAI\Services\GrocyAiShoppingCaptureService($db);
$s->SelectList($id, 770, '1');
for ($i = 1;$i <= 3;$i++)
{
	$row = $s->Read($id, '1')['items'][0];
	$op = $s->Scan($id, '1', 'native-' . $i, '4006381333931', 770, $row['version']);
	if ($op['state'] !== 'settled')
	{
		throw new RuntimeException('Native scan failed');
	}
}
if ((int)$db->query('SELECT done FROM shopping_list WHERE id=770')->fetchColumn() !== 1)
{
	throw new RuntimeException('Native completion failed');
}
$s->Undo($id, '1', 'native-3');
if ((int)$db->query('SELECT done FROM shopping_list WHERE id=770')->fetchColumn() !== 0)
{
	throw new RuntimeException('Native Undo failed');
}
$row = $s->Read($id, '1')['items'][0];
$unknown = $s->Scan($id, '1', 'native-create', '036000291452', 770, $row['version']);
$options = $s->CurationOptions($id, '1', 'native-create');
$fields = ['name' => 'New fixture branded milk', 'location_id' => 1, 'qu_id_purchase' => 901, 'qu_id_stock' => 901, 'parent_mode' => 'existing', 'parent_product_id' => 10001];
$result = $s->Curate($id, '1', 'native-create', $row['version'], 'create', $options['revision'], null, $fields);
$s->Settle($id, '1', 'native-create', 770, $row['version']);
$replay = $s->Curate($id, '1', 'native-create', $row['version'], 'create', $options['revision'], null, $fields);
if ($result['catalog']['product_id'] !== $replay['catalog']['product_id'])
{
	throw new RuntimeException('Native product approval replay failed');
}
foreach (['stock', 'stock_log'] as $t)
{
	if ($before[$t] !== $db->query('SELECT * FROM ' . $t)->fetchAll(PDO::FETCH_ASSOC))
	{
		throw new RuntimeException('Native stock changed');
	}
}
if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok')
{
	throw new RuntimeException('Native DB integrity failed');
}


$db->exec("INSERT INTO quantity_units(id,name,active) VALUES(902,'Fixture six-pack',1); INSERT INTO quantity_unit_conversions(from_qu_id,to_qu_id,factor) VALUES(901,902,1.0/6); UPDATE shopping_list SET amount=6,qu_id=902,done=0 WHERE id=770; UPDATE product_barcodes SET amount=6 WHERE product_id=10002");
$row = $s->Read($id, '1')['items'][0];
if (abs($row['amount'] - 1) > 1e-9)
{
	throw new RuntimeException('Native stock target must display one six-pack');
}
$s->Scan($id, '1', 'native-six-pack', '4006381333931', 770, $row['version']);
if (!(bool)$db->query('SELECT done FROM shopping_list WHERE id=770')->fetchColumn())
{
	throw new RuntimeException('One native six-pack completes list');
}
$s->Undo($id, '1', 'native-six-pack');
if ((bool)$db->query('SELECT done FROM shopping_list WHERE id=770')->fetchColumn())
{
	throw new RuntimeException('Six-pack Undo uses converted threshold');
}

echo "shopping_capture_native: full Grocy schema, collection/Undo, integrity and zero stock writes passed\n";

// Requested amount is a target, not the identity of this trip's contributions.
$db->exec("UPDATE shopping_list SET amount=3,qu_id=901,done=0 WHERE id=770;UPDATE product_barcodes SET amount=1 WHERE product_id=10002");
$t2 = (int)$capture->StartTrip('1')['id'];
$s->SelectList($t2,770,'1');
foreach (['edit-a','edit-b'] as $opid) { $r=$s->Read($t2,'1')['items'][0]; $s->Scan($t2,'1',$opid,'4006381333931',770,$r['version']); }
$db->exec('UPDATE shopping_list SET amount=4 WHERE id=770');
$r=$s->Read($t2,'1')['items'][0];
if ($r['collected_amount'] !== 2.0 || $r['remaining_amount'] !== 2.0) throw new RuntimeException('Amount edit must retain two collected and two remaining');
$s->Undo($t2,'1','edit-b');
if ($s->Read($t2,'1')['items'][0]['collected_amount'] !== 1.0) throw new RuntimeException('Amount edit must permit capture-only Undo');
$db->exec("UPDATE shopping_list SET note='Native note' WHERE id=770");
$s->Undo($t2,'1','edit-a');
if ($s->Read($t2,'1')['items'][0]['collected_amount'] !== 0.0) throw new RuntimeException('Note edit must permit capture-only Undo');

// No verified conversion: remaining explicit confirmations still fulfill the row.
$db->exec("INSERT INTO quantity_units(id,name,active) VALUES(903,'Fixture unknown unit',1);UPDATE shopping_list SET amount=2,qu_id=903,done=0 WHERE id=770");
$t3=(int)$capture->StartTrip('1')['id']; $s->SelectList($t3,770,'1');
foreach (['null-a'=>2,'null-b'=>1] as $opid=>$amount) { $r=$s->Read($t3,'1')['items'][0];$s->Scan($t3,'1',$opid,'4006381333931',770,$r['version']);$s->Settle($t3,'1',$opid,770,$r['version'],(float)$amount); }
$s->Undo($t3,'1','null-b');
if (!(bool)$db->query('SELECT done FROM shopping_list WHERE id=770')->fetchColumn()) throw new RuntimeException('Null target Undo must retain remaining confirmation');
$s->Undo($t3,'1','null-a');
if ((bool)$db->query('SELECT done FROM shopping_list WHERE id=770')->fetchColumn()) throw new RuntimeException('Last null confirmation Undo must reopen owned row');

$r=$s->Read($t3,'1')['items'][0];$s->Scan($t3,'1','deleted-scan','4006381333931',770,$r['version']);
$db->exec('DELETE FROM shopping_list WHERE id=770');
$s->Undo($t3,'1','deleted-scan');
if ($s->Operation($t3,'1','deleted-scan')['state'] !== 'undone') throw new RuntimeException('Deleted row must permit capture-only Undo');
echo "shopping_capture_native: follow-up amount edits, null targets, note/deletion Undo passed\n";

// A native edit after completion must never be overwritten by a stale reversal.
$db->exec("INSERT INTO shopping_list(id,shopping_list_id,product_id,amount,qu_id) VALUES(771,770,10001,1,901)");
$t4=(int)$capture->StartTrip('1')['id'];$s->SelectList($t4,770,'1');$r=$s->Read($t4,'1')['items'][0];
$s->Scan($t4,'1','stale-done','4006381333931',771,$r['version']);
$db->exec("UPDATE shopping_list SET note='Another shopper' WHERE id=771");
$rejected=false;try {$s->Undo($t4,'1','stale-done');} catch(RuntimeException $e) {$rejected=true;}
if (!$rejected || !(bool)$db->query('SELECT done FROM shopping_list WHERE id=771')->fetchColumn()) throw new RuntimeException('Stale shared completion reversal must be rejected');
// A reader must not request a write lock once migrations are installed.
$file=$db->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
$db->exec('PRAGMA busy_timeout=0');
$writer=new PDO('sqlite:'.$file);$writer->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$writer->exec('BEGIN IMMEDIATE');
try { (new GrocyAI\Services\GrocyAiShoppingCaptureService($db))->Read($t4,'1'); }
finally { $writer->exec('ROLLBACK'); }
echo "shopping_capture_native: stale reversal and read-under-writer passed\n";


$db->exec('INSERT INTO shopping_list(id,shopping_list_id,product_id,amount,qu_id) VALUES(772,770,10001,2,903)');
$t5=(int)$capture->StartTrip('1')['id'];$s->SelectList($t5,770,'1');
$r=array_values(array_filter($s->Read($t5,'1')['items'],fn($r)=>$r['id']===772))[0];
$s->Scan($t5,'1','null-scan-owner','4006381333931',772,$r['version']);$s->Settle($t5,'1','null-scan-owner',772,$r['version'],2.0);
$r=array_values(array_filter($s->Read($t5,'1')['items'],fn($r)=>$r['id']===772))[0];
$s->SetDone($t5,'1','null-manual-confirm',772,$r['version'],true);
$s->Undo($t5,'1','null-scan-owner');
if (!(bool)$db->query('SELECT done FROM shopping_list WHERE id=772')->fetchColumn()) throw new RuntimeException('Manual null confirmation must retain fulfillment');
$s->Undo($t5,'1','null-manual-confirm');
if ((bool)$db->query('SELECT done FROM shopping_list WHERE id=772')->fetchColumn()) throw new RuntimeException('Last manual null confirmation must reverse owned completion');

foreach (['stock','stock_log'] as $table) {
 if ($before[$table] !== $db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)) throw new RuntimeException('Follow-up operations changed stock');
}
if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Follow-up database integrity failed');
echo "shopping_capture_native: all follow-up regressions and stock isolation passed\n";
