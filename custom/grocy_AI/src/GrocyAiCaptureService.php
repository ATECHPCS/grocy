<?php

namespace GrocyAI\Services;

use PDO;

/**
 * The Phase 8 purchase-capture engine (server-side capture queue).
 *
 * This plan (08-02) implements the live-connection capture core: start/close a trip, rapid-scan barcodes
 * into it (resolving known/unknown ownership per scan through the shipped `GrocyAiBarcodeService` and
 * coalescing same-barcode scans into one incrementing line), load a trip with its ordered lines
 * (re-resolving still-unknown lines by barcode, Q10), advance the trip lifecycle open -> reviewing, set
 * the trip-level location/store defaults, and edit a line (quantity/price/selection/removal). It writes
 * ONLY the namespaced `grocy_ai_capture_*` tables and one append-only audit row per mutating action — and
 * writes NO stock. The audited native purchase (the single documented stock-write exception via
 * `CommitTrip`) arrives in a later Phase 8 plan.
 */
class GrocyAiCaptureService
{
	private PDO $Db;

	/**
	 * The native purchase writer. In production this is Grocy's `StockService` (the ONLY module code
	 * allowed to write stock, and only through `CommitTrip`). Tests inject a fake object exposing the same
	 * `AddProduct(int, float, $bbd, $type, $date, $price, $loc, $store, &$transactionId, …)` signature, so
	 * the commit logic is proven without Grocy's full stock schema.
	 *
	 * @var object|null
	 */
	private $StockService;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true, $stockService = null)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		$this->StockService = $stockService;
		if ($bootstrap)
		{
			GrocyAiCaptureMigration::Bootstrap($this->Db);
		}
	}

	/**
	 * Open a fresh capture trip. Returns the closed trip DTO in `open` status.
	 *
	 * @return array<string, mixed>
	 */
	public function StartTrip(?string $actor = null): array
	{
		$moduleVersion = $this->ModuleVersion();
		$statement = $this->Db->prepare('INSERT INTO grocy_ai_capture_trips (created_by, status, module_version) VALUES (?, ?, ?)');
		$statement->execute([$actor, 'open', $moduleVersion]);
		$tripId = (int)$this->Db->lastInsertId();
		$trip = $this->FetchTrip($tripId);
		$this->WriteAudit($tripId, null, $actor, 'start_trip', null, $trip);
		return $trip;
	}

	/**
	 * Resolve a scanned barcode's ownership immediately and coalesce it into the trip: a repeated
	 * canonical/barcode increments the one existing line's quantity; a new one appends a line. Returns the
	 * coalesced line DTO. Writes no stock.
	 *
	 * @return array<string, mixed>
	 */
	public function ScanIntoTrip(int $tripId, string $barcode, ?string $actor = null): array
	{
		$trip = $this->FetchTrip($tripId);
		if ($trip['status'] === 'committed')
		{
			throw new \InvalidArgumentException('A committed trip cannot accept scans');
		}

		$resolution = $this->Resolve($barcode);

		// The coalescing bucket is the canonical GTIN when the scan has a checksum-valid form, otherwise the
		// raw scanned barcode — exactly the migration's UNIQUE index key.
		$coalesceKey = $resolution['canonical_gtin'] ?? $barcode;
		$existing = $this->Db->prepare('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ? AND COALESCE(canonical_gtin, scanned_barcode) = ?');
		$existing->execute([$tripId, $coalesceKey]);
		$existingRow = $existing->fetch(PDO::FETCH_ASSOC);

		if ($existingRow !== false)
		{
			$update = $this->Db->prepare('UPDATE grocy_ai_capture_lines SET quantity = quantity + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
			$update->execute([(int)$existingRow['id']]);
			$line = $this->FetchLineById((int)$existingRow['id']);
			$this->WriteAudit($tripId, (int)$line['id'], $actor, 'scan_coalesce', $existingRow, $line);
			return $line;
		}

		$seq = (int)$this->Db->query('SELECT COALESCE(MAX(seq), 0) + 1 FROM grocy_ai_capture_lines WHERE trip_id = ' . $tripId)->fetchColumn();
		$insert = $this->Db->prepare('INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, canonical_gtin, resolved_product_id, status, quantity, selected) VALUES (?, ?, ?, ?, ?, ?, 1, 1)');
		$insert->execute([$tripId, $seq, $barcode, $resolution['canonical_gtin'], $resolution['resolved_product_id'], $resolution['status']]);
		$line = $this->FetchLineById((int)$this->Db->lastInsertId());
		$this->WriteAudit($tripId, (int)$line['id'], $actor, 'scan_new_line', null, $line);
		return $line;
	}

	/**
	 * Load a trip and its lines ordered by seq. Still-`unknown` lines are re-resolved by barcode on load
	 * (Q10): a line whose owner now exists flips to `known` with its resolved product.
	 *
	 * @return array{trip: array<string, mixed>, lines: array<int, array<string, mixed>>}
	 */
	public function LoadTrip(int $tripId, ?string $actor = null): array
	{
		$trip = $this->FetchTrip($tripId);

		// Q10: re-resolve every still-unresolved line (unknown OR a prior conflict) by barcode, so a line
		// flips to known the moment its product + barcode exist in Grocy.
		$unknownLines = $this->Db->query('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ' . $tripId . " AND status IN ('unknown', 'conflict') ORDER BY seq")->fetchAll(PDO::FETCH_ASSOC);
		foreach ($unknownLines as $unknownRow)
		{
			$resolution = $this->Resolve((string)$unknownRow['scanned_barcode']);
			if ($resolution['status'] !== 'known' || $resolution['resolved_product_id'] === null)
			{
				continue;
			}
			$update = $this->Db->prepare("UPDATE grocy_ai_capture_lines SET status = 'known', resolved_product_id = ?, canonical_gtin = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
			$update->execute([$resolution['resolved_product_id'], $resolution['canonical_gtin'], (int)$unknownRow['id']]);
			$reresolved = $this->FetchLineById((int)$unknownRow['id']);
			$this->WriteAudit($tripId, (int)$unknownRow['id'], $actor, 'reresolve_line', $unknownRow, $reresolved);
		}

		return ['trip' => $this->FetchTrip($tripId), 'lines' => $this->FetchLines($tripId)];
	}

	/**
	 * List all capture trips, newest first, as closed trip DTOs. Read-only; writes nothing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function ListTrips(): array
	{
		return $this->Db->query('SELECT * FROM grocy_ai_capture_trips ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Advance the trip lifecycle. Only open -> reviewing is permitted here; the committed terminal state is
	 * reached exclusively through `CommitTrip` in a later plan.
	 *
	 * @return array<string, mixed>
	 */
	public function SetStatus(int $tripId, string $status, ?string $actor = null): array
	{
		$trip = $this->FetchTrip($tripId);
		if (!($trip['status'] === 'open' && $status === 'reviewing'))
		{
			throw new \InvalidArgumentException('Unsupported trip status transition');
		}
		$update = $this->Db->prepare('UPDATE grocy_ai_capture_trips SET status = ? WHERE id = ?');
		$update->execute([$status, $tripId]);
		$after = $this->FetchTrip($tripId);
		$this->WriteAudit($tripId, null, $actor, 'set_status', $trip, $after);
		return $after;
	}

	/**
	 * Set the trip-level location / shopping-location defaults (Q11). Either may be null.
	 *
	 * @return array<string, mixed>
	 */
	public function SetTripDefaults(int $tripId, ?int $locationId, ?int $shoppingLocationId, ?string $actor = null): array
	{
		$trip = $this->FetchTrip($tripId);
		$this->AssertMutable($trip);
		$update = $this->Db->prepare('UPDATE grocy_ai_capture_trips SET default_location_id = ?, default_shopping_location_id = ? WHERE id = ?');
		$update->execute([$locationId, $shoppingLocationId, $tripId]);
		$after = $this->FetchTrip($tripId);
		$this->WriteAudit($tripId, null, $actor, 'set_defaults', $trip, $after);
		return $after;
	}

	/**
	 * Edit one line of a trip: change its quantity, price, or selection, or remove it. The closed change set
	 * is `quantity` (> 0), `price` (nullable number), `selected` (bool), or `delete` (true). Returns the
	 * trip with its remaining ordered lines.
	 *
	 * @param array<string, mixed> $change
	 * @return array{trip: array<string, mixed>, lines: array<int, array<string, mixed>>}
	 */
	public function UpdateLine(int $tripId, int $seq, array $change, ?string $actor = null): array
	{
		$trip = $this->FetchTrip($tripId);
		$this->AssertMutable($trip);
		$before = $this->Db->prepare('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ? AND seq = ?');
		$before->execute([$tripId, $seq]);
		$beforeRow = $before->fetch(PDO::FETCH_ASSOC);
		if ($beforeRow === false)
		{
			throw new \InvalidArgumentException('Unknown trip line');
		}
		$lineId = (int)$beforeRow['id'];

		if (($change['delete'] ?? null) === true)
		{
			if (array_diff(array_keys($change), ['delete']) !== [])
			{
				throw new \InvalidArgumentException('delete is exclusive');
			}
			$this->Db->prepare('DELETE FROM grocy_ai_capture_lines WHERE id = ?')->execute([$lineId]);
			$this->WriteAudit($tripId, $lineId, $actor, 'delete_line', $beforeRow, null);
			return ['trip' => $this->FetchTrip($tripId), 'lines' => $this->FetchLines($tripId)];
		}

		$allowed = array_intersect_key($change, array_flip(['quantity', 'price', 'selected']));
		if ($allowed !== $change || $allowed === [])
		{
			throw new \InvalidArgumentException('Unsupported line change');
		}

		if (array_key_exists('quantity', $allowed))
		{
			if (!is_int($allowed['quantity']) && !is_float($allowed['quantity']) || $allowed['quantity'] <= 0)
			{
				throw new \InvalidArgumentException('Quantity must be a positive number');
			}
			$this->Db->prepare('UPDATE grocy_ai_capture_lines SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$allowed['quantity'], $lineId]);
		}
		if (array_key_exists('price', $allowed))
		{
			$price = $allowed['price'];
			if ($price !== null && !is_int($price) && !is_float($price) && !is_string($price))
			{
				throw new \InvalidArgumentException('Price must be a number or null');
			}
			$this->Db->prepare('UPDATE grocy_ai_capture_lines SET price = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$price, $lineId]);
		}
		if (array_key_exists('selected', $allowed))
		{
			if (!is_bool($allowed['selected']))
			{
				throw new \InvalidArgumentException('Selected must be a boolean');
			}
			$this->Db->prepare('UPDATE grocy_ai_capture_lines SET selected = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$allowed['selected'] ? 1 : 0, $lineId]);
		}

		$after = $this->FetchLineById($lineId);
		$this->WriteAudit($tripId, $lineId, $actor, 'update_line', $beforeRow, $after);
		return ['trip' => $this->FetchTrip($tripId), 'lines' => $this->FetchLines($tripId)];
	}

	/**
	 * The deterministic trip checksum: a lowercase 64-hex SHA-256 over the commit-relevant content of the
	 * selected known lines — resolved product, canonical/scanned barcode, quantity, price, and best-before
	 * override — via the canonical-JSON idiom (mirrors `GrocyAiBulkService::ChecksumForPlan`). Lines are
	 * normalized and sorted by identity, so reordering never changes it and mutating any covered value
	 * always does. The reviewed trip and the committed trip are provably the same artifact.
	 */
	public function ChecksumForTrip(int $tripId): string
	{
		$rows = $this->Db->query("SELECT resolved_product_id, canonical_gtin, scanned_barcode, quantity, price, best_before_override FROM grocy_ai_capture_lines WHERE trip_id = " . $tripId . " AND selected = 1 AND status = 'known' ORDER BY seq")->fetchAll(PDO::FETCH_ASSOC);
		$normalized = [];
		foreach ($rows as $row)
		{
			$normalized[] = [
				'product_id' => (int)$row['resolved_product_id'],
				'barcode' => $row['canonical_gtin'] !== null ? (string)$row['canonical_gtin'] : (string)$row['scanned_barcode'],
				'quantity' => (string)$row['quantity'],
				'price' => $row['price'] === null ? null : (string)$row['price'],
				'best_before_override' => $row['best_before_override'] === null ? null : (string)$row['best_before_override']
			];
		}
		usort($normalized, static fn(array $left, array $right): int =>
			[$left['product_id'], $left['barcode']] <=> [$right['product_id'], $right['barcode']]);

		return hash('sha256', $this->CanonicalJson(['version' => GrocyAiCaptureMigration::VERSION, 'lines' => $normalized]));
	}

	/**
	 * Commit a reviewed trip to real Grocy stock — the ONLY module path that writes stock (CAP-05..CAP-08).
	 *
	 * Mirrors `GrocyAiBulkService::ApplyPlan`: the recomputed checksum is `hash_equals`-checked against the
	 * caller's confirmed checksum before and after acquiring the write lock, refusing BEFORE any write;
	 * the write set is taken under one raw
	 * `BEGIN IMMEDIATE` with a single `COMMIT` (or `ROLLBACK` on any `\Throwable`), never PDO's
	 * begin/commit. Only selected known lines with a null `applied_at` are written; each is re-resolved
	 * in-lock (a changed owner is recorded `conflict` and skipped), its stock amount is
	 * `quantity × (barcode amount override | product purchase→stock factor)`, and it is posted through
	 * `StockService::AddProduct('purchase', …)` sharing one `transaction_id`, then stamped `applied_at`.
	 * Deselected / unknown / conflict lines are never written and remain in the trip (partial commit). A
	 * re-tap is idempotent: an already-applied line is skipped. When every selected line is applied the trip
	 * archives `committed` (+ `committed_at` + `transaction_id` + `checksum`), read-only; otherwise it stays
	 * `reviewing`. No network call happens under the lock.
	 *
	 * @return array<string, mixed> the commit result DTO
	 */
	public function CommitTrip(int $tripId, ?string $actor, string $confirmedChecksum): array
	{
		$trip = $this->FetchTrip($tripId);
		$status = (string)$trip['status'];
		$recomputed = $this->ChecksumForTrip($tripId);

		// Bind the committed trip to the reviewed one before any write.
		if ($status === 'committed' || !hash_equals($recomputed, $confirmedChecksum))
		{
			return $this->CommitResult($tripId, $status, $status === 'committed' ? ($trip['transaction_id'] === null ? null : (string)$trip['transaction_id']) : null, $recomputed, $status === 'committed' ? 'already_committed' : 'checksum_mismatch', 0, 0, 0);
		}

		$stock = $this->StockService ?? \Grocy\Services\StockService::GetInstance();
		$today = date('Y-m-d');
		$location = $trip['default_location_id'] === null ? null : (int)$trip['default_location_id'];
		$store = $trip['default_shopping_location_id'] === null ? null : (int)$trip['default_shopping_location_id'];
		$note = 'grocy_AI purchase capture trip #' . $tripId;

		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$lockedTrip = $this->FetchTrip($tripId);
			$lockedStatus = (string)$lockedTrip['status'];
			$lockedChecksum = $this->ChecksumForTrip($tripId);
			if ($lockedStatus === 'committed' || !hash_equals($lockedChecksum, $confirmedChecksum))
			{
				$this->Db->exec('ROLLBACK');
				return $this->CommitResult($tripId, $lockedStatus, $lockedTrip['transaction_id'] === null ? null : (string)$lockedTrip['transaction_id'], $lockedChecksum, $lockedStatus === 'committed' ? 'already_committed' : 'checksum_mismatch', 0, 0, 0);
			}

			$appliedAt = (string)$this->Db->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
			$auditInsert = $this->Db->prepare('INSERT INTO grocy_ai_capture_audit (trip_id, line_id, actor, action, before_json, after_json, transaction_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
			$markConflict = $this->Db->prepare("UPDATE grocy_ai_capture_lines SET status = 'conflict', outcome = 'conflict', updated_at = ? WHERE id = ?");
			$markApplied = $this->Db->prepare("UPDATE grocy_ai_capture_lines SET outcome = 'applied', applied_at = ?, updated_at = ? WHERE id = ?");

			$selected = $this->Db->query("SELECT * FROM grocy_ai_capture_lines WHERE trip_id = " . $tripId . " AND selected = 1 AND status = 'known' AND applied_at IS NULL ORDER BY seq")->fetchAll(PDO::FETCH_ASSOC);

			$transactionId = null;
			$applied = 0;
			$conflicted = 0;
			$skipped = 0;
			foreach ($selected as $row)
			{
				$lineId = (int)$row['id'];
				$productId = (int)$row['resolved_product_id'];

				// In-lock optimistic-concurrency re-resolve (Q12): a line whose owner drifted since review is
				// recorded conflict and never written; it stays in the trip for the next review/re-resolve.
				$resolution = $this->Resolve((string)$row['scanned_barcode']);
				if ($resolution['status'] !== 'known' || $resolution['resolved_product_id'] !== $productId)
				{
					$markConflict->execute([$appliedAt, $lineId]);
					$auditInsert->execute([$tripId, $lineId, (string)($actor ?? ''), 'commit_conflict', $this->CanonicalJson($row), null, null, $appliedAt]);
					$conflicted++;
					continue;
				}

				$amount = (float)$row['quantity'] * $this->StockMultiplier($productId, $row['canonical_gtin'] !== null ? (string)$row['canonical_gtin'] : (string)$row['scanned_barcode']);
				$bestBefore = $row['best_before_override'] === null ? null : (string)$row['best_before_override'];
				$price = $row['price'] === null ? null : (string)$row['price'];

				// The single documented stock-write exception. Shares one transaction_id across the batch; the
				// native API auto-computes best-before from default_best_before_days when null. No network here.
				// The native purchase transaction type ('purchase' == StockService::TRANSACTION_TYPE_PURCHASE);
				// kept as the literal so the commit path never hard-loads the framework under an injected fake.
				$stock->AddProduct($productId, $amount, $bestBefore, 'purchase', $today, $price, $location, $store, $transactionId, 0, false, $note);

				$markApplied->execute([$appliedAt, $appliedAt, $lineId]);
				$after = $this->FetchLineByIdRaw($lineId);
				$auditInsert->execute([$tripId, $lineId, (string)($actor ?? ''), 'commit_line', $this->CanonicalJson($row), $this->CanonicalJson($after), $transactionId, $appliedAt]);
				$applied++;
			}

			// Every selected line applied (none left unresolved/unapplied) → archive committed, read-only.
			$outstanding = (int)$this->Db->query('SELECT COUNT(*) FROM grocy_ai_capture_lines WHERE trip_id = ' . $tripId . ' AND selected = 1 AND applied_at IS NULL')->fetchColumn();
			$fullyCommitted = $outstanding === 0;

			if ($applied > 0 || $conflicted > 0)
			{
				if ($fullyCommitted)
				{
					$this->Db->prepare("UPDATE grocy_ai_capture_trips SET status = 'committed', committed_at = ?, transaction_id = ?, checksum = ? WHERE id = ?")
						->execute([$appliedAt, $transactionId, $recomputed, $tripId]);
				}
				elseif ($transactionId !== null)
				{
					// Partial commit (Q9): work landed but selected lines remain — keep the trip open for review
					// and record the batch transaction_id.
					$this->Db->prepare("UPDATE grocy_ai_capture_trips SET status = 'reviewing', transaction_id = ? WHERE id = ?")->execute([$transactionId, $tripId]);
				}
				else
				{
					$this->Db->prepare("UPDATE grocy_ai_capture_trips SET status = 'reviewing' WHERE id = ?")->execute([$tripId]);
				}
				$auditInsert->execute([$tripId, null, (string)($actor ?? ''), 'commit_trip', $this->CanonicalJson(['status' => $status, 'confirmed_checksum' => $confirmedChecksum]), $this->CanonicalJson(['status' => $fullyCommitted ? 'committed' : 'reviewing', 'applied' => $applied, 'conflicted' => $conflicted]), $transactionId, $appliedAt]);
			}

			$this->Db->exec('COMMIT');

			$finalStatus = $fullyCommitted ? 'committed' : 'reviewing';
			return $this->CommitResult($tripId, $finalStatus, $transactionId, $recomputed, $fullyCommitted ? 'committed' : 'partial', $applied, $conflicted, $skipped);
		}
		catch (\Throwable $exception)
		{
			$this->Db->exec('ROLLBACK');
			return $this->CommitResult($tripId, $status, null, $recomputed, 'commit_failed', 0, 0, 0);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function CommitResult(int $tripId, string $status, ?string $transactionId, string $checksum, string $outcome, int $applied, int $conflicted, int $skipped): array
	{
		return [
			'trip_id' => $tripId,
			'status' => $status,
			'transaction_id' => $transactionId,
			'checksum' => $checksum,
			'outcome' => $outcome,
			'applied' => $applied,
			'conflicted' => $conflicted,
			'skipped' => $skipped
		];
	}

	/**
	 * The per-scan stock multiplier (Q7): the scanned barcode's own `product_barcodes.amount` override when
	 * present, otherwise the product's `qu_factor_purchase_to_stock`. Defaults to 1 when neither is a
	 * positive number, so a missing factor never zeroes or reverses a purchase.
	 */
	private function StockMultiplier(int $productId, string $barcode): float
	{
		$canonical = GrocyAiGtin::CanonicalOrNull($barcode);
		if ($canonical !== null)
		{
			$expression = GrocyAiGtin::CanonicalSqlExpression('barcode');
			$override = $this->Db->prepare('SELECT amount FROM product_barcodes WHERE product_id = :pid AND ' . $expression . ' = :gtin AND amount IS NOT NULL AND amount > 0 ORDER BY id LIMIT 1');
			$override->execute(['pid' => $productId, 'gtin' => $canonical]);
			$amount = $override->fetchColumn();
			if ($amount !== false && (float)$amount > 0)
			{
				return (float)$amount;
			}
		}

		$factorStatement = $this->Db->prepare('SELECT qu_factor_purchase_to_stock FROM products WHERE id = ?');
		$factorStatement->execute([$productId]);
		$factor = $factorStatement->fetchColumn();
		return $factor !== false && (float)$factor > 0 ? (float)$factor : 1.0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function FetchLineByIdRaw(int $lineId): array
	{
		$statement = $this->Db->prepare('SELECT * FROM grocy_ai_capture_lines WHERE id = ?');
		$statement->execute([$lineId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		return $row === false ? [] : $row;
	}

	/**
	 * @param array<string, mixed> $trip
	 */
	private function AssertMutable(array $trip): void
	{
		if ((string)$trip['status'] === 'committed')
		{
			throw new \InvalidArgumentException('A committed trip is read-only');
		}
	}

	/**
	 * Resolve a scanned barcode through the shipped ownership read. A checksum-valid owned barcode is a
	 * `known` line with its owner; a checksum-valid unused barcode is an `unknown` line carrying its
	 * canonical form; a barcode with no checksum-valid GTIN is an `unknown` line with a null canonical
	 * (coalesced on its raw value).
	 *
	 * @return array{status: string, resolved_product_id: ?int, canonical_gtin: ?string}
	 */
	private function Resolve(string $barcode): array
	{
		try
		{
			$ownership = $this->Barcode()->ResolveOwner($barcode);
		}
		catch (\InvalidArgumentException)
		{
			return ['status' => 'unknown', 'resolved_product_id' => null, 'canonical_gtin' => null];
		}

		$known = $ownership['owner_product_id'] !== null;
		return [
			'status' => $known ? 'known' : 'unknown',
			'resolved_product_id' => $known ? (int)$ownership['owner_product_id'] : null,
			'canonical_gtin' => (string)$ownership['canonical_gtin']
		];
	}

	private function Barcode(): GrocyAiBarcodeService
	{
		$db = $this->Db;
		$ownerLookup = static function (string $canonicalGtin, string $sqlExpression) use ($db): array
		{
			$query = $db->prepare(
				'SELECT pb.product_id, p.name AS owner_label'
				. ' FROM product_barcodes pb'
				. ' JOIN products p ON p.id = pb.product_id'
				. ' WHERE ' . $sqlExpression . ' = :canonical_gtin'
				. ' ORDER BY pb.id LIMIT 2'
			);
			$query->execute(['canonical_gtin' => $canonicalGtin]);
			return $query->fetchAll(PDO::FETCH_ASSOC);
		};
		return new GrocyAiBarcodeService($ownerLookup, null);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function FetchTrip(int $tripId): array
	{
		$statement = $this->Db->prepare('SELECT * FROM grocy_ai_capture_trips WHERE id = ?');
		$statement->execute([$tripId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if ($row === false)
		{
			throw new \InvalidArgumentException('Unknown capture trip');
		}
		return $row;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function FetchLineById(int $lineId): array
	{
		$statement = $this->Db->prepare('SELECT * FROM grocy_ai_capture_lines WHERE id = ?');
		$statement->execute([$lineId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if ($row === false)
		{
			throw new \InvalidArgumentException('Unknown capture line');
		}
		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function FetchLines(int $tripId): array
	{
		return $this->Db->query('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ' . $tripId . ' ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 */
	private function WriteAudit(int $tripId, ?int $lineId, ?string $actor, string $action, ?array $before, ?array $after): void
	{
		$statement = $this->Db->prepare('INSERT INTO grocy_ai_capture_audit (trip_id, line_id, actor, action, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)');
		$statement->execute([
			$tripId,
			$lineId,
			(string)($actor ?? ''),
			$action,
			$before === null ? null : $this->CanonicalJson($before),
			$after === null ? null : $this->CanonicalJson($after)
		]);
	}

	private function ModuleVersion(): string
	{
		$path = __DIR__ . '/../module-version.json';
		$data = is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
		return (string)($data['module_version'] ?? '');
	}

	private function CanonicalJson(mixed $value): string
	{
		return (string)json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}
}
