<?php

namespace GrocyAI\Services;

class GrocyAiDiagnostic
{
	public const SCHEMA_VERSION = 1;
	public const OUTCOMES = ['success', 'partial_image', 'not_found', 'timeout', 'provider_error'];
	public const STAGE_NAMES = ['grocy_connect', 'grocy_companion', 'federation', 'open_food_facts', 'image_search', 'image_fetch'];
	public const STAGE_STATUSES = ['ok', 'not_found', 'timeout', 'unavailable', 'error', 'malformed', 'skipped'];
	public const ERROR_CODES = [null, 'deadline', 'connection', 'http_status', 'invalid_response', 'invalid_gtin', 'not_configured', 'provider_error', 'budget_exhausted'];
	public const CACHE_STATUSES = ['hit', 'miss', 'bypass', 'unknown'];
	public const SERVER_TIMING_NAMES = ['grocy_connect', 'grocy_companion', 'federation', 'open_food_facts', 'image_search', 'image_fetch'];

	private const TRACE_PATTERN = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D';
	private const TRACE_ID_PATTERN = '/^(?!0{32}$)[0-9a-f]{32}$/D';
	private const PARENT_ID_PATTERN = '/^(?!0{16}$)[0-9a-f]{16}$/D';
	private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/D';
	private const MAX_STAGE_DURATION_MS = 10000;
	private const MAX_OVERALL_DURATION_MS = 12000;

	public static function CreateTraceContext(?string $traceparent, ?callable $randomBytes = null): array
	{
		$traceId = null;
		$flags = '01';
		$matches = [];
		if (is_string($traceparent) && preg_match(self::TRACE_PATTERN, $traceparent, $matches) === 1
			&& preg_match(self::TRACE_ID_PATTERN, $matches[1]) === 1
			&& preg_match(self::PARENT_ID_PATTERN, $matches[2]) === 1)
		{
			$traceId = $matches[1];
			$flags = $matches[3];
		}

		$randomBytes = $randomBytes ?? static fn(int $size): string => random_bytes($size);
		if ($traceId === null)
		{
			$traceId = self::NonzeroRandomHex(16, $randomBytes);
		}
		$parentId = self::NonzeroRandomHex(8, $randomBytes);

		return [
			'trace_id' => $traceId,
			'parent_id' => $parentId,
			'flags' => $flags,
			'traceparent' => '00-' . $traceId . '-' . $parentId . '-' . $flags
		];
	}

	public static function EnsureTraceContext(?array $traceContext): array
	{
		if ($traceContext !== null
			&& preg_match(self::TRACE_ID_PATTERN, (string)($traceContext['trace_id'] ?? '')) === 1
			&& preg_match(self::PARENT_ID_PATTERN, (string)($traceContext['parent_id'] ?? '')) === 1
			&& preg_match('/^[0-9a-f]{2}$/D', (string)($traceContext['flags'] ?? '')) === 1)
		{
			$expected = '00-' . $traceContext['trace_id'] . '-' . $traceContext['parent_id'] . '-' . $traceContext['flags'];
			if (hash_equals($expected, (string)($traceContext['traceparent'] ?? '')))
			{
				return [
					'trace_id' => $traceContext['trace_id'],
					'parent_id' => $traceContext['parent_id'],
					'flags' => $traceContext['flags'],
					'traceparent' => $expected
				];
			}
		}

		return self::CreateTraceContext(null);
	}

