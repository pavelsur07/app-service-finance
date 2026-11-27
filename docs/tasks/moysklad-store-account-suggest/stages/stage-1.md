# Stage 1: правила и UI без ИИ

**Risk:** MEDIUM — новый Action, UI на существующих паттернах, без схемы.

## 1. Задача
Предлагать счёт складу по правилам названия и дать принять предложение по строке и пакетом.

## 2. Результат
- `MoySklad/Domain/StoreAccountRulePolicy` + `StoreAccountSuggestion` — правила, версия `1`.
- `MoySklad/Application/StoreAccountSuggestions` — код счёта → действующий счёт актива компании.
- `AcceptStoreAccountSuggestionsAction` + POST `moysklad_store_accept_suggestions`.
- `SaveStoreBalanceLinkAction(..., onlyIfUnassigned)` — принятие не перетирает назначение, сделанное после показа страницы
  (проверка под тем же company advisory-lock); возвращает, записано ли.
- Страница: блок «Предложение · правило» с причиной, предупреждение текстом, «Принять»; кнопка «Принять все предложения · N».

## 3. Выполнено, когда
Правила на эталоне дают ≥ 60 % без ошибок; тесты и гейты зелёные.

## 4. Чек-лист приёмки
- [x] Эталон: 25 из 36 (69 %), совпадает с разметкой stage-0, ошибок 0.
- [x] Склад с назначением или «не учитывать» — без предложения (unit + functional).
- [x] Нет счёта с кодом у компании или счёт не актива — без предложения (unit).
- [x] «Принять все» не трогает ручное назначение, повтор → `accepted 0`, те же строки (integration).
- [x] Без права проводить в Балансе — `actor_forbidden`, 0 строк (integration).
- [x] Предупреждение у склада площадки видно текстом (functional).
- [x] PHPStan по `src/MoySklad` и тестам модуля — OK; CS (`.php-cs-fixer.php`, без кеша) — исправлено; twigcs, lint:twig — OK.
- [x] `tests/Unit|Integration|Functional/MoySklad` + `tests/Architecture` — 607 OK.

## Итог
Транзакции: «Принять все» — N независимых назначений, у каждого своя транзакция `SaveStoreBalanceLinkAction`;
отказ по складу (счёт занят другим подключением) считается в `failed` и показывается предупреждением.
