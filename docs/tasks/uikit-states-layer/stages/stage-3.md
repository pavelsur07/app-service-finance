# Stage 3: uikit-update.md — подстраховка behavior layer — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (Stage 4 — финал)

## Что сделано (site/ui-kit/uikit-update.md, 4 точечные правки)

- **Phase 4** (шаблон `<head>` storybook): в фиксированный список `<link>` добавлен `components/states.css` последним — иначе следующая пересборка head выкинула бы подключение.
- **Phase 6.1**: п.5 — расхождения storybook↔монолит, вызванные behavior layer, ОЖИДАЕМЫ (в монолите слоя нет), не флагать как регрессию.
- **Self-review**: чекбокс «states.css существует, апдейтом не изменён, импортируется из app.css последним (не из all.css)».
- **«Что НИКОГДА не делать»**: строка «регенерить или перезаписывать components/states.css — code-owned behavior layer».

По патчу Владельца compose-шаг для all.css и full-replace-защита в all.css НЕ добавлялись — all.css чист, states подключён из app.css вне зоны апдейта.

## Затронутые файлы

- `site/ui-kit/uikit-update.md` — modified (+7 строк)

## Self-review

- [x] Scope: только uikit-update.md, ровно 4 пункта из плана
- [x] Защита сведена к минимуму (1 never-do + чекбокс), без остаточных костылей в all.css
- [x] Phase 4 head-шаблон теперь воспроизводит Stage 2 при каждом апдейте
- [x] Тесты: N/A — процедурный документ
- [x] Lint/build не затронуты (правка .md)

## Команды для проверки

- `git diff HEAD~1 -- site/ui-kit/uikit-update.md`

## Риски / на что смотреть ревьюеру

- Never-do строка — подстраховка на случай full-replace: states.css лежит в components/, полная замена split-структуры без этой строки могла бы его снести.

## Открытые вопросы

- нет
