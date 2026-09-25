# Provisioning سادهٔ چندرباتهٔ Faoxima

معماری نهایی برای Botهای جدید مستقل است و از container، `childDispatch`، Linux user اختصاصی، FPM pool اختصاصی، socket اختصاصی، release symlink یا `.instances` استفاده نمی‌کند:

```text
Telegram -> https://kanamir.faoximabot.xyz/faoxima/bot_N/index.php
         -> Nginx generic route
         -> /run/php/php8.3-fpm.sock (shared www-data pool)
         -> /var/www/faoxima/bot_N/ (real directory)
         -> muteshop_bot_00000N / mbot_00000N@localhost
```

## نتیجهٔ Audit

| CHECK | RESULT | مدرک/توضیح |
|---|---|---|
| Real directory per bot | PASS (SIMULATED) | install مستقیماً stage مخفی را با `rename` به `bot_N` تبدیل می‌کند و تست `is_dir && !is_link` دارد. |
| No per-bot Linux user | PASS | هیچ `useradd`، `userdel` یا `fx_bot_N` در control plane جدید نیست. |
| No per-bot FPM pool | PASS | فایل pool تولید نمی‌شود و Nginx فقط socket مشترک را دارد. |
| No `.instances` architecture | PASS (SIMULATED) | directory نهایی واقعی است؛ تست عدم وجود `.instances` را assert می‌کند. |
| Shared PHP 8.3-FPM | SERVER VALIDATION REQUIRED | config به `/run/php/php8.3-fpm.sock` اشاره می‌کند؛ وجود socket و User مؤثر باید روی سرور با `php-fpm8.3 -tt` تأیید شود. |
| Generic Nginx route | SIMULATED | یک route ثابت `/faoxima/bot_N/` وجود دارد؛ parser تست آن را بررسی می‌کند. |
| No Nginx reload per bot | PASS | install/update/delete هیچ `nginx reload` یا `systemctl` اجرا نمی‌کنند. |
| Independent DB | SIMULATED | نام schema برابر `muteshop_bot_00000N` است. |
| Restricted DB user | SIMULATED | `mbot_00000N@localhost` فقط روی schema خودش `GRANT ALL` دارد. |
| Direct webhook | SIMULATED | URL مستقیم `.../faoxima/bot_N/index.php` ثبت و با `getWebhookInfo` verify می‌شود. |
| Atomic install | PASS (SIMULATED) | template در `.bot_N.new-RANDOM` آماده و سپس rename می‌شود. |
| Atomic update | PASS (SIMULATED) | `.new` آماده، `bot_N` به `.backup` و `.new` به `bot_N` rename می‌شود. |
| Rollback | PASS (SIMULATED) | failureهای migration/webhook/token/delete و DB در تست تزریق شده‌اند. |
| Retry | PASS (SIMULATED) | FRESH، OWNED_EXISTING و UNKNOWN_CONFLICT و retry امن Telegram پوشش داده شده‌اند. |
| Lifecycle integration | PASS (STATIC + SIMULATED) | install/update/pause/renew/retoken/delete/suspend/expiry به Provisioner متصل‌اند. |
| Central scheduler | PASS (SIMULATED) | `cron/cron.php` واقعی با working directory ریشهٔ Bot، lock، timeout و concurrency محدود اجرا می‌شود. |
| Secret protection | PASS | token وارد Git/argv نمی‌شود و config عمومی توسط Nginx block است. |
| Main bot integration | PASS (STATIC) | call siteهای واقعی `bot.php` به lifecycle وصل شده‌اند. |

`PASS (SIMULATED)` به معنی اجرای تست با filesystem، process runner، DB و Telegram mock است. `SERVER VALIDATION REQUIRED` عمداً PASS قطعی نیست.

## User واقعی Processها

