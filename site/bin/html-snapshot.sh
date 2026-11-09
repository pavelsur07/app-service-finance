#!/usr/bin/env bash
# Снимок сырого HTML страниц стенда для сверки «до/после» рефакторинга шаблонов
# (docs/tasks/twig-ui-systematization/stages/stage-5.md, §4.1).
#
#   site/bin/html-snapshot.sh <urls.txt> <out-dir>
#   diff -ru <out-before> <out-after>        # пустой вывод = HTML идентичен
#
# urls.txt — по пути на строку (/report/cashflow?grouping=week), # — комментарий.
# Логин в основной (/login) и админский (/admin/login) firewall одной сессией.
# Окружение: SNAPSHOT_BASE (по умолчанию — IP nginx стенда app-service-finance),
# SNAPSHOT_HOST (localhost), SNAPSHOT_USER (owner@app.ru), SNAPSHOT_PASS (password).
#
# Нормализация: значения CSRF-токенов и nonce, отладочная панель Symfony вырезается,
# пробелы между тегами и серии пробелов — вне <pre>/<textarea>.
set -euo pipefail

[[ $# -eq 2 ]] || { echo "usage: $0 <urls.txt> <out-dir>" >&2; exit 2; }
urls=$1
out=$2

if [[ -z ${SNAPSHOT_BASE:-} ]]; then
  ip=$(docker inspect -f '{{(index .NetworkSettings.Networks "app-service-finance_default").IPAddress}}' app-service-finance-site-1)
  SNAPSHOT_BASE="http://$ip"
fi
host=${SNAPSHOT_HOST:-localhost}
user=${SNAPSHOT_USER:-owner@app.ru}
pass=${SNAPSHOT_PASS:-password}

mkdir -p "$out"
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT
req() { curl -sS -b "$jar" -c "$jar" -H "Host: $host" "$@"; }

login() {
  local path=$1 token
  token=$(req "$SNAPSHOT_BASE$path" | perl -ne 'print $1 if /name="_csrf_token" value="([^"]*)"/')
  [[ -n $token ]] || { echo "нет _csrf_token на $path" >&2; exit 1; }
  req -o /dev/null --data-urlencode "_username=$user" --data-urlencode "_password=$pass" \
    --data-urlencode "_csrf_token=$token" "$SNAPSHOT_BASE$path"
}
login /login
login /admin/login

normalize() {
  perl -0777 -pe '
    s{(<(?:input|meta)\b[^>]*(?:token|csrf)[^>]*>)}{ my $t = $1; $t =~ s/\b(value|content)="[^"]*"/$1="TOKEN"/g; $t }gie;
    s{(\bdata-[\w-]*(?:token|csrf)[\w-]*)="[^"]*"}{$1="TOKEN"}gi;
    s{<!-- START of Symfony Web Debug Toolbar -->.*?<!-- END of Symfony Web Debug Toolbar -->}{}gs;
    s{<div id="sfwdt[0-9a-f]+".*?</script>}{}gs;
    s{\bnonce="[^"]*"}{nonce="X"}g;
    my @parts = split m{(<(pre|textarea)\b.*?</\2>)}si;
    my $res = "";
    for (my $i = 0; $i < @parts; $i++) {
      my $p = $parts[$i];
      if ($p =~ m{^<(pre|textarea)\b}i) { $res .= $p; $i++; next; }
      $p =~ s/>\s+</></g;
      $p =~ s/\s+/ /g;
      $res .= $p;
    }
    $res =~ s/></>\n</g;
    $_ = $res;
  '
}

fail=0
while IFS= read -r url || [[ -n $url ]]; do
  [[ -z $url || $url == \#* ]] && continue
  name=$(printf '%s' "$url" | sed 's#^/##; s#[^A-Za-z0-9._-]#_#g')
  code=$(req -o "$out/$name.raw" -w '%{http_code}' "$SNAPSHOT_BASE$url")
  { echo "HTTP $code $url"; normalize < "$out/$name.raw"; } > "$out/$name.html"
  rm -f "$out/$name.raw"
  [[ $code == 200 ]] || { echo "HTTP $code $url" >&2; fail=1; }
done < "$urls"
exit $fail
