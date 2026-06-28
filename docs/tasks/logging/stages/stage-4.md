## Stage 4: антиспам бёрстов — in-process rate-limiter — DONE

**Риск:** 🔴 HIGH (меняет контракт того, что уходит во внешний сервис — события могут дропаться)
**Следующее действие:** 🛑 STOP, ждать Владельца перед Stage 5

### Решение по подходу (выбор Владельца)
Из трёх вариантов выбран **in-process rate-limiter в `before_send`**. Отвергнуты:
- `DeduplicationHandler` — буферизует до `close()`/shutdown процесса; в долгоживущих воркерах (`docker-compose.prod.yml`: `site-php-cli-worker-*`, ads до 10 мин) это задержало бы доставку ошибок в GlitchTip → регресс наблюдаемости.
- «Только server-side группировка» — GlitchTip и так группирует, но при настоящих бёрстах (тесный цикл на 10k итераций) self-hosted инстанс всё равно проглатывает 10k событий (нагрузка на ingest/хранилище).

### Что сделано
- `App\Shared\Infrastructure\Sentry\SentryRateLimiter` (`final class`, stateful) — первые `limit` (по умолчанию **10**) событий с одинаковым ключом за окно `windowSeconds` (по умолчанию **60с**) проходят, остальные дропаются (`return null`). Ключ = классы+сообщения исключений события, либо текст сообщения, либо уровень (md5). Карта счётчиков ограничена `MAX_KEYS=1000` (защита от роста памяти), истёкшие окна вычищаются лениво. Часы — `Psr\Clock\ClockInterface` (инжектируемые, тестируемые `MockClock`).
- `App\Shared\Infrastructure\Sentry\SentryBeforeSend` (`final readonly class`) — композит: Sentry допускает только один `before_send`, поэтому объединяет **rate-limit → scrub** (дропнутое не чистим). `EventScrubber` из Stage 3 не изменён.
- `config/packages/sentry.yaml`: `before_send` переключён с `EventScrubber` на `SentryBeforeSend`.

### Почему так безопасно
- Первые `limit` событий каждого ключа **всегда** проходят → новые/рекуррентные ошибки видны; GlitchTip покажет, что ошибка повторяется.
- Дропаются только **идентичные** повторы сверх порога в коротком окне — настоящие *разные* ошибки не страдают (независимые ключи).
- Состояние — per-process; в воркере живёт, пока жив воркер (то, что и нужно для троттлинга цикла).

### Затронутые файлы
- `site/src/Shared/Infrastructure/Sentry/SentryRateLimiter.php` — new (`final class`, stateful)
- `site/src/Shared/Infrastructure/Sentry/SentryBeforeSend.php` — new (`final readonly class`)
- `site/config/packages/sentry.yaml` — modified (`before_send` → композит)
- `site/tests/Unit/Shared/Infrastructure/Sentry/SentryRateLimiterTest.php` — new
- `site/tests/Unit/Shared/Infrastructure/Sentry/SentryBeforeSendTest.php` — new

### Self-review
- [x] Scope compliance — только троттлинг исходящих событий + композиция с существующим скраббером
- [x] Patterns / naming — `final class` (stateful) / `final readonly class` (stateless композит), слой `Infrastructure`, `__invoke`, constructor injection
- [x] Forbidden actions — none (нет миграций, нет новых зависимостей — `psr/clock`+`symfony/clock` уже в проекте, нет правок legacy `src/`, публичный API не меняется)
- [x] Security — событие дропается целиком/чистится; ничего не логируется
- [x] CS-fixer — чисто на всех 4 новых файлах (`Found 0 of 1`)
- [x] PHPStan — N/A (не настроен); типы аннотированы (`array<string,array{windowStart:int,count:int}>`)
- [x] Tests — 11 unit-тестов Sentry зелёные (41 ассерт): первые N проходят/троттлинг, сброс окна, независимость ключей, fallback на message-ключ, композит чистит и дропает
- [x] lint:container — OK (`SentryBeforeSend`/`SentryRateLimiter` автоварятся, `ClockInterface` резолвится, скалярные дефолты применены)
- [x] ARCHITECTURE.md — N/A (не Facade/Enum/Entity; внутренние инфраструктурные колбэки)

### Команды для проверки
```
docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter Sentry
docker compose run --rm -T site-php-cli php bin/console lint:container --env=test
```

### Риски / на что обратить внимание ревьюеру
- Пороги `limit=10 / window=60s` зашиты дефолтами конструктора (autowiring оставляет дефолты). Если потребуется тюнинг — добавить bind в `services.yaml` (отдельная мелкая правка). Сознательно не выносил в `.env`, чтобы не плодить конфиг без потребности.
- Троттлинг — per-process: несколько воркеров троттлят независимо (итоговый поток ≈ limit × число процессов за окно). Для текущих объёмов это приемлемо; глобальный троттлинг (через Redis) — возможная будущая итерация, если понадобится.
- Реальный эффект проверяется по факту в GlitchTip.

### Открытые вопросы
- нет (Stage 5 — глобальное обогащение scope + чистка `logSlowExecution` — опционально, ждёт решения Владельца на STOP)
