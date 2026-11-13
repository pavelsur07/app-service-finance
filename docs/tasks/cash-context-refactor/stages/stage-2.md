# Stage 2 — контекстные папки

**Status:** DONE. **Risk:** MEDIUM. **Stage base:** `88c042cb7`.

73 Action/service classes перенесены по карте `moves.json`. AddCashTransactionAction оставлен в прежнем namespace для защищённого теста балансов. Обновлены только namespace/import/type/static/PHPDoc ссылки, baseline paths и документация. Защищённые пути 1С неизменны.

## Проверки
- Module + mixed guard: PASS, 1025 tests / 4093 assertions.
- DI lint: PASS. Tagged CashCategorizationResultConsumer зарегистрирован в новом namespace.
- Focused PHPStan: первоначально 15 ошибок относительного DTO; исправлена явная ссылка MoneyAccountBankLinkInput, narrow rerun exit0. Остальных ошибок первоначальный прогон не обнаружил.
- Focused CS всех 211 изменённых PHP files: exit0; import ordering исправлен. Final SaveMoneyAccountAction CS: 0 fixes.
- Protected paths: PASS. git diff --check: PASS.

## Review
Свежая независимая сессия review_context_moves: BLOCKER 0 / IMPORTANT 0 / MINOR 0. 73 implementations сопоставлены один-к-одному; нормализованные тела меняются только разрешением типов и PHPDoc whitespace. Нет старых FQCN в executable/config/tests, нет path/reflection/serialization hazards. Baseline только expected rename replacements: 3260 исключений, без роста.
External review не требуется для MEDIUM Stage (0 раундов); обязательный Large handoff остаётся.

## Далее
Stage 3: файловый импорт, сохраняя protected CashFileMappingValidator/Page и отдельные legacy CSV modes.
