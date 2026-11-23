# Ревью плана маппинга МойСклад

## Результат

- Объект: TASK.md и plan.md, только план; реализация не выполнялась.
- База репозитория: `4a2583829bf5997317a75f4e4e08b8614ad5d4f1`.
- Независимый reviewer: Claude Code CLI, effort medium, 1 завершённый раунд,
  exit code 0, непустой отчёт. В процессе ревью проверялся окружающий код.
- Исходные внешние находки: BLOCKER **0**, IMPORTANT **2**, MINOR **5**.
- Все 7 находок подтверждены и исправлены в плане; отклонённых нет.
- Итог по AGENTS.md §7.2: **fixed without re-run**. Повторного запуска после
  IMPORTANT/MINOR не было; наличие маркера REVIEW_GREEN не заявляется.
- Открытые BLOCKER/IMPORTANT: **0** по проверке внесённых правок.
- Внутренний review: 1 IMPORTANT исправлен до внешнего раунда —
  реальное значение MarketplaceType для WB — wildberries, а не wb;
  также учтены yandex_market/sber_megamarket в общем Facade и ограничение
  только ozon/wildberries в UI этой задачи.
- Финальный внутренний review выполнен отдельным агентом в свежем контексте
  по TASK/полному плану/checklist: ещё 2 IMPORTANT подтверждены и исправлены.
  История основной сессии этому reviewer не передавалась.
  Вторая проверка исправлений тем же reviewer: «Обе IMPORTANT закрыты»,
  «Нет BLOCKER/IMPORTANT».
- Метрики: internal B/I = 0/3; external B/I = 0/2, MINOR = 5; всё исправлено.

## Как запускалось

Штатный site/bin/external-review.sh не передаёт docs ревьюеру: при пустом
code diff он возвращает REVIEW_GREEN без вызова Claude. Для явно заказанного
ревью документа CLI вызван напрямую с теми же ограничениями; скрипт не менялся.
На stdin переданы запрос/уточнение владельца, TASK.md и plan.md. Разрешены
только Read/Glob/Grep; ни Bash, ни запись, ни web/MCP недоступны. Секреты,
окружение и production-данные не передавались.

```bash
timeout 900 claude -p \
  --safe-mode --permission-mode dontAsk --effort medium \
  --tools 'Read,Glob,Grep' --allowedTools Read Glob Grep \
  --disallowedTools Edit Write NotebookEdit Bash WebFetch WebSearch 'mcp__*' \
  --strict-mcp-config --no-session-persistence --max-turns 40 \
  --output-format text \
  'Review the implementation plan provided on stdin. Use read-only tools to verify referenced code. Report concrete findings in Russian.' \
  < /tmp/moysklad-listing-mapping-plan-review/prompt.txt \
  > /tmp/moysklad-listing-mapping-plan-review/review.txt \
  2> /tmp/moysklad-listing-mapping-plan-review/stderr.log
```

Временные файлы CLI не коммитятся. Исходный ответ сохранён ниже для аудита.
Claude сослался на разделы исходного плана, потому что stdin не содержал
номеров строк; точные file:line приведены им для проверенного кода.

## Разбор и проверка исправлений

| ID | Уровень | Исправление | Проверка исправленного плана |
|---|---|---|---|
| E1 | IMPORTANT | Общие вкладки имеют маршруты без connectionId; 0 подключений — пусто, 1 — 302, N — выбор; в контексте ссылки сохраняют connectionId. Имена routes moysklad_. | Stage 4: явный входной Controller, четвёртый Twig-шаблон, существующий _tabs, functional cases 0/1/N и active tab/sidebar. |
| E2 | IMPORTANT | Единое поле target=product:<uuid>/variant:<uuid>, choices включают подсказки и поиск; clear отдельной формой без цели; ручная CSRF-проверка даёт 403 до handleRequest. | Stage 4: контракт полей и CSRF, tests выбора подсказки без q, поиска со второй страницы, clear и invalid token. |
| E3 | MINOR | Facade перехватывает out-of-range для нового метода; DTO собираются через существующий ListingMetaQuery с сохранением порядка. | Stage 1: тест страницы за концом и единой нормализации UNKNOWN → null. |
| E4 | MINOR | CatalogTargetPage/MappingHistoryPage имеют totalItems/perPage; partial получает явные route params. | Stage 3/4: тест history 20+1, пагинация edit/history с connection/marketplace/listing. |
| E5 | MINOR | Unique conflict requestId на другом mapping key превращается в 409 после rollback, закрытый EM не используется; отдельный advisory namespace. | Stage 3: реальная гонка разных keys на двух DB connections и отсутствие 500. |
| E6 | MINOR | Для history/clear существующей своей связи без листинга используется сохранённый listing_snapshot; set отсутствующего листинга запрещён. | Stage 3/4: snapshot fallback, clear/history, unknown/foreign key и чужая вкладка. |
| E7 | MINOR | Явные product/variant builders; гонки на существующем PostgresResetTestCase с DbReset. | Stage 2/3: точные пути новых builders и concurrency test, без DAMA wrapping transaction. |
| I1 | IMPORTANT | При назначении product блокировать его FOR UPDATE, затем проверять отсутствие вариантов; FOR SHARE совместим с FK KEY SHARE и не предотвращает конкурентный INSERT variant. | Stage 3: двухсоединительная гонка set product/INSERT variant при variantsCount=0; после более позднего импорта статус требует проверки. |
| I2 | IMPORTANT | После CSRF и разбора target, до ChoiceType выполнять scoped lookup; foreign/unknown target → 404, своя цель вне choices → 422. | Stage 4: отдельные случаи invalid CSRF + foreign target (403), valid CSRF + foreign target (404) и valid own target вне choices (422). |

