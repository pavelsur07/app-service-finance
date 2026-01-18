# Builders/Shared

Общие builders для всех тестовых слоёв.

- Builders-first: сначала создаём builders, потом тесты.
- Используются в Unit/Integration/Functional.
- Слои тестирования: Unit, Integration, Functional.
- В Integration/Functional применяем явные persist/flush/clear.
- Моки в домене запрещены.
- Не добавляем слой-специфичную логику сюда.
- Модули пока не заведены.
- Держим builders простыми и переиспользуемыми.
