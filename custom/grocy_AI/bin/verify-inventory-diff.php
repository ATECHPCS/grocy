<?php

declare(strict_types=1);

/**
 * verify-inventory-diff.php — DATA-06 per-table zero-diff harness (Phase 6 / 06-02).
 *
 * Computes a deterministic per-table {row_count, per-column content hash} manifest
 * of a SQLite database, and diffs two manifests against an allowlist so that
 * applying a Phase 6 bulk plan can be PROVEN to change only approved
 * tables/columns and leave every other table byte-identical.
 *
 * Library (also required by tests/inventory_diff.php):
 *   inventoryDiffManifest(PDO): array                         — {table => {count, columns:{col=>hash}, keyed:bool}}
 *   inventoryDiffCompare(before, after, appdTables, appdCols)  — {changes:[...], violations:[...]}
 *
 * CLI:
 *   verify-inventory-diff.php --db PATH --before MANIFEST.json
 *   verify-inventory-diff.php --db PATH --after  MANIFEST.json \
 *        [--approved-tables a,b] [--approved-columns t.c,t.d]
 *   verify-inventory-diff.php --db PATH --print
 *
 * --before writes a manifest and exits 0. --after recomputes, diffs against the
 * saved manifest, prints any changes, and exits NON-ZERO if any change falls
 * outside the allowlist (naming the offending table/column). Comparing an
 * unchanged DB yields an empty diff and exit 0.
 *
 * Deterministic: the manifest is independent of physical row order. Rows are
 * identified by the table's PRIMARY KEY (falling back to the whole-row tuple when
 * a table has none), so a single-cell change is attributed to exactly its column.
 */

function inventoryDiffQuoteIdent(string $ident): string
{
	return '"' . str_replace('"', '""', $ident) . '"';
}

/** User tables only — never the sqlite_* internals (sqlite_sequence etc.). */
function inventoryDiffTables(PDO $pdo): array
{
	$rows = $pdo
		->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
		->fetchAll(PDO::FETCH_COLUMN);
	return array_map('strval', $rows);
}

