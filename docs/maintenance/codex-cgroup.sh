#!/usr/bin/env bash
set -euo pipefail

# codex-cgroup — read-only отчёт о памяти cgroup контейнеров.
#
# Зачем: без cgroup-лимита у контейнера нет ни потолка, ни счётчиков, и подобрать
# лимит можно только гаданием. `memory.peak` даёт high-water mark с момента старта
# контейнера одним чтением, без сэмплирования `docker stats`, а `memory.events`
# показывает, упирался ли контейнер в потолок и убивал ли ядро процессы внутри него.
# См. docs/tasks/scheduler-cgroup-limit/task.md.
#
# Почему это безопасно отдавать агенту:
#   * НЕ использует `docker exec` — внутри контейнеров не запускается ни один процесс;
#     всё читается с хоста через cgroupfs;
#   * пути к файлам заданы в этом скрипте константами, из аргумента берётся только
#     имя контейнера, и оно сверяется со списком реально работающих, а не
#     подставляется в путь;
#   * файлы cgroup содержат счётчики памяти и не содержат секретов, переменных
#     окружения, аргументов команд и пользовательских данных;
#   * ничего не пишет и не меняет.
#
# Путь до cgroup выводится из /proc/<pid>/cgroup, а не угадывается по драйверу,
# поэтому работает и на systemd, и на cgroupfs.
#
# Использование:
#   codex-cgroup              — таблица по всем работающим контейнерам
#   codex-cgroup <container>  — подробности по одному

CGROUP_ROOT="/sys/fs/cgroup"

if [ "$#" -gt 1 ]; then
    echo "usage: codex-cgroup [<container>]" >&2
    exit 2
fi

running_names() { docker ps --format '{{.Names}}' | sort; }

cgroup_dir() {   # cgroup_dir <container> -> путь или пусто
    local pid rel
    pid=$(docker inspect "$1" --format '{{.State.Pid}}' 2>/dev/null) || return 1
    [ -n "$pid" ] && [ "$pid" != "0" ] || return 1
    rel=$(sed -n 's/^0:://p' "/proc/$pid/cgroup" 2>/dev/null | head -1)
    [ -n "$rel" ] || return 1
    [ -d "$CGROUP_ROOT$rel" ] || return 1
    printf '%s%s' "$CGROUP_ROOT" "$rel"
}

val() {          # val <dir> <file> -> содержимое или "-"
    local f="$1/$2"
    [ -r "$f" ] && head -1 "$f" 2>/dev/null || echo "-"
}

mib() {          # mib <байты|max|-> -> человекочитаемое
    case "$1" in
        max) echo "—" ;;
        -|'') echo "-" ;;
        *[!0-9]*) echo "$1" ;;
        *) echo "$(( $1 / 1048576 ))M" ;;
    esac
}

pct() {          # pct <peak> <limit>
    case "$2" in
        max|-|'') echo "—" ; return ;;
    esac
    case "$1" in
        *[!0-9]*|'') echo "—" ; return ;;
    esac
    [ "$2" -gt 0 ] 2>/dev/null || { echo "—"; return; }
    echo "$(( $1 * 100 / $2 ))%"
}

oom_kill_of() {  # oom_kill_of <dir>
    local f="$1/memory.events"
    [ -r "$f" ] || { echo "-"; return; }
    awk '$1=="oom_kill"{print $2; found=1} END{if(!found) print "-"}' "$f"
}

report_one() {
    local name="$1" dir
    if ! dir=$(cgroup_dir "$name"); then
        echo "codex-cgroup: cgroup контейнера $name не найден (не запущен?)" >&2
        exit 3
    fi
    echo "container : $name"
    echo "cgroup    : $dir"
    echo
    local limit current peak
    limit=$(val "$dir" memory.max)
    current=$(val "$dir" memory.current)
    peak=$(val "$dir" memory.peak)
    printf 'memory.max      %-14s %s\n' "$limit"   "$(mib "$limit")"
    printf 'memory.current  %-14s %s\n' "$current" "$(mib "$current")"
    printf 'memory.peak     %-14s %s   (%s от лимита)\n' "$peak" "$(mib "$peak")" "$(pct "$peak" "$limit")"
    echo
    echo "memory.events:"
    if [ -r "$dir/memory.events" ]; then
        sed 's/^/  /' "$dir/memory.events"
    else
        echo "  (недоступен)"
    fi
    echo
    echo "Трактовка: max=0 и oom_kill=0 — потолок не задет; max>0 — упирался, но выжил"
    echo "на reclaim; oom_kill>0 — процесс убит внутри контейнера, хост при этом цел."
    echo "memory.peak обнуляется при пересоздании контейнера, то есть при каждом деплое."
}

report_all() {
    # Ширина колонок считается по данным (column -t), иначе длинное имя
    # контейнера съезжает и таблица становится нечитаемой.
    {
        printf 'CONTAINER\tLIMIT\tCURRENT\tPEAK\tPEAK%%\tOOM_KILL\n'
        local name dir limit current peak
        while IFS= read -r name; do
            if ! dir=$(cgroup_dir "$name"); then
                printf '%s\t-\t-\t-\t-\t-\n' "$name"
                continue
            fi
            limit=$(val "$dir" memory.max)
            current=$(val "$dir" memory.current)
            peak=$(val "$dir" memory.peak)
            printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
                "$name" "$(mib "$limit")" "$(mib "$current")" "$(mib "$peak")" \
                "$(pct "$peak" "$limit")" "$(oom_kill_of "$dir")"
        done < <(running_names)
    } | column -t -s "$(printf '\t')"
    echo
    echo "«—» в LIMIT означает отсутствие cgroup-лимита: OOM такого контейнера"
    echo "ограничен только памятью хоста и может задеть посторонние сервисы."
}

if [ "$#" -eq 0 ]; then
    report_all
    exit 0
fi

target="$1"
if ! running_names | grep -qxF "$target"; then
    echo "codex-cgroup: контейнер \"$target\" не найден среди работающих" >&2
    echo "доступны:" >&2
    running_names | sed 's/^/  /' >&2
    exit 2
fi
report_one "$target"
