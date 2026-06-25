# Stale Branches Cleanup — Plan

**Generated at:** 2026-06-25
**Base branch:** origin/master
**Total non-merged branches:** 215
**Run scope:** Phases 0–6 — plan approved and **executed** on 2026-06-25 (see Execution result below).
**Thresholds (Owner-confirmed, defaults):** ACTIVE <14d · RECENT 14–59d · STALE 60–179d · ANCIENT ≥180d

## Summary

| Category | Count | Default action |
|---|---|---|
| 🛡️ KEEP (protected) | excluded from scan | keep |
| 🟢 KEEP (active < 14d) | 2 | keep |
| 🟡 REVIEW (14–59d, single/newest) | 12 | manual decision |
| 🟡 DELETE_OLD_DUP (older duplicate in family) | 26 | delete after approval |
| 🟠 DELETE_STALE (60–179d) | 159 | delete after approval |
| 🔴 DELETE_ANCIENT (≥ 180d) | 16 | delete after approval |

**Branches proposed for deletion:** 201
**Branches recommended to keep:** 14

## Top branch families (>1 branch per family)

| Family | Count | Newest branch | Action |
|---|---|---|---|
| `codex/fix-migration-error-with-balance_categories` | 12 | 2026-01-15 / origin/codex/fix-migration-error-with-balance_categories | keep newest, delete 11 others |
| `codex/add-ci-job-for-empty-db-migration` | 5 | 2025-12-24 / origin/codex/add-ci-job-for-empty-db-migration | keep newest, delete 4 others |
| `claude/fix-sticky-table-header` | 3 | 2026-04-21 / origin/claude/fix-sticky-table-header-DRDXV | keep newest, delete 2 others |
| `codex` | 2 | 2026-03-01 / origin/codex-66oa2j | keep newest, delete 1 others |
| `codex/add-admin-plan-viewing-functionality` | 2 | 2026-01-31 / origin/codex/add-admin-plan-viewing-functionality | keep newest, delete 1 others |
| `codex/add-read-only-plans-view-in-admin` | 2 | 2026-01-31 / origin/codex/add-read-only-plans-view-in-admin | keep newest, delete 1 others |
| `codex/add-subscriptionintegration-entity-and-repository` | 2 | 2026-01-31 / origin/codex/add-subscriptionintegration-entity-and-repository | keep newest, delete 1 others |
| `codex/add-usagecounter-entity-and-repository` | 2 | 2026-01-31 / origin/codex/add-usagecounter-entity-and-repository | keep newest, delete 1 others |
| `codex/add-view-for-plans-in-admin` | 2 | 2026-01-31 / origin/codex/add-view-for-plans-in-admin | keep newest, delete 1 others |
| `codex/fix-email-case-sensitivity-on-registration` | 2 | 2026-01-27 / origin/codex/fix-email-case-sensitivity-on-registration | keep newest, delete 1 others |
| `codex/implement-invitetokenservice-and-companyinvitemanager` | 2 | 2026-01-27 / origin/codex/implement-invitetokenservice-and-companyinvitemanager | keep newest, delete 1 others |
| `codex/implement-token-authorization-for-public-api` | 2 | 2025-09-19 / origin/codex/implement-token-authorization-for-public-api | keep newest, delete 1 others |

## Full deletion plan

### 🔴 DELETE_ANCIENT (≥180 days)

| Branch | Age | Author | Last commit |
|---|---|---|---|
| origin/codex/align-button-block-to-the-right-q7gf9k | 296d | pavelsur07@gmail.com | 2025-09-01 |
| origin/codex/fix-transaction-saving-error-in-cashtransactioncontroller | 295d | pavelsur07@gmail.com | 2025-09-02 |
| origin/codex/remove | 281d | pavelsur07@gmail.com | 2025-09-16 |
| origin/codex/implement-token-authorization-for-public-api | 278d | pavelsur07@gmail.com | 2025-09-19 |
| origin/codex/add-apijson-method-to-reportcashflowcontroller | 278d | pavelsur07@gmail.com | 2025-09-19 |
| origin/codex/refactor-plcategory-with-crud-ui | 265d | pavelsur07@gmail.com | 2025-10-02 |
| origin/codex/investigate-async-messenger-configuration | 263d | pavelsur07@gmail.com | 2025-10-04 |
| origin/codex/fix-docker-compose-socket-proxy-error-jqtm0x | 261d | pavelsur07@gmail.com | 2025-10-06 |
| origin/codex/implement-payment-calendar-page | 252d | pavelsur07@gmail.com | 2025-10-15 |
| origin/codex/refactor-ui-to-match-tabler-io | 242d | pavelsur07@gmail.com | 2025-10-26 |
| origin/codex/refactor-_sidebar-to-remove-macros | 241d | pavelsur07@gmail.com | 2025-10-26 |
| origin/codex/add-create-document-button-in-payment-schedule | 206d | pavelsur07@gmail.com | 2025-11-30 |
| origin/codex/refactor-pl-preview-ui-rlne2j | 205d | pavelsur07@gmail.com | 2025-12-01 |
| origin/codex/create-empty-cardreceiptingest-module | 189d | pavelsur07@gmail.com | 2025-12-18 |
| origin/codex/add-migration-rules-and-ci-checks | 183d | pavelsur07@gmail.com | 2025-12-24 |
| origin/codex/add-ci-job-for-empty-db-migration | 183d | pavelsur07@gmail.com | 2025-12-24 |

