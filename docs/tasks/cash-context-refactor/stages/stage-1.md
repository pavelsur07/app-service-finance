# Stage 1 — удаление подтверждённого мёртвого кода

**Status:** DONE. **Risk:** LOW.
**Stage base:** `1f874580f7b54722f24d9d99117b337f3756e963`.

Удалены declaration-only CashTransactionExternalIdGenerator и createOperationFromTransaction(); wrapper findMatchingRule удалён после перевода двух assertions на match()->rule. Живое создание DocumentOperation и все helpers сохранены.

## Проверки
- Baseline Cash + mixed isolation/Settlement guard: 1025 tests / 4093 assertions, PASS.
- B1 до/после: 40 tests / 157 assertions, PASS.
- Переведённые assertions до удаления wrapper: 39 tests / 152 assertions, PASS.
- PHPStan на двух изменённых сервисах и unit test: exit 0, до/после.
- PHP lint трёх файлов: PASS. Focused CS на AutoRule service: 0 fixes.
- Protected-path script: Protected paths unchanged.
- git diff --check: PASS.

## Внутреннее review
Полный diff проверен: scope, callers, динамическая регистрация, сохранение тестовых сценариев, public Facade unchanged, отсутствие protected diff. BLOCKER 0 / IMPORTANT 0; открытых нет. Новых запросов, транзакций, финансовых правил и событий нет.
Внешнее review не требуется для LOW Stage (0 раундов); Large handoff остаётся обязательным.

## Следующий этап
Stage 2 автоматически. AddCashTransactionAction оставить на месте: protected AccountBalanceServiceTransactionFlowTest импортирует прежний FQCN.
