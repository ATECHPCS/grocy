<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiBarcodeService;

// A new tests/<mode>.php must require_once its own src/* dependencies (guarded by is_file), mirroring the
// run.php top block, or class_exists() stays false and later plans mis-report "not implemented".
foreach (['GrocyAiCaptureMigration', 'GrocyAiCaptureService'] as $captureClassFile)
{
	$captureClassPath = __DIR__ . '/../src/' . $captureClassFile . '.php';
	if (is_file($captureClassPath))
	{
		require_once $captureClassPath;
	}
}

/**
 * Phase 8 purchase-capture contract suite.
 *
 * Plan 08-01 fixes the trip/line/audit DTO shapes, the closed trip-status / line-status / line-outcome
 * vocabularies, the coalescing key, and the checksum contract as a failing (RED) suite before any capture
 * ENGINE code exists — the namespaced schema (`GrocyAiCaptureMigration`) is created in this same wave, so
 * the schema-shape assertions run GREEN now while every engine assertion stays RED until later Phase 8
 * plans implement `GrocyAiCaptureService`.
 *
 * Two modes:
 *   - capture-contract   pins the schema/DTO/vocabulary/coalescing contract; RED at the missing engine.
 *   - capture-invariants pins the six behavioral invariants (lifecycle, coalescing, known/unknown
 *                        resolution, review re-resolve, checksum + per-item applied_at idempotency,
 *                        partial commit, and the stock-write boundary); RED until the engine exists.
 */

const CAPTURE_CONTRACT_MARKER = 'EXPECTED_RED: capture.contract_shapes';
const CAPTURE_INVARIANTS_MARKER = 'EXPECTED_RED: capture.engine_invariants';

function captureCases(): array
{
	$path = __DIR__ . '/fixtures/capture-cases.json';
	return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function captureAssert(bool $condition, string $marker, string $message): void
{
	if (!$condition)
	{
		expectedRed($marker, $message);
	}
}

/**
 * The wave-2 live-capture core surface (start/scan/coalesce/load/lifecycle/edit). The `capture-contract`
 * gate routes through this, so it goes GREEN as soon as this plan lands the service.
 *
 * @return array<int, string>
 */
function captureCoreMissingMethods(): array
{
	if (!class_exists(GrocyAiCaptureService::class))
	{
		return ['GrocyAiCaptureService'];
	}
	return captureMissing(['StartTrip', 'ScanIntoTrip', 'LoadTrip', 'UpdateLine', 'SetTripDefaults', 'SetStatus']);
}

/**
 * The full engine surface, adding the commit-era checksum/idempotency methods later Phase 8 plans deliver.
 * The `capture-invariants` gate routes through this and stays RED until the commit wave lands.
 *
 * @return array<int, string>
 */
function captureEngineMissingMethods(): array
{
	if (!class_exists(GrocyAiCaptureService::class))
	{
		return ['GrocyAiCaptureService'];
	}
	return captureMissing(['StartTrip', 'ScanIntoTrip', 'LoadTrip', 'UpdateLine', 'SetTripDefaults', 'SetStatus', 'ChecksumForTrip', 'CommitTrip']);
}

/**
 * @param array<int, string> $methods
 * @return array<int, string>
 */
function captureMissing(array $methods): array
{
	$missing = [];
	foreach ($methods as $method)
	{
		if (!method_exists(GrocyAiCaptureService::class, $method))
		{
			$missing[] = $method;
		}
	}
	return $missing;
}

/**
 * A self-contained native + module fixture database. The four native tables the capture engine reads or is
 * forbidden to write (`products`, `product_barcodes`, `stock`, `stock_log`) are created here; the module
 * capture tables are created by the migration under test.
 */
final class CaptureBeginRacePdo extends PDO
{
	public ?Closure $beforeBegin = null;

	public function exec(string $statement): int|false
	{
		if ($statement === 'BEGIN IMMEDIATE' && $this->beforeBegin !== null)
		{
			$callback = $this->beforeBegin;
			$this->beforeBegin = null;
			$callback($this);
		}
		return parent::exec($statement);
	}
}

function captureFixturePdo(?PDO $pdo = null): PDO
{
	$pdo ??= new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE products (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL, qu_id_purchase INTEGER NOT NULL, qu_id_stock INTEGER NOT NULL)');
	$pdo->exec("INSERT INTO products (id, name, qu_id_purchase, qu_id_stock) VALUES (101, 'Fixture product A', 1, 1), (102, 'Fixture product B', 2, 1), (103, 'Fixture product C', 1, 1)");
	$pdo->exec('CREATE TABLE cache__quantity_unit_conversions_resolved (product_id INTEGER, from_qu_id INTEGER, to_qu_id INTEGER, factor REAL)');
	$pdo->exec('INSERT INTO cache__quantity_unit_conversions_resolved VALUES (102, 2, 1, 6)');
	$pdo->exec('CREATE VIEW uihelper_product_details AS SELECT p.id, CAST(IFNULL(c.factor, 1.0) AS REAL) AS qu_factor_purchase_to_stock FROM products p LEFT JOIN cache__quantity_unit_conversions_resolved c ON p.id = c.product_id AND p.qu_id_purchase = c.from_qu_id AND p.qu_id_stock = c.to_qu_id');
	$pdo->exec('CREATE TABLE product_barcodes (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL, amount REAL NULL)');
	$pdo->exec("INSERT INTO product_barcodes (id, product_id, barcode) VALUES (1, 101, '012345678905')");
	$pdo->exec('CREATE TABLE stock (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NOT NULL, amount REAL NOT NULL, transaction_id TEXT NULL)');
	$pdo->exec('CREATE TABLE stock_log (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NOT NULL, amount REAL NOT NULL, transaction_type TEXT NOT NULL, transaction_id TEXT NULL)');
	return $pdo;
}

function captureSnapshotTables(PDO $pdo, array $tables): array
{
	$snapshots = [];
	foreach ($tables as $table)
	{
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string)$table) !== 1)
		{
			throw new RuntimeException('Unsafe fixture table name');
		}
		$snapshots[$table] = $pdo->query('SELECT * FROM "' . $table . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
	}
	return $snapshots;
}

