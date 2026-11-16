# Stage 2: production build / CI proposal

`stage-2-production.patch` — сохранённый точный reviewed patch.
**Одобрен Владельцем и применён 2026-11-16** к Stage 2. Реальные пять файлов
побайтно совпали с проверенным candidate; повторно применять patch не нужно.
Основание отдельного согласования — AGENTS.md §3.3:

> changes to production Docker, Traefik, workers, scheduler, queues, secrets,
> credentials, CI/CD behavior

Применённые изменения:

- `docker/frontend/Dockerfile`: manifest/lock и npm ci пакета DS до npm ci app;
  исходники DS до app build. App prebuild проверяет generated токены.
- `docker/frontend/Dockerfile.dockerignore`: разрешить только apps/packages/ds,
  исключить больше не используемый site/assets/styles/kit. Секреты/локальные
  артефакты остаются исключены; runtime получает только app dist.
- `.github/workflows/deploy.yml`: изменение DS запускает существующие SPA jobs;
  каждый job устанавливает DS перед app, cache key учитывает оба lock-файла.
- `tests/shell/frontend-release-test.py`: существующий контракт фильтров
  дополнен CSS/JSON путями DS (RED на старом workflow, GREEN на candidate).
- `bin/frontend.sh`: front-install устанавливает DS перед выбранными apps;
  это поддерживает existing real-auth workflow и локальное окружение.

Сервисы, сети, порты, credentials, права, deploy-команды, порядок выкладки и
механизм миграций не меняются. site/ не изменяется. Выполненная команда:

```sh
git apply docs/tasks/ds-v3-apps/proposals/stage-2-production.patch
```

До одобрения проверены эквивалентные файлы вне repo в
`/tmp/vf-ds-v3-stage2-proposal/`. После применения проходят фактические
release/build/CI gates PR #3030; production deploy Stage 2 ещё не разрешён.
