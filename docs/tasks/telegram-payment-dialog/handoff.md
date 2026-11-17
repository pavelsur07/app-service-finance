# Telegram payment dialog — handoff

Branch `feat/telegram-payment-dialog`, PR https://github.com/pavelsur07/app-service-finance/pull/3051

Первый выпуск замечаний Tony: current/cancel, надёжная остановка, атомарные
общие уточнения, явные реквизиты списка, компактная сводка/детали и читаемые
кнопки. Cash/Telegram и их тесты; подробные пути — PR diff.
Миграций, зависимостей, production config и LLM-вызовов нет. Внутренний DTO
AmendPaymentIntakeDraftCommand дополнен commonText; Cash Facade — open IDs.

Проверки: baseline136/992; итоговый модуль161/1164 PASS; full PHPStan, CS,
strict_types PASS (focused recheck последних fixes выполняется); unit4935/27299
PASS до последних regressions. Full suite9482/53407: один order-dependent
сбой InviteRegistrationFlowTest:67; отдельно PASS1/8, CI final SHA pending.
Локальный root-owned vendor препятствовал composer install: применён только
временный local Compose user override, permissions и production не менялись.
Неудачные environment/diagnostics: gh pr edit GraphQL deprecated projectCards
→ использован GitHub REST connector; doctrine:query:sql отсутствует (SQL не
выполнялся); первая formatter config отличалась от canonical, исправлено.

Review: internal fresh REVIEW_GREEN, B0/I9 fixed total; external round1,
Claude unavailable → Codex, bwrap → corrected static-only stdin retry,
B0/I3 fixed without re-run. Open B/I0. Артефакты review и gates — ignored
site/var/external-review/telegram-dialog, site/var/telegram-dialog-checks.

Использование: «Текущий черновик» / /draft; «Остановить разбор» / /cancel
отменяет несохранённый пакет, при нескольких нужен выбор. Ответ сводке
«дата 14.10.2026; проект Название; отдельные платежи» меняет неисключённые
строки. «Все оплачены» не угадывает источник средств. Короткий год — 20xx,
перед сохранением дата видна. Нераспознанные финансовые вопросы сохраняются
и решаются на карточке строки. Оплата и сохранение — отдельные действия.

Ограничения: уже начатый HTTP может завершиться после отмены; новые шаги
не запускаются. Live Telegram UX и provider accuracy этим выпуском не
измерялись. Ночной eval — контроль, не дообучение. Файловый импорт, учёт
личных средств и ОПиУ остаются следующими задачами согласованного плана.

Owner authorization: «Делай» в текущем чате, AGENTS.md §3.2. После зелёных CI
выполнить Ready → merge → automatic deploy → read-only acceptance без
повторного запроса. Production mutations вне pipeline не разрешены.