### 🟠 DELETE_STALE (60–179 days)

| Branch | Age | Author | Last commit |
|---|---|---|---|
| origin/codex/update-composer-dependencies-for-php-version | 177d | pavelsur07@gmail.com | 2025-12-30 |
| origin/codex/fix-missing-php-zip-extension | 177d | pavelsur07@gmail.com | 2025-12-30 |
| origin/codex/fix-migration-error-with-balance_categories-gtqvwz | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/update-project-selection-to-show-hierarchy | 156d | pavelsur07@gmail.com | 2026-01-19 |
| origin/codex/add-reusable-projectdirectionpicker-widget | 156d | pavelsur07@gmail.com | 2026-01-19 |
| origin/codex/add-parent-selection-validation-to-projectdirection | 156d | pavelsur07@gmail.com | 2026-01-19 |
| origin/codex/add-methods-for-project-hierarchy | 156d | pavelsur07@gmail.com | 2026-01-19 |
| origin/codex/ensure-importlog-finishedat-is-set | 154d | pavelsur07@gmail.com | 2026-01-22 |
| origin/codex/roll-back-to-previous-version | 153d | pavelsur07@gmail.com | 2026-01-22 |
| origin/codex/add-xlsx-format-validation-service | 153d | pavelsur07@gmail.com | 2026-01-23 |
| origin/codex/add-test-for-invalid-non-xlsx-file | 152d | pavelsur07@gmail.com | 2026-01-23 |
| origin/codex/remove-marketplace-modules-completely | 151d | pavelsur07@gmail.com | 2026-01-24 |
| origin/codex/implement-invitetokenservice-and-companyinvitemanager | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/implement-email-invitation-sending | 148d | pavelsur07@gmail.com | 2026-01-28 |
| origin/codex/fix-symfony-argument-resolver-error | 148d | pavelsur07@gmail.com | 2026-01-28 |
| origin/codex/fix-email-case-sensitivity-on-registration | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/fix-activecompanyservice-for-invited-operators | 148d | pavelsur07@gmail.com | 2026-01-28 |
| origin/codex/create-company-repositories-structure | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/add-unit-and-functional-tests-for-company-wxmjmo | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/add-unit-and-functional-tests-for-company | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/add-info-section-on-homepage-based-on-tabler | 147d | pavelsur07@gmail.com | 2026-01-29 |
| origin/codex/add-subscriptionintegration-entity-and-repository | 145d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-billing-subscription-entity-and-repository | 145d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-view-for-plans-in-admin-flfkwh | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-view-for-plans-in-admin | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-usagecounter-entity-and-repository | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-read-only-plans-view-in-admin | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-admin-plan-viewing-functionality | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-auditlog-entity-and-migration | 140d | pavelsur07@gmail.com | 2026-02-04 |
| origin/codex/refactor-cashtransactioncontroller-to-use-new-service | 138d | pavelsur07@gmail.com | 2026-02-07 |
| origin/codex/fix-cash-file-import-job-hang | 137d | pavelsur07@gmail.com | 2026-02-07 |
| origin/codex/implement-freecash-widget-in-analytics-snapshot | 132d | pavelsur07@gmail.com | 2026-02-13 |
| origin/codex/update-pl-register-to-fact-only | 130d | pavelsur07@gmail.com | 2026-02-15 |
| origin/codex/implement-set-purchase-price-action | 122d | pavelsur07@gmail.com | 2026-02-22 |
| origin/codex/-marketplacecost | 121d | pavelsur07@gmail.com | 2026-02-24 |
| origin/codex-66oa2j | 115d | pavelsur07@gmail.com | 2026-03-01 |
| origin/codex/add-marketplacelistingpreloadquery-for-fast-preload | 111d | pavelsur07@gmail.com | 2026-03-05 |
| origin/codex/create-fetching-layer-and-orchestrator | 110d | pavelsur07@gmail.com | 2026-03-06 |
| origin/codex/create-three-new-action-files | 109d | pavelsur07@gmail.com | 2026-03-07 |
| origin/fix/inventory-cost-company-id-isolation | 89d | pavelsur07@gmail.com | 2026-03-27 |
| origin/claude/organize-enums-by-module-vKEFE | 88d | noreply@anthropic.com | 2026-03-28 |
| origin/claude/organize-dtos-by-module-vKEFE | 88d | noreply@anthropic.com | 2026-03-28 |
| origin/claude/snapshot-recalc-policy-DsYTQ | 87d | noreply@anthropic.com | 2026-03-30 |
| origin/claude/add-marketplace-enums-YjFjj | 87d | noreply@anthropic.com | 2026-03-29 |
| origin/claude/add-listing-snapshot-interface-f8oTJ | 87d | noreply@anthropic.com | 2026-03-29 |
| origin/codex/find-cause-of-handlerfailedexception | 86d | pavelsur07@gmail.com | 2026-03-30 |
| origin/claude/task-62-cost-mapping-resolver | 86d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/task-61-repository-interface | 86d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/task-60-migration-expand-migrate-contract | 86d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/task-59-unit-economy-cost-mapping-refactor | 86d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/fix-navbar-spacing-Az9mA | 86d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/add-snapshot-calculation-tests-NMbb1 | 86d | noreply@anthropic.com | 2026-03-30 |
| origin/claude/task-76-native-js-cost-mappings | 85d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/task-67-entity-constructor-order | 85d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/restore-remap-cost-mapping-architecture | 85d | noreply@anthropic.com | 2026-03-31 |
| origin/claude/add-unit-economy-cost-mapping-RaOuo | 85d | noreply@anthropic.com | 2026-03-31 |
| origin/fix/cleanup-artifacts-80 | 84d | noreply@anthropic.com | 2026-04-02 |
| origin/claude/fix-marketplace-economics-data-0ybRn | 84d | noreply@anthropic.com | 2026-04-01 |
| origin/claude/marketplace-processing-enums-El59Y | 83d | noreply@anthropic.com | 2026-04-02 |
| origin/claude/debug-ozon-sync-command-cQtJF | 83d | noreply@anthropic.com | 2026-04-03 |
| origin/claude/task-f-period-presets | 82d | noreply@anthropic.com | 2026-04-03 |
| origin/claude/review-marketplace-analytics-JrtVT | 82d | noreply@anthropic.com | 2026-04-03 |
| origin/claude/update-transaction-template-s2F5e | 81d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/fix-create-button-condition-Rw5BI | 81d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-document-entity-field-8mHhW | 81d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/revert-to-release-1377-R09nr | 80d | noreply@anthropic.com | 2026-04-06 |
| origin/claude/fix-create-from-icon-condition | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-marketplace-step-execution | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-marketplace-run-status-ui-10 | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-marketplace-retry-9ZDRx | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-marketplace-pipeline-tests-11 | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-marketplace-finalize-run-7ZDRx | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/add-marketplace-autostart-8ZDRx | 80d | noreply@anthropic.com | 2026-04-05 |
| origin/claude/fix-marketplace-csrf-token-cBRal | 79d | noreply@anthropic.com | 2026-04-06 |
| origin/claude/fix-marketplace-cost-category-8aefC | 79d | noreply@anthropic.com | 2026-04-07 |
| origin/claude/fix-marketplace-cache-key-MzKju | 79d | noreply@anthropic.com | 2026-04-07 |
| origin/fix/wb-timezone-msk | 78d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/review-wb-sync-cron-b0Yf4 | 78d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/review-marketplace-processing-2m7PJ | 78d | noreply@anthropic.com | 2026-04-07 |
| origin/claude/find-service-error-logging-ogbbT | 78d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/create-week-partition-service-vSs9a | 78d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/fix-marketplace-processing-1Nbhb | 77d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/add-forceReprocess-flag-I8wLq | 77d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/add-categories-mapping-method-aDheF | 77d | noreply@anthropic.com | 2026-04-08 |
| origin/claude/auto-trigger-pipeline-Gplrb | 76d | noreply@anthropic.com | 2026-04-10 |
| origin/claude/fix-docker-moscow-timezone-HiFZI | 74d | noreply@anthropic.com | 2026-04-12 |
| origin/claude/add-ozon-query-class-4Plaf | 74d | noreply@anthropic.com | 2026-04-11 |
| origin/claude/setup-marketplace-ads-GF6cR | 73d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/create-listing-sales-dto-DYsGE | 73d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/add-tests-builders-To1Zo | 73d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/wb-costs-operation-type-7d2tp | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/ozon-costs-explicit-operation-type | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/migrate-queries-operation-type-fallback | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/delete-dead-ozon-action | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/backfill-ozon-operation-type | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/audit-unit-economics-table-ORIfn | 72d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/add-ozon-decompensation-sibling | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/add-operation-type-migration-7cWd3 | 72d | noreply@anthropic.com | 2026-04-13 |
| origin/claude/widgets-summary-api | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/widgets-integrate | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/widgets-frontend | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/widgets-cpc-fix | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/widget-service-group-map-Yq3jM | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/fix-ozon-revenue-mismatch-lK9A7 | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/fix-marketplace-yesterday-period-258fn | 71d | noreply@anthropic.com | 2026-04-15 |
| origin/claude/fix-marketplace-sync-handler-258fn | 71d | noreply@anthropic.com | 2026-04-15 |
| origin/claude/debug-ozon-sales-records-f5P6f | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/debug-ozon-revenue-ccVI2 | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/add-unit-economy-query-8zRyJ | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/add-cleanup-ozon-returns-KPZwI | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/add-cleanup-ozon-processor-7qiD2 | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/add-cleanup-controller-UYjHx | 71d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/rename-widget-subgroups-QFHr4 | 70d | noreply@anthropic.com | 2026-04-15 |
| origin/claude/rename-marketplace-ads-commands-OohLm | 70d | noreply@anthropic.com | 2026-04-15 |
| origin/claude/ozon-reconciliation-report-LsXgv | 70d | noreply@anthropic.com | 2026-04-16 |
| origin/claude/fix-widget-mapping-CwaCk | 70d | noreply@anthropic.com | 2026-04-16 |
| origin/claude/fix-connection-type-visibility-ozon | 70d | noreply@anthropic.com | 2026-04-15 |
| origin/claude/add-marketplace-connection-type-OTnfy | 70d | noreply@anthropic.com | 2026-04-15 |
| origin/claude/xlsx-group-backward-compat-test | 69d | noreply@anthropic.com | 2026-04-16 |
| origin/claude/ozon-cost-category-reference-G75ad | 69d | noreply@anthropic.com | 2026-04-16 |
| origin/claude/invert-compensation-codes-zkM4W | 69d | noreply@anthropic.com | 2026-04-17 |
| origin/claude/fix-settlement-parser-4Hvds | 69d | noreply@anthropic.com | 2026-04-16 |
| origin/claude/debug-revenue-endpoint-vdnF6 | 69d | noreply@anthropic.com | 2026-04-17 |
| origin/claude/debug-ozon-discrepancies-PG0yI | 68d | noreply@anthropic.com | 2026-04-17 |
| origin/claude/audit-ozon-actions-IBFQv | 68d | noreply@anthropic.com | 2026-04-17 |
| origin/claude/add-idempotency-sync-ozon-pBH3E | 68d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/update-ozon-handler-tests-XcanS | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/review-marketplace-ads-8xWdm | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/remove-dead-fields-yIFXe | 67d | noreply@anthropic.com | 2026-04-19 |
| origin/claude/plan-ozon-ads-loading-YCI0T | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/ozon-ads-ui-loader-AH7zP | 67d | noreply@anthropic.com | 2026-04-19 |
| origin/claude/ozon-ad-statistics-message-eQHk5 | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/marketplace-ads-chunk-progress-tests | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/marketplace-ads-chunk-progress-switch | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/marketplace-ads-chunk-progress-repo | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/enhance-ozon-handler-PGEHD | 67d | noreply@anthropic.com | 2026-04-18 |
| origin/claude/month-closing-rollback-Dw37m | 65d | noreply@anthropic.com | 2026-04-20 |
| origin/claude/investigate-month-close-bug-671Zm | 65d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/fix-sticky-table-header-DRDXV | 65d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/fix-ozon-sale-gross-PROdZ | 65d | noreply@anthropic.com | 2026-04-20 |
| origin/claude/fix-ozon-operations-c6me9 | 65d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/fix-orphan-document-access-ybgBs | 65d | noreply@anthropic.com | 2026-04-20 |
| origin/claude/export-unit-extended-xlsx-VzYYR | 65d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/audit-unit-extended-export-putaF | 65d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/add-sku-column-zRzy4 | 65d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/unit-extended-xls-button | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/split-messenger-async-queue-TSb7D | 64d | noreply@anthropic.com | 2026-04-22 |
| origin/claude/setup-swagger-api-docs-GYESs | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/register-nested-schemas-ePuAP | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/openapi-typescript-setup-qoP0k | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/fix-snapshot-schema-vpJ4S | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/fix-schema-registration-qo3hU | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/document-marketplace-api-PYtbL | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/add-api-schema-validation-U9Irw | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/ad-efficiency-frontend-IgStl | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/ad-efficiency-backend-XstYJ | 64d | noreply@anthropic.com | 2026-04-21 |
| origin/claude/ozon-diagnostic-controller-jQmtg | 63d | noreply@anthropic.com | 2026-04-23 |
| origin/claude/refactor-sidebar-menu-JzXcd | 61d | noreply@anthropic.com | 2026-04-24 |

