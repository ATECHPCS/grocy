# Phase 4: Reusable Conversion Model - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-21
**Phase:** 04-reusable-conversion-model
**Areas discussed:** Universal conversion baseline, food-specific mass/volume profiles, conversion source experience, safety and compatibility proof

---

## Universal Conversion Baseline

| Option | Selected |
|---|---|
| Mass only | |
| Mass and volume | ✓ |
| Include temperature and length | |

**User's choice:** Support universal mass and volume conversions, using standard US customary kitchen measures anchored to metric units. Preserve full precision internally and round only for display.

**Notes:** Universal defaults are editable in Grocy's normal quantity-unit conversion screen. Product screens keep product-specific overrides. Package-derived suggestions require explicit review and normal Grocy save.

---

## Food-Specific Mass/Volume Profiles

| Option | Selected |
|---|---|
| Exact package data only | |
| Conservative, sourced food-type profiles | ✓ |
| Both with product data first | |

**User's choice:** Start with calibrated common liquids and simple ingredients, record source/version, and require an explicit taxonomy assignment before a profile applies.

**Notes:** The user asked for eventual broad coverage, but accepted no estimate for food types without an approved profile.

---

## Conversion Source Experience

| Option | Selected |
|---|---|
| Product screen only | |
| Resolved-conversions view only | |
| Both | ✓ |

**User's choice:** Show concise provenance in both views, expose details on demand, and block conflicting paths.

**Notes:** An approximate estimate is allowed only when an approved profile exists and must identify itself as approximate with source/version.

---

## Safety and Compatibility Proof

| Option | Selected |
|---|---|
| Post-implementation compatibility check | |
| Dual-branch characterization release gate | ✓ |
| Main-branch-only behavior check | |

**User's choice:** Require dual-branch characterization before activation; prove stock, recipes, purchases, consumption, prices, quantity displays, transfers, and meal plans remain equivalent.

**Notes:** Existing product-specific conversions remain unchanged in Phase 4; Phase 6 owns reviewed cleanup. Universal edits must show factor, source/version, affected pair, and a conflict/cycle-free impact result.

---

## the agent's Discretion

- Select the safe initial food profiles, authoritative sources, storage representation, and concise copy.
- Determine the smallest compatible resolved/cache projection after dual-branch characterization.

## Deferred Ideas

- Automatic package-derived conversion creation — Phase 5 audited preview/apply workflow.
- Existing product-specific conversion cleanup — Phase 6.
- Interactive conversion graph — v2.
