# Исправление двойного учёта СПП Ozon

Spec: TASK.md и план в чате. Класс Large: финансовый отчёт и контракт автонастройки.
Работа inline по executing-plans/TDD; контрольные точки по AGENTS.md.
База: 1fc52299d. Формулы фактов и документы учёта автоматически не менять.

## Phase 0
- [x] Изучены preview, SQL, AmountSource, auto setup, 3 похожих query/action/test.
- [ ] Baseline: профильные PlPreview и auto setup тесты в изолированных локальных контейнерах.

## Stage 1: обнаружение конфликта на backend
Risk: HIGH-LOCAL. stage_base_commit: 1fc52299d.
DoD: единая scoped-проверка legacy Ozon источников на стандартных статьях с СПП
или на той же статье, что активная реализация; предупреждения preview и auto setup,
apply блокируется до явного исправления. Период без реализации — явное предупреждение.
Work items: 1.1 red/green query и интеграция preview; 1.2 red/green auto setup.
Checks: профильные integration/functional + focused PHPStan и CS.
Review focus: company isolation, WB, disabled mappings, ручные статьи, read-only.

## Stage 2: отображение предупреждений автонастройки
Risk: MEDIUM. Записать base перед стартом.
DoD: modal показывает причины блокировки; прежний sign mismatch сохранён.
Work items: 2.1 UI + функциональная проверка.
Checks: профильные functional, Twig lint; frontend checklist.

## Stage 3: подготовка исправления данных и handoff
Risk: HIGH-LOCAL. Записать base перед стартом.
DoD: read-only аудит проведённых документов, точный SQL с preconditions/rollback,
проверки всей задачи, fresh internal review, external review, Ready PR.
Work items: 3.1 runbook и критерии; 3.2 gates/review/handoff.
Checks: make site-stan/site-cs-check/site-cs-strict-types/site-test-unit/site-test.
Production выполнение runbook не входит в локальное разрешение.

## Чек-лист готовности
- [ ] Regression red → green; корректные правила не дают предупреждений.
- [ ] Чужие компании, WB, отключённые правила не дают ложных конфликтов.
- [ ] Автонастройка ничего не исправляет молча и не добавляет конфликт.
- [ ] Сентябрь Ozon после утверждённого ремонта: 995659.13 − 77169.07 = 918490.06.
- [ ] Октябрь без реализации не выдаёт начисления без СПП за достоверную выручку с СПП.
- [ ] Теги/XLSX используют общий report; неполный месяц сохраняет предупреждение.
- [ ] Проверено влияние на проведённые документы; пересчёт без одобрения запрещён.
- [ ] Полные gates и оба review; PR Ready, merge/deploy не выполнен без решения владельца.
