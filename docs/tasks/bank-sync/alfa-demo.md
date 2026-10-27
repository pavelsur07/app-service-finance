# Альфа-Банк: канал mTLS, вход Alfa ID и сертификаты — runbook

Как настроить двусторонний TLS до API Альфы и вход клиента через Alfa ID, проверить их и
заменить сертификат без простоя. Устройство в коде — `ARCHITECTURE.md`, раздел «BankSync → Cash: загрузка
выписки» (пункт «Канал Альфы»).

## Что нужно каналу

| Что | Где | В git |
|---|---|---|
| Клиентский сертификат PEM (лист; при необходимости + промежуточный УЦ банка) | файл, путь в `ALFA_TLS_CERT_PATH` | нет |
| Закрытый ключ PEM (можно зашифрованный) | файл, путь в `ALFA_TLS_KEY_PATH` | нет |
| Пароль ключа | `ALFA_TLS_KEY_PASSPHRASE` (пусто — ключ без пароля) | нет |
| CA серверов банка — УЦ Минцифры | `site/config/bank_sync/alfa/ca-bundle.pem` | да (публичные) |

Сертификаты и ключи в git не попадают: `test_cert.zip`, `*.key`, `*.p12`, `*.pfx` в
`.gitignore`. Локально класть в `site/var/secrets/alfa/` (папка `var/` игнорируется).

## Доверенные CA серверов

Проверено 26.10.2026 (`openssl s_client -showcerts`): `sandbox.alfabank.ru` и
`baas.alfabank.ru` отдают цепочку `сервер → Russian Trusted Sub CA (2024) → Russian
Trusted Root CA`. Набор:

| Сертификат | Действует до | SHA-256 |
|---|---|---|
| Russian Trusted Root CA | 2032-02-27 | `D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31` |
| Russian Trusted Sub CA (2024) | 2029-07-19 | `21:55:78:50:36:C9:00:DB:B5:F1:BB:2A:15:69:C8:0C:55:59:5B:D6:BF:94:86:7A:29:BB:DD:BC:7D:88:A3:F2` |

Источник — страница банка «Сертификаты Минцифры» (`docs/plan/alfa/`). Старый Russian
Trusted Sub CA (до 2027-03-06) в набор не входит: сервера банка выпущены от Sub CA 2024,
а для проверки достаточно корня. В папке набора не должно быть других файлов: она же
`capath`, и клиент Альфы доверяет только ей.

Обновить набор (банк сменил УЦ, гейт краснеет на CA):
1. Скачать новые сертификаты Минцифры, сверить отпечатки с опубликованными на
   `gosuslugi.ru/crt`.
2. Проверить, что сервер банка проходит с новым набором:
   `curl -v --cacert новый-набор.pem https://baas.alfabank.ru/` — в выводе
   `SSL certificate verify ok` (ответ `certificate required` дальше — нормально, это
   отсутствие клиентского сертификата).
3. Заменить `ca-bundle.pem` (с комментариями subject/срок/отпечаток), обновить таблицу
   выше, обычный PR.

## Тестовый сертификат (песочница)

Банк выдаёт общий тестовый сертификат `test_cert.zip`; пароль к архиву — у
персонального менеджера. В архиве сертификат, ключ, цепочка УЦ банка
(`alfa-bank_st_ca_root`, `alfa-bank_st_ca_sub2`) и контейнер `.p12`.

1. Распаковать в `site/var/secrets/alfa/` (вне git).
2. Если сертификат и ключ есть отдельными PEM-файлами — использовать их. Если только
   `.p12`:
   ```
   openssl pkcs12 -in test.p12 -clcerts -nokeys -out client.crt
   openssl pkcs12 -in test.p12 -nocerts -out client.key   # спросит пароль .p12 и новый пароль ключа
   ```
   Для OpenSSL 3 и старого `.p12` может понадобиться `-legacy`.
3. Цепочка УЦ банка — **не** CA сервера (это издатели клиентских сертификатов). Если
   банк не примет один лист (ошибка рукопожатия при верном ключе), дописать
   промежуточный в файл сертификата: `cat client.crt alfa-bank_st_ca_sub2.crt > client-chain.crt`.
4. В `site/.env.local`:
   ```
   ALFA_TLS_CERT_PATH=/app/var/secrets/alfa/client.crt
   ALFA_TLS_KEY_PATH=/app/var/secrets/alfa/client.key
   ALFA_TLS_KEY_PASSPHRASE=…
   ```
5. Проверка:
   ```
   php bin/console bank-sync:alfa:channel-probe --target=sandbox
   php bin/console bank-sync:alfa:certificates-check
   ```
   Ожидается `TLS channel to https://sandbox.alfabank.ru OK: bank answered HTTP 401…`
   (токена нет — банк отвечает отказом, но TLS пройден). `FAILED … certificate required`
   или `handshake failure` — сертификат не принят. Ошибка `not configured` — проблема
   файлов или пароля, текст называет переменную.

## Вход через Alfa ID

