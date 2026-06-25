# Fix Stale Branches Plan — патч плана и TSV

> Точечные исправления к артефактам `stale-branches-cleanup.md` и `stale-branches-raw.tsv`.
> Только правки этих двух файлов. Ничего больше. Никаких `git push --delete`.

---

## Входы

- `docs/maintenance/stale-branches-cleanup.md` — план (правится)
- `docs/maintenance/stale-branches-raw.tsv` — сырые данные (правится)

---

## Что НЕ делать

- Не удалять ни одной ветки. Это задача-патч, не execute.
- Не запускать `git push origin --delete`.
- Не править никакие другие файлы.
- Не менять пороги (14 / 60 / 180 дней).
- Не менять список защищённых веток.

---

## Что исправить — 4 правки

### Правка 1. Пересчитать `family` в TSV с правильной нормализацией

**Проблема:** текущая нормализация имени семейства срезала смысловые слова. Примеры:

- `claude/widgets-frontend` → семья `claude/widgets` (потеряно слово `frontend`)
- `claude/marketplace-ads-chunk-progress-repo` → отдельная семья (должна быть в `marketplace-ads-chunk-progress` вместе с `-switch` и `-tests`)
- `claude/fix-sticky-table-header-*` → семья `claude/fix-sticky-table` (потеряно `header`)

**Правило новой нормализации:**

Срезать с конца ТОЛЬКО если суффикс подходит под один из шаблонов:

1. **Mixed-case hash** длиной 5–8 (Claude Code/Codex), пример: `-XstYJ`, `-IgStl`, `-7ZDRx`, `-qoP0k`, `-c6me9`, `-258fn`, `-PROdZ`. Регекс: `-[A-Za-z0-9]{5,8}$`, **но при условии что суффикс содержит И заглавные, И строчные буквы** (чтобы не срезать слово `frontend`).
2. **Lowercase + digit hash** длиной 6–7, пример: `-q7gf9k`, `-2lptkx`, `-cj7yh0`. Регекс: `-[a-z0-9]{6,7}$`, **но при условии что суффикс содержит хотя бы одну цифру** (чтобы не срезать слово `frontend`, `backend`).
3. **Чистая цифра в конце**: `-2`, `-10`, `-11`. Регекс: `-[0-9]+$`.

Слова `-frontend`, `-backend`, `-tests`, `-repo`, `-switch`, `-header`, `-page`, `-api`, `-ui` **не срезать** — это часть имени.

**Пример работы:**

| Ветка | Старая family (неверно) | Новая family (верно) |
|---|---|---|
| `claude/widgets-frontend` | `claude/widgets` | `claude/widgets-frontend` |
| `claude/widgets-summary-api` | `claude/widgets-summary-api` | `claude/widgets-summary-api` |
| `claude/marketplace-ads-chunk-progress-repo` | `claude/marketplace-ads-chunk-progress-repo` | `claude/marketplace-ads-chunk-progress` |
| `claude/marketplace-ads-chunk-progress-switch` | `claude/marketplace-ads-chunk-progress` | `claude/marketplace-ads-chunk-progress` |
| `claude/marketplace-ads-chunk-progress-tests` | `claude/marketplace-ads-chunk-progress` | `claude/marketplace-ads-chunk-progress` |
| `claude/fix-sticky-table-header-62inZ` | `claude/fix-sticky-table` | `claude/fix-sticky-table-header` |
| `claude/openapi-typescript-setup-qoP0k` | `claude/openapi-typescript-setup` | `claude/openapi-typescript-setup` |
| `codex/align-button-block-to-the-right-q7gf9k` | `codex/align-button-block-to-the-right` | `codex/align-button-block-to-the-right` |

**Действие:** пройти по всем строкам TSV, пересчитать поле `family`, переписать TSV.

---

### Правка 2. Пересчитать `keep_candidate` и `category` после новой нормализации

После Правки 1 изменилась группировка по семьям. Для каждой семьи (>1 ветки):

- Самая свежая ветка (по `age_days`, меньше = свежее) → `keep_candidate=true`. Категория: `DELETE_STALE` или `DELETE_ANCIENT` по возрасту.
- Остальные ветки в семье → `keep_candidate=false`. Категория: `DELETE_OLD_DUP`.

