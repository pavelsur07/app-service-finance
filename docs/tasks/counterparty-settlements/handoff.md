# counterparty-settlements — handoff preparation

**Branch:** `feat/counterparty-settlements`.
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2901.
**Base:** `master`. **Status:** Draft; final gates/external review in progress.
**Task base:** `4fdad15c974830d13ff9342d1f40a526f3b5bd33`.

## Результат

Новый раздел «Взаиморасчёты» показывает задолженности и изменения по контрагентам
в шести отдельных корзинах. Расчётные документы находятся в
**«Взаиморасчёты → Документы»** (`/settlements/documents`). Основание создаётся
самостоятельно либо явно из ручного ОПиУ с подтверждением полной суммы; само ОПиУ
долг автоматически не создаёт. Существующий Cash явно включается в расчёты,
затем вручную распределяется по основаниям M:N. Погашение не повторяет ДДС/ОПиУ.

Все деньги — существующий Money, точные RUB-строки на границах. Права, company
scope, CSRF, requestId/version, блокировки и исторические проверки сохраняют
корректность повторов и конкурентных запросов. Изменения источников защищены
до отмены зависимых расчётов. Производный регистр проверяется и восстанавливается
из первичных документов/распределений; повреждённые первичные факты не исправляются
молчаливо. Исторические отмены не переписывают предшествующие остатки.

## Этапы и файлы

- Stage1 `66e666657`: `site/src/Settlement` — документы/позиции/книга/движения,
  opening/correction/reversal, Money; additive migration.
- Stage2 `81ba2ffa5`: Cash/Finance Facade DTO, события/guards, material write paths,
  атомарный импорт1C и порядок блокировок.
- Stage3 `a3b1ba860`: allocation/classification, исторические query/DTO/Facade,
  CLI verify/rebuild, EXPLAIN.
- Stage4 `89ec6ea49`: controllers/forms/Twig/access map и переходы из существующих
  ДДС/ОПиУ/контрагентов; тесты и desktop/mobile smoke.
- Stage5: фиксированная приёмка, валидация первичных фактов восстановления,
  архитектурная registry entry и уменьшение PHPStan baseline; итоговые документы.

Подробные Stage Reports — в `stages/`; правила источников —
[source-write-paths.md](source-write-paths.md), приёмка — [acceptance.md](acceptance.md).
Frontend assets/JS/dependencies/CI не менялись.

## Миграция и rollback

`site/migrations/Version20261106183000.php` создаёт6таблиц; существующие документы,
суммы и связи не преобразуются. Доказано на пустой локальной БД и схеме306
с существующими синтетическими Cash/Finance: точные snapshots сохранены,
автоматических записей Settlement нет. Production-данные не использовались.

`down()` намеренно отклонён, чтобы не удалить финансовую историю. Rollback:
возврат кода при сохранении additive tables; destructive DB rollback не выполнять.
Отдельной трансформации/очистки или backfill нет. Production migration и deploy
выполняются только после единого именованного решения владельца по release.md.

## Контракты

Добавлены Cash/Finance locked source Facade DTO, company-scoped batched labels,
SettlementFacadeInterface report/card Money DTO, HTML routes `/settlements/*`,
source-changing события и JSON422 `settlement_source_in_use`.
CLI: `app:settlement:verify` и dry-run `app:settlement:rebuild`; production
`--execute` требует отдельного именованного одобрения и не входит в этот выпуск.

## Проверки

- P01–P24: PASS, [acceptance.md](acceptance.md).
- Модуль Settlement: **153 tests / 719 assertions PASS**.
- Stage4 module/UI/affected legacy views: **144/725 PASS**; Twig21PASS.
- Browser:5страниц×1440/390,10smoke PASS; offline fixture, не production.
- Fresh migration307/schema1/4 PASS; existing-data306→307 PASS.
- Focused Stage5 CS/PHPStan10files PASS.
- Full PHPStan3837files PASS;3obsolete ignores removed. Первый full attempt
  остановился только на этих3unmatched ignores, затем исправлен.
- Full CS/strict-types PASS; unit4513/25298 PASS. Первый полный8400/47261:1E/4F,
  четыре причины исправлены; failed-gates+module/legacy source191/942 PASS.
  После обновления master выполняется заключительный полный прогон.
- Make wrapper повторяет project CLI recipes в running worktree container;
  только переустановка уже доступного vendor пропущена (`-o`), exact lock hash
  совпадает, остальные prerequisites выполняются.

## Review

Fresh full-task internal: **B0/I0/MINOR1 fixed**; полный код/тесты без истории
реализации. Stage5 primary-gap **B0/I1 fixed**,8negative red→green и bounded recheck.
Предыдущие Stage internal B0/I13 fixed. External Stage1–4:4раунда, B0/I3 fixed;
Stage3/4 REVIEW_GREEN с локальными MINOR fixes. Итоговый external round1: B0/I1/MINOR2. HTML Finance delete500 исправлен
с RED500→green; индекс добавлен; N guardqueries maintenance — FOLLOW-UP,
для пакетной оптимизации нужен source-owned event contract без Cash→Settlement SQL.
Результат fixed without re-run, BLOCKER не было.
Сводные metrics и финальный результат будут обновлены после выхода reviewer.

## Ограничения / follow-up

RUB; ручное включение/распределение; без netting/refunds/FX/loans/payroll/contract
balances и автоматического импорта истории. Старые данные не включены в долг
автоматически: компания задаёт cutoff и начальные остатки. Ограничения overflow
общего Money сохраняются, локальных обходов нет. Native scoped choice в app DS;
enhanced counterparty picker adapter — отдельное продолжение. EXPLAIN42.728ms
на10000синтетических движений — локальный замер, не production SLA.

Production-команд, merge, миграции или deploy в рамках реализации не было.
Запрос решения владельца будет сделан после gates/review/Ready.
