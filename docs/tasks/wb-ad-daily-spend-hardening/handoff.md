# Final handoff: WB daily advertising spend hardening

Task base commit: `44b28304d27c5e1025e8c29230d75b928a36681f`
Branch: `codex/wb-ad-daily-spend-hardening`
Draft PR: <https://github.com/pavelsur07/app-service-finance/pull/2233>

## Итог

- Persisted reconciliation связывает `/upd` source total с
  `AdDocument.totalCost` и `AdDocumentLine.cost`, отдельно показывая
  intentional `__unallocated__` и реальные расходы без listing line.
- Неизвестный `nmId` запускает ровно один refresh WB-каталога и одну
  перепроекцию того же raw document без повторных рекламных API-запросов.
- Неразрешённый `review_required` создаёт один агрегированный normal-channel
  `ERROR` с маркером `wb_ad_spend_review_required`.
- WB Promotion API имеет не более трёх попыток на запрос для 429/5xx,
  поддерживает `Retry-After` и ограничивает ожидание.
- Production CLI image больше не пытается загрузить сломанный opcache;
  PHP-FPM не изменён.

## Финансовые инварианты

```text
/upd source total = AdDocument total
AdDocument total = AdDocumentLine total + documents-without-lines total
documents-without-lines total = __unallocated__ total + unmapped-nmId total
/upd-derived __unallocated__ total = persisted __unallocated__ total
```

Все сравнения выполняются через `Money` в minor units, без `float`.
Несовпадение переводит raw document в `DRAFT`, сохраняет audit trail и делает
команду неуспешной. Намеренный `__unallocated__` видим, но сам по себе не
создаёт `review_required`.

## Проверки

- Stage 1 focused: 17 tests / 117 assertions, green.
- Final MarketplaceAds unit: 346 tests / 2203 assertions, green.
- Final MarketplaceAds integration: 173 tests / 697 assertions, green.
- Task-scoped PHP CS Fixer and PHP lint: 11 PHP files, green.
- Symfony container lint and `git diff --check`: green.
- Production CLI image build and complete runtime smoke: green, opcache
  warning отсутствует.
- `make site-test` blocked before PHPUnit by pre-existing
  `bot_links.updated_at` test-schema drift.
- `make site-cs-check` reports 585 pre-existing repository-wide violations;
  task-owned PHP files are clean.

## Reviews

- Stage 1 internal and external reviews: green.
- Stage 2 internal and external reviews: green.
- Stage 3 internal review: green.
- Stage 3 external review: three iterations, final `REVIEW_GREEN`; no
  unresolved BLOCKER/IMPORTANT.
- Final whole-task internal review from
  `44b28304d27c5e1025e8c29230d75b928a36681f`: green.
- Final whole-task external review: initial 40-turn attempt was retried with
  the prescribed narrowed 80-turn scope; result `REVIEW_GREEN`, no
  BLOCKER/IMPORTANT findings.

## Совместимость и scope

- Нет Doctrine migration в этой hardening-задаче.
- Финансовый источник, знаки, allocation/rounding и 06:15 MSK D-1 schedule
  не изменены.
- Ozon Ads, public HTTP API, production PHP-FPM и Messenger routing не
  изменены.
- Owner-owned dirty/untracked files не включены.

## Follow-up

- Отдельно проверить и при необходимости исправить opcache в production
  PHP-FPM image; это не доказанная часть текущего production incident и не
  входило в Stage.
- Решение об удалении компиляции opcache из CLI image — отдельная
  инфраструктурная оптимизация.
- Rolling 7-day/month-close WB refresh остаётся отдельной операционной
  политикой после накопления статистики корректировок.

## Release Gate

Draft PR остаётся Draft. Решение Владельца после зелёного CI:

`Разрешаю перевести Draft PR #2233 в Ready for review. Production Gate не разрешаю.`

## Production Gate

Production Gate не выполнялся и не разрешён. Отдельного явного разрешения
требуют merge/deploy, production health check и любой live WB rerun.
