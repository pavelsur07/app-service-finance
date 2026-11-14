# Checkpoint — финальная публикация и CI

Рабочая копия /tmp/vf-payment-intake-pilot; ветка feat/payment-intake-tretyakova-pilot; PR3001, base master. Исходная копия владельца с AGENTS/CLAUDE и посторонними файлами не менялась.

## Готово
- Phase0/TASK/план и ТЗ/чек-листы трёх этапов. Stage1 commit12eca1d07; Stage2 c5cd1ed88; финальные исправления44139affe. Следующий коммит — только итоговые документы.
- Scope/guards, profiledworker/cron, DeepSeek-only route. ОбщиеAIgrants/финансовые правила/бюджет30/900 не менялись.
- Полный code CI43608542423 SUCCESS на c5cd1ed88. Локальные полные CS/strict0/4242 и unit4804/26736 PASS; модуль191/1315; Pythonunit545/1skip + integration/eval69/1skip; runtime10 и все focused checks PASS.
- Fresh Task1/Task2/wholebranch INTERNALREVIEW_GREEN B0/I0/M0. Родитель I1+M1fixed.
- External Claude medium round1 exactREVIEW_GREEN B0/I0/M2; оба исправлены44139affe, RED/GREEN сохранён, scopedfixreviewGREEN. No externalre-run needed(noBLOCKER).
- Production pilot не включался; paidbenchmarks не запускались. Частный UUID и preflight — только .superpowers/sdd/plan/pilot-runtime.json; компания однозначно найдена, AIgrant и активная касса есть, binding безactor.

## Точное следующее действие
Commit/push итоговых документов, дождаться CI ТОЧНОГО последнегоHEAD, обновить PRbody по фактам, Ready. Затем единственный конкретный запрос merge/deploy#3001 с companyscope/flag/worker+cron и двумя deploy. Ранее #2985 approval исключало бизнес-пилот, не подменять им новое release решение. После named approval выполнить всю pipeline до postdeploy без новых вопросов.

Два deploy: сначалаflag0+единственныйсогласованныйUUID -> новыйAIimage/routeverify, затемflag1 -> deploysameSHA. Миграций в задаче нет. Постпроверки только vf-prod-codex wrappers; ручнойSQL/consume/recovery/Telegramtest/новыйpaidbenchmark не нужны. Обязательный rebind делает сам пользователь через /integrations/telegram, затем /set_cash и реальные сообщения с ручнымConfirm. LiveE2E/holdout ещё не приняты; heartbeat/GlitchTipreceipt отдельныеfollowups. Старый nightlyfailedreport не удалять и не переигрывать текущуюдату.

## Артефакты
Игнорируемый workspace .superpowers/sdd/plan содержит progress.md, task-1/2 reports/reviews, final-internal-review.md, final-fix-review.md, RED/GREEN logs, final-external/{review.txt,diff.patch,prompt.txt,stderr.log}, pr-body.json. PHP runner vf-payment-intake-pilot-php монтирует текущую копию и rootvendor. Все implementer/reviewer агенты завершены, внешний CLI завершёнGREEN. Старые только task-owned PHP runners остановлены; чужие контейнеры не менялись. ХостRAM ограничен/fullswap, полномасштабные Stan/integration подтверждать exact-head CI, не запускать неограниченно локально.
