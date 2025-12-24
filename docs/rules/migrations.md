# Правила миграций Doctrine

## 1) Любая миграция обязана проходить на чистой базе
**Definition of Done для PR со схемой:**
- поднять пустой Postgres
- выполнить: `bin/console doctrine:migrations:migrate --no-interaction`
- миграции проходят без ошибок

## 2) Запрещены ALTER/ADD CONSTRAINT без guard-проверок
### 2.1 ALTER TABLE
Перед любыми SQL по таблице:
- `if (!$schema->hasTable('table_name')) { return; }`

### 2.2 Добавление колонок/индексов
- Добавлять только после проверки существования (Schema API или системные таблицы)

### 2.3 FK/UNIQUE/CONSTRAINT
Нельзя делать “в лоб”:
- `ALTER TABLE ... ADD CONSTRAINT ...`

Нужно делать условно через PostgreSQL:
- `DO $$ BEGIN IF NOT EXISTS(...) THEN ALTER TABLE ... ADD CONSTRAINT ...; END IF; END $$;`

## 3) Имена constraints/индексов задаём явно
Формат:
- `fk_<table>_<ref>`
- `uniq_<table>_<cols>`
- `idx_<table>_<cols>`

## 4) Запрет на schema:update и ручной SQL в проде
Запрещено:
- `doctrine:schema:update --force`
- ручные изменения схемы в проде
Любые изменения схемы — только миграцией.

## 5) Ветки и мердж миграций
Перед мерджем PR:
- rebase на актуальный main
- повторный прогон миграций на чистой базе

## 6) 1 PR = 1 смысловая миграция
Одна бизнес-правка схемы → один файл миграции (по возможности).

## 7) Чеклист для ревью миграции
- [ ] `migrate` проходит на чистой БД
- [ ] нет `ALTER TABLE ...` без `hasTable` guard
- [ ] все `ADD CONSTRAINT` обёрнуты в `IF NOT EXISTS` (pg_constraint)
- [ ] у constraints/индексов явные имена по стандарту
- [ ] нет ручных SQL “на всякий случай”
- [ ] PR сделан после rebase на main