/**
 * A fake native stock writer with `StockService::AddProduct`'s exact signature (including the by-reference
 * shared `$transactionId`). It records every call so the commit contract can be proven without Grocy's full
 * stock schema, and it is the ONLY thing `CommitTrip` writes stock through in the suite.
 */
final class CaptureFakeStockService
{
	/** @var array<int, array<string, mixed>> */
	public array $calls = [];

	public function AddProduct(int $productId, float $amount, $bestBeforeDate, $transactionType, $purchasedDate, $price, $locationId = null, $shoppingLocationId = null, &$transactionId = null, $stockLabelType = 0, $addExactAmount = false, $note = null)
	{
		if ($transactionId === null)
		{
			$transactionId = 'txn-capture-fixture';
		}
		$this->calls[] = [
			'product_id' => $productId,
			'amount' => $amount,
			'best_before' => $bestBeforeDate,
			'transaction_type' => $transactionType,
			'purchased_date' => $purchasedDate,
			'price' => $price,
			'location' => $locationId,
			'store' => $shoppingLocationId,
			'transaction_id' => $transactionId,
			'note' => $note
		];
		return $transactionId;
	}
}

/**
 * Task 1 (contract shapes): the trip/line/audit DTO shapes, the closed vocabularies, the coalescing key,
 * and the namespaced schema created in this wave. The schema assertions are GREEN now; the mode is RED at
 * the missing capture engine.
 */
