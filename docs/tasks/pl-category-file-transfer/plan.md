# План: перенос категорий ОПиУ файлом

## Context

Категории ОПиУ переносятся между компаниями только внутри одного аккаунта: экран
`/pl-categories/import` копирует дерево напрямую из другой компании, к которой у
текущего пользователя есть доступ (`PLCategoryController::import()`,
site/src/Finance/Controller/PLCategoryController.php:54). Между разными аккаунтами
переноса нет вообще — это явный follow-up предыдущей задачи
(`docs/tasks/pl-category-import/handoff.md`: «Импорт/экспорт через файл (JSON upload)
— не строился»).

Выгрузка формально есть (`GET /pl-categories/export/json`, :39), но это не файл:
экран забирает JSON через `fetch()` и вываливает в `<pre>` для копипаста
(templates/pl_category/index.html.twig:156–196).

Нужно: выгрузить категории компании в файл и загрузить этот файл в другую компанию,
в том числе в другом аккаунте.

Ключевой факт, определяющий объём работ: движок переноса уже написан и покрыт
15 unit- + 1 integration- + 6 functional-тестами — `ImportPLCategoryTreeAction`
(матчинг по `code` с фолбэком на `(parent, name)`, диффы, валидация глубины,
освобождение кодов, одна транзакция). Единственное, что мешает переносу между
аккаунтами, — он сам ходит в БД за деревом компании-источника. И второй факт:
набор полей в `serializeCategory()` уже **точно совпадает** с тем, что применяет
`applyFields()` (12 полей, родитель — через вложенность). Экспорт уже является
валидным payload'ом импорта. Значит задача — не новый импорт, а развязка источника
от движка и доставка того же дерева через файл.

## Решения Владельца (приняты)

- Формулы со ссылками на коды, которых нет в целевой компании: **предупредить в
  предпросмотре, импорт не блокировать**.
- Объём: **только ОПиУ**. `CashflowCategory` (ДДС) и `BalanceCategory` не трогаем.
- **Только UI**, консольной команды не делаем.

Сохраняем текущую семантику: категории целевой компании, отсутствующие в источнике,
не удаляются и не изменяются (импорт, а не синхронизация). Режим «из другой компании»
остаётся в UI — после рефакторинга он стоит одну строку.

## Архитектура изменений

```
                    PLCategoryTreeNode (readonly DTO, плоский DFS pre-order)
                            ↑                              ↑
   PLCategoryTreeExporter::fromEntities()      PLCategoryTreeFileReader::read()
   (PLCategory[] из findTreeByCompany)         (строка JSON + валидация)
                            ↓                              ↓
              ImportPLCategoryTreeAction(sourceNodes, targetCompanyId, dryRun)
```

### 1. `src/Finance/Application/DTO/PLCategoryTreeNode.php` (new)

`final readonly class`: `string $key`, `?self $parent` + 11 переносимых полей
(`name`, `code`, `type`, `format`, `flow`, `expenseType`, `weightInParent`,
`isVisible`, `formula`, `calcOrder`, `sortOrder`). Enum'ы — настоящими типами
(`PLCategoryType` и др.), не строками: валидация значений тогда происходит один раз,
на границе чтения файла.

`key` — синтетический ключ узла внутри дерева (для `$resolvedBySourceId` в Action),
а не `id` источника: id из чужого аккаунта не имеют смысла и не должны попадать в
целевую компанию. Список плоский, в том же DFS pre-order, в котором Action уже
работает; `parent` ссылается на узел, созданный раньше по списку.

### 2. `src/Finance/Application/Service/PLCategoryTreeExporter.php` (new)

- `fromEntities(PLCategory[] $dfsTree): list<PLCategoryTreeNode>` — чистая функция,
  без обращений к БД; `key` = id сущности.
- `toFilePayload(list<PLCategoryTreeNode> $nodes, string $companyName, \DateTimeImmutable $now): array`
  — конверт + вложенный `children`. Единственное место сериализации формата;
  приватный `serializeCategory()` из контроллера удаляется.

`findTreeByCompany()` зовёт контроллер (репозиторий у него уже есть) — отдельный
адаптер «компания → ноды» не заводим.

