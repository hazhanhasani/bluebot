#!/bin/bash
set -u

BOT_DIR="/var/www/html/mirzaprobotconfig"
STATE_DIR="/var/lib/bluebot"
QUEUE_FILE="$STATE_DIR/update-request.json"
RUNNING_FILE="$STATE_DIR/update-running.json"
STATUS_FILE="$STATE_DIR/update-status.json"
LOCK_FILE="/var/lock/bluebot-update.lock"
LOG_FILE="/var/log/bluebot-update.log"

mkdir -p "$STATE_DIR"
touch "$LOCK_FILE"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

[ -s "$QUEUE_FILE" ] || exit 0

read_json() {
    php -r '$j=json_decode((string)@file_get_contents($argv[1]),true); $v=is_array($j)?($j[$argv[2]]??""):""; if (is_scalar($v)) echo $v;' "$QUEUE_FILE" "$1" 2>/dev/null
}

CHANNEL="$(read_json channel)"
INSTALLED_CHANNEL="$(read_json installed_channel)"
CHAT_ID="$(read_json chat_id)"
REF="$(read_json ref)"
LABEL="$(read_json label)"

case "$INSTALLED_CHANNEL" in
    release|beta) ;;
    *) INSTALLED_CHANNEL="$CHANNEL" ;;
esac

case "$CHANNEL" in
    release|beta|auto) ;;
    *)
        rm -f "$QUEUE_FILE"
        exit 2
        ;;
esac

if ! [[ "$CHAT_ID" =~ ^-?[0-9]+$ ]]; then
    rm -f "$QUEUE_FILE"
    exit 2
fi

case "$INSTALLED_CHANNEL" in
    release)
        if [ -z "$REF" ] || [[ "$REF" == *[[:space:]]* ]] || [ "${#REF}" -gt 128 ]; then
            rm -f "$QUEUE_FILE"
            exit 2
        fi
        ;;
    beta)
        if ! [[ "$REF" =~ ^[0-9a-fA-F]{40}$ ]]; then
            rm -f "$QUEUE_FILE"
            exit 2
        fi
        ;;
    *)
        rm -f "$QUEUE_FILE"
        exit 2
        ;;
esac

mv -f "$QUEUE_FILE" "$RUNNING_FILE"

write_status() {
    local state="$1" message="$2"
    php -r '
        $path=$argv[1];
        $payload=[
            "state"=>$argv[2],
            "message"=>$argv[3],
            "time"=>gmdate(DATE_ATOM),
            "channel"=>$argv[4],
            "ref"=>$argv[5],
        ];
        file_put_contents($path,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX);
    ' "$STATUS_FILE" "$state" "$message" "$CHANNEL" "$REF" 2>/dev/null || true
    chmod 664 "$STATUS_FILE" 2>/dev/null || true
    chown www-data:www-data "$STATUS_FILE" 2>/dev/null || true
}

bot_token() {
    [ -f "$BOT_DIR/config.php" ] || return 1
    sed -n "s/^\$APIKEY = '\([^']*\)';/\1/p" "$BOT_DIR/config.php" | head -1
}

notify() {
    local text="$1" retry="${2:-0}" token markup=""
    token="$(bot_token)"
    [ -n "$token" ] || return 0

    if [ "$retry" = "1" ]; then
        markup='{"inline_keyboard":[[{"text":"🔁 تلاش مجدد","callback_data":"bluebot_update_run"},{"text":"🔍 وضعیت","callback_data":"bluebot_update_status"}]]}'
    fi

    if [ -n "$markup" ]; then
        curl -fsS --max-time 12             --data-urlencode "chat_id=$CHAT_ID"             --data-urlencode "text=$text"             --data-urlencode "reply_markup=$markup"             "https://api.telegram.org/bot${token}/sendMessage" >/dev/null 2>&1 || true
    else
        curl -fsS --max-time 12             --data-urlencode "chat_id=$CHAT_ID"             --data-urlencode "text=$text"             "https://api.telegram.org/bot${token}/sendMessage" >/dev/null 2>&1 || true
    fi
}

write_status "running" "BlueBot update is running."
notify "🚀 بروزرسانی BlueBot شروع شد. تا پایان عملیات از تغییر فایل‌های سرور خودداری کنید."

: > "$LOG_FILE"
export TERM="${TERM:-xterm}"

UPDATE_ARGS=(update --background)
case "$INSTALLED_CHANNEL" in
    release) UPDATE_ARGS+=(--version "$REF") ;;
    beta)    UPDATE_ARGS+=(--ref "$REF") ;;
esac

if /usr/local/bin/bluebot "${UPDATE_ARGS[@]}" >>"$LOG_FILE" 2>&1; then
    if [ -f "$BOT_DIR/scripts/update-state.php" ]; then
        php "$BOT_DIR/scripts/update-state.php" "$INSTALLED_CHANNEL" "$REF" >>"$LOG_FILE" 2>&1 || true
    fi

    NEW_VERSION=""
    if [ -f "$BOT_DIR/src/Support/UpdateManager.php" ]; then
        NEW_VERSION="$(php -r 'require $argv[1]; echo bluebotUpdateCurrentVersion();' "$BOT_DIR/src/Support/UpdateManager.php" 2>/dev/null || true)"
    fi
    if [ -z "$NEW_VERSION" ] && [ -f "$BOT_DIR/version" ]; then
        NEW_VERSION="$(tr -d '\r\n' < "$BOT_DIR/version")"
    fi

    write_status "success" "BlueBot update completed successfully."
    notify "✅ بروزرسانی BlueBot با موفقیت انجام شد. نسخه فعلی: ${NEW_VERSION:-unknown}"
    rm -f "$RUNNING_FILE"
    exit 0
fi

write_status "failed" "BlueBot update failed. See /var/log/bluebot-update.log"
notify "❌ بروزرسانی BlueBot ناموفق بود. برای تلاش دوباره دکمه زیر را بزنید." 1
rm -f "$RUNNING_FILE"
exit 1
