## Что сделано
- …

## Чеклист (обязательный)
- [ ] Изменения не ломают текущий функционал
- [ ] Если есть изменения схемы/миграции — выполнен чеклист миграций ниже

### Чеклист миграций
- [ ] `bin/console doctrine:migrations:migrate --no-interaction` проходит на чистой БД
- [ ] Нет `ALTER TABLE` без guard `hasTable`
- [ ] Все `ADD CONSTRAINT` выполнены условно (IF NOT EXISTS / pg_constraint)
- [ ] Имена constraints/индексов заданы явно (`fk_`, `uniq_`, `idx_`)
- [ ] Нет `doctrine:schema:update --force` и ручных SQL правок схемы
