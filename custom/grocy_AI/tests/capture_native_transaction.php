<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../packages/autoload.php';

define('GROCY_MODE', 'production');

use Grocy\Services\DatabaseService;
use Grocy\Services\StockService;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, product_id INTEGER, amount REAL, stock_id TEXT)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY, stock_id TEXT)');
$db->exec("INSERT INTO stock VALUES (1, 1, 2, 'a'), (2, 1, 3, 'b')");
$db->exec("INSERT INTO stock_log VALUES (1, 'a'), (2, 'b')");
$db->exec('CREATE VIEW stock_splits AS SELECT product_id, SUM(amount) total_amount, MIN(stock_id) stock_id_to_keep, MAX(id) id_to_keep, GROUP_CONCAT(id) id_group, GROUP_CONCAT(stock_id) stock_id_group, MIN(id) id FROM stock GROUP BY product_id HAVING COUNT(*) > 1');

$connection = new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw');
$connection->setValue(null, $db);

$db->exec('BEGIN IMMEDIATE');
try
{
	StockService::GetInstance()->CompactStockEntries(1);
	$rows = $db->query('SELECT amount, stock_id FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	if (count($rows) !== 1 || (float)$rows[0]['amount'] !== 5.0 || $rows[0]['stock_id'] !== 'a' || !$db->inTransaction())
	{
		throw new RuntimeException('Compaction did not preserve the caller transaction and combined stock');
	}
	$db->exec('ROLLBACK');
	if ((int)$db->query('SELECT COUNT(*) FROM stock')->fetchColumn() !== 2)
	{
		throw new RuntimeException('Caller rollback did not restore both stock rows');
	}
	StockService::GetInstance()->CompactStockEntries(1);
	if ($db->inTransaction() || (int)$db->query('SELECT COUNT(*) FROM stock')->fetchColumn() !== 1)
	{
		throw new RuntimeException('Standalone compaction did not commit its own transaction');
	}
}
catch (Throwable $exception)
{
	if ($db->inTransaction())
	{
		$db->exec('ROLLBACK');
	}
	fwrite(STDERR, 'FAIL: native compaction under purchase transaction: ' . $exception->getMessage() . PHP_EOL);
	exit(1);
}

fwrite(STDOUT, "Native compaction respects the caller transaction\n");
