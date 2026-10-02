<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\BaseApiController;
use Grocy\Services\ApiKeyService;
use Grocy\Services\DatabaseService;
use Grocy\Controllers\Users\User;
use GrocyAI\Services\GrocyAiCaptureResearchService;
use GrocyAI\Services\GrocyAiCaptureProductService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GrocyAiCaptureResearchController extends BaseApiController
{
	public static function ValidWorkerKey(string $received, string $configured): bool
	{
		return $configured !== '' && $received !== '' && hash_equals($configured, $received);
	}

	private function Authorized(Request $request): bool
	{
		return self::ValidWorkerKey($request->getHeaderLine('X-Grocy-AI-Worker-Key'), (string)(defined('GROCY_AI_RESEARCH_WORKER_KEY') ? GROCY_AI_RESEARCH_WORKER_KEY : ''));
	}

	protected function HasApiCredential(Request $request): bool
	{
		$key = $request->getHeaderLine('GROCY-API-KEY');
		if ($key === '' || strlen($key) > 256) return false;
		try { return (new ApiKeyService())->IsValidApiKey($key); }
		catch (\Throwable) { return false; }
	}

	protected function Service(): GrocyAiCaptureResearchService
	{
		return new GrocyAiCaptureResearchService(DatabaseService::GetInstance()->GetDbConnectionRaw());
	}

	private function Body(Request $request): ?array
	{
		$parsed = $request->getParsedBody();
		if (is_array($parsed)) return $parsed;
		$raw = (string)$request->getBody();
		if ($raw === '' || strlen($raw) > 16384 || !str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) return null;
		try { $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
		catch (\JsonException) { return null; }
		return is_array($decoded) ? $decoded : null;
	}

	private function HasFields(array $body, array $fields): bool
	{
		return count($body) === count($fields) && array_diff($fields, array_keys($body)) === [];
	}

	public function Claim(Request $request, Response $response, array $args): Response
	{
		if (!$this->HasApiCredential($request)) return $this->GenericErrorResponse($response, 'API authentication required', 401);
		if (!$this->Authorized($request)) return $this->GenericErrorResponse($response, 'Worker authorization failed', 403);
		$body = $this->Body($request);
		if ($body === null || !$this->HasFields($body, ['limit', 'worker_id']) || !is_int($body['limit']) || !is_string($body['worker_id'])) return $this->GenericErrorResponse($response, 'Invalid research claim', 400);
		try { return $this->ApiResponse($response, ['jobs' => $this->Service()->ClaimJobs($body['limit'], $body['worker_id'])]); }
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid research claim', 400); }
		catch (\RuntimeException) { return $this->GenericErrorResponse($response, 'Research unavailable', 503); }
	}

	public function ReserveWebSearch(Request $request, Response $response, array $args): Response
	{
		if (!$this->HasApiCredential($request)) return $this->GenericErrorResponse($response, 'API authentication required', 401);
		if (!$this->Authorized($request)) return $this->GenericErrorResponse($response, 'Worker authorization failed', 403);
		$jobId = $this->JobId($args);
		$body = $this->Body($request);
		if ($jobId === null || $body === null || !$this->HasFields($body, ['lease_token']) || !is_string($body['lease_token'])) return $this->GenericErrorResponse($response, 'Invalid search reservation', 400);
		try { return $this->ApiResponse($response, $this->Service()->ReserveWebSearch($jobId, $body['lease_token'], 'research-worker')); }
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid search reservation', 400); }
		catch (\RuntimeException) { return $this->GenericErrorResponse($response, 'Research lease conflict', 409); }
	}

	public function Complete(Request $request, Response $response, array $args): Response
	{
		if (!$this->HasApiCredential($request)) return $this->GenericErrorResponse($response, 'API authentication required', 401);
		if (!$this->Authorized($request)) return $this->GenericErrorResponse($response, 'Worker authorization failed', 403);
		$jobId = $this->JobId($args);
		$body = $this->Body($request);
		if ($jobId === null || $body === null || !$this->HasFields($body, ['lease_token', 'result']) || !is_string($body['lease_token']) || !is_array($body['result'])) return $this->GenericErrorResponse($response, 'Invalid research completion', 400);
		try { return $this->ApiResponse($response, $this->Service()->CompleteJob($jobId, $body['lease_token'], $body['result'])); }
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid research completion', 400); }
		catch (\RuntimeException) { return $this->GenericErrorResponse($response, 'Research lease conflict', 409); }
	}

	public function Fail(Request $request, Response $response, array $args): Response
	{
		if (!$this->HasApiCredential($request)) return $this->GenericErrorResponse($response, 'API authentication required', 401);
		if (!$this->Authorized($request)) return $this->GenericErrorResponse($response, 'Worker authorization failed', 403);
		$jobId = $this->JobId($args);
		$body = $this->Body($request);
		if ($jobId === null || $body === null || !$this->HasFields($body, ['lease_token', 'safe_code']) || !is_string($body['lease_token']) || !is_string($body['safe_code'])) return $this->GenericErrorResponse($response, 'Invalid research failure', 400);
		try { return $this->ApiResponse($response, $this->Service()->FailJob($jobId, $body['lease_token'], $body['safe_code'])); }
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid research failure', 400); }
		catch (\RuntimeException) { return $this->GenericErrorResponse($response, 'Research lease conflict', 409); }
	}

	private function JobId(array $args): ?int
	{
		$value = $args['jobId'] ?? null;
		return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 ? (int)$value : null;
	}

	private function CaptureIds(array $args, bool $line): ?array
	{
		$trip = $args['tripId'] ?? null;
		$seq = $args['seq'] ?? null;
		if (!is_string($trip) || preg_match('/^[1-9][0-9]{0,9}$/D', $trip) !== 1 || ($line && (!is_string($seq) || preg_match('/^[1-9][0-9]{0,9}$/D', $seq) !== 1))) return null;
		return ['trip_id' => (int)$trip, 'seq' => $line ? (int)$seq : null];
	}

	private function CaptureLineId(int $tripId, int $seq): ?int
	{
		$query = DatabaseService::GetInstance()->GetDbConnectionRaw()->prepare('SELECT id FROM grocy_ai_capture_lines WHERE trip_id = ? AND seq = ?');
		$query->execute([$tripId, $seq]);
		$id = $query->fetchColumn();
		return $id === false ? null : (int)$id;
	}

	public function Review(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$ids = $this->CaptureIds($args, false);
		if ($ids === null) return $this->GenericErrorResponse($response, 'Invalid trip', 400);
		try { return $this->ApiResponse($response, $this->Service()->ReviewForTrip($ids['trip_id'])); }
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Unknown trip', 404); }
	}

	public function Options(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		return $this->ApiResponse($response, $this->Service()->ReviewOptions());
	}

	public function Update(Request $request, Response $response, array $args): Response
	{
		return $this->ReviewMutation($request, $response, $args, 'update');
	}

	public function ReceiptEvidence(Request $request, Response $response, array $args): Response
	{
		return $this->ReviewMutation($request, $response, $args, 'evidence');
	}

	public function Retry(Request $request, Response $response, array $args): Response
	{
		return $this->ReviewMutation($request, $response, $args, 'retry');
	}

	public function Approve(Request $request, Response $response, array $args): Response
	{
		return $this->ProductMutation($request, $response, $args, 'approve');
	}

	public function Link(Request $request, Response $response, array $args): Response
	{
		return $this->ProductMutation($request, $response, $args, 'link');
	}

	private function ProductMutation(Request $request, Response $response, array $args, string $kind): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$ids = $this->CaptureIds($args, true);
		$body = $this->Body($request);
		if ($ids === null || $body === null) return $this->GenericErrorResponse($response, 'Invalid product approval', 400);
		$lineId = $this->CaptureLineId($ids['trip_id'], $ids['seq']);
		if ($lineId === null) return $this->GenericErrorResponse($response, 'Unknown capture line', 404);
		try
		{
			$service = new GrocyAiCaptureProductService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			if ($kind === 'approve' && $this->HasFields($body, ['revision', 'fields']) && is_int($body['revision']) && is_array($body['fields'])) return $this->ApiResponse($response, $service->ApproveDraft($ids['trip_id'], $lineId, $body['revision'], $body['fields'], (string)GROCY_USER_ID));
			if ($kind === 'link' && $this->HasFields($body, ['revision', 'product_id']) && is_int($body['revision']) && is_int($body['product_id'])) return $this->ApiResponse($response, $service->LinkDraft($ids['trip_id'], $lineId, $body['revision'], $body['product_id'], (string)GROCY_USER_ID));
			return $this->GenericErrorResponse($response, 'Invalid product approval', 400);
		}
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid product approval', 400); }
		catch (\RuntimeException|\PDOException) { return $this->GenericErrorResponse($response, 'Product review conflict', 409); }
	}

	private function ReviewMutation(Request $request, Response $response, array $args, string $kind): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$ids = $this->CaptureIds($args, true);
		$body = $this->Body($request);
		if ($kind === 'retry' && $body === null && (string)$request->getBody() === '') $body = [];
		if ($ids === null || $body === null) return $this->GenericErrorResponse($response, 'Invalid research request', 400);
		$lineId = $this->CaptureLineId($ids['trip_id'], $ids['seq']);
		if ($lineId === null) return $this->GenericErrorResponse($response, 'Unknown capture line', 404);
		$actor = (string)GROCY_USER_ID;
		try
		{
			$service = $this->Service();
			if ($kind === 'update' && $this->HasFields($body, ['revision', 'changes']) && is_int($body['revision']) && is_array($body['changes'])) return $this->ApiResponse($response, $service->UpdateDraft($ids['trip_id'], $lineId, $body['revision'], $body['changes'], $actor));
			if ($kind === 'evidence' && $this->HasFields($body, ['receipt_line_id']) && ($body['receipt_line_id'] === null || is_int($body['receipt_line_id']))) return $this->ApiResponse($response, $service->SetReceiptEvidence($ids['trip_id'], $lineId, $body['receipt_line_id'], $actor));
			if ($kind === 'retry' && $body === []) return $this->ApiResponse($response, $service->RetryJob($ids['trip_id'], $lineId, $actor));
			return $this->GenericErrorResponse($response, 'Invalid research request', 400);
		}
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid research request', 400); }
		catch (\RuntimeException) { return $this->GenericErrorResponse($response, 'Research draft conflict', 409); }
	}
}
