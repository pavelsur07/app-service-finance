#!/usr/bin/env bash
#
# Запрет роста baseline PHPStan.
#
# Baseline — это зафиксированный долг, а не свалка. Без этой проверки правило
# «только сокращать» держится на внимании ревьюера: достаточно один раз
# выполнить `make site-stan-baseline` вместо починки, и новая ошибка тихо
# станет частью «нормы», неотличимой от унаследованной.
#
# Сравниваются не агрегаты, а мультимножество записей с ключом
# (path, identifier, message). Первая редакция сравнивала сумму count и число
# записей — и пропускала ровно тот случай, ради которого проверка нужна:
# PR удаляет одну старую запись и добавляет одну новую, обе метрики совпадают,
# регресс проходит молча. Проверяется два условия: не появилось нового ключа
# и ни у одного существующего не вырос count.
#
# Использование:
#   phpstan-baseline-guard.sh <baseline базы> <baseline текущий>

set -euo pipefail

base_file=${1:?первым аргументом — baseline базы сравнения}
head_file=${2:?вторым аргументом — текущий baseline}

if [ ! -e "$base_file" ]; then
    echo "Файла базы сравнения нет ($base_file) — в базе baseline ещё не существует, проверка роста неприменима."
    exit 0
fi

if [ ! -s "$base_file" ]; then
    echo "База сравнения пуста ($base_file) — проверка роста неприменима."
    exit 0
fi

if [ ! -s "$head_file" ]; then
    echo "Текущего baseline нет или он пуст ($head_file) — рост невозможен." >&2
    exit 0
fi

# Парсер ниже понимает канонический формат `--generate-baseline`: четыре поля,
# по одному на строке. NEON допускает и flow-форму (`[{ message: ..., path: ... }]`),
# которую PHPStan прочитает, а этот парсер — нет. Молча пропустить такой файл
# нельзя: проверка сделала бы вид, что роста нет. Поэтому ниже стоит контроль
# на нулевое число разобранных записей в непустом файле.

report=$(
    awk '
        function record(store,   key) {
            if (path == "") { return }
            key = path "|" ident "|" msg
            if (cnt == "BAD") { badcount++; cnt = 0 }
            if (store == "base") { base[key] += cnt; nbase++ } else { head[key] += cnt; nhead++ }
            msg = ""; ident = ""; cnt = 0; path = ""
        }
        FNR == 1 { record(side); side = (side == "") ? "base" : "head" }
        # Любая непустая строка внутри ignoreErrors обязана быть канонической.
        # Иначе baseline может нести запись во flow-форме — в том числе с
        # закавыченными ключами (`{ "message": ... }`), которую PHPStan
        # применит, а этот парсер не увидит.
        /^[ \t]*$/                     { next }
        /^parameters:[ \t]*$/          { next }
        /^[ \t]+ignoreErrors:[ \t]*$/ { next }
        /^[ \t]+-[ \t]*$/             { next }
        $1 == "message:"    { sub(/^[ \t]*message:[ \t]*/, "", $0); msg = $0; next }
        $1 == "identifier:" { ident = $2; next }
        $1 == "count:"      { cnt = ($2 ~ /^[1-9][0-9]*$/) ? $2 : "BAD"; next }
        $1 == "path:"       { path = $2; record(side); next }
        { noncanonical++ }
        END {
            record(side)
            for (k in head) {
                if (!(k in base))          { print "NEW\t" head[k] "\t" k }
                else if (head[k] > base[k]) { print "GREW\t" base[k] "->" head[k] "\t" k }
                else if (head[k] < base[k]) { shrunk++ }
                delete base[k]
            }
            for (k in base) { removed++ }
            print "SUMMARY\t" (shrunk + 0) "\t" (removed + 0) "\t" (nbase + 0) "\t" (nhead + 0) "\t" (noncanonical + 0) "\t" (badcount + 0)
        }
    ' "$base_file" "$head_file"
)

summary=$(printf '%s\n' "$report" | grep '^SUMMARY' || true)
problems=$(printf '%s\n' "$report" | grep -E '^(NEW|GREW)' || true)
shrunk=$(printf '%s' "$summary" | cut -f2)
removed=$(printf '%s' "$summary" | cut -f3)
parsed_base=$(printf '%s' "$summary" | cut -f4)
parsed_head=$(printf '%s' "$summary" | cut -f5)
noncanonical=$(printf '%s' "$summary" | cut -f6)
badcount=$(printf '%s' "$summary" | cut -f7)

