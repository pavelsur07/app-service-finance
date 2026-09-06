# Stage 1 — Свежесть остатков

`owner_gate: no` · `release_candidate: no` · `independently_deployable: yes`
Риск: 🟠 HIGH-LOCAL — меняет денежную величину, показанную в отчёте.

- `stage_base_commit`: `8f034153510082c0a4dec64874f6c166db0f9424`
- Ветка: `feat/inventory-stock-freshness`

## Что сделано

`StockQtyByListingOnDateQuery` перестал отдавать протухший снапшот как остаток на
дату отчёта. Источник старше порога не участвует в расчёте и возвращается отдельно
как отброшенный, поэтому «данных нет» отличимо от «данные протухли».

| Work item | Файл | Суть |
|---|---|---|
| 1.1 | `src/Inventory/Domain/StockSnapshotFreshnessPolicy.php` | Единственное определение «снапшот протух». Порог по умолчанию — 2 дня |
| 1.1 | `src/Inventory/Infrastructure/Query/StockQtyByListingOnDateQuery.php` | Нижняя граница свежести в `LEFT JOIN`; отброшенный источник остаётся видимым строкой с датой |
| 1.2 | `src/Inventory/Application/DTO/StockOnDateResult.php` | Контракт: `qtyByListingId` + `snapshotDateBySource` + `staleSources` |
| 1.2 | `src/Inventory/Facade/InventoryFacade.php` | Метод возвращает DTO вместо голой карты; докблок описывает правило выбора снапшота |
| 1.3 | `src/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQuery.php` | Читает DTO, но по-прежнему подставляет `0.0` — вид отчёта на этом Stage не меняется |
| 1.4 | `src/Inventory/Infrastructure/Query/LatestStockSnapshotDateQuery.php` | Дата последнего снапшота по паре «компания + источник», ограничена явным списком компаний |
| 1.4 | `src/Inventory/Command/StockFreshnessCheckCommand.php` | Гейт `app:inventory:stock-freshness-check` |

Документация: `ARCHITECTURE.md` — новая секция `InventoryFacade` с описанием DTO и
правила свежести.

## Наблюдаемое изменение поведения

Для компании с протухшим снапшотом остаток становится `0` вместо устаревшего числа.
Это промежуточное состояние: замена нуля на прочерк — Stage 3. Компании со свежими
данными не затронуты.

Мотивирующий случай PROD (`5d26f9aa`, последний снапшот 2026-05-23, активного
подключения нет) зафиксирован тестом `testProductionCaseIsStale`.

## Охват гейта = охват починки

Гейт проверяет только компании с **активным** подключением нужного маркетплейса.
`5d26f9aa` подключения не имеет, загрузка для неё не запускается, чинить нечего —
включение её в проверку сделало бы гейт вечно красным. Правило зафиксировано тестом
`testCompanyWithoutActiveConnectionIsNotChecked`.

Гейт строится на свежести, а не на накопленном числе исторических дыр. При провале
пишется один агрегированный `error` со счётчиками, а не запись на каждую пару.

**В cron не поставлен** — намеренно, по плану: сначала подтверждение, что он
достижимо зелёный на проде.

## Доказательство дефекта

Старый код не может выполнить новый тест: сигнатура `execute()` изменилась с
`array` на `StockOnDateResult`. Поэтому применён предписанный `CLAUDE.md` вариант
«новое поведение + старое условие» — тест
`StockQtyByListingOnDateFreshnessTest::testWithoutTheFreshnessBoundTheStaleRowIsReturned`
прогоняет тот же набор данных через тот же код с порогом, заведомо перекрывающим
возраст снапшота, и показывает, что протухшая позиция возвращается. Порог — то
единственное, что меняет исход; вхолостую тест пройти не может.

## Проверки

Baseline снят на `8f034153` до первой правки, был полностью зелёным — красного
baseline в этом Stage нет, чужих нарушений не унаследовано.

