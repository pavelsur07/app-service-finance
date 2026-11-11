### Stage 2: build/release integration и handoff — DONE

Risk: HIGH-LOCAL в пределах явного поручения production Docker/CI/Traefik;
production mutation only after merge+deploy approval.
Stage base commit: `d5cfcecfc`; task base: `1d0383a4f`.
Work items: 2.1, 2.2.

#### Result
- SPA path filters: apps, packages, kit, runtime, helper и workflow. Общие
  packages также активируют старый frontend build/lint.
- spa-checks гейтит typecheck/lint/MSW/build; PR matrix selective, master/
  dispatch full 9-image SHA set. Docker loads image, настоящий browser smoke
  выполняется до registry push. Последующая ошибка не публикует SPA image.
- Deploy pre-pull включает account/admin; их up --wait перенесён после
  существующих workers/fetchers и перед final bulk up. Ошибка readiness
  блокирует завершение, не обрывая старый rollout между FPM и workers.
- Runner перенесён из промежуточного site/bin в bin/: frontend-only изменения
  runner не должны запускать backend CI. Make front-prod-build/test с APP.
- Документация apps/README.md: deployment, DNS, image/runtime boundaries,
  первые GHCR packages и проверка доступа через pre-pull до service updates.

#### Checks / evidence
- До реализации: 10 failed matrix/filter assertions; после — 6/6 release
  contract tests (actual Bash matrix) + deploy stdin/worker guard passed.
- Публикация до browser smoke и SPA before-workers order — отдельно RED→GREEN.
- Старый deploy test baseline FAIL (sync уже в раннем шаге 0); теперь проверяет
  sync before FPM и прочие workers в 4/5. Parser SIGPIPE при early awk exit
  устранён потреблением полного input.
- Counterfactual APP=account IMAGE=admin: 2 identity tests failed, как ожидается;
  правильные images: 4/4 each passed, desktop/mobile, total 8.
- front typecheck/lint/Prettier/MSW13 per app passed после review fixes.
- Оба Docker builds passed; no API calls/external requests из заглушек.
- Actionlint 1.7.12, bash -n, git diff --check passed.
- Handoff make site-stan/site-cs-check/site-cs-strict-types/site-test-unit:
  all passed; fixer 0/4027 each, unit 4611 tests/25547 assertions.
- Full PHP integration suite не запускалась: новых PHP integration paths нет.
- DNS публично разрешает оба домена в адрес существующего app; локальный
  resolver ещё negative cached. Реальный HTTPS/redirect smoke — после deploy.

#### Reviews
- Fresh-session internal complete task diff: B0/I0/M0/F0. Self-review добавил
  тест идентичности app; его отрицательный сценарий и правильные images проверены.
- External Claude Code, medium, round 1: I1 + M2, B0. Все исправлены:
  rollout ordering, frontend runner location, first registry-pull prerequisite.
- IMPORTANT ordering fix: regression RED→GREEN + all 6 contracts and browser
  suite; self-review clean. Fixed without re-run per AGENTS §7.2, no BLOCKER.
- Supplemented --context полный diff apps/infra/workflow/tests, поскольку
  стандартные paths скрипта этих путей не перечисляют.
- Metrics: internal BLOCKER 0 / IMPORTANT 0; external BLOCKER 0 / IMPORTANT 1
  fixed (+2 MINOR fixed); open B/I 0.

#### DoD / risks
Все пункты Stage 2 выполнены. Новые images/routers готовы к штатному release;
авторизация, API, БД, migrations/workers implementations и старый app route
не менялись. Доступ production Docker к новым GHCR packages проверяется
начальным pull после master build; registry access из исходников неизвестен.
Live CI status доступен на PR #2967; TLS проверяется после owner-approved deploy.
Next: Ready PR #2967, owner merge+deploy decision.
