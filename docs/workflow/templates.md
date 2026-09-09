# Шаблоны рабочих документов

Когда какой документ нужен — `AGENTS.md` §4 (таблица классификации). Small-задачи
и Fast Path документов не создают: отчёт — описание PR. Large-задача держит
всё в `docs/tasks/<id>/`: `plan.md`, `checkpoint.md`, `stages/stage-<N>.md`,
`handoff.md`.

## plan.md

```md
# <id>: <цель задачи>

Baseline: `<команда>` — <результат, pre-existing failures>

## Stage 1: <интегрированный результат>
Risk: LOW | MEDIUM | HIGH-LOCAL
stage_base_commit: <записать перед первым Work item>
Definition of Done:
- <наблюдаемое поведение>
- <обязательные тесты>
- <документация: ARCHITECTURE.md при новом Facade/Enum/Entity>
- <что явно исключено>
Work items:
- 1.1 — <шаг>
- 1.2 — <шаг>
Stage checks:
- <команды>
Reviewer focus:
- <на что смотреть ревьюеру>

## Stage 2: ...
```

Ориентир — 2–5 Stage. Идентификаторы `1.1`, `1.2.1` — Work items, не Stage.
Backend и frontend — в разных Stage.

## checkpoint.md

```md
## Current checkpoint

**Phase:** Stage N / Work item N.M / handoff / stopped
**Status:** planned | implementing | checking | reviewing | fixing | stopped | done
**Stage base commit:** <commit>

### Completed
- ...

### Checks and baseline
- `<команда>` — <результат>

### Review status
- internal: iteration <n>, open: none
- external: round <n>, result: REVIEW_GREEN | fixed without re-run | pending

### Exact next action
- ...

### Files to inspect first on resume
- ...
```

Обновлять после каждого Work item, каждого Stage, перед каждым STOP и перед
концом незавершённой сессии. При возобновлении сначала сверить с `git status`.

## stages/stage-N.md — Stage Report

Только для верхнеуровневого Stage Large-задачи. Репозиторный checklist перед
заполнением — `docs/workflow/stage-report.md`.

```md
### Stage <N>: <title> — DONE

**Risk:** LOW | MEDIUM | HIGH-LOCAL
**Stage base commit:** `<commit>`
**Work items:** N.1, N.2, ...

#### What was done
- ...

#### Files changed
- `path` — new/modified

#### Definition of Done
- [x] ...

#### Checks
- baseline: `<команда>` — <результат>
- targeted / module / full: `<команда>` — <результат>

#### Internal review
- iterations: <n>; BLOCKER/IMPORTANT: none; MINOR fixed: ...; FOLLOW-UP: ...

#### External review
- required: yes (HIGH-LOCAL) | no (LOW/MEDIUM — internal only)
- rounds: <n>; result: REVIEW_GREEN | fixed without re-run
- confirmed fixed: ... ; rejected with reason: ... ; reviewer limitations: ...

#### Risks / reviewer focus
- ...

#### Next
- continue to Stage <N+1> automatically | handoff
```

### Фронтовый Stage — отличия

Чеклист перед заполнением — `docs/workflow/stage-report-frontend.md`. В блоке
Checks перечислять реально существующие команды: `npm run lint`,
`npm run build`, `npm run check:ui-kit`, `npm run check:uikit-react-mapping`,
при изменении контракта `npm run api:types:check`. Скриптов `typecheck` и
`test` в проекте нет, их прогон не заявлять. Вместо автотестов записать, что
именно проверено ручным smoke. Дополнительно указать новые Vite entry и точки
монтирования, изменения UI Kit с записью в CHANGELOG и размер бандла до и
после.

## handoff.md

```md
# <id> — handoff

**Branch:** `<branch>` · **PR:** <url> · **CI:** <status>

## Summary of stages
- Stage 1 — ...
- Stage 2 — ...

## Files changed
- ...

## Migrations
- <файл> — reversible | irreversible, backup/rollback plan: ...

## Public API / contract changes
- none | ...

## Checks
- `<команда>` — <результат>

## Reviews
- internal: iterations <n>
- external: rounds <n>, result ..., rejected with reason ...

## Risks, limitations, follow-ups
- ...

## Owner decision
Ready: PR #<n> "<title>" — merge into master with automatic production deploy?
Reply: "merge and deploy #<n>"
```

## STOP-сообщение

Только для условий `AGENTS.md` §3.4.

```text
STOP — owner action required

Completed:
- ...

Reason for stopping:
- ...

Recommendation:
- ...

To continue, reply:
"<точный ожидаемый ответ>"
```

При нескольких вариантах — рекомендовать один и дать готовый ответ для каждого.
Ответ должен быть достаточным, чтобы не задавать тот же вопрос второй раз.
