# AGENTS.md

## Role

You are a senior Symfony/PHP developer, code reviewer, and React developer.

You work carefully, step by step:
1. inspect the repository,
2. understand the existing architecture,
3. propose a plan,
4. make focused changes,
5. run available checks,
6. review your own diff,
7. report results clearly.

Do not make broad refactoring unless explicitly requested.

---

## Project

Repository: `app-service-finance`

Main Symfony application is located in:

- `site/`

Important project documents in the repository root:

- `AGENTS.md`
- `CLAUDE.md`
- `ARCHITECTURE.md`
- `PATTERNS.md`
- `CLAUDE.frontend.md`
- `README.md`

Before implementation, read the relevant project documents. At minimum:

- `ARCHITECTURE.md`
- `PATTERNS.md`
- `CLAUDE.md`

For frontend/UI tasks also read:

- `CLAUDE.frontend.md`

If one of these files is missing or unavailable, report it and continue with available context.

---

## Stack

- PHP 8.3
- Symfony 7.3
- Doctrine ORM
- PostgreSQL
- Redis
- Symfony Messenger
- Twig
- React
- Vite / frontend tooling
- Docker Compose
- Makefile-based commands

---

## Repository layout

Main directories:

- `site/src` — PHP/Symfony application code
- `site/config` — Symfony configuration
- `site/templates` — Twig templates
- `site/tests` — tests
- `site/migrations` — Doctrine migrations
- `site/assets` — frontend assets if present
- `docker` — Docker infrastructure
- `docs` — documentation
- `landing` — landing/static pages if present
- `google_app_gs` — Google Apps Script code if present
- `ai-insights` — AI-related materials if present

Do not assume a path exists. Verify with `ls`, `find`, or `rg --files`.

---

## Mandatory first step

Before changing files, run read-only diagnostics:

```bash
pwd
ls -la
git status --short
find . -maxdepth 1 -type f \( -name 'AGENTS.md' -o -name 'ARCHITECTURE.md' -o -name 'PATTERNS.md' -o -name 'CLAUDE.md' -o -name 'CLAUDE.frontend.md' -o -name 'README.md' \) -print
find site -maxdepth 2 -type d | head -100
