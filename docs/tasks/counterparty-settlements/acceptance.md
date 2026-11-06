# Приёмка взаиморасчётов

Дата проверки: 2026-11-06. Ветка `feat/counterparty-settlements`, task base
`4fdad15c974830d13ff9342d1f40a526f3b5bd33`; Stage 1–4 до `89ec6ea49`,
дополнительная сквозная приёмка и защита восстановления включены в Stage 5.
Итоговый commit — в [handoff.md](handoff.md).

Изолированная компания, cutoff 01.11.2026. Сквозные примеры используют существующий
MockClock: 10.11.2026, Москва; это позволяет проверить отмены 07.11/08.11 независимо
от реального today. Денежные проверки сравнивают точные строки Money.

Команда модуля в контейнере `vf-settlements-php` с worktree в `/app`:

```sh
php bin/phpunit tests/Unit/Settlement tests/Integration/Settlement tests/Functional/Settlement
```

Результат: **153 tests, 719 assertions, PASS**. Локальный launcher
`/tmp/vf-settlement-check.py` выбирает только изолированную тестовую БД;
production-параметры и данные не использовались. Последний отдельный запуск
AcceptanceTest до двух дополнительных проверок projection: **21/107 PASS**;
итоговый модульный запуск включает эти проверки и новый BaseDocumentPolicyTest.

В таблице сокращения — классы `Settlement*Test` в `tests/Integration/Settlement`,
кроме явно указанных functional/unit. Доказательство — утверждения наблюдаемых
сумм/HTTP/числа записей; финансовые вычисления реализации в тестах не повторяются.

| Done | ID | Фактический результат | Owning test / протокол |
|---|---|---|---|
| [x] | P01 | Opening/closing 25000.00, обычный оборот 0.00 | Acceptance::testOpeningIsSeparateFromOrdinaryTurnover |
| [x] | P02 | 100000.00 − 40000.00 = долг 60000.00; оплата распределена | Acceptance::testA1A5DatedCancellationAndRebuildPreserveHistoricalAmounts |
| [x] | P03 | Аванс 40000.00 до основания, зачёт и доплата 60000.00; обе позиции 0.00 | Acceptance::testA2AdvanceBeforeLiabilityAndFinalPaymentCloseBothPositions |
| [x] | P04 | Дебиторка 120000.00 после двух оплат 30000.00/50000.00 = 40000.00 | Acceptance::testA3SeveralIncomingPaymentsLeaveReceivableFortyThousand |
| [x] | P05 | Платёж 100000.00 закрывает 60000.00 и 40000.00; долг 10000.00, перерасход запрещён | Acceptance::testA4OnePaymentClosesSeveralGroundsAndCannotBeSpentAgain |
| [x] | P06 | Долг 04/06/08.11 = 100000.00/60000.00/100000.00; после отмены 40000.00 неопределённой оплаты, Cash не повторён | Acceptance::testA1A5DatedCancellationAndRebuildPreserveHistoricalAmounts |
| [x] | P07 | Долг нам 30000.00 и наш долг 50000.00 показаны отдельно | Acceptance::testA6MutualDebtsAreNotNetted |
| [x] | P08 | Долг 60000.00; исходный ОПиУ 100000.00 и legacy allocatedAmount Cash не изменены, нового расхода нет | Acceptance::testA7FinanceGroundAndLegacyCashAllocationRemainUnchanged |
| [x] | P09 | Полное обязательство 120000.00 при неизменном ОПиУ 100000.00; проведение отдельно | CreateSettlementFromFinanceTest::testFullSettlementAmountIsIndependentFromPnlAndPostingIsExplicit |
| [x] | P10 | Неопределённая оплата отдельно от аванса; классификация переносит только свободный остаток | RegisterCashSettlementTest; Allocation::testClassificationMovesOnlyUnallocatedRemainder |
| [x] | P11 | Повтор возвращает исходный ID; другой payload с тем же ключом отклонён | DocumentInvariant::testRequestKeyCannotBeReusedWithDifferentAmount; Allocation::testAllocationRequestCannotBeReusedWithAnotherAmount; functional Journey |
| [x] | P12 | Две реальные PostgreSQL-сессии расходуют по 30000.00 из 40000.00: одно распределение, свободно 10000.00 | AllocationConcurrency::testIndependentSessionsCannotDoubleSpendOrDoubleReverse |
| [x] | P13 | Чужие ресурсы не раскрыты; read-only/отозванное право отклонено также в Action | functional Access; DocumentInvariant::testRevokedRightsAreRecheckedBeforePosting |
| [x] | P14 | Bulk/import/delete/raw SQL paths атомарно отклонены при активном источнике; комментарий разрешён | SourceMutationGuard; SourceLockOrder; [source-write-paths.md](source-write-paths.md) |
| [x] | P15 | Проведение до cutoff/в будущем/до свежей границы закрытия запрещено | Acceptance::testPostingRespectsFrozenMoscowTodayAndFreshClosure; DocumentInvariant |
| [x] | P16 | На dueDate просрочка 0.00; на следующий день 100.00; 50.00 без срока отдельно | ReportQuery::testOpeningIsNotTurnoverAndDueDateHasAnExactBoundary |
| [x] | P17 | Порча производных движений обнаружена; rebuild восстанавливает историю; повтор безопасен | Acceptance::testA1A5DatedCancellationAndRebuildPreserveHistoricalAmounts; RegisterVerification |
| [x] | P18 | На пустой БД применены все 307 миграций; на схеме 306 с Cash/Finance новая миграция сохранила исходные снимки, все 6 Settlement-таблиц пусты | Протокол миграции ниже; SchemaInSyncWithMigrationsTest |
| [x] | P19 | Заднее распределение/коррекция/классификация и неверная хронология отмен отклонены без отрицательных интервалов | Acceptance::testA9BackdatedAllocationCannotSpendTheIntervalBeforeCancellation; Allocation; DocumentInvariant |
| [x] | P20 | На 06/07/08.11 учтено 40000.00/0.00/40000.00; один исходный Cash | Acceptance::testA10ReinclusionKeepsOneCashAndThreeHistoricalSnapshots |
| [x] | P21 | Разные requestId, включая конкурентные: один reversal ID и один обратный эффект | AllocationConcurrency; Allocation::testDatedReversalRestoresBothSidesExactlyOnce |
| [x] | P22 | Общий Money в DTO/Entity/логике; точные 0.10 + 0.20 = 0.30; 0.31 отклонено, смешение валют запрещено | unit PostingPolicy; Schema; контрактный review |
| [x] | P23 | 0.01/0.30 переданы и отображены точно; 0.001/overflow отклонены до округления и записи | unit SettlementAmountTypeTest; functional Validation/Journey; Schema |
| [x] | P24 | SQL SUM даёт Money 0.30; сумма вне диапазона отклонена до int-cast | ReportQuery::testGrandTotalsCoverEveryPageAndKopecksStayExact; testAggregateOverflowIsRejectedBeforeIntConversion |