### 🟡 DELETE_OLD_DUP (older duplicate in family — newer sibling kept)

| Branch | Age | Author | Last commit |
|---|---|---|---|
| origin/codex/implement-token-authorization-for-public-api-k7xau3 | 278d | pavelsur07@gmail.com | 2025-09-19 |
| origin/codex-6oftok | 232d | pavelsur07@gmail.com | 2025-11-04 |
| origin/codex/add-ci-job-for-empty-db-migration-le1u8w | 183d | pavelsur07@gmail.com | 2025-12-24 |
| origin/codex/add-ci-job-for-empty-db-migration-ge1me6 | 183d | pavelsur07@gmail.com | 2025-12-24 |
| origin/codex/add-ci-job-for-empty-db-migration-3lhq64 | 183d | pavelsur07@gmail.com | 2025-12-24 |
| origin/codex/add-ci-job-for-empty-db-migration-2qsm3t | 183d | pavelsur07@gmail.com | 2025-12-24 |
| origin/codex/fix-migration-error-with-balance_categories-x4y55x | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-uizd5p | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-sjlkr8 | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-rp8m4z | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-q7r4l0 | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-ogt3zg | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-i62uuc | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-gau23w | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-bje61b | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-9l8zbb | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/fix-migration-error-with-balance_categories-8q7xpz | 160d | pavelsur07@gmail.com | 2026-01-15 |
| origin/codex/implement-invitetokenservice-and-companyinvitemanager-p4t1j0 | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/fix-email-case-sensitivity-on-registration-2lptkx | 148d | pavelsur07@gmail.com | 2026-01-27 |
| origin/codex/add-subscriptionintegration-entity-and-repository-qd7utg | 145d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-view-for-plans-in-admin-d6aqst | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-usagecounter-entity-and-repository-3xo4o7 | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-read-only-plans-view-in-admin-jwyp0o | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/codex/add-admin-plan-viewing-functionality-cj7yh0 | 144d | pavelsur07@gmail.com | 2026-01-31 |
| origin/claude/fix-sticky-table-header-62inZ | 72d | noreply@anthropic.com | 2026-04-14 |
| origin/claude/fix-sticky-table-header-HVv5W | 70d | noreply@anthropic.com | 2026-04-16 |

