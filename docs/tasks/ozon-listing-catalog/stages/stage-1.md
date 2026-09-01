## Stage 1: схема и raw-seam — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP — Release Gate, решение Владельца

### Scope Stage
- Stage base commit: `97fcdc73b32448a60f2f9265087ff972be457f75`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`

### Что сделано

Подготовительный этап без изменения поведения. Новые поля никем не пишутся,
новый метод фасада никем не вызывается — потребители появляются в Stage 2.
Это осознанно: разнести схему и логику на два независимо деплоящихся этапа
дешевле, чем катить миграцию вместе с внешним API.

- `MarketplaceListing.marketplaceCreatedAt` — дата создания товара **на маркетплейсе**.
  Отдельное поле, потому что `createdAt` означает другое: момент появления строки
  у нас, его проставляет `#[ORM\PrePersist]`. Для Ozon источник — `items[].created_at`
  из `/v3/product/info/list`; в выгрузке реального API поле заполнено у 50 из 50 карточек.
- `MarketplaceListing.lastSeenAt` — когда листинг последний раз встретился в выгрузке
  каталога. По решению Владельца пропажа из каталога **не** гасит `isActive`: разбор
  ручной. Побочный эффект решения — частичный сбой пагинации в Stage 2 не сможет
  массово погасить живые товары.
- `RawStorageFacade::storeAndGetIds(RawBatch): list<string>` — та же запись, что и
  `store()`, но возвращает скалярные id. Нужен потому, что вызывать запись будет модуль
  `Marketplace`, а `App\Ingestion\Entity\*` не должны пересекать границу модуля;
  `tests/Unit/Ingestion/Architecture/EntityBoundaryTest` грепает текстовые ссылки на них
  вне Ingestion. Внутри Ingestion по-прежнему используется `store()`.

### Затронутые файлы
- `site/migrations/Version20260901090000.php` — new
- `site/src/Marketplace/Entity/MarketplaceListing.php` — modified
- `site/src/Ingestion/Facade/RawStorageFacade.php` — modified
- `site/tests/Unit/Marketplace/Entity/MarketplaceListingTest.php` — modified
- `site/tests/Integration/Ingestion/RawStorageFacadeTest.php` — modified
- `ARCHITECTURE.md` — modified (версия 1.83, раздел «Marketplace: даты жизненного
  цикла листинга», строка про `storeAndGetIds`, запись в changelog)

Публичный контракт расширен одним методом фасада; существующие сигнатуры не менялись.

### Миграция

`Version20260901090000` добавляет две nullable-колонки без DEFAULT — `ADD COLUMN` такого
вида не переписывает таблицу и не держит долгой блокировки.

SQL взят из `doctrine:schema:update --dump-sql`, не написан по памяти. Тот же dump
показывает посторонний дрейф схемы (`created_at`/`updated_at` TYPE, `size DROP DEFAULT`,
`uniq_marketplace_listing_company_variant`) — он существовал **до** задачи и в миграцию
намеренно не включён.

Прогнана на локальной БД в обе стороны: `up` → `down` → `up`. После `down` колонок нет,
после `up` обе присутствуют:

```
last_seen_at             timestamp without time zone   YES
marketplace_created_at   timestamp without time zone   YES
```

Данные не мигрируются, обратный ход теряет только то, что этим Stage и добавлено.
Замеры «до» не требуются: существующие строки не читаются и не изменяются.

### Self-review
- [x] Scope compliance — только схема и seam; ни один существующий вызов не тронут
- [x] Patterns / naming — `declare(strict_types=1)`, `datetime_immutable`, Entity без `final`
- [x] Forbidden actions — нет `dump()`, `new Service()`, секретов, `flush()` в Repository
- [x] Security / IDOR — новых выборок нет; `storeAndGetIds` делегирует в существующий
      company-scoped `store()`, своих запросов не делает
- [x] Индексы — новые колонки не FK и не участвуют в фильтрах, индексы не нужны
- [x] Границы модулей — `IngestRawRecord` упоминается только внутри `App\Ingestion`
- [x] `marketplaceCreatedAt` документирован рядом с `createdAt`, чтобы не путались
- [x] `ARCHITECTURE.md` обновлён

