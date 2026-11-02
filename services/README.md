# services/ — монорепо Go/Python-сервисов

Сервисы вне PHP-монолита (`site/`). Линия развития — marketplace-аудит
(продолжение M1/M2; документы аудита удалены из docs 22.10.2026, есть в истории git): fetch, parse и
source normalization маркетплейсов. Symfony остаётся владельцем финансовых
операций, правил, закрытия периодов и posting.

## Правила

- Общие для всех сервисов: [AGENTS.md](AGENTS.md) (обязателен к прочтению).
- Каждый сервис: собственный `AGENTS.md` + `README.md` в своём каталоге.
- Межсервисные контракты: [_contracts/](_contracts/) — единственный источник
  правды, версионируются, после публикации неизменны.

## Сервисы

| Сервис | Язык | Назначение | Статус |
|---|---|---|---|
| [ozon-fetcher](ozon-fetcher/README.md) | Go | Получение финансовых данных Ozon Seller API, публикация source facts | scaffold |
| [wb-fetcher](wb-fetcher/README.md) | Go | Финансовые отчёты Wildberries (детальный отчёт о реализации) в raw S3 | этап 2, не в проде |
| [shared/fetchkit](shared/fetchkit/README.md) | Go | Общее ядро загрузчиков маркетплейсов: очередь задач, исполнитель, лимитер, raw в S3, HTTP API | библиотека, в ozon-fetcher и wb-fetcher |
| [ai-service](ai-service/README.md) | Python | ИИ-задачи для Symfony: pipeline с проверками, несколько LLM | каркас, не в проде |

## Запуск (dev)

Сервисы собираются профилем `services`, чтобы обычный `docker compose up`
монолита их не трогал:

```bash
make ozon-fetcher-build   # сборка образа
make ozon-fetcher-up      # запуск в dev-окружении
make fetchkit-test        # гейты общего ядра (также fetchkit-vet, fetchkit-lint)
```

## Как добавить новый сервис

1. Каталог `services/<name>/` со структурой из `services/AGENTS.md` §4
   (`AGENTS.md`, `README.md`, исходники, `docker/Dockerfile`).
2. Dev-сервис в корневом `docker-compose.yml` с профилем `services`.
3. Таргеты `make <name>-build` / `make <name>-up` / `make <name>-test` /
   `make <name>-migrate` (гейты и миграции — `services/go.md` / `services/python.md`) в корневом `Makefile`.
4. Строка в таблице «Сервисы» выше.
