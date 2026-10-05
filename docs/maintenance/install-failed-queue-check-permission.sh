#!/usr/bin/env bash
# Добавляет в production wrapper /usr/local/bin/codex-console разрешение ТОЛЬКО для
#   app:messenger:failed-queue-check   (read-only гейт очереди failed, аргументы запрещены)
# и больше ничего. Запускает Владелец вручную:
#   sudo ./install-failed-queue-check-permission.sh
#
# Что скрипт делает: проверяет структуру wrapper'а, делает backup, строит новую версию во
# временном файле (новый case — непосредственно перед default `*)`), проверяет `bash -n`,
# показывает diff и автоматически убеждается, что изменилось ровно добавленные строки, после чего
# атомарно заменяет файл с сохранением owner/group/mode.
#
# Чего скрипт НЕ делает: не запускает сам гейт и вообще никаких Symfony-команд, не трогает sudoers,
# Docker и git, не ставит референсную копию wrapper'а целиком (в ней есть лишний мутирующий блок
# app:marketplace:ozon-reconciliation:run, которого на проде нет).
#
# Для проверки на копии (не на проде) путь можно переопределить: CODEX_CONSOLE_PATH=/tmp/copy.sh

set -euo pipefail

TARGET="${CODEX_CONSOLE_PATH:-/usr/local/bin/codex-console}"
CASE_MARKER='app:messenger:failed-queue-check)'
DEFAULT_LINE='  *) echo "Command not allowed: $cmd" >&2; exit 2 ;;'

die() { echo "STOP: $*" >&2; exit 1; }

# Ровно тот блок, который разрешён. Heredoc с кавычками: ничего не раскрывается.
NEW_BLOCK="$(cat <<'EOF'
  app:messenger:failed-queue-check)
    # Read-only гейт очереди failed: только глубина и возраст.
    # Ничего не retry/remove/ack, внешних вызовов нет. Аргументы запрещены.
    if [ "$#" -ne 0 ]; then echo "Arguments not allowed for $cmd" >&2; exit 2; fi
    ;;
EOF
)"

# --- 0. Права ---------------------------------------------------------------------------------------
if [ -z "${CODEX_CONSOLE_PATH:-}" ] && [ "$(id -u)" -ne 0 ]; then
    die "запускать от root: sudo $0"
fi

# --- 1. Preconditions -------------------------------------------------------------------------------
[ -e "$TARGET" ] || die "файл $TARGET не существует"
[ -L "$TARGET" ] && die "$TARGET — символьная ссылка, ожидается обычный файл"
[ -f "$TARGET" ] || die "$TARGET не является обычным файлом"

# --- 2. Уже установлено -----------------------------------------------------------------------------
if grep -Fq "$CASE_MARKER" "$TARGET"; then
    echo "already installed"
    exit 0
fi

# Ожидаемая структура: ровно одна default-строка.
default_count="$(grep -Fxc "$DEFAULT_LINE" "$TARGET" || true)"
[ "$default_count" = "1" ] || die "ожидалась ровно одна строка default-case, найдено: $default_count. Структура wrapper'а неожиданная, ничего не изменено"

# --- 3. Backup (существующий не перезаписываем) -----------------------------------------------------
BACKUP="${TARGET}.bak-$(date +%Y%m%d-%H%M%S)"
[ ! -e "$BACKUP" ] || die "backup $BACKUP уже существует, повторите запуск"

# --- 4. Новая версия во временном файле в том же каталоге (атомарная замена через mv) ---------------
TMP="$(mktemp "$(dirname "$TARGET")/.codex-console.new.XXXXXX")"
DIFF_FILE=""
cleanup() { rm -f "$TMP" "$DIFF_FILE"; }
trap cleanup EXIT

line_no="$(grep -Fxn "$DEFAULT_LINE" "$TARGET" | cut -d: -f1)"
{
    head -n "$((line_no - 1))" "$TARGET"
    printf '%s\n' "$NEW_BLOCK"
    tail -n "+${line_no}" "$TARGET"
} > "$TMP"

# --- 5. Синтаксис -----------------------------------------------------------------------------------
bash -n "$TMP" || die "bash -n не прошёл для новой версии, production не менялся"

# --- 6. Diff: только добавленный блок, ничего удалённого и постороннего -----------------------------
DIFF_FILE="$(mktemp)"
diff -u "$TARGET" "$TMP" > "$DIFF_FILE" || true
echo "----- diff -u (old -> new) -----"
cat "$DIFF_FILE"
echo "--------------------------------"

removed="$(grep -c '^-[^-]' "$DIFF_FILE" || true)"
[ "$removed" = "0" ] || die "в diff есть удаляемые строки ($removed), ничего не установлено"

actual_added="$(grep '^+[^+]' "$DIFF_FILE" || true)"
expected_added="$(printf '%s\n' "$NEW_BLOCK" | sed 's/^/+/')"
[ "$actual_added" = "$expected_added" ] || die "diff содержит что-то кроме нового блока $CASE_MARKER, ничего не установлено"

[ "$(grep -Fc "$CASE_MARKER" "$TMP")" = "1" ] || die "новый case должен встречаться ровно один раз"

# --- 7. Установка с сохранением owner/group/mode ----------------------------------------------------
cp -p "$TARGET" "$BACKUP"
chown --reference="$TARGET" "$TMP"
chmod --reference="$TARGET" "$TMP"
mv -f "$TMP" "$TARGET"
trap - EXIT
rm -f "$DIFF_FILE"

# --- 8. Контроль результата (без запуска команд wrapper'а) ------------------------------------------
bash -n "$TARGET" || die "после установки bash -n не прошёл; восстановите из $BACKUP"
grep -Fq "$CASE_MARKER" "$TARGET" || die "новый case не найден после установки; восстановите из $BACKUP"

echo "installed"
echo "backup: $BACKUP"
echo "owner/group/mode: $(stat -c '%U:%G %a' "$TARGET")"
echo "Проверка вручную (скрипт её не запускает):"
echo "  sudo $TARGET app:messenger:failed-queue-check --anything   # ожидается: Arguments not allowed ..., exit 2"
echo "Откат: sudo cp -p $BACKUP $TARGET"
