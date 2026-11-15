# balance-legacy-ui — handoff (Draft, environment blocker)

**Branch:** `feat/balance-legacy-ui`. Один коммит задачи; один Draft PR в `pavelsur07/app-service-finance` (URL — в итоговом сообщении и GitHub).

## Изменения
- Stage 1: все страницы Баланса и ошибки — существующая legacy-оболочка; root/main, вкладки «Ещё», правильное меню вложенных экранов и справочников.
- Stage 2: Tabler-карточки, прокручиваемые таблицы, статусы, точные KPI и иерархия; пресеты диапазона, две даты сравнения, пагинация с route/query params.
- Stage 3: Bootstrap-формы и ошибки; существующий confirm_modal для удаления/архивации с CSRF/version/archive; Stimulus и бизнес-сценарии сохранены. На mobile исправлен collapse только для balance_*.

## Файлы
- `site/templates/balance/`, `balance_structure/`, `balance_error.html.twig` и `partials/_sidebar.html.twig`.
- `site/assets/styles/pages/balance.css` + импорт в `legacy-app.js`.
- `site/tests/Functional/Balance/Controller/` — тесты оболочки/навигации, форм, ролей, сценариев и пагинации; `Functional/Logging/ExceptionLogLevelTest.php` — адаптация удаления к диалогу с прежними проверками логирования.
- `docs/tasks/balance-legacy-ui/` — approved TASK, план, checkpoint, отчёты и browser acceptance; карта shell обновлена в Twig conventions.

## Контракты и миграции
Миграций/зависимостей нет. Контроллеры, API, маршруты, query keys, права,
финансовые формулы и денежные фильтры не изменены. Продакшен не затрагивался.

## Проверки
- Baseline Balance: 31 tests / 248 assertions. Новые проверки shell, фильтров/таблиц и темы сначала red (1, 2 и 2 failures).
- Финальный Balance functional: OK, 38 tests / 530 assertions (включая mobile shell и все фильтры пагинации журнала).
- `make site-stan`: OK (4268 файлов).
- `make site-cs-twig`: OK (406); точечный Twig lint после title/mobile fixes: OK.
- `make site-cs-check`, `make site-cs-strict-types`: OK (4304).
- `make site-test-unit`: OK (4819 tests, 26765 assertions).
- frontend `npm run lint`, `npm run build`, `npm run test:unit`: OK, 152 теста (10 файлов).
- Bundle: legacy JS 123920 bytes без изменений; CSS 48224 → 48924 (+1.45%).
- `make site-test`: сначала ошибка прав на root-owned vendor GeneratedConfig.php.
  `docker exec -u 0 vf-balance-php composer install` прошёл; повтор
  `make -o site-composer-install site-test` использует уже выполненную установку.
  Первый полный прогон: 9262 tests, 50509 assertions, 288 errors / 33 failures / 137 warnings.
  Основная причина — root-owned локальные storage/log, недоступные UID1000.
  Контрольный тест импорта под UID0 прошёл (1 / 6). Повтор полного набора с
  временным `/tmp/vf-balance-test-user.yml` (только UID0 для local php-cli) и
  уже выполненной подготовкой:
  `make -o site-test-prepare DOCKER_COMPOSE='docker compose -f docker-compose.yml -f /tmp/vf-balance-test-user.yml' site-test`:
  9262 tests, 52066 assertions, **1 failure**, ошибок/предупреждений нет.
  Единственное падение — logging-тест искал удалённую HTML-форму вместо кнопки
  общего диалога. Тест адаптирован через существующий submitConfirmTrigger;
  проверки 422, warning и отсутствия ERROR+ сохранены.
  Повтор Logging + Balance: **OK, 40 tests / 541 assertions**. Весь набор после
  этой адаптации теста повторно не запускался; не заявляем полностью зелёный
  повтор без вывода.
- Browser: 20 экранов × 2 ширины на реальном Symfony HTML и production bundle.
  Подробности и границы метода: [browser-acceptance.md](browser-acceptance.md).
  Финальный повторный рендер из исходников 15.11.2026: PASS, 40/40; mobile collapse/More/presets/rows/totals/confirm/focus/scroll проверены, ошибок JS нет.

## Review
- Свежий независимый внутренний review и допроверки title/mobile/logging-test fixes: 0 BLOCKER / 0 IMPORTANT / 0 MINOR; открытых находок нет. Визуальная приёмка нашла и закрыла subtitle/collapse.
- Внешний review: 0 завершённых раундов, 1 неудачный preflight. Команда
  `site/bin/external-review.sh c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b --effort medium`
  завершилась exit 2: `ревьюер 'claude' не установлен`. Бинарь и стандартная
  конфигурация не найдены; исправленная попытка невозможна без установки и
  авторизации инструмента. REVIEW_GREEN не заявляется, ревью другим Codex не подменяется.

## Что осталось
Ограничение локального полного прогона описано выше; подтверждённых продуктовых дефектов не осталось.

После появления установленного и авторизованного Claude Code агент должен
сам выполнить обязательный read-only review по `docs/workflow/external-review.md`,
исправить подтверждённые находки, обновить PR, перевести в Ready и задать единый
вопрос merge + deploy. До этого PR остаётся Draft; merge/deploy не выполнялись.
