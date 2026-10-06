# Capture Classification Suggestions Design

## Goal

For an identified UPC that still needs a Grocy product, suggest a Product Group, food classification leaf, and Generic Parent in receipt review. The person reviewing the purchase chooses the final values before approving product creation.

## Evidence and precedence

An existing, unambiguous Open Food Facts category mapping remains the first source for group and taxonomy. When the identified product has no usable category, the companion may make one bounded OpenAI classification call. The call supplies only the identified product name, available package/brand details, linked receipt description when present, and bounded choices from the current active Grocy catalog. It does not use web search. The response must be schema constrained and may select only supplied group IDs, taxonomy slugs, and parent IDs, or null. The server validates every returned value independently against the live catalog. Uncertain or invalid fields remain unselected; one field's failure does not discard valid suggestions for the other fields.

Generic Parent is an existing active top-level Grocy product, not a new parent created by the model. A parent suggestion is displayed only if its stock quantity unit can be made compatible under the existing approval rules; approval still rechecks compatibility after the reviewer chooses stock and purchase units. Existing exact-barcode matches remain resolved products and are never reclassified by capture research.

## Review and persistence

The review card labels every suggestion with its source and makes its three values easy to inspect or change. A valid, unambiguous suggestion may be preselected, but a user's saved choice or explicit clearing wins over later research. All three fields appear in the final double confirmation. Research and classification never create a product, alter an existing product, claim a barcode, or change stock. The existing Approve new product and Commit purchase actions retain their separate gates.

## Bounded background work

Classification runs automatically after a successful identified-product research result, without blocking the research result. Grocy reserves a classification call atomically before the companion calls OpenAI. The reservation is durable, limited to one attempt per draft research result revision, and subject to a separate configurable UTC-day ceiling. A timeout, refusal, malformed response, exhausted quota, or unavailable provider leaves a visible neutral status and manual controls; it does not label the product unidentified or spend again on a lease retry. Results are stored with source and input revision for audit. A result arriving after the draft has been approved, linked, canceled, changed to an existing product, or superseded by newer research is ignored.

## Verification

Contract tests cover category precedence, name-only AI fallback, strict choice validation, cost reservations, stale completions, user-edit precedence, unit compatibility, and no catalog/stock write from research. Browser tests cover narrow mobile review and double confirmation. Production rollout keeps existing trips and receipt data intact; no product or stock action is performed on behalf of the reviewer.