Клиент подключает Альфу только входом в банк (OIDC, Authorization Code + PKCE + `nonce`);
формы с токеном у Альфы нет. Канал mTLS выше — обязательное условие: token, revoke и
client-consents идут по нему.

| Что | Где | В git |
|---|---|---|
| `client_id` / `client_secret` приложения (своя пара на песочницу и прод) | `ALFA_SANDBOX_CLIENT_ID`, `ALFA_SANDBOX_CLIENT_SECRET`, `ALFA_PROD_CLIENT_ID`, `ALFA_PROD_CLIENT_SECRET` | нет |
| Сертификат подписи `id_token` (`cer.crt` с портала, PEM; можно несколько — на время смены ключа банком) | файл, путь в `ALFA_SANDBOX_ID_SIGNING_CERT_PATH` / `ALFA_PROD_ID_SIGNING_CERT_PATH` | нет |

Среда считается настроенной, только когда заданы все три её переменные; иначе кнопки
«Войти в Альфа-Банк» этой среды в списке подключений нет, а сверка согласий её
пропускает.

Настройка среды:
1. На портале банка в интеграции зарегистрировать `redirect_uri`
   `https://<домен>/bank-connections/oauth/callback` (точное совпадение, без query) и
   права: `openid`, `transactions`, `obru_accounts_le`.
2. Скачать с портала сертификат подписи, при DER — `openssl x509 -inform der -in cer.crt
   -out id-signing.pem`. Положить рядом с клиентским сертификатом (вне git, только
   чтение для контейнера).
3. Заполнить три переменные среды (`site/.env.local` локально, секреты деплоя на проде).
4. Проверка: `bank-sync:alfa:certificates-check` показывает `id-signing-sandbox` /
   `id-signing-production` со сроком; на `/bank-connections/` — кнопка входа этой среды.

Сценарий в песочнице: «Войти в Альфа-Банк (песочница)» → вход тестовым пользователем
банка → возврат на `/bank-connections/` с подключением `active`; «Запустить импорт
сейчас» → счета и выписка; на следующее утро `bank-sync:alfa:consents-sync` заполняет
колонку «Согласие»; «Удалить» отзывает refresh в банке (повторное обновление этим токеном
— отказ банка).

Что значат ошибки:
- flash «Не удалось завершить вход в банк» + `error` «id_token verification failed» с
  `reason`: `signature` — не тот сертификат подписи (банк сменил ключ — скачать новый,
  на время смены положить оба в файл); `issuer`/`audience` — пара приложения другой
  среды; `nonce`/`expired` — повтор старого ответа или часы хоста.
- `error` «bank sign-in is not configured» — переменная среды пуста или файл
  сертификата подписи не читается (текст называет переменную).
- `invalid_client` от token endpoint — неверная пара `client_id`/`client_secret`
  (`BankApiRequestFailedException`, тоже `error`).
- Подключение `needs_reauth` с `insufficient_scope` — банк ответил 403: у согласия нет
  нужных прав, клиенту войти заново; `revoked` с `consent_expired` — согласие истекло
  или отозвано в интерфейсе банка.

## Прод (Stage 11)

1. Ключ RSA 2048 и CSR по конфигу банка `openssl_INNLE.cnf` (ИНН юрлица, ФИО
   представителя, e-mail для уведомлений о сроке):
   `openssl req -new -newkey rsa:2048 -config openssl_INNLE.cnf -keyout client.key -out client.csr`.
2. CSR — на портал банка в прод-интеграцию, сертификат выпускается на 365 дней.
3. Файлы — на хост прода в папку вне репозитория, только чтение для пользователя
   контейнера; volume в `site-php-fpm` (callback входа меняет код на токены), воркер
   `async_sync` и cron (гейт). Переменные `ALFA_TLS_*` — секретами деплоя.
4. После деплоя: `bank-sync:alfa:channel-probe --target=production` → ответ банка;
   `bank-sync:alfa:certificates-check` → клиентский сертификат в выводе, `ok`.
5. Alfa ID прода — раздел «Вход через Alfa ID» с переменными `ALFA_PROD_*`.

## Замена сертификата без простоя

У интеграции одновременно до двух активных сертификатов, поэтому старый работает,
пока выкатывается новый.

1. Гейт пишет `warning` за 30 дней (банк — письмо за месяц). Выпустить **новый ключ** и
   CSR (перевыпуск со старым ключом банк не принимает), получить сертификат на портале —
   старый остаётся активным.
2. Положить новые файлы рядом со старыми под новыми именами, сменить пути в
   `ALFA_TLS_CERT_PATH` / `ALFA_TLS_KEY_PATH` (и пароль), выкатить. Переключение — при
   перезапуске контейнеров деплоем; запросы до перезапуска идут со старым, оба
   действительны.
3. Проверить `channel-probe --target=production` и `certificates-check` (новый срок).
4. Отозвать старый сертификат на портале, удалить его файлы с хоста.

Компрометация ключа — письмо в поддержку банка и перевыпуск по шагам 1–4 немедленно.
