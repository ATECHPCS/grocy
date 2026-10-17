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
if ($result['catalog']['product_id'] !== $replay['catalog']['product_id']) throw new RuntimeException('Native product approval replay failed');
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
echo "shopping_capture_native: full Grocy schema, collection/Undo, integrity and zero stock writes passed\n";