	public static function NormalizeDuration($value, int $maximum): ?int
	{
		if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value))
		{
			return null;
		}

		return max(0, min($maximum, (int)round((float)$value)));
	}

	public static function NormalizeOutcome($value): string
	{
		return is_string($value) && in_array($value, self::OUTCOMES, true) ? $value : 'provider_error';
	}

	public static function NormalizeCompanionDiagnostics(array $data, string $traceId, string $outcome, array $transportTiming = []): array
	{
		$companionVersion = self::SafeVersion($data['companion_version'] ?? null);
		$stages = [];

		$connectDuration = self::NormalizeDuration($transportTiming['connect_ms'] ?? null, self::MAX_OVERALL_DURATION_MS);
		if ($connectDuration !== null)
		{
			$stages[] = self::MakeStage('grocy_connect', 'ok', null, 'unknown', $connectDuration);
		}

		$transferDuration = self::NormalizeDuration($transportTiming['transfer_ms'] ?? null, self::MAX_OVERALL_DURATION_MS);
		if ($transferDuration !== null)
		{
			$status = match ($outcome)
			{
				'timeout' => 'timeout',
				'provider_error' => 'error',
				'not_found' => 'not_found',
				default => 'ok'
			};
			$errorCode = $outcome === 'timeout' ? 'deadline' : ($outcome === 'provider_error' ? 'provider_error' : null);
			$stages[] = self::MakeStage('grocy_companion', $status, $errorCode, 'unknown', $transferDuration);
		}

		$providerStages = is_array($data['stages'] ?? null) ? $data['stages'] : [];
		foreach (array_slice($providerStages, 0, 4) as $providerStage)
		{
			$stage = self::NormalizeStage($providerStage);
			if ($stage !== null)
			{
				$stages[] = $stage;
			}
		}

		$companionDuration = self::NormalizeDuration($data['overall_duration_ms'] ?? null, 10500);
		$overallDuration = $transferDuration ?? $companionDuration;

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'versions' => self::Versions($companionVersion),
			'trace_id' => preg_match(self::TRACE_ID_PATTERN, $traceId) === 1 ? $traceId : self::CreateTraceContext(null)['trace_id'],
			'outcome' => $outcome,
			'stages' => $stages,
			'overall_duration_ms' => $overallDuration
		];
	}

	public static function FailureEnvelope(array $traceContext, string $outcome, string $status, ?string $errorCode, ?string $rawException = null): array
	{
		$traceContext = self::EnsureTraceContext($traceContext);
		$outcome = self::NormalizeOutcome($outcome);
		$status = in_array($status, self::STAGE_STATUSES, true) ? $status : 'error';
		$errorCode = in_array($errorCode, self::ERROR_CODES, true) ? $errorCode : 'provider_error';

		return [
			'found' => false,
			'product' => ['name' => '', 'brand' => '', 'size' => ''],
			'images' => [],
			'sources' => [],
			'warnings' => [],
			'outcome' => $outcome,
			'diagnostics' => [
				'schema_version' => self::SCHEMA_VERSION,
				'versions' => self::Versions('unknown'),
				'trace_id' => $traceContext['trace_id'],
				'outcome' => $outcome,
				'stages' => [self::MakeStage('grocy_companion', $status, $errorCode, 'unknown', null)],
				'overall_duration_ms' => null
			]
		];
	}

	public static function ServerTiming(array $diagnostics): string
	{
		$metrics = [];
		$stages = is_array($diagnostics['stages'] ?? null) ? $diagnostics['stages'] : [];
		foreach ($stages as $stage)
		{
			if (!is_array($stage) || !in_array($stage['name'] ?? null, self::SERVER_TIMING_NAMES, true))
			{
				continue;
			}
			$duration = self::NormalizeDuration($stage['duration_ms'] ?? null, self::MAX_OVERALL_DURATION_MS);
			if ($duration !== null)
			{
				$metrics[] = $stage['name'] . ';dur=' . $duration;
			}
		}

		return implode(', ', array_slice($metrics, 0, count(self::SERVER_TIMING_NAMES)));
	}

	private static function NormalizeStage($value): ?array
	{
		if (!is_array($value)
			|| !in_array($value['name'] ?? null, self::STAGE_NAMES, true)
			|| !in_array($value['status'] ?? null, self::STAGE_STATUSES, true)
			|| !in_array($value['cache'] ?? null, self::CACHE_STATUSES, true))
		{
			return null;
		}

		$errorCode = $value['error_code'] ?? null;
		if (!in_array($errorCode, self::ERROR_CODES, true))
		{
			$errorCode = 'provider_error';
		}

		return self::MakeStage(
			$value['name'],
			$value['status'],
			$errorCode,
			$value['cache'],
			self::NormalizeDuration($value['duration_ms'] ?? null, self::MAX_STAGE_DURATION_MS)
		);
	}

	private static function MakeStage(string $name, string $status, ?string $errorCode, string $cache, ?int $duration): array
	{
		return [
			'name' => $name,
			'status' => $status,
			'error_code' => $errorCode,
			'cache' => $cache,
			'duration_ms' => $duration
		];
	}

	private static function Versions(string $companionVersion): array
	{
		$module = self::ReadJson(dirname(__DIR__) . '/module-version.json');
		$grocy = self::ReadJson(dirname(__DIR__, 3) . '/version.json');

		return [
			'grocy' => self::SafeVersion($grocy['Version'] ?? null),
			'module' => self::SafeVersion($module['module_version'] ?? null),
			'companion' => $companionVersion,
			'contract' => self::SafeVersion($module['diagnostic_contract_version'] ?? null)
		];
	}

	private static function SafeVersion($value): string
	{
		return is_string($value) && preg_match(self::VERSION_PATTERN, $value) === 1 ? $value : 'unknown';
	}

	private static function ReadJson(string $path): array
	{
		$content = is_file($path) ? file_get_contents($path) : false;
		if (!is_string($content))
		{
			return [];
		}
		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	private static function NonzeroRandomHex(int $size, callable $randomBytes): string
	{
		do
		{
			$bytes = $randomBytes($size);
			if (!is_string($bytes) || strlen($bytes) !== $size)
			{
				throw new \RuntimeException('Secure random source returned an invalid value');
			}
			$value = bin2hex($bytes);
		}
		while ($value === str_repeat('0', $size * 2));

		return $value;
	}
}

class GrocyAiServiceException extends \RuntimeException
{
	private string $DiagnosticOutcome;
	private string $DiagnosticStatus;
	private string $DiagnosticErrorCode;
	private int $HttpStatus;

	public function __construct(string $outcome, string $status, string $errorCode, int $httpStatus, ?\Throwable $previous = null)
	{
		parent::__construct('The grocy_AI companion request failed', 0, $previous);
		$this->DiagnosticOutcome = GrocyAiDiagnostic::NormalizeOutcome($outcome);
		$this->DiagnosticStatus = in_array($status, GrocyAiDiagnostic::STAGE_STATUSES, true) ? $status : 'error';
		$this->DiagnosticErrorCode = in_array($errorCode, GrocyAiDiagnostic::ERROR_CODES, true) ? $errorCode : 'provider_error';
		$this->HttpStatus = in_array($httpStatus, [400, 502, 503, 504], true) ? $httpStatus : 502;
	}

	public function GetDiagnosticOutcome(): string
	{
		return $this->DiagnosticOutcome;
	}

	public function GetDiagnosticStatus(): string
	{
		return $this->DiagnosticStatus;
	}

	public function GetDiagnosticErrorCode(): string
	{
		return $this->DiagnosticErrorCode;
	}

	public function GetHttpStatus(): int
	{
		return $this->HttpStatus;
	}
}
