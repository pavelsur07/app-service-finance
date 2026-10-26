# Ротация ключа шифрования секретов

Как безопасно сменить ключ шифрования (`v1 → v2`, далее по той же схеме) для всех
зашифрованных секретов, полностью через GitHub Secrets, без key-файла на хосте и без
даунтайма.

## Что шифруется этим ключом

Один ключ (`APP_ENCRYPTION_*`) на все модули. Команда `app:security:rotate-keys`
знает их все:

| Модуль (`--module`) | Таблица | Поле | Где версия ключа |
|---|---|---|---|
| `marketplace` | `marketplace_connections` | `api_key` | колонка `api_key_key_version` |
| `moysklad` | `moysklad_connections` | `access_token`, `refresh_token` | `keyVersion` внутри JSON `*_encrypted` |
| `bank_sync` | `bank_sync_connection` | `credentials` | колонка `credentials_key_version` (и внутри JSON) |

Новый зашифрованный секрет в модуле обязан получить ротатор
(`SecretRotatorInterface`, `ARCHITECTURE.md` → «Чувствительные данные»), иначе этот
список неполон и удаление старого ключа его уничтожит.

## Модель

- Каждый секрет хранит версию ключа, которым зашифрован.
- Приложение читает несколько версий одновременно из `APP_ENCRYPTION_KEYS_JSON`
  (карта `{"v1":"base64...","v2":"base64..."}`).
- Запись всегда идёт активной версией (`APP_ENCRYPTION_CURRENT_KEY_VERSION`).
- Приоритет источников ключа: файл `APP_ENCRYPTION_KEY_FILE` → карта `APP_ENCRYPTION_KEYS_JSON`
  → `APP_ENCRYPTION_FALLBACK_KEY` (только активная версия, legacy-схема).

**Железные правила:**
1. Старая версия ключа живёт в секрете, пока `app:security:rotate-keys` показывает на
   ней хотя бы одну запись в любом модуле. Удалил раньше — потерял данные необратимо.
2. Значение секрета — JSON в одинарных кавычках при прокидывании в `.env`; само значение не должно содержать символ `'`.
3. Ключ — base64 от 32 байт (`openssl rand -base64 32`).

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

После обновления secrets вручную запустить workflow `🚀 Deploy to Production`
с action `deploy` на текущем `master`, если нового deployable-коммита нет.
Push deployable-изменений в `master` запускает тот же production deploy
автоматически; ручной action нужен для повторного deploy без нового коммита.
После деплоя:
- секреты на `v1` читаются через карту — ничего не ломается;
- новые и изменённые секреты шифруются уже `v2`.

Проверка, что переменные доехали (не печатает секреты):

```bash
docker exec site-messenger-worker-sync bin/console app:security:rotate-keys
# «Активная версия ключа: v2» + таблица module / field / key_version / records / key
```

В колонке `key` у каждой версии должно быть `есть`. `НЕТ` — версия, на которой лежат
записи, отсутствует в карте: команда завершится с кодом 1; вернуть ключ в
`APP_ENCRYPTION_KEYS_JSON` и повторить деплой.

### 4. Ротация данных

```bash
# dry-run: распределение по версиям и сколько требует ротации
docker exec site-messenger-worker-sync bin/console app:security:rotate-keys

# выполнение (все модули; один модуль — --module=moysklad)
docker exec site-messenger-worker-sync bin/console app:security:rotate-keys --execute

# контроль: «Делать нечего»
docker exec site-messenger-worker-sync bin/console app:security:rotate-keys
```

Команда идемпотентна: порции по 100 секретов, каждая — отдельная транзакция.
Воркеры останавливать не нужно: чтение старых версий работает всё время перехода, а
секрет, который пользователь сохранил во время ротации, не перезаписывается старым
значением (запись идёт только если значение не изменилось с момента чтения).

`app:marketplace:rotate-connection-keys` — устаревшее имя того же для одного модуля
(`--module=marketplace`); для смены ключа не годится — пропустит MoySklad и банки.

### 5. Проверка

- В UI `/marketplace` → «Проверить» у подключения — живой API-вызов с расшифрованным
  ключом; у МойСклад — проверка подключения.
- `app:security:rotate-keys` — все строки таблицы на `v2` (или `(без шифра)`), итог
  «Делать нечего».
- SQL (read-only), тот же итог без приложения:
  ```sql
  SELECT api_key_key_version, count(*) FROM marketplace_connections GROUP BY 1;
  SELECT access_token_encrypted::json->>'keyVersion', refresh_token_encrypted::json->>'keyVersion', count(*)
    FROM moysklad_connections GROUP BY 1, 2;
  SELECT credentials_key_version, count(*) FROM bank_sync_connection GROUP BY 1;
  ```

### 6. Уборка (через 1–2 дня наблюдения)

Только если `app:security:rotate-keys` не показывает ни одной записи на `v1`:

- `APP_ENCRYPTION_KEYS_JSON` = `{"v2":"<K2>"}` (убрать `v1`)
- Удалить `APP_ENCRYPTION_FALLBACK_KEY` (оставлен с первой версии схемы, больше не нужен)
- Деплой/re-run для применения.

## Rollback

До шага 6 откат = вернуть `APP_ENCRYPTION_CURRENT_KEY_VERSION=v1` и re-deploy:
секреты `v2` продолжат читаться через карту. После шага 6 откат невозможен без K1 —
поэтому K1 удаляем из секрета только после окна наблюдения, а сам K1 храним
в менеджере секретов постоянно.

## Сбои

- `MissingEncryptionKeyException` при чтении — в БД есть версия, отсутствующая в карте.
  Диагностика: `app:security:rotate-keys` (колонка `key` = `НЕТ`) → вернуть
  недостающую версию в `APP_ENCRYPTION_KEYS_JSON`, re-deploy.
- «<модуль>: порция откатилась (…)» — секрет в порции не расшифровался; готовые порции
  сохранены. Битую запись найти SQL выше и разбирать точечно, затем повторить
  `--execute`.
- «порция из N секретов не перешифрована — значения меняются параллельно» или
  «осталось N секретов на старых версиях» — повторить `--execute`; если повторяется,
  кто-то продолжает писать старой версией (не обновлённый воркер) — проверить, что
  все контейнеры на новом образе с новыми переменными.
- Прерывание посередине безопасно: каждая порция коммитится атомарно, повторный
  запуск продолжает с оставшихся (выборка `версия ≠ активной`).
