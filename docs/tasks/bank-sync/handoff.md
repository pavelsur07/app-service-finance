# bank-sync — handoff Stage 1

**Branch:** `feat/bank-sync` · **PR:** см. описание PR · **CI:** см. PR

Поставка — Stage 1 из плана (`plan.md` §5). Для пользователя поведение не меняется:
страница «Банки / Подключения» по тому же адресу; Альфа на проде не подключена.

## Summary of stages
- Stage 1 — модуль `BankSync`: банковская интеграция вынесена из Cash; Cash получает
  выписку только через `CashFacade`; импорт дня пересчитывает остатки счёта; токен
  хранится только шифротекстом; путь выписки Альфы исправлен на `/api/jp/v1/…`.
  Подробно — `stages/stage-1.md`, «Итог этапа».

## Files changed
- `site/src/BankSync/**` — новый модуль (адаптер Альфы, сущности, handler, команда, UI).
- `site/src/Cash/{Application,Facade,Repository,Exception}` — контракт загрузки выписки;
  старый банковский код Cash удалён.
- `site/migrations/Version20261026120000.php`, `site/templates/bank_sync/**`, сайдбар,
  `config/{routes,packages/doctrine,packages/twig,packages/messenger}.yaml`,
  `ModuleAccessMap`, `ModuleBoundaryRules`, `phpstan.dist.neon`, baseline (−19).
- `ARCHITECTURE.md` 2.76, `site/src/Cash/README.md`.

## Migrations
- `Version20261026120000` — создаёт `bank_sync_connection`, `bank_sync_import_cursor`;
  удаляет `cash_bank_connection`, `cash_bank_import_cursor` (на проде пусты, проверено
  26.10; миграция блокирует их и останавливается, если строки появились). Обратима:
  `down` возвращает старые таблицы пустыми. Бэкап не нужен — данных нет.

## Public API / contract changes
- `CashFacade`: `findStatementImportAccount`, `tryLockAccountForStatementImport`,
  `releaseAccountStatementImportLock`, `startStatementImportLog`,
  `finishStatementImportLog`, `abandonStatementImportLog`, `importBankStatementDay`.
- Messenger: `SyncBankConnectionMessage` → `async_sync` вместо `BankImportMessage`
  (очереди на проде пусты).
- Команда `cash:bank:enqueue` → `bank-sync:enqueue --bank=alfa` (в cron нет).
- Маршруты `cash_bank_connection_*` → `bank_sync_connection_*`, пути прежние.

## Checks
- PHPStan полный — 0 ошибок (до rebase); после rebase — CI.
- Unit 4009 — OK; integration + functional 3199 — OK (до rebase); после rebase
  BankSync + Cash integration/functional 455 — OK.
- CS, `strict_types` по изменённым файлам — чисто; миграция up → down → up, abort при
  строках — OK.

## Reviews
- internal: найден и исправлен курсор нового счёта (сохранялся без блокировки после сбоя).
- external: 2 раунда; раунд 1 — гонка проверки пустоты в миграции (исправлено),
  раунд 2 — REVIEW_GREEN.

## Risks, limitations, follow-ups
- Ручной smoke на стенде не выполнялся — заменён functional-тестом полного сценария.
- `CashTransactionsChanged` из импортов выписок — FOLLOW-UP для всех импортов.
- Дальше Stage 2 (ротация ключа шифрования), затем OAuth/mTLS (Stage 3–4).

## Owner decision
Ready: PR с миграцией `Version20261026120000` (обратима, данных не трогает, бэкап не
нужен). Run the production migration and deploy?