فایل shared pool موجود پروژه `docker/php/pool.d/www.conf` نام pool را `[www]` نشان می‌دهد، اما در آن image directive صریح `user/group` وجود ندارد. معماری host-native هدف از socket استاندارد `/run/php/php8.3-fpm.sock` استفاده می‌کند و setup برای `www-data` نوشته شده است. unit واقعی scheduler نیز `User=www-data` و `Group=www-data` دارد. User نهایی FPM را باید روی سرور از خروجی `php-fpm8.3 -tt` و فایل `/etc/php/8.3/fpm/pool.d/www.conf` تأیید کرد؛ اگر متفاوت بود، `runtime_user` و مالکیت setup باید همان User شوند.

Permission هدف پس از setup:

```text
/var/www/faoxima                www-data:www-data 0750
/var/www/faoxima/bot_N          www-data:www-data 0750
normal files                    www-data:www-data 0640
config.php                      www-data:www-data 0640
/var/lib/faoxima-provisioner    www-data:www-data 0750
/run/lock/faoxima-provisioner   www-data:www-data 0750
```

PHP-FPM باید `config.php` را بخواند. حفاظت secret در لایهٔ HTTP با deny صریح Nginx انجام می‌شود، نه با ناخوانا کردن config برای runtime.

## Setup یک‌بارهٔ root

```bash
install -d -o www-data -g www-data -m 0750 /var/www/faoxima /var/lib/faoxima-provisioner /run/lock/faoxima-provisioner
install -d -o root -g www-data -m 0750 /opt/muteshop/template
install -m 0644 ops/nginx/faoxima-bots.conf /etc/nginx/snippets/faoxima-bots.conf
install -m 0644 ops/systemd/faoxima-scheduler.service /etc/systemd/system/
install -m 0644 ops/systemd/faoxima-scheduler.timer /etc/systemd/system/
install -d -m 0755 /usr/local/lib/faoxima/provisioning
install -m 0644 provisioning/scheduler.php /usr/local/lib/faoxima/provisioning/scheduler.php
nginx -t && php-fpm8.3 -tt
systemctl daemon-reload
systemctl enable --now faoxima-scheduler.timer
```

snippet فقط یک بار داخل TLS server دامنه include می‌شود. پس از setup، Main Bot با همان runtime user همهٔ lifecycle روزمره را انجام می‌دهد؛ ساخت Bot به root، sudo، reload Nginx، Linux user یا FPM pool جدید نیاز ندارد.

## Flowها

### Install

`getMe -> lock -> .new -> FRESH یا OWNED_EXISTING DB -> secure template copy/config -> idempotent table.php -> table verification -> permissions -> rename to bot_N -> local HTTP check -> direct setWebhook/getWebhookInfo -> active`

* `FRESH`: schema و user وجود ندارند؛ ساخته می‌شوند و callback کنترل‌پلین credential رمزگذاری‌شده را فوراً در Bot Record ثبت می‌کند.
* `OWNED_EXISTING`: نام schema/user دقیقاً مطابق Bot ID است، credential رمزگشایی‌شدهٔ همان record احراز اتصال می‌شود و grantها فقط schema همان Bot را پوشش می‌دهند؛ DB/user/password reuse و migration تکرار می‌شود.
* `UNKNOWN_CONFLICT`: هر resource ناقص، credential نامعتبر/غایب یا grant اضافی با `SAFE CONFLICT` متوقف می‌شود؛ هیچ drop/adopt خودکار انجام نمی‌شود.

### Update

`DB dump -> disable active webhook -> .new secure copy -> preserve verified PERSISTENT_PATHS -> migration -> validation -> bot_N to .backup -> .new to bot_N -> HTTP verification -> webhook restore -> remove .backup`

`PERSISTENT_PATHS` واقعی: `logs/`, `storage/`, `cronbot/.runtime/`, فایل‌های صف `cronbot/{users.json,users.txt,users.txt.new,info,gift,username.json}`, `users.json`, `optimization_config.php` و logهای runtime ریشه. `cron.lock` و `.cron_internal_auth` transient هستند و preserve نمی‌شوند. assetهای `app/assets` و سایر assetها source ثابت‌اند و از template تازه می‌آیند.

در شکست بعد از swap، bot جدید quarantine/حذف و `.backup` به directory واقعی `bot_N` بازگردانده می‌شود؛ DB dump، webhook و state قبلی نیز restore می‌شوند.

### Delete

