# Stage 6: счета банка и привязка к денежным счетам

Risk: HIGH-LOCAL (миграция в двух модулях, новый контракт `CashFacade`, смена правила
выбора счёта для загрузки выписки).
stage_base_commit: `d66cdf009` (ветка `feat/bank-sync-stage6` от `master`, после merge #2803).
Поставка: B (Stage 4–10, Альфа на проде скрыта, `plan.md` §5).

## 1. Какую задачу решаем

Сейчас загрузка выписки сама решает, в какой денежный счёт класть операции:
`SyncBankConnectionHandler` берёт номера счетов из банка и ищет `MoneyAccount` компании
с тем же номером (сравниваются только цифры, `CashFacade::findStatementImportAccount`).
Это опасно и неудобно:

- **Неявно.** Клиент не видит, какой счёт банка куда грузится. Опечатка в номере
  денежного счёта — и выписка молча не грузится; совпадение номера у двух денежных
  счетов — и выписка уходит в первый попавшийся.
- **Счета банка нигде не видны.** Счёт, для которого нет денежного счёта, только пишет
  `info` в лог — клиент о нём не знает и не может его подключить.
- **Путь списка счетов устарел.** Код ходит в `open-banking/v1.3/accounts`; в
  спецификации банка (`docs/plan/alfa/accounts-ob.yaml`) — только
  `GET /api/jp/open-banking/v3.0/aisp-le/accounts` с обязательным заголовком
  `x-fapi-interaction-id` (UUID v4). Под этот путь запрошен scope `obru_accounts_le`
  (Stage 5).
- **Файл и API не знают друг о друге.** Если счёт грузится из банка по API, ручная
  загрузка файла в тот же счёт даст дубли (у файла и API разные ключи операций).
  Stage 8 должен предупреждать об этом, но Cash не может спросить BankSync
  (`Cash → BankSync` запрещено): признак «выписка из банка по API» должен лежать в
  самом `MoneyAccount`.

Что отдаёт банк (v3.0, `Account`): `accountId` (slug до 40 символов), `status`
(`Enabled` / `Disabled`), `currency` (ISO 4217; на практике у Альфы бывает `RUR`),
`accountType` (`Business`), `accountDescription`, `AccountDetails[]` (`schemeName`
`RU.CBR.BBAN`, `identification` — номер счёта), `Owner` (наименование и ИНН),
`Servicer` (банк: наименование, БИК `RU.CBR.BIC`). Не больше 50 счетов в ответе;
`Meta.totalPages` — число страниц, параметра страницы в спецификации нет.

Проверено на проде 27.10 (read-only): `bank_sync_connection` — 0 строк; `money_account`
— 43, из них 22 типа «банк», 16 с номером счёта. Нового правила привязки это не ломает:
загружать по API на проде пока нечего.

## 2. Что будет результатом

```
site/src/BankSync/
  Entity/BankAccount.php              счёт банка в подключении: номер, БИК, валюта,
                                      описание, accountId банка, статус в банке,
                                      когда видели, money_account_id (null — не привязан)
  Repository/BankAccountRepository.php
  Application/
    LinkBankAccountAction.php         привязать / перепривязать к денежному счёту
    UnlinkBankAccountAction.php       отвязать
    BankAccountCatalog.php            upsert списка счетов банка (persist, без flush)
  Controller/AccountsController.php   GET /bank-connections/{id}/accounts — список
  Controller/AccountLinkController.php POST …/accounts/{accountId}/link | unlink
  Form/BankAccountLinkType.php        ChoiceType денежных счетов из CashFacade
  Exception/BankAccountNotFoundException, MoneyAccountNotLinkableException,
            MoneyAccountAlreadyLinkedException
  Infrastructure/Bank/Alfa/AlfaApiClient.php   v3.0 + x-fapi-interaction-id
  Infrastructure/Bank/Alfa/AlfaStatementMapper::mapAccounts → полный BankAccountDTO
  Application/DTO/BankAccountDTO.php  + externalId, currency, bic, enabled, description
  MessageHandler/SyncBankConnectionHandler.php  счёт для загрузки — только по привязке
  templates/bank_sync/account/index.html.twig
site/src/Cash/
  Entity/Accounts/MoneyAccount.php    + bank_api_source (код банка или null)
  Facade/CashFacade.php               + getStatementImportAccount(companyId, moneyAccountId)
                                      + markBankApiStatementSource / clearBankApiStatementSource
                                      + listLinkableMoneyAccounts (активные, валюта)
site/migrations/Version2026102…       bank_sync_account + money_account.bank_api_source
```

Поведение:

- **Список счетов банка** хранится в `bank_sync_account` и обновляется при каждой
  загрузке (первый шаг загрузки уже запрашивает счета): новые появляются как «не
  привязан», у известных обновляются описание, статус в банке и «видели в банке»;
  пропавшие из ответа не удаляются (история привязки и курсор), а помечаются
  «нет в ответе банка» и не грузятся.
- **Загрузка** берёт только счета с привязкой, статусом `Enabled` и присутствующие в
  последнем ответе банка; денежный счёт — по `money_account_id` через
  `CashFacade::getStatementImportAccount()` (неактивный или исчезнувший — пропуск с
  `warning`). Непривязанный счёт — `info`, без `error`; счётчики в итоговом логе.
  Поиск по цифрам номера (`findStatementImportAccount`) из загрузки уходит.
- **Привязка** — страница «Счета банка» у подключения: каждый счёт банка с номером,
  валютой, описанием и выпадающим списком денежных счетов компании той же валюты
  (активные, ещё не привязанные к другому счёту банка). Совпадение номера
  подсказывается выбранным пунктом по умолчанию, но сохраняет только клиент.
- **Признак источника** у `MoneyAccount` — `bank_api_source = 'alfa'` ставится при
  привязке и снимается при отвязке, перепривязке на другой денежный счёт и удалении
  подключения — в той же транзакции.
- **Список подключений** показывает число непривязанных счетов и ссылку «Счета банка».

### Решения, уточнённые при детализации

- **Одна таблица `bank_sync_account` вместо `bank_sync_account_link`** (plan.md):
  непривязанный счёт тоже должен где-то жить, иначе его не показать; привязка —
  nullable `money_account_id` в той же строке. Уникальность из плана сохраняется:
  `(company_id, connection_id, account_number)` и частичный
  `(company_id, money_account_id) WHERE money_account_id IS NOT NULL`.
- **Список счетов обновляет загрузка, отдельной кнопки нет.** «Запустить импорт сейчас»
  первым шагом получает счета; привязку можно сделать после первого прогона. Отдельный
  запрос к банку из контроллера потребовал бы второй путь обновления токенов вне
  Handler. Полноценный онбординг (сразу после входа) — Stage 10.
- **Совпадение номера — только подсказка.** Автопривязка повторила бы неявность,
  которую этап убирает; клиент подтверждает одним нажатием.
- **Валюта счёта банка и денежного счёта должны совпадать** (`RUR` → `RUB`): иначе
  Cash отвергнет каждую строку выписки (`ImportBankStatementDayAction`).
- **`Meta.totalPages > 1` — ошибка запроса, а не первые 50 счетов.** Параметра страницы
  в спецификации нет; молча потерять счета хуже, чем остановиться с `error`. Проверить
  в песочнице (Stage 7).
- **`Owner` (наименование, ИНН) не хранится** — не нужен для привязки; БИК и описание
  хранятся (не ПДн, нужны в списке).
- **FK `money_account_id → money_account ON DELETE SET NULL`** только в миграции, как
  FK на `companies`: удаления денежного счёта в Cash сейчас нет, но висячая привязка
  невозможна.
- **Подпись ответа `x-jws-signature` не проверяется** — канал mTLS к банку; проверка
  подписи ответов — вопрос Stage 11, если банк её потребует.

## 3. Что считаем выполненной работой

- Список счетов Альфы запрашивается по v3.0 с `x-fapi-interaction-id` и разбирается
  полностью (номер, БИК, валюта, статус, описание, accountId).
- Загрузка сохраняет все счета банка в подключении; грузит только привязанные
  `Enabled`-счета; непривязанный виден на странице «Счета банка» и в логе как `info`.
- Привязка сохраняется; один денежный счёт нельзя привязать к двум счетам банка;
  повтор привязки — тот же результат; чужой или неактивный денежный счёт, другая
  валюта — отказ с понятным сообщением.
- У привязанного `MoneyAccount` стоит `bank_api_source`, у отвязанного — нет.
- Миграция, `ARCHITECTURE.md` (BankSync, Cash, `CashFacade`), «История плана».
- Гейты зелёные, ревью внутреннее и внешнее, handoff.

## 4. Work items

| # | Что | Коммит |
|---|---|---|
| 6.0 | `stage-5.md` §5 «Прод»: первый ночной прогон `consents-sync` 28.10 07:52, гейт сертификатов, страница | 1 |
| 6.1 | Baseline: тесты BankSync и Cash зелёные | — |
| 6.2 | Альфа: путь v3.0, `x-fapi-interaction-id`, полный `BankAccountDTO`, маппер на фикстуре из спецификации, `RUR` → `RUB`, `totalPages > 1` → ошибка + unit | 2 |
| 6.3 | `BankAccount` + репозиторий + Builder; миграция `bank_sync_account` и `money_account.bank_api_source`; unit Entity | 3 |
| 6.4 | `CashFacade`: `getStatementImportAccount`, `listLinkableMoneyAccounts`, `mark/clearBankApiStatementSource` (persist, коммитит вызывающий) + тесты | 3 |
| 6.5 | Загрузка: каталог счетов в Handler, выбор счёта только по привязке, счётчики, `info` для непривязанных + integration | 4 |
| 6.6 | `LinkBankAccountAction` / `UnlinkBankAccountAction`: IDOR, валюта, активность, уникальность, идемпотентность, признак источника в той же транзакции + integration | 5 |
| 6.7 | Удаление подключения снимает признак источника у привязанных счетов + тест | 5 |
| 6.8 | UI: страница «Счета банка», ссылка и счётчик непривязанных в списке подключений + functional | 6 |
| 6.9 | `ARCHITECTURE.md`, «История плана» | 7 |
| 6.10 | Гейты: PHPStan, CS, strict_types, unit, integration+functional, миграция на чистой БД | — |
| 6.11 | Ревью внутреннее и внешнее; итог, checkpoint, handoff | 8 |

## 5. Чек-лист приёмки

Список счетов Альфы (unit)
- [ ] запрос — `GET {base}/api/jp/open-banking/v3.0/aisp-le/accounts`, Bearer,
      `x-fapi-interaction-id` — UUID v4, новый на каждый запрос; в лог — без номеров.
- [ ] фикстура из спецификации → номер (`RU.CBR.BBAN`), БИК (`RU.CBR.BIC`), валюта,
      `Enabled`/`Disabled`, описание, `accountId`; `RUR` → `RUB`.
- [ ] счёт без `AccountDetails` или без номера — пропуск с `warning`, остальные счета
      разобраны; повтор номера в ответе — один счёт.
- [ ] `Meta.totalPages > 1` или нечитаемый ответ → `BankApiRequestFailedException`.

Каталог счетов и загрузка (integration)
- [ ] первый прогон сохраняет все счета банка как «не привязан», ничего не грузит,
      `info` без номеров, итог — счётчики найдено / привязано / пропущено, без `error`.
- [ ] повторный прогон не плодит строки (unique), обновляет описание и статус.
- [ ] счёт пропал из ответа банка → помечен, не грузится, привязка и курсор целы;
      вернулся → снова грузится.
- [ ] привязанный `Enabled` грузится в свой денежный счёт, даже если номер у
      `MoneyAccount` не заполнен или другой; `Disabled` — не грузится.
- [ ] денежный счёт стал неактивным → пропуск с `warning`, без `error`.
- [ ] два подключения одной компании видят один и тот же счёт банка — у каждого своя
      строка; привязать денежный счёт можно только к одной.

Привязка (integration + functional)
- [ ] привязка сохраняется, `bank_api_source = 'alfa'` у денежного счёта в той же
      транзакции.
- [ ] повторная та же привязка — тот же результат, строк и событий не прибавилось.
- [ ] денежный счёт уже привязан к другому счёту банка → отказ, ничего не изменилось
      (и при гонке двух запросов — unique, а не 500).
- [ ] перепривязка на другой денежный счёт → признак снят со старого, поставлен на
      новый.
- [ ] отвязка → `money_account_id` null, признак снят.
- [ ] валюта не совпадает, денежный счёт неактивный — отказ с сообщением.
- [ ] IDOR: чужой `money_account_id`, чужое подключение, счёт банка другого
      подключения → 404 / отказ, без изменений.
- [ ] права: чтение — `FINANCE` read, привязка — `FINANCE_WRITE`; CSRF.

Удаление подключения
- [ ] признак источника снят у всех привязанных счетов, строки `bank_sync_account`
      удалены каскадом.

Интерфейс (functional)
- [ ] страница «Счета банка»: номер, валюта, описание, статус, «не привязан»; в списке
      выбора — только активные денежные счета той же валюты, не занятые другим счётом
      банка; совпадение номера выбрано по умолчанию.
- [ ] в списке подключений — число непривязанных счетов и ссылка на страницу.

Cash (integration)
- [ ] `getStatementImportAccount` — только счёт своей компании; неактивный → null.
- [ ] `listLinkableMoneyAccounts` — только своя компания, активные, нужная валюта.
- [ ] `mark/clearBankApiStatementSource` — только своя компания, без `flush()`.

Миграция
- [ ] `bank_sync_account` (unique, частичный unique, FK на компанию и подключение с
      `CASCADE`, на денежный счёт с `SET NULL`, индексы), `money_account.bank_api_source`
      nullable; `down()` обратим; на чистой БД зелёный; маппинг совпадает со схемой.

Архитектура
- [ ] `Cash` не зависит от `BankSync`; `BankSync` — только через `CashFacade`
      (`ModuleBoundaryRules`); новые методы Facade возвращают DTO/скаляры.
- [ ] HTTP вне транзакций; `flush()` / `wrapInTransaction()` только в Action/Handler;
      Repository с `companyId`; PHPStan level 8, baseline не вырос; CS, strict_types.
- [ ] номера счетов не попадают в `warning`/`error` (только суффикс в `info`, как
      сейчас).

Документация
- [ ] `ARCHITECTURE.md`: BankSync (таблица, загрузка по привязке, страница), Cash
      (`bank_api_source`), Facade-методы и кто коммитит, Changelog; «История плана».

Гейты и ревью
- [ ] unit, integration+functional — зелёные.
- [ ] внутреннее и внешнее (Codex) ревью — замечания закрыты.
- [ ] CI PR зелёный.

Прод (после деплоя, read-only)
- [ ] миграция применена: `bank_sync_account` есть, `money_account.bank_api_source`
      есть и у всех 43 счетов null.
- [ ] страница «Банки / Подключения» открывается.
