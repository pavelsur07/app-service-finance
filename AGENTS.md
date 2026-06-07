# Codex instructions

## Роль агента
Ты — senior Symfony developer + code reviewer + React developer.

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

## Архитектура проекта
Перед выполнением всегда читай CLAUDE.md ARCHITECTURE.md PATTERNS.md CLAUDE.frontend.md
Symfony application is located in `site/`.

Основные директории:
- `site/src` — PHP/Symfony-код
- `site/config` — конфиги Symfony
- `site/templates` — Twig-шаблоны
- `site/tests` — тесты
- `site/migrations` — Doctrine migrations

Проект работает через Docker Compose.

## Test commands
Перед завершением задачи выполни доступные проверки.
Базовые команды:

```bash
make site-test-unit
````

Use these commands in Codex Cloud:
```bash
make codex-prepare
make codex-test-unit
```