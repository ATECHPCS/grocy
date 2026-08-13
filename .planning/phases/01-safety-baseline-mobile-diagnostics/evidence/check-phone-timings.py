#!/usr/bin/env python3
"""Validate redacted physical-phone timing evidence against locked release budgets."""

from __future__ import annotations

import argparse
import datetime as dt
import json
import math
import re
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
ALLOWED_FIELDS = {
	"schema_version",
	"recorded_at",
	"device_model",
	"os_name",
	"os_version",
	"browser_name",
	"browser_version",
	"viewport_width_px",
	"viewport_height_px",
	"orientation",
	"network_route",
	"network_condition",
	"server_instance",
	"grocy_version",
	"module_version",
	"companion_version",
	"contract_version",
	"scenario",
	"attempt",
	"outcome",
	"overall_ms",
	"browser_ms",
	"grocy_ms",
	"companion_ms",
	"provider_ms",
	"image_ms",
	"normal_save_available",
	"read_count",
	"write_count",
	"form_restored",
}
FORBIDDEN_FIELD_PATTERN = re.compile(
	r"gtin|upc|product|value|credential|key|auth|header|cookie|csrf|payload|body|inventory|url|query|token|history",
	re.IGNORECASE,
)
SAFE_TEXT_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9 ._+()/-]{0,63}$")
VERSION_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$")
RECORDED_AT_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
FORBIDDEN_VALUE_PATTERNS = (
	re.compile(r"://", re.IGNORECASE),
	re.compile(r"\b(?:authorization|bearer|cookie|csrf|api[_ -]?key|secret|token)\b", re.IGNORECASE),
	re.compile(r"(?<!\d)\d{8}(?:\d{4})?(?:\d{1,2})?(?!\d)"),
)
ALLOWED_ORIENTATIONS = {"portrait", "landscape"}
ALLOWED_NETWORK_ROUTES = {"wifi_lan", "wifi_vpn", "cellular_vpn"}
ALLOWED_NETWORK_CONDITIONS = {"normal", "slow", "disconnected", "reconnected"}
ALLOWED_SERVER_INSTANCES = {"household_lan"}
ALLOWED_SCENARIOS = set(THRESHOLDS_MS) | {"browser_timeout"}
ALLOWED_OUTCOMES = {"success", "timeout", "cancelled", "offline", "not_found", "provider_error", "partial_image"}
TEXT_FIELDS = {"device_model", "os_name", "browser_name"}
VERSION_FIELDS = {"os_version", "browser_version", "grocy_version", "module_version", "companion_version", "contract_version"}
STAGE_FIELDS = {"browser_ms", "grocy_ms", "companion_ms", "provider_ms", "image_ms"}


class EvidenceError(ValueError):
	"""Raised when evidence violates the closed release contract."""


def nearest_rank(values: list[int], percentile: float) -> int:
	if not values:
		raise EvidenceError("nearest-rank requires at least one value")
	if percentile <= 0 or percentile > 1:
		raise EvidenceError("percentile must be greater than 0 and at most 1")
	ordered = sorted(values)
	rank = math.ceil(percentile * len(ordered))
	return ordered[rank - 1]


def load_jsonl(path: Path) -> list[dict[str, object]]:
	records: list[dict[str, object]] = []
	with path.open("r", encoding="utf-8") as evidence:
		for line_number, raw_line in enumerate(evidence, start=1):
			line = raw_line.strip()
			if not line:
				continue
			try:
				record = json.loads(line)
			except json.JSONDecodeError as error:
				raise EvidenceError(f"line {line_number}: malformed JSON ({error.msg})") from error
			if not isinstance(record, dict):
				raise EvidenceError(f"line {line_number}: record must be a JSON object")
			records.append(record)
	return records


