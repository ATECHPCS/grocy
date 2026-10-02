# OpenAI web research fallback for captured UPCs

## Outcome and approved direction

When Barcode Buddy Federation and Open Food Facts have no product record for a scanned UPC/GTIN, purchase capture should try one bounded OpenAI web search for that exact code. Any resulting name is a **sourced suggestion** in the existing product draft review. The household user still approves or edits the product before Grocy creates it or assigns the barcode. Receipt allocation, price confirmation, and **Commit purchase** remain separate explicit actions.

The user approved an automatic fallback after the existing providers miss, cited suggestions for approval, and provisional drafts when evidence cannot establish the product. OpenAI is a web research tool here, not a barcode authority. The model's uncited memory is never accepted as an exact UPC match.

## Trigger and result rules

The existing Grocy research job remains the durable unit, keyed by canonical GTIN. The companion first queries Federation and Open Food Facts concurrently as today. An attributed name from either provider ends lookup without calling OpenAI. If one provider has a transient error and neither supplies a name, preserve the existing retry behavior; do not spend on OpenAI until both providers have returned definitive misses. Unsupported, checksum-invalid, deselected, canceled, or committed captures do not trigger new web searches.

For a definitive dual miss, send only the normalized barcode and a fixed instruction to the OpenAI Responses API with hosted `web_search`. Do not send receipt images, receipt text, household inventory, account information, or existing product lists. Require an actual search call and inspect returned URL citations. A candidate may be displayed only with a bounded HTTPS source URL and an explicit `OpenAI web suggestion — verify UPC` label. The model must report whether its cited result associates the exact UPC/GTIN with the proposed product, but even an exact-code claim remains unverified until the user checks the linked source and package. A response with no usable citation remains a barcode-only provisional draft. No web result automatically assigns a Grocy group, food taxonomy, parent, image, barcode owner, receipt match, or stock quantity.

The companion normalizes the result to a versioned, size-limited contract. It limits candidate names, source count, URL length, and text length, strips control characters, and permits only HTTPS URLs to public hosts. Grocy validates the exact contract before storing suggestions. The UI shows source title/domain and a safe external link beside each AI candidate, with a clear `Verify against package` instruction. Provider names and user edits retain their existing precedence. A later provider hit must not overwrite user-edited draft fields.

If the OpenAI key is absent, the API is down, web search is refused, no citation is returned, or the search is inconclusive, the draft remains provisional with a safe status. The user can enter a name or retry where appropriate. Raw model output, prompts, API keys, receipt contents, and fetched page bodies are not retained in Grocy audit records or logs.

## Cost, credentials, and retries

Use a dedicated OpenAI project API key only in the `grocy-mcp` Komodo environment as `OPENAI_API_KEY`; never pass it to Grocy, the browser, Git, build URLs, or logs. Keep the fallback disabled when the key is absent. The operator should set a project hard spend limit separately in OpenAI Platform. The companion uses a configurable model, bounded output/search budget and timeout; the default follows the currently supported Responses web search integration. A configurable daily call ceiling and one automatic search reservation per GTIN prevent a failed provider from repeatedly spending through the five-attempt Grocy worker retry cycle. Explicit manual retry may allow one additional search, with an auditable actor and bounded ceiling. Repeated scans and multiple trips sharing a GTIN reuse the stored result.

OpenAI's [web search guide](https://developers.openai.com/api/docs/guides/tools-web-search) documents the Responses `web_search` tool, required tool choice, and citation annotations; it also notes tool call cost. Its [production guidance](https://developers.openai.com/api/docs/guides/production-best-practices) recommends server-side secret storage and project spend controls. [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) can constrain extraction, but Grocy must still independently validate citations and the barcode evidence.

## Rollout and acceptance

Implement the companion search adapter and worker result normalization in `grocy-mcp`; extend the Grocy research contract, draft DTO, and review card under `custom/grocy_AI/` and `public/custom/grocy_AI/`. Only the companion receives the new key through its Compose/Komodo mapping. No schema migration or upstream Grocy product write path should change unless implementation tests show a durable call reservation needs a namespaced migration.

Tests cover provider hit bypass, definitive dual miss, transient provider failure, exact-code citation, missing or mismatched citation, hostile URL/content, timeout/refusal, key absent, daily ceiling, duplicate claims, lost worker response, user edits, and zero product/barcode/stock writes before explicit approval and purchase commit. Run the companion suite, Grocy PHP/receipt contract suite, mobile Chromium/WebKit review suite, and a live secret-free health check.

After a fresh full-data backup and deployment, preview trip #12's still-provisional UPC job IDs and the maximum OpenAI calls. Requeue only that bounded set once the dedicated key and spend limit are configured. Verify source links and names on a phone before approving any new product. Preserve the open trip, receipts, and stock throughout the research backfill.
