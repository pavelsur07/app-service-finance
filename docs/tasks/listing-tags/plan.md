# Теги листингов — план задачи

## Контекст

Нужны теги как аналитическое измерение: пометить листинги («Зима», «Распродажа», «Бренд X»)
и получать по ним разрез в расширенной юнит-экономике `/marketplace-analytics/unit-extended`.

Факты, установленные разведкой по коду (важны для решений ниже):

1. `UnitExtendedQuery` **не использует** `listing_daily_snapshots`. Он собирает данные в PHP из
   `MarketplaceFacade::getSalesAggregatesByListing / getReturnAggregatesByListing /
   getCostAggregatesByListing`, `MarketplaceAdsFacade`, `InventoryFacade` и фильтрует в памяти.
   Нерабочие снапшоты задачу не блокируют и в неё не входят.
2. В репозитории нет ни одного `ManyToMany` / `JoinTable` — модули связаны скалярными id.
   Но теги и листинги живут в **одном** модуле `Marketplace`, поэтому настоящие FK здесь законны.
3. UI Kit уже содержит паттерн Tags: `.tags-input`, `.tag-chip`, `.tag-chip .x`, `.suggest`,
   `.suggest-item`, `.suggest-create` в `site/ui-kit/patterns/tags.css`, подключён глобально через
   `assets/styles/app.css` → `ui-kit/patterns/all.css`. Новый UI Kit компонент не нужен,
   CSS писать не нужно, нужен только JS-поведенческий слой.
4. Страница реестра `templates/marketplace/listings/index.html.twig` — Tabler + vanilla JS
   с `fetch` (см. модалку привязки товара). Новый JS пишем в том же стиле, каркас страницы
   не переписываем.
5. `role_hierarchy`: `ROLE_COMPANY_USER ⊃ ROLE_USER`. Страница реестра и соседний
   `ListingMappingController` требуют `ROLE_USER` → новые endpoint'ы тоже `ROLE_USER`,
   иначе часть пользователей увидит UI и получит 403.

## Разбиение задачи

| Шаг | Содержание | Статус |
|---|---|---|
| **Stage 1** | Хранилище тегов + назначение/снятие в реестре `/marketplace/listings` | этот план |
| Stage 2 | `ListingTagFacade`, фильтр `tags[]` в API юнит-экономики, колонка + экспорт XLSX | план позже |
| Stage 3 | Группировка «свернуть по тегу» + решение по двойному счёту мультитегов | план позже |

Stage 1 самодостаточен и деплоится отдельно: он даёт возможность накопить теги до того, как
появится их потребитель в аналитике.

---

# Stage 1: теги листингов хранятся и назначаются из реестра

```yaml
risk: HIGH-LOCAL          # новые таблицы + миграция
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: <зафиксировать перед реализацией>
```

## Definition of Done

- [ ] Мигрированы две таблицы, `up()` защищён проверкой PostgreSQL, `down()` обратим.
- [ ] В реестре `/marketplace/listings` в каждой строке видны теги листинга.
- [ ] Работает массовое назначение и снятие тега по выбранным чекбоксами строкам.
- [ ] Тег создаётся inline из поля ввода («Создать "Зима"»); отдельной страницы справочника нет.
- [ ] Листинг чужой компании невозможно затегировать — фильтрация по `company_id` внутри SQL.
- [ ] Колонка тегов не даёт N+1: один запрос на страницу независимо от числа строк.
- [ ] Повторное назначение того же тега идемпотентно (без ошибки и без дублей).
- [ ] `make site-cs-check` чист, `make site-test-unit` и `make site-test` зелёные.
- [ ] `ARCHITECTURE.md` дополнен новой Entity.

## Модель данных

