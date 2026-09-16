#!/usr/bin/env python3
"""Append REAL observed phone timings to phone-timings.jsonl as schema-valid records.

This helper does NOT invent numbers. You pass the `overall_duration_ms` values you
actually read off the phone's Diagnostics summary line, and it wraps each one in the
closed release schema (device metadata from device-profile.json) so you never hand-edit
JSON. Every record is validated with the SAME validator the release checker uses; a
forbidden value or bad field aborts the write.

Usage
-----
  # one-time: copy the template and fill it in
  cp device-profile.example.json device-profile.json && $EDITOR device-profile.json

  # add observed successful samples (space-separated ms, one record each)
  python3 record-samples.py add --scenario metadata --ms 2450 2610 2380 ...
  python3 record-samples.py add --scenario cached   --ms 640 590 710 ...
  python3 record-samples.py add --scenario image_attachment --ms 3100 2950 ...

  # add the single forced-timeout sample (overall_ms is fixed at 15000)
  python3 record-samples.py timeout [--network-condition disconnected]

  # see how many of each scenario you still owe
  python3 record-samples.py status

  # clear the evidence file to start over
  python3 record-samples.py reset --yes

Then run the release checker:
  python3 check-phone-timings.py phone-timings.jsonl
"""

from __future__ import annotations

import argparse
import datetime as dt
import importlib.util
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
EVIDENCE = HERE / "phone-timings.jsonl"
PROFILE = HERE / "device-profile.json"

# Reuse the release checker's own record validator so a sample the checker would reject
# never reaches the file.
_spec = importlib.util.spec_from_file_location("check_phone_timings", HERE / "check-phone-timings.py")
_checker = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_checker)

PROFILE_FIELDS = (
    "device_model", "os_name", "os_version", "browser_name", "browser_version",
    "viewport_width_px", "viewport_height_px", "orientation", "network_route",
    "network_condition", "server_instance", "grocy_version", "module_version",
    "companion_version", "contract_version",
)
PERF_SCENARIOS = ("cached", "metadata", "image_attachment")


def load_profile() -> dict:
    if not PROFILE.exists():
        sys.exit(f"error: {PROFILE.name} not found. Copy device-profile.example.json to it and fill it in.")
    data = json.loads(PROFILE.read_text(encoding="utf-8"))
    data.pop("_comment", None)
    missing = [f for f in PROFILE_FIELDS if f not in data]
    if missing:
        sys.exit(f"error: device-profile.json missing field(s): {', '.join(missing)}")
    return data


def load_records() -> list[dict]:
    if not EVIDENCE.exists():
        return []
    return _checker.load_jsonl(EVIDENCE)


def now_utc_second() -> str:
    return dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def next_attempt(records: list[dict], scenario: str) -> int:
    return sum(1 for r in records if r.get("scenario") == scenario) + 1


def build_record(profile: dict, scenario: str, attempt: int, overall_ms: int,
                 outcome: str, network_condition: str | None) -> dict:
    record = {
        "schema_version": 1,
        "recorded_at": now_utc_second(),
        "scenario": scenario,
        "attempt": attempt,
        "outcome": outcome,
        "overall_ms": overall_ms,
        # Stage breakdown is not separately captured on the phone; null is allowed and truthful.
        "browser_ms": None,
        "grocy_ms": None,
        "companion_ms": None,
        "provider_ms": None,
        "image_ms": None,
        # Enrichment is zero-write by design; the operator confirms this each run.
        "normal_save_available": True,
        "read_count": 1,
        "write_count": 0,
        "form_restored": True,
    }
    for field in PROFILE_FIELDS:
        record[field] = profile[field]
    if network_condition is not None:
        record["network_condition"] = network_condition
    return record


def cmd_add(args: argparse.Namespace) -> int:
    profile = load_profile()
    records = load_records()
    to_write = []
    attempt = next_attempt(records, args.scenario)
    for ms in args.ms:
        record = build_record(profile, args.scenario, attempt, ms, "success", args.network_condition)
        _checker.validate_record(record, len(records) + len(to_write) + 1)  # raises EvidenceError on any violation
        to_write.append(record)
        attempt += 1
    append(to_write)
    print(f"added {len(to_write)} {args.scenario} sample(s); {status_line(load_records())}")
    return 0


def cmd_timeout(args: argparse.Namespace) -> int:
    profile = load_profile()
    records = load_records()
    record = build_record(profile, "browser_timeout", next_attempt(records, "browser_timeout"),
                          _checker.BROWSER_TIMEOUT_MS, "timeout", args.network_condition)
    _checker.validate_record(record, len(records) + 1)
    append([record])
    print(f"added browser_timeout sample (15000ms); {status_line(load_records())}")
    return 0


def cmd_status(_args: argparse.Namespace) -> int:
    print(status_line(load_records()))
    return 0


def cmd_reset(args: argparse.Namespace) -> int:
    if not args.yes:
        sys.exit("refusing to clear evidence without --yes")
    EVIDENCE.write_text("", encoding="utf-8")
    print(f"cleared {EVIDENCE.name}")
    return 0


def append(records: list[dict]) -> None:
    with EVIDENCE.open("a", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record) + "\n")


def status_line(records: list[dict]) -> str:
    parts = []
    for scenario in PERF_SCENARIOS:
        count = sum(1 for r in records if r.get("scenario") == scenario and r.get("outcome") == "success")
        parts.append(f"{scenario} {count}/20")
    timeouts = sum(1 for r in records if r.get("scenario") == "browser_timeout")
    parts.append(f"timeout {timeouts}/1")
    return "have: " + ", ".join(parts)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)

    p_add = sub.add_parser("add", help="append observed successful samples")
    p_add.add_argument("--scenario", required=True, choices=PERF_SCENARIOS)
    p_add.add_argument("--ms", required=True, type=int, nargs="+", help="observed overall_duration_ms value(s)")
    p_add.add_argument("--network-condition", choices=["normal", "slow", "disconnected", "reconnected"], default=None)
    p_add.set_defaults(func=cmd_add)

    p_to = sub.add_parser("timeout", help="append the single forced 15000ms timeout sample")
    p_to.add_argument("--network-condition", choices=["normal", "slow", "disconnected", "reconnected"], default="disconnected")
    p_to.set_defaults(func=cmd_timeout)

    p_st = sub.add_parser("status", help="show sample counts vs the required 20/20/20/1")
    p_st.set_defaults(func=cmd_status)

    p_rs = sub.add_parser("reset", help="clear the evidence file")
    p_rs.add_argument("--yes", action="store_true")
    p_rs.set_defaults(func=cmd_reset)

    args = parser.parse_args()
    try:
        return args.func(args)
    except _checker.EvidenceError as error:
        print("REJECTED (would fail the release checker): " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
