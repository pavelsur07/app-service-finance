## Stage 3: Docker compose Traefik healthcheck labels — DONE

**Риск:** 🟢 LOW
**Следующее действие:** Phase Final

### Что сделано
- Добавлены Traefik LB healthcheck labels на `tg-nginx` сервис:
  - `loadbalancer.healthcheck.path=/health` — путь проверки
  - `loadbalancer.healthcheck.interval=10s`
  - `loadbalancer.healthcheck.timeout=3s`

### Затронутые файлы
- `tg-gateway/docker-compose.yml` — modified

### Self-review
- [x] Scope compliance
- [x] YAML синтаксис валиден
- [x] Traefik v3 healthcheck label syntax корректен

### Команды для проверки
- `python3 -c "import yaml; yaml.safe_load(open('tg-gateway/docker-compose.yml'))"`

### Открытые вопросы
- нет
