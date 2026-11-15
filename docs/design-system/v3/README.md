# ДС «Ваш Финдир» v3.4 — эталон для apps

Только для `apps/account` и `apps/admin` (решение Владельца 2026-11-15). Для `site/`
эталон прежний — `../sheets/*.dc.html`. План внедрения — `docs/plan/ds-v3.md`,
задача — `../PROMPT-ds-v3.md`, приёмка — `../CHECKLIST-ds-v3.md`.

Источник — UI Kit v3.4 (канвас Claude Design). Файлы здесь сгенерированы из канваса:
вручную не править, правка вносится в UI Kit и выгрузка повторяется.

| Путь | Что |
|---|---|
| `sheets/DS-*.html` | листы, обычный HTML (открываются в браузере): Tokens, Controls, Data, Table, Money, Tabs, Feedback, Overlays, Layout, Decisions |
| `sheets/ds-kit.css` | CSS листов: токены (`:root`) и классы кита — эталон вида |
| `sheets/png/*.jpg` | скриншоты листов |
| `tokens/ds-v3.tokens.json` | токены в формате W3C Design Tokens: группы `primitive` → `semantic` → `component`, `deprecated` — алиасы v2.6; ссылки `{name}` |
| `tokens/ds-v3.dark.tokens.json` | тёмная тема (beta, в продукт не включается) |
| `tailwind/app.css` | референс Tailwind v4: тема только на токенах ДС |
| `screens/App-Layout.html` | каркас приложения (шапка, сайдбар, футер); собран на Tailwind CDN для просмотра |

## Имена токенов
В листах и JSON токены без префикса (`--text-tertiary`). В пакете `@vashfindir/ds`
они выпускаются с префиксом `--ds-` (`--ds-text-tertiary`), чтобы не пересекаться с
переменными темы Tailwind. Соответствие один к одному.

## Ключевые правила v3.4
- Главная кнопка и интерактив — navy (`--color-primary`, `#1E2A44`).
- Красный бренда (`--color-accent`, `#B00020`) — только логотип и промо, не кнопки.
- Ошибки, деструктивные действия, снижение — оранжевый (`--danger-*`, `#C2410C`).
- Фокус — `outline 2px` navy, offset 2px; границы полей ≥3:1.
- Новый компонент — только если у него есть экран в продукте и он не собирается из
  существующих (Decision 45).