## Branches to keep (for reference)

### 🟢 ACTIVE (< 14 days — never deleted)

| Branch | Age | Author | Last commit |
|---|---|---|---|
| origin/codex/style-local-cleanup | 11d | pavelsur07@gmail.com | 2026-06-13 |
| origin/codex/vashfindir-domain-cutover | 8d | pavelsur07@gmail.com | 2026-06-16 |

### 🟡 REVIEW (14–59 days, single/newest — manual decision)

| Branch | Age | Author | Last commit |
|---|---|---|---|
| origin/claude/remove-cost-redirect-JJSRO | 59d | noreply@anthropic.com | 2026-04-27 |
| origin/claude/fix-expense-report-delta-OzJ0g | 59d | noreply@anthropic.com | 2026-04-27 |
| origin/claude/add-inventory-search-EhbpF | 59d | noreply@anthropic.com | 2026-04-27 |
| origin/claude/revert-soft-mode-close-month-IwC38 | 58d | noreply@anthropic.com | 2026-04-27 |
| origin/claude/marketplace-monthly-preclosing-yP6r8 | 58d | noreply@anthropic.com | 2026-04-27 |
| origin/claude/add-fines-prohibited-products-N5U7x | 58d | noreply@anthropic.com | 2026-04-27 |
| origin/claude/add-ozon-expense-type-Ff6Ul | 57d | noreply@anthropic.com | 2026-04-28 |
| origin/codex/refactor-wildberriesadapter-response-handling | 56d | pavelsur07@gmail.com | 2026-04-30 |
| origin/codex/perform-final-verification-and-regression | 51d | pavelsur07@gmail.com | 2026-05-04 |
| origin/codex/add-prod-safe-cli-cleanup-for-ozon-duplicates | 49d | pavelsur07@gmail.com | 2026-05-06 |
| origin/codex/fix-reprocess-raw-document-for-wb-sales-returns-and-costs | 41d | pavelsur07@gmail.com | 2026-05-14 |
| origin/codex/fix-deduplication-of-wb-financial-reports | 22d | pavelsur07@gmail.com | 2026-06-03 |

