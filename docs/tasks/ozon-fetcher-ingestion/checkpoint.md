## Current checkpoint

**Phase:** Stage 13 — проверка на реальном кабинете
**Status:** Stage 0–12 done; Stage 13 — мок-сценарии 1–6 пройдены, ревью группы 5 исправлено; ждём ключи «Вумджой»
**PR (цепочкой):** #2706 (0) → #2707 (1–2) → #2708 (3–6) → #2709 (7–10) → #2710 draft (11–13, `feat/ozon-fetcher-run`)
**Этап 14:** отдельная ветка `infra/ozon-fetcher-deploy` (секреты, CI, прод) — решение Владельца 17.10, не начат

### Exact next action
- Владелец кладёт ключи: `~/.config/ozon-fetcher/vumjoy.env` (`OZON_CLIENT_ID`, `OZON_API_KEY`, 0600)
- запустить `<scratchpad>/real-run.sh`: мок отдаёт `cab-real` с ключами из env, сервис ходит в настоящий Ozon, лимит by-day 0,3 запроса/с, `MAX_PARALLEL_CONNECTIONS=1`
- проверить `/internal/fetch-status`, форму by-day/справочника/реализации/взаиморасчётов, 429; затем удалить данные (`DROP DATABASE ozon_fetcher_real`, `docker rm -f -v asf9-s3`)
- закрыть Stage 13, PR #2710 → ready

### Окружение (dev-машина)
- мои контейнеры: `asf9-pg`, `asf9-redis`, `asf9-s3` (seaweedfs, tmpfs 160m, тома 4 МБ ×24) в `asf9-net`; бинарники стенда — `<scratchpad>/bin`
- Go-тесты: контейнер `--memory=1200m`, `GOMAXPROCS=2`, `-p=1`, по пакету (OOM)
- стенд-БД `ozon_fetcher_stand` создана со старой `00003` — пересоздать перед использованием
