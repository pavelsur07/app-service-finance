# Маппинг товаров МойСклад — Implementation Plan

> **Для исполнителя:** использовать навык `executing-plans`; выполнять Work items
> последовательно, начиная с тестов. Backend и UI — разные Stage. Этот документ
> описывает будущую реализацию; текущая задача заканчивается планом и его ревью.

**Goal:** вручную и безопасно сопоставлять листинги Marketplace с импортированной
номенклатурой MoySklad, сохраняя историю и границы контекстов.

**Architecture:** связи и история принадлежат MoySklad. Marketplace отдаёт
постраничные DTO через свой существующий Facade. Выбор цели использует только
локальный каталог MoySklad; HTTP, финансовые пересчёты и внешняя запись отсутствуют.

**Tech Stack:** существующие Symfony 7.4, Doctrine ORM/DBAL, PostgreSQL,
PHPUnit, Twig/Tabler, Pagerfanta. Новых зависимостей нет.

**Spec:** [TASK.md](TASK.md), запрос владельца в чате.
**Проверенная база:** `4a2583829bf5997317a75f4e4e08b8614ad5d4f1`.
**Класс реализации:** Large; четыре Stage, одна ветка и один Draft PR.
**Класс текущего PR:** документация, Fast Path; внешнее ревью явно заказано.
**Baseline документации:** `git diff --check` на чистой базе — PASS.
Baseline приложения не запускался: код не меняется. Перед реализацией выполнить
команды раздела «Проверки», записать фактический вывод и новую базу.

## Global Constraints

- «Важно не смешивать контексты».
- Две отдельные вкладки «Маппинг Ozon» и «Маппинг Wildberries» в легаси.
- UI и его код — site/templates/moy_sklad, Twig/Tabler, существующие легаси-стили;
  не переносить в apps/account, React или новый shell.
- «Always переда началом разрабатываем технический план и чек-лист приемки».
- «Always начинать разработку с тестов».
- «Один комит одна задача».
- Права — существующие MARKETPLACE_READ/WRITE; новую систему ролей не вводить.
- Нет изменений Catalog, Inventory, Finance, продаж, возвратов и costPrice.
- Нет автосопоставления, автосоздания номенклатуры, штрихкодов, комплектов,
  коэффициентов единиц, фоновых задач и расписания.
- Каждый запрос ограничен companyId; companyId и actorUserId берутся на сервере.
- Статус «сопоставлен с Catalog» не равен «сопоставлен с МойСклад».

## Review Focus

1. Одинаковый nm_id/артикул у нескольких размеров: связь выбирается по listingId,
   варианты не схлопываются. Тесты 1.1 и 3.1.
2. Две формы с одной исходной ревизией: второй отличающийся запрос получает
   конфликт, история первого сохраняется. Тесты 3.1.
3. Архивирование/изменение номенклатуры между открытием формы и POST: сервер
   повторно проверяет цель; сохранённая история не переписывается. Тесты 3.1 и 4.1.
4. Чужой UUID цели при правильном companyId листинга: не раскрывать существование
   чужого объекта, не создавать межаккаунтную связь. Тесты 2.1, 3.1, 4.1.
5. Большой каталог, спецсимволы поиска, пустые/неактивные листинги: ограниченные
   страницы и batch-обогащение; история доступна без внешнего API. Тесты 1.1, 3.2, 4.1.

## 0. Что уже существует и что переиспользовать

| Файл | Факт / применение |
|---|---|
| `site/src/Marketplace/Entity/MarketplaceListing.php` | UUID листинга, marketplaceSku, marketplaceVariantId, supplierSku, size, isActive; product — чужой для этой задачи Catalog |
| `site/src/Marketplace/Facade/MarketplaceFacade.php` | getListingsMetaByIds(companyId, listingIds) уже возвращает batch DTO; расширить существующий Facade |
| `site/src/Marketplace/DTO/ListingMetaDTO.php` | id/title/sku/marketplace/supplierSku/size; добавить только недостающие isActive и marketplaceVariantId |
| `site/src/Marketplace/Infrastructure/Query/ListingMetaQuery.php` | scoped batch-чтение; расширить SELECT для новых полей DTO |
| `site/src/Marketplace/Infrastructure/Query/MarketplaceListingsQuery.php` | стабильная пагинация createdAt DESC, id DESC и экранированный поиск; использовать без изменения старой страницы |
| `site/src/Marketplace/Application/DTO/ListingsFilter.php` | внутренний фильтр Marketplace; не импортировать в MoySklad |
| `site/src/MoySklad/Entity/MoySkladProduct.php`, `MoySkladVariant.php` | внешний UUID, companyId/connectionId, article/code, archived; у варианта parent и characteristics |
| `site/src/MoySklad/Infrastructure/Repository/MoySklad*Repository.php` | существующие tenant-scoped lookup |
| `site/src/MoySklad/Infrastructure/Doctrine/MoySkladSchemaExtension.php` | составные FK для Doctrine schema diff; расширить вместе с миграцией |
| `site/src/MoySklad/Infrastructure/Query/MoySkladSyncStatusQuery.php` | статусы product/variant и успешные проходы; не вводить второй журнал синхронизации |
| `site/src/MoySklad/Controller/MoySkladConnectionFormController.php` | пример прав, active company, CSRF, optimistic version и HTML-ошибок |
| `site/templates/moy_sklad/layout.html.twig` | существующий shell; новая страница наследуется от него |