```sql
-- up()
CREATE TABLE marketplace_listing_tags (
    id         UUID NOT NULL,
    company_id UUID NOT NULL,
    name       VARCHAR(50) NOT NULL,
    slug       VARCHAR(50) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_listing_tag_company_slug
    ON marketplace_listing_tags (company_id, slug);

CREATE TABLE marketplace_listing_tag_assignments (
    listing_id UUID NOT NULL,
    tag_id     UUID NOT NULL,
    company_id UUID NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (listing_id, tag_id),
    CONSTRAINT fk_listing_tag_assign_listing FOREIGN KEY (listing_id)
        REFERENCES marketplace_listings (id) ON DELETE CASCADE,
    CONSTRAINT fk_listing_tag_assign_tag FOREIGN KEY (tag_id)
        REFERENCES marketplace_listing_tags (id) ON DELETE CASCADE
);
CREATE INDEX idx_listing_tag_assign_company_tag
    ON marketplace_listing_tag_assignments (company_id, tag_id);

-- down()
DROP TABLE marketplace_listing_tag_assignments;
DROP TABLE marketplace_listing_tags;
```

Решения и почему:

- **Две таблицы, не JSONB-колонка на листинге.** Переименование тега в JSONB — mass-update по всем
  листингам, а список тегов для autosuggest — полный скан. Здесь rename = один `UPDATE` строки.
- **Не полиморфная таблица `taggables` с `entity_type`.** Тегируем только листинги; когда придут
  теги на `Product`, обобщение стоит одну миграцию, а данных к тому моменту будет мало.
- **FK с `ON DELETE CASCADE`.** Обе таблицы в модуле `Marketplace`, кросс-модульного FK нет —
  правило не нарушено, а уборка при удалении листинга или тега достаётся бесплатно.
- **`company_id` в таблице связей денормализован.** Любой join и `DELETE` скоуплены по компании
  структурно, без обращения к `marketplace_listings`.
- **Таблица связей без ORM-маппинга, только DBAL.** Массовое назначение — один
  `INSERT … SELECT … ON CONFLICT DO NOTHING`; через Doctrine это N сущностей, `flush()` и возня
  с составным первичным ключом. `doctrine:schema:validate` в CI не запускается — проверено,
  дрейфа маппинга не будет.
- **`slug`** = `mb_strtolower(trim(name))`. Не даёт расползтись «Зима» / «зима» / « Зима » в три тега.

## Карта файлов

**Новые:**

```
migrations/Version20260722xxxxxx.php                                     миграция
src/Marketplace/Entity/MarketplaceListingTag.php                         ORM Entity
src/Marketplace/Repository/MarketplaceListingTagRepository.php           ORM repository
src/Marketplace/Infrastructure/Query/ListingTagAssignmentRepository.php  DBAL
src/Marketplace/DTO/ListingTagDTO.php                                    id + name
src/Marketplace/Application/AssignListingTagAction.php
src/Marketplace/Application/DetachListingTagAction.php
src/Marketplace/Controller/Api/ListingTagsListController.php
src/Marketplace/Controller/Api/ListingTagAssignController.php
src/Marketplace/Controller/Api/ListingTagDetachController.php
tests/Builders/Marketplace/MarketplaceListingTagBuilder.php
tests/Functional/Marketplace/Controller/ListingTagAssignControllerTest.php
tests/Functional/Marketplace/Controller/ListingTagDetachControllerTest.php
tests/Functional/Marketplace/Controller/MarketplaceListingsTagsColumnTest.php
tests/Unit/Marketplace/Entity/MarketplaceListingTagSlugTest.php
```

**Изменяемые:**

```
src/Marketplace/Controller/MarketplaceListingsController.php   передать tags_by_listing и all_tags
templates/marketplace/listings/index.html.twig                 колонка, чекбоксы, панель, JS
ARCHITECTURE.md                                                новая Entity
```

## Work items

### 1.1 — Миграция

`migrations/Version20260722xxxxxx.php`, по образцу `Version20260718090000`:

- `getDescription()`: `Add listing tags and listing-tag assignments`.
- `up()` начинается с `abortIf(!$platform instanceof PostgreSQLPlatform, …)`.
- SQL из раздела «Модель данных».
- `down()` — два `DROP TABLE` в обратном порядке. Обратимость честная: таблицы новые,
  `throwIrreversibleMigrationException` здесь не нужен.

Проверка: `make site-test-migrations` на тестовой БД, затем откат и повторный накат.

### 1.2 — Entity и репозиторий тега

`src/Marketplace/Entity/MarketplaceListingTag.php` — `class` (не `final`, Doctrine proxy):

