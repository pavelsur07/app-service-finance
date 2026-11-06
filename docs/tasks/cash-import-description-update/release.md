# Выкладка: миграция и совместимость worker/web

## Подтверждённый release BLOCKER

Текущий `.github/workflows/deploy.yml` запускает новый PHP-FPM до обновления `site-messenger-worker-sync`. Новый UI создаёт прежний тип `CashFileImportMessage` с versioned `description_plan` в options. Старый handler не проверяет этот ключ и импортирует весь файл по legacy-дедупликации, включающей описание. В окне между обновлением web и worker он способен создать дубль вместо подтверждённой замены. Это проверено по handler базы `cfb6728d` и порядку deploy, а не предположение о внешнем окружении.

Минимальное решение подготовлено в [release-worker-order.patch](release-worker-order.patch): существующий `site-messenger-worker-sync` обновляется с `--wait` перед FPM и исключается из позднего общего `up`. Новый handler совместим со старыми jobs. Другие workers, scheduler, transports, credentials и grace period не меняются. Patch прошёл `git apply --check` и применён после явного разрешения владельца.

Изменение CI/CD и поведения production workers требует отдельного именованного разрешения по AGENTS.md §3.3. Владелец дал это разрешение вместе с migration/merge/deploy; consumer-first patch применён и независимо проверен, BLOCKER закрыт. Обходить существующие production wrappers или запускать worker-команды вручную нельзя.

## Разрешённая pipeline

1. Применить подготовленный patch, проверить diff/YAML и consumer-first порядок, провести focused review; commit/push в тот же PR.
2. Интегрировать актуальный master, дождаться требуемых CI gates; при зелёных проверках закрыть release BLOCKER и Stage4, Ready.
3. По одновременно данному разрешению merge+production migration+deploy слить PR. Обычный push deploy должен остановиться на pending migration; не обходить gate.
4. Dispatch `deploy.yml --ref master -f production_action=migrations`, дождаться результата, проверить схему read-only.
5. Dispatch `deploy.yml --ref master -f production_action=deploy`, дождаться результата. `async_sync --wait` должен завершиться до обновления FPM; неуспех останавливает rollout.
6. Read-only postdeploy acceptance через разрешённые wrappers: актуальная миграция, image/process нового sync consumer, здоровье web/очереди. Никаких production test imports/backfill/recalc без отдельного named approval.

Существующий stop_grace_period60s сохранён. Успешное завершение длинного сообщения до остановки не гарантируется; восстановление/повторная доставка используют текущую идемпотентность. Process healthcheck не доказывает бизнес-результат, поэтому нужны CI legacy/new-job тесты и postdeploy проверка версии.

## Миграция

`Version20261106120000` добавляет `cash_transaction.imported_description_overridden BOOLEAN DEFAULT FALSE NOT NULL`. Старые строки получают false; суммы, тексты и финансовые связи не переписываются. Local UP→DOWN→UP проверен. Миграция структурно обратима, но DOWN удалит маркеры защиты уже подтверждённых описаний. При откате кода предпочтительно оставить additive колонку; destructive DOWN не выполнять автоматически. Финансовый backfill и отдельный data backup этой миграцией не требуются; действующая backup policy сохраняется.

## Единый текст решения

Владелец может одновременно явно согласовать обе отдельно названные операции:

> Разрешаю consumer-first изменение deploy из release-worker-order.patch; слить PR #2894, выполнить production-миграцию Version20261106120000 и deploy.

Это разрешение покрывает названное изменение CI/CD и последующую стандартную release pipeline. Разрешение получено в текущем чате; pipeline исполняется по этому решению. Итоговые runs и production acceptance — в PR/финальном отчёте.
