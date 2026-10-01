<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiCaptureResearchService;

require_once __DIR__ . '/../../../packages/autoload.php';
define('GROCY_AI_RESEARCH_WORKER_KEY', 'configured-secret');

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService'] as $file)
{
	require_once __DIR__ . '/../src/' . $file . '.php';
}

function checkWorker(bool $ok, string $message): void
{
	if (!$ok) throw new RuntimeException($message);
}

function rejectWorker(callable $call, string $message): void
{
	try { $call(); } catch (InvalidArgumentException|RuntimeException $expected) { return; }
	throw new RuntimeException($message);
}

$path = tempnam(sys_get_temp_dir(), 'grocy-research-');
$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
GrocyAiCaptureMigration::Bootstrap($db);
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (1, 1, 1, '4006381333931', '04006381333931', 'unknown')");
$service = new GrocyAiCaptureResearchService($db);
$service->EnqueueUnknown(1, 1, '4006381333931');
$other = new PDO('sqlite:' . $path);
$other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$otherService = new GrocyAiCaptureResearchService($other);

checkWorker(method_exists($service, 'ClaimJobs'), 'missing ClaimJobs');
$claimed = $service->ClaimJobs(1, 'worker-a');
checkWorker(count($claimed) === 1 && $claimed[0]['canonical_gtin'] === '04006381333931', 'first claim receives canonical job');
checkWorker(count($otherService->ClaimJobs(1, 'worker-b')) === 0, 'concurrent claimer cannot steal live lease');
checkWorker(!isset($service->DraftsForTrip(1)[0]['lease_token']), 'browser draft omits lease token');
rejectWorker(fn() => $service->ClaimJobs(6, 'worker-a'), 'oversized claim accepted');

$jobId = (int)$claimed[0]['id'];
$token = $claimed[0]['lease_token'];
$hit = ['contract_version' => 1, 'canonical_gtin' => '04006381333931', 'outcome' => 'found', 'name_candidates' => ['Sample food'], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => ['bb-federation']];
rejectWorker(fn() => $service->CompleteJob($jobId, $token, $hit + ['raw_html' => '<p>no</p>']), 'extra provider field accepted');
rejectWorker(fn() => $service->CompleteJob($jobId, $token, array_replace($hit, ['canonical_gtin' => '00000096385074'])), 'wrong GTIN accepted');
rejectWorker(fn() => $service->CompleteJob($jobId, $token, array_replace($hit, ['name_candidates' => [str_repeat('a', 201)]])), 'oversized name accepted');
rejectWorker(fn() => $service->CompleteJob($jobId, $token, array_replace($hit, ['sources' => ['unknown-provider']])), 'unknown source accepted');
rejectWorker(fn() => $service->CompleteJob($jobId, $token, array_replace($hit, ['outcome' => 'found', 'name_candidates' => []])), 'empty hit accepted');
rejectWorker(fn() => $service->CompleteJob($jobId, $token, array_replace($hit, ['outcome' => 'retryable_failure', 'name_candidates' => []])), 'retryable failure without safe code accepted');
rejectWorker(fn() => $service->CompleteJob($jobId, str_repeat('0', 64), $hit), 'stale token accepted');

$db->exec("UPDATE grocy_ai_capture_research_jobs SET lease_expires_at = datetime('now', '-1 second') WHERE id = $jobId");
rejectWorker(fn() => $service->CompleteJob($jobId, $token, $hit), 'expired lease accepted');
$reclaimed = $otherService->ClaimJobs(1, 'worker-b');
checkWorker(count($reclaimed) === 1 && $reclaimed[0]['lease_token'] !== $token && $reclaimed[0]['attempts'] === 2, 'expired worker lease is reclaimed with new token');
$done = $otherService->CompleteJob($jobId, $reclaimed[0]['lease_token'], $hit);
checkWorker($done['state'] === 'ready', 'hit completes job');
checkWorker($otherService->CompleteJob($jobId, $reclaimed[0]['lease_token'], $hit) === $done, 'duplicate completion is idempotent');
rejectWorker(fn() => $service->CompleteJob($jobId, $token, $hit), 'old token cannot complete after recovery');

