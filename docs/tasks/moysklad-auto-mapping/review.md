# Самопроверка плана автомаппинга

Только документация, Fast Path. Продуктовый код не изменён, данные и
production не читались и не менялись в этой задаче планирования.

Основание: изучены текущие Facade/DTO/Entity и импорт Ozon/WB, ручной writer,
каталожная синхронизация/lock protocol и legacy UI из выпущенного #3016.
План базируется на master b8679062a (#3017 не меняет затронутые контракты).

## Исправлено при внутренней проверке

- IMPORTANT: хранение mutable preview/results в обычной PHP Session могло
  терять параллельные обновления. Выделен существующий Redis cache backend,
  immutable preview и attempt results, короткий distributed mutex индексов,
  строгие TTL/лимиты; cache failure до/после commit различены.
- IMPORTANT: source row lock сам по себе не гарантирует свежесть Entity из
  identity map. Facade обязан вернуть свежий DBAL DTO после FOR SHARE.
- IMPORTANT: PHP trim и PostgreSQL TRIM могут удалять разные символы.
  Определён общий ASCII whitespace набор; NBSP/регистр/ведущие нули сохранены.
- IMPORTANT: bounded выбор характеристики не имел отдельного протокола
  поиска/страниц и сохранения выбранного id. Дополнены index Controller,
  char_q/char_page и find/ChoiceType; правило никогда не угадывается по имени.
- MINOR: уточнены типы PreviewItem/ApplyItem и владелец проверок selectedIds;
  storage tests отделены от проверки бизнес-команды массового применения.

Перепроверены: source/target принадлежность; truncation sentinel до policy;
архивные дубли и неопределимые размеры; accepted replay до catalog lock;
lock удерживается после callback до commit; closed EM прекращает batch;
GET summary использует свой attemptId и текущие связи; old manual совместим.

Проверка покрытия: правила TASK назначены Stage1, preview concurrency Stage2,
write/race/partial failure Stage3, HTTP/legacy UX Stage4. Нет реализации
штрихкодов, фоновых задач, новых финансовых правил или смешения с Catalog.

Метрики внутренней самопроверки: B/I found 0/4, MINOR1; всё исправлено,
open B/I0/0. Внешнее ревью: не запускалось (Fast Path, AGENTS.md §7.2).
Это не ревью будущего кода: при реализации обязательны новые reviews и gates.

Проверки документов: ссылки, code fences, whitespace, четыре Stage и существующие
пути исходников PASS. Первоначальная служебная проверка искала «после» с
учётом регистра и отвергла «ПОСЛЕ»; нормализована проверка, текст протокола верен.
PHP/npm/БД тесты не запускались: изменения только документационные.
