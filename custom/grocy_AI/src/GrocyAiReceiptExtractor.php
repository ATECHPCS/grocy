<?php

namespace GrocyAI\Services;

use GuzzleHttp\Client;
use PDO;
use RuntimeException;

class GrocyAiReceiptExtractor
{
	private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
	// Covers 200 items + 50 adjustments with 240 Unicode characters each,
	// including JSON surrogate escapes (12 bytes per character) and field overhead.
	private const MAX_RESPONSE_BYTES = 1024 * 1024;
	private PDO $Db;
	private GrocyAiReceiptImageStore $Images;
	private GrocyAiReceiptService $Receipts;
	private $Transport;
	private string $ServiceUrl;
	private string $ApiKey;

	public function __construct(?PDO $pdo = null, ?string $dataPath = null, ?string $serviceUrl = null, ?string $apiKey = null, ?callable $transport = null)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		$this->Images = new GrocyAiReceiptImageStore($this->Db, $dataPath);
		$this->Receipts = new GrocyAiReceiptService($this->Db);
		$this->ServiceUrl = trim($serviceUrl ?? (defined('GROCY_AI_SERVICE_URL') ? (string)GROCY_AI_SERVICE_URL : ''));
		$this->ApiKey = trim($apiKey ?? (defined('GROCY_AI_SERVICE_API_KEY') ? (string)GROCY_AI_SERVICE_API_KEY : ''));
		$this->Transport = $transport ?? [$this, 'Send'];
	}

	public function Extract(int $tripId, int $receiptId): array
	{
		$receipt = $this->FindReceipt($tripId, $receiptId);
		if ($receipt === null) throw new \InvalidArgumentException('Receipt not found');
		if ($receipt['trip_status'] === 'committed' || (int)$receipt['trip_canceled'] === 1) throw new \InvalidArgumentException('Closed trip is read only');
		$view = $this->View($tripId, $receiptId);
		if ($receipt['extraction_json'] !== null) return ['status' => $view['receipt']['status'], 'receipt' => $view];
		if ($view['lines'] !== []) return ['status' => 'manual_entry', 'message' => 'Receipt already has manual entries', 'receipt' => $view];
		if ($this->HasManualHeaderEdits($receiptId)) return ['status' => 'manual_entry', 'message' => 'Receipt has manual edits', 'receipt' => $view];
		try
		{
			if ($this->ServiceUrl === '' || $this->ApiKey === '' || filter_var($this->ServiceUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($this->ServiceUrl, PHP_URL_SCHEME), ['http', 'https'], true)) throw new RuntimeException('Provider unavailable');
			if ((int)$receipt['image_bytes'] < 1 || (int)$receipt['image_bytes'] > self::MAX_IMAGE_BYTES) throw new RuntimeException('Image size exceeds OCR limit');
			$image = $this->Images->Read($tripId, (string)$receipt['image_id']);
			if (strlen($image['bytes']) > self::MAX_IMAGE_BYTES || !in_array($image['mime_type'], ['image/png', 'image/jpeg', 'image/webp'], true)) throw new RuntimeException('Image is unsuitable for OCR');
			$requestId = hash('sha256', $tripId . ':' . $receiptId . ':' . $receipt['revision'] . ':' . $receipt['image_id']);
			$response = ($this->Transport)(rtrim($this->ServiceUrl, '/') . '/v1/receipts/extract', [
				'X-API-Key' => $this->ApiKey,
				'X-Request-ID' => $requestId,
				'Content-Type' => $image['mime_type'],
				'Accept' => 'application/json'
			], $image['bytes']);
			if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null) || strlen($response['body']) > self::MAX_RESPONSE_BYTES) throw new RuntimeException('Invalid OCR response');
			if ($response['status'] !== 200) throw new RuntimeException('OCR unavailable');
			$extraction = $this->ValidateResponse($response['body']);
			$current = $this->FindReceipt($tripId, $receiptId);
			if ($current === null || (int)$current['revision'] !== (int)$receipt['revision'] || $current['trip_status'] === 'committed' || (int)$current['trip_canceled'] === 1) throw new RuntimeException('Receipt changed during OCR');
			$view = $this->Receipts->ImportExtraction($receiptId, $extraction, null, (int)$receipt['revision']);
			return ['status' => 'needs_review', 'receipt' => $view];
		}
		catch (\Throwable $error)
		{
			// Provider and image failures are reviewable; never expose exception text or response data.
			return ['status' => 'manual_entry', 'message' => 'Receipt extraction unavailable. Enter the receipt manually or retry.', 'receipt' => $this->View($tripId, $receiptId)];
		}
	}

	public function Retry(int $tripId, int $receiptId): array
	{
		return $this->Extract($tripId, $receiptId);
	}

	private function FindReceipt(int $tripId, int $receiptId): ?array
	{
		$statement = $this->Db->prepare('SELECT r.*, t.status AS trip_status, EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id) AS trip_canceled FROM grocy_ai_receipts r JOIN grocy_ai_capture_trips t ON t.id = r.trip_id WHERE r.trip_id = ? AND r.id = ?');
		$statement->execute([$tripId, $receiptId]);
		return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	private function View(int $tripId, int $receiptId): array
	{
		foreach ($this->Receipts->ListForTrip($tripId) as $view) if ((int)$view['receipt']['id'] === $receiptId) return $view;
		throw new \InvalidArgumentException('Receipt not found');
	}

	private function HasManualHeaderEdits(int $receiptId): bool
	{
		$statement = $this->Db->prepare("SELECT 1 FROM grocy_ai_receipt_audit WHERE receipt_id = ? AND action = 'update_receipt' LIMIT 1");
		$statement->execute([$receiptId]);
		return $statement->fetchColumn() !== false;
	}

	private function ValidateResponse(string $body): array
	{
		try { $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException $error) { throw new RuntimeException('Invalid OCR response'); }
		if (!is_array($value) || array_diff(array_keys($value), ['merchant', 'purchase_date', 'printed_total', 'currency', 'lines', 'adjustments', 'diagnostics']) !== [] || count($value) !== 7) throw new RuntimeException('Invalid OCR response');
		foreach (['merchant', 'purchase_date', 'printed_total', 'currency'] as $key) if (!array_key_exists($key, $value)) throw new RuntimeException('Invalid OCR response');
		if (!$this->Text($value['merchant'], 160, true) || !$this->Date($value['purchase_date']) || !$this->Decimal($value['printed_total'], true) || ($value['currency'] !== null && (!is_string($value['currency']) || !preg_match('/^[A-Z]{3}$/D', $value['currency'])))) throw new RuntimeException('Invalid OCR response');
		if (!is_array($value['lines']) || !array_is_list($value['lines']) || count($value['lines']) > 200 || !is_array($value['adjustments']) || !array_is_list($value['adjustments']) || count($value['adjustments']) > 50) throw new RuntimeException('Invalid OCR response');
		if (!is_array($value['diagnostics']) || !$this->Keys($value['diagnostics'], ['provider', 'warnings']) || !$this->Text($value['diagnostics']['provider'], 80, false) || !is_array($value['diagnostics']['warnings']) || !array_is_list($value['diagnostics']['warnings']) || count($value['diagnostics']['warnings']) > 20) throw new RuntimeException('Invalid OCR response');
		foreach ($value['diagnostics']['warnings'] as $warning) if (!$this->Text($warning, 240, false)) throw new RuntimeException('Invalid OCR response');
		foreach ($value['lines'] as $line)
		{
			if (!is_array($line) || !$this->Keys($line, ['description', 'quantity', 'line_total', 'kind', 'confidence']) || !$this->Text($line['description'], 240, false) || !$this->Decimal($line['quantity'], true, true) || !$this->Decimal($line['line_total'], true) || !in_array($line['kind'], ['item', 'tax', 'discount', 'deposit', 'other'], true) || !$this->Confidence($line['confidence'])) throw new RuntimeException('Invalid OCR response');
		}
		foreach ($value['adjustments'] as $adjustment)
		{
			if (!is_array($adjustment) || !$this->Keys($adjustment, ['description', 'amount', 'kind', 'confidence']) || !$this->Text($adjustment['description'], 240, false) || !$this->Decimal($adjustment['amount'], false) || !in_array($adjustment['kind'], ['tax', 'discount', 'deposit', 'other'], true) || !$this->Confidence($adjustment['confidence'])) throw new RuntimeException('Invalid OCR response');
			$value['lines'][] = ['description' => $adjustment['description'], 'quantity' => null, 'line_total' => $adjustment['amount'], 'kind' => $adjustment['kind'], 'confidence' => $adjustment['confidence']];
		}
		$value['adjustments'] = [];
		return $value;
	}

	private function Text(mixed $value, int $limit, bool $nullable): bool
	{
		return ($nullable && $value === null) || (is_string($value) && trim($value) !== '' && mb_strlen($value, 'UTF-8') <= $limit);
	}

	private function Keys(array $value, array $expected): bool
	{
		$keys = array_keys($value);
		sort($keys);
		sort($expected);
		return $keys === $expected;
	}

	private function Date(mixed $value): bool
	{
		if ($value === null) return true;
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}

	private function Decimal(mixed $value, bool $nullable, bool $positive = false): bool
	{
		if ($nullable && $value === null) return true;
		return is_string($value) && preg_match('/^-?\d{1,9}(?:\.\d{1,4})?$/D', $value) && (!$positive || (float)$value > 0);
	}

	private function Confidence(mixed $value): bool
	{
		return $value === null || ((is_int($value) || is_float($value)) && $value >= 0 && $value <= 1);
	}

	private function Send(string $url, array $headers, string $body): array
	{
		$client = new Client(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 28.0, 'connect_timeout' => 2.0]);
		$response = $client->post($url, ['headers' => $headers, 'body' => $body, 'stream' => true]);
		$stream = $response->getBody();
		$content = '';
		while (!$stream->eof())
		{
			$chunk = $stream->read(8192);
			if ($chunk === '') throw new RuntimeException('OCR response stalled');
			$content .= $chunk;
			if (strlen($content) > self::MAX_RESPONSE_BYTES) throw new RuntimeException('OCR response too large');
		}
		return ['status' => $response->getStatusCode(), 'body' => $content];
	}
}
