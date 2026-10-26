# bank-sync — handoff Stage 3

**Branch:** `feat/bank-sync-stage3` · **PR:** см. описание PR · **CI:** см. PR

Поставка — Stage 3 из плана (`plan.md` §5). Пользователь видит статус подключения
вместо «активно/нет»; вход в банк по OAuth появится в интерфейсе только с провайдером
банка (Альфа — Stage 5), сейчас провайдеров на проде нет.

## Summary of stages
- Stage 3 — общая часть OAuth-подключения: статусы `active`/`needs_reauth`/`revoked`/
  `disabled`, вход Authorization Code + PKCE, хранение и обновление токенов под
  блокировкой подключения, 401 и отказ обновления → `needs_reauth`. Подробно —
  `stages/stage-3.md`, «Итог этапа».

## Files changed
- `site/src/BankSync/{Enum,Entity}` — `BankConnectionStatus`, переходы в `BankConnection`.
- `site/src/BankSync/Domain/OAuth`, `Infrastructure/OAuth` — токены, PKCE, контракт
  провайдера, клиент token endpoint, сессия входа, одноразовый `state`, блокировка токенов.
- `site/src/BankSync/Application` — старт/завершение входа, обновление токенов, форма под блокировкой.
- `site/src/BankSync/Controller`, шаблоны — маршруты входа, статус в списке.
- `SyncBankConnectionHandler` — обновление токена, 401, `needs_reauth`.
- Тесты unit/integration/functional, `ARCHITECTURE.md` 2.78, `plan.md`.

## Migrations
- `Version20261026170000`: `status` (+ CHECK), `status_reason`, `status_changed_at`
  вместо `is_active`; обратима (`down` — `is_active = status = 'active'`). На проде
  в `bank_sync_connection` 0 строк.

## Public API / contract changes
- Маршруты `bank_sync_oauth_start` (POST, CSRF, `FINANCE_WRITE`), `bank_sync_oauth_callback` (GET).
- Тег `bank_sync.authorization_provider` (`BankAuthorizationProviderInterface`).
- Тип учётных данных `oauth2` в `BankCredentialsCodec`.
- `BankConnectionRepository::findSyncableByBankCode()` вместо выборки по `is_active`.

## Checks
- PHPStan полный — 0 ошибок, baseline не изменён; CS и `strict_types` — чисто.
- Полный прогон: unit 4052 — OK, integration + functional 3234 — OK.

## Reviews
- internal: done.
- external: раунды 1–3 — 6 IMPORTANT, исправлены с тестами; раунд 4 — REVIEW_GREEN.

## Risks, limitations, follow-ups
- Провайдера банка нет: кнопки входа на проде не показываются до Stage 5.
- Одноразовость `state` держится на Redis сессий (`SET NX`, 15 минут).
