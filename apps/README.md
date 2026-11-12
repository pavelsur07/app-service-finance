# Фронтэнды account и admin

`account` — клиентский кабинет; `admin` — административный интерфейс.
Оба — независимые React 19 / TypeScript / Vite SPA. Главная защищена сессией существующего Symfony; `/login` принимает существующие
учётные данные. Бизнес-разделы остаются заглушками. Публичные `/about` и 404
не запрашивают сессию или данные компаний.
Старые Twig-страницы и React 18 острова остаются в `site/`.

## Команды

Нужны Docker Compose и Make. Node/npm на хосте не используются.
Команды запускаются из корня репозитория. Сначала установите зависимости:

```sh
make front-install
make front-dev
make front-typecheck front-lint front-test front-build
make front-e2e MODE=dev
make front-e2e MODE=preview
```

По умолчанию работают оба приложения; `APP=account` или `APP=admin`
ограничивает любую команду одним приложением. Каждое независимо устанавливается
через `npm ci` из собственного lock-файла. При изменении зависимостей обновляйте
lock внутри соответствующего Node-контейнера. `front-lint` проверяет также
Prettier; форматирование: `docker compose -p vf-finance-frontend -f
 docker-compose.frontend.yml run --rm account npm run format` (аналогично admin).

| Приложение | Dev | Preview | Сборка |
|---|---|---|---|
| account | http://127.0.0.1:5174 | http://127.0.0.1:4174 | `apps/account/dist/` |
| admin | http://127.0.0.1:5175 | http://127.0.0.1:4175 | `apps/admin/dist/` |

Порты опубликованы только на loopback. `strictPort` исключает незаметную смену
порта. `/about` можно открыть напрямую и обновить в обоих режимах (SPA fallback).
`make front-e2e MODE=preview` сначала собирает выбранные приложения, затем
перезапускает их в preview. `make front-dev` возвращает dev; можно запустить
preview отдельно через `make front-dev MODE=preview` после `front-build`.
Существующий Vite `site/` сохраняет порт 5173. Standalone Compose запускает только UI: без backend вход показывает ошибку с
повтором. Для реального входа используйте изолированный auth-стек ниже.

Остановка только нового стека:

```sh
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml down
```

Можно переопределить `FRONT_PROJECT` для изолированного имени Compose-проекта
и `CONTAINER_UID`/`CONTAINER_GID` (по умолчанию текущий пользователь).
Node: `24.18.1-bookworm-slim`, Playwright npm и браузерный образ: `1.56.1`.

## Структура и общие пакеты

- `apps/*/src/app/` — маршрутизация и композиция приложения.
- `apps/*/src/pages/` — главная, описание и неизвестный маршрут.
- `apps/*/src/shared/session/` — типы, адаптеры и жизненный цикл сессии; account picker.
- `apps/*/src/app/SessionProvider.tsx` — React subscription и проверка на focus.
- `site/packages/ui/` — Button, cn, EmptyState, ErrorBoundary, вход CSS.
- `site/packages/api/` — JSON-транспорт и общий MSW-контракт тестирования.

Пакеты подключаются через `file:../../site/packages/...`. Они находятся внутри
`site/`, поскольку production build context старого приложения ограничен этим
каталогом. Это сохраняет его сборку без изменений production Docker/CI.
Канонические токены и CSS остаются в `site/assets/styles/kit/`; общий UI
импортирует их, не копирует. React/React DOM — peer dependencies UI с поддержкой
18 и 19. Vite каждого приложения разрешает React из его собственных зависимостей.
Старые импорты Button/cn/API сохранены реэкспортами и адаптером.

Общий API отправляет JSON/FormData, cookies (`credentials: include`), явный
CSRF через `csrfToken`, принимает `signal` и `keepalive`, нормализует ошибки в
`ApiError`. Он не меняет location. Старый адаптер `site/assets/api/client.ts`
по-прежнему переводит на `/login` при 401 или редиректе туда; новые возвращают
ошибку авторизации; приложение само очищает контекст и открывает свой `/login`.
Login401 показывается как неверные credentials, без глобального redirect.
`@vashfindir/api/session` — только cookie/CSRF transport, без React/router.
Account DTO-types генерируются из Nelmio area account: `make front-api-types`
и `make front-api-types-check`; экспорт `@vashfindir/api/account-types`.
Существующие `make api-types api-types-check` продолжают работать.

