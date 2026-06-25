# Stale Branches Cleanup — чистка остаточных веток в origin/

> **Эта задача чистит несмерженные ветки в origin/, которые остались после старых работ.**
> Цель — сократить branch-list с 210+ до управляемого числа без потери активной работы.
> Задача — **destructive** (удаляет references в remote). Точки STOP обязательны.

---

## Когда запускать

- Однократно, как «генеральная уборка».
- Можно повторять раз в квартал.
- Только по явному триггеру Владельца.

---

## Что эта задача делает и чего НЕ делает

**Делает:**

- Получает свежий список несмерженных remote-веток в `origin/`.
- Классифицирует каждую ветку по возрасту последнего коммита и автору.
- Группирует семейства похожих названий (`fix-X-AAA`, `fix-X-BBB`, `fix-X-CCC`).
- Формирует список кандидатов на удаление.
- Пишет отчёт `docs/maintenance/stale-branches-cleanup.md`.
- 🛑 STOP — ждёт явного апрува Владельца на удаление.
- После апрува — удаляет ветки командами `git push origin --delete`.
- Пишет финальный отчёт «что удалено, что осталось, почему».

**НЕ делает:**

- **Никогда** не удаляет ветку без апрува Владельца (даже если она «очевидно мёртвая»).
- Не удаляет `origin/master`, `origin/main`, `origin/develop`, `origin/staging`, `origin/HEAD`.
- Не удаляет ветки, последний коммит которых **младше N дней** (по умолчанию 60).
- Не делает `git push --force` или `git reset` куда-либо.
- Не правит локальные ветки.
- Не трогает теги.
- Не правит `.git/config`, `.gitignore`, любые конфиги репозитория.
- Не запускает `git gc`, `git prune` (это отдельная операция, опасная).

Любое отклонение → 🛑 STOP, спросить Владельца.

---

## Входы

| Что | Где | Обязательно |
|---|---|---|
| Имя основной ветки | `git symbolic-ref refs/remotes/origin/HEAD` | да |
| Защищённые ветки | конфигурация (см. ниже) | да |
| Возрастные пороги | конфигурация (см. ниже) | да |

---

## Защищённые ветки (НИКОГДА не удалять)

```
origin/master
origin/main
origin/develop
origin/staging
origin/production
origin/release/*
origin/HEAD
```

Любая другая ветка, помеченная Владельцем как защищённая в Open Questions — также не удаляется.

---

## Возрастные пороги (правила по умолчанию)

| Возраст последнего коммита | Категория | Действие |
|---|---|---|
| < 14 дней | 🟢 ACTIVE | Не трогать, даже не предлагать к удалению |
| 14–59 дней | 🟡 RECENT | В отчёт «к рассмотрению», по умолчанию **не** удалять |
| 60–179 дней | 🟠 STALE | В отчёт «кандидат на удаление», по умолчанию **удалять после апрува** |
| ≥ 180 дней | 🔴 ANCIENT | В отчёт «удалить безусловно», по умолчанию **удалять после апрува** |

Эти пороги настраиваются Владельцем перед запуском.

---

## Фазы

### Phase 0 — Подготовка

1. `cd <repo-root>`, проверить, что мы в git-репозитории.
2. `git fetch --all --prune` — обновить remote-references, удалить локальные ссылки на уже-удалённые remote-ветки.
3. Зафиксировать имя основной ветки:
   ```bash
   MAIN=$(git symbolic-ref refs/remotes/origin/HEAD | sed 's@^refs/remotes/origin/@@')
   ```
4. Создать рабочую директорию для артефактов: `docs/maintenance/`.

---

### Phase 1 — Сбор данных

Для **каждой** несмерженной remote-ветки собрать:

```
{
  name: "origin/claude/fix-sticky-table-header-62inZ",
  last_commit_sha: "abc123",
  last_commit_date: "2025-08-15T14:22:00",
  age_days: 314,
  author_name: "Иван Иванов",
  author_email: "ivan@example.com",
  files_changed_count: 5,
  lines_changed_total: 47,
  family: "fix-sticky-table-header",   // см. Phase 2
  category: "ANCIENT",                  // 🔴 ANCIENT | 🟠 STALE | 🟡 RECENT | 🟢 ACTIVE
  is_protected: false,
}
```

Команды:

```bash
git branch -r --no-merged origin/$MAIN \
  | grep -v 'HEAD ->' \
  | grep -v 'origin/master\|origin/main\|origin/develop\|origin/staging\|origin/production\|origin/release/' \
  | tr -d ' ' \
  > /tmp/all-branches.txt

while read branch; do
  last_ts=$(git log -1 --format='%ct' "$branch" 2>/dev/null)
  now=$(date +%s)
  age=$(( (now - last_ts) / 86400 ))
  author=$(git log -1 --format='%an <%ae>' "$branch")
  files=$(git diff --name-only origin/$MAIN...$branch 2>/dev/null | wc -l)
  echo "$age|$branch|$author|$files"
done < /tmp/all-branches.txt > /tmp/branches-data.txt
```