`findListingById()` возвращает урезанный ActiveListingDTO; поиск по одному
supplierSku в MarketplaceListingLinkingFacade не доказывает уникальность размера.
Не строить новый маппинг поверх этих сокращённых представлений. Для проверки
конкретного UUID переиспользовать getListingsMetaByIds с одним ID.

## 1. Решения и альтернативы

Рекомендуемый подход — явная связь внутри MoySklad, подтверждаемая вручную.
Использование MarketplaceListing.product смешивает Catalog и ERP; автоматическая
запись по артикулу неоднозначна при размерах и дублях. Эти варианты исключены.

### 1.1. Идентичность и допустимость

- Ключ: companyId + connectionId МС + listingId. Внешний SKU не уникален сам
  по себе и не служит FK. Seller connection в этой модели листинга отсутствует.
- Цель: product с variantsCount = 0 и без импортированных дочерних вариантов,
  либо конкретный variant с существующим неархивным родителем.
- Архивная цель не предлагается. Связь на архивную цель остаётся в истории
  и текущем состоянии как «Требует проверки», не заменяется другой целью.
- Отсутствующий в частичной загрузке объект не считается удалённым.
- Неактивные листинги доступны с явной отметкой для работы с историей.
- Назначение/замена: активное подключение, accountId привязан, есть успешные
  полные проходы product и variant. Не требовать свежего успешного HTTP-check
  для каждой локальной операции. Показывать даты последнего успешного импорта.
- Снятие связи разрешено при выключенном подключении. Не стирать историю
  при отключении, замене токена, архивировании или изменении кода номенклатуры.
- Не обещать разрешение связи «на дату продажи»: ревизия описывает время
  изменения настройки. Будущий документ фиксирует ревизию и цель снимком.

### 1.2. История в одной таблице

Создать `moysklad_listing_mapping_revisions`: каждая принятая перемена —
новая запись. Текущее состояние — максимальная revision для ключа связи.
Операция clear — запись без цели, а не удаление прошлой строки. Отдельной
таблицы current и синхронизации current/history нет.

| Поле | Тип и правило |
|---|---|
| id | UUID v7, первичный ключ |
| company_id, connection_id, listing_id | UUID, immutable, NOT NULL |
| revision | int > 0, последовательность внутри ключа связи |
| operation | set или clear |
| product_external_id / variant_external_id | nullable UUID; set — ровно один, clear — оба NULL |
| request_id | UUID, идентификатор POST для дедупликации |
| actor_user_id | UUID, scalar из текущего пользователя |
| created_at | UTC timestamp с миллисекундами, ClockInterface |
| listing_snapshot | нормализованные id, marketplace, sku, supplierSku, size, variantId, title |
| target_snapshot | для set: type, externalId, parentExternalId, name, code, article, characteristics; для clear NULL |

Снимки создаются сервером из проверенных DTO/каталога. Секретов, сырых API-ответов
и произвольного JSON из формы нет. По snapshot можно прочитать прошлую связь
после переименования цели. Метаданные снимка не используются для новой валидации.

Ограничения и индексы:

- UNIQUE(company_id, connection_id, listing_id, revision).
- UNIQUE(company_id, request_id); reuse requestId с другим payload — конфликт.
- FK(company_id, connection_id) → moysklad_connections(company_id, id), RESTRICT.
- Составные FK на product и variant по company/connection/externalId, RESTRICT;
  тот же приём, что у MoySkladStockSnapshotLine.
- CHECK operation/revision/ровно одной цели/наличия target_snapshot.
- Индекс (company_id, connection_id, listing_id, revision DESC) для текущего
  состояния и истории; не дублировать его автоматически идентичным UNIQUE.
- Частичные индексы по составным FK целей для строк с ненулевой целью.
- listingId и actorUserId — scalar без ORM-связей и без FK в чужой модуль.
  При записи listingId валидируется через Facade. Отсутствующий позже листинг
  не должен ронять чтение истории: использовать snapshot и статус «Листинг недоступен».
- Все новые FK/CHECK/индексы согласовать с MoySkladSchemaExtension; schema diff
  после миграции не должен предлагать их удалить. FK RESTRICT согласуется с
  существующим запретом удалять подключение с данными.

Приложение не предоставляет update/delete для ревизий; поля immutable, repository
только append. PostgreSQL write-права и универсальный аудит не расширяются.
Миграция создаёт одну пустую таблицу, без backfill и изменения чужих таблиц.
Откат разрешён только при пустой новой таблице; при данных — forward fix.
Конкретный номер Version выбрать перед реализацией, проверив свободное имя.

### 1.3. Конкуренция и идемпотентность

Action владеет одной короткой транзакцией на соединении EntityManager:

1. Tenant-scoped lookup подключения; заблокировать его FOR SHARE, затем
   взять pg_advisory_xact_lock для companyId + connectionId + listingId.
   Единый порядок: connection → mapping key → catalog parent → catalog variant.
