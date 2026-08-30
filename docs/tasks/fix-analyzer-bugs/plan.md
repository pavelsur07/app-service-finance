# Задача: два бага, найденных статическим анализом

**Источник задачи:** инструкция Владельца в чате от 2026-08-30 —
«Сначала почини два найденных бага отдельной задачей».

**Базовый коммит задачи:** `015d92e088b71050a8b89fc6d26fb8b2f64b3caf` (`master`)
**Ветка:** `fix/ai-repository-em-and-role-id`

Оба дефекта найдены PHPStan в ходе задачи `docs/tasks/static-analysis`
(Stage 1 и Stage 2) и сознательно не чинились там: исправление бага требует
регрессионного теста и лежит вне scope внедрения анализатора.

## Подтверждение дефектов до правки

**Баг A — `$this->_em` в репозиториях модуля Ai.**
`src/Ai/Repository/AiRunRepository.php:26,29` и
`src/Ai/Repository/AiSuggestionRepository.php:25,28` обращаются к `$this->_em`.
В Doctrine ORM 3 `EntityRepository` хранит менеджер в
`private readonly EntityManagerInterface $em`
(`vendor/doctrine/orm/src/EntityRepository.php:44`), а публичного или
защищённого `$_em` нет; вместо него есть
`protected function getEntityManager(): EntityManagerInterface` (строка 183).
Это шаблон MakerBundle времён ORM 2, переживший миграцию.

Отказ детерминированный, не гоночный: обращение к несуществующему свойству
даёт `null`, и следом `Call to a member function persist() on null`.
Методы живые — `src/Ai/Service/AiAgentRunner.php:47`,
`src/Ai/Service/Agent/CashflowAgent.php:51,66,271`, консольная команда
`RunCashflowAgentsCommand`.

**Баг B — `$roleId` не захвачен в замыкание.**
`src/Company/Application/AssignCompanyMemberAccessRoleAction.php:39` объявляет
`use ($member, $role, $company)`, а строка 51 внутри замыкания использует
`$roleId`, объявленный на строке 35 снаружи.
`CompanyRoleNotAvailableException::__construct(string $roleId, ...)` типизирован
строго, файл под `declare(strict_types=1)` — значит на ветке «шаблон роли удалён
до блокировки» вместо доменного исключения возникнет `TypeError`, то есть 500
вместо осмысленного отказа. Это ветка гонки, поэтому баг не проявляется в
обычном сценарии.

## Stage 1: оба бага исправлены и закрыты регрессионными тестами
Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`

Обоснование риска: две точечные правки в существующем коде без изменения
контрактов, схемы БД и конфигурации. Баг A затрагивает модуль Ai, баг B —
ветку гонки в Company. Оба меняют поведение с «падение» на «объявленное
поведение», то есть сужают, а не расширяют функциональность.

### Definition of Done
- Регрессионный тест на каждый баг **красный на текущем коде** и зелёный после
  правки — доказано прогоном до правки, а не заявлено.
- `AiRunRepository::save()` и `AiSuggestionRepository::save()` работают.
- `AssignCompanyMemberAccessRoleAction` на ветке «роль удалена до блокировки»
  бросает `CompanyRoleNotAvailableException`, а не `TypeError`.
- Взаимодействие с PR #2386 задокументировано: три записи его baseline
  (5 ошибок) после слияния обеих веток протухнут, и мержащий вторым обязан
  выполнить `make site-stan-baseline`. Сам baseline на этой ветке не существует,
  поэтому его сокращение здесь не критерий — критерий в том, чтобы будущее
  сокращение было названо и не выглядело поломкой гейта.
- Полный набор проверок зелёный.

### Явно вне scope
- Вынос `flush()` из репозиториев в Action (`CLAUDE.md`, «Глобальные запреты»):
  это рефакторинг контракта `save(bool $flush)`, отдельная задача.
- Прочие находки анализатора: 51 метод-запрос без `companyId`, 118 нарушений
  границ модулей.
- Ветка `chore/phpstan-static-analysis` — задача независима и ветвится от `master`.

### Work items
- 1.1 — регрессионный тест на баг A, доказать красным.
- 1.2 — правка бага A.
- 1.3 — регрессионный тест на баг B, доказать красным.
- 1.4 — правка бага B.

### Stage checks
- `composer test:unit` — оба новых теста зелёные.
- Прогон новых тестов на коде ДО правки — оба красные (доказательство).
- `composer cs:check`, `composer cs:strict-types`.
- PHPStan на этой ветке не настроен (он в PR #2386) — проверка типов на
  изменённых файлах выполняется точечно, факт фиксируется в отчёте.

### Reviewer focus
- Регрессионные тесты действительно красные на старом коде.
- Правка бага A не меняет контракт `save(bool $flush)`.
- Правка бага B не меняет поведение вне ветки гонки.
