#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# taxi-bot — barcha fon jarayonlarini tirik tutadi (supervisorsiz).
# Har daqiqada cron orqali chaqiriladi; o'lgan jarayonni qayta ishga tushiradi.
#
# O'rnatish:
#   chmod +x deploy/start-workers.sh
#   crontab -e  →  eski "start-bot.sh" qatorini quyidagiga almashtiring:
#     * * * * * /bin/bash /var/www/dawran/data/www/taxibot.test.webclub.uz/NawrizTaxiBot/deploy/start-workers.sh
# ─────────────────────────────────────────────────────────────────────────────

cd /var/www/dawran/data/www/taxibot.test.webclub.uz/NawrizTaxiBot || exit 1

PHP=/usr/bin/php
LOG=storage/logs/keepalive.log

# Telegram to'g'ridan-to'g'ri ~20s (bloklangan). Lokal SOCKS5 tunnel orqali yo'naltiramiz.
# (Kod .env TELEGRAM_PROXY ni ham o'qiydi; bu yerda export — qo'shimcha kafolat.)
export https_proxy="socks5h://127.0.0.1:1080"
export HTTPS_PROXY="socks5h://127.0.0.1:1080"

# Loglar disk to'ldirmasin: 20 MB dan oshgan har bir .log faylni tozalaymiz.
# (Append-rejimdagi jarayonlar uchun xavfsiz — keyingi yozuv yangidan boshlanadi.)
for logf in storage/logs/*.log; do
    [ -f "$logf" ] || continue
    size=$(stat -c%s "$logf" 2>/dev/null || echo 0)
    if [ "$size" -gt 20971520 ]; then       # 20 MB = 20*1024*1024
        : > "$logf"
        echo "$(date '+%F %T') log tozalandi: $logf (${size} bayt edi)" >> "$LOG"
    fi
done

start_if_down () {
    local pattern="$1"; shift
    if ! pgrep -f "$pattern" > /dev/null; then
        echo "$(date '+%F %T') ishga tushirilyapti: $pattern" >> "$LOG"
        nohup "$@" >> "$LOG" 2>&1 &
    fi
}

# MASTER bot — proxy orqali long-poll (webhooksiz; Telegram'ning serverga
# yetishiga bog'liq emas → kiruvchi timeout yo'q, panel doim tez)
start_if_down "artisan master:poll" \
    $PHP artisan master:poll

# DRIVER bot(lar) — proxy orqali long-poll (webhooksiz)
start_if_down "artisan driver:poll" \
    $PHP artisan driver:poll

# Guruhlarga broadcast (avvalgidek)
start_if_down "artisan bot:run" \
    $PHP artisan bot:run
