<?php

namespace GrocyAI\Services;

use GuzzleHttp\Client;
use JsonException;

class GrocyAiService
{
	private $Transport;

	public function __construct(?callable $transport = null)
	{
		$this->Transport = $transport;
	}

	public function GetStatus(): array
	{
		$traceContext = GrocyAiDiagnostic::CreateTraceContext(null);
		$versions = GrocyAiDiagnostic::FailureEnvelope($traceContext, 'provider_error', 'skipped', 'not_configured')['diagnostics']['versions'];

		return [
			'module' => 'grocy_AI',
			'phase' => 1,
			'enabled' => defined('GROCY_FEATURE_FLAG_GROCY_AI') && GROCY_FEATURE_FLAG_GROCY_AI,
			'service_configured' => $this->GetServiceUrl() !== '',
			'api_key_configured' => $this->GetApiKey() !== '',
			'mode' => 'review-before-save',
			'versions' => $versions
		];
	}

	public function EnrichByUpc(string $barcode, ?array $traceContext = null): array
	{
		$upc = self::NormalizeUpc($barcode);
		$traceContext = GrocyAiDiagnostic::EnsureTraceContext($traceContext);
		$serviceUrl = $this->GetServiceUrl();

		if ($serviceUrl === '')
		{
			throw new \LogicException('The grocy_AI companion service is not configured');
		}

		$serviceScheme = strtolower((string)parse_url($serviceUrl, PHP_URL_SCHEME));
		if (filter_var($serviceUrl, FILTER_VALIDATE_URL) === false || !in_array($serviceScheme, ['http', 'https'], true))
		{
			throw new \LogicException('GROCY_AI_SERVICE_URL must be a valid HTTP or HTTPS URL');
		}

		$url = rtrim($serviceUrl, '/') . '/v1/products/enrich/upc/' . rawurlencode($upc);
		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => 'grocy_AI/1'
		];
		$apiKey = $this->GetApiKey();
		if ($apiKey !== '')
		{
			$headers['X-API-Key'] = $apiKey;
		}
		$headers['traceparent'] = $traceContext['traceparent'];

		$result = $this->Request($url, $headers);
		if ($result['status'] < 200 || $result['status'] >= 300)
		{
			if (in_array($result['status'], [408, 504], true))
			{
				throw new GrocyAiServiceException('timeout', 'timeout', 'deadline', 504);
			}
			$status = in_array($result['status'], [502, 503], true) ? 'unavailable' : 'error';
			throw new GrocyAiServiceException('provider_error', $status, 'http_status', 502);
		}

		try
		{
			$data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $ex)
		{
			throw new GrocyAiServiceException('provider_error', 'malformed', 'invalid_response', 502, $ex);
		}

		if (!is_array($data))
		{
			throw new GrocyAiServiceException('provider_error', 'malformed', 'invalid_response', 502);
		}