```php
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_listing_tags')]
#[ORM\UniqueConstraint(name: 'uniq_listing_tag_company_slug', columns: ['company_id', 'slug'])]
class MarketplaceListingTag
{
    public function __construct(string $id, string $companyId, string $name);
    public function getId(): string;
    public function getCompanyId(): string;
    public function getName(): string;
    public function getSlug(): string;
    public function rename(string $name): void;   // Stage 1 не использует, но slug-логика одна
}
```

- `id` — `Uuid::uuid7()->toString()`, генерируется в Action (как в `Catalog`), в конструктор
  приходит готовым.
- `companyId` — `string`, `Assert::uuid()`, без сеттера.
- Нормализация в одном приватном методе: `$name = trim($name)`, `Assert::lengthBetween($name, 1, 50)`,
  `$this->slug = mb_strtolower($name)`. Вызывается из конструктора и `rename()`.
- `createdAt` — `DateTimeImmutable`.

`MarketplaceListingTagRepository` (`final class`), каждый метод принимает `string $companyId`:

```php
public function findBySlug(string $companyId, string $slug): ?MarketplaceListingTag;
public function findById(string $companyId, string $tagId): ?MarketplaceListingTag;
/** @return list<ListingTagDTO> — отсортировано по name */
public function listForCompany(string $companyId): array;
public function add(MarketplaceListingTag $tag): void;   // persist, без flush
```

### 1.3 — DBAL-репозиторий связей

`src/Marketplace/Infrastructure/Query/ListingTagAssignmentRepository.php` (`final readonly class`,
инжектится `Doctrine\DBAL\Connection`):

```php
/** @param list<string> $listingIds @return int число реально назначенных */
public function assign(string $companyId, array $listingIds, string $tagId): int;

/** @param list<string> $listingIds @return int число реально снятых */
public function detach(string $companyId, array $listingIds, string $tagId): int;

/** @param list<string> $listingIds @return array<string, list<ListingTagDTO>> ключ — listingId */
public function tagsForListings(string $companyId, array $listingIds): array;
```

`assign` — вся защита в SQL, отдельной проверки владения в PHP нет:

```sql
INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
SELECT l.id, :tagId, :companyId, NOW()
FROM marketplace_listings l
WHERE l.id IN (:listingIds) AND l.company_id = :companyId
ON CONFLICT DO NOTHING
```

Чужие листинги не проходят `WHERE`, повтор гасится `ON CONFLICT`. Возвращаем `rowCount()` —
он может быть меньше `count($listingIds)`, это нормально и отражается в ответе API.

`detach`:

```sql
DELETE FROM marketplace_listing_tag_assignments
WHERE company_id = :companyId AND tag_id = :tagId AND listing_id IN (:listingIds)
```

`tagsForListings` — один запрос, колонки перечислены явно:

```sql
SELECT a.listing_id, t.id, t.name
FROM marketplace_listing_tag_assignments a
JOIN marketplace_listing_tags t ON t.id = a.tag_id
WHERE a.company_id = :companyId AND a.listing_id IN (:listingIds)
ORDER BY t.name
```

Пустой `$listingIds` → сразу `return []`, без обращения к БД (`IN ()` в PG — синтаксическая ошибка).
Массивы передавать через `ArrayParameterType::STRING`.

### 1.4 — Actions

`src/Marketplace/Application/AssignListingTagAction.php` (`final class`, `__invoke`):

```php
public function __invoke(
    string $companyId,
    array $listingIds,
    ?string $tagId,
    ?string $tagName,
): AssignListingTagResult;   // {tagId, tagName, assigned: int}
```

Логика:
1. `$tagId !== null` → `findById(companyId, tagId)`, отсутствует → `ListingTagNotFoundException`.
2. Иначе `findOrCreate` по `mb_strtolower(trim($tagName))`: `findBySlug`, при промахе
   `new MarketplaceListingTag(Uuid::uuid7()->toString(), …)` + `add()` + `flush()`.
   Гонка двух параллельных запросов → ловим `UniqueConstraintViolationException`,
   перечитываем `findBySlug` и продолжаем.
3. `$assignments->assign($companyId, $listingIds, $tag->getId())`.
4. `flush()` — только здесь, не в репозитории.

`DetachListingTagAction` — `__invoke(string $companyId, array $listingIds, string $tagId): int`,
одна строка поверх `assignments->detach()`.

