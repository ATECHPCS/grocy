<?php

namespace GrocyAI\Services;

use Grocy\Services\DatabaseService;

class GrocyAiBarcodeService
{
	private $OwnerLookup;
	private ?int $CurrentProductId;

	public function __construct(?callable $ownerLookup = null, ?int $currentProductId = null)
	{
		$this->OwnerLookup = $ownerLookup;
		$this->CurrentProductId = $currentProductId !== null && $currentProductId > 0 ? $currentProductId : null;
	}

	public function ResolveOwner(string $scannedGtin): array
	{
		$canonicalGtin = GrocyAiGtin::CanonicalOrNull($scannedGtin);
		if ($canonicalGtin === null)
		{
			throw new \InvalidArgumentException('Barcode is not a checksum-valid GTIN');
		}

		$sqlExpression = GrocyAiGtin::CanonicalSqlExpression('pb.barcode');
		$rows = $this->LookupRows($canonicalGtin, $sqlExpression);
		if (count($rows) > 1)
		{
			throw new \RuntimeException('Canonical barcode ownership is not unique');
		}

		$ownerProductId = null;
		$ownerLabel = null;
		$status = 'unused';
		if (count($rows) === 1)
		{
			$row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
			$productId = filter_var($row['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($productId === false)
			{
				throw new \RuntimeException('Canonical barcode owner is invalid');
			}
			$ownerProductId = (int)$productId;
			$ownerLabel = self::BoundedLabel($row['owner_label'] ?? null);
			$status = $this->CurrentProductId !== null && $ownerProductId === $this->CurrentProductId
				? 'owned_current'
				: 'owned_other';
		}

		$equivalents = [$scannedGtin];
		if (!hash_equals($scannedGtin, $canonicalGtin))
		{
			$equivalents[] = $canonicalGtin;
		}

		return [
			'scanned_gtin' => $scannedGtin,
			'canonical_gtin' => $canonicalGtin,
			'equivalents_checked' => $equivalents,
			'status' => $status,
			'owner_product_id' => $ownerProductId,
			'owner_label' => $ownerLabel
		];
	}

	public function ResolveBeforeProvider(string $scannedGtin, callable $permissionCheck, callable $provider): array
	{
		$permissionCheck();
		$ownership = $this->ResolveOwner($scannedGtin);
		$providerResult = null;
		if ($ownership['status'] === 'unused')
		{
			$providerResult = $provider();
			if (!is_array($providerResult))
			{
				throw new \RuntimeException('Provider result is invalid');
			}
		}

		return [
			'ownership' => $ownership,
			'provider_result' => $providerResult
		];
	}

	private function LookupRows(string $canonicalGtin, string $sqlExpression): array
	{
		if ($this->OwnerLookup !== null)
		{
			$rows = ($this->OwnerLookup)($canonicalGtin, $sqlExpression);
			if (!is_array($rows))
			{
				throw new \RuntimeException('Canonical barcode lookup failed');
			}
			return array_slice($rows, 0, 2);
		}

		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$query = $pdo->prepare(
			'SELECT pb.product_id, p.name AS owner_label'
			. ' FROM product_barcodes pb'
			. ' JOIN products p ON p.id = pb.product_id'
			. ' WHERE ' . $sqlExpression . ' = :canonical_gtin'
			. ' ORDER BY pb.id LIMIT 2'
		);
		$query->execute(['canonical_gtin' => $canonicalGtin]);
		return $query->fetchAll(\PDO::FETCH_ASSOC);
	}

	private static function BoundedLabel(mixed $label): ?string
	{
		if (!is_string($label))
		{
			return null;
		}
		$label = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label));
		if ($label === '')
		{
			return null;
		}

		return mb_substr($label, 0, 120, 'UTF-8');
	}
}