Для одиночек (семья = 1 ветка):
- `keep_candidate=true`.
- Категория по возрасту: `DELETE_ANCIENT` / `DELETE_STALE` / `REVIEW` / `ACTIVE`.

**Действие:** пересчитать `keep_candidate` и `category` во всех строках TSV.

---

### Правка 3. Пересчитать summary в `stale-branches-cleanup.md` точно по TSV

После Правок 1–2 — пересчитать таблицу `## Summary` строго `awk`-ом по обновлённому TSV. Сейчас там расхождение: 158 + 21 = 179, но в детальных таблицах фактически 159 + 20 = 179. Цифры в summary должны совпадать с длиной таблиц в разделах `### 🟠 DELETE_STALE` и `### 🟡 DELETE_OLD_DUP`.

Команда для подсчёта:

```bash
awk -F'\t' 'NR>1 {print $7}' docs/maintenance/stale-branches-raw.tsv \
  | sort | uniq -c
```

Результат вставить в `## Summary`.

Также пересчитать:
- `Total non-merged branches:` = всего строк в TSV минус заголовок.
- `Branches proposed for deletion:` = `ANCIENT + STALE + OLD_DUP`.
- `Branches recommended to keep:` = `ACTIVE + REVIEW`.

---

### Правка 4. Обновить три раздела в плане после пересчёта семей

После Правки 1 изменился состав некоторых разделов. Перегенерировать:

**4.1.** `## Top branch families` — пересобрать список семей с count ≥ 2 по обновлённому TSV. Сейчас там 12 семей, после правок их состав может измениться.

**4.2.** `### 🟠 DELETE_STALE` — перегенерировать таблицу из строк TSV с `category=DELETE_STALE`. Те ветки, которые после новой нормализации стали `DELETE_OLD_DUP`, перенести в соответствующий раздел и наоборот.

**4.3.** `### 🟡 DELETE_OLD_DUP` — то же самое.

Разделы `DELETE_ANCIENT`, `ACTIVE`, `REVIEW` после правок не должны измениться (там одиночки или возраст-based) — но **проверить** на всякий случай.

---

### Правка 5. Зафиксировать решения по Open Questions

В конце файла, перед STOP-маркером, заменить раздел `## Open questions for Owner` на `## Owner decisions (locked)`:

```markdown
## Owner decisions (locked)

1. **Thresholds:** defaults (14 / 60 / 180) — locked.
2. **Extra protected branches:** none. Standard protection set is sufficient.
3. **REVIEW bucket (14–59d singles):** keep all. They will roll into STALE naturally if abandoned.
4. **DELETE_OLD_DUP policy:** keep newest in each family, delete older siblings. Confirmed.
5. **Notify authors:** no. 119 branches belong to automated agents, 82 to Owner himself.
```

Это даёт чёткое разрешение на execute без дополнительных уточнений.

---

## Self-review (перед commit)

- [ ] TSV: каждая строка имеет согласованные `family` / `category` / `keep_candidate`
- [ ] TSV: в каждой семье ровно одна ветка с `keep_candidate=true` и категорией не `DELETE_OLD_DUP`
- [ ] TSV: ни одна ветка-одиночка не помечена `DELETE_OLD_DUP`
- [ ] Summary в `.md` сходится с фактическими count в детальных таблицах
- [ ] `Total = ACTIVE + REVIEW + DELETE_ANCIENT + DELETE_STALE + DELETE_OLD_DUP`
- [ ] `Branches proposed for deletion` = `ANCIENT + STALE + OLD_DUP`
- [ ] Раздел `Owner decisions (locked)` присутствует вместо `Open questions for Owner`
- [ ] Никакие ветки физически не удалены (нет `git push --delete`)
- [ ] Изменены только 2 файла: `stale-branches-cleanup.md` и `stale-branches-raw.tsv`

---

## Закрытие

1. Коммит: `docs(maintenance): fix stale branches plan — family normalization`.
2. Финальный отчёт в одно сообщение Владельцу:
   ```
   Patched. Diff vs previous plan:
   - Reclassified into OLD_DUP after fix: <N> branches
   - Reclassified from OLD_DUP into STALE: <M> branches
   - Summary numbers now match detail tables: yes
   - Total proposed for deletion: <NEW_TOTAL> (was 201)
   ```
3. 🛑 STOP. Следующая задача — `stale-branches-cleanup.md execute`, отдельным запуском, по триггеру Владельца.
