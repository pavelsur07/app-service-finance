#!/bin/sh
# Печатает пути tests/Functional для шарда N из M — для параллельного прогона в CI.
#
# Зачем: functional-сьют идёт ~275 с одним процессом и делает критическим путём
# job backend-tests. GitHub даёт параллельные job'ы бесплатно, поэтому сьют
# режется на шарды, каждый со своей БД и своим раннером.
#
# Балансировка greedy по числу тестовых файлов: самая крупная единица уходит в
# самый лёгкий на тот момент шард. Это приближение — файлы не равны по времени, —
# но оно устойчиво и не требует ручной раскладки.
#
# Главное свойство: списки НЕ захардкожены. Новый каталог под tests/Functional
# попадает в раскладку автоматически, иначе его тесты молча перестали бы
# запускаться, а зелёный CI это бы не показал.
#
# Учитываются и каталоги, и тестовые файлы, лежащие прямо в tests/Functional.
#
# Использование:  bin/functional-shard.sh <индекс с 0> <всего шардов>
# Пример:         php bin/phpunit $(bin/functional-shard.sh 0 3)

set -eu

N=${1:?нужен индекс шарда, с нуля}
M=${2:?нужно общее число шардов}

cd "$(dirname "$0")/.."

if [ ! -d tests/Functional ]; then
    echo "tests/Functional не найден" >&2
    exit 1
fi

find tests/Functional -mindepth 1 -maxdepth 1 \( -type d -o -name '*Test.php' \) \
| while read -r unit; do
    if [ -d "$unit" ]; then
        weight=$(find "$unit" -name '*Test.php' | wc -l)
    else
        weight=1
    fi
    printf '%s\t%s\n' "$weight" "$unit"
done \
| sort -rn \
| awk -v n="$N" -v m="$M" '
    BEGIN { for (i = 0; i < m; i++) load[i] = 0 }
    {
        lightest = 0
        for (i = 1; i < m; i++) if (load[i] < load[lightest]) lightest = i
        load[lightest] += $1
        shard[lightest] = shard[lightest] " " $2
    }
    END {
        if (n >= m) { print "индекс шарда вне диапазона" > "/dev/stderr"; exit 1 }
        if (shard[n] == "") { print "шард " n " пуст — уменьши число шардов" > "/dev/stderr"; exit 1 }
        print substr(shard[n], 2)
    }
'
