<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiCaptureResearchMigration;
use GrocyAI\Services\GrocyAiCaptureResearchService;
use GrocyAI\Services\GrocyAiCaptureService;

// The concurrency assertion is a required gate, so unsupported runtimes fail explicitly.
$requiredFunctions = ['pcntl_fork', 'pcntl_waitpid', 'pcntl_wexitstatus', 'stream_socket_pair'];
if (PHP_OS_FAMILY === 'Windows' || !defined('STREAM_PF_UNIX') || array_filter($requiredFunctions, static fn(string $name): bool => !function_exists($name)) !== [])
{
	fwrite(STDERR, "capture research queue requires pcntl_fork, pcntl_waitpid, pcntl_wexitstatus and Unix stream sockets; run on a supported Unix PHP CLI runtime\n");
	exit(1);
}
$prerequisiteSockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
if ($prerequisiteSockets === false)
{
	fwrite(STDERR, "capture research queue requires working Unix stream sockets\n");
	exit(1);
}
foreach ($prerequisiteSockets as $socket) fclose($socket);

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService'] as $file)
{
	$path = __DIR__ . '/../src/' . $file . '.php';
	if (is_file($path)) require_once $path;
}

function checkResearch(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function rejectsResearch(callable $operation, string $message): void
{
	try { $operation(); } catch (PDOException $expected) { return; }
	throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
$db->exec("INSERT INTO products VALUES (1, 'Owned')");
$db->exec("INSERT INTO product_barcodes VALUES (1, 1, '012345678905')");
GrocyAiCaptureMigration::Bootstrap($db);
GrocyAI\Services\GrocyAiReceiptMigration::Bootstrap($db);
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (9, 'reviewing', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, status) VALUES (90, 9, 1, 'bad', 'unknown')");
$db->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (91, 9, 'existing-image', 'image/png', 5)");
$before = $db->query('SELECT * FROM grocy_ai_capture_lines WHERE id = 90')->fetch(PDO::FETCH_ASSOC);
GrocyAiCaptureResearchMigration::Bootstrap($db);
GrocyAiCaptureResearchMigration::Bootstrap($db);
checkResearch($db->query('SELECT * FROM grocy_ai_capture_lines WHERE id = 90')->fetch(PDO::FETCH_ASSOC) === $before, 'bootstrap preserves capture rows');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_receipts WHERE id = 91')->fetchColumn() === 1, 'bootstrap preserves receipt rows');

$capture = new GrocyAiCaptureService($db);
$oldUrl = getenv('GROCY_AI_SERVICE_URL');
putenv('GROCY_AI_SERVICE_URL=http://10.255.255.1:81');
$scanStarted = microtime(true);
$first = $capture->ScanIntoTrip(9, '4006381333931');
checkResearch(microtime(true) - $scanStarted < 1.0, 'unavailable provider does not delay a scan');
if ($oldUrl === false) putenv('GROCY_AI_SERVICE_URL');
else putenv('GROCY_AI_SERVICE_URL=' . $oldUrl);
$again = $capture->ScanIntoTrip(9, '04006381333931');
checkResearch((int)$first['id'] === (int)$again['id'] && (int)$again['quantity'] === 2, 'equivalent GTIN scans coalesce');
checkResearch(array_keys($first) === array_keys($again), 'scan DTO shape stays fixed');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 1, 'one job per canonical GTIN');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts')->fetchColumn() === 1, 'one draft per line');
$job = $db->query('SELECT * FROM grocy_ai_capture_research_jobs')->fetch(PDO::FETCH_ASSOC);
checkResearch($job['canonical_gtin'] === '04006381333931' && $job['state'] === 'queued' && (int)$job['attempts'] === 0, 'job starts queued');
$drafts = (new GrocyAiCaptureResearchService($db))->DraftsForTrip(9);
checkResearch(count($drafts) === 1 && (int)$drafts[0]['line_id'] === (int)$first['id'], 'trip draft is visible');
checkResearch(!array_key_exists('lease_hash', $drafts[0]), 'browser draft omits lease secret');

$capture->ScanIntoTrip(9, '012345678905');
$capture->ScanIntoTrip(9, '4006381333932');
$capture->ScanIntoTrip(9, 'unsupported');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 1, 'known and invalid scans never queue');
checkResearch((int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'scan never books stock');

$secondTrip = $capture->StartTrip();
$capture->ScanIntoTrip((int)$secondTrip['id'], '4006381333931');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 1, 'jobs are shared across trips');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts')->fetchColumn() === 2, 'each capture line gets its own review draft');
$otherLineId = (int)$db->query('SELECT id FROM grocy_ai_capture_lines WHERE trip_id = ' . (int)$secondTrip['id'])->fetchColumn();
rejectsResearch(fn() => $db->exec("UPDATE grocy_ai_capture_research_drafts SET trip_id = 9 WHERE line_id = $otherLineId"), 'draft cannot reference a line from another trip');
$db->exec("INSERT INTO grocy_ai_receipt_lines (id, receipt_id, seq, description) VALUES (92, 91, 1, 'item')");
rejectsResearch(fn() => $db->exec("UPDATE grocy_ai_capture_research_drafts SET trip_id = " . (int)$secondTrip['id'] . ", receipt_line_id = 92 WHERE line_id = $otherLineId"), 'draft cannot reference receipt evidence from another trip');
$firstDraftId = (int)$db->query('SELECT id FROM grocy_ai_capture_research_drafts WHERE line_id = ' . (int)$first['id'])->fetchColumn();
$db->exec("UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = 92 WHERE id = $firstDraftId");
rejectsResearch(fn() => $db->exec('UPDATE grocy_ai_receipts SET trip_id = ' . (int)$secondTrip['id'] . ' WHERE id = 91'), 'moving receipt cannot strand linked draft in another trip');
$otherDraftId = (int)$db->query('SELECT id FROM grocy_ai_capture_research_drafts WHERE line_id = ' . $otherLineId)->fetchColumn();
rejectsResearch(fn() => $db->exec("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action) VALUES (9, $otherDraftId, 'test', 'bad')"), 'audit cannot reference a draft from another trip');
$db->exec('DELETE FROM grocy_ai_capture_lines WHERE id = ' . (int)$first['id']);
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_audit')->fetchColumn() === 2, 'audit survives capture-line deletion');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE line_id IS NULL')->fetchColumn() === 1, 'deleted line clears only the live association');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = NULL WHERE id = $firstDraftId");
rejectsResearch(fn() => $db->exec('UPDATE grocy_ai_capture_research_drafts SET trip_id = ' . (int)$secondTrip['id'] . " WHERE id = $firstDraftId"), 'draft cannot move away from its audit trip');

