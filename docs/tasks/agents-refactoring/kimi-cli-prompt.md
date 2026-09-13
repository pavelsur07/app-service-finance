# Промт для Kimi CLI — консолидация linked-документов

Задача: консолидация перенесённых из AGENTS.md/CLAUDE.md деталей в linked-документы. Fast Path (только документация). Работай по workflow репозитория: ветка docs/linked-docs-consolidation от master, коммит, push, Draft PR.

ОГРАНИЧЕНИЯ: не трогать AGENTS.md, CLAUDE.md, PATTERNS.md и любой код. Меняются ровно 4 файла: docs/workflow/new-module.md, docs/maintenance/prod-access.md, docs/maintenance/quality-gates.md, ARCHITECTURE.md.

ШАГ 0. git status — дерево должно быть чистым. Если нет — стоп, отчёт.

ПРАВКА 1 — docs/workflow/new-module.md
Прочитай файл целиком. Если раздела со структурой каталогов нет — добавь после заголовка:

## Структура каталогов модуля

```
src/{Module}/Controller/          src/{Module}/Application/
src/{Module}/Controller/Api/      src/{Module}/Application/Command/
src/{Module}/Entity/              src/{Module}/Application/DTO/
src/{Module}/Repository/          src/{Module}/Application/Processor/
src/{Module}/Facade/              src/{Module}/Application/Service/
src/{Module}/Enum/                src/{Module}/Application/Source/
src/{Module}/Form/                src/{Module}/Domain/
src/{Module}/DTO/                 src/{Module}/Domain/ValueObject/
src/{Module}/Message/             src/{Module}/Domain/Service/
src/{Module}/MessageHandler/      src/{Module}/Infrastructure/
src/{Module}/EventSubscriber/     src/{Module}/Infrastructure/Api/
src/{Module}/Exception/           src/{Module}/Infrastructure/Query/
tests/Builders/{Module}/          src/{Module}/Infrastructure/Normalizer/
src/{Module}/Api/Request/         src/{Module}/Api/Response/
```

Затем открой ARCHITECTURE.md, раздел «При добавлении нового модуля — обязательно прописать» (yaml-блоки routes.yaml, doctrine.yaml, messenger.yaml, twig.yaml):
- Если эти yaml-блоки уже есть в docs/workflow/new-module.md — в ARCHITECTURE.md замени весь подраздел «При добавлении нового модуля — обязательно прописать» одной строкой: «Готовые блоки конфигурации нового модуля — docs/workflow/new-module.md».
- Если их там нет — перенеси блоки в new-module.md (после структуры каталогов) и в ARCHITECTURE.md оставь ту же строку-указатель.
Раздел «Конфигурация — где что лежит» (карта config/) в ARCHITECTURE.md НЕ трогать.

ПРАВКА 2 — docs/maintenance/prod-access.md
Прочитай файл. Если путей /var/log/app-service-finance ещё нет — добавь раздел:

## Пути на production

- Логи приложения — stdout/stderr контейнеров.
- Сохраняемые ручные артефакты — `/var/log/app-service-finance/maintenance/`.
- Бэкапы — `/var/backups/app-service-finance/`.
- Временные аудиты — `/var/tmp/app-service-finance.*`.
- Создание или изменение этих путей — production-мутация (одобрение по AGENTS.md §3.3).

ПРАВКА 3 — docs/maintenance/quality-gates.md
Прочитай файл. Если абзац про обязательные проверки уже есть — пропусти и отметь в отчёте. Если нет — добавь:

## Обязательные проверки на master (branch protection)

На `master` обязательны три проверки: `🔬 Static analysis`, `🎨 PHP code style`, `🧪 Unit tests`. Имя job'а в `deploy.yml` — контракт с защитой ветки: переименовал job — обнови список обязательных проверок в том же PR.

PATTERNS.md НЕ редактировать: §23 уже содержит все правила логирования (проверено вручную).

ВЕРИФИКАЦИЯ (обязательно, до коммита):
1. grep -n "Структура каталогов" docs/workflow/new-module.md
2. grep -n "app-service-finance" docs/maintenance/prod-access.md
3. grep -n "Static analysis" docs/maintenance/quality-gates.md
4. grep -n "new-module.md" ARCHITECTURE.md
5. git diff --stat — ровно 4 файла, ни одного лишнего
6. git diff — прочитай собственный дифф: только добавления/переносы, никаких удалений чужого содержимого, кроме заменённого подраздела в ARCHITECTURE.md

Отчёт: что добавлено, что пропущено как уже существующее, файл:строка по каждой правке, ссылка на PR.