| Проверка | Baseline | После Stage |
|---|---|---|
| `php-cs-fixer --dry-run --using-cache=no` | `Found 0 of 2471`, exit 0 | `Found 0 of 2478`, exit 0 |
| `make site-cs-strict-types` | `Found 0 of 2471`, exit 0 | `Found 0 of 2478`, exit 0 |
| `make site-stan` (level 8) | `[OK] No errors`, 2456 файлов | `[OK] No errors`, 2463 файла |
| `composer test:unit` | 24/24 целевых | **2312 тестов, 0 падений** (4 deprecation — были и в baseline) |
| Integration `Inventory` | — | 95 тестов, 411 утверждений |
| Integration `MarketplaceAnalytics` | — | 8 тестов, 51 утверждение |
| Полный integration | — | **1238 тестов, 5734 утверждения**, exit 0 |

PHPStan-baseline **не рос**: все 8 найденных им ошибок исправлены в коде, ни одна не
записана в `phpstan-baseline.neon`.

## Новые тесты

| Файл | Тестов |
|---|---|
| `tests/Unit/Inventory/Domain/StockSnapshotFreshnessPolicyTest.php` | 9 — все ветки политики, включая границу порога и нулевой порог |
| `tests/Integration/Inventory/Infrastructure/Query/StockQtyByListingOnDateFreshnessTest.php` | 3 — регрессия на реальной БД + доказательство дефекта |
| `tests/Integration/Inventory/Command/StockFreshnessCheckCommandTest.php` | 6 — свежий / протухший / отсутствующий / будущий / без подключения |

Обновлены под новый контракт: `StockQtyByListingOnDateQueryTest` (+2 теста на
протухший и на свежий источник без листингов), `UnitExtendedQueryTest`,
`NormalizeInventorySnapshotActionTest`.

## Внешнее ревью

Ревьюер — Codex (`codex exec -s read-only --ephemeral`), два круга до `REVIEW_GREEN`.

**Ограничение ревьюера зафиксировано:** песочница внутри песочницы не поднимается,
поэтому дифф и контекст передавались через stdin, шелла у ревьюера не было. Факты,
которые он не мог добыть сам — структура таблицы `inventory_stock_snapshots`,
индексы, объёмы PROD, список потребителей Facade, расписание cron, результаты
проверок — были перечислены в промпте. Вердикт следует читать с этой поправкой:
он основан на тексте диффа и переданных фактах, а не на самостоятельной проверке базы.

**Круг 1 — IMPORTANT, принята и исправлена.**
`LatestStockSnapshotDateQuery` брал `MAX(snapshot_date)` без верхней границы, тогда
как `isStale()` ограничивает возраст только снизу. Снапшот с датой из будущего дал
бы зелёный гейт при отсутствии актуальных данных. Отдельно это было расхождением с
отчётным запросом, где граница `snapshot_date <= :reportDate` есть, — ровно тот
случай, когда две копии одного предиката разъезжаются.

Исправление: `AND s.snapshot_date <= :asOf`, команда передаёт `today`. Добавлен тест
`testFutureDatedSnapshotDoesNotCountAsFresh`, **проверен красным без исправления**:
гейт возвращал `SUCCESS` вместо `FAILURE`.

**Круг 2 — `REVIEW_GREEN`, одна MINOR, принята и исправлена.**
Сообщение `no stock snapshot at all` вводило в заблуждение при наличии только
будущего снапшота. Заменено на `no stock snapshot on or before <дата>`.

## Решения, принятые по ходу

**Тест команды сделан интеграционным, а не unit.** Первая версия мокала
`MarketplaceFacade` и `LatestStockSnapshotDateQuery`. Оба — `final readonly`, их
дубли дают PHPStan-ошибку `method.unresolvableReturnType`; в проекте такие случаи
лежат в baseline, а его разрешено только сокращать. Вместо роста baseline тест
переписан на реальный контейнер по образцу соседнего
`OzonInventoryDailySyncCommandTest`. Временная запись в allowlist
`tests/bootstrap.php` откачена — файл не изменён.

## Вне Stage

Прочерки вместо нулей, мёрж остатков в источники строк отчёта, постановка гейта в
cron. Поле `staleSources` в DTO потребителя пока не имеет — он появляется в Stage 3;
поле введено сейчас, чтобы не менять публичный контракт дважды.