		return $this->NormalizeResponse($upc, $data, $traceContext, $result['timing'] ?? []);
	}

	public function FetchImage(string $token): array
	{
		if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/', $token))
		{
			throw new \InvalidArgumentException('Invalid image selection');
		}

		$serviceUrl = $this->GetServiceUrl();
		if ($serviceUrl === '')
		{
			throw new \LogicException('The grocy_AI companion service is not configured');
		}

		$url = rtrim($serviceUrl, '/') . '/v1/products/images/' . rawurlencode($token);
		$headers = [
			'Accept' => 'image/png,image/jpeg,image/webp',
			'User-Agent' => 'grocy_AI/1'
		];
		$apiKey = $this->GetApiKey();
		if ($apiKey !== '')
		{
			$headers['X-API-Key'] = $apiKey;
		}

		$result = $this->Request($url, $headers);
		if ($result['status'] < 200 || $result['status'] >= 300)
		{
			throw new \RuntimeException('The selected image is unavailable; search again');
		}

		$contentType = strtolower(trim(explode(';', (string)($result['content_type'] ?? ''))[0]));
		if (!in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true))
		{
			throw new \RuntimeException('The selected file is not a supported product image');
		}
		$body = (string)$result['body'];
		if (strlen($body) < 2000 || strlen($body) > 3000000)
		{
			throw new \RuntimeException('The selected product image has an invalid size');
		}
		if (!self::HasImageSignature($body, $contentType))
		{
			throw new \RuntimeException('The selected product image has an invalid format');
		}

		return ['body' => $body, 'content_type' => $contentType];
	}

	public static function NormalizeUpc(string $barcode): string
	{
		$upc = str_replace([' ', '-'], '', trim($barcode));
		if (!preg_match('/^\d{8}$|^\d{12,14}$/', $upc))
		{
			throw new \InvalidArgumentException('UPC must contain 8, 12, 13, or 14 digits');
		}

		$weightedSum = 0;
		$body = substr($upc, 0, -1);
		for ($offset = 0, $length = strlen($body); $offset < $length; $offset++)
		{
			$digit = (int)$body[$length - $offset - 1];
			$weightedSum += $digit * ($offset % 2 === 0 ? 3 : 1);
		}
		$expectedCheckDigit = (10 - ($weightedSum % 10)) % 10;
		if ((int)$upc[strlen($upc) - 1] !== $expectedCheckDigit)
		{
			throw new \InvalidArgumentException('UPC checksum is invalid');
		}

		return $upc;
	}

	private function Request(string $url, array $headers): array
	{
		$timing = ['transfer_ms' => null, 'connect_ms' => null];
		$options = $this->RequestOptions($timing);
		try
		{
			if ($this->Transport !== null)
			{
				$result = ($this->Transport)($url, $headers, $options);
				if (!is_array($result) || !isset($result['status'], $result['body']))
				{
					throw new GrocyAiServiceException('provider_error', 'malformed', 'invalid_response', 502);
				}
				$result['timing'] = $timing;
				return $result;
			}

			$client = new Client();
			$requestOptions = $options;
			$requestOptions['headers'] = $headers;
			$requestOptions['http_errors'] = false;
			$response = $client->request('GET', $url, $requestOptions);

			return [
				'status' => $response->getStatusCode(),
				'body' => (string)$response->getBody(),
				'content_type' => $response->getHeaderLine('Content-Type'),
				'timing' => $timing
			];
		}
		catch (GrocyAiServiceException $ex)
		{
			throw $ex;
		}
		catch (\Throwable $ex)
		{
			$isTimeout = method_exists($ex, 'getHandlerContext') && ($ex->getHandlerContext()['errno'] ?? null) === 28;
			throw new GrocyAiServiceException(
				$isTimeout ? 'timeout' : 'provider_error',
				$isTimeout ? 'timeout' : 'unavailable',
				$isTimeout ? 'deadline' : 'connection',
				$isTimeout ? 504 : 502,
				$ex
			);
		}
	}

	private function RequestOptions(array &$timing): array
	{
		return [
			'timeout' => 12.0,
			'connect_timeout' => 2.0,
			// Never forward the optional API key or owned trace to a redirected host.
			'allow_redirects' => false,
			'on_stats' => static function ($stats) use (&$timing): void
			{
				if (!is_object($stats) || !method_exists($stats, 'getTransferTime'))
				{
					return;
				}
				$timing['transfer_ms'] = GrocyAiDiagnostic::NormalizeDuration($stats->getTransferTime() * 1000, 12000);
				$handlerStats = method_exists($stats, 'getHandlerStats') ? $stats->getHandlerStats() : [];
				if (is_array($handlerStats) && array_key_exists('connect_time', $handlerStats))
				{
					$timing['connect_ms'] = GrocyAiDiagnostic::NormalizeDuration($handlerStats['connect_time'] * 1000, 12000);
				}
			}
		];
	}

	private function NormalizeResponse(string $upc, array $data, array $traceContext, array $transportTiming): array
	{
		$product = is_array($data['product'] ?? null) ? $data['product'] : [];
		$images = [];
		$imageCandidates = is_array($data['images'] ?? null) ? $data['images'] : [];
		foreach ($imageCandidates as $image)
		{
			if (!is_array($image) || !isset($image['url']) || filter_var($image['url'], FILTER_VALIDATE_URL) === false)
			{
				continue;
			}

			$scheme = strtolower((string)parse_url($image['url'], PHP_URL_SCHEME));
			if (!in_array($scheme, ['http', 'https'], true))
			{
				continue;
			}

			$images[] = [
				'url' => $image['url'],
				'download_token' => self::ImageToken($image['download_token'] ?? ''),
				'source' => self::ScalarString($image['source'] ?? ''),
				'score' => is_numeric($image['score'] ?? null) ? (float)$image['score'] : null,
				'match_confidence' => is_numeric($image['match_confidence'] ?? null) ? (float)$image['match_confidence'] : null
			];
		}

		$outcome = array_key_exists('outcome', $data)
			? GrocyAiDiagnostic::NormalizeOutcome($data['outcome'])
			: ((bool)($data['found'] ?? !empty($product)) ? 'success' : 'not_found');
		$companionDiagnostics = is_array($data['diagnostics'] ?? null) ? $data['diagnostics'] : [];

		return [
			'found' => (bool)($data['found'] ?? !empty($product)),
			'upc' => $upc,
			'product' => [
				'name' => self::ScalarString($product['name'] ?? ''),
				'brand' => self::ScalarString($product['brand'] ?? ''),
				'size' => self::ScalarString($product['size'] ?? '')
			],
			'images' => array_slice($images, 0, 20),
			'sources' => self::StringList($data['sources'] ?? []),
			'warnings' => self::StringList($data['warnings'] ?? []),
			'outcome' => $outcome,
			'diagnostics' => GrocyAiDiagnostic::NormalizeCompanionDiagnostics(
				$companionDiagnostics,
				$traceContext['trace_id'],
				$outcome,
				$transportTiming
			)
		];
	}

	private static function ScalarString($value): string
	{
		return is_scalar($value) ? trim((string)$value) : '';
	}

	private static function ImageToken($value): string
	{
		$value = self::ScalarString($value);
		return preg_match('/^[A-Za-z0-9_-]{20,200}$/', $value) ? $value : '';
	}

	private static function HasImageSignature(string $body, string $contentType): bool
	{
		if ($contentType === 'image/png')
		{
			return str_starts_with($body, "\x89PNG\r\n\x1a\n");
		}
		if ($contentType === 'image/jpeg')
		{
			return str_starts_with($body, "\xFF\xD8\xFF");
		}
		if ($contentType === 'image/webp')
		{
			return strlen($body) >= 12 && str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP';
		}
		return false;
	}

	private static function StringList($values): array
	{
		if (!is_array($values))
		{
			return [];
		}

		$result = [];
		foreach ($values as $value)
		{
			if (is_scalar($value) && trim((string)$value) !== '')
			{
				$result[] = trim((string)$value);
			}
		}

		return array_values(array_unique($result));
	}

	private function GetServiceUrl(): string
	{
		return defined('GROCY_AI_SERVICE_URL') ? trim((string)GROCY_AI_SERVICE_URL) : '';
	}

	private function GetApiKey(): string
	{
		return defined('GROCY_AI_SERVICE_API_KEY') ? trim((string)GROCY_AI_SERVICE_API_KEY) : '';
	}

}
