# Provisioning نهایی چندرباتهٔ Faoxima (Host-native)

این پیاده‌سازی عمداً از Docker، runtime مشترک و `childDispatch` استفاده نمی‌کند. هر Bot کپی مستقل Faoxima، `config.php`، دیتابیس، MySQL user، Linux user و PHP-FPM pool مستقل دارد؛ همهٔ poolها زیر یک سرویس مشترک `php8.3-fpm.service` اجرا می‌شوند. دامنهٔ این استقرار `kanamir.faoximabot.xyz` است، ولی token در Git ذخیره نمی‌شود و فقط هنگام اجرای CLI دریافت می‌شود.

## گزارش Audit پیش از اصلاح

| CHECK | STATUS | دلیل از نسخهٔ قبلی Source |
|---|---|---|
| Independent folder install | FIX NEEDED | مسیر `/opt/faoxima-bots` و container مستقل بود، نه `/var/www/faoxima/bot_N` روی FPM مشترک. |
| DB isolation | OK | برای هر Bot schema و MySQL user جدا ساخته می‌شد؛ این اصل حفظ شد. |
| Webhook direct | OK | URL مستقیماً به `/<bot>/index.php` اشاره می‌کرد؛ `childDispatch` استفاده نمی‌شد. |
| Webhook secret | FIX NEEDED | Provisioner از HMAC نام Bot استفاده می‌کرد، ولی `FaoximaWebhookAuth` مقدار `sha256(token + suffix)` را انتظار دارد. |
| Template clean | FIX NEEDED | copy کامل می‌توانست log/cache/compiled artifact و فایل runtime را به Bot جدید منتقل کند. |
| Faoxima initialization | OK | `table.php` اجرا می‌شد، ولی اکنون وجود جدول‌های ضروری هم صریحاً بررسی می‌شود. |
| Atomic install | FIX NEEDED | mount فقط‌خواندنی با entrypoint بازنویس `config.php` ناسازگار بود و publish نهایی health-check نداشت. |
| Rollback | FIX NEEDED | rollbackهای DB، FPM identity، state و config ownership کامل و قابل آزمون نبودند. |
| Retry | FIX NEEDED | پاسخ API، `retry_after` و تفکیک 4xx غیرقابل‌تکرار از 429/5xx درست مدل نشده بود. |
| Update | FIX NEEDED | webhook هنگام migration فعال می‌ماند و state قبلی pause/suspend/expire به active تبدیل می‌شد. |
| Pause/Resume | FIX NEEDED | متکی به stop/start container بود و نتیجهٔ حذف/ثبت webhook verify نمی‌شد. |
| Expire/Renew | FIX NEEDED | همان وابستگی container و نبود verification را داشت. |
| Suspend/Unsuspend | FIX NEEDED | همان وابستگی container و نبود verification را داشت. |
| Token rotation | FIX NEEDED | rotation در state متوقف نیز webhook را فعال می‌کرد و atomic write مالک FPM را حفظ نمی‌کرد. |
| Delete | FIX NEEDED | مدل container/nginx اختصاصی بود و backup پس از حذف نگه‌داری نمی‌شد. |
| Cron scheduler | FIX NEEDED | scheduler مرکزی host-native و lock مشترک lifecycle وجود نداشت. |
| Nginx | FIX NEEDED | route تولیدی با release symlink و `SCRIPT_FILENAME` واقعی سازگار نبود و generic نبود. |
| Permissions | FIX NEEDED | pool واقعی `user/group` صریح نداشت و `config.php` می‌توانست برای FPM ناخوانا یا برای group خوانا شود. |
| Security | FIX NEEDED | password در argv، dump در RAM، token در argv و جداسازی ناکافی OS user وجود داشت. |

## معماری و مالکیت واقعی processها

```text
/var/www/faoxima/
├── .instances/                  root:www-data 0711 (فقط traverse؛ بدون directory listing)
│   ├── bot_3-<random>/          root:fx_bot_3 0750 (source مستقل)
│   ├── bot_4-<random>/          root:fx_bot_4 0750
│   └── ...
├── bot_3 -> .instances/bot_3-<random>   # publish/switch اتمیک
├── bot_4 -> .instances/bot_4-<random>
└── ...

/etc/php/8.3/fpm/pool.d/faoxima-bot_3.conf
/var/lib/faoxima-provisioner/bot_3.json
/run/lock/faoxima-provisioner/bot_3.lock
```

