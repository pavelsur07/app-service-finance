# Ozon Performance Ingestion — Handoff

## Summary

Implemented the first raw-only Ozon Performance ingestion stage inside the existing Ingestion module.

The implementation adds:

- Ozon Performance API client with token acquisition, cached bearer token, request classification, JSON/CSV report decoding, and no secret logging.
- Ozon Performance connector for campaigns, SKU CPC campaign objects, Search Promo products, SKU product statistics, Search Promo/CPO async statistics, and expense statistics.
- Ingestion connector registry routing by source and resource type, so seller finance and performance resources can coexist under `ozon`.
- Raw-only continuation handling for async report generation/polling.
- Daily and explicit-period dispatch commands for active Ozon Performance connections.
- Coverage readable labels/groups for new Performance resources.
- Unit and integration test coverage for registry routing, raw-only continuation, connector flows, and command dispatch.

## Commands

- `app:ingestion:ozon-performance:daily-load --days-back=14 --execute`
- `app:ingestion:ozon-performance:backfill --company-id=<uuid> --from=YYYY-MM-DD --to=YYYY-MM-DD --execute`

Both commands dispatch Ingestion backfill jobs only. They do not normalize Performance data at this stage.

## Files Changed

- `site/src/Ingestion/Application/Source/Ozon/OzonPerformanceReportConnector.php`
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonPerformanceReportClient.php`
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonPerformanceReportClientInterface.php`
- `site/src/Ingestion/Command/OzonPerformanceDailyLoadCommand.php`
- `site/src/Ingestion/Command/OzonPerformanceBackfillCommand.php`
- `site/src/Marketplace/Infrastructure/Query/ActiveOzonPerformanceConnectionsQuery.php`
- `site/src/Marketplace/Facade/MarketplaceFacade.php`
- `site/src/Ingestion/Domain/Service/ConnectorRegistry.php`
- `site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php`
- `site/src/Ingestion/Application/Service/IngestionResourceLabelProvider.php`
- Coverage DTO/query/controller/frontend schema/view files
- Unit/integration tests under `site/tests/*/Ingestion`

## Checks

- `php -l` on new/changed PHP files — passed
- `php bin/phpunit --testsuite unit --filter "ConnectorRegistryTest|OzonPerformanceReportConnectorTest"` — passed
- `php bin/phpunit --testsuite integration --filter "OzonIngestionRegistryTest|RunSyncChunkHandlerTest|OzonPerformanceLoadCommandTest"` — passed, existing PHPUnit deprecations only
- `make site-test-unit` — passed, existing warning/deprecation only
- `npm run lint` — passed
- `npm run build` — passed
- OpenAPI schema regeneration diff against `assets/api/schema.d.ts` — passed
- Symfony test/prod container command discovery — passed

## Migrations / API / Cron

- No database migrations.
- No public API endpoints added.
- Existing coverage API response was extended with optional readable fields: `resource_label`, `resource_group`.
- No cron or worker configuration changed.

## Risks / Follow-up

- This stage stores raw Performance data only. Normalization, money field modeling, and business reporting remain out of scope.
- Ozon Performance endpoint shapes should be validated with live samples before normalization design.
- If multiple active Performance connections per company become common, credential lookup should be tightened to connection-specific credentials.
