#!/usr/bin/env bash
set -euo pipefail

# codex-console — запуск разрешённых Symfony-команд на проде.
#
# Команда выполняется в ОДНОРАЗОВОМ контейнере site-php-cli
# (`docker compose run --rm`), а не через `docker exec` в живой воркер:
#
#   * у site-messenger-worker-sync cgroup 256M, поэтому тяжёлая ad-hoc команда
#     получала SIGKILL от kernel OOM-killer (GlitchTip issue 262) вместо
#     понятного PHP-фатала; у site-php-cli лимит 1536M;
#   * ad-hoc команда больше не делит память с живым консьюмером async_sync
#     и не может его уронить;
#   * `run` соблюдает deploy.resources.limits из compose и игнорирует
#     container_name, поэтому одновременные вызовы не конфликтуют именами.
#
# Секреты на диск не пишутся. Переменные подстановки для compose
# восстанавливаются в момент вызова из окружения уже работающих контейнеров
# и живут только в памяти этого процесса. IMAGE_TAG compose берёт из .env сам.

ENV_SOURCE_PHP="site-php-fpm"
ENV_SOURCE_PG="symfony-postgres"
RUN_SERVICE="site-php-cli"

# Переменные, чьё имя в compose совпадает с именем env внутри php-контейнера.
# Необязательные могут отсутствовать — в compose у них заданы дефолты.
PHP_ENV_VARS=(
    APP_SECRET
    SENTRY_DSN
    SENTRY_RELEASE
    HEALTH_CHECK_TOKEN
    APP_ENCRYPTION_FALLBACK_KEY
    APP_ENCRYPTION_KEYS_JSON
    APP_ENCRYPTION_CURRENT_KEY_VERSION
    APP_OBJECT_STORAGE_S3_ACCESS_KEY
    APP_OBJECT_STORAGE_S3_SECRET_KEY
    FEATURE_FUNDS_AND_WIDGET
    WB_LEGACY_RECONCILE_ENABLED
    TELEGRAM_WEBHOOK_URL
    TELEGRAM_WEBHOOK_SECRET
    TELEGRAM_API_BASE_URL
)