2. Найти принятый requestId в своей компании. Совпали actor, ключ связи,
   operation, target и expectedRevision — вернуть прежний результат без записи;
   отличаются — idempotency_conflict. Права проверены контроллером до Action.
3. Считать свежую последнюю ревизию под блокировкой; отсутствие равно 0.
   Получить listing DTO через getListingsMetaByIds. Для set отсутствие —
   not_found. Для clear при отсутствии DTO разрешён только ключ со своей
   существующей ревизией; взять listing_snapshot из неё. Никогда не принимать
   снимок листинга из формы. Новую связь для отсутствующего листинга не создавать.
4. expectedRevision не совпала — stale_mapping, HTTP 409. Не применять
   last-write-wins. Если цель уже равна выбранной при совпавшей ревизии —
   вернуть текущее состояние без новой записи; clear пустой связи — такой же no-op.
5. Для set перечитать и заблокировать каталог. При цели product брать его
   FOR UPDATE, затем проверять variantsCount и отсутствие дочерних variant:
   этот lock конфликтует с FK KEY SHARE при конкурентной вставке варианта.
   FOR SHARE для такой проверки недостаточен. При цели variant брать
   parent FOR SHARE, затем variant FOR SHARE. Повторно проверить архив, тип,
   родителя и готовность подключения/каталога. Изменившаяся после GET цель
   отклоняется. Последующий импорт после commit может сделать связь
   недоступной; статус чтения тогда становится «Требует проверки».
6. Добавить revision + 1 с серверными снимками, flush и commit. HTTP и
   Messenger отсутствуют. Исключение откатывает и связь, и историю.

Нормализованное тело запроса включает expectedRevision. Для состоявшихся
изменений дедупликация проверяется до CAS, чтобы повтор старого принятого POST
после следующей перемены не отменял её. No-op не сохраняет requestId:
он не создаёт ревизий; повтор с устаревшим expectedRevision получает 409.
Не пытаться продолжать UnitOfWork после rollback/закрытия EntityManager.

Advisory-lock строить как в SyncCatalogAction: два hashtext-ключа, namespace
«moysklad-listing-mapping» и строка companyId:connectionId:listingId. Namespace
отличается от «moysklad-catalog». Конкурирующий повтор requestId на другом
listingId/connectionId защищён UNIQUE(company_id, request_id), а не этим lock:
перехватить UniqueConstraintViolationException именно этого ограничения после
rollback и вернуть idempotency_conflict (409). Остальные unique violations не
маскировать. Закрытый EntityManager не переиспользовать, повторного append нет.

### 1.4. Подсказки и чтение

- Подсказка только при точном равенстве trim(supplierSku) и trim(article)
  позиции МС, с сохранением регистра и ведущих нулей. Ни имени, ни внешнего
  marketplaceSku в этом правиле нет. Пустой артикул подсказок не даёт.
- Сравнение варианта — по его собственному article; не наследовать артикул
  родителя и не угадывать размер по строке. Несколько совпадений остаются списком.
- Подсказки исключают недопустимые цели и ограничены 20 строками с totalItems.
  Это результаты поиска, не сохранённые связи. Кнопки массового подтверждения нет.
- Реестр: 25 листингов на страницу, включая неактивные, отдельно для Ozon и
  Wildberries. Marketplace фиксирован вкладкой; фильтр q по существующим полям.
  Текущие связи читаются одним batch
  на 25 listingId и обогащаются локальными целями без N+1.
- Фильтра «Все несопоставленные» и глобального процента покрытия в V1 нет:
  он потребовал бы общей выборки двух контекстов или отдельной read model.
  Признак маппинга показывается у каждой строки текущей страницы.
- Поиск цели по name/code/article — локальный, 20 строк на страницу,
  стабильная сортировка name/type/externalId. q: trim, до 100 символов,
  LIKE спецсимволы экранируются; q короче 2 символов не запускает широкий поиск.
- Статусы текущей строки вычисляются по живым данным: «Не сопоставлен»,
  «Сопоставлен», «Требует проверки»; отдельно причина недоступности подключения.
  Сам revision не меняется от импорта каталога.

## 2. Файлы и интерфейсы будущей реализации

Новые пути ниже — планируемые, существующие — проверены на указанной базе.
Все DTO final readonly, Entity — без final, прочие классы — по CLAUDE.md.

**Marketplace — расширить существующие:**

- `Facade/MarketplaceFacade.php`: добавить только постраничное read API.
- `DTO/ListingMetaDTO.php`, `Infrastructure/Query/ListingMetaQuery.php`:
  isActive и marketplaceVariantId; обновить всех конструкторов DTO.
- `Infrastructure/Query/MarketplaceListingsQuery.php`: переиспользовать
  существующую пагинацию и фильтры, не менять поведение старых потребителей.
- Создать `DTO/ListingMetaPageDTO.php`: items list<ListingMetaDTO>,
  page, perPage, totalItems. DTO не содержит ORM/Pagerfanta/QueryBuilder.

```php
// Новое в существующем MarketplaceFacade:
public function paginateListingsForExternalMapping(
    string $companyId, ?string $marketplace, ?string $search,
    int $page = 1, int $perPage = 25,
): ListingMetaPageDTO;

// Уже существует; для записи достаточно одного ID:
public function getListingsMetaByIds(string $companyId, array $listingIds): array;
```

