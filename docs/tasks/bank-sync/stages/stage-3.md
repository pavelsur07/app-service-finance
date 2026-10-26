# Stage 3: OAuth-подключение, общая часть

Risk: HIGH-LOCAL (миграция, секреты, блокировки, внешний HTTP авторизации).
stage_base_commit: `6f9d4fe90` (ветка `feat/bank-sync-stage3` от `master`, merge #2799).
Поставка: A (Stage 1–3, `plan.md` §5).

## 1. Какую задачу решаем

Сейчас подключение к банку — статический токен, который пользователь вставляет в
форму, и флаг «активно». Альфа (и большинство банков для SaaS) так не работает:
клиент входит в банк, банк возвращает код, мы меняем его на пару токенов OAuth 2.0.
Access-токен живёт час, refresh — ограниченно (у Альфы 180 дней и **не больше двух
обновлений**), согласие клиента — год. Отсюда три проблемы, которые надо решить до
адаптера конкретного банка:

1. **Вход.** Нужен безопасный цикл «редирект в банк → callback → обмен кода»: защита
   от подделки (`state`), от перехвата кода (PKCE S256), от чужой компании; код
   одноразовый и живёт 2 минуты; повторный вход заменяет токены того же
   подключения, а не плодит второе.
2. **Обновление.** Access надо обновлять заранее и строго одним процессом: два
   воркера, обновившие один refresh-токен, сожгут его (второй ответ банка — отказ,
   первый новый refresh перезапишется). Новый refresh сохраняется сразу.
3. **Потеря доступа.** Отозванное согласие сейчас даёт `error` в GlitchTip на каждом
   прогоне cron. Нужно состояние «нужен повторный вход»: одно `warning`, подключение
   больше не ставится в очередь, пользователь видит причину.

Банк-специфики нет: адреса, scope и разбор ответов банка — интерфейс провайдера
авторизации, реализация Альфы — Stage 5. Проверяется на заглушке.

## 2. Что будет результатом

```
site/src/BankSync/
  Enum/BankConnectionStatus.php        active | needs_reauth | revoked | disabled
  Entity/BankConnection.php            status вместо is_active; переходы:
                                       reauthorize(), markNeedsReauth(), markRevoked(),
                                       disable(), enable(); canSync()
  Domain/OAuth/
    BankAuthorizationProviderInterface.php  тег bank_sync.authorization_provider:
                                       code(), authorizationUrl(), exchangeCode(), refresh()
    AuthorizationRequest.php           state, code_challenge, redirect_uri, base_url
    OAuthTokens.php                    access, refresh, accessExpiresAt, consentExpiresAt?
    Pkce.php                           verifier (43–128 символов), challenge S256
  Exception/BankAuthorizationRejectedException.php   invalid_grant / 400 / 401 → повторный вход
  Infrastructure/OAuth/
    OAuthTokenEndpointClient.php       POST form-urlencoded на token endpoint, разбор
                                       access_token/refresh_token/expires_in; без тела в логах
    BankAuthorizationProviderRegistry.php
    BankConnectionTokenLock.php        сессионная advisory-блокировка подключения
                                       (ожидание с таймаутом) + транзакционная для входа
  Infrastructure/Security/BankCredentialsCodec.php   тип oauth2 в том же JSON
  Application/
    OAuth/AuthorizationSessionStore.php  state → {company, bank, base_url, connection_id,
                                         verifier, expires_at} в сессии, одноразово, 10 мин
    StartBankAuthorizationAction.php   state + PKCE → URL банка
    CompleteBankAuthorizationAction.php  новое подключение или reauthorize() того же,
                                         под транзакционной блокировкой подключения
    BankAccessResolver.php             токен для запроса; oauth2 истекает в ближайшие
                                       5 минут → обновление (без commit — его делает Handler)
  Controller/OAuthStartController.php    POST /bank-connections/oauth/start (CSRF)
  Controller/OAuthCallbackController.php GET /bank-connections/oauth/callback
  MessageHandler/SyncBankConnectionHandler.php  обновление токена под блокировкой до
                                       загрузки; 401 банка → принудительное обновление;
                                       отказ обновления → needs_reauth + одно warning
  Command/EnqueueBankSyncCommand.php   только status = active
migrations/Version2026102615…php      status (+ CHECK), status_reason, status_changed_at;
                                       is_active → status; is_active удаляется
```

Устройство:
- **Состояние входа** — в сессии пользователя, ключ — `state` (UUID v4). В нём
  компания, банк, среда (`base_url`), ID подключения (новый UUID v7 — он же ID
  создаваемого подключения, или ID переподключаемого) и PKCE `code_verifier`. Callback
  забирает запись и сразу удаляет (повтор того же `state` — отказ), проверяет срок
  10 минут и что активная компания та же.
- **Обмен кода** — `CompleteBankAuthorizationAction` под сессионной блокировкой токенов
  подключения (до 10 с): обмен через провайдера (HTTP вне транзакции) →
  `wrapInTransaction` → найти по ID и компании, перечитать → `reauthorize()` или создать
  → снять блокировку. (План был `pg_advisory_xact_lock` только на запись; ревью Codex:
  два параллельных входа сохраняли бы пары в обратном порядке — блокировка расширена
  на обмен.)
- **Обновление токена** в Handler, до первого запроса к банку:
  сессионная блокировка подключения (ждать до 30 с) → перечитать подключение из БД →
  если access всё ещё истекает в ближайшие 5 минут → `refresh()` (HTTP) → новые
  токены в сущность → `flush` сразу → снять блокировку. Второй воркер, дождавшись
  блокировки, перечитывает и видит свежий токен — второго запроса к банку нет.
  Та же блокировка (тот же ключ) у входа: переподключение не перетрётся обновлением.
- **Отказ обновления** (`invalid_grant`, 400/401 token endpoint) →
  `markNeedsReauth('refresh_rejected')`, commit, одно `warning`, сообщение
  завершается без ретрая и без `error` (в очередь `failed` не попадает). 5xx/сеть —
  обычный ретрай.
- **401 от API данных**: OAuth → принудительное обновление под блокировкой; успех →
  исключение для ретрая транспорта с новым токеном; отказ → `needs_reauth`.
  Статический токен → сразу `needs_reauth('token_rejected')`.
- **Статусы и очередь**: `bank-sync:enqueue` и кнопка «Загрузить» берут только
  `active`; Handler пропускает не-`active` с `info`.

Матрица переходов (всё прочее — `\DomainException`):

| из \ в | active | needs_reauth | revoked | disabled |
|---|---|---|---|---|
| active | `reauthorize` | `markNeedsReauth` | `markRevoked` | `disable` |
| needs_reauth | `reauthorize` | — (без изменений) | `markRevoked` | `disable` |
| revoked | `reauthorize` (новое согласие) | — | — | — |
| disabled | `enable`, `reauthorize` | — | `markRevoked` | — |

`revoked` возвращается в `active` только через `reauthorize()` с новыми токенами;
`enable()` из `revoked` и `needs_reauth` запрещён.

### Решения, уточнённые при детализации

- **`Client_ID`/`Client_Secret` из `.env` — в Stage 5**, а не здесь: их читает
  провайдер конкретного банка, в Stage 3 провайдера нет; пустые переменные без
  потребителя — мёртвая конфигурация. Общий клиент token endpoint принимает пару
  аргументом. Запись — в «Историю плана».
- **UI кнопок «Подключить через банк» / «Переподключить» — Stage 10.** Здесь только
  маршруты и статус в списке подключений (текстом), чтобы потеря доступа была видна.
- **Срок согласия** хранится в зашифрованном JSON (как в плане); ночная сверка сроков
  — Stage 5.
- **`is_active` заменяется `status`**: флаг и `disabled` дублировали бы друг друга. На
  проде подключений 0 (проверено 26.10), перенос значений всё равно в миграции.
- HTTP обновления токена внутри сессионной блокировки, но вне транзакции — правило
  «HTTP вне транзакции» соблюдено; окно «банк выдал новый refresh, commit не прошёл»
  минимально (commit сразу после ответа) и логируется `error`: это потеря токена.

## 3. Что считаем выполненной работой

- Подключение проходит полный цикл против заглушки: start → редирект на URL
  провайдера с `state` и `code_challenge` → callback → токены в БД только
  шифротекстом → загрузка выписки работает с access-токеном.
- Истекающий access обновляется до запроса к банку; новый refresh сохранён; два
  воркера дают один запрос обновления.
- Отказ обновления → `needs_reauth`, одно `warning`, подключение не ставится в
  очередь, `error` нет.
- Повторный вход — то же подключение, учётные данные заменены, статус `active`.
- Миграция `up → down → up` локально; `ARCHITECTURE.md`, README модуля обновлены.
- Гейты зелёные, ревью внутреннее и внешнее, handoff.

## 4. Work items

| # | Что | Коммит |
|---|---|---|
| 3.1 | Прод read-only: `bank_sync_connection` пуста; отметки прод-сверки Stage 2 | — |
| 3.2 | Baseline: тесты BankSync зелёные | — |
| 3.3 | `BankConnectionStatus`, переходы в сущности, миграция, репозиторий/команда/контроллер/форма/шаблоны на статус + unit матрицы | 1 |
| 3.4 | `OAuthTokens`, `Pkce`, тип `oauth2` в кодеке + unit | 2 |
| 3.5 | Интерфейс провайдера авторизации, реестр, `OAuthTokenEndpointClient` + unit (MockHttpClient) | 2 |
| 3.6 | Сессионное хранилище state, Start/Complete Action, контроллеры start/callback + functional | 3 |
| 3.7 | Блокировка подключения, `BankAccessResolver`, Handler: обновление, 401, `needs_reauth` + integration/конкурентный тест | 4 |
| 3.8 | `ARCHITECTURE.md`, README BankSync, `plan.md` «История плана» | 5 |
| 3.9 | Гейты: PHPStan, CS, strict_types, unit, integration+functional, миграция up/down/up | — |
| 3.10 | Ревью внутреннее и внешнее; итог, checkpoint, handoff | 6 |

## 5. Чек-лист приёмки

Вход (functional, заглушка провайдера)
- [x] start: редирект на URL провайдера; в нём `state` (UUID), `code_challenge`
      (S256 от `code_verifier`), `redirect_uri` на наш callback; без прав
      `FINANCE_WRITE` и без CSRF — отказ.
- [x] callback с верным `state` и кодом → подключение `active`, в строке БД токенов
      открытым текстом нет, расшифровываются в выданные заглушкой.
- [x] неверный, повторный или просроченный (> 10 мин) `state` → отказ, ничего не
      сохранено.
- [x] callback при другой активной компании → отказ, ничего не сохранено.
- [x] отказ token endpoint на обмен (неверный `code_verifier`) → отказ, ничего не
      сохранено.
- [x] `error=access_denied` от банка → сообщение пользователю, ничего не сохранено.
- [x] повторный вход в существующее подключение → та же строка, токены заменены,
      статус `active` (в т.ч. из `needs_reauth` и `revoked`), второй строки нет.

Обновление (unit + integration)
- [x] access истекает через ≤ 5 минут → обновляется до запроса к банку; раньше — нет.
- [x] новый refresh из ответа сохранён (commit до запроса данных).
- [x] два воркера: второй ждёт блокировку, перечитывает и не обращается к token
      endpoint (один запрос обновления) — тест на реальных commit
      (`PostgresResetTestCase`).
- [x] 400/401 token endpoint → `needs_reauth`, одно `warning`, без `error`,
      сообщение не ретраится.
- [x] 5xx/сеть token endpoint → ретрай транспорта, статус не меняется.
- [x] 401 API данных: OAuth → принудительное обновление и один повтор в том же
      прогоне, 401 и на повторе → `needs_reauth`; статический токен → `needs_reauth`;
      учётные данные, заменённые за время прогона, не гасятся.
- [x] смена учётных данных и статуса (воркер, callback, форма) — только под
      блокировкой токенов на перечитанной строке; `state` одноразов и при параллельных
      запросах (Redis `SET NX`); среда подключения, сменённая во время входа, — отказ
      до обмена кода.

Статусы
- [x] unit на всю матрицу переходов; `revoked` → `active` только `reauthorize()`.
- [x] `bank-sync:enqueue` и кнопка «Загрузить» не берут не-`active`; Handler
      пропускает не-`active` с `info`.
- [x] статус и причина видны в списке подключений.

Безопасность
- [x] токены, код, `code_verifier`, `client_secret` не попадают в логи, исключения,
      сессию (кроме verifier до callback), вывод; тело ответа token endpoint не
      логируется.
- [x] все запросы репозитория — с компанией; callback ищет подключение по ID и
      компании из state, совпадающей с активной.

Архитектура
- [x] транзакции только в Action/Handler; блокировки: подключение (сессионная) раньше
      блокировки счёта импорта; HTTP вне транзакций.
- [x] миграция `up → down → up`; на проде 0 строк — данных не трогает.
- [x] `ModuleBoundaryRules`, PHPStan level 8, baseline не вырос; CS, strict_types.

Документация
- [x] `ARCHITECTURE.md` (BankSync: статусы, OAuth, блокировки, Changelog); README
      BankSync; «История плана» — перенос `.env`-пары в Stage 5. (Отдельного README у
      модуля нет — контракт целиком в `ARCHITECTURE.md`, новый файл не заводился.)

Гейты и ревью
- [x] unit, integration+functional — зелёные.
- [x] внутреннее и внешнее (Codex) ревью — замечания закрыты.
- [x] CI PR зелёный.

Прод (после деплоя, read-only)
- [x] миграция применена; `bank_sync_connection` с колонкой `status`, без `is_active`.
- [x] страница «Банки / Подключения» открывается (ответ 302 на логин без сессии, не 500).

## Итог этапа

Статус: на проде с 26.10 (PR #2800, merge `cbb956ea`; проверки §5 «Прод» — read-only 26.10); миграция `Version20261026170000` (обратима; на проде 0 строк).

Коммиты: `4b8cb2c9f` статус вместо `is_active` · `fd44574d0` токены OAuth, PKCE, token
endpoint · `f7f85d386` вход по OAuth · `f036a21bc` обновление токена под блокировкой ·
`007bd0327` документация · `cb1cad19f` `5d76acd42` `abd8ffd40` — замечания внешнего ревью.

Что сделано против плана:
- всё из §4. Отступления записаны в «Истории плана» (`plan.md`) и в §2:
  пара приложения в `.env` — в Stage 5 (провайдера Альфы здесь нет); отказ обновления
  завершает сообщение без ретрая; кнопки входа — минимальные.
- Callback держит сессионную блокировку от обмена кода до commit (план —
  `pg_advisory_xact_lock` только на запись); транзакционная блокировка удалена.
- 401 после обновления повторяется в том же сообщении, не ретраем транспорта.
- Одноразовость `state` — отметкой в Redis: сессии в Redis без блокировки.
- Сохранение формы существующего подключения тоже берёт блокировку токенов.

Проверки:
- PHPStan полный — 0 ошибок, baseline не изменён; CS (`.php-cs-fixer.php`, без кеша) и
  `strict_types` — чисто.
- BankSync unit + integration + functional — 124 OK. Полный прогон: unit 4052 — OK, integration + functional 3234 — OK.
- Миграция `up → down → up` локально, схема совпадает с маппингом.
- Мутационно красные на старом коде: гонка двух воркеров (`ConcurrentTokenRefreshTest`),
  401 на уже заменённый статический токен, обмен кода под блокировкой, занятое
  подключение при входе, форма поверх отозванного, повторный `state`, смена среды во
  время входа, 401 на обновлённый токен, повтор в том же прогоне.

Ревью:
- внутреннее — правки вошли в коммиты этапа.
- внешнее (Codex, high): раунд 1 — 3 IMPORTANT (устаревший отказ гасит новые учётные
  данные; параллельные повторные входы; форма мимо блокировки); раунд 2 — 2 IMPORTANT
  (неатомарный `state`; смена среды между стартом и callback); раунд 3 — 1 IMPORTANT
  (повторный 401 после обновления); раунд 4 — REVIEW_GREEN.

Follow-up:
- провайдер Альфы, пара приложения, mTLS — Stage 5;
- проверки на проде после деплоя — §5 «Прод».
