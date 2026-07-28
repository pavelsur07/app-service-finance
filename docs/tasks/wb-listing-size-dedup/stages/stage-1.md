## Stage 1: источник задвоения закрыт — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes
**Следующее действие:** continue autonomously → Stage 2

### Scope Stage
- Stage base commit: `b1a49db2`
- Work items completed: `1.1`, `1.2`, `1.3`

### Что сделано
- `normalizeWbSize()` приводит `'0'` к `'UNKNOWN'`: WB отдаёт безразмерный товар как `ts_name="0"` в отчётах и как `""` в каталоге, из-за чего на один nm_id заводилось два листинга.
- Снято условие `size !== 'UNKNOWN'` при записи баркода в `createListing()`. Без этого после нормализации безразмерные листинги перестали бы получать баркоды, а это единственный рабочий способ массовой загрузки себестоимости WB. Ветка безопасна: до `createListing()` вызов уже проверил, что баркод не принадлежит другому листингу WB компании, а `upsertIfNotExists()` идемпотентен и защищён уникальным индексом `(company_id, marketplace, barcode)`.
- Два unit-теста: нормализация `'0'` и запись баркода для безразмерного листинга.

### Затронутые файлы
- `site/src/Marketplace/Application/Service/WbListingResolverService.php` — modified
- `site/tests/Unit/Marketplace/Application/Service/WbListingResolverServiceTest.php` — modified

### Self-review
- [x] Scope compliance — только резолвер WB и его тест
- [x] Patterns / naming — стиль файла сохранён
- [x] Forbidden actions — none (нет `dump()`, новых зависимостей, правок legacy-зоны)
- [x] Security (companyId, IDOR) — новых запросов к БД нет
- [x] CS-Fixer точечно по изменённым файлам / phpunit — green
- [x] ARCHITECTURE.md — N/A, новых Facade / Enum / Entity нет

### Регрессия доказана
- `testZeroTsNameIsNormalizedToUnknownSize` красный на старом коде (`'0'` вместо `'UNKNOWN'`).
- `testBarcodeIsStoredForSizelessListing` красный при нормализации со старым условием записи баркода — проверено отдельным прогоном.

### External Claude Code review
- Result: N/A — внешний read-only review по `AGENTS.md` запускает Codex; в этой сессии реализацию ведёт сам Claude Code, отдельного независимого ревьюера нет. Выполнен внутренний review полного diff.

### Команды для проверки
- `make site-test-unit` — 1629 тестов, green
- `docker compose run --rm site-php-cli vendor/bin/php-cs-fixer fix --dry-run --path-mode=intersection <файл>`

### Риски / на что обратить внимание ревьюеру
- `'0'` считается «без размера». На проде проверено: у компании с настоящими размерами (Вумджой, XS/S/M/L) нет ни одного листинга с `size='0'`, значение встречается только у безразмерных товаров.
- Запись баркода для безразмерных листингов — изменение поведения; см. обоснование выше.

### Открытые вопросы
- нет