Пагинация Facade принимает null или значение MarketplaceType на базе
(wildberries, ozon, yandex_market, sber_megamarket); значения брать из enum.
Маппинг V1 вызывает её только с ozon/wildberries согласно выбранной вкладке.
Неизвестный marketplace или
perPage вне 1..100 отклоняется до запроса; page >= 1. Страница за концом —
пустые items с сохранённым totalItems. Поиск ограничен 100 символами.
Не менять контракт MarketplaceListingsQuery: он сейчас бросает Pagerfanta
OutOfRangeCurrentPageException. Новый Facade читает totalItems и перехватывает
это исключение только для своего API. Из pager брать упорядоченные listingId,
затем batch ListingMetaQuery::findByIds; порядок восстановить по ID страницы.
Так нормализация size UNKNOWN → null и новые поля DTO совпадают у обоих методов.
Новые поля ListingMetaDTO добавить в конец с совместимыми default,
в ListingMetaQuery заполнять их из БД. Проверить остальные места new ListingMetaDTO.

**MoySklad — создать под `site/src/MoySklad/`:**

| Путь | Ответственность |
|---|---|
| `Entity/MoySkladListingMappingRevision.php` | неизменяемая принятая перемена |
| `Enum/ListingMappingOperation.php` | set/clear |
| `Application/Command/ChangeListingMappingCommand.php` | companyId, actorUserId, connectionId, listingId, operation, targetType?, targetExternalId?, expectedRevision, requestId |
| `Application/Action/ChangeListingMappingAction.php` | валидация, транзакция, CAS, append |
| `Application/DTO/ListingMappingState.php` | revision, revisionId?, target?, status, reason? |
| `Application/DTO/CatalogTargetDTO.php` | тип, UUID, родитель, name/code/article/characteristics, selectable, reason? |
| `Application/DTO/CatalogTargetPage.php` | items list<CatalogTargetDTO>, page, perPage=20, totalItems |
| `Application/DTO/MappingRevisionDTO.php` | id, revision, operation, actorUserId, createdAt, listingSnapshot, targetSnapshot |
| `Application/DTO/MappingHistoryPage.php` | items list<MappingRevisionDTO>, page, perPage=20, totalItems |
| `Infrastructure/Repository/MoySkladListingMappingRepository.php` | latest, acceptedRequest, append |
| `Infrastructure/Query/MoySkladListingMappingsQuery.php` | batch current, постраничная история; только свои таблицы |
| `Infrastructure/Query/MoySkladCatalogTargetsQuery.php` | локальный поиск/подсказки; только свои таблицы |
| `Exception/ListingMappingException.php` | безопасные reason/message, без данных чужого tenant |
| `Controller/MoySkladListingMappingsController.php` | GET реестра |
| `Controller/MoySkladMappingConnectionsController.php` | вход во вкладку без выбранного подключения |
| `Controller/MoySkladListingMappingFormController.php` | GET/POST назначения/замены/снятия и GET истории |
| `Form/MoySkladListingMappingType.php` | ChoiceType target, operation, expectedRevision/requestId; csrf_protection=false, ручной CSRF до handleRequest |

Дополнить существующие MoySkladProductRepository/MoySkladVariantRepository
методом scoped lookup с блокировкой, если его не хватает. Не писать второй
API-клиент или универсальный registry. MoySkladFacade для будущего отчёта сейчас
не создавать: внутри модуля достаточно Action/Query, внешнего потребителя пока нет.

```php
public function __invoke(ChangeListingMappingCommand $command): ListingMappingState;

// MoySkladListingMappingsQuery:
public function currentForListings(
    string $companyId, string $connectionId, array $listingIds,
): array; // array<string, ListingMappingState>, максимум 100 ID, отсутствующие — revision=0

public function history(
    string $companyId, string $connectionId, string $listingId, int $page = 1,
): MappingHistoryPage; // 20 записей, revision DESC

// MoySkladCatalogTargetsQuery:
public function search(
    string $companyId, string $connectionId, string $q, int $page = 1,
): CatalogTargetPage;

public function suggest(
    string $companyId, string $connectionId, ?string $supplierSku,
): CatalogTargetPage;
```

**Прочие изменения:** одна новая `site/migrations/Version<свободный timestamp>.php`,
MoySkladSchemaExtension, `ARCHITECTURE.md` только при появлении реального кода;
`site/templates/moy_sklad/mappings/{index,form,history}.html.twig`,
а также `site/templates/moy_sklad/mappings/connections.html.twig` для выбора подключения;
вкладка/ссылка в moy_sklad/layout и на карточке подключения.
Новых конфигов модулей, ролей, Messenger routing, JS entry и зависимостей нет.

## Stage 1: DTO-контракт чтения листингов без привязки к ERP

**Risk:** MEDIUM. **stage_base_commit:** `e0b5ac0d276ccb58d38c069722a2fad90358edda`.
**Definition of Done:** внешний модуль может получить страницу всех листингов
и batch-метаданные с размером/variantId/activity, не получая Entity;
существующие consumers и каталог Marketplace работают как раньше.