### 3. `src/Finance/Application/Service/PLCategoryTreeFileReader.php` (new)

`read(string $json): list<PLCategoryTreeNode>`, бросает `\DomainException` с
человекочитаемым текстом и путём проблемного узла. Единственная граница доверия в
задаче: файл приходит из чужого аккаунта.

### 4. `ImportPLCategoryTreeAction` — минимальный диф

- `ImportPLCategoryTreeCommand`: `list<PLCategoryTreeNode> $sourceNodes` вместо
  `string $sourceCompanyId`.
- Убрать резолв компании-источника и `findTreeByCompany()`; убрать проверку
  «источник == цель» (переезжает в контроллер, где есть понятие компании-источника).
- `CompanyRepository` остаётся — только для целевой компании.
- Механическая замена геттеров на свойства DTO: `getName()` → `->name`,
  `getParent()` → `->parent`, `$sourceParent->getId()` → `->key`. Сигнатуры
  `fieldsDiffer()` / `applyFields()` принимают `PLCategoryTreeNode $source`.
- Логика матчинга, `claimedTargetIds`, `releaseChangingCodes()`,
  `preservedDescendantDepth()`, транзакция — **не меняются**.
- Обновить устаревший докблок :92–106: индекс `uniq_plcat_company_code` восстановлен
  миграцией `migrations/Version20260804120000.php` (2026-08-04), утверждение «схема
  его физически не проверяет» больше не соответствует действительности, и
  `releaseChangingCodes()` теперь держит инвариант на уровне БД, а не только
  приложения.

### 5. Предупреждение о формулах

В `ImportPLCategoryTreeResult` добавить `list<string> $unresolvedFormulaCodes`.
В Action: `known = codes(target)` (готовый `PLCategoryRepository::findCodesByCompany()`)
`∪ codes(sourceNodes)`; из каждой непустой `formula` вынуть токены
`/\b[A-Z][A-Z0-9_]{1,63}\b/u`; всё, чего нет в `known`, — в список. Парсера формул в
проекте нет (`formula` в PLCategory — «пока только хранение»), поэтому в тексте
предупреждения честно писать «среди них могут быть имена функций». Пометить
`// ponytail: токенизация регуляркой; заменить на разбор, когда появится настоящий
парсер формул`.

### 6. Контроллер `PLCategoryController`

| Маршрут | Изменение |
|---|---|
| `GET /pl-categories/export/json` (`pl_category_export_json`) | Тот же маршрут, но `Content-Disposition: attachment; filename="pl-categories-<company>-<date>.json"` и конверт вместо голого массива. Точный прецедент — `ReportCashflowController::exportJson()` (src/Finance/Controller/ReportCashflowController.php:32): `JsonResponse` + `setEncodingOptions(JSON_PRETTY_PRINT\|JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES)` + заголовок. Второй маршрут не заводим. |
| `GET /pl-categories/import` | Экран получает второй блок — загрузка файла. Режим «из компании» переводится на `$exporter->fromEntities($repo->findTreeByCompany($source))`; проверка «источник ≠ цель» переезжает сюда. |
| `POST /pl-categories/import/upload` (new) | CSRF, лимит размера, `read()`, `$action(dryRun: true)`, сырой JSON в сессию под `pl_category_import_file`, рендер того же предпросмотра с `mode: 'file'`. |
| `POST /pl-categories/import/apply` | Двухрежимный: `mode=file` — берёт JSON из сессии, `read()` заново, применяет, `session->remove()`; иначе прежний путь по `sourceCompanyId`. |

Состояние между предпросмотром и применением — в сессии, прецедент
`CashFileImportController` (src/Cash/Controller/Import/CashFileImportController.php:125).
S3 и Messenger, как в cash-импорте, здесь не нужны: дерево ОПиУ — сотня узлов,
обработка синхронная.

### 7. Шаблоны

- `templates/pl_category/index.html.twig`: кнопка «Выгрузить в JSON» → обычная
  `<a href="{{ path('pl_category_export_json') }}">Выгрузить в файл</a>`. Удаляются
  `<pre id="pl-category-json-output">`, блок `<style>` под него и ~50 строк
  `fetch()`-обвязки (:156–196) — чистое удаление.
