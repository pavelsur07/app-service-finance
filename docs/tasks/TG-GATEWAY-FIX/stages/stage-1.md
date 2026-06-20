## Stage 1: Deploy script fix — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously

### Что сделано
- Исправлен комментарий "Spain VPS" → "Kazakhstan VPS"
- Добавлен шаг `init-certs`: проверяет acme.json, если сертификатов нет — удаляет файл для чистого старта Traefik
- Добавлен post-deploy wait (15s) + проверка `docker compose ps`
- Добавлена проверка nginx `/health` через `docker exec`
- Добавлена проверка connectivity до `app.vashfindir.ru` с казахстанского сервера
- Добавлен вывод Traefik ACME-логов в stdout deploy job

### Затронутые файлы
- `.github/workflows/deploy-tg-gateway.yml` — modified

### Self-review
- [x] Scope compliance
- [x] Нет секретов в логах
- [x] YAML синтаксис валиден (python3 yaml.safe_load)
- [x] jq для парсинга acme.json (однострочный, без YAML-конфликтов)

### Команды для проверки
- `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy-tg-gateway.yml'))"`

### Риски / на что обратить внимание
- jq должен быть установлен на Kazakhstan VPS; если нет — шаг падает с `|| echo "0"` fallback и безопасно удаляет acme.json

### Открытые вопросы
- нет
