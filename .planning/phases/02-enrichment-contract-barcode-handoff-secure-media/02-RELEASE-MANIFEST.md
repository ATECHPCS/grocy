# Phase 02 Release Manifest

main_branch: atech-main
main_candidate_sha: e7f8036de05e606745b4b3a92ff6ee8694cb76ce
companion_branch: main
companion_candidate_sha: 3861acf34694585cf2201a1f8edbed4e7f6d8627
stable_branch: atech-release
stable_base_sha: 30242219bfe8707d4c8e00c82b31cd15ea89b57d
stable_portable_sha: d0fd0dfa2bf7748a6fab5a758c471d06192bc382
stable_portable_parent_sha: 2d73c3dd979e22771d74dd55cc863139d1bfea3f
stable_adapter_sha: cf317830e0ce5138506edff75a45b19407a67672
stable_adapter_parent_sha: d0fd0dfa2bf7748a6fab5a758c471d06192bc382
stable_runtime_sha: 9efe0dd3ba44eab9c60148e5b6a19947a6acf0ab
stable_runtime_parent_sha: cf317830e0ce5138506edff75a45b19407a67672
stable_module_version: 2.4.1
stable_cache_marker: ATECHPCS-grocy_AI-9
main_post_candidate_tracking_exception: user-approved finite GSD handoff, state, roadmap, and checkpoint paths
dependency_constraints_sha256: 53c2a4b530e9802d0d0f5587875db0ae72320652dd4627925b06eca2edbb2019
portable_paths_sha256: fda39b8a8f3a5c14d6d5bebc230cfd4b29c4e570e625f5ae52b709c002501cc7
phase2_changed_paths_sha256: fda39b8a8f3a5c14d6d5bebc230cfd4b29c4e570e625f5ae52b709c002501cc7
stable_adapter_paths_sha256: 7599fc38b230df4035aa48271d02322576e78b52a8199b07ce82644e1d164576

portable_paths_begin
custom/grocy_AI/README.md
custom/grocy_AI/module-version.json
custom/grocy_AI/src/GrocyAiBarcodeService.php
custom/grocy_AI/src/GrocyAiContract.php
custom/grocy_AI/src/GrocyAiDiagnostic.php
custom/grocy_AI/src/GrocyAiGtin.php
custom/grocy_AI/src/GrocyAiService.php
custom/grocy_AI/tests/barcode-handoff.php
custom/grocy_AI/tests/fixtures/enrichment-v2-cases.json
custom/grocy_AI/tests/run.php
public/custom/grocy_AI/grocy-ai.css
public/custom/grocy_AI/product-enrichment.js
portable_paths_end

phase2_changed_paths_begin
custom/grocy_AI/README.md
custom/grocy_AI/module-version.json
custom/grocy_AI/src/GrocyAiBarcodeService.php
custom/grocy_AI/src/GrocyAiContract.php
custom/grocy_AI/src/GrocyAiDiagnostic.php
custom/grocy_AI/src/GrocyAiGtin.php
custom/grocy_AI/src/GrocyAiService.php
custom/grocy_AI/tests/barcode-handoff.php
custom/grocy_AI/tests/fixtures/enrichment-v2-cases.json
custom/grocy_AI/tests/run.php
public/custom/grocy_AI/grocy-ai.css
public/custom/grocy_AI/product-enrichment.js
phase2_changed_paths_end

stable_adapter_paths_begin
CUSTOMIZATIONS.md
Dockerfile.atech
custom/grocy_AI/routes.php
custom/grocy_AI/src/GrocyAiApiController.php
custom/grocy_AI/version.json
migrations/0256.php
public/viewjs/productform.js
views/productform.blade.php
stable_adapter_paths_end

stable_runtime_paths_begin
Dockerfile.atech
custom/grocy_AI/routes.php
custom/grocy_AI/src/GrocyAiApiController.php
public/viewjs/components/productamountpicker.js
views/consume.blade.php
views/inventory.blade.php
views/mealplan.blade.php
views/productbarcodeform.blade.php
views/purchase.blade.php
views/recipeform.blade.php
views/recipeposform.blade.php
views/recipes.blade.php
views/shoppinglistitemform.blade.php
views/transfer.blade.php
stable_runtime_paths_end

