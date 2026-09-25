<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiConversionService;

$dataPath = getenv('GROCY_DATAPATH');
if (!is_string($dataPath) || $dataPath === '' || $dataPath[0] !== '/')
{
	fwrite(STDERR, "GROCY_DATAPATH must be an absolute configured Grocy data path\n");
	exit(2);
}

$databasePath = rtrim($dataPath, '/') . '/grocy.db';
if (!is_file($databasePath) || !is_readable($databasePath))
{
	fwrite(STDERR, "Configured Grocy database is unavailable\n");
	exit(2);
}

require_once __DIR__ . '/../src/GrocyAiTaxonomyMigration.php';
require_once __DIR__ . '/../src/GrocyAiConversionMigration.php';
require_once __DIR__ . '/../src/GrocyAiConversionService.php';

try
{
	$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	// Read-only by construction: bootstrap is disabled and the connection is put into
	// query_only mode, so this maintainer command cannot migrate, project, or activate.
	$pdo->exec('PRAGMA query_only = ON');
	$report = (new GrocyAiConversionService($pdo, false))->ValidateConversionCoverage();
	fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
catch (Throwable)
{
	fwrite(STDERR, "Conversion validation could not read the configured Grocy database\n");
	exit(1);
}
