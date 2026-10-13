# services/ — монорепо Go/Python-сервисов

Сервисы вне PHP-монолита (`site/`). Линия развития — marketplace-аудит
(`docs/architecture/marketplace-audit/`, продолжение M1/M2): fetch, parse и
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

## Запуск (dev)

Сервисы собираются профилем `services`, чтобы обычный `docker compose up`
монолита их не трогал:

```bash
make ozon-fetcher-build   # сборка образа
make ozon-fetcher-up      # запуск в dev-окружении
```

## Как добавить новый сервис

1. Каталог `services/<name>/` со структурой из `services/AGENTS.md` §4
   (`AGENTS.md`, `README.md`, исходники, `docker/Dockerfile`).
2. Dev-сервис в корневом `docker-compose.yml` с профилем `services`.
3. Таргеты `make <name>-build` / `make <name>-up` в корневом `Makefile`.
4. Строка в таблице «Сервисы» выше.
