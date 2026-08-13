#!/usr/bin/env python3
"""Validate redacted physical-phone timing evidence against locked release budgets."""

from __future__ import annotations

import argparse
import json
import math
import sys
import tempfile
import unittest
from pathlib import Path


SAMPLE_MINIMUM = 20
THRESHOLDS_MS = {
	"cached": 1000,
	"metadata": 5000,
	"image_attachment": 5000,
}
BROWSER_TIMEOUT_MS = 15000


class EvidenceError(ValueError):
	"""Raised when evidence violates the closed release contract."""


def nearest_rank(values: list[int], percentile: float) -> int:
	raise NotImplementedError("RED: nearest-rank implementation is not present")


def load_jsonl(path: Path) -> list[dict[str, object]]:
	raise NotImplementedError("RED: JSONL validation is not present")


def check_records(records: list[dict[str, object]]) -> list[str]:
	raise NotImplementedError("RED: threshold enforcement is not present")


def sample_record(scenario: str, attempt: int, overall_ms: int, outcome: str = "success") -> dict[str, object]:
	return {
		"schema_version": 1,
		"recorded_at": "2026-08-13T00:00:00Z",
		"device_model": "Fixture Phone",
		"os_name": "Fixture OS",
		"os_version": "1.0",
		"browser_name": "Fixture Browser",
		"browser_version": "1.0",
		"viewport_width_px": 390,
		"viewport_height_px": 844,
		"orientation": "portrait",
		"network_route": "wifi_lan",
		"network_condition": "normal",
		"server_instance": "household_lan",
		"grocy_version": "4.6.0",
		"module_version": "1.0.0",
		"companion_version": "0.1.0",
		"contract_version": "1",
		"scenario": scenario,
		"attempt": attempt,
		"outcome": outcome,
		"overall_ms": overall_ms,
		"browser_ms": 1,
		"grocy_ms": 1,
		"companion_ms": 1,
		"provider_ms": 1,
		"image_ms": 1,
		"normal_save_available": True,
		"read_count": 1,
		"write_count": 0,
		"form_restored": True,
	}


def passing_records() -> list[dict[str, object]]:
	records = []
	for scenario, threshold in THRESHOLDS_MS.items():
		for attempt in range(1, SAMPLE_MINIMUM + 1):
			records.append(sample_record(scenario, attempt, threshold))
	records.append(sample_record("browser_timeout", 1, BROWSER_TIMEOUT_MS, "timeout"))
	return records


class CheckerSelfTest(unittest.TestCase):
	def test_nearest_rank_uses_sorted_ceiling_rank(self) -> None:
		self.assertEqual(nearest_rank([40, 10, 30, 20], 0.50), 20)
		self.assertEqual(nearest_rank([40, 10, 30, 20], 0.95), 40)

	def test_locked_threshold_boundaries_pass(self) -> None:
		lines = check_records(passing_records())
		self.assertTrue(all("PASS" in line for line in lines))
		self.assertEqual(THRESHOLDS_MS, {"cached": 1000, "metadata": 5000, "image_attachment": 5000})
		self.assertEqual(BROWSER_TIMEOUT_MS, 15000)

	def test_threshold_breach_fails(self) -> None:
		records = passing_records()
		for record in records:
			if record["scenario"] == "cached":
				record["overall_ms"] = 1001
		with self.assertRaisesRegex(EvidenceError, "cached p95 1001ms exceeds locked 1000ms"):
			check_records(records)

	def test_insufficient_samples_list_exact_missing_count(self) -> None:
		records = [record for record in passing_records() if not (record["scenario"] == "cached" and record["attempt"] == 20)]
		with self.assertRaisesRegex(EvidenceError, r"cached: missing 1 successful sample\(s\) \(19/20\)"):
			check_records(records)

	def test_malformed_json_fails_with_line_number(self) -> None:
		with tempfile.TemporaryDirectory() as directory:
			path = Path(directory) / "evidence.jsonl"
			path.write_text('{"schema_version": 1}\n{broken}\n', encoding="utf-8")
			with self.assertRaisesRegex(EvidenceError, "line 2: malformed JSON"):
				load_jsonl(path)

	def test_unknown_key_fails_closed(self) -> None:
		record = sample_record("cached", 1, 1000)
		record["notes"] = "not allowed"
		with self.assertRaisesRegex(EvidenceError, "unknown field: notes"):
			check_records([record])

	def test_forbidden_key_pattern_is_rejected(self) -> None:
		record = sample_record("cached", 1, 1000)
		record["product_name"] = "forbidden"
		with self.assertRaisesRegex(EvidenceError, "forbidden field pattern: product_name"):
			check_records([record])

	def test_timeout_must_be_exact(self) -> None:
		records = passing_records()
		records[-1]["overall_ms"] = 14999
		with self.assertRaisesRegex(EvidenceError, "browser_timeout must equal exactly 15000ms"):
			check_records(records)


def run_self_test() -> int:
	suite = unittest.defaultTestLoader.loadTestsFromTestCase(CheckerSelfTest)
	result = unittest.TextTestRunner(verbosity=2).run(suite)
	return 0 if result.wasSuccessful() else 1


def main() -> int:
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("evidence", nargs="?", type=Path, default=Path(__file__).with_name("phone-timings.jsonl"))
	parser.add_argument("--self-test", action="store_true", help="run deterministic checker contract tests")
	args = parser.parse_args()
	if args.self_test:
		return run_self_test()
	try:
		for line in check_records(load_jsonl(args.evidence)):
			print(line)
	except (EvidenceError, OSError) as error:
		print("FAIL: " + str(error), file=sys.stderr)
		return 1
	return 0


if __name__ == "__main__":
	sys.exit(main())
