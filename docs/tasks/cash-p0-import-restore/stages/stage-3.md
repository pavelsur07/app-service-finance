### Stage 3: `--restore` откатывает только массовое удаление — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `87cdc0d4`
**Work items:** 3.1, 3.2, 3.3

#### What was done
- 3.1 — Тесты в `SoftDeleteCompanyTransactionsCommandTest`:
  - restore не трогает удалённые вручную (с user id и с `deleted_by IS NULL`);
  - dry-run считает только массовые и выводит число ручных;
  - ручное удаление в закрытом периоде не блокирует restore;
  - страховочный: массово удалённая строка в закрытом периоде по-прежнему
    блокирует restore.
- 3.2 — Целевой набор restore — `deleted_at IS NOT NULL AND deleted_by = ACTOR`.
  Он применяется в подсчёте цели, проверке замка, диапазоне пересчёта,
  `UPDATE` и финальной верификации. Delete-ветка не менялась. Dry-run и
  execute выводят «Удалены вручную, restore их не тронет: N». Docblock и
  описание `--restore` обновлены.

#### Red on old code (`87cdc0d4` + новые тесты)
`.......FFF..` — 3 failures:
- `testRestoreKeepsManuallyDeletedTransactions` — «Удалённая пользователем операция должна остаться удалённой»;
- `testRestoreDryRunCountsOnlyMassDeleted` — «Будет восстановлено: 3» вместо 1;
- `testManuallyDeletedRowInLockedPeriodDoesNotBlockRestore` — «Операция отклонена: … закрытом периоде».

Страховочный `testMassDeletedRowInLockedPeriodStillBlocksRestore` зелёный и
до, и после фикса.

#### Files changed
- `site/src/Cash/Command/SoftDeleteCompanyTransactionsCommand.php` — modified
- `site/tests/Integration/Cash/Command/SoftDeleteCompanyTransactionsCommandTest.php` — modified

#### Definition of Done
- [x] Ручные удаления до массового удаления после restore остаются удалёнными, `deleted_by` сохранён.
- [x] Dry-run restore: цель = только ACTOR-строки, ручные выведены отдельно.
- [x] Замок: ручная строка не блокирует, ACTOR-строка блокирует.
- [x] Верификация считает только ACTOR-строки; старые тесты команды зелёные (12/12).
- [x] Docblock и описание опции обновлены.

#### Checks
- targeted: red 3 → green 12/12 (34 assertions).
- PHPStan, php-cs-fixer по 2 файлам — чисто.

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none.
- Проверено:
  - `minOccurredAt` по ACTOR-строкам не сужает пересчёт ошибочно: не
    затронутые ручные строки не меняют факты;
  - повторный restore — no-op («Восстанавливать нечего»);
  - на проде у единственной компании с массовым удалением ручных удалений 0,
    поэтому поведение будущего отката для неё не меняется.

#### Next
- handoff.
