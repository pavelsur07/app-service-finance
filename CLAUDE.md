# Claude Code Instructions — VashFinDir

Читай перед любой задачей: ARCHITECTURE.md

## Правила
- Не создавай файлы в src/Entity/, src/Service/, src/Repository/
- Новые Entity: string $companyId, UUID v7 в конструкторе
- Межмодульно: только через Facade (см. ARCHITECTURE.md)
- flush() только в Action, не в Repository
- final class по умолчанию, declare(strict_types=1) везде
- Комментарии на русском языке

## Перед написанием кода
1. Уточни модуль
2. Проверь ARCHITECTURE.md — есть ли уже нужный Facade/Enum
3. Не выдумывай интерфейсы — спрашивай если неясно