ESLint запрещает прямой fetch вне транспорта, импорты между account/admin,
зависимости общих пакетов от приложений и прямое использование старого frontend
новыми приложениями. Общий код размещайте в пакетах; папки бизнес-функций
создавайте только с появлением соответствующей реализации.

MSW включается только в Vitest и блокирует необработанные запросы; Playwright
проверяет оба viewport в dev и preview, историю, прямые URL, CSS, клавиатуру,
переполнение, ошибки входа и отсутствие API-запросов на публичных страницах.
UI request fixtures не заменяют actual-backend suite ниже.
Production-публикация описана ниже.

Dev-контейнеры видят только `apps`, общие пакеты и CSS кита (CSS read-only).
`server.fs.allow` ограничен выбранным приложением, пакетами и стилями;
корневые служебные файлы, локальные секреты и `site/var` им недоступны.
Тест необработанного запроса MSW использует встроенную стратегию `error`,
проверяет отклонение запроса и отсутствие вызова сетевого fallback.


## Production: account.vashfindir.ru / admin.vashfindir.ru

Собранную статику обслуживают два независимых Nginx-контейнера `account` и
`admin` в `docker-compose.prod.yml`. Dockerfile и nginx.conf общие:
`docker/frontend/`. Node используется только для сборки, Vite dev/preview
на сервере не запускаются. Runtime содержит dist, работает с read-only root
и tmpfs для cache/pid; исходники, зависимости, .env и site/var туда не попадают.
Build context ограничен allowlist в Dockerfile.dockerignore.

Traefik уже настроен в production Compose: Docker provider, сеть
`traefik-public`, entrypoints `http`/`https`, resolver `letsEncrypt` с HTTP
challenge и постоянным хранилищем сертификатов. Новые сервисы добавляют только
Host routers, сертификаты и HTTP→HTTPS через существующий `redirect-to-https`.
API routers priority100 отправляют только соответствующий `/api/account/v1/`
или `/api/admin/v1/` на существующий site nginx, сохраняя Host и HTTPS.
Прямые порты наружу не публикуются, backend/default network не подключена.
Остальные API/internal/admin/PHP пути на SPA-хостах возвращают404.
Symfony повторно проверяет host+path, Origin и purpose-specific CSRF; nginx
исключает CORS-заголовки новых API даже после internal redirect к PHP.

DNS обоих имён должен указывать на тот же сервер, что `app.vashfindir.ru`
(например, CNAME account/admin → app.vashfindir.ru). Для ACME должны быть
доступны 80/443. Маршрут существующего app.vashfindir.ru сохраняется.

Локальная проверка именно production images (после `front-install`):

```sh
make front-prod-build
make front-prod-test
# Можно ограничить одним приложением:
make front-prod-build front-prod-test APP=account
```

Образы по умолчанию: `vf-finance/account:local`, `vf-finance/admin:local`.
`FRONT_IMAGE_TAG` меняет локальный тег. Image runner
`bin/frontend-production-test.sh` также принимает `APP` и `IMAGE`.
Он создаёт временную Docker-сеть, запускает настоящий read-only Nginx без
публикации портов, выполняет Playwright Chromium и удаляет только свои
контейнер/сеть. Проверяются desktop/mobile, history/reload/404, отсутствие
запросов данных на публичных страницах, HTML no-cache, fingerprinted assets immutable, missing asset
404, private paths и readiness `/health` (наличие index.html).

В CI `spa-checks` выполняет npm ci/typecheck/lint/test/build обоих приложений.
Изменения apps, общих packages, CSS кита и production Docker/CI запускают SPA
проверки. Общие packages также запускают старый frontend lint/build, поскольку
site продолжает их использовать. PR собирает выбранные images; master и
ручные release gates собирают полный набор с единым SHA. SPA image сначала
загружается в локальный Docker runner и проходит browser smoke, затем при
успехе публикуется в GHCR (`account-nginx`/`admin-nginx`, SHA и latest).

Production deploy явно скачивает оба образа по release SHA и выполняет
`up -d --no-deps --wait account admin`; ошибка readiness блокирует завершение.
Backend обновляется раньше новых SPA. `spa-auth-e2e` обязателен для schema-ready
и deploy; проверяет dev, preview и production nginx через настоящий Symfony.
Релиз проходит обычный Draft PR → checks/reviews → Ready → merge+deploy.
Миграций или действий с БД для SPA нет. Откат — revert PR через тот же pipeline.