- `templates/pl_category/import.html.twig`: блок загрузки файла (`<input type="file"
  accept="application/json,.json">` + CSRF), в предпросмотре — источник (компания
  или имя файла), скрытое поле `mode`, блок предупреждения по формулам.
- Шаблоны остаются на текущей Tabler-разметке файлов, которые правим: смена
  верстки на UI Kit — отдельная задача, смешивать её с логикой переноса нельзя.

## Формат файла (v1)

```json
{
  "version": 1,
  "exportedAt": "2026-08-05T10:00:00+03:00",
  "company": "ООО Ромашка",
  "categories": [
    { "name": "Выручка", "code": "REVENUE", "type": "SUBTOTAL", "format": "MONEY",
      "flow": "INCOME", "expenseType": "other", "weightInParent": "1.0000",
      "isVisible": true, "formula": null, "calcOrder": null, "sortOrder": 10,
      "children": [] }
  ]
}
```

- `id` и `level` не пишем: первое бессмысленно в чужом аккаунте, второе выводится из
  вложенности.
- Неизвестные ключи при чтении игнорируются. Голый массив верхнего уровня (то, что
  отдавал старый endpoint и что пользователи могли скопировать из `<pre>`)
  принимается как v0 — три строки на `array_is_list()`.
- `company` и `exportedAt` — информационные, при импорте не используются.

## Валидация файла

Отказ = `\DomainException` → flash + 422-семантика, не 500. Проверяем:

- Размер загруженного файла и длина строки (лимит 1 МБ), `json_decode` с лимитом
  глубины, число узлов ≤ 1000.
- `version` — только 1 (иначе «файл выгружен другой версией формата»).
- `name` непустой, `mb_strlen ≤ 255`; `code` ≤ 64 или `null`, нормализация как в
  `PLCategory::setCode()` (trim + upper) — иначе `abc` и `ABC` из файла разойдутся с
  матчингом.
- Enum'ы через `tryFrom()` с перечислением допустимых значений в ошибке.
- `weightInParent` — числовая строка, приводится к `decimal(10,4)`; `isVisible` —
  bool; `calcOrder` — int|null; `sortOrder` — int.
- Глубина ≤ 5 (совпадает с `MAX_LEVEL` в Action и `Assert::range()` в
  `PLCategory::setParent()`).
- **Дубли `code` внутри файла — отказ.** С 2026-08-04 действует уникальный индекс
  `uniq_plcat_company_code`; два узла файла с одним кодом дали бы SQLSTATE 23505 в
  середине транзакции импорта.
- `companyId` из файла не читается вообще: импорт всегда в активную компанию
  (`ActiveCompanyService::getActiveCompany()`), доступ к компании-источнику в
  файловом режиме не проверяется, потому что понятия «компания-источник» нет.

## Stage 1 — развязка движка и выгрузка файлом

```yaml
Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: 7992b6e354c181ce7b086e098b20898bf1608007
```

Work items:
- 1.1 `PLCategoryTreeNode` + `PLCategoryTreeExporter::fromEntities()`.
- 1.2 `ImportPLCategoryTreeCommand` на нодах; Action переведён на DTO; докблок про
  uniq-индекс приведён в соответствие с `Version20260804120000`.
- 1.3 Контроллер: режим «из компании» через exporter, проверка «источник ≠ цель».
- 1.4 Тесты переведены (см. ниже), зелёные.
- 1.5 `toFilePayload()` + attachment на существующем маршруте; `index.html.twig` —
  ссылка `download`, удаление `<pre>`/`<style>`/JS; functional-тест выгрузки.

Definition of Done: поведение переноса компания→компания не изменилось (те же
существующие тесты проходят на новой сигнатуре), выгрузка отдаёт скачиваемый файл
в формате v1, миграций нет.

## Stage 2 — загрузка из файла

```yaml
Risk: MEDIUM
owner_gate: no
release_candidate: yes
independently_deployable: yes
```

Work items:
- 2.1 `PLCategoryTreeFileReader` + unit-тесты валидации.
- 2.2 Round-trip тест: `fromEntities → toFilePayload → read` даёт эквивалентный
  список нод. Это главный тест задачи — он и есть гарантия, что выгрузка одной
  компании грузится в другую.