## Owner decisions (locked)

1. **Thresholds:** defaults (14 / 60 / 180) — locked.
2. **Extra protected branches:** none. Standard protection set is sufficient.
3. **REVIEW bucket (14–59d singles):** keep all. They will roll into STALE naturally if abandoned.
4. **DELETE_OLD_DUP policy:** keep newest in each family, delete older siblings. Confirmed.
5. **Notify authors:** no. 119 branches belong to automated agents, 82 to Owner himself.

> **Note on family normalization:** the family suffix-stripping keeps meaningful lowercase words
> (`-frontend`, `-header`, `-backend`, `-tests`, `-repo`, `-switch`, `-api`, …) and strips only
> hash-like suffixes (containing a digit or an uppercase letter, e.g. `-62inZ`, `-DRDXV`, `-q7gf9k`).
> Consequences, all immaterial to the deletion set (every branch below is a stale candidate regardless):
> - `marketplace-ads-chunk-progress-{repo,switch,tests}` stay as distinct singletons (word suffixes kept).
> - An all-lowercase hash without digits (e.g. `…balance_categories-gtqvwz`) is indistinguishable from a
>   word, so it stays a singleton by design of the rule.
> - `widgets-{frontend,summary-api,integrate,cpc-fix}` stay separate — these are genuinely distinct tasks.

