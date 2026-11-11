# Локальные фронтэнды account и admin

`account` — клиентский кабинет; `admin` — административный интерфейс.
Оба — независимые React 19 / TypeScript / Vite SPA. Главная, `/about` и 404
показывают только заглушки. Авторизация и подключение Symfony API ещё не
реализованы. При открытии страниц запросы пользовательских данных не идут.
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
Существующий Vite `site/` сохраняет порт 5173. Backend запускается обычным
локальным Compose проекта; новый standalone Compose его не поднимает и не меняет.

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
- `apps/*/src/shared/api.ts` — адаптер API; пока без вызовов с экранов.
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
ошибку авторизации, поскольку маршрута входа ещё нет. OpenAPI-клиент `api`
остаётся в `site/`, схемы и серверные контракты не меняются.

ESLint запрещает прямой fetch вне транспорта, импорты между account/admin,
зависимости общих пакетов от приложений и прямое использование старого frontend
новыми приложениями. Общий код размещайте в пакетах; папки бизнес-функций
создавайте только с появлением соответствующей реализации.

MSW включается только в Vitest и блокирует необработанные запросы; Playwright
проверяет оба viewport в dev и preview, историю, прямые URL, CSS, клавиатуру,
переполнение и отсутствие fetch/XHR/внешней сети. Настоящие бизнес-эндпоинты,
авторизация, production-хостинг, Traefik и CI/CD в этот этап не входят.

Dev-контейнеры видят только `apps`, общие пакеты и CSS кита (CSS read-only).
`server.fs.allow` ограничен выбранным приложением, пакетами и стилями;
корневые служебные файлы, локальные секреты и `site/var` им недоступны.
Тест необработанного запроса MSW использует встроенную стратегию `error`,
проверяет отклонение запроса и отсутствие вызова сетевого fallback.
