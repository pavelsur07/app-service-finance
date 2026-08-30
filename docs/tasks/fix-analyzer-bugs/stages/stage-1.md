### Stage 1: два бага, найденных анализом, исправлены и закрыты регрессией — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required — решение о merge

#### Stage scope
- Stage base commit: `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`

#### Что сделано
Изменения в исходниках — **5 строк в 3 файлах**:

- `src/Ai/Repository/AiRunRepository.php`, `src/Ai/Repository/AiSuggestionRepository.php`:
  `$this->_em` → `$this->getEntityManager()` (по 2 обращения в каждом).
- `src/Company/Application/AssignCompanyMemberAccessRoleAction.php`:
  `$roleId` добавлен в `use (...)` замыкания транзакции.

Плюс два регрессионных теста:
- `tests/Unit/Ai/Repository/AiRepositorySaveTest.php` — 4 сценария:
  persist без flush и с flush, для обоих репозиториев.
- `tests/Unit/Company/Application/AssignCompanyMemberAccessRoleActionTest.php` —
  ветка гонки «шаблон роли удалён до блокировки».

#### Доказательство дефектов до правки
**Баг A.** `vendor/doctrine/orm/src/EntityRepository.php:44` — менеджер лежит в
`private readonly EntityManagerInterface $em`; `$_em` не существует, вместо него
`protected function getEntityManager()` на строке 183. Обращение к
несуществующему свойству даёт `null`.

**Баг B.** `use ($member, $role, $company)` на строке 39 не захватывает `$roleId`
со строки 35, а строка 51 внутри замыкания его использует.

#### Регрессия доказана красным
Тесты прогнаны на коде `master` (исходники возвращены `git checkout master --`,
тесты оставлены новые):

```
Tests: 5, Assertions: 2, Errors: 4, Failures: 1, Warnings: 3
```

Конкретные отказы — ровно предсказанные:
- `Error: Call to a member function persist() on null` в `AiRunRepository.php:26`;
- `TypeError: CompanyRoleNotAvailableException::__construct(): Argument #1
  ($roleId) must be of type string, null given` вместо доменного исключения.

После восстановления правок — `OK (5 tests, 11 assertions)`.

#### Definition of Done
- [x] Регрессионный тест на каждый баг красный на текущем коде — доказано прогоном
- [x] `save()` обоих репозиториев Ai работает
- [x] Ветка гонки в Company даёт доменное исключение, а не `TypeError`
- [x] Полный набор проверок зелёный
- [x] Взаимодействие с PR #2386 задокументировано с точным перечнем протухающих
      записей и командой их снятия — см. раздел ниже

#### Checks
- targeted: `php bin/phpunit tests/Unit/Ai/Repository tests/Unit/Company/Application`
  — `OK (5 tests, 11 assertions)`
- targeted (доказательство красного): те же тесты на исходниках `master` —
  4 ошибки + 1 падение
- module: `composer test:unit` — 1932 теста (на `master` было 1927), 10980
  ассертов, OK; 4 deprecations — они есть и на `master`
- full relevant stage: `composer cs:check` — exit 0; `composer cs:strict-types` — exit 0
- PHPStan на этой ветке отсутствует: он вводится в PR #2386. Типы изменённых
  строк проверены косвенно — `getEntityManager()` объявлен с возвращаемым типом
  `EntityManagerInterface`, а тесты фиксируют наблюдаемое поведение

#### Взаимодействие с PR #2386 — требует внимания при слиянии
Baseline в PR #2386 содержит ровно эти три записи (5 ошибок):

| message | identifier | count | path |
|---|---|---|---|
| `Access to an undefined property …AiRunRepository::$_em` | `property.notFound` | 2 | `src/Ai/Repository/AiRunRepository.php` |
| `Access to an undefined property …AiSuggestionRepository::$_em` | `property.notFound` | 2 | `src/Ai/Repository/AiSuggestionRepository.php` |
| `Undefined variable: $roleId` | `variable.undefined` | 1 | `src/Company/Application/AssignCompanyMemberAccessRoleAction.php` |

После слияния обеих веток эти подавления станут протухшими, и
`reportUnmatchedIgnoredErrors` уронит гейт. **Это не поломка, а ratchet,
работающий как задумано:** ошибка исправлена, значит её строка в baseline
обязана исчезнуть. Тому, кто мержит вторым, нужно выполнить
`make site-stan-baseline` — baseline сократится на 5 ошибок и 3 записи.
Guard роста при этом не сработает: он запрещает рост, а не сокращение.

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: порядок `use`-импортов и выравнивание phpdoc в новых тестах
  (нашёл `cs:check`)
- FOLLOW-UP: см. ниже

#### External Codex review
- Iterations: 2
- Result: **REVIEW_GREEN** на итерации 2
- Итерация 1 — 1 IMPORTANT, принята:
  - **IMPORTANT** — Definition of Done требовал сокращения baseline PHPStan,
    которое на этой ветке недостижимо: анализатора здесь нет. Формально это
    означало закрытие Stage с невыполненным DoD, что `AGENTS.md` запрещает.
    Исправлен сам критерий, а не отметка о нём: DoD теперь требует
    задокументировать взаимодействие с PR #2386 и назвать протухающие записи
    вместе с командой их снятия. Пункт выполнен.
  Итерация 2 — **REVIEW_GREEN**, находок нет. Ревьюер независимо подтвердил:
  `_em->` в `site/src` — 0 вхождений; в baseline PR #2386 три записи
  с count 2+2+1; `variable.undefined` встречается ровно один раз, то есть оба
  класса дефекта в кодовой базе единственны и закрыты.

#### Risks / reviewer focus
- Контракт `save(Entity, bool $flush = false)` не менялся — правка внутренняя.
- Правка бага B не меняет поведение вне ветки гонки: `$roleId` вычисляется до
  транзакции и внутри замыкания только читается.
- `CompanyAdminWriteGuard` в тесте собран настоящим, а не моком: класс `final`,
  а на этой ветке он не в `allowPaths` bypass-finals. На проверяемой ветке
  guard не вызывается — `refresh()` бросает раньше.

#### FOLLOW-UP (вне scope, не реализовано)
1. `flush()` внутри репозиториев противоречит `CLAUDE.md` («Глобальные запреты»:
   `flush()` только в Action). Исправление меняет контракт `save(bool $flush)`
   и всех вызывающих — отдельная задача.
2. Остальные находки анализатора: 51 метод-запрос без `companyId`,
   118 нарушений границ модулей.
3. Call-site правило для унаследованных finder'ов Doctrine (84 вызова).

#### Checkpoint
- `docs/tasks/fix-analyzer-bugs/checkpoint.md` обновлён
- exact next action: см. checkpoint

#### Open questions
- none

#### Expected owner response
Recommended response:
`Мержи оба PR: сначала фикс багов, потом анализатор с пересобранным baseline`

Alternative responses, when relevant:
- `Мержи только фикс багов`
- `Оставь оба в Draft`
