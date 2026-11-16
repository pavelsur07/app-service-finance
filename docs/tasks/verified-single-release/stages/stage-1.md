# Stage 1: доверенный атомарный релиз — DONE

Risk HIGH-LOCAL; stage_base_commit da7cef655. Work items 1.1–2.2 объединены
в интегрированный Stage: workflow trust и production зависят друг от друга.

Реализованы SHA gate, доверенный manifest с provenance каждого digest,
переиспользование полного комплекта/успешных builds partial rerun, один
production job/flock, backup всех non-template БД, migrations/schema gates,
anti-downgrade и same-version retry, digest override для 20 services,
app-only rolling/health без инфраструктурных restart/upgrade.

Tests RED→GREEN: отсутствующие helpers; foreign descriptors/ancestry;
backup/migration/schema failures; tempnam-independent SSH stdin helper mains;
disabled-health workers, enabled eval-worker; partial rerun descriptors;
malformed manifest; last-success только после health; lifecycle cleanup.

Проверки: 12 bundle + 8 production + 8 actual SSH workflow + 1 opt-in real
PostgreSQL tests; 6 existing runtime + 6 frontend contracts; shell worker/stdin
инварианты; actionlint 1.7.12; git diff --check. Все отдельные последние
прогоны GREEN. Был concurrency-test timeout во время host OOM/сильного thrash;
изолированный диагностический повтор после окончания PHPStan GREEN ~3s.

Internal fresh: исходно B0/I0/M1, затем I1 enabled nightly worker health;
собственный review: malformed artifact I1. Всё исправлено с regression proof.
External: round1 B1/I3/M1; round2 B1/I1/M2; round3 REVIEW_GREEN. R3 MINOR bootstrap stdin исправлен RED→GREEN без повторного external.
Подтверждённые findings исправлены. Указание добавить backup retention
отклонено как противоречащее явному исключению утверждённого плана;
восстановлен существующий image prune, временные конфиги очищаются после health.
Production-права backup path не проверялись и не расширялись.

Fresh final internal B0/I0/M0; дополнительная реальная 404 форма registry miss проверена RED→GREEN, auth ошибки остаются фатальными. Открытых code findings нет. Stage закрыт, Stage2 — handoff с ресурсным ограничением общей local acceptance.
