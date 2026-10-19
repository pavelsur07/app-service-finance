# Stage 4 — гейты, ревью, PR, handoff

- `stage_base_commit`: `d062d7b1f` (база задачи — `ae67bde9c`)
- Риск: LOW — код не меняется, кроме исправлений по ревью.

## 1. Какую задачу решаем

Код этапов 1–3 готов и проверен по частям. Перед вопросом «merge and
deploy» нужно доказать, что вся задача целиком не ломает проект, получить
независимый взгляд на дифф и оформить передачу.

## 2. Что будет результатом

- Полные гейты по одному (память dev-машины): `make site-stan`,
  `make site-cs-check` (и прогон без кеша), `make site-cs-strict-types`,
  `make site-test-unit`, `make site-test` (затронуты интеграционные пути).
- Финальное внутреннее ревью в свежей сессии (субагент получает только
  спецификацию, полный дифф `ae67bde9c..HEAD` и чек-лист
  `docs/workflow/stage-report.md`).
- Внешнее ревью Codex — одна сессия на handoff (§7.2: Large, публичный
  контракт): `site/bin/external-review.sh ae67bde9c --effort medium`.
- Push ветки, Draft PR → Ready, `handoff.md` с планом приёмки на проде.

### Шаги

| # | Что |
|---|---|
| 4.1 | Полные гейты по одному, фиксация вывода |
| 4.2 | Финальное внутреннее ревью свежим субагентом, исправления |
| 4.3 | Внешнее ревью Codex, исправления по BLOCKER / IMPORTANT |
| 4.4 | `handoff.md`, итог этапа, коммит |
| 4.5 | Push, PR (base `master`, проверка `baseRefName`), Ready, вопрос «merge and deploy» |

## 3. Что считаем выполненной работой

- Все гейты зелёные с выводом; baseline PHPStan не вырос.
- Внутреннее и внешнее ревью: нет открытых BLOCKER / IMPORTANT; отклонённые
  замечания — с технической причиной.
- PR открыт на `master`, Ready, описание с проверками и планом приёмки.
- `handoff.md` и `checkpoint.md` актуальны.

## 4. Чек-лист приёмки этапа

- [x] `make site-stan` — OK, baseline без роста.
- [x] `make site-cs-check` и прогон без кеша — OK.
- [x] `make site-cs-strict-types` — OK.
- [x] `make site-test-unit` — OK.
- [x] `make site-test` — OK (или падения доказанно не связаны с задачей, с цифрами).
- [x] Финальное внутреннее ревью: BLOCKER 0, IMPORTANT 0 после исправлений.
- [x] Внешнее ревью: `REVIEW_GREEN` или все подтверждённые IMPORTANT исправлены.
- [x] PR на `master`, `baseRefName = master`, статус Ready.
- [x] `handoff.md`: что сделано, проверки, риски, приёмка на проде (ИП Лазарева, «навол» → 108).

## 5. Итог

Сделано 19.10.2026. Гейты, ревью и правки — `handoff.md` (разделы Checks и
Reviews). Коммиты этапа: `d79d1deaf` (MINOR финального ревью), `1f479a339`
(IMPORTANT Codex). Ревью-метрики: внутренние BLOCKER 0 / IMPORTANT 0,
внешние BLOCKER 0 / IMPORTANT 1 (исправлен).

