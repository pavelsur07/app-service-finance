## Stage 1: Backend core — matching engine + Company facade — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously (Stage 2)

### Scope Stage
- Stage base commit: `fcd8b9874df93e2a878bd4506dc61e702c62be6`
- Work items completed: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7

### Что сделано
- `PLCategoryController::serializeCategory` теперь сериализует `expenseType` (round-trip fix для существующего `export/json`).
- `CompanyMemberRepository::findActiveByUserId()`, `CompanyRepository::findByUserId()` — новые repository-методы по scalar id.
- `CompanyFacade::listAccessibleCompaniesForUser()` / `userHasAccess()` — публичный контракт для Stage 2 (список компаний пользователя = owned + активные CompanyMember, дедуплицированные по id; проверка доступа).
- `ImportPLCategoryTreeAction` — движок переноса дерева ОПиУ компания→компания:
  - матчинг по `code` (если задан у источника, ищет по всей компании, не по siblings) либо по `(parent, name)`;
  - биекция source↔target: один существующий target-узел не может быть отдан двум разным source-узлам (первый в порядке обхода забирает, остальные создают новый);
  - обновление полей при совпадении, создание при отсутствии, **никогда не удаляет** узлы target, отсутствующие в источнике;
  - глубина (≤5 уровней) считается по собственному плану (`targetLevel`), не по «живому» `getLevel()` — dry-run и apply дают идентичный вердикт; учитывает глубину сохраняемых (не в источнике) потомков совпавшего узла, но не задваивает узлы, которыми управляет отдельный source-узел;
  - три фазы: матчинг (read-only) → валидация глубины и diff (read-only) → мутации (только если весь план прошёл валидацию — ни один узел не остаётся частично применённым при исключении);
  - `releaseChangingCodes()` — защищает от временной коллизии `code` внутри одного `flush()` (Doctrine выполняет все insert раньше всех update); вся мутационная фаза обёрнута в `$em->wrapInTransaction()`.
- `Command`/`DTO` (`ImportPLCategoryTreeCommand`, `ImportPLCategoryTreeResult`, `ImportPLCategoryTreeRow`).
- `ARCHITECTURE.md` обновлён (секция `CompanyFacade`, changelog 1.71).

### Затронутые файлы
- `src/Finance/Application/Action/ImportPLCategoryTreeAction.php` — new
- `src/Finance/Application/Command/ImportPLCategoryTreeCommand.php` — new
- `src/Finance/Application/DTO/ImportPLCategoryTreeResult.php` — new
- `src/Finance/Application/DTO/ImportPLCategoryTreeRow.php` — new
- `src/Finance/Controller/PLCategoryController.php` — modified (`expenseType` в exportJson)
- `src/Company/Facade/CompanyFacade.php` — modified (+2 метода)
- `src/Company/Infrastructure/Repository/CompanyRepository.php` — modified (+`findByUserId`)
- `src/Company/Repository/CompanyMemberRepository.php` — modified (+`findActiveByUserId`)
- `tests/Builders/Finance/PLCategoryBuilder.php` — modified (+`withCode`/`withParent`/`withExpenseType`)
- `tests/Unit/Finance/Application/Action/ImportPLCategoryTreeActionTest.php` — new (22 теста)
- `tests/Unit/Company/Facade/CompanyFacadeTest.php` — new (5 тестов)
- `tests/Integration/Finance/ImportPLCategoryTreeActionCodeCollisionTest.php` — new (реальный Doctrine+PostgreSQL)
- `tests/Unit/Admin/Application/CreateAccountActionTest.php` — modified (новый обязательный конструкторный параметр `CompanyFacade`)
- `ARCHITECTURE.md` — modified
- `docs/tasks/pl-category-import/plan.md` — new

### Self-review
- [x] Scope compliance — только backend core; Controller/UI сознательно в Stage 2/3
- [x] Patterns / naming — `final class` для Action, `final readonly class` для Command/DTO, конструкторная инъекция
- [x] Forbidden actions — none (нет `dump()`, нет `flush()` вне Action, нет прямого `EntityType`/чужой Entity в формах)
- [x] Security (companyId, IDOR) — все repository-запросы matching scoped по `Company`; auth "кто может импортировать" — намеренно в Stage 2 (Action доверяет уже провалидированным id)
- [x] PHPStan/CS-Fixer/tests — CS-Fixer чисто на изменённых файлах; PHPStan в проекте не настроен (см. `phpstan-not-installed` — self-review только cs-fixer + phpunit)
- [x] ARCHITECTURE.md updated

