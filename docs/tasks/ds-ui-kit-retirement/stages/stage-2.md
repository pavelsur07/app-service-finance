### Stage 2: удаление `site/ui-kit/` и двух гейтов — DONE

Одобрение Владельца 2026-10-11: «одобряю удаление ui-kit… Удаляем папку и обе
проверки после переноса CSS — я за… замена проверке check:ui-kit — пока без замены».

#### Что удалено
- `site/ui-kit/` целиком: `storybook.html`, 56 HTML-образцов `components/*.html`,
  `patterns/*.html`, `decisions.md`, `design-audit.md`, `uikit-update.md`, `CHANGELOG.md`,
  `README.md`, `_audit/`, `_incoming/`, `promts/` (~8,6 МБ; история — в git).
- Гейты: `site/tools/check-ui-kit-classes.mjs`, `site/tools/check-uikit-react-mapping.mjs`,
  скрипты `check:ui-kit`, `check:uikit-react-mapping` в `package.json`
  (`check:all` теперь только `npm run build`), цели `check-ui-kit`,
  `check-uikit-react-mapping` в корневом `Makefile`. Замена не ставится (решение Владельца).

#### Что поправлено
- `assets/react/ui-kit/Button/Button.tsx`, `index.ts`: JSDoc `@uiKit` → ссылка на CSS
  в `assets/styles/kit/` и листы DS.
- `assets/styles/pages/{admin-shell,login}.css`: комментарии со ссылками на старую папку.
- `site/bin/external-review.sh`: промпт ревьюера больше не ссылается на `check:ui-kit`.
- `.github/workflows/deploy.yml`: из фильтра путей фронта убрана `site/ui-kit/**`;
  CSS кита теперь под `site/assets/**`, который в фильтре уже есть — поведение CI не меняется.

#### Проверки
- `npm run lint` ✅, `npm run build` ✅, `npm run check:all` ✅.
- Хеши всех CSS-бандлов совпадают с исходным `master` байт в байт.
- Ссылок на `site/ui-kit` в коде, конфиге и CI не осталось (grep).
