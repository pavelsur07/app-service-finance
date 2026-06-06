# Codex instructions

Symfony application is located in `site/`.

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

Перед выполнением всегда читай CLAUDE.md ARCHITECTURE.md PATTERNS.md CLAUDE.frontend.md


## Test commands

Use these commands in Codex Cloud:

```bash
make codex-prepare
make codex-test-unit-filter FILTER=ReconcileMarketplaceAdsCommandTest
make codex-test-unit