# Новый интерфейс на DS «Ваш Финдир» — реестр страниц

План — `docs/plan/ds-new-layer.md`. Этапы 1–2 (токены, компоненты кита) действуют на
все страницы сразу; столбец «Содержимое» — перевод разметки страницы (деньги, даты,
вкладки, пагинация, статусы, пустые состояния).

| Страница | Шаблон | Палитра (этап 1) | Компоненты (этап 2) | Содержимое |
|---|---|---|---|---|
| Баланс | `balance/index` | ✓ | этап 2 | этап 3 |
| Статьи | `balance_structure/index`, `edit`, `new` | ✓ | этап 2 | этап 3 |
| Счета | `balance/accounts`, `account_edit`, `target` | ✓ | этап 2 | этап 3 |
| Документы | `balance/journal`, `document_view`, `document_edit`, `reversal`, `_document_lines` | ✓ | этап 2 | этап 3 |
| Движения | `balance/statement` | ✓ | этап 2 | этап 3 |
| Сравнение | `balance/compare` | ✓ | этап 2 | этап 3 |
| Периоды | `balance/periods` | ✓ | этап 2 | этап 3 |
| Карточка счёта / статьи | `balance/card` | ✓ | этап 2 | этап 3 |
| Настройка, права, история | `balance/setup`, `access`, `audit` | ✓ | этап 2 | этап 3 |
| Главная нового слоя | `app/home/index` | ✓ | этап 2 | этап 4 |
| Настройки API | `api/settings/*` (6) | ✓ | этап 2 | этап 4 |
| Админка | `admin/dashboard`, `users/*` (3), `billing`, `ingestion/external_categories`, `marketplace/admin/mapping_error_list` | ✓ | этап 2 | этап 4 |
| Telegram-боты | `telegram/admin/bot/*` (3) | ✓ | этап 2 | этап 4 |
| Вход в приложение | `security/login` | ✓ | этап 2 | этап 4 |
| Вход в админку | `admin/security/login` (отдельная страница) | — | — | этап 4 |
| «Покрытие» маркетплейсов (легаси, CSS кита) | `marketplace/index` | ✓ | этап 2 | — |
