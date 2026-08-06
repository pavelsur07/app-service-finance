# Ротация ключей шифрования api_key подключений маркетплейсов

Как безопасно сменить ключ шифрования (`v1 → v2`, далее по той же схеме) для
`marketplace_connections.api_key_encrypted`, полностью через GitHub Secrets,
без key-файла на хосте и без даунтайма.

## Модель

- Каждая строка хранит `api_key_key_version` — версию ключа, которым зашифровано.
- Приложение умеет читать несколько версий одновременно из `APP_ENCRYPTION_KEYS_JSON`
  (карта `{"v1":"base64...","v2":"base64..."}`).
- Запись всегда идёт активной версией (`APP_ENCRYPTION_CURRENT_KEY_VERSION`).
- Приоритет источников ключа: файл `APP_ENCRYPTION_KEY_FILE` → карта `APP_ENCRYPTION_KEYS_JSON`
  → `APP_ENCRYPTION_FALLBACK_KEY` (только активная версия, legacy-схема).

**Железные правила:**
1. Старая версия ключа живёт в секрете, пока в БД есть строки на ней. Удалил раньше — потерял данные необратимо.
2. Значение секрета — JSON в одинарных кавычках при прокидывании в `.env`; само значение не должно содержать символ `'`.
3. Ключ — base64 от 32 байт (`openssl rand -base64 32`).

## Предусловия

- Задеплоен код с поддержкой `APP_ENCRYPTION_KEYS_JSON` и командой
  `app:marketplace:rotate-connection-keys` (ветка `task/encryption-key-rotation`).
- Доступ к GitHub Secrets репозитория и к прод-хосту (docker).

## Пошагово

### 1. Сгенерировать новый ключ

```bash
openssl rand -base64 32
```

Сразу сохранить в менеджер секретов. Далее — `K2`.

### 2. Обновить GitHub Secrets

- `APP_ENCRYPTION_KEYS_JSON` = `{"v1":"<текущий ключ>","v2":"<K2>"}`
  (текущий ключ — из действующего `APP_ENCRYPTION_FALLBACK_KEY`)
- `APP_ENCRYPTION_CURRENT_KEY_VERSION` = `v2`

### 3. Деплой

Вручную запустить workflow `🚀 Deploy to Production` с action `deploy` на
текущем `master`. Обычный push и re-run CI production не меняют. После деплоя:
- старые строки (`v1`) читаются через карту — ничего не ломается;
- новые/изменённые подключения шифруются уже `v2`.

Проверка, что переменные доехали (не печатает секреты):

```bash
docker exec site-messenger-worker-sync bin/console app:marketplace:rotate-connection-keys
# заголовок: «Активная версия ключа: v2» + таблица распределения по версиям
```

### 4. Ротация данных

```bash
# dry-run: распределение по версиям и сколько требует ротации
docker exec site-messenger-worker-sync bin/console app:marketplace:rotate-connection-keys

# выполнение
docker exec site-messenger-worker-sync bin/console app:marketplace:rotate-connection-keys --execute

# контроль: «Делать нечего»
docker exec site-messenger-worker-sync bin/console app:marketplace:rotate-connection-keys
```

Команда идемпотентна, батчи по 100, воркеры останавливать не нужно
(чтение старых версий работает всё время перехода).

### 5. Проверка

- В UI `/marketplace` → «Проверить» у подключения — живой API-вызов с расшифрованным ключом.
- SQL (read-only): `SELECT api_key_key_version, count(*) FROM marketplace_connections GROUP BY 1;` — все строки `v2`.

### 6. Уборка (через 1–2 дня наблюдения)

- `APP_ENCRYPTION_KEYS_JSON` = `{"v2":"<K2>"}` (убрать `v1`)
- Удалить `APP_ENCRYPTION_FALLBACK_KEY` (оставлен с первой версии схемы, больше не нужен)
- Деплой/re-run для применения.

## Rollback

До шага 6 откат = вернуть `APP_ENCRYPTION_CURRENT_KEY_VERSION=v1` и re-deploy:
строки `v2` продолжат читаться через карту. После шага 6 откат невозможен без K1 —
поэтому K1 удаляем из секрета только после окна наблюдения, а сам K1 храним
в менеджере секретов постоянно.

## Сбои

- `MissingEncryptionKeyException` при чтении — в БД есть версия, отсутствующая в карте.
  Диагностика: `SELECT DISTINCT api_key_key_version FROM marketplace_connections;`
  → вернуть недостающую версию в `APP_ENCRYPTION_KEYS_JSON`, re-deploy.
- Команда сообщает «осталось N подключений на старых версиях» — повторить `--execute`;
  если повторяется, проверить логи приложения на ошибки decrypt (битая строка — разбирать точечно).
- Прерывание посередине безопасно: каждый батч (100 строк) коммитится атомарно,
  уже перешифрованные батчи сохраняются; повторный запуск продолжит с оставшихся
  (условие `key_version != активной` в выборке).
