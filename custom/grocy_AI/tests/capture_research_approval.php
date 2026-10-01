<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../packages/autoload.php';
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService', 'GrocyAiTaxonomyMigration', 'GrocyAiTaxonomyService', 'GrocyAiCaptureProductService'] as $file) require_once __DIR__ . '/../src/' . $file . '.php';

function approvalCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function approvalReject(callable $operation): void { try { $operation(); } catch (InvalidArgumentException|RuntimeException|PDOException $expected) { return; } throw new RuntimeException('Expected approval rejection'); }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, location_id INTEGER NOT NULL, qu_id_purchase INTEGER NOT NULL, qu_id_stock INTEGER NOT NULL, qu_factor_purchase_to_stock REAL NOT NULL, product_group_id INTEGER, parent_product_id INTEGER, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
$db->exec('CREATE TABLE locations (id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE quantity_units (id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO locations VALUES (1, 1)');
$db->exec('INSERT INTO quantity_units VALUES (1, 1)');
$db->exec('INSERT INTO product_groups VALUES (1, 1)');
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
approvalReject(fn() => $approval->ApproveDraft(3, 3, 1, [...$fields, 'name' => 'Committed'], 'test'));
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (2, 'test')");
approvalReject(fn() => $approval->LinkDraft(2, 2, 1, $id, 'test'));
approvalCheck((int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'approval writes no stock');
echo "capture research approval: PASS\n";
