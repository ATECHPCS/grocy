<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureDashboardService;
use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiReceiptMigration;
use GrocyAI\Controllers\Api\GrocyAiApiController;
use GrocyAI\Controllers\GrocyAiCaptureController;

if (!defined('GROCY_MODE')) define('GROCY_MODE', 'production');
if (!defined('GROCY_DATAPATH')) define('GROCY_DATAPATH', sys_get_temp_dir());
if (!defined('GROCY_USER_ID')) define('GROCY_USER_ID', 1);
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
foreach (['GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureService', 'GrocyAiApiController', 'GrocyAiCaptureController'] as $file) require_once __DIR__ . '/../src/' . $file . '.php';
$serviceFile = __DIR__ . '/../src/GrocyAiCaptureDashboardService.php';
if (is_file($serviceFile)) require_once $serviceFile;
function dashboardCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function dashboardSnapshot(PDO $db): array
{
	$rows = [];
	foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $name) $rows[$name] = $db->query('SELECT * FROM "' . str_replace('"', '""', $name) . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
	return $rows;
}
function dashboardTrip(PDO $db, int $id, string $actor, string $status, ?string $committed = null): void
{
	$db->prepare('INSERT INTO grocy_ai_capture_trips (id, created_by, status, committed_at, module_version) VALUES (?, ?, ?, ?, ?)')->execute([$id, $actor, $status, $committed, 'test']);
}
function dashboardReceipt(PDO $db, int $trip, mixed $total): int
{
	$db->prepare("INSERT INTO grocy_ai_receipts (trip_id, image_id, mime_type, image_bytes, printed_total) VALUES (?, ?, 'image/png', 1, ?)")->execute([$trip, bin2hex(random_bytes(12)), $total]);
	return (int)$db->lastInsertId();
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, amount REAL)');
$db->exec('CREATE TABLE user_permissions_resolved (id INTEGER PRIMARY KEY, user_id INTEGER, permission_name TEXT)');
$db->exec("INSERT INTO user_permissions_resolved VALUES (1, 1, 'STOCK_PURCHASE')");
GrocyAiReceiptMigration::Bootstrap($db);
GrocyAI\Services\GrocyAiCaptureResearchMigration::Bootstrap($db);
dashboardCheck(class_exists(GrocyAiCaptureDashboardService::class), 'Dashboard read service missing');
$service = new GrocyAiCaptureDashboardService($db);
$now = new DateTimeImmutable('2026-10-13T12:00:00Z');
$before = dashboardSnapshot($db);
$empty = $service->ReadForUser('1', null, null, $now);
dashboardCheck($empty['contract_version'] === 1 && $empty['period_start'] === '2026-09-13T12:00:00Z' && $empty['period_end'] === '2026-10-13T12:00:00Z', 'UTC rolling period');
dashboardCheck($empty['stats'] === ['open_sessions' => 0, 'reviewing_sessions' => 0, 'completed_sessions_30d' => 0, 'receipt_printed_total_30d' => null, 'receipts_with_total_30d' => 0, 'receipts_missing_total_30d' => 0, 'completed_without_receipts_30d' => 0], 'empty statistics truthful');
dashboardCheck($empty['active'] === ['sessions' => [], 'next_before_id' => null] && $empty['completed'] === ['sessions' => [], 'next_before_id' => null], 'empty pages');
dashboardCheck($before === dashboardSnapshot($db), 'empty read mutated database');
foreach ([[1,'1','open',null],[2,'1','reviewing',null],[3,'1','committed','2026-09-13 12:00:00'],[4,'1','committed','2026-10-13T08:00:00-04:00'],[5,'1','committed','2026-09-13T11:59:59Z'],[6,'1','committed','2026-10-13T12:00:01Z'],[7,'2','committed','2026-10-10 00:00:00'],[8,'1','committed','2026-10-10 00:00:00'],[9,'1','open',null],[10,'1','committed',null],[11,'1','committed','2026-10-01 15:00:00'],[12,'1','committed','2026-10-01T15:00:00Z'],[13,'1','committed','invalid'],[14,'2','reviewing',null]] as $trip) dashboardTrip($db, ...$trip);
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (8, '1'), (9, '1')");
for ($seq = 1; $seq <= 3; $seq++) $db->prepare("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, status, quantity) VALUES (3, ?, ?, 'unknown', 10)")->execute([$seq, 'scan-' . $seq]);
$receipt = dashboardReceipt($db, 3, 10.25); dashboardReceipt($db, 3, 2.75);
dashboardReceipt($db, 4, null); dashboardReceipt($db, 12, 'malformed');
foreach ([1,2,5,6,7,8,9,10,13] as $id) dashboardReceipt($db, $id, 999);
// Receipt allocations/ignored lines and partial applied scans cannot change printed totals.
$db->prepare("INSERT INTO grocy_ai_receipt_lines (receipt_id, seq, description, decision, line_total) VALUES (?, 1, 'ignored', 'ignore', 88)")->execute([$receipt]);
$db->prepare('INSERT INTO grocy_ai_receipt_allocations (trip_id, receipt_id, receipt_line_id, quantity, unit_price) VALUES (3, ?, ?, 1, 88)')->execute([$receipt, $db->lastInsertId()]);
$db->exec("UPDATE grocy_ai_capture_lines SET applied_at = CURRENT_TIMESTAMP, outcome = 'applied' WHERE id = 1");
$before = dashboardSnapshot($db);
$result = $service->ReadForUser('1', null, null, $now);
dashboardCheck($result['stats'] === ['open_sessions' => 1, 'reviewing_sessions' => 1, 'completed_sessions_30d' => 4, 'receipt_printed_total_30d' => 13.0, 'receipts_with_total_30d' => 2, 'receipts_missing_total_30d' => 2, 'completed_without_receipts_30d' => 1], 'actor/status/date/money aggregates');
dashboardCheck(array_column($result['active']['sessions'], 'id') === [2,1], 'empty active retained, foreign/cancelled excluded');
dashboardCheck(array_column($result['completed']['sessions'], 'id') === [13,12,11,10,6,5,4,3], 'history includes all dates but excludes foreign/cancelled');
$joined = $result['completed']['sessions'][7];
dashboardCheck(array_keys($joined) === ['id','status','created_at','committed_at','item_count','receipt_count'] && $joined['item_count'] === 3 && $joined['receipt_count'] === 2, 'closed session DTO with distinct counts');
dashboardCheck($service->ReadForUser("1' OR 1=1 --", null, null, $now)['active']['sessions'] === [], 'actor is parameterized');
dashboardCheck($service->ReadForUser('2', null, null, $now)['stats']['receipt_printed_total_30d'] === 999.0, 'independent actor');
dashboardCheck($before === dashboardSnapshot($db), 'reads changed full database snapshot');
for ($id = 100; $id <= 129; $id++) dashboardTrip($db, $id, '1', 'open');
for ($id = 200; $id <= 229; $id++) dashboardTrip($db, $id, '1', 'committed', '2026-10-10 00:00:00');
$before = dashboardSnapshot($db);
$page = $service->ReadForUser('1', null, null, $now);
dashboardCheck(count($page['active']['sessions']) === 25 && $page['active']['next_before_id'] === 105 && count($page['completed']['sessions']) === 25 && $page['completed']['next_before_id'] === 205, 'bounded independent pages');
$next = $service->ReadForUser('1', 105, 205, $now);
dashboardCheck(array_column($next['active']['sessions'], 'id') === [104,103,102,101,100,2,1] && $next['active']['next_before_id'] === null && count($next['completed']['sessions']) === 13 && $next['completed']['next_before_id'] === null, 'exclusive cursor complete next page');
dashboardCheck($page['stats'] === $next['stats'] && $page['stats']['completed_sessions_30d'] === 34 && $page['stats']['open_sessions'] === 31, 'stats independent of page');
dashboardCheck($before === dashboardSnapshot($db), 'paged reads mutated');
dashboardTrip($db, 300, 'missing-money', 'committed', '2026-10-10 00:00:00');
foreach ([null, 'not a number', -2, '1e999'] as $invalidTotal) dashboardReceipt($db, 300, $invalidTotal);
$missingMoney = $service->ReadForUser('missing-money', null, null, $now)['stats'];
dashboardCheck($missingMoney['receipt_printed_total_30d'] === null && $missingMoney['receipts_missing_total_30d'] === 4 && $missingMoney['receipts_with_total_30d'] === 0, 'invalid and absent totals never become zero spending');
dashboardReceipt($db, 300, 0);
dashboardCheck($service->ReadForUser('missing-money', null, null, $now)['stats']['receipt_printed_total_30d'] === 0.0, 'available zero total is distinct from missing totals');
dashboardTrip($db, 301, 'finite-money', 'committed', '2026-10-10 00:00:00');
dashboardReceipt($db, 301, '1.1e308');
dashboardCheck($service->ReadForUser('finite-money', null, null, $now)['stats']['receipts_with_total_30d'] === 1, 'finite numeric totals remain available');
$before = dashboardSnapshot($db);
dashboardCheck(method_exists(GrocyAiApiController::class, 'DashboardCapture'), 'dashboard API action missing');
$reflection = new ReflectionClass(Grocy\Services\DatabaseService::class);
foreach (['DbConnectionRaw' => $db, 'DbConnection' => new LessQL\Database($db), 'instance' => $reflection->newInstance()] as $property => $value) $reflection->getProperty($property)->setValue(null, $value);
$controller = (new ReflectionClass(GrocyAiApiController::class))->newInstanceWithoutConstructor();
function dashboardApi($controller, array $query): Psr\Http\Message\ResponseInterface
{
	$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/test')->withQueryParams($query);
	return $controller->DashboardCapture($request, (new Slim\Psr7\Factory\ResponseFactory())->createResponse(), []);
}
foreach (['active_before','completed_before'] as $key) foreach ([null, [], ['12'], 12, 12.0, true, '', '0', '-1', '01', '1.2', '1e2', ' 12', '12 ', '10000000000', "1' OR 1=1"] as $value) dashboardCheck(dashboardApi($controller, [$key => $value])->getStatusCode() === 400, 'invalid cursor: ' . $key . ' ' . json_encode($value));
$response = dashboardApi($controller, ['active_before' => '105','completed_before' => '205','actor' => '2']);
$api = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
dashboardCheck($response->getStatusCode() === 200 && array_column($api['active']['sessions'], 'id') === [104,103,102,101,100,2,1], 'API server actor and valid cursors');
dashboardCheck(dashboardApi($controller, ['active_before' => '9999999999'])->getStatusCode() === 200, 'maximum allowed decimal cursor');
$app = Slim\Factory\AppFactory::create();
require __DIR__ . '/../routes.php';
$registered = [];
foreach ($app->getRouteCollector()->getRoutes() as $route) if ($route->getPattern() === '/api/grocy-ai/capture/dashboard') $registered[] = [$route->getMethods(), $route->getCallable()];
dashboardCheck($registered === [[['GET'], [GrocyAiApiController::class, 'DashboardCapture']]], 'GET dashboard route registered to read action');
class DashboardPageProbe extends GrocyAiCaptureController
{
	public array $rendered = [];
	protected function renderPage($response, $viewName, $data = []) { $this->rendered = [$viewName, $data]; return $response; }
}
$pageController = (new ReflectionClass(DashboardPageProbe::class))->newInstanceWithoutConstructor();
function dashboardLanding($controller, array $query): Psr\Http\Message\ResponseInterface
{
	$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/grocyai/capture')->withQueryParams($query);
	return $controller->Capture($request, (new Slim\Psr7\Factory\ResponseFactory())->createResponse(), []);
}
dashboardLanding($pageController, []);
dashboardCheck($pageController->rendered[0] === 'grocyai_capture_dashboard', 'no trip query renders dashboard');
dashboardLanding($pageController, ['trip' => '12']);
dashboardCheck($pageController->rendered === ['grocyai_capture', ['defaultTripId' => 12]], 'explicit trip scanner preserved');
foreach ([null, [], '', '0', '01', 'bad', 12, '10000000000'] as $invalid) dashboardCheck(dashboardLanding($pageController, ['trip' => $invalid])->getStatusCode() === 400, 'malformed landing query safe');
dashboardCheck($before === dashboardSnapshot($db), 'API/landing reads mutated database');
$db->exec('DELETE FROM user_permissions_resolved');
try { dashboardApi($controller, []); throw new RuntimeException('dashboard permission bypass'); }
catch (Grocy\Controllers\Users\PermissionMissingException $expected) {}
try { dashboardLanding($pageController, []); throw new RuntimeException('landing permission bypass'); }
catch (Grocy\Controllers\Users\PermissionMissingException $expected) {}
echo "capture dashboard contract passed\n";
