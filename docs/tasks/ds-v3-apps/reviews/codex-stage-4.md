# Stage4 — reviews

Base d99ede00de60e210d373feea8b80e371daa521e4; branch feat/ds-v3-stage-4.
PR https://github.com/pavelsur07/app-service-finance/pull/3037.

## Independent internal

Свежая session с task/spec/full diff/checklist; без истории реализации,
read-only. Проверены runtime/route/keyboard/focus/storage/scroll/native refs,
source App-Layout и full/rail screenshots. INTERNAL_REVIEW_GREEN, B/I/M0/0/0.
После scoped logout correction — полный актуальный diff ещё раз сверён,
INTERNAL_REVIEW_GREEN, новых B/I/M0/0/0. Сам reviewer тесты не запускал.

## Author findings — IMPORTANT2fixed

- Resize auto-rail→expanded скрывал expand button с фокусом. Chromium
  regression RED→GREEN, симметричный transfer focus в AppShell;21browserPASS.
- Общий state.busy ошибочно блокировал logout при background session refresh.
  Store намеренно позволяет logout при busy; прежний header не блокировал.
  Убрана только связь busy→logoutBusy в Layout обоих apps, без изменений
  store/API. Regression REDdisabled→GREEN both apps desktop/mobile4PASS;
  final nginx smoke также включает этот сценарий.

## Author finding — MINOR1fixed

- Utility layer `scroll-stable` перекрывал component rail scrollbar rules,
  оставляя gutter в72px. Класс применяется только expanded; rail получает
  auto/none. Browser assertion RED→GREEN, полный fixture21PASS. Независимый
  focused review: INTERNAL_REVIEW_GREEN, новых B/I/M0/0/0, open0.

## External Codex — round1

Claude CLI отсутствует; fallback по AGENTS §7.2. site/bin/external-review.sh
не охватывает apps; wrapper/site не менялся. Отдельная fresh ephemeral session
`codex exec -s read-only --ephemeral -c model_reasoning_effort=medium`, timeout900.
Переданы полный diff с новыми файлами, source spec/checklist/DS-Layout/App-Layout/
ds-kit.css из Git3882c5dd9, текущие токены/стили/компоненты и факты проверок.

Ограничение: stdin-only static analysis (bwrap read-only shell ранее подтверждённо
недоступен), tools запрещены, независимых запусков tests/lock/binary нет;
секретов/environment/production данных нет. Exact output, exit0:

```text
REVIEW_GREEN
```

B/I0/0, rounds1. После внешнего GREEN исправлены busy→logoutBusy binding
и scoped rail CSS layer, добавлены regression assertions/docs. Обе правки
получили focused independent internal GREEN и фактический RED→GREEN;
повторный внешний раунд не нужен по §7.2.
Артефакты локально: /tmp/vf-ds-v3-stage4/external.

Review metrics: internal BLOCKER0/IMPORTANT2(author, fixed), independent
reviewer0/0/0; author MINOR1fixed; external BLOCKER0/IMPORTANT0, rounds1REVIEW_GREEN; open0.