main_post_candidate_paths_begin
.planning/HANDOFF.json
.planning/ROADMAP.md
.planning/STATE.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/.continue-here.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-17-PLAN.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-17-SUMMARY.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-18-PLAN.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-18-SUMMARY.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-19-PLAN.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-19-SUMMARY.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-20-PLAN.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-20-SUMMARY.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-21-PLAN.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-21-SUMMARY.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-DEPLOYMENT-EVIDENCE.md
.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-RELEASE-MANIFEST.md
custom/grocy_AI/tests/deployment-gate.sh
custom/grocy_AI/tests/release-gate.sh
main_post_candidate_paths_end

portable_blobs_begin
custom/grocy_AI/README.md ac3667c09263d4167882abbb003348f576de0826 f131e267caf13cb6118b525350c72abf5c4f7a8c1550b51f8caeca028dd839c8
custom/grocy_AI/module-version.json 8f274a4f813a8af39300b064df2ab582c23055d9 297ee4118e248ac4ab231ad6e8c83789c55f1b70b683fa934cd9a00d8e9af531
custom/grocy_AI/src/GrocyAiBarcodeService.php f4e85279966bd397e9d27501e8963379465d568e bff10e51739bdacdc5fba719aadf2937208993d2880b3891ccebb9db91f03cc3
custom/grocy_AI/src/GrocyAiContract.php eefeb335315cf118875e5ae6695e6256f9cb2d39 0a34771d55539a19ede308d4df38fc5f5593d0d93555db393fbadc39e8fa1cd5
custom/grocy_AI/src/GrocyAiDiagnostic.php e7bcdf6c9bf998f2e01cee7df7f25a9f435398d4 6abee070567cc173afaf4a2c82b1f92665ad8f2520b7cb38a6852cb9dd6c59f4
custom/grocy_AI/src/GrocyAiGtin.php 961b62862ad4938e4e7e671bc795d3ceb90f9151 b68a3253183f54b56f740e4eebb75ee36dd602090196b4f371a5948b1bcddacf
custom/grocy_AI/src/GrocyAiService.php 4bab59b9c21ae77039e6407aeab50297c00319be 7df2e868803bfad9f14eef4477afae61edfc672ccc0cd719cf617ad24a86af52
custom/grocy_AI/tests/barcode-handoff.php 701f48ae051fdcbcabb51cc7b0177fd80c9c9572 1a80fc4830b487e5b8cea2545746357100383d746222be5d03941c1a7cef89c6
custom/grocy_AI/tests/fixtures/enrichment-v2-cases.json 927fff88314d85486015ff04976c35ed6be8a0ba 25bcd693182aa2e7866175da47e7e77252ddaf6e0daf7561683f56460a4cd637
custom/grocy_AI/tests/run.php 6f14c369ca8ebb1399868c7ccf99f6b590699230 2a812d2c0bb36abcc0404b6b9ddfb2f8cb1badf8ceaf3628382f3c4606229dbc
public/custom/grocy_AI/grocy-ai.css 5eb8c71a3da5dc42c8cb384ddd4879d3cf3eafed 1de2954ccbb60a5ba8b026309e7ede5d656bf3e5403b456d51fc3cbef568b0c2
public/custom/grocy_AI/product-enrichment.js 7534ebff50b6497dca21fd334461c490480843c7 9b69a6ca7a79bff62f3c5b432d77c07b02acee95ab7cd1f6f529a787308273a2
portable_blobs_end

candidate_gate_results_begin
main_php_contract: 113/113 PASS
main_barcode_handoff: 84/84 PASS
main_browser_release: 146/146 PASS
companion_unittest_discovery: 42/42 PASS
stable_php_contract: 113/113 PASS
stable_barcode_handoff: 84/84 PASS
stable_portable_parity: 12/12 PASS
stable_adapter_scope: 8/8 PASS
stable_adapter_parent: PASS
live_canonical_collision_groups: 0
candidate_gate_results_end

Phase 1 physical evidence remains untouched and `SKIPPED — NOT ACCEPTED`.
Nutrition Facts remains deferred.