def check_records(records: list[dict[str, object]]) -> list[str]:
	for index, record in enumerate(records, start=1):
		validate_record(record, index)

	missing = []
	values_by_scenario: dict[str, list[int]] = {}
	for scenario in THRESHOLDS_MS:
		values = [
			record["overall_ms"]
			for record in records
			if record["scenario"] == scenario and record["outcome"] == "success"
		]
		values_by_scenario[scenario] = values
		if len(values) < SAMPLE_MINIMUM:
			missing.append(f"{scenario}: missing {SAMPLE_MINIMUM - len(values)} successful sample(s) ({len(values)}/{SAMPLE_MINIMUM})")

	timeout_values = [record["overall_ms"] for record in records if record["scenario"] == "browser_timeout"]
	if not timeout_values:
		missing.append("browser_timeout: missing 1 exact timeout sample (0/1)")
	if missing:
		raise EvidenceError("insufficient evidence:\n" + "\n".join(missing))
	if any(value != BROWSER_TIMEOUT_MS for value in timeout_values):
		raise EvidenceError(f"browser_timeout must equal exactly {BROWSER_TIMEOUT_MS}ms")

	lines = []
	failures = []
	for scenario, threshold in THRESHOLDS_MS.items():
		values = values_by_scenario[scenario]
		p50 = nearest_rank(values, 0.50)
		p95 = nearest_rank(values, 0.95)
		status = "PASS" if p95 <= threshold else "FAIL"
		lines.append(f"{scenario} count={len(values)} p50={p50}ms p95={p95}ms threshold={threshold}ms {status}")
		if p95 > threshold:
			failures.append(f"{scenario} p95 {p95}ms exceeds locked {threshold}ms")
	lines.append(
		f"browser_timeout count={len(timeout_values)} observed={BROWSER_TIMEOUT_MS}ms "
		f"threshold=exactly-{BROWSER_TIMEOUT_MS}ms PASS"
	)
	if failures:
		raise EvidenceError("\n".join(failures))
	return lines


def is_int(value: object) -> bool:
	return isinstance(value, int) and not isinstance(value, bool)


def validate_safe_text(field: str, value: object, pattern: re.Pattern[str]) -> None:
	if isinstance(value, str) and any(forbidden.search(value) for forbidden in FORBIDDEN_VALUE_PATTERNS):
		raise EvidenceError(f"{field} contains forbidden evidence data")
	if not isinstance(value, str) or not pattern.fullmatch(value):
		raise EvidenceError(f"{field} must use the closed safe text format")