## Восстановление и первичные факты

Согласованная порча документа и позиции не должна создавать деньги при rebuild.
Восемь отрицательных сценариев AcceptanceTest сначала дали **8 failures**:
неверная корзина обязательства, неверная дата OPENING, дата до cutoff/после today,
ручной источник PAYMENT, сумма/контрагент/направление Cash, не совпадающие с оплатой.
После исправления весь acceptance set — **21/107 PASS**, итоговый модуль — **153/719 PASS**.
При отказе движения и source links остаются неизменными.

Общая PostingPolicy проверяет сочетания вида/корзины/источника при записи и replay;
maintenance сверяет активные источники под блокировками. Положительный контроль:
валидная история восстанавливается после закрытия её периода. Исторические записи
не превращаются в новые операции закрытого периода. Новые записи по-прежнему
проверяют актуальную границу закрытия компании.

## Протокол миграции

Только локальные изолированные БД, без копии production:

1. `vf_settlements_fresh_test`: все 307 миграций с пустой схемы;
   SchemaInSyncWithMigrationsTest — **1 test / 4 assertions PASS**.
2. `vf_settlements_migration_b42f`: 306 миграций до `Version20261106120000`;
   синтетические Cash **40000.00**, Finance и одна операция **100000.00**.
3. Сохранён точный снимок материальных полей существующих источников. Применена
   только `Version20261106183000`: 35 SQL statements, PASS.
4. Снимок совпал; Cash=1, Finance=1, operations=1. Books/documents/items/movements/
   allocations/source_links — **0/0/0/0/0/0**. История автоматически не включена.

Полный локальный лог: `/tmp/vf-settlement-existing-migration.log`. Миграция
добавляет таблицы без преобразования прежних данных. `down()` намеренно запрещён,
чтобы откат кода не удалял финансовую историю.

## Интерфейс и производительность

[ui-smoke.md](ui-smoke.md): 5 страниц × desktop/mobile, 10 проверок, без
горизонтального переполнения страницы, неподписанных controls и JS errors;
клавиатурный focus виден. Офлайн smoke использует синтетические HTML и существующий
DS CSS; бизнес-запросы проверены functional tests.

[performance.md](performance.md): EXPLAIN на 10000 синтетических движений,
100 контрагентах и странице 50 строк — 42.728 ms в локальной среде.
Это измерение тестовой среды, не SLA production.

Итоговые общие gates и review фиксируются отдельно в [handoff.md](handoff.md).