`DB backup -> deleteWebhook verification -> bot_N to .delete quarantine -> DROP restricted user/schema -> remove quarantine -> remove metadata`

تا قبل از DROP schema، شکست باعث بازگشت directory، webhook و state قبلی می‌شود. پس از DROP ادعای rollback کامل وجود ندارد: شکست cleanup، state=`delete_failed` همراه quarantine و backup می‌نویسد؛ اجرای مجدد delete cleanup را resume می‌کند و Bot Record تا موفقیت نهایی باقی می‌ماند. dump فقط تا پایان delete نگه داشته می‌شود؛ در موفقیت یا تکمیل retry حذف می‌شود.

## Scheduler

Faoxima واقعاً entry point برابر `cron/cron.php` دارد. scheduler مرکزی فقط stateهای `active` و directory واقعی `bot_N` را می‌پذیرد، lock مشترک lifecycle را می‌گیرد، jobها را با concurrency محدود و configurable (پیش‌فرض ۴، بازهٔ ۱ تا ۱۶ از `FAOXIMA_SCHEDULER_CONCURRENCY`) و stagger کوتاه اجرا می‌کند و timeout چهار دقیقه دارد. unit با `www-data` اجرا می‌شود؛ root و `sudo -u` استفاده نمی‌شوند.

## مسیرهای Private و Public Nginx

`storage/` public نیست: Source واقعی فقط `storage/private/api-token` و cache داخلی را آنجا می‌نویسد. `logs/` شامل log است؛ `cron/cronbot` entrypoint/queue داخلی‌اند؛ `lib`, `re`, `api/lib`, `api/handlers`, `panel/lib`, `provisioning`, `tests`, `ops`, `bin`, `installer` کد داخلی‌اند. این مسیرها deny هستند. در مقابل `app/assets`, endpointهای public `api`, `panel`, `payment`, `sub` و فایل‌های static معمول از route generic عبور می‌کنند.

## Production entrypoint و call siteها

* Repository production entrypoint: `bot.php`.
* Deployed production entrypoint: `/var/www/muteshop/bot.php`.
* Deployment mapping: فایل repository `bot.php` مستقیماً با همین نام در مسیر deployed نصب می‌شود؛ فایل واسط/demo دیگری وجود ندارد.


* `installBot()` بعد از تأیید مدیر، نصب کامل و webhook مستقیم را انجام می‌دهد.
* `bot_update`، `pause/resume`، `renew_pay`، `retoken`، `bot_delete_confirm` و `suspend/unsuspend` مستقیماً Provisioner را صدا می‌زنند.
* `expiryReminders()` وضعیت expired را به lifecycle منتقل و webhook را حذف می‌کند.
* `childDispatch` فقط برای compatibility قدیمی باقی مانده است؛ هیچ webhook جدید Faoxima به `?child=N` ثبت نمی‌شود.

توکن افشاشدهٔ گفتگو داخل repository قرار داده نشده است. آن token باید در BotFather rotate و مقدار جدید فقط از secret storage/runtime وارد شود.


## Template clean verification

جست‌وجوی کامل repository برای `MUTESHOP_FAOXIMA_RUNTIME`, `MUTESHOP_FAOXIMA_UPDATE_B64` و `muteshop-runtime.php` بدون نتیجه است. `botapi.php` payload واقعی webhook را مستقیماً از `php://input` می‌خواند؛ CLI/B64 update injection وجود ندارد.

## Final server validation checklist

تا اجرای موارد زیر روی Host وضعیت Shared FPM/Nginx برابر `SERVER VALIDATION REQUIRED` است:

```bash
find /var/www/muteshop /opt/muteshop/template -name '*.php' -type f -exec php8.3 -l {} \;
nginx -t
php-fpm8.3 -tt
systemctl status --no-pager php8.3-fpm
systemctl status --no-pager nginx
test -S /run/php/php8.3-fpm.sock
sed -n '/^[[:space:]]*user[[:space:]]*=/p;/^[[:space:]]*group[[:space:]]*=/p;/^[[:space:]]*listen[[:space:]]*=/p' /etc/php/8.3/fpm/pool.d/www.conf
```
