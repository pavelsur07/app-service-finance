# Stage 4 — автоправила и итоговая приёмка

**Status:** DONE. **Risk:** HIGH-LOCAL. **Stage base:** a2e7cc6cd; mechanical move c87db2d79.

## Результат

Work items4.1–4.5 выполнены: coordinator798→132LOC, Matcher267, Planner253, Preview239. Один matching/planning путь для preview и применения, snapshots загружаются пакетно, решения/порядок/финансовые условия сохранены. Небольшие превышения мягкого размера у matcher/planner сохраняют связность. Work item4.6: документы подготовлены, commit/push/Ready выполняются при закрытии Stage; merge/deploy только по owner decision.

## Проверки

- Original-code characterization56/177 PASS; final AutoRule190/791 PASS.
- Unchanged B3 import58/342 + shared77/199 PASS.
- Full unit4656/25723; integration2768/13202; functional1403/10437 — OK. Вместе8827 tests/49362 assertions.
- Full site-stan, site-cs-check и site-cs-strict-types после исправлений PASS.
- Единый make site-test завершился137 после7300+ тестов; полный набор завершён раздельно по suite. Functional shell exit metadata потеряна при restart, полный PHPUnit OK сохранился.
- После последних type/style исправлений74/278 PASS, focused static/style PASS. Protected/DI/diff/ratchet PASS.
- Baseline3260→3238: только удаления устаревших/устранённых предупреждений и допустимые rename replacements; новых suppressions нет.

## Review

Свежий финальный reviewer с входами spec + full diff + checklist: BLOCKER0/IMPORTANT0/MINOR0. Scoped повтор после исправлений гейтов:0/0/0. Авторские отчёты reviewer не читал.
Combined Stage4/handoff external: round1 REVIEW_GREEN, BLOCKER0/IMPORTANT0. MINOR executable bit исправлен; удаление normalizedRows отклонено — FR4 явно сохраняет сигнатуру. Повтор external не требуется по §7.2. Stage3 ранее также получил REVIEW_GREEN.

## Ограничения и доказательства

Все критерии D1–D7 выполнены. Импорт1С/общие protected зависимости неизменны. Не менялись schema, public contracts, routing, flush/locks/events. Подробная матрица — ../acceptance-results.md; диагностика красных гейтов — ../gate-corrections.md.