### Тесты

5 новых кейсов, все прогнаны красными до реализации:

| Тест | Красный на старом коде |
|---|---|
| `testMarketplaceCreatedAtIsNullByDefault` | `Call to undefined method ...::getMarketplaceCreatedAt()` |
| `testMarketplaceCreatedAtKeepsTheDateTheProductWasCreatedOnTheMarketplace` | `Call to undefined method ...::setMarketplaceCreatedAt()` |
| `testLastSeenAtIsNullByDefault` | `Call to undefined method ...::getLastSeenAt()` |
| `testLastSeenAtCanBeSet` | `Call to undefined method ...::setLastSeenAt()` |
| `testStoreAndGetIdsReturnsScalarIdentifiersUsableWithoutTheEntity` | `Call to undefined method ...RawStorageFacade::storeAndGetIds()` |

Красный здесь тривиальный — функциональность новая, а не исправление ошибки.
Содержательный регрессионный тест (листинг с `name = NULL` по вторичному fbs-SKU)
относится к Stage 2 и в этом Stage не пишется.

Интеграционный тест `storeAndGetIds` намеренно ходит в реальный контейнер, реальный
`ObjectStorage` и реальную БД, без моков: проверяется, что возвращённый id пригоден
для чтения payload'а через `read()` — то есть сущность вызывающему действительно не нужна.

### Команды для проверки

| Проверка | Результат |
|---|---|
| `composer test:unit` | OK — 1955 tests, 11004 assertions (4 deprecation, унаследованные) |
| `composer test:integration` | OK — 992 tests, 4940 assertions |
| `composer cs:check` | Found 0 of 2365 files, exit 0 |
| `composer cs:strict-types` | Found 0 of 2365 files, exit 0 |
| `composer stan` (PHPStan level 8) | `[OK] No errors`, 2350 файлов |
| `doctrine:migrations:execute --down` / `--up` | оба OK, колонки проверены в `information_schema` |

Baseline зелёный до и после задачи — красного baseline в этом Stage нет.

Промежуточный красный прогон интеграционных тестов (136 ошибок
`Undefined column: marketplace_created_at`) был вызван неприменённой миграцией
в тестовой БД, а не кодом: после `make site-test-migrations` сюита зелёная.
Отмечено, чтобы разница не читалась как скрытый регресс.

### External review
- Reviewer: Codex CLI 0.151.0 (`codex exec -s read-only --ephemeral`, дифф через stdin)
- Iterations: 1
- Result: **REVIEW_GREEN**
- Findings: нет
- Ограничения ревьюера: без шелла и доступа к репозиторию — видит только переданный
  дифф. Факты, которые он не мог добыть сам, переданы в промпте: результаты пяти
  проверок, прогон миграции в обе стороны с выводом `information_schema`, происхождение
  SQL из `--dump-sql` и сознательное исключение постороннего дрейфа схемы, тексты
  красных прогонов, существующие уникальные ключи таблицы, сигнатуры
  `IngestRawRecord::getId()` и `RawStorageFacade::store()`.
- Оговорка: зелёный с нуля итераций и без единой находки на диффе в 149 строк
  правдоподобен для механического изменения такого объёма, но он подтверждает
  меньше, чем зелёный после цикла исправлений. Содержательная проверка замысла
  придётся на Stage 2, где появляется логика.

### Риски / на что обратить внимание ревьюеру
- Поля добавлены без потребителей. Если Stage 2 не будет выпущен, это мёртвая схема —
  дешёвая (две nullable-колонки), но мёртвая.
- `lastSeenAt` намеренно не индексирован: в Stage 2 он пишется, но не фильтруется.
  Если в Stage 3 появится выборка «давно не встречался» — индекс нужно будет добавить
  тогда, а не заранее.
- Посторонний дрейф схемы `marketplace_listings` (`created_at`/`updated_at` TYPE,
  `size DROP DEFAULT`, отсутствующий уникальный частичный индекс) остаётся неустранённым.
  Он существовал до задачи; чинить его в этом Stage значило бы протащить чужое изменение
  в дифф. Вынесено в follow-up.

### Открытые вопросы
- нет
