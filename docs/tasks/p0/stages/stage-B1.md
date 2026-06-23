## Stage B1: Детерминизм fallback `accrualId` — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (B2)

### Что сделано
- Создан `App\Ingestion\Domain\Service\SourceDataHasher` — order-independent SHA-256 хеш
  source-row: рекурсивный `ksort` ассоциативных массивов + `json_encode` (UNESCAPED_UNICODE |
  UNESCAPED_SLASHES | PRESERVE_ZERO_FRACTION) + `sha256`. Зеркалит `RawNdjsonCodec::normalizeValue`,
  но для одной row.
- `OzonAccrualByDayPreviewMapper::accrualId` — убран параметр `$rowIndex`; fallback стал
  `'fallback-' . substr($hasher->hash($row), 0, 16)`. Сигнатура: `accrualId(array $row): string`.
- В `preview(...)` удалён счётчик `$rowIndex`; вызов `accrualId($row)`.
- Внедрён `SourceDataHasher` в конструктор маппера (autowired, без правок config).

### Затронутые файлы
- `site/src/Ingestion/Domain/Service/SourceDataHasher.php` — new
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapper.php` — modified
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonAccrualByDayPreviewMapperTest.php` — modified (фабрика + 2 новых теста)
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonAccrualByDayMapperTest.php` — modified (фабрика)

### Self-review
- [x] Scope compliance — только B1
- [x] Patterns / naming — `final readonly class` для stateless-сервиса
- [x] Forbidden actions — none (нет миграций, legacy не тронут, dump/dd нет)
- [x] Security — N/A (маппер, без Repository/companyId-доступа)
- [x] CS-Fixer — green на изменённых файлах; PHPUnit unit — green (10 тестов, 75 ассертов)
- [x] PHPStan — N/A (в проекте phpstan не установлен; `make stan` не сконфигурирован)
- [x] ARCHITECTURE.md — N/A (Domain Service, не Facade/Enum/Entity)

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter 'OzonAccrualByDayPreviewMapperTest|OzonAccrualByDayMapperTest'`
- `docker compose run --rm -T site-php-cli php vendor/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.php --path-mode=intersection -- <files>`

### Риски / на что обратить внимание ревьюеру
- O1 (открытый): прод-raw_records со старой формулой `fallback-N-...` станут «осиротевшими»
  после B1. Подтвердить запросом к проду перед merge; при необходимости — одноразовый бэкфилл.

### Открытые вопросы
- нет
