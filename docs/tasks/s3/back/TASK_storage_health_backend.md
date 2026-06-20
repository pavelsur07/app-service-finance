# ТЗ Backend — SHC-001: Storage Health Check (Admin)

> Платформа VashFinDir · Symfony 7.3 / PHP 8.3 / Doctrine / PostgreSQL / Redis / Messenger
> Исполнитель: Claude Code (автономный режим). ТЗ не содержит тел методов — только контракты и намерения.

---

## 0. Сводка (1 экран)

- **Бизнес-цель:** Платформа переходит на поддержку двух хранилищ (local / S3). Администраторам необходимо видеть статус хранилища в реальном времени и запускать ручную проверку — без перехода в консоль. MVP обеспечивает ручной пинг из Admin-панели и кэширование результата в Redis.
- **Модуль:** `App\Shared` (существующий) + `App\Admin` (существующий)
- **Тип:** feature
- **Ветка:** `feature/shc-001-storage-health-admin`
- **Подзадачи:** B1 · B2 · B3 · B4 · B5
- **Затрагивает другие модули:** нет (всё внутри `Shared` + `Admin`, через DI)
- **Требует миграции БД:** нет
- **Меняет публичный API:** нет (Admin firewall изолирован)

---

## 1. Контекст и границы

### 1.1 Текущее состояние

