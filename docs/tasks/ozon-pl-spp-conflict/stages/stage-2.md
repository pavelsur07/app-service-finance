### Stage 2: предупреждения в автонастройке — DONE
Risk MEDIUM; stage_base_commit cd73ff430.
Work item 2.1: отдельный alert с причинами конфликта; блокирующий текст и hint
покрывают источники; sign mismatch и состояние кнопки сохранены.
Checks: 23 functional tests/170 assertions; Twig lint green.
JS smoke исполняет реальный inline-script: conflict/normal/error, disabled button,
textContent (без HTML injection), loading cleanup, existing signMismatch — PASS.
Internal review: 0 BLOCKER / 0 IMPORTANT. Нет новых CSS/entry/dependencies.
Frontend lint/build/API types: green. Build initially EROFS in .vite-temp; isolated writable mount fixed it, no source change.
External: at handoff. Next: Stage 3 runbook and final verification.
