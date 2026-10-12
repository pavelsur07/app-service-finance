# Новый интерфейс на DS «Ваш Финдир» — реестр страниц

План — `docs/plan/ds-new-layer.md`. Этапы 1–2 (токены, компоненты кита) действуют на
все страницы сразу; столбец «Содержимое» — перевод разметки страницы (деньги, даты,
вкладки, пагинация, статусы, пустые состояния).

| Страница | Шаблон | Палитра (этап 1) | Компоненты (этап 2) | Содержимое |
|---|---|---|---|---|
| Баланс | `balance/index` | ✓ | ✓ | ✓ |
| Статьи | `balance_structure/index`, `edit`, `new` | ✓ | ✓ | ✓ |
| Счета | `balance/accounts`, `account_edit`, `target` | ✓ | ✓ | ✓ |
| Документы | `balance/journal`, `document_view`, `document_edit`, `reversal`, `_document_lines` | ✓ | ✓ | ✓ |
| Движения | `balance/statement` | ✓ | ✓ | ✓ |
| Сравнение | `balance/compare` | ✓ | ✓ | ✓ |
| Периоды | `balance/periods` | ✓ | ✓ | ✓ |
| Карточка счёта / статьи | `balance/card` | ✓ | ✓ | ✓ |
| Настройка, права, история | `balance/setup`, `access`, `audit` | ✓ | ✓ | ✓ |
| Главная нового слоя | `app/home/index` | ✓ | ✓ | ✓ |
| Настройки API | `api/settings/*` (6) | ✓ | ✓ | ✓ |
| Админка | `admin/dashboard`, `users/*` (3), `billing`, `ingestion/external_categories`, `marketplace/admin/mapping_error_list` | ✓ | ✓ | ✓ |
| Telegram-боты | `telegram/admin/bot/*` (3) | ✓ | ✓ | ✓ |
| Вход в приложение | `security/login` | ✓ | ✓ | ✓ |
| Вход в админку | `admin/security/login` (отдельная страница, слой DS легаси поверх Tabler) | ✓ | ✓ | ✓ |
| «Покрытие» маркетплейсов (легаси, CSS кита) | `marketplace/index` | ✓ | ✓ | — |
