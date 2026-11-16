# Автомаппинг МойСклад — handoff

PR: https://github.com/pavelsur07/app-service-finance/pull/3023
Branch: feat/moysklad-auto-mapping. Original base0658c9fc6, final review basecd19c36b (master интегрирован).

## Что получает пользователь
В существующих legacy-вкладках Ozon/Wildberries кнопка поиска соответствий для текущих25 листингов. Правила: точный собственный артикул; артикул родителя и размер по явно выбранному ID характеристики. Регистр/ведущие нули/NBSP сохраняются. Preview показывает основания, причины отказа, выбранный ID, предложенную цель и текущее состояние; применяются выбранные READY строки.

Архивные дубли, непроверяемые/пустые размеры, неоднозначные/обрезанные кандидаты не дают автоматическую связь. Existing/needs_review/clear и inactive не перезаписываются. Каждая запись повторно проверяется под catalog/source locks, expectedRevision0, устойчивым requestId. Повтор accepted запроса не восстанавливает последующий manual clear.

Preview/result private Redis cache TTL15min, максимум3 preview/3 attempts; full session/company/actor/connection/provider binding. Реальные Redis faults→503, потерянный результат не выдаётся за rollback. Partial acceptance и актуальная история показаны отдельно. Import-vs-auto collision повторяется штатно; duplicate import сохраняет skip.

Контексты Catalog/Finance/Inventory/FIFO/отчёта комиссионера не изменены. No schema migration, новых зависимостей, фоновых jobs или live API side effects.

## Файлы
- site/src/MoySklad: Domain policy, DTO/Enum, bounded Query, cache/locks, guarded существующий writer, batch Action, формы/контроллеры.
- site/src/Marketplace/Facade/MarketplaceFacade.php и Infrastructure/Query/ListingMetaQuery.php: scoped fresh locking metadata read, caller transaction.
- site/config/packages/cache.yaml, services.yaml, services_test.yaml; site/templates/moy_sklad/mappings/.
- Unit/Integration/Functional MoySklad, MarketplaceFacade tests, exact Company route-policy coverage; два local/test worker fixture.
- ARCHITECTURE.md, TASK/plan/checkpoint и stages/stage-1..4.md.

## Проверки
- Final unit4879 tests/26926 assertions PASS.
- Module491/2114 PASS + последняя private-logger regression8/31 PASS. Focused PHPStan/CS48 task PHP files PASS.
- Full PHP CS/strict types/Twigcs/lint PASS. Browser JS/no-JS1440/375, keyboard, long names, sameSKU sizes, partial conflict, READ-only/empty/unready/error404: PASS, overflow0/page errors0.
- Реальные PostgreSQL/Redis races, partial technical failure/closed EM/fresh GET/retry, cache-after-commit503, company switch/CSRF/tampered selection, WRITE-removal mutation и lock-removal mutations проверены.
- Local50k query:131.78ms, EXPLAIN151.785ms, одна bounded SQL; это локальные fixtures, не production SLA или покрытие каталога.

## Честные ограничения общих gates
Full composer test прогнан один раз:9357 tests/52487 assertions;3 старых Ozon5s worker timeouts под RAM pressure,2 failures (READ cache-preview route-policy и test-only barrier timeout). Все5 адресно перепроверены GREEN, затем module GREEN; полный9357 повторно не запускался.
Full PHPStan:9 ignore.unmatched в неизменённых baseline путях Cash/Ozon/WB/Telegram. Baseline не менялся и не расширялся; все48 task PHP files проходят. Унаследованный master CI static analysis успешен. Это локальные pre-existing gate diagnostics, не скрытая зелёная проверка.

## Review
Fresh internal review и focused follow-ups завершены; external Claude CLI3 раунда всего (Stage1, Stage3, handoff). Все подтверждённые замечания исправлены и проверены; external IMPORTANT — fixed without re-run согласно AGENTS§7.2, ни одного external BLOCKER.
Метрики B/I: internal1/3, external0/5; open0/0. Reviewer code-only, фактические tests/browser/EXPLAIN выполнял implementer. Подробности в Stage Reports.

## Выпуск
В PR нет миграции. Production выпуск выполняется только после отдельного решения владельца: merge into master с автоматическим deploy и post-deploy acceptance. Готовая команда согласования: merge and deploy #3023.

## Follow-ups вне v1
Баркоды МС, постоянные настройки правил, размер/цвет aliases, обработка всего каталога, индексы после реальных замеров покрытия. Новые финансовые документы/FIFO требуют отдельной задачи.
