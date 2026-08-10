# Stage 2 Report: UI и MCP-контракт переноса в root

## Result

- Форма показывает явный вариант `— Корневая категория —`.
- Из родителей исключены текущая категория, её потомки и ветки `CF_TECH*`/`CF_UNALLOC`.
- Обычная дочерняя категория может за один submit стать root и сохранить либо изменить `flowKind`.
- Stimulus блокирует `flowKind`, пока выбран родитель, и включает его при выборе root.
- Symfony `PRE_SUBMIT` восстанавливает текущий non-null `flowKind`, если disabled-поле не попало в POST.
- MCP `parentId` имеет tri-state: omitted — без изменений; UUID — новый родитель; `null` — root.
- Неверный тип `parentId` отклоняется и не интерпретируется как detach.
- `TECHNICAL` удалён из выбора обычных категорий в UI и MCP schema.

## Checks

- Targeted unit: 23 tests, 60 assertions — green.
- Relevant integration: 9 tests, 33 assertions — green.
- Relevant functional: 9 tests, 54 assertions — green.
- Twig lint (3 category templates) — green.
- Scoped PHP CS Fixer (8 PHP files) — green.
- ESLint — green.
- Vite production build — green; сохранилось известное предупреждение об отсутствующем optional `@symfony/ux-turbo` package.
- `git diff --check` — green.

## Reviews

- Internal review: green; DTO flag appended to preserve positional compatibility, invalid nullable MCP input is rejected explicitly.
- External review 1: `REVIEW_GREEN`, optional UX MINOR about active-looking inherited `flowKind`.
- UX fix: lightweight Stimulus disable/enable behavior.
- External review 2: BLOCKER confirmed — disabled browser control is absent from POST and could map `null` into a non-nullable enum setter.
- Fix: server-side `PRE_SUBMIT` fallback plus a functional POST test without `flowKind`.
- External review final: `REVIEW_GREEN`; no unresolved BLOCKER or IMPORTANT findings.

## Scope

- No schema, migration, dependency, React, Vite-entry, Money, transaction, or production changes.