- [ ] **1.1 RED:** в `site/tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php`
  добавить tests: twoWbSizesRemainDistinct, includesInactiveListings,
  isolatesCompanyAndBatchIds, stablePaginationWithEqualCreatedAt,
  filtersMarketplaceAndLiteralSearchCharacters, rejectsInvalidPagination.
  Данные: один nm_id, размеры S/M, два listingId; два tenant с одинаковым SKU;
  поиск «001%_» — буквальный, ведущие нули не теряются.
  Выполнить точечный PHPUnit, подтвердить FAIL нового поведения.
- [ ] **1.2 GREEN:** добавить ListingMetaPageDTO, расширить ListingMetaDTO/Query,
  реализовать новый метод существующего MarketplaceFacade через существующий
  MarketplaceListingsQuery с внутренним ListingsFilter(mapped: null).
  Из pager брать ID, batch-читать DTO через ListingMetaQuery и сохранять порядок.
  Перехватить out-of-range только в новом API Facade. Не экспортировать
  внутренний фильтр или QueryBuilder. Запустить тесты 1.1, включая одинаковое
  представление size UNKNOWN в batch/page и пустую страницу за концом.
- [ ] **1.3 REVIEW:** проверить всех new ListingMetaDTO, targeted PHPStan/CS,
  добавить контракт в ARCHITECTURE.md. Review полного Stage diff, Stage Report,
  commit/push/Draft PR; продолжить Stage 2.

**Stage checks:** FacadeTest + MarketplaceListingsQueryTest / ближайшие существующие
тесты пагинации; focused PHPStan и CS. Не создавать новую копию поисковой логики.
**Reviewer focus:** семантика «mapped» старого Catalog не просочилась в ERP.

## Stage 2: хранение ревизий и tenant-инварианты

**Risk:** HIGH-LOCAL — миграция. **stage_base_commit:** `bd3b59bf4`.
**Definition of Done:** схема хранит set/clear, историю и requestId, запрещает
смешение компаний и подключений; нет backfill и изменений чужих таблиц.

- [ ] **2.1 RED:** создать
  `site/tests/Unit/MoySklad/Entity/MoySkladListingMappingRevisionTest.php` и
  `site/tests/Integration/MoySklad/ListingMappingStorageTest.php`;
  builders — `site/tests/Builders/MoySklad/MoySkladListingMappingRevisionBuilder.php`.
  Добавить отсутствующие `site/tests/Builders/MoySklad/MoySkladProductBuilder.php`
  и `site/tests/Builders/MoySklad/MoySkladVariantBuilder.php` с явными
  companyId/connectionId/parent и согласованными внешними UUID; не дублировать
  уже существующие ConnectionBuilder и фабрики snapshot.
  Проверить round-trip product/variant/clear, снимки до/после переименования,
  UUID/revision/operation, две цели/нет цели при set, цель при clear,
  duplicate revision/requestId, чужие company/connection/target и rollback
  целой записи при constraint violation. Проверки CHECK/FK выполнить также
  прямой DBAL-вставкой, чтобы тест не ограничивался PHP-конструктором.
  Запустить новые тесты и зафиксировать RED.
- [ ] **2.2 GREEN:** Entity/Enum, repository latest/acceptedRequest/append,
  builder, одна миграция и SchemaExtension. Получить GREEN 2.1.
  На пустой test БД проверить migration up/down/up; rollback с данными
  должен безопасно отказываться. Проверить Doctrine schema diff без
  генерации второй миграции с удалением составных ограничений.
- [ ] **2.3 REVIEW:** module unit+integration, focused gates, ARCHITECTURE.md,
  Stage Report. Внешнее ревью Stage — только если HIGH-LOCAL diff >500 строк
  согласно AGENTS.md; commit/push/обновить тот же Draft PR, продолжить Stage 3.

**Reviewer focus:** NULL-семантика CHECK, составные FK, индексы, отсутствие
уникальности цели (many-to-one разрешён), небезопасный down и schema drift.

## Stage 3: назначение, снятие, конкуренция, read model

**Risk:** HIGH-LOCAL — tenant validation и конкуренция.
**stage_base_commit:** записать перед 3.1.
**Definition of Done:** Action реализует раздел 1.3; чтение и подсказки bounded,
каталог не изменяется, архивирование не стирает ревизии.

- [ ] **3.1 RED:** создать
  `site/tests/Integration/MoySklad/ChangeListingMappingActionTest.php`.
  Сценарии: set product/variant, replace, clear, clear выключенного подключения,
  many-to-one, два подключения, неактивный листинг, отсутствующий/чужой листинг,
  чужая цель/родитель, parent с variantsCount>0, parent с реально существующими
  вариантами при устаревшем count=0, архивный variant/parent, нет успешного
  импорта, transient check error после успешного импорта, token replacement.
  Два запроса с expectedRevision=0: только один set, второй 409.
  Отдельный `site/tests/Integration/MoySklad/ListingMappingConcurrencyTest.php`
  наследуется от существующего PostgresResetTestCase: два реальных DBAL
  connection без DAMA wrapping transaction, teardown через его DbReset.
  Проверить гонку первого создания, повтор requestId на одном ключе и
  одинаковый requestId на разных ключах (одна запись и один 409, не 500);
  не ограничиваться последовательной имитацией. Архивирование/изменение
  родителя конкурирует с сохранением.
  Отдельно при variantsCount=0 проверить реальную гонку set product и INSERT
  дочернего variant: под FOR UPDATE вставка ждёт commit назначения; если
  вставка завершилась первой, назначение отклоняется при проверке дочерних строк.
  После вставки, прошедшей уже после commit назначения, current показывает
  «Требует проверки», история назначения сохраняется.
  Повтор принятого requestId после replace возвращает прежнюю ревизию и не
  меняет current; тот же requestId с другим payload — conflict.
  set той же цели и clear revision=0 — без новых ревизий; stale CAS не обойти no-op.
  SQL-снимком до/после доказать, что MarketplaceListing.product, costPrice,
  остатки и сам каталог не изменились. Запустить, подтвердить RED.
