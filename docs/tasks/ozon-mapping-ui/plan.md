# План: настройка Ozon
Base c5485f18f. Large: UI + scoped mutation endpoint; 1 task/branch/Draft PR.
Phase 0: прочитаны CLAUDE/frontend, Marketplace auto mapping/preview в ARCHITECTURE,
query/action/controller patterns, существующие tests/fixture/default modal.
Baseline: профильные mapping tests в отдельном compose, ниже в checkpoint.

## Stage 1: backend действия и защиты
Risk HIGH-LOCAL. Base c5485f18f.
DoD: единая existing-conflict query возвращает детали; guarded scoped transaction
отключает только legacy при активном replacement на той же статье; CSRF+write endpoint.
Проверка create/edit/toggle запрещает повторно включать legacy на статье с СПП либо
параллельный конфликт на одной статье; другие marketplaces без изменения поведения.
Work items: 1.1 red/green repair endpoint; 1.2 red/green save guards; focused gates.
Review: company isolation, CSRF, permissions, race, idempotence, no silent retarget.

## Stage 2: интерфейс только Ozon
Risk MEDIUM. Base записать перед стартом.
DoD: понятные Ozon labels в таблице/формах/modal, единый alert+confirmation repair,
четыре понятных типа через существующие tabs, source descriptions и realization status.
Work items: 2.1 labels/status; 2.2 confirmation/action + UI tests.
Checks: functional, Twig, modal JS smoke, lint/build/API types.

## Handoff
Полные PHPStan/CS/strict/unit/test один раз; fresh internal review + external review.
Готовый PR Ready; merge/deploy только после нового согласования этой задачи.
Production настройки не менять при разработке; UI применит repair только после явного клика.