def validate_record(record: dict[str, object], index: int) -> None:
	prefix = f"record {index}: "
	for field in record:
		if not isinstance(field, str):
			raise EvidenceError(prefix + "field names must be strings")
		if FORBIDDEN_FIELD_PATTERN.search(field):
			raise EvidenceError(prefix + "forbidden field pattern: " + field)
	unknown = sorted(set(record) - ALLOWED_FIELDS)
	if unknown:
		raise EvidenceError(prefix + "unknown field: " + unknown[0])
	missing = sorted(ALLOWED_FIELDS - set(record))
	if missing:
		raise EvidenceError(prefix + "missing required field: " + missing[0])

	if record["schema_version"] != 1 or isinstance(record["schema_version"], bool):
		raise EvidenceError(prefix + "schema_version must equal 1")
	recorded_at = record["recorded_at"]
	if not isinstance(recorded_at, str) or not RECORDED_AT_PATTERN.fullmatch(recorded_at):
		raise EvidenceError(prefix + "recorded_at must be an ISO 8601 UTC second")
	try:
		dt.datetime.strptime(recorded_at, "%Y-%m-%dT%H:%M:%SZ")
	except ValueError as error:
		raise EvidenceError(prefix + "recorded_at is not a valid timestamp") from error
	for field in TEXT_FIELDS:
		validate_safe_text(field, record[field], SAFE_TEXT_PATTERN)
	for field in VERSION_FIELDS:
		validate_safe_text(field, record[field], VERSION_PATTERN)

	for field in ("viewport_width_px", "viewport_height_px"):
		if not is_int(record[field]) or not 1 <= record[field] <= 4096:
			raise EvidenceError(prefix + field + " must be an integer from 1 through 4096")
	if record["orientation"] not in ALLOWED_ORIENTATIONS:
		raise EvidenceError(prefix + "orientation is not allowed")
	if record["network_route"] not in ALLOWED_NETWORK_ROUTES:
		raise EvidenceError(prefix + "network_route is not allowed")
	if record["network_condition"] not in ALLOWED_NETWORK_CONDITIONS:
		raise EvidenceError(prefix + "network_condition is not allowed")
	if record["server_instance"] not in ALLOWED_SERVER_INSTANCES:
		raise EvidenceError(prefix + "server_instance is not allowed")
	if record["scenario"] not in ALLOWED_SCENARIOS:
		raise EvidenceError(prefix + "scenario is not allowed")
	if record["outcome"] not in ALLOWED_OUTCOMES:
		raise EvidenceError(prefix + "outcome is not allowed")
	if not is_int(record["attempt"]) or record["attempt"] < 1:
		raise EvidenceError(prefix + "attempt must be a positive integer")
	if not is_int(record["overall_ms"]) or not 0 <= record["overall_ms"] <= BROWSER_TIMEOUT_MS:
		raise EvidenceError(prefix + f"overall_ms must be an integer from 0 through {BROWSER_TIMEOUT_MS}")
	for field in STAGE_FIELDS:
		value = record[field]
		if value is not None and (not is_int(value) or not 0 <= value <= BROWSER_TIMEOUT_MS):
			raise EvidenceError(prefix + field + f" must be null or an integer from 0 through {BROWSER_TIMEOUT_MS}")
	for field in ("read_count", "write_count"):
		if not is_int(record[field]) or record[field] < 0:
			raise EvidenceError(prefix + field + " must be a nonnegative integer")
	for field in ("normal_save_available", "form_restored"):
		if not isinstance(record[field], bool):
			raise EvidenceError(prefix + field + " must be boolean")
	if record["normal_save_available"] is not True:
		raise EvidenceError(prefix + "normal_save_available must remain true")
	if record["write_count"] != 0:
		raise EvidenceError(prefix + "write_count must remain zero during enrichment evidence")
	if record["form_restored"] is not True:
		raise EvidenceError(prefix + "form_restored must remain true")
	if record["scenario"] in THRESHOLDS_MS and record["outcome"] == "timeout":
		raise EvidenceError(prefix + "performance scenarios cannot count timeout as success")
	if record["scenario"] == "browser_timeout" and record["outcome"] != "timeout":
		raise EvidenceError(prefix + "browser_timeout outcome must be timeout")


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

	def test_missing_metadata_fails_closed(self) -> None:
		record = sample_record("cached", 1, 1000)
		del record["browser_version"]
		with self.assertRaisesRegex(EvidenceError, "missing required field: browser_version"):
			check_records([record])

	def test_forbidden_key_pattern_is_rejected(self) -> None:
		record = sample_record("cached", 1, 1000)
		record["product_name"] = "forbidden"
		with self.assertRaisesRegex(EvidenceError, "forbidden field pattern: product_name"):
			check_records([record])

	def test_forbidden_values_cannot_hide_in_metadata(self) -> None:
		for forbidden in ("012345678905", "https://private.invalid/path", "Bearer fixture-secret"):
			record = sample_record("cached", 1, 1000)
			record["device_model"] = forbidden
			with self.subTest(forbidden=forbidden):
				with self.assertRaisesRegex(EvidenceError, "device_model contains forbidden evidence data"):
					check_records([record])

	def test_timeout_must_be_exact(self) -> None:
		records = passing_records()
		records[-1]["overall_ms"] = 14999
		with self.assertRaisesRegex(EvidenceError, "browser_timeout must equal exactly 15000ms"):
			check_records(records)

	def test_zero_write_and_restoration_are_required(self) -> None:
		records = passing_records()
		records[0]["write_count"] = 1
		with self.assertRaisesRegex(EvidenceError, "write_count must remain zero"):
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
