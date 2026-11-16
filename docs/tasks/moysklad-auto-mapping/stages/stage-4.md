# Stage 4 — legacy UI и handoff

Base `c5d12a78d`. Risk HIGH-LOCAL. Одна задача, PR #3023.

## Результат
- Три single-action Controller, две FormType, index и automatic_preview Twig на существующем layout/Tabler. Ozon/WB разделены. Новых JS/CSS/React entries нет.
- Явный scoped выбор характеристики по opaque ID; поиск20, source25; CSRF привязан к показанному контексту. Client выбирает только READY IDs, максимум25.
- Preview READ+CSRF меняет только private session cache; Apply READ+WRITE+CSRF. Exact existing ROUTE_POLICY для preview согласован с TASK, доменные записи не разрешает.
- POST303→GET свежий EM/current state; accepted/replay не выдаются за число новых строк или нынешнее состояние. Частичный commit, остановка после закрытого EM, cache-after-commit503 показаны честно.

## Доказательства
- RED4 отсутствующий UI → functional8/68. Финальный module491/2114 PASS; final unit4879/26926 PASS; privacy/cache unit8/31 PASS.
- Полный composer test9357/52487:3 старых Ozon worker5s timeout при дефиците RAM,2 failures (route coverage + искусственный barrier2s). Все5 перепроверены GREEN; targeted13/111 и12/105; финальный module GREEN. Повтор полного9357 не требовался после адресных исправлений.
- Full CS/strict/Twigcs/lint PASS; после последних PHP fixes focused CS/Stan48 task files PASS. Full Stan9 ignore.unmatched только неизменённый baseline (Cash/Ozon/WB/Telegram), baseline не расширялся.
- Browser Chromium JS on/off:1440/375, keyboard, long names,25 строк, WB sameSKU M/L, selected characteristic ID, реальный manual SET→bulk partial conflict, WB apply2, empty/unready/READ-only/error404. Document overflow0, page errors0. Скриншоты просмотрены.
- Browser harness: отдельная app_browser, dummy fixtures; MockFileSessionStorageFactory не читает HTTP cookie, router явно связывает mock session; Docker env переносится в $_SERVER/$_ENV до Dotenv. Оставшиеся2 dummy WB revisions старого прогона сброшены только в app_browser; затем оба режима GREEN. Production не использовался.
- WRITE mutation: снятый production attribute превращает valid-CSRF READ-only POST в303 → RED; attribute восстановлен, module GREEN.

## Review и исправления
- Fresh internal whole-task review: BLOCKER1 blank target size; IMPORTANT1 RedisAdapter fail-open; MINOR1 выбранный ID скрыт. RED→GREEN, focused follow-up open0/0/0.
- Дополнительный self-audit IMPORTANT1: Symfony Lock logger писал resource с session hash. Private lock получает NullLogger, глобальный factory не меняется; regression RED1/2→GREEN8/31, final unit GREEN.
- External Claude final1 round: IMPORTANT1 duplicate sync терял skip; MINOR2 filtered characteristic lookup и valid-CSRF authorization test. Fixed without re-run по §7.2, новый BLOCKER не найден.
- Duplicate sync: отдельный import-intent session lock; другой настоящий импорт→skip без retry/error, auto collision→existing temporary/retry. Actual Action+Handler regression RED1→GREEN; fresh reviewer подтвердил fix.
- Strict native Redis adapter сохраняет общий marshaller/write protocol, false/incomplete/error reads→503, acknowledged index не перезаписывается, keys/session hash не логируются. Real Redis roundtrip/fault/concurrency PASS. Parent prototype размещён после App resource; первоначальная DI override ошибка устранена.

## Метрики и ограничения
Internal по задаче B/I1/3 (self Stage1 Cyrillic search + final Redis read + private logger), external B/I0/5 (Stage1=1, Stage3=3, handoff=1). Все исправлены, open0/0. External раундов3 (по1 на точку), повтор после IMPORTANT не требовался. Reviewer не запускал tests/browser/profile; их выполнил implementer.

Latest master интегрирован merge517317b86, review base cd19c36b; единственный конфликт ARCHITECTURE.md разрешён с сохранением обеих записей, наша версия2.200. Чужие изменения master исключены из task diff.

Evidence: site/var/external-review/moysklad-auto-handoff/verification (ignored). Следующий шаг — один release decision merge+deploy. Миграции в PR нет.
