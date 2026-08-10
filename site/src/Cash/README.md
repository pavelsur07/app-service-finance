1️⃣ ЦЕЛЕВАЯ МОДЕЛЬ МОДУЛЯ CASH (ФИКСАЦИЯ)

Это важно зафиксировать один раз, дальше всё под это правим.

1.1. Границы модуля Cash
✅ Разрешено

Доступ к Cash только через:

App\Cash\Service\* (Application Services)

App\Cash\Repository\* (read/write, но только внутри Cash)

Контроллеры Cash:

НЕ используют EntityManager напрямую

НЕ строят QueryBuilder

Делают: DTO → Service → Response

❌ Запрещено

EntityManager / QueryBuilder / DBAL вне Cash

Прямые Cash* Entity в:

DTO

Controllers других модулей

MessageHandler

Telegram / Admin / AI

Прямые ManyToOne на Cash Entity вне Cash (кроме legacy → выносим в адаптер)

⚠️ Отчёты / аналитика (D)

Cash — источник данных, но:

Только через QueryService / ReadModel

Никаких createQueryBuilder() снаружи

## Мультивалютные счета и переводы

- Единый fiat-контракт Cash — `RUB`, `USD`, `EUR`, `KZT`. Он применяется к
  `BANK`, `CASH` и `EWALLET`; `CRYPTO_WALLET` в переводах не участвует.
- Валюта сохранённого счёта неизменяема, а валюта операции всегда совпадает с
  валютой её счёта. `PaymentPlan` без валюты остаётся RUB-only.
- `CashTransfer` — один агрегат над двумя техническими операциями: исходящая
  нога имеет одну split-строку `CF_TECH_OUT`, входящая — `CF_TECH_IN`. Эти
  системные категории всегда являются детьми `CF_TECH`.
- Same-currency перевод требует одинаковые суммы и не хранит FX metadata.
  Cross-currency v1 разрешает только `RUB↔USD` и `RUB↔EUR`. Пользователь
  передаёт обе фактические суммы; effective rate хранится как точный decimal
  scale 18 в направлении «валюта назначения за единицу валюты источника».
- Комиссия банка проводится отдельной обычной OUTFLOW-операцией. Внешний FX
  provider, автоматический расчёт одной суммы, `USD↔EUR`, переоценка остатков и
  сведение разных валют в RUB не входят в v1.
- Legacy-операции `isTransfer=true` без агрегата не спариваются автоматически и
  сохраняют прежнее поведение.

## Публичные операции и UI

- Создание выполняется только через
  `CashFacade::createTransfer(CreateCashTransferCommand)`; агрегат, обе ноги,
  splits, аудит и пересчёт счетов находятся в одной DB-транзакции.
- Удаление и восстановление выполняются через `CashFacade::deleteTransfer()` и
  `CashFacade::restoreTransfer()`. Отдельная мутация ноги запрещена во всех
  generic edit/delete/restore/split/bulk paths.
- UI расположен под `/finance/cash-transfers`; контроллеры только валидируют
  company scope/CSRF и делегируют финансовую логику фасаду.
- Cash dashboard и Home требуют явной валюты агрегирования; default — `RUB`.
  P&L widgets не зависят от Cash selector.

## Проверка целостности

```bash
php bin/console app:cash:verify-transfers
```

Команда строго read-only, обрабатывает детальные проверки пакетами компаний и
возвращает ненулевой exit code при нарушении pair/company/account/currency/
direction/category/rate/deletion/idempotency/orphan-инвариантов. Уникальность
idempotency key и владения ногами проверяется двумя глобальными aggregate scans,
чтобы видеть нарушения между пакетами. В логи идут только названия проверок и
счётчики — без UUID, сумм и реквизитов. Legacy `isTransfer=true` без агрегата
печатается как INFO и не делает проверку красной. Автоматического repair нет.

## Дерево категорий ДДС

- Обычная категория может быть root; её `flowKind` — `OPERATING`, `INVESTING` или `FINANCING`.
- Дочерние категории наследуют `flowKind` от root. Обычные потомки не разрешены в
  `CF_TECH`, `CF_TECH_IN`, `CF_TECH_OUT` и `CF_UNALLOC`.
- `app:cashflow-categories:migrate-system-structure` создаёт и нормализует только
  канонические системные категории. Она не перемещает обычные root-категории.
- Обычные legacy root с `TECHNICAL` не изменяются автоматически и отражаются в CLI только
  агрегированным warning без UUID, названий и реквизитов.

## Безопасный rollout

1. До production-действий открыть отдельный Production Gate. Проверить
   системную структуру категорий read-only командой
   `app:cashflow-categories:migrate-system-structure --companies-with-accounts`
   без `--execute`. Команда выбирает только компании со счетами ДДС и выводит
   агрегированные счётчики без UUID и реквизитов. В production этот dry-run
   запускается ручным workflow action `category-plan`. Обычные root-категории не входят
   в execute-план; warning о legacy `TECHNICAL` root остаётся read-only.
2. Применить expand-only migration `cash_transfer`, затем развернуть application
   code. Backfill и автоматическое спаривание legacy-операций не нужны.
3. После deploy выполнить smoke создания/просмотра на разрешённой паре и
   read-only `app:cash:verify-transfers`; любой FAIL блокирует дальнейший rollout
   и требует отдельного анализа, а не автоматического исправления.
4. До создания первого агрегата безопасен обычный rollback application code с
   сохранением expand-таблицы. После появления агрегатов нельзя откатываться на
   код без pair-mutation guards: предпочтителен forward fix; при вынужденном
   rollback сначала блокируются Cash write-paths.

Production migration, category `--execute`, deploy, smoke с записью и rollback
всегда требуют отдельного явного разрешения Владельца.