if [ "${noncanonical:-0}" -ne 0 ] || [ "${badcount:-0}" -ne 0 ]; then
    {
        echo
        echo "Baseline не канонический: неразобранных строк ${noncanonical:-0}, некорректных count ${badcount:-0}."
        echo
        echo "Парсер принимает только формат \`phpstan --generate-baseline\`: четыре поля"
        echo "message/identifier/count/path, по одному на строке, count — положительное целое."
        echo "NEON допускает и другие формы (flow, закавыченные ключи) — PHPStan их прочитает,"
        echo "а проверка роста нет. Пропустить такой файл значило бы соврать, что роста нет."
        echo
        echo "Что делать: перегенерировать baseline через 'make site-stan-baseline'."
    } >&2
    exit 1
fi

# Строгая валидация формата. Разобранных записей должно быть ровно столько,
# сколько в файле вхождений `message:`. Проверки «> 0» недостаточно: файл,
# в котором одна запись каноническая, а вторая записана во flow-форме
# (`ignoreErrors: [{ message: ..., path: ... }]`), дал бы parsed > 0, и новая
# ошибка прошла бы молча — тот же fail-open, только с одной записью прикрытия.
count_field() {
    grep -o "$2:" "$1" | wc -l | tr -d ' '
}

# Проверяются все четыре поля, а не только message. Запись без `count:` —
# отдельная ловушка: для PHPStan отсутствие count снимает ограничение на число
# совпадений, а парсер оставил бы cnt нулевым, и рост выглядел бы сокращением.
malformed=''
for field in message identifier count path; do
    field_base=$(count_field "$base_file" "$field")
    field_head=$(count_field "$head_file" "$field")
    if [ "${parsed_base:-0}" -ne "$field_base" ] || [ "${parsed_head:-0}" -ne "$field_head" ]; then
        malformed="${malformed}  ${field}: в базе ${field_base} при ${parsed_base:-0} записях, в текущем ${field_head} при ${parsed_head:-0}
"
    fi
done

if [ -n "$malformed" ]; then
    {
        echo
        echo "Baseline не удалось разобрать целиком — число полей не сходится с числом записей:"
        printf '%s' "$malformed"
        echo
        echo "Этот парсер понимает только канонический формат \`phpstan --generate-baseline\`:"
        echo "четыре поля message/identifier/count/path, по одному на строке. NEON допускает"
        echo "и flow-форму — PHPStan её прочитает, а проверка роста нет. Молча пропустить"
        echo "такой файл нельзя: это выглядело бы как отсутствие роста."
        echo
        echo "Что делать: перегенерировать baseline через 'make site-stan-baseline'."
    } >&2
    exit 1
fi

if [ -z "$problems" ]; then
    echo "Baseline не вырос: новых записей нет, ни один count не увеличился."
    echo "Записей удалено: ${removed:-0}, сокращено по count: ${shrunk:-0}."
    exit 0
fi

new_count=$(printf '%s\n' "$problems" | grep -c '^NEW' || true)
grew_count=$(printf '%s\n' "$problems" | grep -c '^GREW' || true)

{
    echo
    echo "Baseline PHPStan вырос: новых записей ${new_count}, выросших по count ${grew_count}."
    echo
    printf '%s\n' "$problems" | head -20 | while IFS=$'\t' read -r kind delta key; do
        printf '  %-5s %-14s %s\n' "$kind" "$delta" "${key%%|*}"
    done
    if [ "$(printf '%s\n' "$problems" | wc -l)" -gt 20 ]; then
        echo "  … и ещё $(( $(printf '%s\n' "$problems" | wc -l) - 20 ))"
    fi
    cat <<'MSG'

Новая ошибка должна быть исправлена, а не подавлена. Перегенерация baseline
вместо починки стирает ratchet: регресс перестаёт отличаться от унаследованного
долга, и гейт теряет смысл.

Что делать:
  1. Посмотреть, что добавилось: git diff -- site/phpstan-baseline.neon
  2. Исправить ошибку в коде и убрать её из baseline.
  3. Если рост осознанный (обновление PHPStan, новое правило, новое расширение),
     добавить в заголовок PR метку [baseline-grow] и объяснить рост в описании.
     Проверка пропускается только по этой метке — молча вырасти baseline не может.
MSG
} >&2
exit 1