### External review
- Reviewer: Codex CLI (`codex exec -s read-only --ephemeral`)
- Iterations: 6
- Result: **REVIEW_GREEN**
- Confirmed findings fixed:
  1. (Round 2) Depth-check читал «живой» `getLevel()` — dry-run и apply могли давать разный вердикт → фикс: собственная карта `targetLevelBySourceId`, независимая от мутаций.
  2. (Round 2) `preservedDescendantDepth` (тогда `maxDescendantDepth`) считал вообще всех текущих потомков, включая тех, которыми отдельно управляет другой source-узел → фикс: исключение по `matchedTargetIds`.
  3. (Round 2, обнаружено проактивно при написании regression-теста, не было отдельной находкой ревьюера) — мутации выполнялись инлайн по ходу единого прохода, из-за чего исключение посреди дерева могло оставить EntityManager с частично применёнными изменениями → фикс: три явные фазы (матчинг → валидация → мутации), мутации только после полной валидации.
  4. (Round 3) `resolveMatches()` не гарантировал биекцию source↔target — два source-узла (code-матч и (parent,name)-фолбэк) могли резолвиться в одну и ту же target-сущность → фикс: `claimedTargetIds`, отклонённый матч создаёт новый узел.
  5. (Round 4) Обратный порядок коллизии (source: узел без code матчится на существующий с кодом C, затем узел с кодом C) мог временно нарушить уникальность `(company, code)` внутри одного `flush()` (Doctrine делает все insert раньше всех update) → фикс: `releaseChangingCodes()`, отдельный pre-flush освобождения кода.
  6. (Round 5) Транзакционность: release-flush и основной flush не были объединены — при падении основного могла закоммититься только часть → фикс: весь мутационный блок обёрнут в `$em->wrapInTransaction()`.
  7. (Round 5, самостоятельно обнаружено при проверке) я забыл убрать свой временный отладочный маркер `TEMP-DISABLED-FOR-VERIFICATION`, из-за которого `releaseChangingCodes()` не вызывался — восстановлено.
  8. (Round 5) Комментарии/докблоки ошибочно утверждали существование реального `uniq_plcat_company_code` в текущей схеме — переписаны с указанием на фактическое состояние (см. «Открытые вопросы» ниже).
- Дополнительно исправлен MINOR findings раунда 6 (тест на транзакционную границу `wrapInTransaction`) — добавлен `testWrapsBothFlushesInSingleTransaction`.
- Rejected findings with reason: нет
- Ограничения ревьюера: без доступа к shell/файловой системе — весь контекст (существующие прецеденты, деловые правила, факты о схеме) передавался в промпте текстом; факт об отсутствующем `uniq_plcat_company_code` эмпирически проверен мной напрямую (psql + raw SQL воспроизведение), не самим ревьюером.

### Команды для проверки
- `docker compose run --rm site-php-cli composer test:unit` — 1753 теста, зелёные
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test:integration` — 933 теста, зелёные (включая новый `ImportPLCategoryTreeActionCodeCollisionTest`, реальный Doctrine+PostgreSQL)
- `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff` точечно по изменённым файлам — чисто

### Риски / на что обратить внимание ревьюеру
- **Обнаружен несвязанный дефект схемы вне scope этой задачи**: unique-индекс `uniq_plcat_company_code` (company_id, code) на `pl_categories`, созданный в `Version20251001120000`, был удалён в `Version20251105174115::up()` (строка 140: `DROP INDEX uniq_plcat_company_code`) и **ни разу не восстановлен** ни в одной последующей `up()`-миграции (пересоздание есть только в `down()` той же миграции, т.е. в откате). Подтверждено напрямую: `\d pl_categories` в тестовой БД не показывает этот индекс среди существующих. Это означает, что в текущей проде/тесте уникальность `(company_id, code)` для категорий ОПиУ физически ничем не обеспечена — только `#[UniqueEntity]` (app-level валидатор, не вызывается большинством существующего кода, включая новый Action). Это pre-existing дефект, не создан этой задачей, но `ImportPLCategoryTreeAction::releaseChangingCodes()` написан с расчётом на его возможное восстановление в будущем. **Рекомендую отдельную задачу**: аудит существующих дублей `code` в проде и восстановление индекса отдельной миграцией.
- `ImportPLCategoryTreeAction` пока не имеет проверки доступа (кто может инициировать импорт) — намеренно, будет в Stage 2 на уровне Controller через `CompanyFacade::userHasAccess()` до вызова Action.
- `Finance/Application/Action/*.php` продолжает существующий (уже до этой задачи противоречивый) прецедент прямого импорта `Company\Infrastructure\Repository\CompanyRepository` вместо `CompanyFacade` — не чинил, вне scope.

### Открытые вопросы
- Нет.
