<?php

namespace GrocyAI\Services;

class GrocyAiGtin
{
	private const LENGTHS = [8, 12, 13, 14];

	public static function CanonicalOrNull(string $gtin): ?string
	{
		$length = strlen($gtin);
		if (!in_array($length, self::LENGTHS, true) || preg_match('/^[0-9]+$/D', $gtin) !== 1)
		{
			return null;
		}

		$weightedSum = 0;
		for ($position = $length - 2, $offset = 0; $position >= 0; $position--, $offset++)
		{
			$weightedSum += ((int)$gtin[$position]) * ($offset % 2 === 0 ? 3 : 1);
		}
		$expectedCheckDigit = (10 - ($weightedSum % 10)) % 10;
		if ((int)$gtin[$length - 1] !== $expectedCheckDigit)
		{
			return null;
		}

		return str_pad($gtin, 14, '0', STR_PAD_LEFT);
	}

	public static function NormalizeOrThrow(string $barcode): string
	{
		$gtin = str_replace([' ', '-'], '', trim($barcode));
		if (self::CanonicalOrNull($gtin) === null)
		{
			throw new \InvalidArgumentException('Barcode is not a checksum-valid GTIN-8, GTIN-12, GTIN-13, or GTIN-14');
		}

		return $gtin;
	}

	public static function CanonicalSqlExpression(string $quotedColumn): string
	{
		$identifier = '(?:"[A-Za-z_][A-Za-z0-9_]*"|[A-Za-z_][A-Za-z0-9_]*)';
		if (preg_match('/^' . $identifier . '(?:\.' . $identifier . ')?$/D', $quotedColumn) !== 1)
		{
			throw new \InvalidArgumentException('Invalid quoted GTIN column expression');
		}

		$lengthCases = [];
		foreach (self::LENGTHS as $length)
		{
			$terms = [];
			for ($position = 1; $position < $length; $position++)
			{
				$weight = (($length - 1 - $position) % 2 === 0) ? 3 : 1;
				$terms[] = $weight . ' * CAST(substr(' . $quotedColumn . ', ' . $position . ', 1) AS INTEGER)';
			}
			$lengthCases[] = 'WHEN ' . $length . ' THEN (' . implode(' + ', $terms) . ')';
		}

		return 'CASE WHEN length(' . $quotedColumn . ') IN (8, 12, 13, 14)'
			. ' AND ' . $quotedColumn . " NOT GLOB '*[^0-9]*'"
			. ' AND CAST(substr(' . $quotedColumn . ', -1, 1) AS INTEGER) = ((10 - ((CASE length('
			. $quotedColumn . ') ' . implode(' ', $lengthCases) . ' ELSE 0 END) % 10)) % 10)'
			. " THEN substr('00000000000000' || " . $quotedColumn . ', -14, 14) ELSE NULL END';
	}
}