سرویس مادر همان `php8.3-fpm.service` سیستم است. pool تولیدشدهٔ `bot_3` صریحاً `user=fx_bot_3` و `group=fx_bot_3` دارد؛ بنابراین workerهای Bot دیگر نمی‌توانند `config.php` آن را بخوانند. socket متعلق به `www-data:www-data` است تا فقط Nginx به FPM متصل شود. نمونهٔ واقعی pool در `ops/php-fpm/faoxima-bot-pool.example.conf` و unitهای scheduler در `ops/systemd/` قرار دارند.

فایل‌های source برابر `root:fx_bot_N 0640` و دایرکتوری‌ها `0750` هستند. فقط `logs`, `storage`, `cron`, `cronbot` متعلق به `fx_bot_N` و writable هستند. `config.php` قبل از rename اتمیک، با مالک `fx_bot_N:fx_bot_N` و mode `0600` ساخته می‌شود؛ در نتیجه FPM همیشه نسخه‌ای کامل و خوانا می‌بیند و هیچ پنجره‌ای با فایل root-only وجود ندارد. ACL فقط برای static fileها به `www-data` حق read می‌دهد و روی `config.php` صریحاً حذف می‌شود.

## Lifecycle و State Machine

```text
install -> active
active -> paused -> active       (resume)
active -> suspended -> active    (unsuspend)
active -> expired -> active      (renew)
active|paused|suspended|expired -> updating -> همان state قبلی
هر state پایدار -> deleting -> deleted
```

Pause، Suspend و Expire webhook را مستقیم از Telegram حذف و نتیجه را با `getWebhookInfo` بررسی می‌کنند. Resume، Unsuspend و Renew webhook مستقیم `https://kanamir.faoximabot.xyz/bot_N/index.php` را همراه secret سازگار با `FaoximaWebhookAuth` ثبت و مجدداً verify می‌کنند. scheduler فقط state=`active` را اجرا می‌کند.

## ترتیب دقیق `installBot()`

1. اعتبارسنجی سخت `bot_N`، version، token و admin ID؛ 2. `flock` اختصاصی؛ 3. رد هر folder/DB/user باقی‌مانده؛ 4. `getMe` پیش از ایجاد resource؛ 5. ایجاد Linux user و FPM pool و اجرای `php-fpm8.3 -tt`؛ 6. کپی clean source بدون symlink، `.git` یا `.env`؛ 7. ایجاد schema/user مستقل و password تصادفی؛ 8. تولید اتمیک `config.php`؛ 9. اجرای واقعی `table.php` با user همان Bot؛ 10. بررسی جدول‌های `users` و `setting`؛ 11. permission/ACL؛ 12. rename instance و ساخت اتمیک symlink عمومی؛ 13. health-check محلی Nginx/FPM؛ 14. `setWebhook` و `getWebhookInfo`؛ 15. ثبت state. شکست، webhook، symlink، files، DB/user، pool و Linux user را معکوس می‌کند.

## ترتیب دقیق `updateBot()`

1. lock و خواندن config/state؛ 2. dump streaming و mode `0600`؛ 3. `getMe`؛ 4. حذف موقت webhook فقط اگر Bot active است؛ 5. کپی clean source به stage؛ 6. تولید config با credential قبلی؛ 7. migration و بررسی جدول‌ها؛ 8. ownership؛ 9. rename به instance جدید؛ 10. تعویض اتمیک symlink `bot_N`؛ 11. health-check؛ 12. بازیابی webhook active؛ 13. حفظ state قبلی و ثبت version؛ 14. حذف instance قبلی. هر failure، symlink قبلی، dump دیتابیس، webhook و state قبلی را restore می‌کند.

## ترتیب دقیق `deleteBot()`

1. lock؛ 2. backup دیتابیس؛ 3. حذف و verify webhook؛ 4. state=`deleting`؛ 5. unlink مسیر عمومی و انتقال instance به quarantine؛ 6. حذف pool و reload مشترک FPM؛ 7. drop user/schema با compensation؛ 8. حذف Linux user، state و quarantine. backup ریشه‌ای با mode `0600` برای بازیابی پس از حذف عمدی نگه‌داری می‌شود. اگر drop دیتابیس fail شود، pool، instance، symlink، webhook و state قبلی بازسازی می‌شوند.

