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
// Completing web research may suggest a name but must preserve reviewer edits and native state.
$draft = $research->UpdateDraft(1, 1, $revision, ['name' => 'Reviewer cereal'], 'test');
$claim = $research->ClaimJobs(1, 'test')[0];
$research->CompleteJob($claim['id'], $claim['lease_token'], ['contract_version' => 2, 'canonical_gtin' => $claim['canonical_gtin'], 'outcome' => 'found', 'name_candidates' => ['Web cereal'], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => ['openai-web'], 'name_candidate_sources' => [['openai-web']], 'web_evidence' => [['candidate_index' => 0, 'exact_gtin_claim' => false, 'citations' => [['title' => 'Cereal', 'url' => 'https://example.com/cereal']]]]]);
$draft = $research->ReviewForTrip(1)['drafts'][0];
$revision = $draft['revision'];
approvalCheck($draft['selected']['name'] === 'Reviewer cereal', 'OpenAI completion preserves reviewer name');
approvalCheck($draft['name_alternatives'][0]['web_evidence']['citations'][0]['domain'] === 'example.com', 'review DTO exposes bounded OpenAI evidence');
approvalCheck($draft['group_candidates'] === [] && $draft['taxonomy_candidates'] === [] && $draft['receipt_evidence'] === null, 'web evidence selects no classification or receipt');
approvalCheck((int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'OpenAI completion never writes stock');

$fields = ['name' => 'Test cereal', 'location_id' => 1, 'qu_id_purchase' => 1, 'qu_id_stock' => 1, 'product_group_id' => 1];
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM product_barcodes')->fetchColumn() === 0, 'research has no native writes');
$approval = new GrocyAI\Services\GrocyAiCaptureProductService($db);
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision + 1, $fields, 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'location_id' => 99], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'qu_id_stock' => 99], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'product_group_id' => 99], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'parent_product_id' => 99], 'test'));
// Rejected unit pairs must leave every native table unchanged.
$nativeSnapshot = fn() => array_map(fn($table) => $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), ['products', 'product_barcodes', 'quantity_units', 'quantity_unit_conversions', 'stock_log']);
$db->exec('INSERT INTO quantity_units VALUES (3, 0)');
$beforeRejectedUnits = $nativeSnapshot();
foreach (['qu_id_purchase', 'qu_id_stock'] as $unitField)
{
	approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, $unitField => 3], 'test'));
	approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, $unitField => null], 'test'));
	approvalCheck($nativeSnapshot() === $beforeRejectedUnits, 'inactive or cleared units leave native rows unchanged');
}
$db->exec('INSERT INTO quantity_unit_conversions (id, from_qu_id, to_qu_id, factor) VALUES (90, 2, 1, 2), (91, 1, 2, 0)');
$beforeRejectedUnits = $nativeSnapshot();
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'qu_id_stock' => 2], 'test'));
approvalCheck($nativeSnapshot() === $beforeRejectedUnits, 'reverse or nonpositive conversion leaves native rows unchanged');
$db->exec('DELETE FROM quantity_unit_conversions WHERE id IN (90, 91)');
$savedUnits = $research->UpdateDraft(1, 1, $revision, ['qu_id_purchase' => 1, 'qu_id_stock' => 2], 'test');
$revision = $savedUnits['revision'];
approvalReject(fn() => $approval->ApproveDraft(1, 1, $revision, [...$fields, 'qu_id_stock' => 2], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0, 'incompatible draft unit pair cannot create product');
$result = $approval->ApproveDraft(1, 1, $revision, $fields, 'test');
$id = (int)$result['product_id'];
approvalCheck($id > 0 && $db->query('SELECT barcode FROM product_barcodes')->fetchColumn() === '4006381333931', 'approval attaches original scan');
approvalCheck((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_lines WHERE id IN (1, 2) AND status = 'known' AND resolved_product_id = $id")->fetchColumn() === 2, 'approval re-resolves matching capture lines across trips');
$otherReview = $research->ReviewForTrip(2)['drafts'][0];
approvalCheck($otherReview['line_status'] === 'known' && $otherReview['resolved_product_id'] === $id && $otherReview['resolved_product_name'] === 'Test cereal' && $otherReview['outcome'] !== 'linked', 'other trip exposes resolved owner for explicit review');
approvalCheck(in_array('capture_line_2_product_review_required', (new GrocyAI\Services\GrocyAiReceiptService($db))->Readiness(2)['reasons'], true), 'other trip stays blocked after first approval');
approvalReject(fn() => $approval->ApproveDraft(2, 2, (int)$otherReview['revision'], [...$fields, 'name' => 'Duplicate'], 'test'));
approvalReject(fn() => $approval->LinkDraft(2, 2, (int)$otherReview['revision'] + 1, $id, 'test'));
approvalReject(fn() => $approval->LinkDraft(2, 2, (int)$otherReview['revision'], $id + 1, 'test'));
$otherLink = $approval->LinkDraft(2, 2, (int)$otherReview['revision'], $id, 'test');
$otherReadiness = (new GrocyAI\Services\GrocyAiReceiptService($db))->Readiness(2);
approvalCheck($otherLink['product_id'] === $id && !in_array('capture_line_2_product_review_required', $otherReadiness['reasons'], true) && !$otherReadiness['ready'], 'explicit other-trip link clears only product blocker');
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'other-trip link creates no product or stock');
$readiness = (new GrocyAI\Services\GrocyAiReceiptService($db))->Readiness(1);
approvalCheck(!in_array('capture_line_1_product_review_required', $readiness['reasons'], true) && in_array('capture_line_4_product_review_required', $readiness['reasons'], true), 'finalized approval clears only its own selected product review');
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
approvalCheck(!in_array('capture_line_4_product_review_required', (new GrocyAI\Services\GrocyAiReceiptService($db))->Readiness(1)['reasons'], true), 'linked draft clears selected product review');
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (5, 1, 3, '036000291452', '00036000291452', 'unknown'), (6, 1, 4, '012345678905', '00012345678905', 'unknown'), (7, 1, 5, '042100005264', '00042100005264', 'unknown'), (8, 1, 6, '7501031311309', '07501031311309', 'unknown')");
foreach ([5 => '036000291452', 6 => '012345678905', 7 => '042100005264', 8 => '7501031311309'] as $lineId => $barcode) $research->EnqueueUnknown(1, $lineId, $barcode);
$db->prepare('INSERT INTO product_barcodes (product_id, barcode) VALUES (?, ?)')->execute([$id, '00036000291452']);
$approval->LinkDraft(1, 5, 1, $id, 'test');
approvalCheck((int)$db->query("SELECT COUNT(*) FROM product_barcodes WHERE product_id = $id AND barcode = '00036000291452'")->fetchColumn() === 1, 'link preserves existing exact barcode');
approvalCheck((int)$db->query("SELECT COUNT(*) FROM product_barcodes WHERE product_id = $id AND barcode IN ('00036000291452', '036000291452')")->fetchColumn() === 1, 'canonical equivalent uses one stored barcode');
$schemaBeforeTaxonomy = $db->query("SELECT name, sql FROM sqlite_master WHERE name LIKE 'grocy_ai_taxonomy_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$catalogBeforeTaxonomy = $db->query('SELECT * FROM grocy_ai_taxonomy_classifications ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);
$countBeforeTaxonomy = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
approvalReject(fn() => $approval->ApproveDraft(1, 6, 1, [...$fields, 'name' => 'Taxonomy item', 'taxonomy_leaf_slug' => 'stale-leaf'], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $countBeforeTaxonomy && $db->query("SELECT name, sql FROM sqlite_master WHERE name LIKE 'grocy_ai_taxonomy_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) === $schemaBeforeTaxonomy && $db->query('SELECT * FROM grocy_ai_taxonomy_classifications ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC) === $catalogBeforeTaxonomy, 'invalid taxonomy rolls back product and bootstrap');
$taxResult = $approval->ApproveDraft(1, 6, 1, [...$fields, 'name' => 'Taxonomy item', 'taxonomy_leaf_slug' => 'meat-seafood'], 'test');
approvalCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_classifications WHERE product_id = ' . (int)$taxResult['product_id'])->fetchColumn() === 1, 'valid taxonomy assignment commits with product');
$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (1, 2, 2, ?)')->execute([$id]);
$countBeforeParent = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$beforeRejectedParent = $nativeSnapshot();
approvalReject(fn() => $approval->ApproveDraft(1, 7, 1, [...$fields, 'name' => 'Child item', 'qu_id_purchase' => 2, 'qu_id_stock' => 2, 'parent_product_id' => $id], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $countBeforeParent, 'parent-only conversion rejection rolls back child');
approvalCheck($nativeSnapshot() === $beforeRejectedParent, 'incompatible parent leaves all native rows unchanged');
$db->exec('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (1, 2, 2)');
// A parent changed after review must be checked against its current stock unit.
$db->prepare('UPDATE products SET qu_id_stock = 2 WHERE id = ?')->execute([$id]);
approvalReject(fn() => $approval->ApproveDraft(1, 7, 1, [...$fields, 'name' => 'Stale parent review', 'parent_product_id' => $id], 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $countBeforeParent, 'live parent unit mismatch rolls back approval');
$db->prepare('UPDATE products SET qu_id_stock = 1 WHERE id = ?')->execute([$id]);
$child = $approval->ApproveDraft(1, 7, 1, [...$fields, 'name' => 'Child item', 'qu_id_purchase' => 2, 'qu_id_stock' => 2, 'parent_product_id' => $id], 'test');
approvalCheck((int)$db->query('SELECT parent_product_id FROM products WHERE id = ' . (int)$child['product_id'])->fetchColumn() === $id, 'compatible parent accepted');
$converted = $approval->ApproveDraft(1, 8, 1, [...$fields, 'name' => 'Converted item', 'qu_id_stock' => 2], 'test');
approvalCheck((float)$db->query('SELECT factor FROM quantity_unit_conversions WHERE product_id = ' . (int)$converted['product_id'] . ' AND from_qu_id = 1 AND to_qu_id = 2')->fetchColumn() === 2.0, 'different units persist native conversion');
approvalReject(fn() => $approval->ApproveDraft(3, 3, 1, [...$fields, 'name' => 'Committed'], 'test'));
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (2, 'test')");
approvalReject(fn() => $approval->LinkDraft(2, 2, 1, $id, 'test'));
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (4, 'reviewing', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (9, 4, 1, '5901234123457', '05901234123457', 'unknown')");
$research->EnqueueUnknown(4, 9, '5901234123457');
$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Ordinary product', 1, 1, 1)");
$ordinaryId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO product_barcodes (product_id, barcode) VALUES (?, ?)')->execute([$ordinaryId, '05901234123457']);
(new GrocyAI\Services\GrocyAiCaptureService($db, false))->ReresolveBarcode('05901234123457', 'test');
$ordinaryReview = $research->ReviewForTrip(4)['drafts'][0];
approvalCheck($ordinaryReview['line_status'] === 'known' && $ordinaryReview['resolved_product_id'] === $ordinaryId && $ordinaryReview['resolved_product_name'] === 'Ordinary product' && in_array('capture_line_9_product_review_required', (new GrocyAI\Services\GrocyAiReceiptService($db))->Readiness(4)['reasons'], true), 'ordinary product creation still requires draft review');
approvalReject(fn() => $approval->LinkDraft(4, 9, (int)$ordinaryReview['revision'], $id, 'test'));
$approval->LinkDraft(4, 9, (int)$ordinaryReview['revision'], $ordinaryId, 'test');
approvalCheck(!in_array('capture_line_9_product_review_required', (new GrocyAI\Services\GrocyAiReceiptService($db))->Readiness(4)['reasons'], true), 'ordinary owner explicit link clears product blocker');
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
// Optional parent creation is one audited catalog transaction; stock remains untouched.
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (10, 1, 7, '4012345678901', '04012345678901', 'unknown')");
$research->EnqueueUnknown(1, 10, '4012345678901');
$parentFields = [...$fields, 'name' => 'Hot honey carrots', 'parent_mode' => 'create', 'new_parent_name' => 'Hot honey carrots (generic)'];
$beforeParentCreation = $nativeSnapshot();
$db->exec("CREATE TRIGGER fail_optional_child BEFORE INSERT ON product_barcodes BEGIN SELECT RAISE(ABORT, 'fail'); END");
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, $parentFields, 'test'));
approvalCheck($nativeSnapshot() === $beforeParentCreation, 'child failure rolls back both parent and child');
$db->exec('DROP TRIGGER fail_optional_child');
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'new_parent_name' => 'HOT HONEY CARROTS'], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'new_parent_name' => 'Test cereal'], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'parent_product_id' => $id], 'test'));
approvalCheck($nativeSnapshot() === $beforeParentCreation, 'invalid or duplicate parent leaves catalog unchanged');
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'taxonomy_leaf_slug' => 'stale-leaf'], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'qu_id_purchase' => 99], 'test'));
approvalCheck($nativeSnapshot() === $beforeParentCreation, 'invalid child classification or reference rolls back optional parent');
$parentCreated = $approval->ApproveDraft(1, 10, 1, $parentFields, 'test');
$parentId = $parentCreated['parent_product_id'];
$parentRow = $db->query('SELECT * FROM products WHERE id = ' . $parentId)->fetch(PDO::FETCH_ASSOC);
approvalCheck($parentId > 0 && (int)$parentRow['qu_id_stock'] === 1 && (int)$parentRow['qu_id_purchase'] === 1 && $parentRow['parent_product_id'] === null, 'new parent has compatible same units and no ancestor');
approvalCheck((int)$db->query('SELECT parent_product_id FROM products WHERE id = ' . $parentCreated['product_id'])->fetchColumn() === $parentId, 'new child links to created parent');
approvalCheck((int)$db->query('SELECT COUNT(*) FROM product_barcodes WHERE product_id = ' . $parentId)->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'parent has no barcode or stock');
approvalCheck($approval->ApproveDraft(1, 10, 1, $parentFields, 'test') === $parentCreated, 'retry returns identical child and parent IDs');
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'new_parent_name' => 'Other parent'], 'test'));
approvalReject(fn() => $approval->ApproveDraft(1, 10, 1, [...$parentFields, 'parent_mode' => 'standalone'], 'test'));
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (11, 1, 8, '9780201379624', '09780201379624', 'unknown')");
$research->EnqueueUnknown(1, 11, '9780201379624');
$duplicateResponse = $controller->Approve($request->withParsedBody(['revision' => 1, 'fields' => [...$parentFields, 'name' => 'Second flavored carrots', 'new_parent_name' => 'TEST CEREAL']]), $response, ['tripId' => '1', 'seq' => '8']);
approvalCheck($duplicateResponse->getStatusCode() === 409 && str_contains((string)$duplicateResponse->getBody(), 'Choose an existing parent or rename'), 'duplicate parent provides actionable safe API feedback');
$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Crème carrots (generic)', 1, 1, 1)");
$beforeUnicodeParent = $nativeSnapshot();
approvalReject(fn() => $approval->ApproveDraft(1, 11, 1, [...$parentFields, 'name' => 'Second flavored carrots', 'new_parent_name' => 'CRÈME CARROTS (GENERIC)'], 'test'));
approvalCheck($nativeSnapshot() === $beforeUnicodeParent, 'Unicode case collision leaves catalog unchanged');
approvalReject(fn() => $approval->ApproveDraft(1, 11, 1, [...$parentFields, 'name' => 'CRÈME CARROTS (GENERIC)', 'new_parent_name' => 'Distinct parent'], 'test'));
approvalCheck($nativeSnapshot() === $beforeUnicodeParent, 'Unicode child collision creates neither parent nor child');
$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Straße (generic)', 1, 1, 1), ('ΟΣ (generic)', 1, 1, 1)");
$beforeFullFold = $nativeSnapshot();
foreach (['STRASSE (GENERIC)', 'ος (generic)'] as $foldedParent)
{
	approvalReject(fn() => $approval->ApproveDraft(1, 11, 1, [...$parentFields, 'name' => 'Distinct child', 'new_parent_name' => $foldedParent], 'test'));
	approvalCheck($nativeSnapshot() === $beforeFullFold, 'full Unicode fold collision creates no parent or child');
}
foreach ([['Straße', 'STRASSE'], ['ΟΣ', 'ος']] as [$childName, $newParentName])
{
	approvalReject(fn() => $approval->ApproveDraft(1, 11, 1, [...$parentFields, 'name' => $childName, 'new_parent_name' => $newParentName], 'test'));
	approvalCheck($nativeSnapshot() === $beforeFullFold, 'parent-child full-fold equal names are rejected');
}
foreach (['inactive', 'deleted', 'nonroot'] as $invalidParentCase)
{
	$liveParentId = $invalidParentCase === 'deleted' ? 9999 : $parentId;
	if ($invalidParentCase === 'inactive') $db->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$parentId]);
	if ($invalidParentCase === 'nonroot') $db->prepare('UPDATE products SET parent_product_id = ? WHERE id = ?')->execute([$id, $parentId]);
	$invalidParentResponse = $controller->Approve($request->withParsedBody(['revision' => 1, 'fields' => [...$fields, 'name' => 'Distinct child', 'parent_mode' => 'existing', 'parent_product_id' => $liveParentId]]), $response, ['tripId' => '1', 'seq' => '8']);
	approvalCheck($invalidParentResponse->getStatusCode() === 400 && str_contains((string)$invalidParentResponse->getBody(), 'Keep this product standalone or choose another parent'), 'invalidated parent provides actionable safe API feedback: ' . $invalidParentCase);
	$db->prepare('UPDATE products SET active = 1, parent_product_id = NULL WHERE id = ?')->execute([$parentId]);
}
echo "capture research approval: PASS\n";
