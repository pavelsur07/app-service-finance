### Stage 1: авторизация `/internal/*` в Symfony — IN PROGRESS

**Risk:** HIGH-LOCAL (auth-код, новый firewall)
**Stage base commit:** `fd17daeb9`
**Branch:** `feat/ozon-fetcher-internal-api` (поверх `docs/ozon-fetcher-ingestion-stage0`)
**Внешнее ревью:** да — в конце этапа

#### Цель
Маршруты `/internal/*` доступны только сервисам `services/` из внутренней сети
по static token; снаружи и без токена — отказ. Без настроенного env — закрыто
для всех (fail-closed).

#### Результат
- firewall `internal_api` (`^/internal/`, stateless) с
  `InternalServiceAuthenticator`: `Authorization: Bearer <token>` →
  `hash_equals` с `INTERNAL_AUTH_TOKEN` + IP клиента в `INTERNAL_ALLOWED_IPS`
  (CIDR через `IpUtils`); роль `ROLE_INTERNAL_SERVICE`
- `GET /internal/ping` → `{"status":"ok"}` — проверка связности для сервисов
- ответы отказа в формате `{"error":{"code","message"}}`:
  401 `internal_auth_required`, 403 `internal_ip_forbidden`,
  403 `internal_api_disabled` (token/allowlist не заданы)
- nginx: `/internal/` с заголовком `X-Forwarded-For` (запрос пришёл через
  публичный traefik) → 404; внутренние вызовы идут напрямую в `site-nginx`
- env `INTERNAL_AUTH_TOKEN=`, `INTERNAL_ALLOWED_IPS=` пустые в `site/.env` →
  на проде endpoint закрыт до этапа 14 (прод-env — §3.3)

#### Почему так
- Отдельный firewall, не `main`: без сессий, form_login и CSRF.
- IP берётся из `Request::getClientIp()`; `trusted_proxies: private_ranges`,
  traefik перезаписывает `X-Forwarded-For` у внешних клиентов — внешний IP не
  попадёт в приватный allowlist. nginx-guard — второй рубеж на случай ошибки
  конфигурации proxy.
- Токен в логах не пишется ни при успехе, ни при отказе; в лог отказа — путь,
  IP и причина (warning).

#### Work items
- **1.1** — `InternalServicePrincipal`, `InternalServiceAuthenticator`,
  `InternalAccessPolicy` (проверка токена и IP, чистая логика), firewall и
  access_control, env.
- **1.2** — `InternalPingController` (`GET /internal/ping`).
- **1.3** — nginx-guard для `/internal/`.
- **1.4** — тесты: unit на политику (все ветки), functional на firewall
  (нет токена, неверный, чужой IP, не настроено, успех, `/internal/` не
  перехватывает `main`).
- **1.5** — `ARCHITECTURE.md` (Firewall и роли, Конфигурация, Changelog).
- **1.6** — гейты по изменённым файлам, внутреннее ревью, внешнее ревью, коммит.

#### Definition of Done / чек-лист готовности
- [ ] без `Authorization` → 401 `internal_auth_required`
- [ ] неверный токен → 401 `internal_auth_required`
- [ ] верный токен, IP вне allowlist → 403 `internal_ip_forbidden`
- [ ] пустой token или allowlist → 403 `internal_api_disabled` при любом запросе
- [ ] верный токен + IP → `GET /internal/ping` 200 `{"status":"ok"}`
- [ ] сравнение токена — `hash_equals`; токен не логируется
- [ ] пользовательская сессия `main` не даёт доступа к `/internal/*`
- [ ] nginx: `/internal/` с `X-Forwarded-For` → 404, без — проходит в PHP
- [ ] `site/.env` — пустые значения, `.env.test` — тестовые
- [ ] unit-тесты политики на все ветки, functional-тесты firewall
- [ ] PHPStan и CS по изменённым файлам; baseline не растёт
- [ ] `ARCHITECTURE.md` обновлён
- [ ] внутреннее ревью: BLOCKER/IMPORTANT нет; внешнее ревью — green
