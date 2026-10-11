# ds-ui-kit-retirement — handoff

Вывод старого UI Kit (`site/ui-kit/`) по плану `docs/plan/ds-ui-kit-retirement.md`,
одобрено Владельцем 2026-10-11. Три PR, мержить по порядку (каждый содержит предыдущий):
1. `chore/ui-kit-move-css` — перенос 56 живых CSS в `site/assets/styles/kit/`.
2. `chore/ui-kit-remove` — удаление `site/ui-kit/` и гейтов `check:ui-kit`, `check:uikit-react-mapping`.
3. `docs/ui-kit-rules` — правила: эталон визуала обоих интерфейсов — листы DS.

## Для пользователей
Ничего не меняется: **все CSS-бандлы (`app`, `marketplace_coverage`, `admin_shell`,
`login`, `design_tokens`, `vf_custom_classes`, `legacy_app`) совпадают с прежними байт в
байт**, скриншоты нового слоя, админки, регистрации и «Покрытия» — тоже.

## Migrations / API / CI
- Миграций и изменений API нет.
- `deploy.yml`: из фильтра путей фронта убран `site/ui-kit/**` — CSS кита теперь под
  `site/assets/**`, который в фильтре уже был; поведение CI не меняется.
- Удалены npm-скрипты `check:ui-kit`, `check:uikit-react-mapping`, `check:all` и цели
  `make check-ui-kit`, `make check-uikit-react-mapping` — в CI они не входили; замена не
  ставится (решение Владельца).

## Checks
- `npm run lint`, `npm run build` — OK; хеши бандлов сравнены после каждого этапа.
- `make site-test-unit` — OK, 3333; `make site-cs-check`, `make site-cs-strict-types` — OK.
- grep по репозиторию: на `site/ui-kit` не ссылаются код, конфиг, CI, Docker, nginx, тесты.

## Reviews
- internal (свежая сессия): BLOCKER 0, IMPORTANT 2 — второй регламент
  `site/screens/screen-intake.md` вставал на удалённом линтере; правила не оговаривали, что
  новый слой пока на палитре кита — исправлены; MINOR исправлены (правило денег во
  фронтовых правилах, `check:all`, комментарии, frontmatter).
- external (Codex, 1 раунд): IMPORTANT «регламент макетов» — тот же, уже исправлен (fixed
  without re-run); IMPORTANT «`site/bin/capture-wb-inventory.sh` в диффе» — **отклонён**:
  неотслеживаемый файл Владельца, скрипт ревью подтягивает такие файлы, в коммиты не входит;
  MINOR — ссылки на удалённые storybook/decisions в комментариях CSS кита — исправлены.
- метрики: внутреннее BLOCKER 0 / IMPORTANT 2; внешнее BLOCKER 0 / IMPORTANT 0 подтверждённых новых.

## Follow-ups
- Новый слой и админка пока на палитре кита (`kit/tokens/colors.css`), не DS — перевод
  отдельной задачей.
- Проверки «класс существует» больше нет; при дрейфе — дешёвый `rg`-чек отдельной задачей.
