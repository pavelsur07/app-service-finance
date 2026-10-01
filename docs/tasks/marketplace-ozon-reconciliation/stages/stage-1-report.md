# Stage 1 Report: домен и хранилище снимков

Сделано: enum блока/проверки/статуса, `OzonReconciliationBlockMap`, `OzonReconciliationTolerance` (Money, знаковая разница, 1 ₽),
Entity `OzonReconciliationRun`/`OzonReconciliationLine`, репозитории, Builder'ы, миграция `Version20261001100000`, `ARCHITECTURE.md`.
Проверки: unit `OzonReconciliation*` 22 OK; integration репозиториев 4 OK (изоляция по компании, удаление чужим `companyId` = 0);
`tests/Unit/Marketplace` 781 OK; `tests/Integration/Marketplace/Repository` 18 OK; PHPStan изменённых файлов — 0 ошибок; cs:check — 0;
миграция up/down/up на тестовой БД, `doctrine:schema:update --dump-sql` по новым таблицам пуст.
Internal review (diff от stage_base_commit): BLOCKER/IMPORTANT нет.
- bigint у Doctrine гидратируется строкой — по прецеденту `MoneyFundMovement` свойства `string`, геттеры отдают `int` (исправлено после PHPStan).
FOLLOW-UP: история прогонов (сейчас один актуальный снимок на период).