function runCaptureContract(): never
{
	$cases = captureCases();

	$expectedTripKeys = ['id', 'created_at', 'created_by', 'status', 'default_location_id', 'default_shopping_location_id', 'transaction_id', 'committed_at', 'checksum', 'module_version'];
	$expectedLineKeys = ['id', 'trip_id', 'seq', 'scanned_barcode', 'canonical_gtin', 'resolved_product_id', 'status', 'quantity', 'price', 'best_before_override', 'selected', 'applied_at', 'outcome', 'created_at', 'updated_at'];
	$expectedAuditKeys = ['id', 'trip_id', 'line_id', 'actor', 'action', 'before_json', 'after_json', 'transaction_id', 'created_at'];

	// The fixtures themselves carry the closed contract shapes and vocabularies.
	captureAssert(($cases['dto_shapes']['trip'] ?? null) === $expectedTripKeys, CAPTURE_CONTRACT_MARKER, 'The trip DTO key set is not the closed contract shape');
	captureAssert(($cases['dto_shapes']['line'] ?? null) === $expectedLineKeys, CAPTURE_CONTRACT_MARKER, 'The line DTO key set is not the closed contract shape');
	captureAssert(($cases['dto_shapes']['audit'] ?? null) === $expectedAuditKeys, CAPTURE_CONTRACT_MARKER, 'The audit-record DTO key set is not the closed contract shape');
	captureAssert(($cases['trip_status_vocabulary'] ?? null) === ['open', 'reviewing', 'committed'], CAPTURE_CONTRACT_MARKER, 'The trip status vocabulary is not the closed open/reviewing/committed set');
	captureAssert(($cases['line_status_vocabulary'] ?? null) === ['known', 'unknown', 'conflict'], CAPTURE_CONTRACT_MARKER, 'The line status vocabulary is not the closed known/unknown/conflict set');
	captureAssert(($cases['line_outcome_vocabulary'] ?? null) === ['applied', 'conflict', 'skipped'], CAPTURE_CONTRACT_MARKER, 'The line outcome vocabulary is not the closed applied/conflict/skipped set');
	captureAssert(($cases['coalesce_key'] ?? null) === ['trip_id', 'COALESCE(canonical_gtin, scanned_barcode)'], CAPTURE_CONTRACT_MARKER, 'The coalescing key is not (trip_id, COALESCE(canonical_gtin, scanned_barcode))');

	// The namespaced schema created in THIS wave (Task 2): a double bootstrap is idempotent, leaves exactly
	// the module capture tables plus one version row, matches the closed DTO shapes, coalesces on the
	// canonical/barcode key, and touches no native table.
	captureAssert(class_exists(GrocyAiCaptureMigration::class), CAPTURE_CONTRACT_MARKER, 'The capture migration is not implemented');

	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	GrocyAiCaptureMigration::Bootstrap($pdo);
	GrocyAiCaptureMigration::Bootstrap($pdo);
	$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'grocy_ai_capture_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	captureAssert($tables === ['grocy_ai_capture_audit', 'grocy_ai_capture_lines', 'grocy_ai_capture_migrations', 'grocy_ai_capture_trips'], CAPTURE_CONTRACT_MARKER, 'Bootstrap did not leave exactly the trip/line/audit tables plus the migration ledger');
	captureAssert((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_capture_migrations')->fetchColumn() === 1, CAPTURE_CONTRACT_MARKER, 'Bootstrap is not idempotent: the migration ledger holds other than one version row');

	foreach ([
		'grocy_ai_capture_trips' => $expectedTripKeys,
		'grocy_ai_capture_lines' => $expectedLineKeys,
		'grocy_ai_capture_audit' => $expectedAuditKeys
	] as $table => $expectedColumns)
	{
		$columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
		captureAssert($columns === $expectedColumns, CAPTURE_CONTRACT_MARKER, "Table {$table} columns are not the closed DTO shape");
	}

	// The coalescing invariant is a UNIQUE index over (trip_id, COALESCE(canonical_gtin, scanned_barcode)).
	$coalesceSql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'grocy_ai_capture_lines_coalesce_idx'")->fetchColumn();
	captureAssert(str_contains($coalesceSql, 'UNIQUE') && str_contains($coalesceSql, 'COALESCE(canonical_gtin, scanned_barcode)'), CAPTURE_CONTRACT_MARKER, 'The line coalescing UNIQUE index is not (trip_id, COALESCE(canonical_gtin, scanned_barcode))');
	$secondScanRejected = false;
	$pdo->exec("INSERT INTO grocy_ai_capture_trips (status, module_version) VALUES ('open', 'v1')");
	$tripId = (int)$pdo->lastInsertId();
	$pdo->exec("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES ({$tripId}, 1, '012345678905', '00012345678905', 'known')");
	try
	{
		$pdo->exec("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES ({$tripId}, 2, '012345678905', '00012345678905', 'known')");
	}
	catch (\PDOException)
	{
		$secondScanRejected = true;
	}
	captureAssert($secondScanRejected, CAPTURE_CONTRACT_MARKER, 'A second same-canonical scan was not rejected by the coalescing UNIQUE index');

	// The migration source exposes no row-rewriting/row-removal path (the audit ledger is append-only).
	$migrationSource = (string)file_get_contents(__DIR__ . '/../src/GrocyAiCaptureMigration.php');
	captureAssert(preg_match('/\b(UPDATE|DELETE|DROP|ALTER)\b/', $migrationSource) !== 1, CAPTURE_CONTRACT_MARKER, 'The capture migration must expose no UPDATE/DELETE/DROP/ALTER path');

	// Bootstrapping alongside native tables creates/alters/drops no native object (excluding sqlite_%
	// internals, since AUTOINCREMENT tables create sqlite_sequence) and mutates no native row.
	$nativePdo = captureFixturePdo();
	$nativeTables = ['products', 'product_barcodes', 'stock', 'stock_log'];
	$nativeSchemaBefore = $nativePdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'grocy_ai_capture_%' AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$nativeBefore = captureSnapshotTables($nativePdo, $nativeTables);
	GrocyAiCaptureMigration::Bootstrap($nativePdo);
	$nativeSchemaAfter = $nativePdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'grocy_ai_capture_%' AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$nativeAfter = captureSnapshotTables($nativePdo, $nativeTables);
	captureAssert($nativeSchemaBefore === $nativeSchemaAfter, CAPTURE_CONTRACT_MARKER, 'Bootstrap created, altered, or dropped a native object');
	captureAssert($nativeBefore === $nativeAfter, CAPTURE_CONTRACT_MARKER, 'Bootstrap mutated native table rows');

	// The live-capture core surface: RED until this plan lands StartTrip/ScanIntoTrip/LoadTrip/SetStatus/
	// SetTripDefaults/UpdateLine.
	$missing = captureCoreMissingMethods();
	if ($missing !== [])
	{
		expectedRed(CAPTURE_CONTRACT_MARKER, 'The capture core surface (' . implode(', ', $missing) . ') is not implemented');
	}

	// --- Real engine assertions (green once the capture service lands) ----------------------------------

	// A freshly started trip is an `open` trip DTO with exactly the closed header keys, the given actor, and
	// the module version.
	$fixturePdo = captureFixturePdo();
	$service = new GrocyAiCaptureService($fixturePdo);
	$trip = $service->StartTrip('capture-fixture-actor');
	captureAssert(array_keys($trip) === $expectedTripKeys, CAPTURE_CONTRACT_MARKER, 'StartTrip returned a trip DTO outside the closed key set');
	captureAssert((string)$trip['status'] === 'open', CAPTURE_CONTRACT_MARKER, 'A freshly started trip is not in open status');
	captureAssert((string)$trip['created_by'] === 'capture-fixture-actor', CAPTURE_CONTRACT_MARKER, 'StartTrip did not record the given actor');
	captureAssert((string)$trip['module_version'] !== '', CAPTURE_CONTRACT_MARKER, 'StartTrip did not stamp the module version');

	// A scan returns exactly the closed line DTO.
	$line = $service->ScanIntoTrip((int)$trip['id'], '012345678905', 'capture-fixture-actor');
	captureAssert(array_keys($line) === $expectedLineKeys, CAPTURE_CONTRACT_MARKER, 'ScanIntoTrip returned a line DTO outside the closed key set');

	// LoadTrip returns the closed trip DTO plus its ordered lines.
	$loaded = $service->LoadTrip((int)$trip['id']);
	captureAssert(array_keys($loaded) === ['trip', 'lines'], CAPTURE_CONTRACT_MARKER, 'LoadTrip did not return the closed {trip, lines} shape');
	captureAssert(array_keys($loaded['trip']) === $expectedTripKeys, CAPTURE_CONTRACT_MARKER, 'LoadTrip returned a trip DTO outside the closed key set');
	captureAssert(count($loaded['lines']) === 1 && array_keys($loaded['lines'][0]) === $expectedLineKeys, CAPTURE_CONTRACT_MARKER, 'LoadTrip returned a line DTO outside the closed key set');

	// --- Live-capture behavior (Plan 08-02): lifecycle, coalescing, known/unknown, review re-resolve, and
	// the zero-stock-write boundary --------------------------------------------------------------------
	$pdo = captureFixturePdo();
	$engine = new GrocyAiCaptureService($pdo);
	$stockBefore = captureSnapshotTables($pdo, ['stock', 'stock_log']);

	// Q5 lifecycle: a trip starts open, advances open -> reviewing, and refuses any other transition.
	$flow = $engine->StartTrip('capture-actor');
	$flowId = (int)$flow['id'];
	captureAssert($flow['status'] === 'open', CAPTURE_CONTRACT_MARKER, 'A started trip is not open');
	captureAssert($engine->SetStatus($flowId, 'reviewing', 'capture-actor')['status'] === 'reviewing', CAPTURE_CONTRACT_MARKER, 'open -> reviewing was not applied');
	$badTransition = false;
	try
	{
		$engine->SetStatus($flowId, 'committed', 'capture-actor');
	}
	catch (\InvalidArgumentException)
	{
		$badTransition = true;
	}
	captureAssert($badTransition, CAPTURE_CONTRACT_MARKER, 'An unsupported trip status transition was not refused');

	// Q3 known/unknown resolution through the shipped ownership read.
	$knownLine = $engine->ScanIntoTrip($flowId, '012345678905', 'capture-actor');
	captureAssert($knownLine['status'] === 'known' && (int)$knownLine['resolved_product_id'] === 101, CAPTURE_CONTRACT_MARKER, 'An owned barcode did not resolve to a known line');
	$unknownLine = $engine->ScanIntoTrip($flowId, '4006381333931', 'capture-actor');
	captureAssert($unknownLine['status'] === 'unknown' && $unknownLine['resolved_product_id'] === null, CAPTURE_CONTRACT_MARKER, 'An unused barcode did not resolve to an unknown, ownerless line');

	// Q6 coalescing: a repeated barcode increments the one existing line; distinct barcodes stay distinct.
	$coalesced = $engine->ScanIntoTrip($flowId, '012345678905', 'capture-actor');
	captureAssert((int)$coalesced['id'] === (int)$knownLine['id'] && (int)$coalesced['quantity'] === 2, CAPTURE_CONTRACT_MARKER, 'A repeated barcode did not coalesce into the one incrementing line');
	$flowLoaded = $engine->LoadTrip($flowId);
	captureAssert(count($flowLoaded['lines']) === 2, CAPTURE_CONTRACT_MARKER, 'Distinct barcodes did not stay distinct lines');
	captureAssert((int)$flowLoaded['lines'][0]['seq'] < (int)$flowLoaded['lines'][1]['seq'], CAPTURE_CONTRACT_MARKER, 'LoadTrip lines are not ordered by seq');

	// UpdateLine edits: quantity, selection, and removal.
	$editById = [];
	foreach ($flowLoaded['lines'] as $flowLine)
	{
		$editById[(string)$flowLine['scanned_barcode']] = (int)$flowLine['seq'];
	}
	$afterQty = $engine->UpdateLine($flowId, $editById['012345678905'], ['quantity' => 5], 'capture-actor');
	$knownAfter = null;
	foreach ($afterQty['lines'] as $afterLine)
	{
		if ((string)$afterLine['scanned_barcode'] === '012345678905')
		{
			$knownAfter = $afterLine;
		}
	}
	captureAssert($knownAfter !== null && (int)$knownAfter['quantity'] === 5, CAPTURE_CONTRACT_MARKER, 'UpdateLine did not set the line quantity');
	$afterDelete = $engine->UpdateLine($flowId, $editById['4006381333931'], ['delete' => true], 'capture-actor');
	captureAssert(count($afterDelete['lines']) === 1, CAPTURE_CONTRACT_MARKER, 'UpdateLine delete did not remove the line');

	// Q10 review re-resolve: an unknown line whose owner now exists flips to known on the next load.
	$reresolvePdo = captureFixturePdo();
	$reresolveEngine = new GrocyAiCaptureService($reresolvePdo);
	$reresolveTrip = (int)$reresolveEngine->StartTrip('capture-actor')['id'];
	$reresolveEngine->ScanIntoTrip($reresolveTrip, '4006381333931', 'capture-actor');
	$reresolvePdo->exec("INSERT INTO product_barcodes (id, product_id, barcode) VALUES (2, 102, '4006381333931')");
	$reloaded = $reresolveEngine->LoadTrip($reresolveTrip, 'capture-actor');
	captureAssert($reloaded['lines'][0]['status'] === 'known' && (int)$reloaded['lines'][0]['resolved_product_id'] === 102, CAPTURE_CONTRACT_MARKER, 'A now-owned unknown line did not re-resolve to known on load');

	// A prior `conflict` line re-resolves the same way — Q10 re-resolves unknown OR conflict on load.
	$conflictSeq = (int)$reresolvePdo->query('SELECT COALESCE(MAX(seq), 0) + 1 FROM grocy_ai_capture_lines WHERE trip_id = ' . $reresolveTrip)->fetchColumn();
	$reresolvePdo->exec("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, canonical_gtin, resolved_product_id, status) VALUES ({$reresolveTrip}, {$conflictSeq}, '012345678905', NULL, NULL, 'conflict')");
	$conflictReloaded = $reresolveEngine->LoadTrip($reresolveTrip, 'capture-actor');
	$conflictLine = null;
	foreach ($conflictReloaded['lines'] as $conflictCandidate)
	{
		if ((string)$conflictCandidate['scanned_barcode'] === '012345678905' && (int)$conflictCandidate['seq'] === $conflictSeq)
		{
			$conflictLine = $conflictCandidate;
		}
	}
	captureAssert($conflictLine !== null && $conflictLine['status'] === 'known' && (int)$conflictLine['resolved_product_id'] === 101, CAPTURE_CONTRACT_MARKER, 'A conflict line did not re-resolve to known on load');

	// ListTrips returns the closed trip DTOs, newest first.
	$listPdo = captureFixturePdo();
	$listEngine = new GrocyAiCaptureService($listPdo);
	$firstTrip = (int)$listEngine->StartTrip('capture-actor')['id'];
	$secondTrip = (int)$listEngine->StartTrip('capture-actor')['id'];
	$trips = $listEngine->ListTrips();
	captureAssert(count($trips) === 2 && array_keys($trips[0]) === $expectedTripKeys, CAPTURE_CONTRACT_MARKER, 'ListTrips did not return the closed trip DTOs');
	captureAssert((int)$trips[0]['id'] === $secondTrip && (int)$trips[1]['id'] === $firstTrip, CAPTURE_CONTRACT_MARKER, 'ListTrips is not newest-first');

	// Stock-write boundary: no capture/scan/review path wrote to stock or stock_log.
	$stockAfter = captureSnapshotTables($pdo, ['stock', 'stock_log']);
	captureAssert($stockBefore === $stockAfter && $stockAfter === ['stock' => [], 'stock_log' => []], CAPTURE_CONTRACT_MARKER, 'A capture/scan/review path wrote to stock or stock_log');

	fwrite(STDOUT, "Capture contract shapes passed\n");
	exit(0);
}

/**
 * Task 1 (engine invariants): the six behavioral invariants stated against the (not-yet-existing) capture
 * service surface. Each claim names the exact method later plans exercise. RED until the full engine — the
 * whole trip lifecycle, coalescing, resolution, review re-resolve, checksum/idempotency commit, partial
 * commit, and the stock-write boundary — exists.
 */
function runCaptureInvariants(): never
{
	$cases = captureCases();

	// The closed vocabularies the invariants depend on.
	captureAssert(($cases['trip_status_vocabulary'] ?? null) === ['open', 'reviewing', 'committed'], CAPTURE_INVARIANTS_MARKER, 'The trip status vocabulary is not the closed set');
	captureAssert(($cases['line_status_vocabulary'] ?? null) === ['known', 'unknown', 'conflict'], CAPTURE_INVARIANTS_MARKER, 'The line status vocabulary is not the closed set');
	captureAssert(($cases['line_outcome_vocabulary'] ?? null) === ['applied', 'conflict', 'skipped'], CAPTURE_INVARIANTS_MARKER, 'The line outcome vocabulary is not the closed set');

	// The known/unknown split is resolved through the shipped ownership read, never a re-implementation:
	// an owned barcode is a known line, an unused barcode is an unknown line handed to enrichment.
	$barcode = new GrocyAiBarcodeService(static fn(): array => [], 0);
	$unused = $barcode->ResolveOwner('012345678905');
	captureAssert($unused['status'] === 'unused' && $unused['owner_product_id'] === null, CAPTURE_INVARIANTS_MARKER, 'An unused barcode does not resolve to an unknown (ownerless) line');
	$owned = (new GrocyAiBarcodeService(static fn(): array => [['product_id' => 101, 'owner_label' => 'Fixture product A']], 0))->ResolveOwner('012345678905');
	captureAssert($owned['status'] === 'owned_other' && (int)$owned['owner_product_id'] === 101, CAPTURE_INVARIANTS_MARKER, 'An owned barcode does not resolve to a known line owner');

	// Each capture invariant names the exact method that later exercises it. The claim is a fixed
	// specification later Phase 8 plans must satisfy; the suite performs no stock write and touches no
	// native Grocy table.
	$invariantClaims = [
		'Q5 open -> reviewing -> committed lifecycle' => 'SetStatus',
		'Q6 same-barcode coalescing increments one line' => 'ScanIntoTrip',
		'Q3 scan resolves known/unknown via ResolveOwner' => 'ScanIntoTrip',
		'Q10 review re-resolves unknown lines by barcode' => 'LoadTrip',
		'Q12 commit refuses on checksum mismatch before any write' => 'CommitTrip',
		'Q12 per-item applied_at idempotency' => 'CommitTrip',
		'Q9 partial commit leaves unresolved/deselected lines in the trip' => 'CommitTrip',
		'stock-write boundary: only CommitTrip writes stock' => 'CommitTrip'
	];
	captureAssert(count($invariantClaims) === 8, CAPTURE_INVARIANTS_MARKER, 'The capture invariant set is not the fixed eight-claim specification');

	// The full engine surface, including the commit-era checksum/idempotency methods: RED until the Phase 8
	// commit wave lands ChecksumForTrip / CommitTrip and turns the stock-write-exception invariants green.
	$missing = captureEngineMissingMethods();
	if ($missing !== [])
	{
		expectedRed(CAPTURE_INVARIANTS_MARKER, 'The capture engine surface (' . implode(', ', $missing) . ') is not implemented');
	}

	// --- Commit invariants (green once ChecksumForTrip / CommitTrip land) -------------------------------

	$pdo = captureFixturePdo();
	$pdo->exec("INSERT INTO product_barcodes (id, product_id, barcode) VALUES (2, 102, '4006381333931')");
	$pdo->exec("INSERT INTO product_barcodes (id, product_id, barcode, amount) VALUES (3, 103, '10012345000017', 12)");
	$fake = new CaptureFakeStockService();
	$service = new GrocyAiCaptureService($pdo, true, $fake);

	$tripId = (int)$service->StartTrip('capture-actor')['id'];
	$service->ScanIntoTrip($tripId, '012345678905', 'capture-actor');
	$service->ScanIntoTrip($tripId, '012345678905', 'capture-actor'); // coalesced → qty 2, product 101, factor 1 → amount 2
	$service->ScanIntoTrip($tripId, '4006381333931', 'capture-actor'); // qty 1, product 102, factor 6 → amount 6
	$service->ScanIntoTrip($tripId, '10012345000017', 'capture-actor');
	$service->ScanIntoTrip($tripId, '10012345000017', 'capture-actor');
	$service->ScanIntoTrip($tripId, '10012345000017', 'capture-actor'); // qty 3, product 103, barcode amount override 12 → amount 36
	$service->ScanIntoTrip($tripId, '96385074', 'capture-actor'); // unowned → unknown, selected, blocks full commit
	$service->SetTripDefaults($tripId, 5, 7, 'capture-actor');

	// Stock-write boundary: nothing before commit touched the native stock writer.
	captureAssert($fake->calls === [], CAPTURE_INVARIANTS_MARKER, 'A capture/scan/review path wrote stock before commit');

	$checksum = $service->ChecksumForTrip($tripId);
	captureAssert(preg_match('/^[0-9a-f]{64}$/D', $checksum) === 1, CAPTURE_INVARIANTS_MARKER, 'ChecksumForTrip is not a lowercase 64-hex SHA-256');

	// Q12 checksum gate: a stale/wrong confirmed checksum refuses before any write.
	$refused = $service->CommitTrip($tripId, 'capture-actor', str_repeat('0', 64));
	captureAssert($refused['outcome'] === 'checksum_mismatch' && (int)$refused['applied'] === 0 && $fake->calls === [], CAPTURE_INVARIANTS_MARKER, 'A checksum mismatch did not refuse the commit before any write');
	$racePdo = captureFixturePdo(new CaptureBeginRacePdo('sqlite::memory:'));
	$raceStock = new CaptureFakeStockService();
	$raceService = new GrocyAiCaptureService($racePdo, true, $raceStock);
	$raceTripId = (int)$raceService->StartTrip('capture-actor')['id'];
	$raceService->ScanIntoTrip($raceTripId, '012345678905', 'capture-actor');
	$raceChecksum = $raceService->ChecksumForTrip($raceTripId);
	$racePdo->beforeBegin = static function (PDO $connection): void
	{
		$connection->exec('UPDATE grocy_ai_capture_lines SET quantity = 9');
	};
	$raceResult = $raceService->CommitTrip($raceTripId, 'capture-actor', $raceChecksum);
	captureAssert($raceResult['outcome'] === 'checksum_mismatch' && $raceStock->calls === [] && !$racePdo->inTransaction(), CAPTURE_INVARIANTS_MARKER, 'A reviewed line changed before the write lock and still reached native stock');

	// Partial commit: the three selected known lines post as one native purchase batch; the selected
	// unknown line is not written and remains, so the trip stays reviewing (Q9).
	$committed = $service->CommitTrip($tripId, 'capture-actor', $checksum);
	captureAssert($committed['outcome'] === 'partial' && (int)$committed['applied'] === 3 && (int)$committed['conflicted'] === 0, CAPTURE_INVARIANTS_MARKER, 'The partial commit did not apply exactly the three selected known lines');
	captureAssert(count($fake->calls) === 3, CAPTURE_INVARIANTS_MARKER, 'The commit did not post exactly three native purchases');
	$byProduct = [];
	$transactionIds = [];
	foreach ($fake->calls as $call)
	{
		$byProduct[(int)$call['product_id']] = $call;
		$transactionIds[(string)$call['transaction_id']] = true;
		captureAssert($call['transaction_type'] === 'purchase' && (int)$call['location'] === 5 && (int)$call['store'] === 7, CAPTURE_INVARIANTS_MARKER, 'A native purchase used the wrong type/location/store');
		captureAssert(is_string($call['note']) && str_contains((string)$call['note'], (string)$tripId), CAPTURE_INVARIANTS_MARKER, 'A native purchase did not note its capture trip');
	}
	captureAssert(count($transactionIds) === 1, CAPTURE_INVARIANTS_MARKER, 'The batch did not share one transaction_id');
	// Q7: purchase→stock factor and the barcode amount override are applied at commit.
	captureAssert(abs((float)$byProduct[101]['amount'] - 2.0) < 1e-9, CAPTURE_INVARIANTS_MARKER, 'Product 101 amount is not quantity 2 × factor 1');
	captureAssert(abs((float)$byProduct[102]['amount'] - 6.0) < 1e-9, CAPTURE_INVARIANTS_MARKER, 'Product 102 amount is not quantity 1 × factor 6');
	captureAssert(abs((float)$byProduct[103]['amount'] - 36.0) < 1e-9, CAPTURE_INVARIANTS_MARKER, 'Product 103 amount is not quantity 3 × barcode override 12');

	$partialLoad = $service->LoadTrip($tripId, 'capture-actor');
	$appliedCount = 0;
	$unknownRemains = false;
	foreach ($partialLoad['lines'] as $partialLine)
	{
		if ($partialLine['applied_at'] !== null && $partialLine['outcome'] === 'applied')
		{
			$appliedCount++;
		}
		if ($partialLine['status'] === 'unknown')
		{
			$unknownRemains = true;
		}
	}
	captureAssert($appliedCount === 3 && $unknownRemains, CAPTURE_INVARIANTS_MARKER, 'The applied lines were not stamped, or the unknown line did not remain in the trip');
	captureAssert($partialLoad['trip']['status'] === 'reviewing', CAPTURE_INVARIANTS_MARKER, 'A partial commit did not leave the trip reviewing');
	captureAssert($partialLoad['trip']['transaction_id'] !== null, CAPTURE_INVARIANTS_MARKER, 'A partial commit did not record the batch transaction_id');

	// Idempotency (Q12): a re-tap with the same checksum re-applies nothing and posts no new purchase.
	$reCommit = $service->CommitTrip($tripId, 'capture-actor', $service->ChecksumForTrip($tripId));
	captureAssert((int)$reCommit['applied'] === 0 && count($fake->calls) === 3, CAPTURE_INVARIANTS_MARKER, 'Re-committing re-applied a line or posted a duplicate purchase');

	// Resolve the unknown, then a full commit archives the trip read-only with the transaction_id.
	$pdo->exec("INSERT INTO product_barcodes (id, product_id, barcode) VALUES (4, 101, '96385074')");
	$service->LoadTrip($tripId, 'capture-actor'); // Q10 re-resolve flips the unknown line to known
	$full = $service->CommitTrip($tripId, 'capture-actor', $service->ChecksumForTrip($tripId));
	captureAssert($full['outcome'] === 'committed' && (int)$full['applied'] === 1, CAPTURE_INVARIANTS_MARKER, 'The final commit did not apply the newly-resolved line and archive the trip');
	captureAssert(count($fake->calls) === 4, CAPTURE_INVARIANTS_MARKER, 'The final commit did not post the fourth purchase');
	$archived = $service->LoadTrip($tripId);
	captureAssert($archived['trip']['status'] === 'committed' && $archived['trip']['committed_at'] !== null && $archived['trip']['transaction_id'] !== null && $archived['trip']['checksum'] !== null, CAPTURE_INVARIANTS_MARKER, 'A fully committed trip is not archived with its transaction_id and checksum');
	$readOnly = false;
	try
	{
		$service->UpdateLine($tripId, 1, ['quantity' => 9], 'capture-actor');
	}
	catch (\InvalidArgumentException)
	{
		$readOnly = true;
	}
	captureAssert($readOnly, CAPTURE_INVARIANTS_MARKER, 'A committed trip is not read-only');

	// Conflict safety (Q12): a line whose owner drifted since review is recorded conflict and not written.
	$conflictPdo = captureFixturePdo();
	$conflictFake = new CaptureFakeStockService();
	$conflictService = new GrocyAiCaptureService($conflictPdo, true, $conflictFake);
	$conflictTrip = (int)$conflictService->StartTrip('capture-actor')['id'];
	$conflictService->ScanIntoTrip($conflictTrip, '012345678905', 'capture-actor');
	$conflictChecksum = $conflictService->ChecksumForTrip($conflictTrip);
	$conflictPdo->exec("UPDATE product_barcodes SET product_id = 102 WHERE barcode = '012345678905'");
	$conflictResult = $conflictService->CommitTrip($conflictTrip, 'capture-actor', $conflictChecksum);
	captureAssert((int)$conflictResult['applied'] === 0 && (int)$conflictResult['conflicted'] === 1 && $conflictFake->calls === [], CAPTURE_INVARIANTS_MARKER, 'A drifted-owner line was written instead of recorded as a conflict');
	$conflictLoad = $conflictService->LoadTrip($conflictTrip);
	captureAssert($conflictLoad['lines'][0]['outcome'] === 'conflict' && $conflictLoad['lines'][0]['applied_at'] === null, CAPTURE_INVARIANTS_MARKER, 'A conflict line was stamped applied or removed from the trip');

	fwrite(STDOUT, "Capture engine invariants passed\n");
	exit(0);
}