Исключение `src/Marketplace/Exception/ListingTagNotFoundException.php` — `final class`,
`extends \RuntimeException`.

### 1.5 — API

Три контроллера, один action на файл, `__invoke`, `#[Route]` через
`Symfony\Component\Routing\Attribute\Route`, `#[IsGranted('ROLE_USER')]`,
`getActiveCompany()` первой строкой. Формат ошибок — стандарт из `CLAUDE.md`
(`{"error": {"code": "...", "message": "..."}}`), а не плоский `{"error": "..."}`
из соседнего легаси-`ListingMappingController`.

**`GET /api/marketplace/listings/tags`** → `ListingTagsListController`

```json
{"items": [{"id": "uuid", "name": "Зима"}]}
```

**`POST /api/marketplace/listings/tags/assign`** → `ListingTagAssignController`

```json
// запрос — ровно одно из tagId / name
{"listingIds": ["uuid", "..."], "tagId": "uuid"}
{"listingIds": ["uuid", "..."], "name": "Зима"}
// ответ 200
{"tagId": "uuid", "tagName": "Зима", "assigned": 17}
```

**`POST /api/marketplace/listings/tags/detach`** → `ListingTagDetachController`

```json
{"listingIds": ["uuid"], "tagId": "uuid"}
{"detached": 3}
```

Валидация до обращения к БД, все нарушения → **422**:

| Условие | `code` |
|---|---|
| `listingIds` отсутствует, не массив или пуст | `listing_ids_required` |
| элемент `listingIds` не uuid | `listing_id_invalid` |
| `count(listingIds) > 500` | `listing_ids_limit_exceeded` |
| заданы оба `tagId` и `name`, либо ни одного | `tag_reference_required` |
| `tagId` не uuid | `tag_id_invalid` |
| `trim(name)` пуст или длиннее 50 | `tag_name_invalid` |

`ListingTagNotFoundException` → **404**, `code: tag_not_found`.

Лимит 500 — потолок массовой операции; страница реестра отдаёт максимум 25 строк, запас
на будущее «выделить всё по фильтру».

### 1.6 — UI реестра листингов

`MarketplaceListingsController::index()` дополняется двумя строками после получения `$pager`:

```php
$listingIds     = array_map(static fn ($l) => $l->getId(), iterator_to_array($pager));
$tagsByListing  = $this->assignments->tagsForListings($companyId, $listingIds);
$allTags        = $this->tagRepository->listForCompany($companyId);
```

Оба вызова — по одному запросу, независимо от числа строк.

`templates/marketplace/listings/index.html.twig`:

1. Колонка-чекбокс первой в `<thead>` (`<input type="checkbox" id="select-all">`) и в каждой строке
   `<input type="checkbox" class="row-select" value="{{ listing.id }}">`.
2. Колонка «Теги» перед действиями:

```twig
<td class="listing-tags" data-listing-id="{{ listing.id }}">
    {% for tag in tags_by_listing[listing.id]|default([]) %}
        <span class="tag-chip">{{ tag.name }}
            <button type="button" class="x" data-tag-id="{{ tag.id }}"
                    aria-label="Снять тег {{ tag.name }}">×</button>
        </span>
    {% endfor %}
</td>
```

3. Панель массового действия — скрыта, показывается при непустом выделении:

```twig
<div class="d-flex align-items-center gap-2 mb-3 d-none" id="bulk-tag-panel">
    <span class="text-muted small"><span id="bulk-count">0</span> выбрано</span>
    <div class="tags-input" id="bulk-tag-input" style="max-width:320px">
        <input type="text" placeholder="Тег…" autocomplete="off">
        <div class="suggest d-none"></div>
    </div>
</div>
```

4. JS в существующем `DOMContentLoaded`-блоке страницы, ~50 строк, без библиотек:
   - чекбоксы обновляют `#bulk-count` и видимость панели;
   - ввод в `.tags-input` фильтрует `all_tags` (данные отрендерены в `data`-атрибут, повторный
     `GET /tags` не нужен) и рисует `.suggest-item`; при отсутствии точного совпадения —
     `.suggest-create` с текстом «Создать "…"»;
   - выбор пункта → `POST …/assign` с `tagId` либо `name` → `location.reload()`;
   - клик по `.tag-chip .x` → `POST …/detach` с одним `listingId` → `location.reload()`;
   - ошибка ответа → `alert(data.error.message)`; перезагрузка страницы вместо точечного
     обновления DOM — сознательное упрощение, страница и так перезагружается после привязки товара.

