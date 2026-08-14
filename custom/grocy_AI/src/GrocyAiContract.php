<?php

namespace GrocyAI\Services;

use DateTimeImmutable;
use JsonException;

class GrocyAiContract
{
	public const CONTRACT_VERSION = 2;
	public const OUTCOMES = ['found', 'not_found', 'timeout', 'provider_error'];
	public const BARCODE_STATUSES = ['unused', 'owned_current', 'owned_other'];
	public const FIELDS = ['name', 'brand', 'package_size', 'product_group', 'quantity_unit', 'food_type'];
	public const SOURCE_IDS = ['openfoodfacts', 'bb-federation', 'searxng'];
	public const CONFIDENCE_BANDS = ['high', 'medium', 'low', 'unverified'];
	public const REASON_CODES = ['canonical_structured_match', 'mapped_local_option', 'inferred_provider_data', 'unverified_search_result', 'canonical_structured_front_image'];
	public const EVIDENCE_KINDS = ['structured_direct', 'mapped', 'inferred', 'search'];
	public const WARNING_CODES = ['image_search_unavailable', 'no_structured_record', 'no_media', 'provider_timeout', 'provider_error'];
	public const MAX_JSON_DEPTH = 64;

	private const GTIN_PATTERN = '/^(?:\d{8}|\d{12,14})$/D';
	private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D';
	private const TRACE_ID_PATTERN = '/^(?!0{32}$)[0-9a-f]{32}$/D';
	private const HANDLE_PATTERN = '/^[A-Za-z0-9_-]{20,200}$/D';
	private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';
	private const MAX_TEXT_LENGTH = 500;