function inventoryDiffColumns(PDO $pdo, string $table): array
{
	$info = $pdo->query('PRAGMA table_info(' . inventoryDiffQuoteIdent($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
	return array_map(static fn(array $c): string => (string)$c['name'], $info);
}

/** PK column names ordered by pk index; [] when the table has no declared primary key. */
function inventoryDiffKeyColumns(PDO $pdo, string $table): array
{
	$info = $pdo->query('PRAGMA table_info(' . inventoryDiffQuoteIdent($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
	$pk = [];
	foreach ($info as $c)
	{
		$pos = (int)$c['pk'];
		if ($pos > 0)
		{
			$pk[$pos] = (string)$c['name'];
		}
	}
	ksort($pk);
	return array_values($pk);
}

/** Type-tagged scalar rendering so null, '', '0' and 0 never collide in a hash. */
function inventoryDiffScalar(mixed $value): string
{
	if ($value === null)
	{
		return "\x00N";
	}
	if (is_int($value))
	{
		return 'i:' . $value;
	}
	if (is_float($value))
	{
		// Canonical, round-trippable float text.
		return 'f:' . rtrim(rtrim(sprintf('%.17g', $value), '0'), '.');
	}
	return 's:' . (string)$value;
}

/**
 * Deterministic per-table {count, per-column hash} manifest. Independent of
 * physical row order: each cell is bound to its row identity (PK, else whole
 * row) and the per-column (identity,value) pairs are sorted before hashing.
 */
function inventoryDiffManifest(PDO $pdo): array
{
	$manifest = [];
	foreach (inventoryDiffTables($pdo) as $table)
	{
		$columns = inventoryDiffColumns($pdo, $table);
		$keyColumns = inventoryDiffKeyColumns($pdo, $table);
		$identityColumns = $keyColumns !== [] ? $keyColumns : $columns;

		$rows = $pdo->query('SELECT * FROM ' . inventoryDiffQuoteIdent($table))->fetchAll(PDO::FETCH_ASSOC);

		$pairsByColumn = array_fill_keys($columns, []);
		foreach ($rows as $row)
		{
			$idParts = [];
			foreach ($identityColumns as $ic)
			{
				$idParts[] = inventoryDiffScalar($row[$ic] ?? null);
			}
			$identity = implode("\x1f", $idParts);
			foreach ($columns as $column)
			{
				$pairsByColumn[$column][] = $identity . "\x1e" . inventoryDiffScalar($row[$column] ?? null);
			}
		}

		$columnHashes = [];
		foreach ($columns as $column)
		{
			sort($pairsByColumn[$column], SORT_STRING);
			$columnHashes[$column] = hash('sha256', implode("\x1d", $pairsByColumn[$column]));
		}

		$manifest[$table] = [
			'count' => count($rows),
			'columns' => $columnHashes,
			'keyed' => $keyColumns !== [],
		];
	}
	return $manifest;
}

/**
 * Diff two manifests against an allowlist.
 *
 * @param string[] $approvedTables   table names allowed to change in any way
 * @param string[] $approvedColumns  "table.column" cell changes allowed (row add/remove still needs table approval)
 * @return array{changes: list<array>, violations: list<array>}
 */
function inventoryDiffCompare(array $before, array $after, array $approvedTables = [], array $approvedColumns = []): array
{
	$approvedTables = array_fill_keys($approvedTables, true);
	$approvedColumns = array_fill_keys($approvedColumns, true);

	$tables = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
	sort($tables, SORT_STRING);

	$changes = [];
	$violations = [];

	foreach ($tables as $table)
	{
		$b = $before[$table] ?? null;
		$a = $after[$table] ?? null;

		if ($b === null)
		{
			$change = ['table' => $table, 'kind' => 'table_created', 'columns' => []];
		}
		elseif ($a === null)
		{
			$change = ['table' => $table, 'kind' => 'table_dropped', 'columns' => []];
		}
		else
		{
			$changedColumns = [];
			foreach ($a['columns'] as $column => $hash)
			{
				if (!array_key_exists($column, $b['columns']) || $b['columns'][$column] !== $hash)
				{
					$changedColumns[] = $column;
				}
			}
			foreach ($b['columns'] as $column => $hash)
			{
				if (!array_key_exists($column, $a['columns']))
				{
					$changedColumns[] = $column;
				}
			}
			$countChanged = $a['count'] !== $b['count'];
			if (!$countChanged && $changedColumns === [])
			{
				continue;
			}
			$change = [
				'table' => $table,
				'kind' => $countChanged ? 'rows_changed' : 'cells_changed',
				'columns' => array_values(array_unique($changedColumns)),
				'count_before' => $b['count'],
				'count_after' => $a['count'],
			];
		}

		$changes[] = $change;

		if (isset($approvedTables[$table]))
		{
			continue;
		}

		// Structural changes (table created/dropped, rows added/removed) require
		// table-level approval; a column allowlist alone does not cover them.
		if ($change['kind'] !== 'cells_changed')
		{
			$violations[] = $change;
			continue;
		}

		$unapproved = [];
		foreach ($change['columns'] as $column)
		{
			if (!isset($approvedColumns[$table . '.' . $column]))
			{
				$unapproved[] = $column;
			}
		}
		if ($unapproved !== [])
		{
			$change['unapproved_columns'] = $unapproved;
			$violations[] = $change;
		}
	}

	return ['changes' => $changes, 'violations' => $violations];
}

// --------------------------------------------------------------------------
// CLI (only when executed directly; requiring this file just loads the library)
// --------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__))
{
	inventoryDiffCli($argv);
}

function inventoryDiffCliArg(array $argv, string $name, ?string $default = null): ?string
{
	$flag = '--' . $name;
	foreach ($argv as $i => $a)
	{
		if ($a === $flag)
		{
			return $argv[$i + 1] ?? '';
		}
		if (str_starts_with($a, $flag . '='))
		{
			return substr($a, strlen($flag) + 1);
		}
	}
	return $default;
}

function inventoryDiffCli(array $argv): never
{
	$dbPath = inventoryDiffCliArg($argv, 'db');
	if ($dbPath === null || $dbPath === '')
	{
		fwrite(STDERR, "usage: verify-inventory-diff.php --db PATH (--before FILE | --after FILE [--approved-tables a,b] [--approved-columns t.c]) | --print\n");
		exit(2);
	}
	if (!is_file($dbPath))
	{
		fwrite(STDERR, "db not found: {$dbPath}\n");
		exit(2);
	}

	$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec('PRAGMA query_only = 1');
	$manifest = inventoryDiffManifest($pdo);

	$beforePath = inventoryDiffCliArg($argv, 'before');
	$afterPath = inventoryDiffCliArg($argv, 'after');

	if (in_array('--print', $argv, true))
	{
		fwrite(STDOUT, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
		exit(0);
	}

	if ($beforePath !== null && $beforePath !== '')
	{
		file_put_contents($beforePath, json_encode($manifest, JSON_THROW_ON_ERROR));
		fwrite(STDERR, "[inventory-diff] wrote before-manifest: {$beforePath} (" . count($manifest) . " tables)\n");
		exit(0);
	}

	if ($afterPath !== null && $afterPath !== '')
	{
		if (!is_file($afterPath))
		{
			fwrite(STDERR, "before-manifest not found: {$afterPath}\n");
			exit(2);
		}
		$before = json_decode((string)file_get_contents($afterPath), true, 512, JSON_THROW_ON_ERROR);
		$approvedTables = array_filter(array_map('trim', explode(',', (string)inventoryDiffCliArg($argv, 'approved-tables', ''))));
		$approvedColumns = array_filter(array_map('trim', explode(',', (string)inventoryDiffCliArg($argv, 'approved-columns', ''))));
		$result = inventoryDiffCompare($before, $manifest, array_values($approvedTables), array_values($approvedColumns));

		foreach ($result['changes'] as $c)
		{
			$cols = $c['columns'] === [] ? '' : ' [' . implode(',', $c['columns']) . ']';
			fwrite(STDERR, "[inventory-diff] changed: {$c['table']} ({$c['kind']}){$cols}\n");
		}
		if ($result['violations'] !== [])
		{
			foreach ($result['violations'] as $v)
			{
				$cols = isset($v['unapproved_columns']) ? ' columns=' . implode(',', $v['unapproved_columns']) : '';
				fwrite(STDERR, "[inventory-diff] VIOLATION: {$v['table']} ({$v['kind']}){$cols}\n");
			}
			fwrite(STDERR, '[inventory-diff] ' . count($result['violations']) . " unapproved change(s)\n");
			exit(1);
		}
		fwrite(STDERR, "[inventory-diff] OK: " . count($result['changes']) . " change(s), all within the allowlist\n");
		exit(0);
	}

	fwrite(STDERR, "nothing to do: pass --before, --after, or --print\n");
	exit(2);
}
