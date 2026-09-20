### Stage 2: API и нормализация ответа — CLOSED

**Risk:** MEDIUM. **Stage base commit:** `421908c2334ad25abba86057e0ed452838a263ad`. **Work items:** 2.1, 2.2.

#### What was done

- Клиент читает страницу `/entity/counterparty` с `limit`, `offset`, `order=id,asc`, явным архивным фильтром, Bearer и точным `Accept`. Redirect и скрытые HTTP retries отключены; ошибки сводятся к безопасным категориям, `X-Lognex-Retry-After` ограничен.
- Парсер проверяет `accountId`, UUID, архив, обязательные поля и длины; необязательные пустые строки превращает в `NULL`; время MSK переводит в UTC. Контакты, вложенные реквизиты и произвольный JSON не переносятся в DTO.
- Тесты используют обезличенные ответы действительного тестового аккаунта для организаций, ИП, физлица и архива.

#### Definition of Done

- [x] HTTP параметры и границы проверены MockHttpClient.
- [x] 401/403/429/5xx/redirect/ошибка транспорта/невалидный JSON классифицируются безопасно.
- [x] Нормализация проверена на пяти JSON-фикстурах, в том числе на пустой странице.
- [x] Несовпадение `accountId` и обязательных полей отклоняется без PII в ошибке.

#### Checks and review

- `tests/Unit/MoySklad` — 77 tests / 325 assertions, PASS.
- Focused PHPStan — no errors; focused CS Fixer — 0 fixable files; `git diff --check` — PASS.
- Internal review: 0 BLOCKER, 1 IMPORTANT fixed — пустой объект `rows: {}` при associative JSON decode был неотличим от `[]`; форма JSON проверяется отдельно. 0 open findings.
- External review: не требуется для Stage MEDIUM; общий review будет на handoff.

#### Risks / next

- Порядок страниц не даёт снимка коллекции при изменениях в источнике; повторный полный проход предусмотрен Stage 3. Следующий Stage — транзакции страниц, cursor и Messenger.
