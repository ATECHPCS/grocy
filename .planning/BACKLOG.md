# Backlog — Future Milestone Candidates

Capabilities intentionally deferred out of the current milestone (which ends at
Phase 7, Upstream & Stable Release Sustainment). Not scheduled — captured here so
they survive milestone boundaries. Promote into a ROADMAP phase only when a new
milestone opens and the dependency phases below are complete.

## Receipt Scan → Reviewed Stock-In & Price History

**Capability.** A user photographs a store receipt on their phone; the system
extracts line items (name, qty, unit price, store, date) and proposes a reviewed
batch that, on approval, records purchases into Grocy stock and writes per-store
price history — never an autonomous write (see PROJECT.md *Out of Scope*: no
product/stock/price writes without an explicit preview and a user-approved action).

**Why.**

- Closes the buy-side inventory loop: what actually entered the house, booked
  without manual entry.
- Auto-fills the per-store regular-price data the sibling `flipp-deal-planner`
  repo's cheapest-source engine depends on (today those prices are captured only
  when a Grocy purchase is recorded by hand).

**Fit with existing work (reuse, do not duplicate).**

- **OCR / extraction** belongs in the `grocy-mcp` companion (`10.10.0.156:3061`),
  alongside the existing UPC-enrichment providers — a bounded, traced, timed
  provider call returning structured line items, the same shape as the Phase 1
  diagnostics contract.
- **Review-before-save** reuses the Phase 2 enrichment contract: current vs.
  proposed side-by-side, each with source / confidence / reason, selected-only apply.
- **The write path is the Phase 5 Bulk Maintenance & Recovery Engine.** Receipt
  line items are just another named typed operation (stock-add + price-history-add)
  fed into the zero-mutation bounded plan → item select/reject → one
  `BEGIN IMMEDIATE` transaction → audit + rollback-preview flow. Do not build a
  second write path.

**Dependencies.** Best sequenced *after* Phase 5 lands, so it consumes the
apply/recovery engine instead of duplicating it. Depends on the Phase 2 review
contract; benefits from the Phase 3 taxonomy for line-item → product matching.

**Open questions for planning time.**

- OCR source: cloud vision (GPT-4o vision — already used by flipp-deal-planner for
  matching) vs. a local model — cost / privacy tradeoff.
- Line-item → Grocy product matching (receipts carry no barcode; fuzzy name plus
  the Phase 3 taxonomy).
- Store/merchant normalization to match the flipp-deal-planner merchant strings.
- Non-inventory receipt lines (tax, bags, deposits) and multi-unit packs.

**Constraint.** Keep implementation under `custom/grocy_AI/` +
`public/custom/grocy_AI/`; OCR provider logic under `ATECHPCS/grocy-mcp`.
