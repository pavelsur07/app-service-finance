# S3 Cutover — Runbook (PR 8)

Инфра-этап миграции. Код полностью на `ObjectStorageInterface` (PR 1–7). Здесь —
проброс env и операционный переезд на timeweb S3.

## Что сделано в этом PR (wiring, безопасно)

- `docker-compose.prod.yml` — в `x-php-env` (php-fpm + все воркеры) и в блок env
  `scheduler` добавлены 7 переменных `APP_OBJECT_STORAGE_*`.
- **`APP_OBJECT_STORAGE_DRIVER: local`** — жёстко, поэтому деплой **ничего не меняет**
  в поведении (файлы по-прежнему на локальном диске).
- Конфиг захардкожен: endpoint `https://s3.twcstorage.ru`, bucket
  `ccd24eb8-1c82-4de4-bd69-9706a7b5443b`, region `ru-1`, path-style `0`.
- Ключи — из GitHub Secrets: `APP_OBJECT_STORAGE_S3_ACCESS_KEY`,
  `APP_OBJECT_STORAGE_S3_SECRET_KEY` (экспортируются в `deploy.yml`, jobs deploy+migrations).

Мержим и деплоим этот PR **до** переезда — он лишь готовит окружение.

## Предусловия перед флипом

- [ ] Бакет timeweb создан: приватный, versioning ON, lifecycle (удалять неактуальные
      версии через 30 дней). SSE-S3 — подготовлено, но не включаем (по решению Владельца).
- [ ] Секреты `APP_OBJECT_STORAGE_S3_ACCESS_KEY/SECRET_KEY` в GitHub — добавлены ✅.
- [ ] Этот wiring-PR задеплоен (DRIVER всё ещё `local`).
- [ ] PR 3/4/5 в проде (cash/telegram/parsers) ✅ — S3-гейт по коду закрыт.

## Шаг 1 — Smoke-тест доступа к S3 (до флипа, без риска)

С прод-сервера, реальными ключами, проверить, что бакет доступен и стиль URL верный:

```bash
docker run --rm \
  -e AWS_ACCESS_KEY_ID="<ACCESS_KEY>" \
  -e AWS_SECRET_ACCESS_KEY="<SECRET_KEY>" \
  amazon/aws-cli \
  --endpoint-url https://s3.twcstorage.ru --region ru-1 \
  s3 ls s3://ccd24eb8-1c82-4de4-bd69-9706a7b5443b
```

Если ошибка резолва/доступа к бакету — переключить `APP_OBJECT_STORAGE_S3_PATH_STYLE_ENDPOINT`
на `"1"` (path-style) в compose и повторить.

## Шаг 2 — Sync данных в бакет (копия, приложение живёт)

Объём мал (~72 МБ / 14 файлов), но storage-корень `var/storage` собран из двух томов:
`current_site_storage` (→ var/storage) и `current_site_company_storage` (→ var/storage/companies).
Монтируем оба и синкаем **с сохранением относительных путей = ключей**:

```bash
docker run --rm \
  -v current_site_storage:/data/storage \
  -v current_site_company_storage:/data/storage/companies \
  -e AWS_ACCESS_KEY_ID="<ACCESS_KEY>" \
  -e AWS_SECRET_ACCESS_KEY="<SECRET_KEY>" \
  amazon/aws-cli \
  --endpoint-url https://s3.twcstorage.ru --region ru-1 \
  s3 sync /data/storage s3://ccd24eb8-1c82-4de4-bd69-9706a7b5443b
```

`sync` = копирование (не move), локальный том остаётся. Идемпотентен — можно гонять повторно.

## Шаг 3 — Короткая пауза + догоняющий sync

1. Остановить приём загрузок / воркеры (короткое окно — секунды, данных мало):
   ```bash
   docker compose -f docker-compose.prod.yml stop \
     site-messenger-worker-sync site-messenger-worker-pipeline \
     site-messenger-worker-ads site-messenger-worker-wb-finance scheduler
   ```
2. Повторить `s3 sync` (шаг 2) — догоняет всё, что налилось за время первого синка.

## Шаг 4 — Флип на S3

Изменить `APP_OBJECT_STORAGE_DRIVER: local` → `s3` в **ДВУХ местах**
`docker-compose.prod.yml` (блок `x-php-env` и блок `scheduler`), закоммитить, задеплоить.
Одна строка × 2 — легко ревьюить и откатывать.

После деплоя воркеры/scheduler поднимутся автоматически (rolling update в `deploy.yml`).

## Шаг 5 — Сверка паритета

Пройти по путям файлов из БД и проверить существование в S3 (count + пара пробных read).
Разово, не глазами. (Скрипт-команду добавить при флипе или прогнать `aws s3 ls`-сверку
против списка `storage_path` из соответствующих таблиц.)

## Шаг 6 — Грейс + удаление local

- Локальные тома **не трогать** 2 недели (холодный бэкап).
- Мониторить GlitchTip на ошибки «файл не найден» / `ObjectStorageException`.
- Через 2 недели без инцидентов + зелёная сверка → удалить локальные тома (решение Владельца).

## Откат (в любой момент до удаления local)

Вернуть `APP_OBJECT_STORAGE_DRIVER` → `local` (2 строки) + деплой. Данные на локальном
томе на месте (sync был копией). Файлы, загруженные ПОСЛЕ флипа, останутся только в S3 —
их домигрировать обратно `aws s3 sync s3://... /data/storage` перед откатом, если критично.

## Открытые вопросы
- path-style `0` vs `1` для timeweb — подтвердить smoke-тестом (шаг 1) до флипа.