Уже реализовано в `App\Shared\Service\Storage\`:

| Класс | Назначение |
|---|---|
| `ObjectStorageInterface` | контракт: `write`, `read`, `exists` |
| `LocalObjectStorage` | local-реализация через `StorageService` |
| `FlysystemS3ObjectStorage` | S3-реализация через Flysystem + AWS SDK |
| `ObjectStorageFactory` | выбирает драйвер по ENV `STORAGE_DRIVER` (`local`/`s3`) |
| `StoredObject` | DTO результата записи |
| `ObjectStorageException` | базовое исключение |
| `StorageService` | низкоуровневые операции с локальной ФС |

**Проблема:** `FlysystemS3ObjectStorage` создаёт `S3Client` **в конструкторе** — если S3 не настроен (пустые ENV), `ObjectStorageFactory::create()` выбросит исключение при инициализации контейнера. Это необходимо устранить до добавления Health Check.

В `App\Admin` уже существует отдельный firewall и базовая структура контроллеров.

### 1.2 Желаемое состояние

1. Admin-панель показывает страницу `/admin/storage` с текущим статусом хранилища: провайдер, alive/dead/unknown, latency, timestamp последней проверки, сообщение об ошибке.
2. Кнопка «Проверить сейчас» делает POST-запрос, запускает синхронный health-check, сохраняет результат в Redis и возвращает обновлённый статус.
3. `ObjectStorageFactory` не падает при пустых S3-кредах — возвращает null-safe вариант.
4. Результат проверки живёт в Redis с TTL 10 минут. После истечения UI показывает «Не проверялось».

### 1.3 In scope

- DTO `HealthResult`
- Сервис `StorageHealthChecker`
- Redis-репозиторий `StorageHealthRepository`
- Метод `createSafe()` в `ObjectStorageFactory`
- Admin-контроллер: страница статуса + POST-эндпоинт ручного пинга
- Twig-шаблон страницы
- Symfony-сервисные определения (YAML / атрибуты)

### 1.4 Out of scope (явно НЕ делаем)

- Scheduler / автоматическая проверка по расписанию — после подключения S3
- Переключение провайдера из Admin UI (только через ENV)
- Лог истории всех проверок (БД / ElasticSearch)
- S3-реализация `StorageHealthChecker` — заглушка `HealthResult::notConfigured()` до готовности credentials
- Метод `delete()` в `ObjectStorageInterface`
- Алерты / уведомления при деградации хранилища
- Доступ к странице для ролей ниже ROLE_ADMIN

### 1.5 Допущения и открытые вопросы

**Допущения:**
- ENV `STORAGE_DRIVER` содержит `local` или `s3`; пустая строка = `local` (уже в Factory)
- Redis доступен; ключ хранится без шифрования (не содержит PII)
- Probe-файл `__health__/probe` перезаписывается при каждой проверке (идемпотентно, не удаляется)
- Admin-панель использует Twig; нет React для этой страницы
- Firewall Admin: роль `ROLE_ADMIN` уже настроена

**Открытые вопросы:**
- Нужен ли audit-лог действия «пинг» (кто, когда)? → §1.4: вне scope MVP, но предусмотреть место
- Какой TTL считается «нормальным» latency для S3 в production? Пока порог не устанавливаем
- При S3 `createSafe()` должен возвращать `null` или выбрасывать специфичное исключение `StorageNotConfiguredException`? → принято: возвращает `null`, checker обрабатывает как `notConfigured`

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

**N/A** — новых Entity не создаётся. Результат хранится в Redis как JSON.

### 2.2 Связи между сущностями

**N/A**

### 2.3 Enum

**Enum:** `App\Shared\Service\Storage\Enum\StorageStatus`
**Тип:** backed string
**Назначение:** статус последней проверки хранилища.

| Case | `value` | Когда устанавливается | Метка | Терминальный? |
|---|---|---|---|---|
| `Ok` | `ok` | проверка прошла успешно | «Доступно» | нет |
| `Fail` | `fail` | проверка вернула ошибку / exception | «Недоступно» | нет |
| `NotConfigured` | `not_configured` | выбранный driver не имеет credentials | «Не настроено» | нет |
| `Unknown` | `unknown` | Redis-ключ отсутствует или TTL истёк | «Не проверялось» | нет |

**Методы enum:**
- `label(): string` — возвращает человекочитаемую метку из таблицы выше
- `isHealthy(): bool` — `true` только для `Ok`
- `badgeClass(): string` — возвращает CSS-класс Tabler для бейджа (`bg-success` / `bg-danger` / `bg-warning` / `bg-secondary`)

**Сериализация:** наружу отдаём `value`.

### 2.4 Переходы статусов

**N/A** — статус не является state machine; он перезаписывается при каждой проверке независимо от предыдущего значения.

---

## 3. Слой доступа к данным

### 3.1 Repository

**N/A** — нет Doctrine-сущностей.

### 3.2 StorageHealthRepository (Redis)

**Класс:** `App\Shared\Service\Storage\StorageHealthRepository` (`final class`)
**Namespace:** `App\Shared\Service\Storage\`
**Зависимость:** `\Redis` или `Symfony\Component\Cache\Adapter\RedisAdapter` — через `$redis` (инжектируется как сервис)

| Метод (сигнатура) | Что делает | Возврат |
|---|---|---|
| `save(HealthResult $result): void` | сериализует `HealthResult` в JSON, пишет в Redis по ключу `storage:health:last` с TTL 600 сек | `void` |
| `load(): ?HealthResult` | читает ключ `storage:health:last`; если ключ отсутствует (TTL истёк) — возвращает `null` | `?HealthResult` |

**Redis-ключ:** `storage:health:last`
**TTL:** 600 секунд
**Формат значения:** JSON-объект (все поля `HealthResult`)

> `companyId` не применяется — данные глобальные, доступны только ROLE_ADMIN.

### 3.3 Индексы

**N/A** — миграции нет.

---

## 4. Слой приложения

### 4.1 Action

**Класс:** `App\Admin\Application\Action\RunStorageHealthCheckAction` (`final class`, метод `__invoke`)
**Назначение:** выполнить синхронную проверку хранилища и сохранить результат.

- **Вход:** нет входных данных (Command не нужен — нет параметров)
- **Шаги:**
  1. Вызвать `StorageHealthChecker::check()` — получить `HealthResult`
  2. Вызвать `StorageHealthRepository::save($result)`
  3. Вернуть `HealthResult`
- **Исключения:** не бросает наружу — `StorageHealthChecker` перехватывает все `\Throwable` внутри себя и возвращает `HealthResult::fail()`
- **Транзакционность:** N/A (нет Doctrine)

### 4.2 Domain Service

**Класс:** `App\Shared\Service\Storage\StorageHealthChecker` (`final class`)
**Namespace:** `App\Shared\Service\Storage\`
**Назначение:** выполнить probe-запись/проверку к текущему хранилищу и вернуть `HealthResult`.

| Метод (сигнатура) | Что делает | Возврат |
|---|---|---|
| `check(): HealthResult` | определяет текущий driver; если `createSafe()` вернул `null` — возвращает `HealthResult::notConfigured()`; иначе — записывает probe-файл `__health__/probe` со строкой `ok`, вызывает `exists()`, фиксирует latency в мс; при любом `\Throwable` возвращает `HealthResult::fail($e->getMessage())` | `HealthResult` |

**Probe-путь:** `__health__/probe` (фиксированный, перезапись — идемпотентна)
**Измерение latency:** `hrtime(true)` до и после операции, разница в наносекундах → миллисекунды (`int`)

### 4.3 DTO

**HealthResult:** `App\Shared\Service\Storage\HealthResult` (`final readonly class`)

| Поле | Тип | Обязательно | Описание |
|---|---|---|---|
| `status` | `StorageStatus` | да | результат проверки |
| `driver` | `string` | да | значение ENV `STORAGE_DRIVER` (`local` / `s3`) |
| `latencyMs` | `int` | да | 0 при `fail` / `not_configured` |
| `error` | `?string` | нет | сообщение исключения при `fail`, иначе `null` |
| `checkedAt` | `DateTimeImmutable` | да | момент выполнения проверки |

**Фабричные методы** (статические, без тела в ТЗ — только сигнатура):
- `HealthResult::ok(int $latencyMs, string $driver): self`
- `HealthResult::fail(string $error, string $driver): self`
- `HealthResult::notConfigured(string $driver): self`
- `HealthResult::unknown(): self` — используется когда Redis вернул `null` (TTL истёк)

**Формат `checkedAt`:** ISO 8601 при сериализации в JSON (`DateTimeImmutable::ATOM`)
**Сериализация `status`:** `value` enum (`"ok"` / `"fail"` / `"not_configured"` / `"unknown"`)

---

## 5. Асинхронность (Messenger)

**N/A** — MVP использует синхронный health check по запросу из Admin UI. Scheduler добавляется после подключения S3.

---

## 6. Обработка ошибок

Все ошибки хранилища **поглощаются внутри `StorageHealthChecker`** и превращаются в `HealthResult::fail()`. Наружу не пробрасываются.

HTTP-уровень Admin-контроллера:

| Ситуация | HTTP-статус | Поведение |
|---|---|---|
| Проверка выполнена (любой статус) | 200 | JSON с `HealthResult` |
| Нет доступа (не ROLE_ADMIN) | 403 | стандартный Symfony redirect на login |
| Redis недоступен при `save()` | 500 | стандартный Symfony error handler; логировать в `app` канал |

**Исключение:** `App\Shared\Exception\StorageHealthCheckException` (`final class`)
Когда: `StorageHealthRepository::save()` упала при записи в Redis
HTTP: 500
`error.code`: `storage_health_redis_error`
`error.message`: «Не удалось сохранить результат проверки хранилища»

> Формат: `{ "error": { "code": "...", "message": "..." } }`

---

## 7. HTTP API (Controller)

### 7.1 Страница статуса хранилища

**Контроллер:** `App\Admin\Controller\Storage\StorageStatusController` (`final class`, `__invoke`)

| Параметр | Значение |
|---|---|
| Метод + путь | `GET /admin/storage` |
| Маршрут | `#[Route('/admin/storage', name: 'admin_storage_status', methods: ['GET'])]` |
| Авторизация | `ROLE_ADMIN` (Admin firewall) |
| Что делает | вызывает `StorageHealthRepository::load()`; если `null` — подставляет `HealthResult::unknown()`; передаёт в Twig |
| Шаблон | `admin/storage/status.html.twig` |
| Ответ | HTML-страница |

