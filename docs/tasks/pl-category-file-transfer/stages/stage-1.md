## Stage 1: развязка движка переноса и выгрузка файлом — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes
**Следующее действие:** continue autonomously → Stage 2

### Scope Stage
- Stage base commit: `7992b6e354c181ce7b086e098b20898bf1608007`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`

### Что сделано

**1.1 — источник переноса как DTO.** `PLCategoryTreeNode` — readonly-узел с 11
переносимыми полями, ссылкой на родителя и синтетическим `key`. `key` намеренно
не является id категории: id из чужого аккаунта не имеет смысла в целевой
компании. `PLCategoryTreeExporter::fromEntities()` строит такой список из
DFS pre-order дерева сущностей и падает `LogicException`, если родитель встретился
позже потомка — молча делать узел корневым нельзя, это переставило бы категории.

**1.2 — Action больше не знает про компанию-источник.**
`ImportPLCategoryTreeCommand` принимает `list<PLCategoryTreeNode>` вместо
`sourceCompanyId`. Из Action убраны резолв компании-источника, вызов
`findTreeByCompany()` и проверка «источник == цель». Матчинг, `claimedTargetIds`,
`releaseChangingCodes()`, `preservedDescendantDepth()`, порядок мутаций и
транзакция не тронуты — замена геттеров на свойства DTO механическая.

Заодно приведён в соответствие устаревший докблок `releaseChangingCodes()`: он
утверждал, что индекс `uniq_plcat_company_code` в схеме отсутствует. Индекс
восстановлен `Version20260804120000` (2026-08-04), поэтому метод теперь держит
инвариант на уровне БД, а не только приложения. Тот же комментарий поправлен в
интеграционном тесте.

**1.3 — контроллер.** Проверка «источник ≠ цель» переехала в
`PLCategoryController::sourceNodesFromCompany()` — туда, где вообще существует
понятие компании-источника. Проверки доступа (`userHasAccess`) остались там же,
где были, до построения дерева. Контроллер переведён на constructor injection
(`PLCategoryRepository`, `PLCategoryTreeExporter`, `CompanyFacade`), чтобы не
держать один и тот же сервис под двумя именами.

**1.5 — выгрузка стала файлом.** `GET /pl-categories/export/json` отдаёт
`Content-Disposition: attachment` и конверт `{version, exportedAt, company,
categories}`. Имя файла собирается `HeaderUtils::makeDisposition()` с
обязательным ASCII-фолбэком: имена компаний кириллические. Из шаблона удалены
~50 строк `fetch()`-обвязки, `<pre>` и стиль под него — кнопка стала обычной
ссылкой `download`.

### Затронутые файлы
- `site/src/Finance/Application/DTO/PLCategoryTreeNode.php` — new
- `site/src/Finance/Application/Service/PLCategoryTreeExporter.php` — new
- `site/src/Finance/Application/Command/ImportPLCategoryTreeCommand.php` — modified
- `site/src/Finance/Application/Action/ImportPLCategoryTreeAction.php` — modified
- `site/src/Finance/Controller/PLCategoryController.php` — modified
- `site/templates/pl_category/index.html.twig` — modified (нетто −63 строки)
- `site/tests/Unit/Finance/Application/Service/PLCategoryTreeExporterTest.php` — new
- `site/tests/Functional/Finance/PLCategoryExportControllerTest.php` — new
- `site/tests/Unit/Finance/Application/Action/ImportPLCategoryTreeActionTest.php` — modified
- `site/tests/Integration/Finance/ImportPLCategoryTreeActionCodeCollisionTest.php` — modified
- `site/tests/Functional/Finance/PLCategoryImportControllerTest.php` — modified
- Миграций нет, схема не менялась.

### Тесты

Существующие 13 регрессионных unit-сценариев Action сохранены целиком: источник в
них строится не вручную, а настоящим `PLCategoryTreeExporter` поверх тех же
`PLCategoryBuilder`-сущностей — тесты проверяют ровно ту пару «экспортёр +
Action», которая работает в проде на переносе компания→компания.

Два unit-теста (`testRejectsSameSourceAndTargetCompany`,
`testRejectsWhenSourceCompanyNotFound`) удалены как относящиеся к слою, которого
у Action больше нет; покрытие перенесено на functional-уровень
(`testApplyRejectsActiveCompanyAsItsOwnSource`). **Этот тест доказан красным:**
на коде с временно удалённым guard'ом падает на `assertResponseRedirects`
(редирект уходит на `/pl-categories/`, то есть в ветку успеха).

Новый `PLCategoryTreeExporterTest` сравнивает payload целиком со всеми 11 полями
в значениях, отличных от дефолтных: молча потерянное поле = тихая потеря
настройки строки P&L при переносе, видимая только в отчёте.

### Self-review
- [x] Scope compliance — только развязка источника и выгрузка файлом
- [x] Patterns / naming — `Application/DTO`, `Application/Service`, `final readonly` для DTO/Command
- [x] Forbidden actions — none
- [x] Security — целевая компания всегда активная; доступ к компании-источнику проверяется до построения дерева; выгрузка ограничена активной компанией (functional-тест на утечку чужой категории)
- [x] CS-Fixer по изменённым файлам — чисто; полный набор тестов — зелёный
- [x] ARCHITECTURE.md — N/A на этом Stage (новых Facade / Entity / Enum нет); заметка о контракте формата v1 добавляется в Stage 2, когда появится читатель

### External review
- Reviewer: Codex CLI 0.146.0
- Iterations: 2
- Result: REVIEW_GREEN
- Confirmed findings fixed:
  - MINOR — guard-тест на self-import не доказывал заявленное поведение → добавлена проверка точного flash, поведение дополнительно доказано красным прогоном без guard'а.
  - MINOR — экспорт проверялся только по `name`/`code`/вложенности → добавлен `PLCategoryTreeExporterTest` со сравнением полного payload.
- Rejected findings with reason: нет
- Ограничения ревьюера: без доступа к репозиторию и БД — схема `pl_categories`, поведение `PLCategoryRepository::findTreeByCompany()`, семантика `setParent()`/`setCode()` и статус миграций переданы текстом в промпте. Sandbox поднялся со встроенным bubblewrap (предупреждение о его отсутствии в PATH), ревью выполнено полностью.

### Команды для проверки
- `make site-test` — 3088 тестов, зелёные
- `make site-cs-check` — baseline по репозиторию красный (не связан с задачей); по изменённым файлам проверено точечно, 0 из 11 требуют правок
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/pl_category`

### Риски / на что обратить внимание ревьюеру
- **Изменение публичного контракта:** `GET /pl-categories/export/json` теперь
  отдаёт конверт вместо голого массива и заголовок `attachment`. Единственный
  известный потребитель — JS на экране категорий — удалён в этом же Stage.
- Экспорт и импорт по-прежнему обходят дерево через ленивые `getChildren()`
  (`findTreeByCompany`), то есть N+1 по числу узлов. Поведение существовало до
  задачи и не менялось; на дереве из сотен узлов с потолком в 5 уровней
  некритично.

### Открытые вопросы
- нет
