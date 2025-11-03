Отличный вопрос. Коротко: сейчас не трогаем текущий `bank:import:run {company} {provider}`. Дальше переходим на **оркестратор задач + rate limit per provider**, который пакетно «размазывает» обновления по всем аккаунтам SaaS, не упираясь в лимиты API банков.

Вот как это сделать по шагам — просто и по-боевому.

# Этап 0 (как сейчас)

Оставляем команду:

```
bank:import:run {companyId} {providerCode}
```

и cron на компании, где уже включены интеграции.

# Этап 1 — Оркестратор и очередь (без ломки кода импорта)

Идея: вместо «одной большой синхронизации» — **мелкие задания** на уровне `({companyId}, {provider}, {moneyAccountId})`, которые исполняют тот же `BankImportService`, но только для одного счёта. Оркестратор сканирует всех клиентов и кидает задания в очередь с правильным **rate limit** на провайдера.

## 1) Команда-оркестратор

```
bank:import:schedule [--provider=alfa|sber|...] [--company=UUID]
```

* Находит все активные `MoneyAccount` типа BANK с заполненным `meta.bank.provider`.
* Для каждого формирует задание: `{companyId, providerCode, moneyAccountId}` → публикует в очередь (Symfony Messenger).

## 2) Воркер-исполнитель

Handler: `BankImportMessageHandler`:

* Получает сообщение `{companyId, providerCode, moneyAccountId}`.
* Берёт **провайдерский лимитер** (Redis) по ключу `rate:{providerCode}` и ждёт «токен».
* Лочит `moneyAccountId` (Symfony Lock/Redis) на время синка, чтобы не параллелить один счёт.
* Вызывает **существующий** `BankImportService->runOneAccount(companyId, providerCode, moneyAccountId, since, until)` — это маленький публичный метод-обёртка, который переиспользует вашу текущую `run(...)`, но фильтрует ровно один счёт.
* Обрабатывает повтор/ошибки с **экспоненциальным backoff** (Messenger retries).

> Важно: сам «импорт ядра» не переписываем — просто даём ему работать для конкретного счёта.

## 3) Rate limit per provider

Минимально — собственный лимитер на Redis:

* Ключ: `rate:{provider}`.
* Алгоритм: **leaky bucket / fixed window**. Например: 5 req/сек, 200 req/мин (вынести в `.env` per provider).
* На каждом вызове `fetchAccounts`/`fetchTransactions` — перед HTTP-запросом вызываем `limiter->acquire()`.

Symfony RateLimiter (если используете):

```php
$limiter = $this->limiterFactory->create('bank_'.$providerCode);
$limit = $limiter->consume(1);
if (!$limit->isAccepted()) {
    // sleep $limit->getRetryAfter()->diffInMs(); или отложить задачу
}
```

## 4) Конфигурация очереди

* Транспорт Redis/RabbitMQ.
* **Отдельные очереди по провайдерам** (пример: `bank.alfa`, `bank.sber`), чтобы лимиты и сбои одного не влияли на других.
* Конкурентность воркеров по провайдеру регулируем: `--limit` или масштабированием подов.

## 5) Расписание

Запускаем оркестратор каждую минуту или 5 минут:

```
* * * * * php bin/console bank:import:schedule --provider=alfa --env=prod
* * * * * php bin/console bank:import:schedule --provider=sber --env=prod
```

Он добавит в очередь лишь те счета, у которых:

* нет активного lock,
* «давно не синкались» (например, `lastSyncedAt > 2 мин назад` в `meta.bank.last_sync_at`),
* не превышают «долг» по обрабатываемым задачам.

# Этап 2 — Умная сегментация и окна (чтобы не съесть лимиты)

1. **Скользящее окно** по датам: для часто обновляемых счетов тянем `since = now()-2d`, для «тихих» — `since = now()-30d` (персональный backlog).
2. **Приоритизация**: новые компании/ошибочные аккаунты — в начало очереди, «тихие» — реже.
3. **Дифференцированные лимиты**: у Sber лимит один, у Tinkoff — другой. Хранить в конфиге:

```
BANK_RATE_LIMITS=alfa:5/s,300/m;sber:2/s,120/m;...
```

4. **Batch endpoints**, если банк поддерживает multi-account загрузку — адаптер провайдера может собирать несколько accountId в один запрос, но handler всё равно остаётся по-счётно, чтобы идемпотентность/курсор были локальными.

# Этап 3 — Вебхуки и «push-first»

Если провайдер умеет webhooks:

* Регистрируем webhook → при событии кидаем **узкое задание** `{companyId, provider, moneyAccountId}` в очередь.
* Periodic cron остаётся как «страховка» и «хвост» (например, раз в час пробегает все аккаунты на случай пропуска webhook).

# Что добавить в код (минимум и без боли)

1. **Небольшой метод** в `BankImportService`:

```php
public function runOneAccount(string $companyId, string $providerCode, string $moneyAccountId, ?DateTimeImmutable $since=null, ?DateTimeImmutable $until=null): void
{
    // тот же код, что в run(), только фильтр по $moneyAccountId и без сканирования всех счетов
}
```

2. **Message DTO**:

```php
final class BankImportMessage {
  public function __construct(
    public string $companyId,
    public string $providerCode,
    public string $moneyAccountId,
    public ?string $sinceIso = null,
    public ?string $untilIso = null,
  ) {}
}
```

3. **Handler** с лимитером, lock’ом, backoff’ом.

4. **Оркестратор**:

* читает все `MoneyAccount` (BANK),
* фильтрует по `meta.bank.provider`,
* проверяет «давность sync»,
* публикует `BankImportMessage` в очередь провайдера.

# Нюансы SaaS-масштаба

* **Справедливость/изоляция**: делайте «round-robin» по компаниям внутри провайдера, чтобы «киты» не выдавливали остальных.
* **Защита от штормов**: ограничение задач в очереди (не больше N задач на провайдера одновременно), drop dupe задач по ключу `{provider}:{accountId}` — можно хранить «in-progress» ключи в Redis с TTL.
* **Мониторинг**: метрики Prometheus — глубина очереди, latency задачи, errors, 429/5xx от банков, средняя «свежесть» данных по аккаунту, пер-компанийная свежесть.
* **Токены**: отдельный «рефрешер» (по крону/по 401) + кэш токенов (Redis) + защита от stampede (singleflight lock).

# Пример лимитера (простой, без внешних библиотек)

Ключ: `bank:rate:{provider}` → хранит JSON: `{window, count, resetAt}`

Псевдо:

```php
public function acquire(string $provider, int $limitPerWindow, int $windowSec): void {
  $key = "bank:rate:$provider";
  $now = time();
  $row = redis->get($key) ?? ['count'=>0,'resetAt'=>$now+$windowSec];

  if ($now >= $row['resetAt']) { $row = ['count'=>0,'resetAt'=>$now+$windowSec]; }

  if ($row['count'] >= $limitPerWindow) {
    $sleep = $row['resetAt'] - $now + 1;
    sleep($sleep);
    // и/или используем отложенную пере-постановку задачи
  }

  $row['count']++;
  redis->setex($key, $row['resetAt']-$now, json_encode($row));
}
```

Подключите это в адаптерах провайдеров перед HTTP-запросами **или** в handler до вызова `BankImportService`.

# Итог

* Сейчас — **ничего не ломаем**, оставляем `bank:import:run`.
* Далее — добавляем **оркестратор + очередь + лимитер**. Это позволит пакетно обновлять **все аккаунты SaaS**, сохраняя «бережное» отношение к лимитам банков, с контролем параллелизма и предсказуемой свежестью данных.
* Реальные банки/вебхуки — подключаются по мере готовности, логика импорта остаётся прежней.