**Переменные Twig:**

| Переменная | Тип | Содержимое |
|---|---|---|
| `health` | `HealthResult` | текущий результат (или `unknown`) |
| `driver` | `string` | значение `STORAGE_DRIVER` ENV |

### 7.2 Ручной пинг хранилища

**Контроллер:** `App\Admin\Controller\Storage\PingStorageController` (`final class`, `__invoke`)

| Параметр | Значение |
|---|---|
| Метод + путь | `POST /admin/storage/ping` |
| Маршрут | `#[Route('/admin/storage/ping', name: 'admin_storage_ping', methods: ['POST'])]` |
| Авторизация | `ROLE_ADMIN` (Admin firewall) |
| CSRF | обязательно — токен `storage_ping` (Twig `csrf_token('storage_ping')`, проверка через `isCsrfTokenValid`) |
| Request body | нет (пустой POST) |
| Что делает | вызывает `RunStorageHealthCheckAction::__invoke()`; возвращает JSON |
| Успешный ответ | 200 + JSON (см. ниже) |

**Контракт ответа (JSON):**
```json
{
  "status": "ok",
  "driver": "local",
  "latencyMs": 12,
  "error": null,
  "checkedAt": "2026-06-18T10:00:00+00:00"
}
```

**Коды ошибок:**

