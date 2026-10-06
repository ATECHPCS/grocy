# Capture Purchase and Stock Unit Suggestions Design

## Goal

When receipt review offers to create a product for an unknown scanned UPC, suggest its Grocy purchase unit and stock unit alongside the existing name and classification suggestions. The reviewer can inspect and change both units before approving product creation. A suggestion never creates a product, conversion, barcode, or stock entry.

## Evidence and choice boundaries

Use the current active Grocy quantity-unit catalog as the only set of selectable IDs. Package and linked receipt text can support a unit suggestion, but they are untrusted evidence. Local matching uses whole unit names or established abbreviations in that text; it does not infer a conversion from a number or substring. Exact, unambiguous local matches take precedence. The existing bounded, tool-free OpenAI classification request may suggest unit IDs from the supplied active choices when it already runs for an identified product. There is no separate paid call, web search, or retry to obtain units. If classification is skipped because the existing category mapping is sufficient, the local rules still run. An ambiguous unit stays blank.

Suggest separate purchase and stock units only when Grocy already has a global conversion with a positive factor from purchase to stock and both units are active. An explicit package statement such as `6 x 330 mL` does not itself verify a conversion and does not create one; it is ambiguous about how the household stocks the product. If one clear unit applies to both, suggest that same unit for both. If the proposed pair lacks a verified conversion, discard the pair and retain only independently safe suggestions; the reviewer must choose a valid pair. Parent-product compatibility remains a separate validation against the chosen stock unit.

## Source and review behavior

Receipt review labels each suggested unit with its source and shows the selected purchase and stock units beside the product name, Product Group, food classification, and Generic Parent. A valid, unambiguous suggestion can preselect a unit, but a saved reviewer choice or explicit clearing wins over later research. The reviewer can change either unit before approval. Both appear in the final product-creation confirmation. No unit suggestion silently changes receipt quantity, receipt price, purchase allocation, or an existing product.

Grocy validates suggested IDs against active units and validates different-unit pairs against the current global conversion table before preselecting them. Product creation repeats those validations in its existing transaction. Failed or unavailable research leaves the controls usable and a neutral explanation rather than a guessed unit.

## Contract and rollout

Extend the existing classification input and result contract with bounded unit choices, eligible global conversion pairs, and nullable purchase/stock unit IDs. Keep the same durable paid-call reservation and daily ceiling; a classification result is still tied to one draft result revision. Grocy normalizes older classification results that have no unit fields, so previously captured trips remain reviewable without rerunning paid calls. Local unit suggestions can be recomputed for those trips from current catalog and evidence. Persist reviewer unit selection or explicit clearing in the existing draft edit flow so it survives refreshes and takes precedence over later suggestions; product creation remains the only normal Grocy product write.

Ship the companion result contract and Grocy consumer together. Tests cover local evidence matching, ambiguous evidence, active-choice validation, conversion direction and factor, parent compatibility, saved-choice precedence, stale results, legacy results, paid-call limits, and no product or stock writes before approval. Browser tests cover narrow-screen unit controls, readable source labels, and the final confirmation. Rollout verifies a reviewable live trip without approving products or committing stock.
