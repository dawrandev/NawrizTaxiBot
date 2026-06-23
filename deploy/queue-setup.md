# Master bot sekinligi — sabab va yechim (joylashtirish qo'llanmasi)

## Asl sabab (serverda tasdiqlangan)

Server O'zbekistonda (`dbc-server.uz`) va **api.telegram.org ga to'g'ridan-to'g'ri
ulanish ~20 soniya** (deyarli bloklangan — o'lchab ko'rildi).

1. **Proxy ishlatilmayotgan edi.** `.env` da `TELEGRAM_PROXY=socks5://127.0.0.1:1080`
   bor, lekin kod `https_proxy` muhit o'zgaruvchisini o'qirdi (u bo'sh edi), va
   `start-bot.sh` ham uni export qilmasdi. Natijada hamma Telegram chaqiruvlari
   to'g'ridan-to'g'ri 20s ketardi. Master har bosishda 2 ta shunday chaqiruv →
   "judaaa kech / o'lik".
2. **`defer()` FPM worker'ini bloklardi.** Webhook javob qaytargach ham PHP-FPM
   worker'i Telegram chaqiruvi tugaguncha band turardi.

## Yechim

1. **Proxy endi haqiqatan ishlaydi.** Kod `config('telegram.proxy')` (ya'ni
   `.env` dagi `TELEGRAM_PROXY`) ni o'qiydi va DNS'ni proxy orqali hal qiladi
   (`SOCKS5_HOSTNAME`). Bu **bot:run** ni ham tezlashtiradi (u ham 20s azobida edi).
2. **Webhook'lar endi navbatga (queue) tashlaydi**, sekin ishni alohida CLI
   worker'lar bajaradi: `master` navbati → o'z worker'i (panel doim tez),
   `driver` navbati → o'z worker'i. FPM hech qachon bloklanmaydi.
3. **Worker'lar `cron + skript` bilan tirik turadi** (sizning `start-bot.sh`
   uslubingiz, supervisorsiz — chunki sizda supervisor huquqi yo'q).

## Kod o'zgarishlari (tayyor, hali push qilinmagan)

- `app/Jobs/ProcessMasterUpdate.php`, `app/Jobs/ProcessDriverUpdate.php` — yangi
- `MasterBotWebhookController`, `DriverBotWebhookController` — `handle()` faqat
  dispatch qiladi; logika `process()` ga ko'chdi
- `app/Services/Telegram/TelegramSenderService.php` — proxy'ni config'dan o'qiydi,
  `CURLPROXY_SOCKS5_HOSTNAME`
- `config/telegram.php` — `'proxy' => env('TELEGRAM_PROXY')`
- `app/Console/Commands/BotRunCommand.php` — keraksiz flag-yield olib tashlandi
- `deploy/start-workers.sh` — keep-alive skript (cron uchun)

---

## Serverda joylashtirish (tartib bilan)

```bash
cd /var/www/dawran/data/www/taxibot.test.webclub.uz/NawrizTaxiBot

# 1. Yangi kodni torting
git pull

# 2. jobs / failed_jobs jadvallari (agar yo'q bo'lsa — idempotent)
php artisan migrate

# 3. Config'ni yangilang (proxy config'dan o'qiladi)
php artisan config:clear

# 4. Keep-alive skriptini bajariladigan qiling
chmod +x deploy/start-workers.sh

# 5. Cron'ni almashtiring: eski start-bot.sh o'rniga yangi skript
crontab -e
#   ESKI qatorni o'chiring:
#     * * * * * /bin/bash .../NawrizTaxiBot/start-bot.sh
#   YANGI qatorni qo'ying:
#     * * * * * /bin/bash /var/www/dawran/data/www/taxibot.test.webclub.uz/NawrizTaxiBot/deploy/start-workers.sh

# 6. ESKI bot:run ni to'xtating (u proxysiz ishlayapti) — cron uni proxy bilan qayta tiklaydi
pkill -f "artisan bot:run"

# 7. (Ixtiyoriy) darhol ishga tushirish, cron'ni 1 daqiqa kutmasdan:
bash deploy/start-workers.sh
```

---

## Tekshirish

```bash
# 3 ta jarayon ishlayaptimi?
ps aux | grep -E "artisan (bot:run|queue:work)" | grep -v grep
# Kutilgan: bot:run + queue=master + queue=driver

# Loglar
tail -f storage/logs/keepalive.log

# Muvaffaqiyatsiz job'lar
php artisan queue:failed
```

So'ng Telegram'da master botga `/start` yuboring → panel **~1-2s** ichida chiqishi
kerak, bot:run ishlab turganda ham. Tugmalar tez javob berishi kerak.

## MUHIM eslatmalar

- **Har `git pull` dan keyin** worker'lar eski kodni xotirada saqlaydi. Yangilang:
  ```bash
  php artisan queue:restart
  ```
  (cron 1 daqiqada ularni qayta ko'taradi; yoki `pkill -f queue:work` qiling.)
- `config:cache` ishlatmang yoki ishlatsangiz har deploy'da `config:clear` qiling
  (proxy/admin_id `env()` orqali o'qiladi).
- **Loglar avtomatik tozalanadi:** `start-workers.sh` har daqiqada `storage/logs/*.log`
  fayllarini tekshiradi va 20 MB dan oshganini bo'shatadi — disk to'lib qolmaydi.