cmd="${1:-}"
shift || true
case "$cmd" in
  messenger:stats) ;;
  messenger:consume) ;;
  app:daily-balance:recalc) ;;
  app:ingestion:marketplace-categories:status) ;;
  app:ingestion:ozon-accrual:daily-maintenance) ;;
  app:ingestion:ozon-accrual:verify-rolling-refresh) ;;
  app:ingestion:ozon-accrual:preview-normalization) ;;
  app:ingestion:ozon-accrual:prune-stale-projection) ;;
  app:ingestion:ozon-accrual:rolling-refresh) ;;
  app:cash-auto-rules:assign-general-cfo) ;;
  app:mailer:healthcheck) ;;
  app:inventory:stock-freshness-check) ;;
  app:cash:verify-transaction-splits)
    if [ "$#" -ne 0 ]; then echo "Arguments not allowed for $cmd" >&2; exit 2; fi
    ;;
  app:cash:backfill-transaction-splits)
    case "${1:-}" in
      "") ;;
      --execute)
        if [ "$#" -ne 1 ]; then echo "Only --execute is allowed for $cmd" >&2; exit 2; fi
        ;;
      *) echo "Argument not allowed for $cmd: ${1}" >&2; exit 2 ;;
    esac
    ;;
  app:marketplace:ozon-financial-reports:sync)
    # Мутирующая: диспатчит SyncOzonAccrualByDayMessage за окно дней для всех
    # активных Ozon seller-подключений. Ходит во внешний API и пишет
    # marketplace_raw_documents, поэтому запуск — отдельное одобрение Владельца
    # по AGENTS.md §3.3.
    #
    # Имя парное к wb-financial-reports:sync — та же работа для другого
    # маркетплейса. Сегмент ozon-accrual: не используется: под ним пятнадцать
    # команд Ingestion, и одноимённый сегмент под другим префиксом читался бы
    # здесь как та же семья.
    #
    # Замена app:marketplace:ozon-daily-sync: его источник
    # /v3/finance/transaction/list Ozon снял 09.09.2026. Обе команды в allowlist
    # намеренно — легаси нужен, пока не восстановлена история, и его 967
    # документов остаются переобрабатываемыми.
    #
    # Форма аргументов проверяется, а не только имя: --days-back задаёт объём
    # работы и обращений к API, поэтому допускается лишь строгий числовой вид.
    # Диапазон значения проверяет сама команда (1..365).
    for arg in "$@"; do
      case "$arg" in
        --no-interaction|-n|--quiet|-q) ;;
        --days-back=[1-9]|--days-back=[1-9][0-9]|--days-back=[1-9][0-9][0-9]) ;;
        --company-id=????????-????-????-????-????????????) ;;
        *) echo "Argument not allowed for $cmd: $arg" >&2; exit 2 ;;
      esac
    done
    ;;
  app:marketplace:ozon-financial-reports:freshness-check)
    # Read-only гейт: проверяет, что у каждого активного seller-подключения есть
    # документ начислений за вчера. Ничего не пишет и во внешний API не ходит,
    # поэтому запускается как рутинная проверка, без отдельного одобрения.
    #
    # Ненулевой exit code — рабочий сигнал этой команды, а не сбой wrapper'а.
    for arg in "$@"; do
      case "$arg" in
        --no-interaction|-n|--quiet|-q) ;;
        *) echo "Argument not allowed for $cmd: $arg" >&2; exit 2 ;;
      esac
    done
    ;;
  app:marketplace:ozon-daily-sync)
    # Мутирующая: диспатчит SyncOzonReportMessage за последние 14 дней для всех
    # активных Ozon seller-подключений. Ходит во внешний API и переписывает
    # marketplace_raw_documents, поэтому запуск — отдельное одобрение Владельца
    # по AGENTS.md §3.3, а не рутинная проверка.
    #
    # Нужна для восстановления пропущенного дня: ночной прогон 09.09.2026 упал
    # с HTTP 400 по всем четырём кабинетам, и перезалить его было нечем — путь
    # существует только в кроне. Команда идемпотентна: существующий документ
    # дня обновляется, дубль не создаётся.
    #
    # Собственных опций у команды нет, поэтому пропускаем только служебные
    # флаги Symfony. Любой другой аргумент означает, что команда изменилась и
    # allowlist пора пересматривать, а не что его надо обойти.
    for arg in "$@"; do
      case "$arg" in
        --no-interaction|-n|--quiet|-q) ;;
        *) echo "Argument not allowed for $cmd: $arg" >&2; exit 2 ;;
      esac
    done
    ;;
  messenger:failed:remove)
    # Мутирующая команда: удаляет сообщение из failed-очереди безвозвратно.
    # Поэтому первым аргументом допускается только числовой id. Это заодно
    # отсекает --all, который вынес бы всю очередь целиком.
    case "${1:-}" in
      ''|*[!0-9]*) echo "First argument must be a numeric message id for $cmd" >&2; exit 2 ;;
    esac
    case "$#" in
      1) ;;
      2) if [ "${2}" != "--force" ]; then echo "Only --force is allowed for $cmd: ${2}" >&2; exit 2; fi ;;
      *) echo "Too many arguments for $cmd" >&2; exit 2 ;;
    esac
    ;;
  app:inventory:renormalize-snapshots)
    # Мутирующая команда: пере-нормализует исторические снапшоты остатков.
    # Разрешены только перечисленные флаги строгой формы; --execute допускается
    # лишь вместе с обязательным диапазоном дат, чтобы случайный запуск не мог
    # затронуть неопределённый объём данных. Семантику диапазона (конец строго
    # раньше сегодняшнего дня) проверяет сама команда — wrapper держит форму.
    seen_from=0; seen_to=0
    for arg in "$@"; do
      if   [[ "$arg" =~ ^--from=[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then seen_from=1
      elif [[ "$arg" =~ ^--to=[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then seen_to=1
      elif [[ "$arg" =~ ^--source=(ozon|wildberries)$ ]]; then :
      elif [[ "$arg" =~ ^--company=[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then :
      elif [ "$arg" = "--execute" ]; then :
      else echo "Argument not allowed for $cmd: $arg" >&2; exit 2
      fi
    done
    if [ "$seen_from" -ne 1 ] || [ "$seen_to" -ne 1 ]; then
      echo "Both --from=YYYY-MM-DD and --to=YYYY-MM-DD are required for $cmd" >&2; exit 2
    fi
    ;;
  app:inventory:unmapped-stock-check)
    # Read-only гейт расхождений «остаток есть, карточки в каталоге нет».
    # Аргументы запрещены: --window-days нужен только для ручного разбора, а для
    # регулярного прогона строгий запрет проще и надёжнее проверки формы.
    if [ "$#" -ne 0 ]; then echo "Arguments not allowed for $cmd" >&2; exit 2; fi
    ;;
  *) echo "Command not allowed: $cmd" >&2; exit 2 ;;
esac

# ─── восстановление окружения из работающих контейнеров ────────────────────

declare -A SRC

load_env() {
    local container="$1" k v
    if [ "$(docker inspect "$container" --format '{{.State.Running}}' 2>/dev/null)" != "true" ]; then
        echo "codex-console: контейнер $container не запущен" >&2
        exit 3
    fi
    unset SRC; declare -gA SRC
    # Ключи фильтруются по форме имени: строка-продолжение многострочного
    # значения под неё не подходит и отдельной переменной не становится.
    while IFS='=' read -r k v; do
        if [[ "$k" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
            SRC["$k"]="$v"
        fi
    done < <(docker inspect "$container" --format '{{range .Config.Env}}{{println .}}{{end}}')
}

require() {   # require <имя-в-compose> <имя-в-контейнере> <контейнер>
    local dst="$1" src="$2" from="$3"
    if [ -z "${SRC[$src]+x}" ]; then
        echo "codex-console: переменная $src не найдена в $from" >&2
        exit 3
    fi
    export "$dst=${SRC[$src]}"
}

load_env "$ENV_SOURCE_PHP"
for v in "${PHP_ENV_VARS[@]}"; do
    if [ -n "${SRC[$v]+x}" ]; then export "$v=${SRC[$v]}"; fi
done
# В compose переменная зовётся SITE_MAILER_DSN, внутри контейнера — MAILER_DSN.
require SITE_MAILER_DSN MAILER_DSN "$ENV_SOURCE_PHP"

# POSTGRES_PASSWORD в php-контейнеры не пробрасывается: он подставляется внутрь
# DATABASE_URL ещё при разборе compose. Единственный контейнер, несущий его
# отдельной переменной, — сам postgres.
load_env "$ENV_SOURCE_PG"
require POSTGRES_PASSWORD POSTGRES_PASSWORD "$ENV_SOURCE_PG"
if [ -z "$POSTGRES_PASSWORD" ]; then
    echo "codex-console: POSTGRES_PASSWORD пуст — окружение прочитано неверно" >&2
    exit 3
fi

# Каталог проекта и файл compose берём из меток самого контейнера, чтобы путь
# деплоя не дублировался здесь константой и не разошёлся с ним молча.
project_dir="$(docker inspect "$ENV_SOURCE_PHP" \
    --format '{{index .Config.Labels "com.docker.compose.project.working_dir"}}')"
config_file="$(docker inspect "$ENV_SOURCE_PHP" \
    --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}')"
if [ -z "$project_dir" ] || [ -z "$config_file" ]; then
    echo "codex-console: не удалось определить каталог compose-проекта" >&2
    exit 3
fi
cd "$project_dir"

# memory_limit не переопределяем. Тяжёлую работу делают подпроцессы, которые
# стартуют как новый php и читают php.ini (1024M) — родительский `php -d` на них
# не распространяется, поэтому прежний `-d memory_limit=512M` ограничивал только
# оркестратор. Потолок задаёт cgroup контейнера: 1536M.
exec docker compose -f "$config_file" run --rm --no-deps -T "$RUN_SERVICE" \
    bin/console "$cmd" "$@"
