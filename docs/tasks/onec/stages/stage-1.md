# Stage 1 — приём частей пакета

База b97beafda. `POST /api/onec/v1/packages`, `onec_package` (Version20261126120000), `PackageEnvelope`, `ReceivePackageAction`, `OneCApiException`, лимитер `onec_packages` 120/мин, контракт.

Проверки: `phpunit tests/{Functional,Integration,Unit}/OneC` — 21 тест / 151 проверка; `SchemaInSyncWithMigrationsTest` и `EventScrubberTest` зелёные; PHPStan по каталогам OneC — 0 ошибок; cs-fixer — 0.

Самопроверка: токен проверяется до разбора тела и повторно под `FOR SHARE` перед вставкой; объект пишется вне транзакции до строки; конфликт ключа — `ON CONFLICT DO NOTHING`, EM не закрывается (тест гонки на закоммиченной строке конкурента, `PostgresResetTestCase`). Тело и токен в логи не пишутся (`/api/onec/v1/*` вычищается `EventScrubber`).
Осознанно: согласованность оболочки частей без блокировки пакета (`ponytail:` в коде).