Разметка тегов — на классах UI Kit из `ui-kit/patterns/tags.css`. Tabler-каркас страницы
не трогаем: её перевод на UI Kit — отдельная задача.

### 1.7 — Тесты

`tests/Unit/Marketplace/Entity/MarketplaceListingTagSlugTest.php`
- «Зима», « зима », «ЗИМА» дают один и тот же `slug`, `name` сохраняется как введён.
- Пустое имя и имя длиннее 50 → `InvalidArgumentException`.

`tests/Builders/Marketplace/MarketplaceListingTagBuilder.php` — по образцу
`MarketplaceListingBuilder`.

`tests/Functional/Marketplace/Controller/ListingTagAssignControllerTest.php`
- happy path: три листинга + новый тег по `name` → 200, `assigned: 3`, тег создан;
- назначение существующего тега по `tagId` → 200;
- повторный вызов тем же набором → 200, `assigned: 0`, дублей в БД нет;
- **IDOR**: листинг чужой компании в `listingIds` → 200, `assigned: 0`, строки в
  `marketplace_listing_tag_assignments` нет;
- 422: пустой `listingIds`, битый uuid, 501 элемент, оба `tagId` и `name`, пустой `name`;
- 404: несуществующий `tagId`.

`tests/Functional/Marketplace/Controller/ListingTagDetachControllerTest.php`
- снятие тега → 200, `detached: 1`, строки нет;
- снятие для чужой компании → 200, `detached: 0`, чужая строка на месте.

`tests/Functional/Marketplace/Controller/MarketplaceListingsTagsColumnTest.php`
- страница реестра рендерит `.tag-chip` с именем назначенного тега;
- листинг без тегов — пустая ячейка без ошибки.

## Stage checks

```
make site-cs-check
make site-test-unit
make site-test
```

Ручной smoke:
- назначить тег 25 листингам одним действием, снять один чип;
- в Symfony Profiler убедиться, что число запросов на странице реестра не выросло
  пропорционально числу строк (колонка тегов — один запрос).

## Reviewer focus

- IDOR: `company_id` внутри `INSERT … SELECT` и `DELETE`, а не проверкой в PHP.
- Батчевость: один INSERT на всё выделение, ни одного запроса в цикле.
- Идемпотентность `ON CONFLICT DO NOTHING` и корректный смысл `assigned`.
- Пустой `$listingIds` не доходит до SQL с `IN ()`.
- Отсутствие N+1 в колонке тегов реестра.
- Лимит 500 и вся валидация — до обращения к БД.
- `flush()` только в Action, не в репозиториях.
- Формат ошибок соответствует стандарту `{"error": {"code", "message"}}`.

## Риски

| Риск | Митигация |
|---|---|
| Гонка при `findOrCreate` двух одинаковых тегов | Уникальный индекс `(company_id, slug)` + перехват `UniqueConstraintViolationException` с перечитыванием |
| Таблица связей без ORM-маппинга разойдётся с Doctrine | `doctrine:schema:validate` в CI не запускается; таблица создаётся и меняется только миграциями |
| Массовое назначение по 500 id медленное | Один INSERT, индекс `(company_id, tag_id)`; при росте — переход на «выделить всё по фильтру» серверным запросом |
| Смешанный UI (Tabler-страница + UI Kit чипы) | Осознанно: перевод страницы на UI Kit — отдельная задача |

## Обновление документации

- `ARCHITECTURE.md` → раздел Entity: `MarketplaceListingTag` (поля, уникальность, назначение).
- Facade **не добавляется**: в Stage 1 внешних потребителей нет, `ListingTagFacade` появится
  в Stage 2 вместе с `MarketplaceAnalytics`.

## Сознательно не входит в Stage 1

Фильтр по тегу в самом реестре листингов; страница управления тегами (rename / merge / delete);
цвета тегов; группы и иерархия тегов; наследование тегов от `Product`; теги в снапшотах;
лимит числа тегов на листинг; теги в XLS-импорте товаров.
