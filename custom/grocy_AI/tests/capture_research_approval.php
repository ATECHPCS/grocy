<?php

declare(strict_types=1);

if (!defined('GROCY_USER_ID')) define('GROCY_USER_ID', 1);
if (!defined('GROCY_MODE')) define('GROCY_MODE', 'production');
require_once __DIR__ . '/../../../packages/autoload.php';
require_once __DIR__ . '/../../../services/StockService.php';
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService', 'GrocyAiTaxonomyMigration', 'GrocyAiTaxonomyService', 'GrocyAiCaptureProductService'] as $file) require_once __DIR__ . '/../src/' . $file . '.php';

function approvalCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function approvalReject(callable $operation): void { try { $operation(); } catch (InvalidArgumentException|RuntimeException|PDOException $expected) { return; } throw new RuntimeException('Expected approval rejection'); }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$nativeMigration = file_get_contents(__DIR__ . '/../../../migrations/0207.sql');
if (!is_string($nativeMigration) || preg_match('/CREATE TABLE products \([\s\S]*?\n\);/', $nativeMigration, $nativeProductTable) !== 1) throw new RuntimeException('Current Grocy products migration unavailable');
$db->exec($nativeProductTable[0]);
foreach (['qu_id_consume INTEGER', 'auto_reprint_stock_label TINYINT NOT NULL DEFAULT 0', 'quick_open_amount REAL NOT NULL DEFAULT 1', 'qu_id_price INTEGER', 'disable_open TINYINT NOT NULL DEFAULT 0', 'default_purchase_price_type TINYINT NOT NULL DEFAULT 1'] as $column) $db->exec('ALTER TABLE products ADD ' . $column);
approvalCheck((int)$db->query("SELECT COUNT(*) FROM pragma_table_info('products') WHERE name = 'qu_factor_purchase_to_stock'")->fetchColumn() === 0, 'fixture uses current native product columns');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
$db->exec('CREATE UNIQUE INDEX ix_product_barcodes_canonical_gtin ON product_barcodes (' . GrocyAI\Services\GrocyAiGtin::CanonicalSqlExpression('barcode') . ')');
$db->exec('CREATE TABLE locations (id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE quantity_units (id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE quantity_unit_conversions (id INTEGER PRIMARY KEY, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, product_id INTEGER)');
$db->exec('CREATE VIEW quantity_unit_conversions_resolved AS SELECT product_id, from_qu_id, to_qu_id, factor FROM quantity_unit_conversions WHERE product_id IS NOT NULL UNION ALL SELECT p.id, c.from_qu_id, c.to_qu_id, c.factor FROM products p CROSS JOIN quantity_unit_conversions c WHERE c.product_id IS NULL');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO locations VALUES (1, 1)');
$db->exec('INSERT INTO quantity_units VALUES (1, 1), (2, 1)');
$db->exec("INSERT INTO product_groups VALUES (1, 'Seafood', 1)");
GrocyAI\Services\GrocyAiCaptureMigration::Bootstrap($db);
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test'), (2, 'reviewing', 'test'), (3, 'committed', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (1, 1, 1, '4006381333931', '04006381333931', 'unknown'), (2, 2, 1, '04006381333931', '04006381333931', 'unknown'), (3, 3, 1, '96385074', '00000096385074', 'unknown'), (4, 1, 2, '96385074', '00000096385074', 'unknown')");
$research = new GrocyAI\Services\GrocyAiCaptureResearchService($db);
$research->EnqueueUnknown(1, 1, '4006381333931');
$research->EnqueueUnknown(2, 2, '04006381333931');
$research->EnqueueUnknown(1, 4, '96385074');
$draft = $research->DraftsForTrip(1)[0];
$revision = (int)$draft['revision'];
$fields = ['name' => 'Test cereal', 'location_id' => 1, 'qu_id_purchase' => 1, 'qu_id_stock' => 1, 'product_group_id' => 1];
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM product_barcodes')->fetchColumn() === 0, 'research has no native writes');
$approval = new GrocyAI\Services\GrocyAiCaptureProductService($db);
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision + 1, $fields, 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'location_id' => 99], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'qu_id_stock' => 99], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'product_group_id' => 99], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'parent_product_id' => 99], 'test'));
$result = $approval->ApproveDraft(1, 1, $revision, $fields, 'test');
$id = (int)$result['product_id'];
approvalCheck($id > 0 && $db->query('SELECT barcode FROM product_barcodes')->fetchColumn() === '4006381333931', 'approval attaches original scan');
approvalCheck((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_lines WHERE id IN (1, 2) AND status = 'known' AND resolved_product_id = $id")->fetchColumn() === 2, 'approval re-resolves matching capture lines across trips');
approvalCheck($approval->ApproveDraft(1, 1, $revision, $fields, 'test')['product_id'] === $id, 'repeat approval is idempotent');
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'name' => 'Changed confirmation'], 'test'));
approvalReject(fn() => $approval->ApproveDraft(2, 2, 1, [...$fields, 'name' => 'Different'], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 1, 'canonical conflict creates no product');
approvalReject(fn() => $approval->ApproveDraft(1, 4, 1, $fields, 'test'));
$db->exec("CREATE TRIGGER fail_barcode BEFORE INSERT ON product_barcodes BEGIN SELECT RAISE(ABORT, 'fail'); END");
approvalReject(fn() => $approval->ApproveDraft(1, 4, 1, [...$fields, 'name' => 'Second item'], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 1, 'barcode failure rolls back product');
$db->exec('DROP TRIGGER fail_barcode');
approvalReject(fn() => $approval->LinkDraft(1, 4, 1, 0, 'test'));
$linked = $approval->LinkDraft(1, 4, 1, $id, 'test');
approvalCheck($linked['product_id'] === $id && $approval->LinkDraft(1, 4, 1, $id, 'test')['product_id'] === $id, 'explicit link idempotent');
approvalCheck((int)$db->query("SELECT resolved_product_id FROM grocy_ai_capture_lines WHERE id = 4")->fetchColumn() === $id, 'link re-resolves capture line');
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (5, 1, 3, '036000291452', '00036000291452', 'unknown'), (6, 1, 4, '012345678905', '00012345678905', 'unknown'), (7, 1, 5, '042100005264', '00042100005264', 'unknown'), (8, 1, 6, '7501031311309', '07501031311309', 'unknown')");
foreach ([5 => '036000291452', 6 => '012345678905', 7 => '042100005264', 8 => '7501031311309'] as $lineId => $barcode) $research->EnqueueUnknown(1, $lineId, $barcode);
$db->prepare('INSERT INTO product_barcodes (product_id, barcode) VALUES (?, ?)')->execute([$id, '00036000291452']);
$approval->LinkDraft(1, 5, 1, $id, 'test');
approvalCheck((int)$db->query("SELECT COUNT(*) FROM product_barcodes WHERE product_id = $id AND barcode = '00036000291452'")->fetchColumn() === 1, 'link preserves existing exact barcode');
approvalCheck((int)$db->query("SELECT COUNT(*) FROM product_barcodes WHERE product_id = $id AND barcode IN ('00036000291452', '036000291452')")->fetchColumn() === 1, 'canonical equivalent uses one stored barcode');
$countBeforeTaxonomy = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
approvalReject(fn() => $approval->ApproveDraft(1, 6, 1, [...$fields, 'name' => 'Taxonomy item', 'taxonomy_leaf_slug' => 'stale-leaf'], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $countBeforeTaxonomy && (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'grocy_ai_taxonomy_classifications'")->fetchColumn() === 0, 'invalid taxonomy rolls back product and bootstrap');
$taxResult = $approval->ApproveDraft(1, 6, 1, [...$fields, 'name' => 'Taxonomy item', 'taxonomy_leaf_slug' => 'meat-seafood'], 'test');
approvalCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_classifications WHERE product_id = ' . (int)$taxResult['product_id'])->fetchColumn() === 1, 'valid taxonomy assignment commits with product');
$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (1, 2, 2, ?)')->execute([$id]);
$countBeforeParent = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
approvalReject(fn() => $approval->ApproveDraft(1, 7, 1, [...$fields, 'name' => 'Child item', 'qu_id_purchase' => 2, 'qu_id_stock' => 2, 'parent_product_id' => $id], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $countBeforeParent, 'parent-only conversion rejection rolls back child');
$db->exec('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (1, 2, 2)');
$child = $approval->ApproveDraft(1, 7, 1, [...$fields, 'name' => 'Child item', 'qu_id_purchase' => 2, 'qu_id_stock' => 2, 'parent_product_id' => $id], 'test');
approvalCheck((int)$db->query('SELECT parent_product_id FROM products WHERE id = ' . (int)$child['product_id'])->fetchColumn() === $id, 'compatible parent accepted');
$converted = $approval->ApproveDraft(1, 8, 1, [...$fields, 'name' => 'Converted item', 'qu_id_stock' => 2], 'test');
approvalCheck((float)$db->query('SELECT factor FROM quantity_unit_conversions WHERE product_id = ' . (int)$converted['product_id'] . ' AND from_qu_id = 1 AND to_qu_id = 2')->fetchColumn() === 2.0, 'different units persist native conversion');
approvalReject(fn() => $approval->ApproveDraft(3, 3, 1, [...$fields, 'name' => 'Committed'], 'test'));
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (2, 'test')");
approvalReject(fn() => $approval->LinkDraft(2, 2, 1, $id, 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'approval writes no stock');
$db->exec('CREATE TABLE user_permissions_resolved (id INTEGER PRIMARY KEY, user_id INTEGER, permission_name TEXT)');
$connection = new ReflectionClass(Grocy\Services\DatabaseService::class);
foreach (['DbConnectionRaw' => $db, 'DbConnection' => new LessQL\Database($db), 'instance' => $connection->newInstance()] as $key => $value) $connection->getProperty($key)->setValue(null, $value);
approvalCheck((int)Grocy\Services\StockService::GetInstance()->GetProductIdFromBarcode('00036000291452') === $id, 'native lookup preserves old barcode spelling');
approvalCheck((int)Grocy\Services\StockService::GetInstance()->GetProductIdFromBarcode('036000291452') === $id, 'native lookup resolves linked raw scan');
require_once __DIR__ . '/../src/GrocyAiCaptureResearchController.php';
$controller = (new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiCaptureResearchController::class))->newInstanceWithoutConstructor();
$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/test')->withParsedBody(['revision' => 1, 'fields' => $fields]);
$response = (new Slim\Psr7\Factory\ResponseFactory())->createResponse();
$args = ['tripId' => '1', 'seq' => '1'];
try { $controller->Approve($request, $response, $args); throw new RuntimeException('Missing purchase permission accepted'); } catch (Grocy\Controllers\Users\PermissionMissingException $expected) {}
$db->exec("INSERT INTO user_permissions_resolved (user_id, permission_name) VALUES (1, 'STOCK_PURCHASE')");
try { $controller->Approve($request, $response, $args); throw new RuntimeException('Missing master-data permission accepted'); } catch (Grocy\Controllers\Users\PermissionMissingException $expected) {}
try { $controller->Link($request, $response, $args); throw new RuntimeException('Link missing master-data permission accepted'); } catch (Grocy\Controllers\Users\PermissionMissingException $expected) {}
$db->exec("INSERT INTO user_permissions_resolved (user_id, permission_name) VALUES (1, 'MASTER_DATA_EDIT')");
approvalCheck($controller->Approve($request->withParsedBody(['revision' => 1, 'fields' => $fields, 'barcode' => 'forged']), $response, $args)->getStatusCode() === 400, 'approval rejects excess body fields');
approvalCheck($controller->Link($request->withParsedBody(['revision' => 1]), $response, $args)->getStatusCode() === 400, 'link requires explicit product ID');
echo "capture research approval: PASS\n";