## Retry، امنیت و Failureها

* تنها درخواست‌های retry-safe تلگرام برای timeout، HTTP 429 و 5xx با exponential backoff، jitter و احترام به `retry_after` تکرار می‌شوند؛ token نامعتبر (4xx) فوراً fail می‌شود.
* همهٔ processها timeout دارند. dump/restore stream می‌شود و password از طریق defaults file تصادفی `0600` عبور می‌کند، نه argv یا log.
* نام Bot تنها `^bot_[1-9][0-9]{0,8}$` است. path مطلق normalize می‌شود؛ template symlink پذیرفته نمی‌شود؛ recursive delete فقط child مستقیم `.instances` را حذف می‌کند.
* Nginx یک route generic دارد، dotfile/secret/backup/runtime/config/table را مسدود می‌کند و فقط socket pool همان Bot را انتخاب می‌کند.
* `flock` مشترک provisioner و scheduler از install/update/delete/cron هم‌زمان برای یک Bot جلوگیری می‌کند، ولی Botهای مختلف موازی هستند.
* token ارائه‌شده در issue/chat یک secret افشاشده محسوب می‌شود و عمداً وارد repository نشده است. CLI فقط مسیر token file با mode `0600` را می‌پذیرد تا token در Git، shell history و process list دیده نشود؛ token افشاشده باید در BotFather rotate شود.

## Scheduler مرکزی

`faoxima-scheduler.timer` هر دقیقه با حداکثر ۲۰ ثانیه jitter اجرا می‌شود. scheduler فقط stateهای active را می‌خواند، symlink را داخل `.instances` validate می‌کند، lock همان Bot را می‌گیرد و `cron/cron.php` را با Linux user اختصاصی `fx_bot_N` و timeout چهار دقیقه اجرا می‌کند. در نتیجه cron collision بین lifecycle و job ممکن نیست.

## نصب فایل‌های Service

```bash
install -m 0755 bin/faoxima-provision /usr/local/sbin/faoxima-provision
install -d -m 0700 /etc/faoxima /var/lib/faoxima-provisioner /run/lock/faoxima-provisioner
install -m 0600 provisioning/config.example.json /etc/faoxima/provisioning.json
install -m 0644 ops/nginx/faoxima-bots.conf /etc/nginx/snippets/faoxima-bots.conf
install -m 0644 ops/systemd/faoxima-scheduler.service /etc/systemd/system/
install -m 0644 ops/systemd/faoxima-scheduler.timer /etc/systemd/system/
install -d -m 0755 /usr/local/lib/faoxima/provisioning
install -m 0644 provisioning/scheduler.php /usr/local/lib/faoxima/provisioning/scheduler.php
systemctl daemon-reload
systemctl enable --now faoxima-scheduler.timer
```

snippet Nginx باید داخل server TLS دامنه include شود. سپس:

```bash
faoxima-provision --config /etc/faoxima/provisioning.json preflight
install -m 0600 /dev/null /run/faoxima-bot-token
# secret manager مقدار token را در /run/faoxima-bot-token می‌نویسد
faoxima-provision --config /etc/faoxima/provisioning.json install bot_3 1.1.1 /run/faoxima-bot-token "$ADMIN_ID"
faoxima-provision --config /etc/faoxima/provisioning.json pause bot_3
faoxima-provision --config /etc/faoxima/provisioning.json resume bot_3
faoxima-provision --config /etc/faoxima/provisioning.json suspend bot_3
faoxima-provision --config /etc/faoxima/provisioning.json unsuspend bot_3
faoxima-provision --config /etc/faoxima/provisioning.json expire bot_3
faoxima-provision --config /etc/faoxima/provisioning.json renew bot_3
faoxima-provision --config /etc/faoxima/provisioning.json rotate-token bot_3 /run/faoxima-new-token
faoxima-provision --config /etc/faoxima/provisioning.json update bot_3 1.1.2
faoxima-provision --config /etc/faoxima/provisioning.json delete bot_3
```
