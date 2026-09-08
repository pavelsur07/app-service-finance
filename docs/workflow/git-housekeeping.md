# Git housekeeping: база PR и удаление веток

Основные git-правила — `AGENTS.md` §8. Здесь две процедуры с ловушками.

## База PR — всегда `master`

```bash
gh pr create --base master --draft ...
gh pr view <n> --json baseRefName
```

База не `master` — дефект, а не вариант: PR вливается в feature-ветку, GitHub
честно пишет `MERGED`, а в `master` ничего не попадает. Список веток и статус PR
это не различают, поэтому `baseRefName` проверяется явно. Исправление:
`gh pr edit <n> --base master`, затем заново смотреть полный дифф и CI — смена
базы меняет содержимое PR.

## Удаление локальной ветки

Удалять только когда merge, записанный GitHub, есть в истории текущего `master`.
Факты берутся из PR:

```bash
git fetch origin master
gh pr view <n> --json state,baseRefName,headRefName,mergeCommit,headRefOid \
  -q '[.state, .baseRefName, .headRefName, .mergeCommit.oid, .headRefOid] | @tsv'
git merge-base --is-ancestor <mergeCommit> refs/remotes/origin/master && echo ancestor
git rev-parse <branch>
```

Удаление разрешено, когда все пять условий выполнены:

1. `state` = `MERGED`;
2. `baseRefName` = `master`;
3. `headRefName` = удаляемая ветка;
4. `mergeCommit` — предок свежего `origin/master`;
5. `headRefOid` равен текущему tip ветки. Это ловушка: ветка, в которую
   писали после merge, держит PR в `MERGED`, а новые коммиты в `master` не
   попали.

Если head PR живёт в другом репозитории, `origin` этой ветки не содержит —
на основании такого PR ничего не удалять.

Удалять только `git branch`, никогда `git update-ref -d`: plumbing сносит
ветку, выписанную в worktree, и оставляет его `HEAD` на несуществующем ref.
Ни успех, ни отказ `git branch -d` доказательством не являются: `-d` сверяет
tip с upstream или `HEAD`, а не с `master`. Когда пять фактов выше сошлись, а
`-d` отказывает (squash-merge), правильно `git branch -D`. `-D` без этих
фактов — запрещено.

## Удаление remote-ветки

Никогда не часть автономной уборки. Только по явной инструкции Владельца с
именем ветки. Непосредственно перед удалением перечитать живой tip и требовать
равенства с `headRefOid`:

```bash
git ls-remote --heads origin refs/heads/<branch>
git push origin --delete <branch>
```

Не `origin/<branch>` из локального кэша — он может быть устаревшим. Любое
расхождение отменяет удаление. Не скриптовать и не удалять списком: окно между
проверкой и удалением принято осознанно, потому что в репозиторий пишет один
человек, и он же даёт инструкцию; пакетное удаление превращает это окно в
реальное.