- [ ] **3.2 RED:** создать
  `site/tests/Integration/MoySklad/ListingMappingsQueryTest.php` и
  `site/tests/Integration/MoySklad/CatalogTargetsQueryTest.php`.
  current latest/clear, архивная цель «Требует проверки», 0/100/101 ID,
  история 20+1 с totalItems/perPage, недоступный листинг и snapshot fallback;
  history/clear существующей своей связи работают без живого listing DTO;
  set отсутствующего листинга и clear неизвестного ключа — not_found;
  одинаковый externalId
  у разных подключений, bounded SQL count для страницы из 1 и 25 строк;
  exact article/дубли/размеры/«001» против «1»/регистр/пробелы/пустой артикул,
  q[]=, q короче 2, literal %/_/обратный слеш, page за концом.
  Статус product, у которого позже появились варианты, требует проверки.
- [ ] **3.3 GREEN:** реализовать Command/DTO/Exception, Action и Queries из
  раздела 2. Scoped locks читать свежими DBAL-запросами; не использовать
  устаревшие ORM-объекты как результат повторной проверки.
  На успешном set создавать снимки из свежих данных. Queries не делают
  flush/commit и не обращаются к таблицам Marketplace; листинги — через Facade.
  Статусы импорта брать из существующих cursor/run, без дополнительного журнала.
  Запустить тесты 3.1–3.2, модульные наборы и focused gates.
- [ ] **3.4 REVIEW:** отдельно проверить lock order против SyncCatalogAction,
  no-op/replay после rollback, безопасные reason: not_found, catalog_not_ready,
  target_unavailable, stale_mapping, idempotency_conflict.
  Обновить ARCHITECTURE.md/Stage Report, выполнить положенное Stage review,
  commit/push/тот же Draft PR; продолжить Stage 4.

**Reviewer focus:** загруженный каталог не является атомарным снимком API:
локальная проверка гарантирует согласованность на момент транзакции,
следующий импорт может изменить допустимость — это отображается статусом,
а не автоматическим remap. requestId не заменяет CAS.

## Stage 4: UI в существующем модуле МойСклад

**Risk:** MEDIUM. **stage_base_commit:** записать перед 4.1.
**Definition of Done:** пользователь выбирает листинг/цель, понимает размер и
подключение, подтверждает замену/снятие, видит историю и ошибки.

Маршруты (все в текущем session/Twig интерфейсе, не public API):

| Метод/путь | Назначение |
|---|---|
| GET /moy-sklad/mappings/{marketplace} | вход во вкладку без connectionId: только ozon/wildberries |
| GET /moy-sklad/connections/{connectionId}/mappings/ozon | вкладка «Маппинг Ozon» |
| GET /moy-sklad/connections/{connectionId}/mappings/wildberries | вкладка «Маппинг Wildberries» |
| GET /moy-sklad/connections/{connectionId}/mappings/{marketplace}/{listingId}/edit | поиск цели и форма |
| POST тот же /edit | operation=set/clear, target?, expectedRevision, requestId, _token |
| GET /moy-sklad/connections/{connectionId}/mappings/{marketplace}/{listingId}/history | история, 20 строк |

Две вкладки рендерят общий легаси-шаблон с фиксированным marketplace, без
копирования бизнес-логики. В edit/history marketplace допускает только
ozon|wildberries; после tenant lookup DTO проверять принадлежность листинга
вкладке на GET и POST, иначе 404. Значение из POST не может сменить вкладку.
Переключение вкладки сохраняет connectionId и сбрасывает q/page.

В общем moy_sklad/layout всегда видны три вкладки через существующий
partials/_tabs.html.twig: «Подключения», «Маппинг Ozon», «Маппинг Wildberries».
Без mappingConnectionId ссылки идут на входные маршруты без подключения.
На входе использовать существующий MoySkladConnectionsQuery с пагинацией 20:
0 подключений — пустое состояние и ссылка на создание; ровно 1 — HTTP 302
на его реестр; больше 1 — легаси-страница выбора подключения.
Во всех местах, где выбранное подключение уже известно, передавать scalar
mappingConnectionId в layout; обе вкладки сохраняют его. В остальных местах
использовать default(null), чтобы не ломать Twig strict_variables.
Имена всех новых routes начинаются с moysklad_ для подсветки существующего
сайдбара. Sidebar не переделывать, вход «Интеграция» ведёт на общие вкладки.