## Authors list (for optional notification)

Unique authors among the 201 branches proposed for deletion:

| Author email | Branches to delete |
|---|---|
| noreply@anthropic.com | 119 |
| pavelsur07@gmail.com | 82 |

---

🛑 **STOP. План удаления готов. Ждать апрува Владельца на Phase 5.**

Чтобы запустить удаление (Phase 5), Владелец должен дать явный апрув — например `approve the deletion plan`. До этого ни одна ветка не удаляется.

---

## Execution result (2026-06-25)

- **Approved for deletion:** 201 (Owner approval: «план одобрен»)
- **Successfully deleted:** 201
- **Failed:** 0
- **Branches remaining in origin:** 1581 (down from 1783)
- **Keep-set verified intact:** 14/14 (🟢 ACTIVE + 🟡 REVIEW branches still present on remote)
- **Backup file:** `docs/maintenance/stale-branches-deleted-2026-06-25.txt` (201 lines `origin/<name> <sha>`)
- **Rollback command (per branch):**
  ```bash
  git branch <name> <sha> && git push origin <name>
  ```
  Full rollback loop: see `stale-branches-cleanup.md` task spec § "Rollback всей задачи".

### Execution notes

- Deleted via `git push origin --delete` in batches of 10, `git fetch --prune` + 2s pause between batches.
- Batch 14 timed out once on the first pass (network, rc=124); its 10 refs were retried individually and all deleted successfully.
- No protected branch, no branch <14d, and no keep-set branch was touched. No `git push --force`, `git reset`, `git gc`, or `git prune` was run.

🛑 **STOP. Финальный handoff. Задача Stale Branches Cleanup завершена.**
