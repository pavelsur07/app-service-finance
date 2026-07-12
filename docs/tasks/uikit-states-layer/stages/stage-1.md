# Stage 1: Правило — behavior layer в §2 + ADR — DONE

**Риск:** 🔴 HIGH (необратимая смена DS-контракта)
**Следующее действие:** 🛑 STOP, ждать Владельца

## Что сделано

- `CLAUDE.frontend.md` §2: в таблицу источников правды добавлена строка **Behavior layer** (native pseudo-classes / outline-reset / focus, живёт в `ui-kit/components/states.css`, владелец — Code); «три слоя» → «четыре слоя».
- `CLAUDE.frontend.md` §2: к правилу «код никогда не источник правды» добавлено явное исключение для behavior layer — правило из `states.css` не баг lint и не регрессия при uikit-update.
- `site/ui-kit/decisions.md`: Decision **16** (контекст / решение / отвергнутый exclusion-костыль / цена: styling примитива в двух файлах); заголовок top-15 → top-16; Don't «не регенери states.css при uikit-update».

## Затронутые файлы

- `CLAUDE.frontend.md` — modified (§2, +5/−1)
- `site/ui-kit/decisions.md` — modified (+4/−1)

## Self-review

- [x] Scope: только правило + ADR, ни строчки CSS/кода
- [x] Никаких упоминаний «top-15» / «три слоя» не осталось (grep чист)
- [x] Forbidden actions — none (docs-only, all.css/tokens/_incoming не тронуты)
- [x] Lint не затронут (правки только в .md)
- [x] Тесты: N/A — документационный этап, поведения нет
- [x] ADR фиксирует и решение, и отвергнутую альтернативу, и цену

## Команды для проверки

- `git diff master -- CLAUDE.frontend.md site/ui-kit/decisions.md`

## Риски / на что смотреть ревьюеру

- **Это единственный необратимый этап** — фиксируется контракт: states.css подключается из `app.css` мимо `all.css`, uikit-update его не трогает. Все последующие этапы механически следуют из этих формулировок.
- Формулировка «ноль новых классов, только псевдоклассы на существующих классах UI Kit» — намеренно жёсткая: она же критерий, по которому lint остаётся без изменений.

## Открытые вопросы

- нет