| Ситуация | HTTP | `error.code` |
|---|---|---|
| Redis недоступен | 500 | `storage_health_redis_error` |
| Нет CSRF | 422 | `invalid_csrf_token` |
| Нет доступа | 403 | — (redirect на login) |

**Поведение после пинга:** страница не перезагружается — JS обновляет блок статуса через ответ JSON (минимальный inline JS или Alpine.js, если уже используется в Admin).

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты этапа |
|---|---|---|---|---|
| **B1** | `StorageStatus` enum + `HealthResult` DTO + `ObjectStorageFactory::createSafe()` | — | 🟡 MEDIUM | unit: все `value` enum, все фабричные методы DTO |
| **B2** | `StorageHealthChecker` + `StorageHealthRepository` | B1 | 🟡 MEDIUM | unit: mock storage OK/fail/notConfigured; mock Redis |
| **B3** | `RunStorageHealthCheckAction` | B2 | 🟢 LOW | unit: happy-path, Redis-fail |
| **B4** | `StorageStatusController` + `PingStorageController` + Twig-шаблон | B3 | 🔴 HIGH (новый публичный маршрут Admin) | functional: GET 200, POST 200, POST 403, POST CSRF 422 |
| **B5** | Сервисные определения (DI), финальный review | B4 | 🟢 LOW | `make stan`, `make cs`, полный прогон |

---

### B1: Enum + DTO + Factory safe-метод

- **Цель:** создать типы данных и устранить проблему падения контейнера при пустых S3-кредах.
- **Создаёт файлы:**
  - `src/Shared/Service/Storage/Enum/StorageStatus.php`
  - `src/Shared/Service/Storage/HealthResult.php`
- **Меняет файлы:**
  - `src/Shared/Service/Storage/ObjectStorageFactory.php` — добавить метод `createSafe(): ?ObjectStorageInterface`
- **DoD:**
  - `StorageStatus` содержит все 4 case с `value`, `label()`, `isHealthy()`, `badgeClass()`
  - `HealthResult` содержит все поля, все 4 фабричных метода
  - `createSafe()` возвращает `null` если driver = `s3` и credentials пусты (не бросает)
  - unit-тесты зелёные

---

### B2: StorageHealthChecker + StorageHealthRepository

- **Цель:** реализовать probe-проверку и Redis-персистентность результата.
- **Создаёт файлы:**
  - `src/Shared/Service/Storage/StorageHealthChecker.php`
  - `src/Shared/Service/Storage/StorageHealthRepository.php`
  - `src/Shared/Exception/StorageHealthCheckException.php`
- **Меняет файлы:** нет
- **DoD:**
  - `StorageHealthChecker::check()` не бросает наружу при любом сценарии
  - probe-файл `__health__/probe` используется как фиксированный путь
  - `StorageHealthRepository` корректно сериализует/десериализует `HealthResult` (включая `DateTimeImmutable`)
  - При `null` из Redis — `load()` возвращает `null` (не `HealthResult::unknown()` — это задача контроллера)
  - unit-тесты: mock `ObjectStorageFactory`, mock Redis; сценарии ok/fail/notConfigured/redisDown

---

### B3: RunStorageHealthCheckAction

- **Цель:** оркестрировать проверку и сохранение в одном use-case.
- **Создаёт файлы:**
  - `src/Admin/Application/Action/RunStorageHealthCheckAction.php`
- **Меняет файлы:** нет
- **DoD:**
  - Action вызывает Checker, затем Repository
  - При `StorageHealthCheckException` из Repository — пробрасывает наружу (ловит контроллер)
  - unit-тест: happy-path + Redis-fail

---

### B4: Контроллеры + Twig-шаблон

- **Цель:** Admin UI для просмотра статуса и ручного пинга.
- **Создаёт файлы:**
  - `src/Admin/Controller/Storage/StorageStatusController.php`
  - `src/Admin/Controller/Storage/PingStorageController.php`
  - `templates/admin/storage/status.html.twig`
- **Меняет файлы:**
  - `config/routes/admin.yaml` (или добавить `#[Route]` — по конвенции проекта)
- **DoD:**
  - `GET /admin/storage` → 200, рендерит шаблон с `HealthResult`
  - `POST /admin/storage/ping` с CSRF → 200 JSON
  - `POST /admin/storage/ping` без CSRF → 422
  - `GET /admin/storage` без ROLE_ADMIN → 403/redirect
  - Шаблон отображает: провайдер, статус с бейджем (`badgeClass()`), latency, timestamp, ошибку (если есть), кнопку пинга