$draft = $service->DraftsForTrip(1)[0];
checkWorker($draft['outcome'] === 'ready' && json_decode($draft['suggested_json'], true)['name_candidates'] === ['Sample food'], 'normalized hit reaches draft');
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (2, 'reviewing', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (3, 2, 1, '4006381333931', '04006381333931', 'unknown')");
$service->EnqueueUnknown(2, 3, '4006381333931');
$sharedDraft = $service->DraftsForTrip(2)[0];
checkWorker($sharedDraft['outcome'] === 'ready' && json_decode($sharedDraft['suggested_json'], true)['name_candidates'] === ['Sample food'], 'later scan reuses completed shared GTIN research');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET selected_json = '{\"name\":\"My edit\"}', user_edits_json = '{\"name\":true}' WHERE line_id = 1");
$db->exec("UPDATE grocy_ai_capture_research_jobs SET state = 'queued', next_retry_at = NULL WHERE id = $jobId");
$retry = $service->ClaimJobs(1, 'worker-c')[0];
$service->CompleteJob($jobId, $retry['lease_token'], array_replace($hit, ['name_candidates' => ['Other food']]));
$draft = $service->DraftsForTrip(1)[0];
checkWorker(json_decode($draft['selected_json'], true)['name'] === 'My edit', 'later research preserves user selection');
checkWorker(json_decode($draft['suggested_json'], true)['name_candidates'] === ['Other food'], 'later research refreshes suggestions');
checkWorker(json_decode($service->DraftsForTrip(2)[0]['selected_json'], true)['name'] === 'Other food', 'unedited draft adopts later suggestion');
checkWorker((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_research_audit WHERE action = 'research_result'")->fetchColumn() === 3, 'results append audit for every active draft');

$db->exec("INSERT INTO grocy_ai_capture_research_jobs (canonical_gtin) VALUES ('00000096385074')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (2, 1, 2, '96385074', '00000096385074', 'unknown')");
$service->EnqueueUnknown(1, 2, '96385074');
$failure = $service->ClaimJobs(1, 'worker-d')[0];
$firstFailure = $service->FailJob((int)$failure['id'], $failure['lease_token'], 'provider_unavailable');
checkWorker($service->FailJob((int)$failure['id'], $failure['lease_token'], 'provider_unavailable') === $firstFailure, 'lost failure response is idempotent');
checkWorker($db->query("SELECT state FROM grocy_ai_capture_research_jobs WHERE id = {$failure['id']}")->fetchColumn() === 'retryable_failure', 'failure is retryable');
checkWorker((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_research_audit WHERE action = 'research_failure'")->fetchColumn() === 1, 'safe failure appends draft audit');
checkWorker(count($service->ClaimJobs(1, 'worker-d')) === 0, 'backoff delays retry');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET next_retry_at = datetime('now', '-1 second') WHERE id = {$failure['id']}");
checkWorker(count($service->ClaimJobs(1, 'worker-d')) === 1, 'retry becomes claimable');
rejectWorker(fn() => $service->FailJob((int)$failure['id'], $failure['lease_token'], 'secret traceback'), 'unsafe failure code accepted');
$latest = $db->query("SELECT lease_hash FROM grocy_ai_capture_research_jobs WHERE id = {$failure['id']}")->fetchColumn();
checkWorker($latest !== hash('sha256', $failure['lease_token']), 'retry rotated lease token');
for ($attempt = 3; $attempt <= 5; $attempt++)
{
	$db->exec("UPDATE grocy_ai_capture_research_jobs SET lease_expires_at = datetime('now', '-1 second') WHERE id = {$failure['id']}");
	$lease = $service->ClaimJobs(1, 'worker-d')[0];
	checkWorker($lease['attempts'] === $attempt, 'attempt count advances to bounded maximum');
}
$terminal = $service->FailJob((int)$failure['id'], $lease['lease_token'], 'worker_unavailable');
checkWorker($terminal['state'] === 'needs_input' && count($service->ClaimJobs(1, 'worker-d')) === 0, 'fifth failure is terminal');
checkWorker($service->DraftsForTrip(1)[1]['outcome'] === 'needs_input', 'terminal failure leaves a reviewable draft');
$db->exec("INSERT INTO grocy_ai_capture_research_jobs (canonical_gtin) VALUES ('00012345678905')");
$providerFailure = $service->ClaimJobs(1, 'worker-e')[0];
$retryable = ['contract_version' => 1, 'canonical_gtin' => '00012345678905', 'outcome' => 'retryable_failure', 'name_candidates' => [], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => [], 'error_code' => 'provider_unavailable'];
$failedResult = $service->CompleteJob((int)$providerFailure['id'], $providerFailure['lease_token'], $retryable);
checkWorker($failedResult['state'] === 'retryable_failure' && $failedResult['safe_error_code'] === 'provider_unavailable', 'contract retryable failure enters bounded retry path');
checkWorker($service->CompleteJob((int)$providerFailure['id'], $providerFailure['lease_token'], $retryable) === $failedResult, 'lost retryable completion response is idempotent');
$db->exec("UPDATE grocy_ai_capture_research_jobs SET state = 'leased', attempts = 5, lease_expires_at = datetime('now', '-1 second') WHERE id = {$failure['id']}");
checkWorker(count($service->ClaimJobs(1, 'worker-f')) === 0, 'expired fifth lease is never issued as sixth attempt');
checkWorker($db->query("SELECT state FROM grocy_ai_capture_research_jobs WHERE id = {$failure['id']}")->fetchColumn() === 'needs_input', 'expired fifth lease resolves to reviewable terminal state');

checkWorker(is_file(__DIR__ . '/../src/GrocyAiCaptureResearchController.php'), 'worker controller missing');
require_once __DIR__ . '/../src/GrocyAiCaptureResearchController.php';
checkWorker(!GrocyAI\Controllers\Api\GrocyAiCaptureResearchController::ValidWorkerKey('', 'configured-secret'), 'absent key rejected');
checkWorker(!GrocyAI\Controllers\Api\GrocyAiCaptureResearchController::ValidWorkerKey('wrong', 'configured-secret'), 'wrong key rejected');
checkWorker(!GrocyAI\Controllers\Api\GrocyAiCaptureResearchController::ValidWorkerKey('configured-secret', ''), 'unset config rejected');
checkWorker(GrocyAI\Controllers\Api\GrocyAiCaptureResearchController::ValidWorkerKey('configured-secret', 'configured-secret'), 'matching key accepted');
$controller = (new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiCaptureResearchController::class))->newInstanceWithoutConstructor();
$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/grocy-ai/capture/research/jobs/claim')->withParsedBody(['limit' => 1, 'worker_id' => 'test-worker']);
$response = (new Slim\Psr7\Factory\ResponseFactory())->createResponse();
checkWorker($controller->Claim($request, $response, [])->getStatusCode() === 403, 'HTTP claim rejects missing worker key');
checkWorker($controller->Claim($request->withHeader('X-Grocy-AI-Worker-Key', 'wrong'), $response, [])->getStatusCode() === 403, 'HTTP claim rejects wrong worker key');
checkWorker($controller->Complete($request, $response, ['jobId' => (string)$jobId])->getStatusCode() === 403, 'HTTP completion rejects missing worker key');
checkWorker($controller->Fail($request, $response, ['jobId' => (string)$jobId])->getStatusCode() === 403, 'HTTP failure rejects missing worker key');
$jsonRequest = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/grocy-ai/capture/research/jobs/claim')->withHeader('X-Grocy-AI-Worker-Key', 'configured-secret')->withHeader('Content-Type', 'application/json');
$jsonRequest->getBody()->write('{"worker_id":"test-worker","limit":0}');
class ResearchTestController extends GrocyAI\Controllers\Api\GrocyAiCaptureResearchController
{
	public function __construct(private GrocyAiCaptureResearchService $research) {}
	protected function Service(): GrocyAiCaptureResearchService { return $this->research; }
}
$testController = new ResearchTestController($service);
checkWorker($testController->Claim($jsonRequest, $response, [])->getStatusCode() === 400, 'HTTP claim parses unordered JSON and validates limit');
$validJson = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/grocy-ai/capture/research/jobs/claim')->withHeader('X-Grocy-AI-Worker-Key', 'configured-secret')->withHeader('Content-Type', 'application/json');
$validJson->getBody()->write('{"worker_id":"test-worker","limit":1}');
checkWorker($testController->Claim($validJson, $response, [])->getStatusCode() === 200, 'HTTP claim accepts bounded JSON without global parser');
checkWorker((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'worker research never writes product or stock');

$db = null;
$other = null;
unlink($path);
echo "capture research worker API: PASS\n";