Самопроверка после правок: границы контекстов, две легаси-вкладки, согласованность
полей формы/DTO/маршрутов, приёмка и каскад проверок сопоставлены с TASK.md.
Отчёт комиссионера/FIFO/финансовые пересчёты не добавлены.
Тесты приложения не запускались: в этом PR меняются только документы.
Проверены Markdown fences/whitespace, локальные ссылки, существующие пути
и git diff --check. Ограничение: план ещё не подтверждает работоспособность
будущего кода; RED/GREEN, миграции и UI smoke выполняются при реализации.

Первый одноразовый текстовый валидатор названий вкладок дал ложный FAIL из-за
переноса строки в Markdown. После нормализации пробельных символов проверка
прошла; текст требований менять для обхода проверки не потребовалось.

## Исходный ответ Claude CLI


Остались два IMPORTANT, оба в Stage 4; BLOCKER нет. Пути существующих файлов из раздела 0, составные FK, уникальные ключи целей, права `MARKETPLACE_READ/WRITE` и порядок блокировок против `SyncCatalogAction` с кодом сходятся. Номеров строк у плана на stdin нет, поэтому на план ссылаюсь по разделам, на код — `file:line`.

## IMPORTANT

### 1. Вкладки в layout не имеют контекста подключения

- **Где:** plan.md, «Прочие изменения» («вкладка/ссылка в moy_sklad/layout и на карточке подключения»), Stage 4 (таблица маршрутов, макет); `site/templates/moy_sklad/layout.html.twig:1-19`; `site/src/MoySklad/Controller/MoySkladConnectionsController.php:33`; `site/templates/partials/_sidebar.html.twig:156-163`.
- **Факт:** оба маршрута вкладок требуют `{connectionId}`. Layout общий для списка подключений (до 20 карточек на страницу) и формы подключения, где выбранного подключения нет. Сейчас в layout вкладок нет вообще, в сайдбаре один пункт «Интеграция».
- **Последствие:** на странице «Подключения» ссылки «Маппинг Ozon» и «Маппинг Wildberries» построить нельзя. Исполнитель либо покажет вкладки только внутри маппинга (тогда в самом модуле их не видно, вход лишь с карточки), либо придумает правило выбора подключения сам. Требование владельца о двух вкладках остаётся недоопределённым, а тесты 4.1 этот вход не покрывают.
- **Правка плана:**
  - Добавить маршруты без подключения: `GET /moy-sklad/mappings/{ozon|wildberries}`. Нет подключений — пустое состояние со ссылкой на создание; одно — 303 на его вкладку; несколько — легаси-список выбора подключения.
  - Layout всегда рендерит три вкладки через `partials/_tabs.html.twig` (план его не называет, а `docs/workflow/twig-conventions.md:28-30` требует искать готовый партиал). Внутри контекста подключения ссылки ведут на маршруты с `connectionId`.
  - Зафиксировать префикс имён маршрутов `moysklad_`, иначе пункт сайдбара не подсветится (`_sidebar.html.twig:156`).
  - Добавить в 4.1 случаи 0, 1 и N подключений и активную вкладку на каждой странице.

### 2. Контракт формы назначения противоречив

