## Stage 3: before_send-скраббер секретов/PII — DONE

**Риск:** 🔴 HIGH (меняет контракт того, что уходит во внешний сервис)
**Следующее действие:** 🛑 STOP, ждать Владельца перед Stage 4

### Что сделано
- Новый сервис `App\Shared\Infrastructure\Sentry\EventScrubber` (`final readonly class`, `__invoke(Event, ?EventHint): ?Event`) — `before_send`-колбэк, чистящий событие перед отправкой в GlitchTip:
  - `extra` — **рекурсивная** чистка по чувствительным ключам (главная ценность: `fillExtraContext:true` кладёт сюда context-массивы Monolog, где могут оказаться токены/пароли; SDK их не трогает).
  - `tags` — чистка значений по чувствительным ключам.
  - `request.headers` — редактирование чувствительных заголовков по имени (`authorization`, `cookie`, `x-api-key`, …).
  - `request.cookies` — **все** значения вычищаются (любая cookie может быть сессией/токеном).
  - `request.data` / `request.env` — рекурсивная чистка по ключам.
  - Событие **никогда не дропается** (всегда `return $event`) — скраб не должен глотать ошибки.
- Подключён в `config/packages/sentry.yaml`: `options.before_send: 'App\Shared\Infrastructure\Sentry\EventScrubber'` (бандл оборачивает строку-id в `Reference`).

### Чувствительные ключи
- Подстрока: `password, passwd, secret, token, authorization, api_key, apikey, api-key, access_key, accesskey, private_key, privatekey, client_secret, credential, bearer, cookie, csrf, session_id, sessionid`.
- Точное совпадение (короткие/неоднозначные): `auth, inn, pan, cvv, cvc, passport, snils, pin, otp`.
- Замена → `[Filtered]` (конвенция Sentry).

### Решения по дизайну
- **Размещение — `Shared/Infrastructure/Sentry/`, а не `Shared/Sentry/`** (как было в плане). Интеграция с внешним SDK — это слой `Infrastructure` из раздела «Структура файлов»; рядом уже лежит `Shared/Infrastructure/Doctrine/MoneyAmountType`. План скорректирован по факту.
- **«Точные» ключи отдельным списком** (`auth`, `inn`, …): как подстроки они дали бы ложные срабатывания (`inn` → `beginning`, `author`), поэтому матчатся только при полном совпадении ключа.
- **`user` (UserDataBag) не трогаю**: `send_default_pii:false` не заполняет PII автоматически; то, что кладёт наш код (id для триажа) — осознанно. При необходимости — отдельная итерация.
- Безопасность важнее полноты отладки: где сомнительно — режем (cookies целиком).

### Затронутые файлы
- `site/src/Shared/Infrastructure/Sentry/EventScrubber.php` — new (`final readonly class`)
- `site/config/packages/sentry.yaml` — modified (`before_send`)
- `site/tests/Unit/Shared/Infrastructure/Sentry/EventScrubberTest.php` — new

### Self-review
- [x] Scope compliance — только скраб исходящих событий; продуктовый код не тронут
- [x] Patterns / naming — `final readonly class`, stateless, слой `Infrastructure`, `__invoke`
- [x] Forbidden actions — none (нет миграций, нет новых зависимостей — Sentry SDK уже в проекте, нет правок legacy `src/`, публичный API не меняется)
- [x] Security — сервис снижает риск утечки секретов/PII; сам ничего не логирует
- [x] CS-fixer — чисто на обоих новых файлах (`Found 0 of 1`)
- [x] PHPStan — N/A (в проекте не настроен); типы аннотированы (`array<string,mixed>` и т.п.)
- [x] Tests — unit `EventScrubberTest` зелёный (4 теста, 18 ассертов): extra рекурсивно, tags, headers/cookies/data, событие не дропается
- [x] lint:container — OK, `before_send` резолвится в сервис (DI валиден)
- [x] ARCHITECTURE.md — N/A (не Facade/Enum/Entity; внутренний инфраструктурный колбэк)

### Команды для проверки
```
docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter EventScrubberTest
docker compose run --rm -T site-php-cli php bin/console lint:container --env=test
docker compose run --rm -T site-php-cli php vendor/bin/php-cs-fixer fix --dry-run --diff src/Shared/Infrastructure/Sentry/EventScrubber.php
```

### Риски / на что обратить внимание ревьюеру
- Чистка по **ключам**, а не по содержимому значений. Секрет, лежащий «голой строкой» в незаметном поле (например, токен внутри текста сообщения исключения), не будет вычищен. Это осознанный компромисс; при необходимости позже можно добавить regex-чистку значений (отдельная итерация).
- Список ключей — стартовый; пополняется по мере появления новых полей. Менять только этот класс.
- Реальный эффект на боевых событиях проверяется по факту в GlitchTip после деплоя.

### Открытые вопросы
- нет (Stage 4 — антиспам бёрстов через DeduplicationHandler — ждёт STOP)