Для history и clear при отсутствующем живом листинге сначала найти последнюю
ревизию по своей компании/подключению/listingId. Её серверный listing_snapshot
задаёт marketplace и подпись; проверить соответствие вкладке и показать
«Листинг недоступен». Чужой/неизвестный ключ без своей истории — 404.
GET edit в таком состоянии показывает только снятие связи и историю,
назначение/замена запрещены. Реестр существующих листингов не обязан показывать
удалённые строки; прямые ссылки history/edit по известному ключу остаются рабочими.

Company и actor из текущей сессии, UUID requirements на routes. READ для
GET, WRITE для POST, CSRF привязан к connectionId/listingId. Foreign/unknown
UUID — одинаковый 404; invalid CSRF/нет WRITE — 403; validation — 422;
stale/reused request — 409 с предложением обновить состояние; успех — 303.
Форма конфликта не должна подставлять свежую revision и молча повторять POST.

Контракт HTML-формы:

- Основная форма: operation=set, ChoiceType target со значениями
  product:<uuid> или variant:<uuid>, expectedRevision >= 0, requestId UUID,
  _token. После серверного разбора target Command получает отдельные
  targetType/targetExternalId. Некорректный префикс/UUID — 422.
- Choices = точные подсказки UNION текущая страница поиска UNION текущая цель;
  dedup по type:externalId. POST восстанавливает их из своих локальных scoped
  запросов по скалярным q/page; при q короче 2 остаются подсказки/текущая цель.
  Нельзя принимать произвольные choices из браузера. Action повторно проверяет
  допустимость под блокировкой независимо от успешной валидации ChoiceType.
- Для clear отдельная форма с operation=clear, expectedRevision, requestId,
  _token, без target; Form получает option operation=clear и не требует цель.
  Кнопка открывает существующий confirm partial, сам POST работает без JS.
- У Symfony Form csrf_protection=false; контроллер вручную проверяет _token
  до handleRequest по ID «moysklad_listing_mapping» + connectionId + listingId.
  Неверный токен даёт именно 403, без изменения состояния. Не использовать
  стандартную Form CSRF-ошибку как 422.
- Для operation=set порядок до handleRequest: scalar/UUID-разбор target,
  затем tenant-scoped lookup цели по companyId/connectionId/type/externalId.
  Неизвестная и чужая цель дают одинаковый 404; существующая своя цель,
  отсутствующая среди серверных choices, даёт 422 при валидации формы.
  Этот lookup выполняется после проверки CSRF и не заменяет проверку Action
  под блокировкой. При invalid CSRF + foreign target вернуть 403 без lookup.
- GET q[]= игнорируется как нестроковое значение; POST q/page/operation/target/
  expectedRevision/requestId должны быть скалярами нужного типа, иначе 422.
  До запросов ограничить q 100 символами; GET длинный q обрезается, POST — 422.
- Все пагинаторы используют page/totalItems/perPage; CatalogTargetPage и
  MappingHistoryPage возвращают totalItems и perPage=20. В partial
  _pagination передавать явные route/params, включая connectionId,
  marketplace, listingId для edit/history, q, expectedRevision и requestId
  только там, где они нужны для сохранения контекста формы.

Предлагаемый вид:

```text
МойСклад / Подключение «Основное»
[Подключения] [Маппинг Ozon] [Маппинг Wildberries — активна]
Поиск [артикул или название]
Листинг         Размер   Артикул      Позиция МС          Статус / действие
Футболка WB     M        T-001        Футболка / M        Сопоставлен [Изменить]
Футболка WB     L        T-001        —                   Не сопоставлен [Выбрать]
```

Форма показывает листинг, размер/variantId, подключение, текущую цель и даты
импорта. Ниже — точные кандидаты и поиск name/code/article с пагинацией.
Выбор V1 — серверная таблица radio + явная кнопка «Сохранить связь»;
для снятия отдельная операция с понятным подтверждением. Это не новый
dropdown/picker: всё работает без JS и без загрузки всего справочника.
Если позже потребуется autocomplete, переиспользовать picker_core.js, не копировать.

- [ ] **4.1 RED:** создать
  `site/tests/Functional/MoySklad/ListingMappingsControllerTest.php`.
  До шаблонов проверить 200/root selector, 25-row pagination, correct selected
  connection, foreign listing/target/history 404, READ/WRITE/CSRF, unknown UUID,
  q[]= и длинный q, 422 с сохранением выбора, 409 без перезаписи, 303 после set.
  Проверить неактивный листинг, выключенное подключение, пустой каталог,
  неоднозначные подсказки, архивную цель и смену active company между GET/POST.
  Отдельные 200/root selectors для обеих вкладок; Ozon не показывает WB,
  WB не показывает Ozon; чужой marketplace listingId в edit/history/POST
  получает 404 и не изменяет ни связь, ни историю; tab switch сохраняет
  подключение и сбрасывает поисковую пагинацию.
  Входные вкладки на списке/создании подключения: 0, 1 и N подключений,
  активный tab и sidebar, стабильная пагинация выбора подключения.
  Выбрать подсказку без q — успешный POST; выбрать цель на второй странице
  поиска — successful POST; clear без target; invalid CSRF именно 403;
  invalid CSRF + foreign target — 403; valid CSRF + foreign target — 404;
  valid CSRF + своя цель вне choices — 422 (порядок lookup до ChoiceType).
  history/clear по своей сохранённой связи без живого листинга доступны,
  при чужом marketplace — 404. Пагинация edit/history сохраняет route params.
  Отдельно regression: существующий Catalog mapping и его экран неизменны.
