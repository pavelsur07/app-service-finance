# ds-legacy-tabler: дизайн-система «Ваш Финдир» в легаси-слое на Tabler

Класс: **Large** (frontend redesign, AGENTS.md §4). Поручение Владельца в чате
2026-10-11. Источник истины для легаси — DS «Ваш Финдир» (приоритет §2 п.1 над
`CLAUDE.frontend.md`/`site/ui-kit/`). Листы DS не приложены → пробелы
`TODO(ds)`, список в `docs/plan/ds-legacy-tabler-audit.md` §7.

Baseline (2026-10-11, `3782e6f4`): `npm run lint` ✅ · `npm run build` ✅ ·
`npm run check:ui-kit` ❌ 9081 (pre-existing, вне CI) ·
`npm run check:uikit-react-mapping` ❌ (pre-existing, TreePicker).

PR по темам (поручение: «один PR — одна логическая тема»), все с базой
`master`, мержить по порядку:

| PR | Ветка | Stage |
|---|---|---|
| 1 | `feat/ds-legacy-tabler` | 0 аудит + 1 токены |
| 2 | `feat/ds-legacy-components` (от 1) | 2 базовые компоненты |
| 3 | `feat/ds-legacy-money` | 3 деньги и даты |
| 4 | `feat/ds-legacy-pagination-tabs` | 4 пагинация, Tabs, таблицы |
| 5 | `feat/ds-legacy-pilot` | 5 пилот на 3 страницах |

Раскатка по модулям — после пилота и подтверждения Владельца. Stage 6 (вывод
`site/ui-kit/`) — отдельно, удаление — §3.3.

## Stage 0: аудит — DONE
Risk: LOW. Результат: `docs/plan/ds-legacy-tabler-audit.md`, пометка в
`site/ui-kit/README.md`.

## Stage 1: токены
Risk: MEDIUM (глобальный визуал всех легаси-страниц, без логики)
Definition of Done:
- `--vf-*` сохраняют имена, значения из DS; не покрытые DS — `deprecated`/`TODO(ds)`;
- слой `--tblr-*` на `:root,[data-bs-theme=light]` после Tabler;
- `legacy-app.css`: хардкод цветов → токены;
- контраст AA для пар text/bg (аудит §8);
- скриншоты до/после: операции ДДС, форма операции, дашборд, вход.
Checks: `npm run lint`, `npm run build`, счётчик `check:ui-kit` не вырос.

## Stage 2: базовые компоненты
Risk: MEDIUM
- `.text-danger`/`invalid-feedback` → danger-текст; красные кнопки только на
  деструктивных действиях; статусы — к трём семантикам (ok/warn/danger) +
  нейтральный; мёртвые `.vf-*` удалить.

## Stage 3: деньги и даты
Risk: MEDIUM (вывод сумм; формулы и знаки не меняются)
- один Twig-фильтр `vf_money` (`src/Twig/`, вне legacy-зоны) + unit-тесты;
- компонент «сумма в таблице»; пресеты периода к набору DS.

## Stage 4: пагинация, Tabs, таблицы
Risk: MEDIUM
- один partial пагинации с «Показано с X по Y из N»; макрос Tabs по DS-Tabs с
  сохранением `.nav-tabs .nav-link.active` (на него завязаны тесты);
- стили таблиц.

## Stage 5: пилот
`transaction/index`, `transaction/_form`, `home/index`.
