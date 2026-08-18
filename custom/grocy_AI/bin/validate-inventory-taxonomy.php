<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiTaxonomyService;

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
require_once __DIR__ . '/../src/GrocyAiTaxonomyService.php';

try
{
	$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	// Bootstrap is deliberately disabled: this maintainer command is a read-only
	// validation over an already configured Grocy database.
	$report = (new GrocyAiTaxonomyService($pdo, false))->ValidateInventoryTaxonomy();
	fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
catch (Throwable)
{
	fwrite(STDERR, "Taxonomy validation could not read the configured Grocy database\n");
	exit(1);
}