- [ ] **4.2 GREEN:** тонкие Controllers/Form, четыре шаблона, входной Controller
  и вкладки через existing partial в layout. Выбор/clear/CSRF реализовать
  строго по контракту HTML-формы выше; POST заново проверяет UUID и допустимость.
  Отправлять параметры поиска/страницы как скаляры для восстановления контекста
  формы, не использовать их как замену валидации цели. Сгенерированный GET
  requestId сохранять при ошибках; после 303 новая форма получает новый UUID.
  Формы с новым поиском сохраняют expectedRevision исходного редактирования,
  чтобы пользователь не подтвердил незаметно изменённую другим человеком связь.
- [ ] **4.3 VERIFY:** функциональный набор, Twig gates, focused CS/PHPStan.
  Smoke: loading обычной навигации, пусто, ошибка поиска, нет прав, 409,
  две вкладки, клавиатура/radio, длинные имена, ширина 375px и desktop.
  Использовать DS и существующие pagination/flash/confirm partials;
  не добавлять inline JS/CSS. Данные только с Twig escaping.
- [ ] **4.4 HANDOFF:** финальные gates один раз, полный diff review, внешнее
  ревью Claude CLI и fix cycle; Stage Report/handoff. Один Ready PR.
  Реализация несёт миграцию: до решения владельца не merge/deploy/dispatch.

## 3. Проверки и порядок исполнения

Для реализации baseline до первой правки:

```bash
make site-test-unit
```

Записать числа тестов/assertions и существующие failures/deprecations. Затем
на подготовленной test БД узкий baseline Marketplace Facade и MoySklad
integration/functional. Использовать действующий Docker Compose проект:
не поднимать второй стек с production credentials или общими writable volumes.
Если worktree требует окружения — настроить изолированный local/test запуск.

Повторные точечные прогоны — в уже работающем site-php-cli:

```bash
docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/Marketplace/Facade/MarketplaceFacadeTest.php
docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/MoySklad/ListingMappingStorageTest.php
docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Integration/MoySklad/ChangeListingMappingActionTest.php
docker compose exec -T -e XDEBUG_MODE=off site-php-cli php bin/phpunit tests/Functional/MoySklad/ListingMappingsControllerTest.php
```

Stage module sets: PHPUnit по tests/Unit/MoySklad, tests/Integration/MoySklad,
tests/Functional/MoySklad плюс затронутые Marketplace Facade/query tests.
Проверку CS/PHPStan ограничить изменёнными PHP-файлами, используя существующие
composer/config; не перегенерировать baseline. После каждого Work item обновлять
checkpoint, после Stage — review/Stage Report/commit/push и продолжать.

Handoff реализации:

```bash
make site-stan
make site-cs-check
make site-cs-strict-types
make site-test-unit
make site-test
make site-cs-twig
```

Нет JS/React изменений — npm gates не нужны. Если scope расширяется до JS,
сначала обновить план и проверки по актуальному CLAUDE.frontend.md.

Внешнее ревью реализации — site/bin/external-review.sh с task base,
--effort medium. После BLOCKER повторить, IMPORTANT исправить и подтвердить
проверками без обязательного повторного раунда, максимум три раунда.
Финальное внутреннее ревью — в свежей сессии с TASK, полным diff и checklist.

## 4. Чек-лист приёмки и выпуска

- [ ] Все пункты приёмки TASK.md покрыты перечисленными тестами/smoke.
- [ ] Маппинг только в MoySklad, Marketplace API только read, нет Catalog side effects.
- [ ] Две вкладки в легаси-шаблоне и стилях модуля; marketplace проверяется
  сервером для GET/POST/истории, SPA/React/new-shell не затронуты.
- [ ] Явный выбор variant; родитель с вариантами недопустим, дубль article не автоприменяется.
- [ ] Успешны tenant/CSRF/permissions, гонки создания/замены и replay тесты.
- [ ] Все чтения bounded, count queries не растёт с количеством строк страницы.
- [ ] История переживает смену кода, архивацию и clear; snapshots не меняются.
- [ ] Миграция up/down/up и schema-diff без потери FK/CHECK/индексов.
- [ ] Нет открытых BLOCKER/IMPORTANT, external review документирован.
- [ ] ARCHITECTURE.md описывает фактические Entity/Enum/Facade и lock order.
- [ ] Для реализации созданы checkpoint.md, stages/stage-N.md и handoff.md.
- [ ] В handoff назвать новую миграцию и запросить одной фразой merge,
  production migration dispatch и deploy по docs/workflow/release.md.
- [ ] После одобрения выполнить pipeline и read-only post-deploy acceptance:
  страница доступна, схема актуальна, старые связи Catalog не изменились.
  Создание пробных production-связей в приёмку не входит.

План принят в документационном PR #3015. После его слияния владелец поручил
автономную реализацию. Продвижение — checkpoint.md; историческое ревью плана
в review.md не заменяет ревью реализации.