Сохранить итог в `docs/maintenance/stale-branches-raw.tsv` для воспроизводимости.

---

### Phase 2 — Группировка семейств

Многие ветки — это варианты одной задачи (`fix-X-AAAAA`, `fix-X-BBBBB`, `fix-X-CCCCC` с разными hash-суффиксами от Claude Code/Codex). В семействе обычно нужна максимум **одна** ветка — самая свежая, либо никакой.

Алгоритм:

1. Нормализовать имя ветки: убрать суффикс из 5–8 случайных символов в конце (`-XstYJ`, `-IgStl`), убрать чистые числовые суффиксы (`-2`, `-10`), убрать суффиксы вида `-N` (`-d6aqst`, `-cj7yh0`).
   ```bash
   normalized=$(echo "$branch" \
     | sed -E 's/-[A-Za-z0-9]{5,8}$//' \
     | sed -E 's/-[0-9]+$//' \
     | sed -E 's/-[a-z0-9]{6}$//')
   ```
2. Сгруппировать по нормализованному имени → это `family`.
3. В каждой семье отметить **самую свежую** ветку как `keep_candidate: true`, остальные — `keep_candidate: false`.

В отчёт идут топ-20 семейств по размеру (например, `fix-migration-error-with-balance_categories` × 14, `fix-sticky-table-header` × 3 и т.д.).

---

### Phase 3 — Классификация и план удаления

Для каждой ветки определить **финальное действие**:

| Условие | Действие |
|---|---|
| Защищена (master/main/develop/staging/release/*) | 🛡️ KEEP — не трогать |
| age < 14 дней | 🟢 KEEP — активная |
| age 14–59 дней + одиночная (не в семье) | 🟡 REVIEW — в отчёт, по умолчанию не удалять |
| age 14–59 дней + в семье + не самая свежая | 🟡 DELETE_OLD_DUP — кандидат на удаление, апрув |
| age 60–179 дней + одиночная | 🟠 DELETE_STALE — кандидат на удаление, апрув |
| age 60–179 дней + в семье + не самая свежая | 🟠 DELETE_OLD_DUP — кандидат на удаление, апрув |
| age ≥ 180 дней | 🔴 DELETE_ANCIENT — кандидат на удаление, апрув |

**Важно:** при категориях `DELETE_*` ветка не удаляется сразу — только записывается в план. Удаление — Phase 5, после апрува.

---

### Phase 4 — Сформировать отчёт и STOP

Отчёт в `docs/maintenance/stale-branches-cleanup.md`:

```markdown
# Stale Branches Cleanup — Plan

**Generated at:** <YYYY-MM-DD>
**Base branch:** origin/master
**Total non-merged branches:** <N>

## Summary

| Category | Count | Default action |
|---|---|---|
| 🛡️ KEEP (protected) | <N> | keep |
| 🟢 KEEP (active < 14d) | <N> | keep |
| 🟡 REVIEW (14–59d, single) | <N> | manual decision |
| 🟡 DELETE_OLD_DUP (14–59d, duplicate in family) | <N> | delete after approval |
| 🟠 DELETE_STALE (60–179d) | <N> | delete after approval |
| 🔴 DELETE_ANCIENT (≥ 180d) | <N> | delete after approval |

**Branches proposed for deletion:** <N>
**Branches recommended to keep:** <N>

## Top branch families (>1 branch per family)

| Family | Count | Newest branch | Action |
|---|---|---|---|
| `fix-migration-error-with-balance_categories` | 14 | 2025-09-12 / origin/codex/...-uizd5p | keep newest, delete 13 others |
| `add-ci-job-for-empty-db-migration` | 5 | 2025-08-20 / ... | keep newest, delete 4 others |
| `fix-sticky-table-header` | 3 | 2025-08-05 / ...62inZ | keep newest, delete 2 others |
| ... |

## Full deletion plan

### 🔴 DELETE_ANCIENT (≥180 days)

| Branch | Age | Author | Last commit |
|---|---|---|---|
| origin/claude/foo-bar | 412d | Ivan I. | 2024-12-... |
| ... |

(полный список)

### 🟠 DELETE_STALE (60–179 days)

(полный список)

### 🟡 DELETE_OLD_DUP (14–59 days, duplicate in family)

(полный список)

## Branches to keep (for reference)

### 🟢 ACTIVE (< 14 days, not deleted regardless)

| Branch | Age | Author |
|---|---|---|
| origin/feature/active-work | 3d | Anna A. |
| ... |

### 🟡 REVIEW (14–59 days, single — manual decision)

| Branch | Age | Author | Notes |
|---|---|---|---|
| origin/claude/some-old-but-singular-work | 35d | Pavel P. | manual |
| ... |

## Open questions for Owner

1. Подтвердить пороги (14 / 60 / 180 дней) или скорректировать.
2. Есть ли защищённые ветки помимо стандартного списка?
3. Для категории 🟡 REVIEW — массово удалить, массово оставить, или просмотреть глазами?
4. Уведомить ли авторов веток перед удалением? (email-список авторов прилагается)

## Authors list (for optional notification)

(список уникальных author_email из веток, помеченных к удалению, с количеством их веток)

---

🛑 **STOP. План удаления готов. Ждать апрува Владельца на Phase 5.**
```

🛑 **STOP.** Без явного апрува (`approve the deletion plan` или эквивалент) Phase 5 не запускается.

---

### Phase 5 — Удаление (только после апрува)

После явного апрува Владельца:

1. **Бэкап**: сохранить список удаляемых веток с их sha в `docs/maintenance/stale-branches-deleted-<date>.txt`. Это файл, по которому можно восстановить ветку командой `git branch <name> <sha> && git push origin <name>`, если что-то пошло не так.

2. Удалить ветки **батчами по 10** с паузой 2 секунды между батчами:
   ```bash
   while read branch_with_sha; do
     branch=$(echo "$branch_with_sha" | awk '{print $1}')
     # branch имеет вид "origin/feature/x" → нужно "feature/x"
     remote_name=${branch#origin/}
     echo "Deleting $remote_name..."
     git push origin --delete "$remote_name" || echo "FAILED: $remote_name"
   done < /tmp/branches-to-delete.txt
   ```

3. После каждого батча — `git fetch --prune` для очистки локальных references.

4. Записать в отчёт фактический результат:
   - Сколько удалено успешно.
   - Сколько провалилось (с причиной — protected by host, permission denied, и т.д.).
   - Сколько осталось веток в `origin/` после очистки.

---

### Phase 6 — Финальный отчёт и handoff

Дополнить `docs/maintenance/stale-branches-cleanup.md` разделом `Execution result`:

```markdown
## Execution result (<YYYY-MM-DD>)

- Approved for deletion: <N>
- Successfully deleted: <N>
- Failed: <N>
  - origin/feature/x: protected by host
  - origin/feature/y: permission denied
- Branches remaining in origin: <N> (down from <M>)
- Backup file: docs/maintenance/stale-branches-deleted-<date>.txt
- Rollback command (per branch):
  git branch <name> <sha> && git push origin <name>
```

🛑 **STOP.** Финальный handoff.

---

## Self-review (перед Phase 5 и перед Phase 6)

- [ ] Отчёт в `docs/maintenance/stale-branches-cleanup.md` создан и валиден
- [ ] В списке к удалению **нет** защищённых веток
- [ ] В списке к удалению **нет** веток моложе 14 дней
- [ ] Группировка семейств выполнена, в каждой семье сохраняется самая свежая ветка
- [ ] Backup-файл со sha сохранён до начала удаления
- [ ] Апрув Владельца получен в явном виде (не «продолжай», а «approve deletion plan»)
- [ ] Никакие локальные ветки не тронуты
- [ ] Никакой `git push --force`, `git reset`, `git gc`, `git prune` не запускался
- [ ] `git fetch --prune` запускался только для синхронизации, не для удаления

---

## Что НИКОГДА не делать

```
удалять без апрува                                   — STOP
удалять защищённые ветки                             — никогда
удалять ветки моложе 14 дней                         — никогда без отдельного апрува
делать git push --force                              — никогда
делать git gc / git prune                            — отдельная задача
правит локальные ветки                               — никогда в этой задаче
правит .git/config или конфиги репозитория           — никогда
удалять теги                                         — никогда в этой задаче
расширять scope (например, заодно почистить теги)    — STOP
```

---

## Закрытие задачи

1. `docs/maintenance/stale-branches-cleanup.md` содержит план + execution result.
2. `docs/maintenance/stale-branches-deleted-<date>.txt` сохранён.
3. Self-review пройден.
4. Коммит: `chore(maintenance): cleanup stale branches (N removed)`.
5. 🛑 STOP. Финальный handoff Владельцу.

---

## Rollback всей задачи

Если что-то пошло не так и нужно восстановить **все** удалённые ветки:

```bash
while read line; do
  branch=$(echo "$line" | awk '{print $1}')
  sha=$(echo "$line" | awk '{print $2}')
  remote_name=${branch#origin/}
  git branch "$remote_name" "$sha"
  git push origin "$remote_name"
done < docs/maintenance/stale-branches-deleted-<date>.txt
```

Каждая ветка восстанавливается своим sha из backup-файла. Поэтому backup делается **до** удаления, а не после.
