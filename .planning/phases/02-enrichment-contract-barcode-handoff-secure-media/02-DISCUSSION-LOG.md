# Phase 2: Enrichment Contract, Barcode Handoff & Secure Media - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-13
**Phase:** 02-enrichment-contract-barcode-handoff-secure-media
**Areas discussed:** Suggestion review

---

## Suggestion Review

### Current and suggested value layout

| Option | Description | Selected |
|--------|-------------|----------|
| Side-by-side per field | Current value beside suggestion, with source, confidence, reason, and freshness directly underneath | ✓ |
| Single comparison table | Compact rows for all fields, with greater density but less comfortable phone use | |
| Suggestion cards | One expandable card per field, providing more detail with additional scrolling | |

**User's choice:** Side-by-side per field.

### Default selection behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Nothing preselected | Every field requires an explicit selection | |
| High-confidence suggestions | Preselect exact structured-source matches while retaining final review | ✓ |
| All available suggestions | Preselect every available suggestion | |

**User's choice:** Preselect high-confidence suggestions.

### High-confidence qualification

| Option | Description | Selected |
|--------|-------------|----------|
| Structured exact evidence only | A validated structured provider supplies the field directly for the canonical barcode | ✓ |
| Structured plus agreement | Two independent sources agree exactly | |
| Configured confidence score | Any suggestion above a numeric threshold qualifies | |

**User's choice:** Structured exact evidence only.

### Existing-value replacement

| Option | Description | Selected |
|--------|-------------|----------|
| Never preselect replacements | Automatic selection applies only to blank fields; replacements require an explicit tap | ✓ |
| Preselect if sources differ | Select a clearly flagged replacement when sources disagree | |
| Preselect newer evidence | Select evidence newer than the current value's provenance | |

**User's choice:** Never preselect replacements.

---

## the agent's Discretion

- Exact selection controls, provenance presentation, and accessible confidence styling.
- Barcode routing, final-diff conflict handling, and secure-media details within ENR-01 through ENR-09 and the carried-forward safety contract.

## Deferred Ideas

- Stage factual Nutrition Facts from a valid barcode during product creation, subject to review and normal Save. This requires a future scoped contract and must not become medical or dietary advice.