try
{
	$db->exec('DELETE FROM grocy_ai_capture_research_audit');
	throw new RuntimeException('audit delete was allowed');
}
catch (PDOException $expected) {}

$db->exec("CREATE TRIGGER reject_research_queue BEFORE INSERT ON grocy_ai_capture_research_jobs BEGIN SELECT RAISE(ABORT, 'forced queue failure'); END");
$failedTrip = $capture->StartTrip();
$failedTripId = (int)$failedTrip['id'];
rejectsResearch(fn() => $capture->ScanIntoTrip($failedTripId, '96385074'), 'queue failure propagates');
checkResearch((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_lines WHERE trip_id = $failedTripId")->fetchColumn() === 0, 'queue failure rolls back new line');
checkResearch((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_audit WHERE trip_id = $failedTripId AND action = 'scan_new_line'")->fetchColumn() === 0, 'queue failure rolls back scan audit');
$db->exec('DROP TRIGGER reject_research_queue');
$retried = $capture->ScanIntoTrip($failedTripId, '96385074');
checkResearch((int)$retried['quantity'] === 1 && count((new GrocyAiCaptureResearchService($db))->DraftsForTrip($failedTripId)) === 1, 'retry creates one line and one draft');

$repairTrip = $capture->StartTrip();
$repairTripId = (int)$repairTrip['id'];
$db->prepare("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (?, 1, '96385074', '00000096385074', 'unknown')")->execute([$repairTripId]);
$repaired = $capture->ScanIntoTrip($repairTripId, '96385074');
checkResearch((int)$repaired['quantity'] === 2 && count((new GrocyAiCaptureResearchService($db))->DraftsForTrip($repairTripId)) === 1, 'coalesced legacy unknown line repairs missing draft');

// Removing the durable gate or its live eligibility checks must fail these tests.
checkResearch(method_exists(GrocyAiCaptureResearchService::class, 'ReserveWebSearch'), 'paid web search reservation gate exists');
define('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT', 2);
$research = new GrocyAiCaptureResearchService($db);
$claims = $research->ClaimJobs(5, 'reservation-test');
checkResearch(count($claims) === 2, 'reservation fixtures claim two shared jobs');
$a = $claims[0];
$b = $claims[1];
function deniesResearch(callable $operation, string $message): void
{
	try { $operation(); } catch (InvalidArgumentException|RuntimeException $expected) { return; }
	throw new RuntimeException($message);
}
deniesResearch(fn() => $research->ReserveWebSearch($a['id'], str_repeat('0', 64), 'worker'), 'invalid lease denied');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET lease_expires_at = datetime('now', '-1 second') WHERE id = " . $a['id']);
deniesResearch(fn() => $research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker'), 'expired lease denied');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET lease_expires_at = datetime('now', '+60 seconds') WHERE id = " . $a['id']);
$db->exec('UPDATE grocy_ai_capture_lines SET selected = 0 WHERE id = ' . $otherLineId);
checkResearch(!$research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker')['allowed'], 'deselected line denied');
$db->exec('UPDATE grocy_ai_capture_lines SET selected = 1 WHERE id = ' . $otherLineId);
$db->exec("UPDATE grocy_ai_capture_trips SET status = 'committed' WHERE id = " . (int)$secondTrip['id']);
checkResearch(!$research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker')['allowed'], 'committed trip denied');
$db->exec("UPDATE grocy_ai_capture_trips SET status = 'open' WHERE id = " . (int)$secondTrip['id']);
$db->exec("UPDATE grocy_ai_capture_lines SET scanned_barcode = 'unsupported' WHERE id = " . $otherLineId);
checkResearch(!$research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker')['allowed'], 'unsupported line denied');
$db->exec("UPDATE grocy_ai_capture_lines SET scanned_barcode = '4006381333931' WHERE id = " . $otherLineId);
$db->exec("UPDATE grocy_ai_capture_research_drafts SET outcome = 'approved' WHERE line_id = " . $otherLineId);
checkResearch(!$research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker')['allowed'], 'finalized draft denied even if stale line remains unknown');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET outcome = 'pending' WHERE line_id = " . $otherLineId);
// A failed eligibility query is infrastructure failure, never a normal denial.
$db->exec('ALTER TABLE grocy_ai_capture_lines RENAME TO unavailable_capture_lines');
rejectsResearch(fn() => $research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker'), 'eligibility database error must propagate');
checkResearch(!$db->inTransaction(), 'eligibility database error rolls back reservation transaction');
$db->exec('ALTER TABLE unavailable_capture_lines RENAME TO grocy_ai_capture_lines');
$reservation = $research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker');
checkResearch($reservation['allowed'] && is_int($reservation['reservation_id']), 'first generation reserves');
// Race two processes for the final daily slot using independent SQLite connections.
$concurrentTrip = $capture->StartTrip();
$capture->ScanIntoTrip((int)$concurrentTrip['id'], '5901234123457');
$c = $research->ClaimJobs(1, 'concurrent')[0];
$racePath = tempnam(sys_get_temp_dir(), 'grocy-reservation-');
unlink($racePath);
$db->exec('VACUUM INTO ' . $db->quote($racePath));
$children = [];
foreach ([$b, $c] as $index => $claim)
{
	$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	checkResearch($pair !== false, 'reservation race Unix socket pair succeeds');
	$pid = pcntl_fork();
	checkResearch($pid >= 0, 'reservation race fork succeeds');
	if ($pid === 0)
	{
		fclose($pair[0]);
		fread($pair[1], 1);
		try
		{
			$raceDb = new PDO('sqlite:' . $racePath);
			$raceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$raceDb->exec('PRAGMA busy_timeout = 5000');
			$raceService = new GrocyAiCaptureResearchService($raceDb, false);
			$result = $raceService->ReserveWebSearch($claim['id'], $claim['lease_token'], 'race-' . $index);
			fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR));
			exit(0);
		}
		catch (Throwable $ex) { fwrite($pair[1], $ex->getMessage()); exit(1); }
	}
	fclose($pair[1]);
	$children[] = [$pid, $pair[0]];
}
foreach ($children as [$pid, $socket]) fwrite($socket, 'G');
$allowed = 0;
foreach ($children as [$pid, $socket])
{
	$result = json_decode(stream_get_contents($socket), true, 512, JSON_THROW_ON_ERROR);
	fclose($socket);
	pcntl_waitpid($pid, $status);
	checkResearch(pcntl_wexitstatus($status) === 0, 'reservation race child succeeds');
	$allowed += (int)$result['allowed'];
}
checkResearch($allowed === 1, 'two concurrent reservations grant only final daily slot');
$raceDb = new PDO('sqlite:' . $racePath);
checkResearch((int)$raceDb->query('SELECT COUNT(*) FROM grocy_ai_capture_web_search_reservations')->fetchColumn() === 2, 'concurrent daily count never exceeds ceiling');
$raceDb = null;
unlink($racePath);

$duplicate = $research->ReserveWebSearch($a['id'], $a['lease_token'], 'worker');
checkResearch(!$duplicate['allowed'] && $duplicate['reservation_id'] === $reservation['reservation_id'], 'lost response cannot repeat paid search');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET lease_expires_at = datetime('now', '-1 second') WHERE id = " . $a['id']);
$reclaimed = $research->ClaimJobs(1, 'reclaimer')[0];
checkResearch(!$research->ReserveWebSearch($a['id'], $reclaimed['lease_token'], 'worker')['allowed'], 'reclaimed lease cannot repeat paid search');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET state = 'needs_input' WHERE id = " . $a['id']);
$research->RetryJob((int)$secondTrip['id'], $otherLineId, 'user');
$manual = $research->ClaimJobs(1, 'manual')[0];
checkResearch($research->ReserveWebSearch($a['id'], $manual['lease_token'], 'worker')['allowed'], 'one manual retry reserves generation one');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET state = 'needs_input' WHERE id = " . $a['id']);
$research->RetryJob((int)$secondTrip['id'], $otherLineId, 'user');
$providerRetry = $research->ClaimJobs(1, 'provider-only')[0];
checkResearch(!$research->ReserveWebSearch($a['id'], $providerRetry['lease_token'], 'worker')['allowed'], 'second manual retry preserves provider lookup without extra paid search');
checkResearch(!$research->ReserveWebSearch($b['id'], $b['lease_token'], 'worker')['allowed'], 'daily ceiling denies next job');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_web_search_reservations')->fetchColumn() === 2, 'denials never spend additional slots');
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES ($failedTripId, 'user')");
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES ($repairTripId, 'user')");
checkResearch($research->ReserveWebSearch($b['id'], $b['lease_token'], 'worker')['reason'] === 'ineligible', 'canceled trips denied before budget decision');

require_once __DIR__ . '/../../../packages/autoload.php';
require_once __DIR__ . '/../src/GrocyAiCaptureResearchController.php';
define('GROCY_AI_RESEARCH_WORKER_KEY', 'reservation-worker-key');
class ReservationTestController extends GrocyAI\Controllers\Api\GrocyAiCaptureResearchController
{
	public function __construct(private GrocyAiCaptureResearchService $research) {}
	protected function Service(): GrocyAiCaptureResearchService { return $this->research; }
	protected function HasApiCredential(Psr\Http\Message\ServerRequestInterface $request): bool { return $request->getHeaderLine('GROCY-API-KEY') === 'test-api-key'; }
}
$controller = new ReservationTestController($research);
$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/reserve')->withParsedBody(['lease_token' => $providerRetry['lease_token']]);
$factory = new Slim\Psr7\Factory\ResponseFactory();
$args = ['jobId' => (string)$a['id']];
checkResearch($controller->ReserveWebSearch($request->withHeader('X-Grocy-AI-Worker-Key', 'reservation-worker-key'), $factory->createResponse(), $args)->getStatusCode() === 401, 'reservation requires API credential');
$request = $request->withHeader('GROCY-API-KEY', 'test-api-key');
checkResearch($controller->ReserveWebSearch($request, $factory->createResponse(), $args)->getStatusCode() === 403, 'reservation requires worker key');
$request = $request->withHeader('X-Grocy-AI-Worker-Key', 'reservation-worker-key');
checkResearch($controller->ReserveWebSearch($request->withParsedBody(['lease_token' => 1]), $factory->createResponse(), $args)->getStatusCode() === 400, 'reservation validates lease type');
checkResearch($controller->ReserveWebSearch($request->withParsedBody(['lease_token' => str_repeat('0', 64)]), $factory->createResponse(), $args)->getStatusCode() === 409, 'reservation maps conflicting lease safely');
$response = $controller->ReserveWebSearch($request, $factory->createResponse(), $args);
checkResearch($response->getStatusCode() === 200 && json_decode((string)$response->getBody(), true) === ['allowed' => false, 'reason' => 'already_reserved', 'reservation_id' => 2], 'reservation response exposes only safe decision');

// A missing v2 validator or relaxed evidence boundary must fail these tests.
$normalize = new ReflectionMethod(GrocyAiCaptureResearchService::class, 'NormalizeResult');
$web = ['contract_version' => 2, 'canonical_gtin' => '04006381333931', 'outcome' => 'found', 'name_candidates' => ['Web cereal'], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => ['openai-web'], 'name_candidate_sources' => [['openai-web']], 'web_evidence' => [['candidate_index' => 0, 'exact_gtin_claim' => false, 'citations' => [['title' => 'Cereal listing', 'url' => 'https://example.com/product']]]]];
$normalized = $normalize->invoke(null, $web, $web['canonical_gtin']);
checkResearch($normalized['web_evidence'][0]['citations'][0] === ['title' => 'Cereal listing', 'url' => 'https://example.com/product', 'domain' => 'example.com'], 'OpenAI citation domain derived from URL');
foreach (['https://router.home.arpa/', 'https://hidden.onion/', 'https://node.alt/', 'https://router.home/', 'https://intranet.corp/', 'https://server.mail/', 'https://node.arpa/', 'https://example.com/%zz', 'https:///missing-host', 'https://example.com:bad/a', 'https://example.test/a', 'https://example.internal/a', 'https://example.com@localhost/a', 'javascript:alert(1)', 'https://127.0.0.1/a', 'https://10.0.0.1/a', 'https://[::1]/a', 'https://localhost/a', 'https://host.local/a', 'https://example.com:444/a', 'https://user@example.com/a', "https://example.com/\n", 'https://example.com/%0a', 'https://example.com\\@127.0.0.1/a'] as $url)
{
	$bad = $web; $bad['web_evidence'][0]['citations'][0]['url'] = $url;
	deniesResearch(fn() => $normalize->invoke(null, $bad, $web['canonical_gtin']), 'unsafe OpenAI citation URL rejected: ' . $url);
}
foreach (['extra', 'index', 'claim', 'title', 'empty', 'version', 'missing', 'name'] as $case)
{
	$bad = $web;
	if ($case === 'extra') $bad['web_evidence'][0]['raw_response'] = 'secret';
	if ($case === 'index') $bad['web_evidence'][0]['candidate_index'] = 1;
	if ($case === 'claim') $bad['web_evidence'][0]['exact_gtin_claim'] = 'true';
	if ($case === 'title') $bad['web_evidence'][0]['citations'][0]['title'] = "Bad\x00title";
	if ($case === 'empty') $bad['web_evidence'][0]['citations'] = [];
	if ($case === 'version') $bad['contract_version'] = 1;
	if ($case === 'missing') unset($bad['web_evidence']);
	if ($case === 'name') $bad['name_candidates'] = ["Bad\x00name"];
	deniesResearch(fn() => $normalize->invoke(null, $bad, $web['canonical_gtin']), 'malformed OpenAI evidence rejected: ' . $case);
}
$v1 = $web; $v1['contract_version'] = 1; $v1['sources'] = ['openfoodfacts']; $v1['name_candidate_sources'] = [['openfoodfacts']]; unset($v1['web_evidence']);
checkResearch($normalize->invoke(null, $v1, $v1['canonical_gtin']) === $v1, 'provider-only version 1 unchanged');
$miss = $v1; $miss['contract_version'] = 2; $miss['outcome'] = 'miss'; $miss['name_candidates'] = []; $miss['name_candidate_sources'] = []; $miss['web_evidence'] = [];
checkResearch($normalize->invoke(null, $miss, $miss['canonical_gtin']) === $miss, 'inconclusive version 2 remains needs input');


require_once __DIR__ . '/../src/GrocyAiTaxonomyMigration.php';
// Unit completion validates the current catalog and directed global conversions.
$udb = new PDO('sqlite::memory:');
$udb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$udb->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1, parent_product_id INTEGER, qu_id_stock INTEGER DEFAULT 1)');
$udb->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
$udb->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT)');
$udb->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
$udb->exec('CREATE TABLE quantity_units (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
$udb->exec('CREATE TABLE quantity_unit_conversions (from_qu_id INTEGER, to_qu_id INTEGER, factor REAL, product_id INTEGER)');
$udb->exec("INSERT INTO quantity_units VALUES (1, 'Pack', 1), (2, 'Piece', 1), (3, 'Inactive', 0)");
$us = new GrocyAiCaptureResearchService($udb);
$udb->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test')");
$udb->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (1, 1, 1, '4006381333931', '04006381333931', 'unknown')");
$us->EnqueueUnknown(1, 1, '4006381333931');
$uj = $us->ClaimJobs(1, 'test')[0];
$us->CompleteJob($uj['id'], $uj['lease_token'], $v1);
foreach (['valid', 'reverse', 'zero', 'product_specific', 'inactive', 'unknown', 'legacy', 'stored_legacy', 'parent', 'saved_parent', 'stale'] as $index => $case)
{
	$udb->exec('DELETE FROM quantity_unit_conversions');
	$udb->exec('UPDATE quantity_units SET active = 1 WHERE id = 2');
	$udb->exec('INSERT INTO quantity_unit_conversions VALUES (1, 2, 6, NULL)');
	$udb->exec('DELETE FROM products');
	$udb->exec("UPDATE grocy_ai_capture_research_drafts SET selected_json = '{\"name\":\"Web cereal\"}', user_edits_json = '{}'");
	if (in_array($case, ['parent', 'saved_parent'], true)) $udb->exec("INSERT INTO products VALUES (9, 'Parent', 1, NULL, 2)");
	if ($case === 'saved_parent') $udb->exec("UPDATE grocy_ai_capture_research_drafts SET selected_json = '{\"name\":\"Web cereal\",\"parent_product_id\":9}', user_edits_json = '{\"parent_product_id\":true}'");
	$udb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = ' . ($index + 1));
	$uc = $us->ClaimClassifications(1, 'test')[0];
	$us->ReserveClassification($uc['id'], $uc['lease_token'], 'test');
	if ($case === 'reverse') $udb->exec('UPDATE quantity_unit_conversions SET from_qu_id = 2, to_qu_id = 1');
	if ($case === 'zero') $udb->exec('UPDATE quantity_unit_conversions SET factor = 0');
	if ($case === 'product_specific') $udb->exec('UPDATE quantity_unit_conversions SET product_id = 99');
	if ($case === 'inactive') $udb->exec('UPDATE quantity_units SET active = 0 WHERE id = 2');
	if ($case === 'stale') $udb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 99');
	$ur = ['status'=>'suggested', 'product_group_id'=>null, 'taxonomy_leaf_slug'=>null, 'parent_product_id'=>null];
	if (!in_array($case, ['legacy', 'stored_legacy'], true)) $ur += ['qu_id_purchase'=>$case === 'unknown' ? 999 : 1, 'qu_id_stock'=>2];
	if ($case === 'parent') { $ur['parent_product_id'] = 9; $ur['qu_id_stock'] = 1; }
	if ($case === 'saved_parent') { $ur['qu_id_purchase'] = 1; $ur['qu_id_stock'] = 1; }
	$done = $us->CompleteClassification($uc['id'], $uc['lease_token'], $ur);
	if ($case === 'stale') checkResearch($done['state'] === 'ignored', 'stale unit result ignored');
	else
	{
		checkResearch($done['state'] === 'suggested', 'new and legacy unit contracts accepted');
		$expected = match ($case) { 'valid'=>[1, 2], 'parent'=>[1, 1], 'saved_parent'=>[1, 1], 'inactive'=>[1, null], 'unknown'=>[null, 2], default=>[null, null] };
		checkResearch([$done['result']['qu_id_purchase'], $done['result']['qu_id_stock']] === $expected, 'live unit validation: ' . $case);
		if ($case === 'parent') checkResearch($done['result']['parent_product_id'] === null, 'incompatible model parent discarded');
		if ($case === 'saved_parent') checkResearch($us->ReviewForTrip(1)['drafts'][0]['selected']['qu_id_stock'] === null, 'unit cannot preselect against saved parent');
		if ($case === 'stored_legacy')
		{
			$udb->prepare('UPDATE grocy_ai_capture_classification_jobs SET result_json = ? WHERE id = ?')->execute([json_encode(['status'=>'suggested', 'product_group_id'=>null, 'taxonomy_leaf_slug'=>null, 'parent_product_id'=>null]), $uc['id']]);
			checkResearch($us->ReviewForTrip(1)['drafts'][0]['classification']['result']['qu_id_stock'] === null, 'stored legacy normalized at review');
		}
		if ($case === 'valid') checkResearch($us->ReviewForTrip(1)['drafts'][0]['selected']['qu_id_stock'] === 2, 'classification units preselected for review');
		if ($case === 'legacy') checkResearch($us->ReviewForTrip(1)['drafts'][0]['classification']['result']['qu_id_stock'] === null, 'legacy trip remains reviewable');
	}
}
$udb->exec('DELETE FROM products');
checkResearch((int)$udb->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0 && (int)$udb->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'unit completion performs no product or stock writes');

// Classification has an independent immutable revision and append-only spend ledger.
require_once __DIR__ . '/../src/GrocyAiTaxonomyMigration.php';
checkResearch(method_exists(GrocyAiCaptureResearchService::class, 'ClaimClassifications'), 'classification queue missing');
$cdb = new PDO('sqlite::memory:');
$cdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cdb->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1, parent_product_id INTEGER, qu_id_stock INTEGER DEFAULT 1)');
$cdb->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
$cdb->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT)');
$cdb->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
$cs = new GrocyAiCaptureResearchService($cdb);
$cdb->exec("INSERT INTO product_groups VALUES (1, 'Other', 1)");
$cdb->exec("INSERT INTO products (id, name) VALUES (1, 'Parent')");
$cdb->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test')");
$cdb->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (1, 1, 1, '4006381333931', '04006381333931', 'unknown')");
$cd = $cs->EnqueueUnknown(1, 1, '4006381333931');
checkResearch($cs->ClaimClassifications(1, 'test') === [], 'pending identity cannot classify');
$cj = $cs->ClaimJobs(1, 'test')[0];
$cs->CompleteJob($cj['id'], $cj['lease_token'], ['contract_version'=>1, 'canonical_gtin'=>'04006381333931', 'outcome'=>'found', 'name_candidates'=>['Food'], 'brand'=>null, 'package'=>null, 'categories'=>[], 'sources'=>['bb-federation']]);
$cc = $cs->ClaimClassifications(1, 'test')[0];
checkResearch($cc['input']['identity']['name'] === 'Food' && $cc['input']['result_revision'] === 1, 'claim returns immutable identity revision');
checkResearch($cs->ClaimClassifications(1, 'other') === [], 'live classification lease exclusive');
checkResearch($cs->ReserveClassification($cc['id'], $cc['lease_token'], 'test')['allowed'], 'first classification reserved');
checkResearch(!$cs->ReserveClassification($cc['id'], $cc['lease_token'], 'test')['allowed'], 'same revision cannot spend twice');
rejectsResearch(fn() => $cdb->exec('DELETE FROM grocy_ai_capture_classification_reservations'), 'classification reservations append-only');
$cdb->exec('UPDATE product_groups SET active = 0 WHERE id = 1');
$cr = $cs->CompleteClassification($cc['id'], $cc['lease_token'], ['status'=>'suggested', 'product_group_id'=>1, 'taxonomy_leaf_slug'=>'produce', 'parent_product_id'=>999]);
checkResearch($cr['result']['product_group_id'] === null && $cr['result']['taxonomy_leaf_slug'] === 'produce' && $cr['result']['parent_product_id'] === null, 'invalid fields do not discard valid live snapshot choice');
checkResearch($cs->CompleteClassification($cc['id'], $cc['lease_token'], ['status'=>'suggested', 'product_group_id'=>1, 'taxonomy_leaf_slug'=>'produce', 'parent_product_id'=>999]) === $cr, 'lost completion idempotent');
checkResearch($cs->ClaimClassifications(1, 'other') === [], 'completed classification not retried');
checkResearch($cdb->query('SELECT outcome FROM grocy_ai_capture_research_drafts')->fetchColumn() === 'ready', 'classification preserves identified research');
$cdb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 2');
$cc = $cs->ClaimClassifications(1, 'test')[0];
$cdb->exec("UPDATE grocy_ai_capture_research_drafts SET outcome = 'approved'");
checkResearch($cs->CompleteClassification($cc['id'], $cc['lease_token'], ['status'=>'suggested', 'product_group_id'=>null, 'taxonomy_leaf_slug'=>'produce', 'parent_product_id'=>1])['state'] === 'ignored', 'finalized completion ignored');
// Lost call, malformed result, stale revision, and review status are all neutral.
$cdb->exec("UPDATE grocy_ai_capture_research_drafts SET outcome = 'ready', result_revision = 3");
$cc = $cs->ClaimClassifications(1, 'test')[0];
$cs->ReserveClassification($cc['id'], $cc['lease_token'], 'test');
$cdb->exec("UPDATE grocy_ai_capture_classification_jobs SET lease_expires_at = datetime('now', '-1 second') WHERE id = " . $cc['id']);
checkResearch($cs->ClaimClassifications(1, 'test') === [], 'lost paid call cannot be reclaimed');
checkResearch($cs->ReviewForTrip(1)['drafts'][0]['classification']['state'] === 'unavailable', 'lost call visible for manual review');
$cdb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 4');
$cc = $cs->ClaimClassifications(1, 'test')[0];
$malformed = $cs->CompleteClassification($cc['id'], $cc['lease_token'], ['arbitrary'=>'data']);
checkResearch($malformed['state'] === 'malformed', 'malformed completion safely recorded');
$cdb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 5');
$cc = $cs->ClaimClassifications(1, 'test')[0];
$cdb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 6');
checkResearch($cs->CompleteClassification($cc['id'], $cc['lease_token'], ['status'=>'suggested', 'product_group_id'=>null, 'taxonomy_leaf_slug'=>'produce', 'parent_product_id'=>1])['state'] === 'ignored', 'superseded completion ignored');
$cc = $cs->ClaimClassifications(1, 'test')[0];
$cdb->exec("INSERT INTO product_barcodes VALUES (1, 1, '4006381333931')");
checkResearch(!$cs->ReserveClassification($cc['id'], $cc['lease_token'], 'test')['allowed'], 'new exact owner prevents spend');
checkResearch($cs->CompleteClassification($cc['id'], $cc['lease_token'], ['status'=>'suggested', 'product_group_id'=>null, 'taxonomy_leaf_slug'=>'produce', 'parent_product_id'=>1])['state'] === 'ignored', 'new exact owner ignores completion');
$cdb->exec('DELETE FROM product_barcodes');
// Existing OFF mapping prevents AI classification even when its group is unavailable.
$cdb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 7');
$provider = json_decode($cdb->query('SELECT suggested_json FROM grocy_ai_capture_research_drafts')->fetchColumn(), true);
$provider['sources'] = ['openfoodfacts']; $provider['categories'] = ['Seafood'];
$cdb->prepare('UPDATE grocy_ai_capture_research_drafts SET suggested_json = ?')->execute([json_encode($provider)]);
checkResearch($cs->ClaimClassifications(1, 'test') === [], 'usable OFF mapping wins');
$provider['sources'] = ['bb-federation']; $provider['categories'] = [];
$cdb->prepare('UPDATE grocy_ai_capture_research_drafts SET suggested_json = ?, result_revision = 8')->execute([json_encode($provider)]);
$cc = $cs->ClaimClassifications(1, 'test')[0];
// Atomic final UTC-day slot shared by two processes, including historical reservations.
define('GROCY_AI_CAPTURE_CLASSIFICATION_DAILY_LIMIT', 3);
$cdb->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (2, 1, 2, '96385074', '00000096385074', 'unknown')");
$cs->EnqueueUnknown(1, 2, '96385074');
$cj = $cs->ClaimJobs(1, 'test')[0];
$provider['canonical_gtin'] = '00000096385074';
$cs->CompleteJob($cj['id'], $cj['lease_token'], $provider);
$cc2 = $cs->ClaimClassifications(1, 'test')[0];
$cdb->exec("INSERT INTO grocy_ai_capture_classification_jobs (draft_id, result_revision, input_json, state, lease_hash, lease_expires_at) VALUES (1, 100, '{}', 'unavailable', 'historical', CURRENT_TIMESTAMP)");
$cdb->exec("INSERT INTO grocy_ai_capture_classification_reservations (draft_id, result_revision, utc_day, actor) VALUES (1, 100, date('now', '-1 day'), 'historical')");
$racePath = tempnam(sys_get_temp_dir(), 'grocy-classification-'); unlink($racePath);
$cdb->exec('VACUUM INTO ' . $cdb->quote($racePath));
$children = [];
foreach ([$cc, $cc2] as $claim)
{
	$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	$pid = pcntl_fork();
	checkResearch($pid >= 0, 'classification fork succeeds');
	if ($pid === 0)
	{
		fclose($pair[0]); fread($pair[1], 1);
		try
		{
			$raceDb = new PDO('sqlite:' . $racePath); $raceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $raceDb->exec('PRAGMA busy_timeout = 5000');
			$result = (new GrocyAiCaptureResearchService($raceDb, false))->ReserveClassification($claim['id'], $claim['lease_token'], 'racer');
			fwrite($pair[1], json_encode($result)); exit(0);
		}
		catch (Throwable $ex) { fwrite($pair[1], json_encode(['error'=>$ex->getMessage()])); exit(1); }
	}
	fclose($pair[1]); $children[] = [$pid, $pair[0]];
}
foreach ($children as [$pid, $socket]) fwrite($socket, 'G');
$allowed = 0;
foreach ($children as [$pid, $socket])
{
	$result = json_decode(stream_get_contents($socket), true); fclose($socket); pcntl_waitpid($pid, $status);
	checkResearch(pcntl_wexitstatus($status) === 0, 'classification race child succeeds'); $allowed += (int)$result['allowed'];
}
checkResearch($allowed === 1, 'classification race grants final UTC slot exactly once');
$raceDb = new PDO('sqlite:' . $racePath);
checkResearch((int)$raceDb->query("SELECT COUNT(*) FROM grocy_ai_capture_classification_reservations WHERE utc_day = date('now')")->fetchColumn() === 3, 'classification daily ceiling exact');
$raceDb = null; unlink($racePath);
checkResearch($cdb->query('SELECT source FROM grocy_ai_capture_classification_jobs WHERE id = 1')->fetchColumn() === 'openai-classification', 'classification source durably recorded');
rejectsResearch(fn() => $cdb->exec("UPDATE grocy_ai_capture_classification_jobs SET input_json = '{}' WHERE id = 1"), 'claim snapshot immutable');
$cdb->exec("UPDATE grocy_ai_capture_lines SET scanned_barcode = '036000291452', canonical_gtin = '00036000291452' WHERE id = 1");
$cdb->exec('UPDATE grocy_ai_capture_research_drafts SET result_revision = 9 WHERE id = 1');
checkResearch($cs->ClassificationInput(1) === null, 'changed scan cannot classify stale identity');

echo "capture research queue: PASS\n";
