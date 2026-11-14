# Live eval — preflight 14.11.2026

## Разрешение владельца

Получено в текущем чате: «1. Разрешаю второй ключ не нужен работаем с темже
ключом». Это разрешает предложенный DeepSeek eval на41 обезличенном сообщении,
до82 вызовов, timeout 60s, расчётный бюджет300₽, с существующим ключом шлюза.
Отдельный test key не требуется. Значение ключа не печатать, не переносить в
локальное окружение, не включать в архив/отчёт. На разрешённом app-host runner
AI_GATEWAY_TOKEN можно связать с PAYMENT_INTAKE_EVAL_TOKEN только в памяти
процесса. Ротация ключа, расширение сетевых allowlist и deploy не разрешались.

## Тариф и лимиты

Проверены официальные источники 14.11.2026:
- [DeepSeek](https://api-docs.deepseek.com/quick_start/pricing/): модель
  deepseek-flash (DeepSeek-V4.1-Flash-1017); верхняя peak ставка cache-miss
  input$0.30/output$1.20 за1M tokens. Выбрана консервативная peak ставка,
  независимо от off-peak/cache скидок.
- [Банк России](https://www.cbr.ru/currency_base/daily/?UniDbQuery.Posted=True&UniDbQuery.To=14.11.2026):
  USD82.1516₽. Округление вверх до копейки даёт input24.65/output98.59₽ за1M.

При declared input guard65536/output16000 и82 вызовах резерв261.82₽ <300₽.
Это estimate, не гарантия биллинга: guard использует байты/allowance, не точный
provider tokenizer; провайдер/шлюз могут учитывать cache, overhead и иной курс.
Существующий runner заранее резервирует maxima и останавливает следующие вызовы
по лимиту; общий max82 включает повтор schema parse. Fallback/SDK retry отсутствуют.

## Проверенный источник

Runtime code: `7b5e7b9509454b5d5d2123244253b0a48de6ec12` (CI43500760767 SUCCESS).
Подготовлен локальный воспроизводимый archive без секретов:

- `site/var/payment-intake-review/approved-live-eval-source.zip`;
- SHA256 `87123e508601131a881cf0a3291808aa0ae8591215285dca38b99fe46230a1b2`;
-64 файлов: ai_service Python/prompts, standalone schema, route config, locked
  dependency manifests,41 anonymized messages и partial review;
- подробный manifest/checksums/preflight —
  `site/var/payment-intake-review/approved-live-eval-preflight.json`.

Архив распакован в отдельный TemporaryDirectory dev-container, проверен digest,
пакет импортирован из этого дерева. Offline default прошёл:41case/network_calls0.
Новых production файлов/контейнеров или настроек этим не создавалось.

## Реальный блокер выполнения

На текущем dev-host нет AI_GATEWAY_TOKEN ни в process env, ни в проверенных
локальных compose env/контейнерах. GET gateway `/deepseek/v1/models` без токена
возвращает403: запросы с этого хоста не проходят сетевой gate. Ключи провайдеров
живут только на шлюзе, существующий токен — на app-host.

Разрешённый read-only codex-docker-ps подтвердил healthy ai-service и работающий
ai-service-worker на app-host. Доступные агенту production entrypoints — только
vf-prod-codex и allowlisted wrappers. В документированном codex-console нет
Python/ai-service/eval команды; wrapper исполняет только Symfony allowlist.
[prod-access.md](../../maintenance/prod-access.md) явно запрещает обходить
недостающий wrapper произвольным Docker/sudo/SSH.

**Paid model calls для этого eval:0.** Нет live метрик или нового качества.
Это отсутствие разрешённого execution entrypoint, не запрос второго ключа.

## Конкретный следующий запуск

Нужен узкий app-host entrypoint либо операторский запуск в отдельном одноразовом
ai-service container с существующим installed image/dependencies. Он должен:

1. Проверить source archive digest, распаковать только проверенный bundle во
   временный source tree; PYTHONPATH указывает на services/ai-service/src.
2. Унаследовать только существующий AI_GATEWAY_TOKEN и связать с eval env name
   в памяти процесса, не выводя значение; не передавать DB credentials.
3. Не запускать внутри рабочего API/worker cgroup, не менять service, worker,
   network allowlist или application release. Выполнять serial, ограничить
   memory/CPU одноразового контейнера; весь report возвращать как metadata JSON.
4. Выполнить уже готовую CLI с фиксированными аргументами:

```text
python -m ai_service.evals.payment_intake
  --execute --routes config/routes.yaml --model deepseek/deepseek-flash
  --gateway-url https://ai.vashfindir.ru
  --max-calls 82 --max-input-tokens 65536 --max-output-tokens 16000
  --timeout 60 --budget-rub 300 --input-price 24.65 --output-price 98.59
```

Runner пишет metadata-only JSON в stdout; результат сохраняется локально в
ignored site/var, без исходных текстов/ответов/trace/значения ключа.

Установка/добавление production wrapper или изменение доступа — отдельная
именованная операция по AGENTS.md§3.3. До предоставления entrypoint агент не
делает произвольный SSH/Docker exec и не копирует production token локально.
