<?php

namespace GrocyAI\Services;

use GuzzleHttp\Client;

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
			$data = GrocyAiContract::DecodeAndValidateRaw((string)$result['body'], $upc);
			if (!hash_equals($traceContext['trace_id'], $data['diagnostics']['trace_id']))
			{
				throw new GrocyAiContractException();
			}
			return $data;
		}
		catch (GrocyAiContractException $ex)
		{
			throw new GrocyAiServiceException('provider_error', 'malformed', 'contract_invalid', 502, $ex);
		}
	}

	public function FetchImage(string $variant, string $token): array
	{
		if (!in_array($variant, ['thumbnail', 'full'], true))
		{
			throw new \InvalidArgumentException('Invalid image variant');
		}
		if (!preg_match('/^[A-Za-z0-9_-]{20,200}$/', $token))
		{
			throw new \InvalidArgumentException('Invalid image selection');
		}

		$serviceUrl = $this->GetServiceUrl();
		if ($serviceUrl === '')
		{
			throw new \LogicException('The grocy_AI companion service is not configured');
		}

		$url = rtrim($serviceUrl, '/') . '/v1/products/images/' . rawurlencode($variant) . '/' . rawurlencode($token);
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

		$contentType = strtolower(trim((string)($result['content_type'] ?? '')));
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
		$imageInfo = @getimagesizefromstring($body);
		if ($imageInfo === false)
		{
			throw new \RuntimeException('The selected product image dimensions are invalid');
		}
		$width = (int)($imageInfo[0] ?? 0);
		$height = (int)($imageInfo[1] ?? 0);
		if ($width < 32 || $height < 32 || $width > 4096 || $height > 4096 || ($width * $height) > 16000000)
		{
			throw new \RuntimeException('The selected product image dimensions are outside the allowed bounds');
		}

		return ['body' => $body, 'content_type' => $contentType];
	}

	public static function NormalizeUpc(string $barcode): string
	{
		return GrocyAiGtin::NormalizeOrThrow($barcode);
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

	private function GetServiceUrl(): string
	{
		return defined('GROCY_AI_SERVICE_URL') ? trim((string)GROCY_AI_SERVICE_URL) : '';
	}

	private function GetApiKey(): string
	{
		return defined('GROCY_AI_SERVICE_API_KEY') ? trim((string)GROCY_AI_SERVICE_API_KEY) : '';
	}

}
