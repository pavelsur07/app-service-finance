# Checkpoint — glitchtip-project-scope

### Текущее состояние
- Ветка: `fix/glitchtip-project-scope`
- `stage_base_commit`: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Stage 1 — DONE, см. `stages/stage-1.md`.

### Ключевой факт
Проекты в GlitchTip уже разведены: `app_vashfindirru` (id 1) — наш, 11 unresolved;
`api_conwix` (id 2) — чужой продукт, 26 unresolved. Смешивала их читалка `gt.sh`,
а не трекер.

### Exact next action
- Ждём решения Владельца по ротации DSN и по HEALTH_CHECK_TOKEN.

### Files to inspect first on resume
- `gt.sh`
- `site/.env`
- `tests/shell/gt-test.sh`
- `site/tests/Unit/Config/CommittedEnvTest.php`