	public static function DecodeAndValidateRaw(string $raw, string $requestedGtin = ''): array
	{
		try
		{
			$offset = 0;
			self::ParseValue($raw, $offset, 0);
			self::SkipWhitespace($raw, $offset);
			if ($offset !== strlen($raw))
			{
				self::Invalid();
			}

			$data = json_decode($raw, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
		}
		catch (GrocyAiContractException $ex)
		{
			throw $ex;
		}
		catch (JsonException $ex)
		{
			throw new GrocyAiContractException($ex);
		}

		if (!is_array($data) || array_is_list($data))
		{
			self::Invalid();
		}

		self::AssertKeys($data, ['contract_version', 'outcome', 'barcode', 'suggestions', 'media', 'warnings', 'diagnostics']);
		if (($data['contract_version'] ?? null) !== self::CONTRACT_VERSION
			|| !in_array($data['outcome'] ?? null, self::OUTCOMES, true))
		{
			self::Invalid();
		}

		self::ValidateBarcode($data['barcode'] ?? null, $requestedGtin);
		self::ValidateSuggestions($data['suggestions'] ?? null);
		self::ValidateMedia($data['media'] ?? null);
		self::ValidateWarnings($data['warnings'] ?? null);
		self::ValidateDiagnostics($data['diagnostics'] ?? null);

		return $data;
	}

	private static function ParseValue(string $raw, int &$offset, int $depth): void
	{
		self::SkipWhitespace($raw, $offset);
		if ($offset >= strlen($raw))
		{
			self::Invalid();
		}

		$character = $raw[$offset];
		if ($character === '{')
		{
			self::ParseObject($raw, $offset, $depth + 1);
			return;
		}
		if ($character === '[')
		{
			self::ParseArray($raw, $offset, $depth + 1);
			return;
		}
		if ($character === '"')
		{
			self::ReadStringToken($raw, $offset);
			return;
		}

		$start = $offset;
		while ($offset < strlen($raw)
			&& !str_contains(",]} \t\r\n", $raw[$offset]))
		{
			$offset++;
		}
		if ($start === $offset)
		{
			self::Invalid();
		}
	}

	private static function ParseObject(string $raw, int &$offset, int $depth): void
	{
		if ($depth > self::MAX_JSON_DEPTH)
		{
			self::Invalid();
		}
		$offset++;
		$keys = [];
		self::SkipWhitespace($raw, $offset);
		if (($raw[$offset] ?? null) === '}')
		{
			$offset++;
			return;
		}

		while (true)
		{
			if (($raw[$offset] ?? null) !== '"')
			{
				self::Invalid();
			}
			$key = self::ReadStringToken($raw, $offset);
			if (array_key_exists($key, $keys))
			{
				self::Invalid();
			}
			$keys[$key] = true;
			self::SkipWhitespace($raw, $offset);
			if (($raw[$offset] ?? null) !== ':')
			{
				self::Invalid();
			}
			$offset++;
			self::ParseValue($raw, $offset, $depth);
			self::SkipWhitespace($raw, $offset);
			$separator = $raw[$offset] ?? null;
			if ($separator === '}')
			{
				$offset++;
				return;
			}
			if ($separator !== ',')
			{
				self::Invalid();
			}
			$offset++;
			self::SkipWhitespace($raw, $offset);
		}
	}

	private static function ParseArray(string $raw, int &$offset, int $depth): void
	{
		if ($depth > self::MAX_JSON_DEPTH)
		{
			self::Invalid();
		}
		$offset++;
		self::SkipWhitespace($raw, $offset);
		if (($raw[$offset] ?? null) === ']')
		{
			$offset++;
			return;
		}

		while (true)
		{
			self::ParseValue($raw, $offset, $depth);
			self::SkipWhitespace($raw, $offset);
			$separator = $raw[$offset] ?? null;
			if ($separator === ']')
			{
				$offset++;
				return;
			}
			if ($separator !== ',')
			{
				self::Invalid();
			}
			$offset++;
		}
	}

	private static function ReadStringToken(string $raw, int &$offset): string
	{
		$start = $offset;
		$offset++;
		$escaped = false;
		while ($offset < strlen($raw))
		{
			$character = $raw[$offset++];
			if ($escaped)
			{
				$escaped = false;
				continue;
			}
			if ($character === '\\')
			{
				$escaped = true;
				continue;
			}
			if ($character === '"')
			{
				$token = substr($raw, $start, $offset - $start);
				$value = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
				if (!is_string($value))
				{
					self::Invalid();
				}
				return $value;
			}
		}

		self::Invalid();
	}

	private static function SkipWhitespace(string $raw, int &$offset): void
	{
		while ($offset < strlen($raw) && str_contains(" \t\r\n", $raw[$offset]))
		{
			$offset++;
		}
	}

	private static function ValidateBarcode($value, string $requestedGtin): void
	{
		self::AssertObject($value, ['scanned_gtin', 'canonical_gtin', 'equivalents_checked', 'status', 'owner_product_id']);
		$scanned = $value['scanned_gtin'];
		if (!is_string($scanned) || !self::IsValidGtin($scanned)
			|| ($requestedGtin !== '' && !hash_equals($requestedGtin, $scanned)))
		{
			self::Invalid();
		}
		$canonical = str_pad($scanned, 14, '0', STR_PAD_LEFT);
		if (($value['canonical_gtin'] ?? null) !== $canonical
			|| !in_array($value['status'] ?? null, self::BARCODE_STATUSES, true)
			|| !is_array($value['equivalents_checked'] ?? null)
			|| !array_is_list($value['equivalents_checked'])
			|| count($value['equivalents_checked']) < 1
			|| count($value['equivalents_checked']) > 4)
		{
			self::Invalid();
		}
		$equivalents = [];
		foreach ($value['equivalents_checked'] as $equivalent)
		{
			if (!is_string($equivalent) || !self::IsValidGtin($equivalent)
				|| str_pad($equivalent, 14, '0', STR_PAD_LEFT) !== $canonical
				|| isset($equivalents[$equivalent]))
			{
				self::Invalid();
			}
			$equivalents[$equivalent] = true;
		}
		if (($value['equivalents_checked'][0] ?? null) !== $scanned || !isset($equivalents[$canonical]))
		{
			self::Invalid();
		}
		$owner = $value['owner_product_id'];
		if (($value['status'] === 'unused' && $owner !== null)
			|| ($value['status'] !== 'unused' && (!is_int($owner) || $owner < 1)))
		{
			self::Invalid();
		}
	}

	private static function ValidateSuggestions($value): void
	{
		if (!is_array($value) || !array_is_list($value) || count($value) > 30)
		{
			self::Invalid();
		}
		$ids = [];
		$fields = [];
		foreach ($value as $suggestion)
		{
			self::AssertObject($suggestion, ['id', 'field', 'value', 'display_value', 'source', 'confidence_band', 'reason_code', 'evidence_kind', 'retrieved_at', 'source_updated_at', 'target']);
			self::AssertId($suggestion['id']);
			if (isset($ids[$suggestion['id']]) || isset($fields[$suggestion['field']])
				|| !in_array($suggestion['field'], self::FIELDS, true)
				|| !self::IsText($suggestion['value'])
				|| !self::IsText($suggestion['display_value'])
				|| !in_array($suggestion['confidence_band'], self::CONFIDENCE_BANDS, true)
				|| !in_array($suggestion['reason_code'], self::REASON_CODES, true)
				|| !in_array($suggestion['evidence_kind'], self::EVIDENCE_KINDS, true))
			{
				self::Invalid();
			}
			$ids[$suggestion['id']] = true;
			$fields[$suggestion['field']] = true;
			self::ValidateSource($suggestion['source']);
			self::ValidateTimestamp($suggestion['retrieved_at']);
			if ($suggestion['source_updated_at'] !== null)
			{
				self::ValidateTimestamp($suggestion['source_updated_at']);
			}
			self::ValidateTarget($suggestion['target']);
		}
	}

	private static function ValidateMedia($value): void
	{
		if (!is_array($value) || !array_is_list($value) || count($value) > 20)
		{
			self::Invalid();
		}
		$ids = [];
		$searchSeen = false;
		foreach ($value as $media)
		{
			self::AssertObject($media, ['id', 'kind', 'thumbnail_handle', 'full_handle', 'source', 'confidence_band', 'reason_code', 'evidence_kind', 'retrieved_at']);
			self::AssertId($media['id']);
			$isStructured = $media['kind'] === 'front_package';
			$isSearch = $media['kind'] === 'search_alternative';
			if (isset($ids[$media['id']])
				|| (!$isStructured && !$isSearch)
				|| !is_string($media['thumbnail_handle'])
				|| preg_match(self::HANDLE_PATTERN, $media['thumbnail_handle']) !== 1
				|| !is_string($media['full_handle'])
				|| preg_match(self::HANDLE_PATTERN, $media['full_handle']) !== 1
				|| hash_equals($media['thumbnail_handle'], $media['full_handle'])
				|| !in_array($media['confidence_band'], self::CONFIDENCE_BANDS, true)
				|| !in_array($media['reason_code'], self::REASON_CODES, true)
				|| !in_array($media['evidence_kind'], self::EVIDENCE_KINDS, true)
				|| ($isStructured && ($searchSeen
					|| $media['confidence_band'] !== 'high'
					|| $media['reason_code'] !== 'canonical_structured_front_image'
					|| $media['evidence_kind'] !== 'structured_direct'))
				|| ($isSearch && ($media['confidence_band'] !== 'unverified'
					|| $media['reason_code'] !== 'unverified_search_result'
					|| $media['evidence_kind'] !== 'search')))
			{
				self::Invalid();
			}
			if ($isSearch)
			{
				$searchSeen = true;
			}
			$ids[$media['id']] = true;
			self::ValidateMediaSource($media['kind'], $media['source']);
			self::ValidateTimestamp($media['retrieved_at']);
		}
	}

	private static function ValidateWarnings($value): void
	{
		if (!is_array($value) || !array_is_list($value) || count($value) > count(self::WARNING_CODES))
		{
			self::Invalid();
		}
		if (count($value) !== count(array_unique($value)))
		{
			self::Invalid();
		}
		foreach ($value as $warning)
		{
			if (!in_array($warning, self::WARNING_CODES, true))
			{
				self::Invalid();
			}
		}
	}

	private static function ValidateDiagnostics($value): void
	{
		self::AssertObject($value, ['trace_id']);
		if (!is_string($value['trace_id']) || preg_match(self::TRACE_ID_PATTERN, $value['trace_id']) !== 1)
		{
			self::Invalid();
		}
	}

	private static function ValidateSource($value): void
	{
		self::AssertObject($value, ['id', 'label']);
		if (!in_array($value['id'], self::SOURCE_IDS, true) || !self::IsText($value['label']))
		{
			self::Invalid();
		}
	}

	private static function ValidateMediaSource(string $kind, $value): void
	{
		self::AssertObject($value, ['id', 'label']);
		$expected = $kind === 'front_package'
			? ['id' => 'openfoodfacts', 'label' => 'Open Food Facts']
			: ['id' => 'searxng', 'label' => 'Search result'];
		if ($value !== $expected)
		{
			self::Invalid();
		}
	}

	private static function ValidateTarget($value): void
	{
		if ($value === null)
		{
			return;
		}
		self::AssertObject($value, ['kind', 'id', 'label']);
		if (!in_array($value['kind'], ['product_field', 'userfield', 'product_group', 'quantity_unit', 'food_type'], true)
			|| !is_int($value['id']) || $value['id'] < 1 || !self::IsText($value['label']))
		{
			self::Invalid();
		}
	}

	private static function ValidateTimestamp($value): void
	{
		if (!is_string($value) || preg_match(self::TIMESTAMP_PATTERN, $value) !== 1)
		{
			self::Invalid();
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
		$errors = DateTimeImmutable::getLastErrors();
		if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)))
		{
			self::Invalid();
		}
	}

	private static function IsValidGtin(string $gtin): bool
	{
		if (preg_match(self::GTIN_PATTERN, $gtin) !== 1)
		{
			return false;
		}
		$sum = 0;
		$body = substr($gtin, 0, -1);
		for ($offset = 0, $length = strlen($body); $offset < $length; $offset++)
		{
			$sum += (int)$body[$length - $offset - 1] * ($offset % 2 === 0 ? 3 : 1);
		}
		return (int)$gtin[strlen($gtin) - 1] === (10 - ($sum % 10)) % 10;
	}

	private static function IsText($value): bool
	{
		return is_string($value) && trim($value) !== '' && strlen($value) <= self::MAX_TEXT_LENGTH
			&& preg_match('/https?:\/\//i', $value) !== 1;
	}

	private static function AssertId($value): void
	{
		if (!is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1)
		{
			self::Invalid();
		}
	}

	private static function AssertObject($value, array $keys): void
	{
		if (!is_array($value) || array_is_list($value))
		{
			self::Invalid();
		}
		self::AssertKeys($value, $keys);
	}

	private static function AssertKeys(array $value, array $expected): void
	{
		$actual = array_keys($value);
		sort($actual);
		sort($expected);
		if ($actual !== $expected)
		{
			self::Invalid();
		}
	}

	private static function Invalid(): never
	{
		throw new GrocyAiContractException();
	}
}

class GrocyAiContractException extends \InvalidArgumentException
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct('contract_invalid', 0, $previous);
	}
}
