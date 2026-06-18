### Stage 5: Block 6 legacy Ozon reconnaissance — DONE

**Risk:** LOW
**Next action:** continue autonomously

#### What was done
- Read `docs/tasks/ingestion/TASK-06-ozon-connector.md`.
- Inspected legacy Ozon Seller API clients, handlers, credential readers, and raw processors.
- Identified which code paths should be wrapped by the new Ingestion adapter and which legacy code must remain untouched.

#### Files changed
- `docs/tasks/ingestion/stages/stage-5.md` — new reconnaissance report

#### Findings
- Daily seller report has two legacy entry points:
  - `App\Marketplace\Infrastructure\Api\Ozon\OzonFetcher::fetch(string $companyId, DateTimeImmutable $dateFrom): Generator` calls `POST /v2/finance/transaction/list`, reads credentials through `MarketplaceCredentialsQuery`, yields decoded pages.
  - `App\Marketplace\Service\Integration\OzonAdapter::fetchRawReport(Company $company, DateTimeInterface $fromDate, DateTimeInterface $toDate): array` calls `POST /v3/finance/transaction/list`, reads `MarketplaceConnection` through `MarketplaceConnectionRepository`, paginates up to `MAX_PAGES`, returns flattened operations.
- Monthly realization entry point:
  - `App\Marketplace\Service\Integration\OzonRealizationFetcher::fetch(MarketplaceConnection $connection, int $year, int $month): array` calls `POST /v2/finance/realization`, validates month is not current/future, returns full Ozon payload.
- Totals support:
  - `App\Marketplace\Infrastructure\Api\Ozon\OzonTransactionTotalsClient::fetchTotals(string $companyId, DateTimeImmutable $from, DateTimeImmutable $to): array` calls `POST /v3/finance/transaction/totals`; useful as reference for credential/error behavior, not required for Stage 6 connector pull.
- Credentials:
  - Legacy daily flows read from `marketplace_connections` by `(companyId, marketplace=ozon, connectionType=seller)`.
  - Ingestion already has `App\Ingestion\Infrastructure\Credential\LegacyMarketplaceCredentialReader`, used through `CredentialFacade`, supporting both connection UUID and refs like `marketplace:ozon:seller`.
- Existing raw processing logic to reuse as mapping reference:
  - `OzonReportRowClassifier` classifies `orders` as SALE, `ClientReturnAgentOperation` as RETURN, `OperationAgentStornoDeliveredToCustomer` as SALE, other `returns` as COST, and `services/other/compensation` as COST.
  - `OzonSalesRawProcessor` handles positive sales and storno sales.
  - `OzonReturnsRawProcessor` handles buyer returns and uses `posting_number` fallback external ids.
  - `OzonCostsRawProcessor` decomposes `sale_commission`, `delivery_charge`, `return_delivery_charge`, `services[]`, compensation and operation-level amounts; it uses `OzonServiceCategoryMap` / `OzonCostCategory` as the service-name source of truth.

#### Stage 6 implications
- Prefer a new `LegacyOzonClientAdapter` inside `App\Ingestion` that uses `CredentialFacade` plus `HttpClientInterface` directly. This keeps the adapter independent of legacy `Company` / `MarketplaceConnection` entity loading and preserves the task rule: no legacy client modifications.
- Use `/v3/finance/transaction/list` for daily reports, because current `OzonAdapter` already uses v3 and returns flattened operations.
- Use `/v2/finance/realization` for realization, matching the current legacy fetcher.
- Error classification should be implemented in the Ingestion adapter rather than inherited from legacy clients, because current legacy classes mostly throw generic `RuntimeException`.
- Tests must mock HTTP/adapter behavior; no live Ozon calls.

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- Read-only reconnaissance plus report creation; no executable code was changed.
- `git diff --check` will be run with Stage 6 verification.

#### Risks / reviewer focus
- Legacy has both v2 and v3 transaction-list usage; Stage 6 should standardize the new connector on v3 without changing legacy behavior.
- Realization payload does not obviously expose the same stable `operation_id` as daily report in all rows; mapper needs a documented fallback natural key.

#### Open questions
- none
