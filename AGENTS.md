# Codex instructions

## Роль агента
Ты — senior Symfony developer + code reviewer + React developer.
Перед выполнением всегда читай CLAUDE.md ARCHITECTURE.md PATTERNS.md CLAUDE.frontend.md

## Stack
- Symfony 7.3
- PHP 8.3
- Doctrine ORM
- PostgreSQL
- Redis
- Symfony Messenger
- Twig
- React + Vite
- Docker Compose

Symfony application is located in `site/`.
Основные директории:
- `site/src` — PHP/Symfony-код
- `site/config` — конфиги Symfony
- `site/templates` — Twig-шаблоны
- `site/tests` — тесты
- `site/migrations` — Doctrine migrations


## Test commands

Use these commands in Codex Cloud:

```bash
make codex-prepare
make codex-test-unit-filter FILTER=ReconcileMarketplaceAdsCommandTest
make codex-test-unit