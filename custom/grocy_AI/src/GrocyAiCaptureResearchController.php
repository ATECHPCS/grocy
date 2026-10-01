<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\BaseApiController;
use Grocy\Services\DatabaseService;
use GrocyAI\Services\GrocyAiCaptureResearchService;
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
		if (!$this->Authorized($request)) return $this->GenericErrorResponse($response, 'Worker authorization failed', 403);
		$body = $this->Body($request);
		if ($body === null || !$this->HasFields($body, ['limit', 'worker_id']) || !is_int($body['limit']) || !is_string($body['worker_id'])) return $this->GenericErrorResponse($response, 'Invalid research claim', 400);
		try { return $this->ApiResponse($response, ['jobs' => $this->Service()->ClaimJobs($body['limit'], $body['worker_id'])]); }
		catch (\InvalidArgumentException) { return $this->GenericErrorResponse($response, 'Invalid research claim', 400); }
		catch (\RuntimeException) { return $this->GenericErrorResponse($response, 'Research unavailable', 503); }
	}

	public function Complete(Request $request, Response $response, array $args): Response
	{
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
}
