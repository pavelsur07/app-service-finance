# Stage 6: документация, гейты, ревью, handoff

stage_base_commit: `7250cf80f`. Risk: LOW.

## 1. Какую задачу решаем

Зафиксировать контракт выгрузки в `ARCHITECTURE.md`, прогнать полные гейты, независимое ревью
всего диффа и подготовить передачу на «merge and deploy».

## 2. Что будет результатом

- `ARCHITECTURE.md`: раздел «Marketplace: предпросмотр ОПиУ по тегам» — маршрут, Action, DTO,
  Exporter, формат 1.0, меню; строка 2.33 в Changelog.
- Полные гейты, финальное ревью в свежей сессии, `handoff.md`, push, PR.

## 3. Что считаем выполненной работой

Гейты зелёные с выводом; ревью без открытых BLOCKER/IMPORTANT; PR открыт и Ready; вопрос
«merge and deploy» задан один раз.

Критерий приёмки ТЗ 7 (01–04.10, 05–11.10, 12–18.10.2026 против присланных `pl-preview_*.xlsx`)
до деплоя выполнить нельзя: маршрута на проде ещё нет, присланных файлов в репозитории и на
машине нет (поиск `pl-preview_*.xlsx` пуст). Выполняется после деплоя — скачать три файла строками
и сверить сумму каналов по `total` (выручка, CM1, CM2, CM3, EBITDA) с присланными файлами.

## 4. Чек-лист приёмки

- [x] `ARCHITECTURE.md` описывает текущее состояние, Changelog — строка 2.33.
- [x] `make site-stan` — OK.
- [x] `make site-cs-check`, `make site-cs-strict-types` — OK.
- [x] Полный `phpunit` — OK (или не связанные с задачей падения с объяснением).
- [x] Финальное ревью в свежей сессии: BLOCKER/IMPORTANT закрыты.
- [x] Внешнее ревью Codex (класс Large — один раунд на handoff).
- [x] TZ (`docs/plan/TZ_vygruzka_plan_fakt.md`) — в PR вместе с задачей; `.secret/`, `docs/integrations/` и прочее чужое не попало.
- [x] `handoff.md`, push, PR Ready, вопрос «merge and deploy».

## Итог этапа

- `ARCHITECTURE.md` обновлён (раздел + Changelog 2.33).
- `make site-stan` — `[OK] No errors` (3145 файлов); `make site-cs-check` — 0 из 3181; `make site-cs-strict-types` — 0 из 3181.
- Полный `phpunit` (своя БД `app_test_rowsx`) — OK, 6909 tests / 39157 assertions, 8:03. Прогон шёл до
  правок по ревью; правки затронули только `PlPreviewRowsXlsxExporter` и его unit-тест — после них
  модульный набор OK, 58 tests / 269 assertions, PHPStan и CS по файлам чистые.
- Финальное ревью в свежей сессии: BLOCKER 0, IMPORTANT 1 (незакоммиченные ARCHITECTURE и ТЗ —
  закрыто коммитом `812185ec1`). Исправлены MINOR: id статьи в `category_code` у `unplaced`,
  подпись формата `tags`, тест «нет каналов»; FOLLOW-UP «потеря предупреждения общего отчёта» —
  исправлен сразу (пустой `marketplace`). Оставлено: порядок каналов — по алфавиту `value`
  (записано в ARCHITECTURE и PR); нагрузка — до 5 расчётов на годовой период (ТЗ §5.6 допускает),
  замерить после деплоя.
- Внешнее ревью Codex (`site/bin/external-review.sh f47d1760e --effort medium`) — `REVIEW_GREEN`, 1 раунд.
- Критерий ТЗ 7 — после деплоя (см. §3).