- 2.3 Маршрут `import/upload`, сессионное состояние, двухрежимный `import/apply`.
- 2.4 `unresolvedFormulaCodes` в результате + вывод предупреждения.
- 2.5 `import.html.twig`: форма загрузки, источник предпросмотра, блок формул.
- 2.6 Functional-тесты сценариев.

Definition of Done: файл, выгруженный в компании A (аккаунт 1), загружается в
компанию B (аккаунт 2) с предпросмотром и повторно применяется идемпотентно;
битый/чужой/слишком большой файл даёт понятную ошибку, а не 500.

## Тесты

| Файл | Что делаем |
|---|---|
| `tests/Unit/Finance/Application/Action/ImportPLCategoryTreeActionTest.php` | Источник строится не моком `findTreeByCompany`, а `PLCategoryTreeExporter::fromEntities([...])` поверх тех же `PLCategoryBuilder`-сущностей — правится строка вызова в каждом тесте, все 13 регрессионных сценариев и их assertions сохраняются. `testRejectsWhenSourceCompanyNotFound` и `testRejectsSameSourceAndTargetCompany` уходят на уровень контроллера (functional). |
| `tests/Integration/Finance/ImportPLCategoryTreeActionCodeCollisionTest.php` | Обновить конструкцию команды; тест на реальной БД с восстановленным uniq-индексом сохраняется как есть. |
| `tests/Unit/Finance/Application/Service/PLCategoryTreeFileReaderTest.php` (new) | Битый JSON; чужой `version`; отсутствующий `categories`; неизвестный enum; пустое/длинное имя; дубль `code`; глубина 6; нормализация `code` в верхний регистр; игнор неизвестных ключей; приём голого массива (v0). |
| `tests/Unit/Finance/Application/Service/PLCategoryTreeExporterTest.php` (new) | Round-trip (2.2) + `unresolvedFormulaCodes`. |
| `tests/Functional/Finance/PLCategoryImportControllerTest.php` | + upload-предпросмотр, + apply из сессии, + apply без CSRF → 403, + битый файл → flash, + повторный импорт того же файла идемпотентен. |
| `tests/Functional/Finance/PLCategoryExportControllerTest.php` (new) | Гость → редирект/403; авторизованный → `Content-Disposition: attachment`, конверт с `version: 1`, только категории активной компании. |

## Verification

```
make site-test-unit
make site-test           # полный набор, включая functional и integration
make site-cs-check       # baseline красный по репозиторию — сверять только изменённые файлы
```

Ручной сквозной прогон (он же приёмка задачи):
1. В компании A нажать «Выгрузить в файл» → скачивается `pl-categories-*.json`.
2. Переключиться в компанию B (можно в другом аккаунте), `/pl-categories/import` →
   загрузить файл → предпросмотр показывает «создать N / обновить M / без изменений K»
   и, если в дереве есть формулы, блок предупреждения.
3. Применить → дерево B совпадает с A; категории B, которых не было в файле, на месте.
4. Загрузить тот же файл повторно → «создать 0, обновить 0».
5. Загрузить заведомо битый файл (обрезанный JSON, `"flow": "WRONG"`, дубль `code`) →
   осмысленная ошибка на экране, БД не изменилась.

Приложение поднимается через `make` из корня; PROD не затрагивается.

## Вне scope / риски

- ДДС (`CashflowCategory`) и `BalanceCategory` — не трогаем; формат файла v1
  специфичен для ОПиУ, обобщать заранее не будем.
- Смена ответа `GET /pl-categories/export/json` с голого массива на конверт —
  изменение публичного контракта; единственный известный потребитель (JS на экране)
  удаляется в этой же задаче. Зафиксировать в handoff.
- Верстку экранов на UI Kit в этой задаче не переносим.
- Миграций нет, схема не меняется.
- Записать `docs/tasks/pl-category-file-transfer/{TASK.md,plan.md}`, Stage Report'ы и
  handoff по регламенту; ARCHITECTURE.md — короткая заметка в разделе Finance про
  контракт формата v1 (новых Facade/Entity/Enum задача не добавляет).
```