Первый релиз новых GHCR packages: после master build проверьте, что production
Docker может скачать `account-nginx` и `admin-nginx` (public visibility либо
существующий доступ к registry). Начальный общий `pull` в deploy проверяет
доступ к обоим образам до обновления любых сервисов; отказ прерывает выкатку.
Публичность packages или сохранённые credentials из исходников не проверяются.


## Реальный локальный вход и HTTP acceptance

`docker-compose.frontend-auth.yml` — отдельный одноразовый PostgreSQL/Redis и
Symfony в окружении `spa_e2e`, native Redis sessions (не BrowserKit mock).
Используется только `.env.test` и явные локальные overrides. Нет production
секретов/данных; seed-скрипт отказывается работать вне этой БД/окружения.
Нужны установленные `site/vendor`, старый frontend build и зависимости обеих SPA.
Подготовка при чистом checkout, все PHP/Node команды в контейнерах:

```sh
export CONTAINER_UID="$(id -u)" CONTAINER_GID="$(id -g)"
mkdir -p .frontend-auth/var
make front-install
docker compose -f docker-compose.frontend-auth.yml build site-php-cli site-php-fpm
docker compose -f docker-compose.frontend-auth.yml run --rm --no-deps \
  -e COMPOSER_HOME=/tmp/composer --entrypoint composer site-php-cli \
  install --no-interaction --prefer-dist --no-scripts
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$PWD/site:/app" -w /app node:24.18.1-bookworm-slim \
  sh -c 'yarn install --frozen-lockfile && yarn build'
make front-auth-e2e MODE=dev
make front-auth-e2e MODE=preview
make front-auth-e2e MODE=production
```

`APP=account|admin` ограничивает тесты; оба контура всё равно запускаются для
проверки изоляции. Тесты создают собственную Docker-сеть без публикации портов,
применяют миграции к пустой тестовой БД и после выполнения удаляют свой стек.
Production-mode использует production SPA images, canonical site nginx config
и Traefik rules, извлечённые из production Compose. Тестовый CA импортируется
в NSS Chromium; `ignoreHTTPSErrors` не используется. Проверяются host-only,
HttpOnly, Secure, SameSite, rotation/logout/replay, legacy и sibling isolation,
Origin, CSRF, forbidden host/path, JSON/no-store/no-CORS после PHP fallback.
Трассы real-auth тестов отключены, чтобы не сохранять credentials/cookies.
`.frontend-auth/` — игнорируемые временные CA/cache, не артефакт выпуска.

Для интерактивной разработки сначала остановите standalone UI (те же порты):

```sh
docker compose -p vf-finance-frontend -f docker-compose.frontend.yml down
make front-auth-dev MODE=dev
# или MODE=preview — сначала собирает SPA
make front-auth-stop
```

В локальном hosts настройте `127.0.0.1 account.auth.test admin.auth.test app.auth.test`.
Account: `http://account.auth.test:5174`, admin: `http://admin.auth.test:5175`;
preview4174/4175; старый UI `http://app.auth.test:8089`. Публикация только loopback.
Это разные имена хостов: одинаковый localhost с разными портами не разделяет cookies.
Vite proxy сохраняет Host/Origin и пропускает только свой API-prefix.
Тестовые пользователи `auth-owner@example.test` и `auth-admin@example.test`,
пароль `Local-auth-password-123!`; эти записи существуют только в одноразовой БД.
Остановка удаляет тестовую БД и сессии; исходники/зависимости сохраняются.

## Выпуск и границы

Отдельные host-only сессии: вход/выход в account, admin и старом app независимы.
Admin требует системный ROLE_ADMIN (или ROLE_SUPER_ADMIN); права компании admin
не дают системный доступ. No-company — штатное состояние account. Регистрации,
reset password, SSO, 2FA, бизнес-экранов и новых финансовых правил здесь нет.

Rollout: backend API → SPA. Rollback: revert через обычный pipeline, согласованная
версия SPA перед несовместимым откатом backend; Redis глобально не очищать.
После production deploy — только read-only acceptance HTTPS/about/health,
anonymous csrf/session, wrong host/prefix и старый login; успешный вход и
изменение компаний доказаны на test fixtures, production mutations не требуются.
