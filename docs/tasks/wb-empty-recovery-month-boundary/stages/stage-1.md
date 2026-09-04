### Stage 1: окно восстановления с хвостом предыдущего месяца — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required (Release Gate: решение по Draft PR)

#### Stage scope
- Stage base commit: `45063ab91da73642ac5649e99692a85bb5adcb64`
- Work items completed: `1.1`–`1.6`

#### What was done
- 1.1 `WbFinancialReportPeriodResolver::recoveryWindowStart(int $daysBack)` —
  единое правило окна, с клампом по началу года и проверкой аргумента.
- 1.2 Оркестратор берёт `recoveryFrom` из этого правила, передавая собственный
  `--refresh-days-back`; обновлён help обеих опций.
- 1.3 `planEmptyRefresh` использует то же правило как дефолт `$from`; глубина
  вынесена в константу `DEFAULT_RECOVERY_DAYS_BACK = 14`, совпадающую с cron.
- 1.4 Тесты оркестратора: восстановление пустых дней хвоста прошлого месяца;
  восстановление **пропущенных** дней хвоста; тест про первое число месяца
  переписан — инвертированный диапазон по-прежнему не запрашивается, но хвост
  прошлого месяца запрашивается.
- 1.5 Тест планировщика на дефолтное окно `planEmptyRefresh` первого числа.
- 1.6 Комментарий к строке оркестратора в `docker/cron/app.cron`.

#### Files changed
- `site/src/Marketplace/Application/Service/WbFinancialReportPeriodResolver.php` — modified
- `site/src/Marketplace/Application/Service/WbFinancialReportSyncPlanner.php` — modified
- `site/src/Marketplace/Command/WbFinancialReportsOrchestrateCommand.php` — modified
- `site/tests/Unit/Marketplace/Application/Service/WbFinancialReportPeriodResolverTest.php` — modified (+6 тестов)
- `site/tests/Unit/Marketplace/Application/Service/WbFinancialReportSyncPlannerTest.php` — modified (+1 тест)
- `site/tests/Unit/Marketplace/Command/WbFinancialReportsOrchestrateCommandTest.php` — modified (+3 теста, 1 переписан)
- `docker/cron/app.cron` — modified (комментарий)
- `docs/tasks/wb-empty-recovery-month-boundary/*` — new

#### Definition of Done
- [x] Регрессионные тесты красные на `stage_base_commit`, зелёные после
- [x] Целевые классы тестов — OK (68 тестов, 260 проверок)
- [x] `composer test:unit` — OK (2290)
- [x] Интеграционные `Marketplace` — OK (500)
- [x] PHPStan по изменённым файлам — `[OK] No errors`
- [x] `composer cs:check` и `composer cs:strict-types` — `Found 0 of 2464`
- [x] Внешнее ревью Codex — `REVIEW_GREEN`
- [x] Handoff, Stage Report, checkpoint записаны

#### Baseline
- `phpunit --filter 'WbFinancialReportsOrchestrateCommandTest|WbFinancialReportSyncPlannerTest|WbFinancialReportPeriodResolverTest'` на базе — OK (59 тестов)
- `composer test:unit` на базе — OK, 4 pre-existing deprecations

#### Checks
- targeted: три класса тестов — OK (68, 260)
- module: `phpunit --testsuite integration --filter Marketplace` — OK (500)
- full relevant stage: `composer test:unit` — OK (2290, те же 4 deprecations)
- static: PHPStan level 8 по изменённым файлам — `[OK] No errors`
- style: `composer cs:check`, `composer cs:strict-types` — `Found 0 of 2464`
  (использован конфиг CI `.php-cs-fixer.php`, а не dist — урок предыдущего PR)

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: формулировка дефекта была неточной — после PR #2409 скользящее
  обновление уже подбирает пустые дни; реально теряются пропущенные дни и
  due-retry чужого режима. План и handoff исправлены, добавлен регрессионный
  тест на пропущенный день хвоста прошлого месяца (красный на базе).
- MINOR fixed: файл плана был создан в `site/docs/` из-за сохранённой рабочей
  директории оболочки — перенесён в корневой `docs/`, `site/docs/` удалён.
- FOLLOW-UP: ранние ночные попытки до формирования отчёта WB; удаление
  устаревшей опции `--retry-window-days`.

#### External Claude Code review
- Ревьюер: Codex (`codex exec -s read-only --ephemeral`, дифф и контекст через stdin, без шелла)
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: none (находок нет)
- Rejected findings with reason: none
- Ограничение ревьюера: без шелла и БД — факты о cron, прод-данных, числе
  вызывающих `planEmptyRefresh` и результатах прогонов переданы в промпте.

#### Review fixes applied
- по внешнему ревью правок не потребовалось

#### Risks / reviewer focus
- В первой половине месяца окно восстановления шире, чем раньше, поэтому
  оркестратор может найти больше кандидатов. Ограничение «одна задача на
  подключение за прогон» не тронуто, рост нагрузки не более одной задачи в час.
- Поведение с середины месяца идентично прежнему: `min` возвращает начало
  месяца, диапазоны в существующих тестах не изменились.
- На 1 января окно по-прежнему пустое; хвост декабря относится к прошлому году
  и оперативным восстановлением не покрывается — это осознанно сохранённое
  прежнее поведение, а не регресс.

#### Checkpoint
- `docs/tasks/wb-empty-recovery-month-boundary/checkpoint.md` updated
- exact next action: Release Gate — решение Владельца по Draft PR

#### Open questions
- none

#### Expected owner response
Recommended response:
`Перевести Draft PR в Ready и смержить в master (с автодеплоем)`

Alternative responses, when relevant:
- `Оставить Draft, нужны правки: <что именно>`
- `Сначала сделать follow-up про ранние ночные попытки`
