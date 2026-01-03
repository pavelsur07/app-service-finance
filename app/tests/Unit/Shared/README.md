# Unit/Shared

Общее для unit-тестов (MVP).

- Builders-first: тестовые данные берём из builders.
- Слои тестирования: Unit, Integration, Functional.
- В Integration/Functional всегда явные persist/flush/clear.
- Моки в домене запрещены.
- Unit проверяет чистую логику без инфраструктуры.
- Подготовку данных выносим в Builders/Shared.
- Модули пока отсутствуют.
- Следуем минимализму и читаемости.