**Структура Twig-шаблона** (описание, не код):
- Extends: `admin/layout.html.twig` (существующий базовый шаблон Admin)
- Блок заголовка: «Хранилище файлов»
- Card с полями: Провайдер, Статус (бейдж), Задержка (мс), Последняя проверка (дата/время), Ошибка (если `error !== null`)
- Кнопка «Проверить сейчас» — POST с CSRF, результат обновляет карточку без перезагрузки страницы

---

### B5: DI + финальный review

- **Цель:** убедиться что всё связано через DI и стандарты кода выполнены.
- **Создаёт файлы:** нет
- **Меняет файлы:**
  - `config/services.yaml` или атрибуты `#[Autowire]` — убедиться что `StorageHealthChecker`, `StorageHealthRepository` зарегистрированы; Redis-сервис инжектируется корректно
- **DoD:**
  - `make stan` level 8 — чисто
  - `make cs` — чисто
  - `make test` — зелёный
  - `php bin/console debug:container StorageHealthChecker` — сервис найден

---

## 9. Ограничения и запреты

**Не ломать:**
- Существующие Admin-маршруты
- `ObjectStorageInterface` контракт (методы `write`, `read`, `exists`) — не менять сигнатуры
- `StorageService` и `LocalObjectStorage` — не трогать

**Не трогать модули:**
- `Cash`, `Marketplace`, `Finance` и все бизнесовые модули — изменения только в `Shared` и `Admin`

**Совместимость:**
- `ObjectStorageFactory::create()` — поведение не меняется; добавляется только `createSafe()`
- Миграции: отсутствуют

**Производительность:**
- Health-check выполняется синхронно — timeout probe-операции не должен превышать 5 секунд; для S3 использовать AWS SDK timeout config

**Безопасность:**
- Маршруты `/admin/storage*` защищены Admin firewall (`ROLE_ADMIN`)
- CSRF обязателен для POST
- Redis-значение не содержит PII; логировать только `status` + `driver` + `latencyMs` (не `error` целиком — может содержать credentials в stack trace)

---

## 10. Критерии приёмки

**Функциональные:**
- [ ] `GET /admin/storage` возвращает 200 и отображает текущий статус хранилища
- [ ] `POST /admin/storage/ping` запускает проверку, обновляет Redis, возвращает JSON с результатом
- [ ] При отсутствии Redis-ключа (первый запуск / TTL истёк) страница показывает статус `unknown` без ошибки
- [ ] При driver=`local` и доступной ФС — статус `ok`, latency > 0
- [ ] При driver=`s3` без credentials — статус `not_configured`, без exception
- [ ] CSRF-защита POST работает: запрос без токена → 422

**Технические:**
- [ ] `make stan` — чисто (level 8)
- [ ] `make cs` — чисто
- [ ] `make test` — зелёный, новые классы покрыты unit-тестами
- [ ] `StorageHealthChecker::check()` не пробрасывает исключения наружу ни при каком сценарии
- [ ] `ObjectStorageFactory::createSafe()` не падает при пустых S3 ENV
- [ ] Probe-файл `__health__/probe` создаётся в local-хранилище без ошибок

---

## 11. План отката

- **Стратегия:** revert PR — все изменения аддитивны (новые файлы + метод `createSafe()`), нет миграций, нет изменений существующих публичных контрактов
- **Заметки:** Redis-ключ `storage:health:last` можно удалить вручную (`DEL storage:health:last`) — данные не критичные

---

## 12. Чек-лист качества ТЗ

- [x] Каждая Entity имеет полную таблицу полей — N/A (нет Entity)
- [x] Каждый enum расписан по всем case: value, когда ставится, метка, терминальность
- [x] Матрица переходов статусов — N/A (нет state machine)
- [x] Каждый Repository/Query метод имеет сигнатуру — применимо к `StorageHealthRepository`; companyId не применяется (глобальные данные Admin)
- [x] Каждый Action описан по шагам (намерение, не код)
- [x] Каждое исключение замаплено на HTTP-статус и error.code
- [x] Каждый эндпоинт имеет метод, путь, авторизацию, контракт ответа, коды ошибок
- [x] Указаны namespace и пути файлов для всех новых классов
- [x] Подзадачи разбиты (B1–B5), помечены по риску, имеют DoD и зависимости
- [x] Раздел «Out of scope» заполнен
- [x] Все открытые вопросы вынесены в §1.5
- [x] В ТЗ нет реализации — только контракты и намерения
