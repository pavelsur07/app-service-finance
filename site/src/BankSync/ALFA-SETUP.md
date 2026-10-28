# Альфа-Банк на проде: что добавить и как проверить

Пошаговая инструкция для Владельца: как включить песочницу Альфы на проде (этап 7B
`docs/tasks/bank-sync/`). Подробности, разбор ошибок и замена сертификатов — в
runbook `docs/tasks/bank-sync/alfa-demo.md`. Устройство кода — `ARCHITECTURE.md`,
раздел «BankSync → Cash: загрузка выписки».

**Состояние на 28.10.2026.** Код этапов 1–7A на проде. Секретов `ALFA_*` в GitHub
нет, каталог сертификатов пустой — Альфа не настроена: кнопок Альфы нет, ночные
`certificates-check` (07:50) и `consents-sync` (07:52) завершаются без ошибок.
Ничего не сломается, пока шаги ниже не выполнены.

## 1. Что получить от банка

| Что | Где взять | Куда пойдёт |
|---|---|---|
| Подписанный договор Alfa API | банк | без него портал не создаёт интеграцию |
| Тестовая интеграция на портале | портал Alfa API | `redirect_uri` и права (шаг 2) |
| `client_id` и `client_secret` песочницы | портал, тестовая интеграция | секреты `ALFA_SANDBOX_CLIENT_ID`, `ALFA_SANDBOX_CLIENT_SECRET` |
| Сертификат подписи `id_token` — `cer.crt` | портал, тестовая интеграция | файл `id-signing-sandbox.pem` |
| Тестовый сертификат канала `test_cert.zip` | банк | файлы `client.crt`, `client.key` |
| Пароль к `test_cert.zip` | персональный менеджер | только для распаковки, на хосте не хранить |

## 2. Настройка интеграции на портале банка

- `redirect_uri`: `https://app.vashfindir.ru/bank-connections/oauth/callback` —
  точное совпадение, без параметров.
- Права (scope): `openid`, `transactions`, `obru_accounts_le`.

## 3. Файлы на хосте прода

Каталог: `${SERVER_DIR}/shared/alfa/` (рядом с `current`, его создаёт деплой). В
контейнерах он виден как `/app/var/secrets/alfa/` только для чтения: php-fpm,
php-cli, воркер `async_sync`, scheduler.

Подготовить на своей машине, не на хосте:

```bash
unzip test_cert.zip -d alfa-test          # пароль — от менеджера
# Если в архиве есть отдельные PEM сертификата и ключа — взять их как client.crt и client.key.
# Если только .p12:
openssl pkcs12 -in alfa-test/<файл>.p12 -clcerts -nokeys -out client.crt          # для старого .p12 добавить -legacy
openssl pkcs12 -in alfa-test/<файл>.p12 -nocerts -out client.key                   # задаст пароль ключа → ALFA_TLS_KEY_PASSPHRASE
# Сертификат подписи id_token: если cer.crt в DER (не начинается с -----BEGIN):
openssl x509 -inform der -in cer.crt -out id-signing-sandbox.pem                    # иначе просто переименовать
```

Положить на хост три файла:

| Файл на хосте | Права | Путь в секрете |
|---|---|---|
| `${SERVER_DIR}/shared/alfa/client.crt` | `0640` | `/app/var/secrets/alfa/client.crt` |
| `${SERVER_DIR}/shared/alfa/client.key` | `0640` | `/app/var/secrets/alfa/client.key` |
| `${SERVER_DIR}/shared/alfa/id-signing-sandbox.pem` | `0640` | `/app/var/secrets/alfa/id-signing-sandbox.pem` |

Каталог — `0750`. Группа каталога и файлов — `82` (`www-data` в образах Alpine: от
него работает php-fpm, пользователь `app` в php-cli входит в эту группу). Архив
`test_cert.zip`, `.p12` и пароль на хосте не хранить. В git файлы не попадают никогда.

Имена файлов можно выбрать свои: важно только, чтобы путь в секрете совпадал с
именем файла и состоял из `A-Za-z0-9._/-`.

## 4. Секреты в GitHub

Settings → Secrets and variables → Actions → New repository secret. Шесть секретов:

| Секрет | Значение | Ограничения |
|---|---|---|
| `ALFA_TLS_CERT_PATH` | `/app/var/secrets/alfa/client.crt` | путь только под `/app/var/secrets/alfa/`, символы `A-Za-z0-9._/-`, без `..` |
| `ALFA_TLS_KEY_PATH` | `/app/var/secrets/alfa/client.key` | то же |
| `ALFA_TLS_KEY_PASSPHRASE` | пароль ключа `client.key` (пусто, если ключ без пароля — тогда секрет не заводить) | без одинарной кавычки и перевода строки |
| `ALFA_SANDBOX_CLIENT_ID` | `client_id` тестовой интеграции | без одинарной кавычки и перевода строки |
| `ALFA_SANDBOX_CLIENT_SECRET` | `client_secret` тестовой интеграции | то же |
| `ALFA_SANDBOX_ID_SIGNING_CERT_PATH` | `/app/var/secrets/alfa/id-signing-sandbox.pem` | как у путей |

Деплой проверяет эти ограничения до выкатки и при нарушении падает с именем секрета —
прод при этом не меняется.

Пока **не заводить** `ALFA_PROD_CLIENT_ID`, `ALFA_PROD_CLIENT_SECRET`,
`ALFA_PROD_ID_SIGNING_CERT_PATH`: деплой их не передаёт до этапа 11 (прод-интеграция
Альфы). Вход песочницы включается, только когда заданы все три `ALFA_SANDBOX_*`.

