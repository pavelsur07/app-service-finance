# Stage 2: полная приёмка — BLOCKED BY ENVIRONMENT

Risk MEDIUM; stage_base_commit 5a6456c03. Work items 2.1, 2.2.

Реализация и инструкция завершены. Fresh final internal review: B0/I0/M0.
External: 1 round REVIEW_GREEN; B0/I0, 5 MINOR закрыты (stdin, повторный сигнал,
terminal process group, CI regression hook, docs). Независимый config/Scheduler
regression подтверждает один main analyser с cache вместо второго PHP worker.

Проверки и точные результаты — ../measurements.md. Все PHP-наборы отдельно
GREEN; два полных cold завершились доказанным host global OOM на PHPStan.
Сначала выполнен RCA, затем существенно другой подход (без child workers).
Оба не снизили level/baseline/paths/2GB и не расширяли права/ресурсы хоста.

Definition of Done: runner/tests/docs/review выполнены; три последовательных
успешных full runs НЕ выполнены. Stage не закрывается как DONE; PR остаётся Draft.
Production не вызывался; merge/deploy не запрашиваются до выполнения критерия.
Следующее действие — достаточная свободная память хоста, затем cold+warm+warm.
