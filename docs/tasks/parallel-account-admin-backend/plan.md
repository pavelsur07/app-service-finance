# Этап 0 — фронтэнды account и admin

Источник требований: утверждённый план владельца в чате 2026-11-11.
Самостоятельные React 19 приложения `apps/account` и `apps/admin`; Symfony
остаётся единственным backend в `site/`. Старые страницы работают параллельно.
Отдельный Symfony `api/` из прежнего плана отменён.

## Stage 0: локальная frontend-основа
Risk: HIGH-LOCAL (извлечение общего API/UI), без production-изменений.
stage_base_commit: ee50c9870
Definition of Done:
- Независимые package.json/lock, React 19, строгий TypeScript, Vite/Router.
- Главная, /about, 404 и ErrorBoundary; нет авторизации и запросов данных.
- Общие file:-пакеты UI/API, старые импорты и API-сигнатуры совместимы.
- Единственный источник CSS — finance kit; React 18/19 через peers.
- Контейнерные команды front-install/dev/typecheck/lint/test/build/e2e;
  APP=account|admin, MODE=dev|preview, loopback 5174/5175 и 4174/4175.
- Vitest/MSW: JSON/204, 401/403/422/500, HTML, invalid JSON, network,
  abort, cookie credentials, CSRF, старый login redirect, unexpected requests.
- Playwright Chromium в dev/preview: маршруты/history/reload/404,
  desktop/mobile без overflow, стили, клавиатура, отсутствие API-запросов.
- Проверки старого фронта до/после, внутреннее и внешнее review.
- Нет PHP, схемы БД, workers, production/CI, реальных API/логинов/данных.
Work items:
- 0.1 — правила, изоляция, baseline, замена плана, Draft PR #2965.
- 0.2 — тесты поведения существующего API до извлечения.
- 0.3 — общие UI/API пакеты и совместимые адаптеры старого frontend.
- 0.4 — два приложения, локальный Compose и Make-команды.
- 0.5 — browser/transport проверки, документация, review и handoff.
Stage checks: старые lint/build/test:unit; все front-* проверки, e2e в двух режимах.
Handoff gates: make site-stan/site-cs-check/site-cs-strict-types/site-test-unit.
Reviewer focus: старый redirect/FormData/abort; React dedupe; CSS single source;
сетевой карантин тестов; независимость apps и loopback портов.

## Решения исполнения
- Один Stage 0 и один Stage Report по прямому указанию владельца (вместо
  обычных 2–5 Stage). Work items не являются промежуточными review-гейтами.
- Используется существующий чистый worktree и ветка PR #2965.
- Последняя строка исходного плана «только план» заменена текущим прямым
  поручением реализовать. Все перечисленные зависимости явно разрешены.
- Общий UI экспортирует существующий kit из site, CSS не копируется.
- Общий API не знает о location; login redirect выполняет только старый адаптер.

- Пакеты находятся в `site/packages/`: production build context старого
  приложения — `site/`. Корневые packages потребовали бы запрещённых этим
  этапом изменений production Docker/CI. Новые приложения используют file:.