## 5. Выкатка

Секреты читаются только при деплое. После добавления секретов и файлов — повторный
деплой текущего master: Actions → «🚀 Deploy to Production» → Run workflow,
`production_action=deploy` (или любой следующий мерж в master).

## 6. Проверка после деплоя

Команды — на хосте в `${SERVER_DIR}/current`. Агенту они пока недоступны:
`channel-probe`, `certificates-check` и `sandbox-check` нет в allowlist `codex-console`.

```bash
docker compose -f docker-compose.prod.yml exec -T site-php-fpm php bin/console bank-sync:alfa:channel-probe --target=sandbox
docker compose -f docker-compose.prod.yml exec -T site-php-fpm php bin/console bank-sync:alfa:certificates-check
```

| Команда | Ожидается | Если иначе |
|---|---|---|
| `channel-probe --target=sandbox` | `TLS channel to https://sandbox.alfabank.ru OK: bank answered HTTP 401…` (401 — нормально: токена нет, TLS пройден) | `certificate required` / `handshake failure` — банк не принял сертификат: проверить пару crt/key, при необходимости дописать промежуточный УЦ банка в `client.crt` (`alfa-demo.md`, «Тестовый сертификат»); `not configured` — файл не читается или неверный пароль, текст называет переменную |
| `certificates-check` | строки `[ok] client #1 serial …: until …` и `[ok] id-signing-sandbox #1 …`, итог `Checked N certificate(s): N ok, 0 warning, 0 error.` | `[error] …: file is not readable or not a complete PEM certificate chain` — права или путь; `[error]` по сроку (≤ 14 дней или истёк) — нужен новый сертификат; `[warning]` — до 30 дней |

Дальше — сценарий в браузере (только `ROLE_ADMIN`, в отдельной тестовой компании,
чтобы данные песочницы не попали в рабочие):

1. `/bank-connections/` → «Войти в Альфа-Банк (песочница)».
2. Вход тестовым пользователем банка (логины — `docs/plan/alfa/kak-eto-testirovat.html`;
   в наши документы не копировать) → согласие → возврат с подключением `active`.
3. «Запустить импорт сейчас» → «Счета банка» → привязать счёт к денежному счёту
   тестовой компании → повторный импорт → операции в ДДС.
4. Проверка API (только чтение, в БД не пишет):
   ```bash
   docker compose -f docker-compose.prod.yml exec -T site-php-fpm php bin/console \
       bank-sync:alfa:sandbox-check --company=<id компании> --connection=<id подключения>
   ```
   В отчёте: какой ключ операции стабилен между двумя чтениями дня, сходятся ли
   обороты, примеры `X-TraceId`. Результат прислать агенту — он внесёт его в
   `alfa-demo.md` и чек-лист этапа 7.
5. Следующее утро: `consents-sync` (07:52) заполняет колонку «Согласие» у подключения.
6. «Удалить» подключение → в банке отзывается refresh-токен.

Песочница должна делать хотя бы один запрос в 3 месяца, иначе банк деактивирует
интеграцию; ночная сверка согласий это обеспечивает.

## 7. Откат

Удалить шесть секретов `ALFA_*` и повторить деплой: переменные станут пустыми, кнопки
Альфы пропадут, гейты вернутся к «не настроено». Затем удалить файлы из
`${SERVER_DIR}/shared/alfa/`. Подключения к песочнице и загруженные операции остаются
в тестовой компании — удалить их через интерфейс («Удалить» у подключения).

## 8. Чек-лист

От банка
- [ ] договор Alfa API подписан
- [ ] тестовая интеграция: `redirect_uri` `https://app.vashfindir.ru/bank-connections/oauth/callback`, scope `openid`, `transactions`, `obru_accounts_le`
- [ ] получены `client_id`, `client_secret`, `cer.crt`
- [ ] получены `test_cert.zip` и пароль

Хост
- [ ] `client.crt`, `client.key`, `id-signing-sandbox.pem` в `${SERVER_DIR}/shared/alfa/`, файлы `0640`, каталог `0750`, группа `82`
- [ ] `test_cert.zip`, `.p12` и пароль на хосте не лежат

GitHub
- [ ] шесть секретов из §4 заведены, пути начинаются с `/app/var/secrets/alfa/` и совпадают с именами файлов
- [ ] `ALFA_PROD_*` не заведены

Деплой и проверка
- [ ] деплой master зелёный (шаг проверки секретов не упал)
- [ ] `channel-probe --target=sandbox` → `OK … HTTP 401`
- [ ] `certificates-check` → `client` и `id-signing-sandbox` с `[ok]`, `0 error`
- [ ] под не-администратором кнопки песочницы нет
- [ ] тестовая компания: вход → подключение `active`
- [ ] счета банка → привязка → операции в ДДС тестовой компании
- [ ] `sandbox-check`: ключ операции, обороты, `X-TraceId` — отчёт передан агенту
- [ ] на следующее утро колонка «Согласие» заполнена, новых `error` в GlitchTip нет
- [ ] удаление подключения отзывает токен в банке

Для агента (по желанию)
- [ ] `bank-sync:alfa:channel-probe`, `bank-sync:alfa:certificates-check`, `bank-sync:alfa:sandbox-check` добавлены в allowlist `codex-console` — тогда проверки §6 агент выполнит сам