- **Где:** plan.md, раздел 2 (`Form/MoySkladListingMappingType.php`), Stage 4 (маршруты, абзац о кодах ответов, 4.2); `site/src/MoySklad/Controller/MoySkladConnectionFormController.php:39-61`; `site/src/MoySklad/Controller/MoySkladConnectionMutationController.php:34-36`.
- **CSRF и код ответа.** План требует «invalid CSRF — 403» и одновременно CSRF внутри Symfony Form. В указанном образце любой невалидный submit, включая токен, отдаёт 422 (`FormController:61`). 403 в модуле даёт только ручной `isCsrfTokenValid` (`MutationController:34-36`; это же проверяет `site/tests/Functional/MoySklad/ConnectionsControllerTest.php:123-124, 625-626`). По плану как написано тест CSRF из 4.1 получит 422.
- **Выбор подсказки.** 4.2 ограничивает choices «текущей страницей поиска и текущим выбором». Но форма показывает ещё и точные кандидаты из `suggest()`, а поиск при `q` короче 2 символов не запускается. Основной сценарий — открыть форму и выбрать подсказку — завершится 422.
- **Поля POST.** Таблица маршрутов задаёт раздельные `targetType` и `targetExternalId`, а раздел 2 — один `ChoiceType`. Составное значение не определено. Не определено и то, как передаётся `clear`: поле `operation`, отдельная форма или кнопка, и обязательна ли при этом цель.
- **Правка плана:**
  - Отключить `csrf_protection` у формы и проверять токен вручную до `handleRequest` с id `moysklad_listing_mapping` + `connectionId` + `listingId` → 403.
  - Убрать зависимость валидности POST от страницы поиска: одно скалярное поле `target` формата `product|variant:<uuid>` с Regex. Допустимость цели и так проверяет Action под блокировкой (1.3, шаг 5). Если `ChoiceType` остаётся, choices = подсказки ∪ страница поиска, восстановленные из скалярных `q`/`page`.
  - Описать `clear` как отдельную форму с `operation=clear`, без цели, со своими `expectedRevision` и `requestId`.

## MINOR

1. **Страница за концом и два пути сборки DTO.** `MarketplaceListingsQuery::paginate()` вызывает `setCurrentPage()` без перехвата (`site/src/Marketplace/Infrastructure/Query/MarketplaceListingsQuery.php:31-33`). Pagerfanta по умолчанию бросает `OutOfRangeCurrentPageException`, а план обещает «пустые items с сохранённым totalItems» при «использовать без изменения». Кроме того, `UNKNOWN → null` для размера делает только `site/src/Marketplace/Infrastructure/Query/ListingMetaQuery.php:57`. Правка: Facade ловит исключение или сверяет `nbPages`; из pager брать только упорядоченные ID, а DTO собирать тем же `ListingMetaQuery::findByIds`.

2. **Пагинация поиска цели и истории.** `CatalogTargetPage` и `MappingHistoryPage` содержат только `hasMore`, а `site/templates/partials/_pagination.html.twig:13-23` требует `pager` либо `total` + `perPage`. Пункт 4.3 «использовать существующие pagination partials» для них невыполним. Правка: добавить `totalItems` и `perPage` в оба DTO либо явно разрешить простые «Назад/Вперёд». Для edit и history передавать в партиал `params` с `connectionId`, `marketplace`, `listingId`: по умолчанию он берёт только query (`:11`).

3. **Гонка по `requestId` между разными ключами.** Advisory-lock взят на company + connection + listing, а `UNIQUE(company_id, request_id)` шире. Два одновременных POST с одним `requestId` на разные листинги оба пройдут шаг 2, и второй упадёт на unique violation с 500. Правка: в 1.3 указать перехват `UniqueConstraintViolationException` по этому индексу → `idempotency_conflict` (409) без продолжения работы с EntityManager. Там же задать вывод ключа блокировки по образцу `site/src/MoySklad/Application/Action/SyncCatalogAction.php:52-53`, с namespace, отличным от `moysklad-catalog`.

4. **Недоступный листинг.** Раздел 1.2 и тест 3.2 требуют snapshot fallback со статусом «Листинг недоступен». Stage 4 для history и шаг 3 Action (в том числе для `clear`) отвечают 404 / `not_found` при отсутствии DTO, а реестр строится только из существующих листингов. Fallback через UI недостижим, такую связь нельзя снять. Удаления листингов в `site/src` я не нашёл, так что сейчас это мёртвое требование. Правка: либо убрать fallback из V1, либо для history и `clear` определять marketplace по `listing_snapshot` последней ревизии.

5. **Тестовая инфраструктура.** В `site/tests/Builders/MoySklad/` нет билдеров товара и модификации, а 2.1–3.2 требуют их с составными FK; план создаёт только билдер ревизии. Для гонок на двух соединениях уже есть база без DAMA-отката — `site/tests/Support/Kernel/PostgresResetTestCase.php:8-15`. Правка: добавить в 2.1 `MoySkladProductBuilder` и `MoySkladVariantBuilder`, в 3.1 назвать `PostgresResetTestCase`.
