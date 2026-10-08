### Stage 2: документация и prod-флаг — DONE

**Risk:** LOW / MEDIUM
**Stage base commit:** `2fa6307b`

#### What was done
- 08 граница нормализации, 09 архитектура замеров, 10 шаблон baseline, строка M1 в 06, ARCHITECTURE.md 1.98.
- `MARKETPLACE_PERF_DIAGNOSTICS: ${MARKETPLACE_PERF_DIAGNOSTICS:-1}` в x-php-env prod; `site/.env` = 0.
- `app:marketplace:perf-report` в `docs/maintenance/codex-console.sh`.

#### Checks
- факты 08 сверены с кодом (выборочно: realization reprocess, WB safe replace, financeLockBefore).

#### Next
- handoff
