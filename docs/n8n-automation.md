# اتوماسیون باز و اتصال n8n

این پروژه یک **لایهٔ اتوماسیون باز (Open Automation Layer)** دارد که به‌گونه‌ای طراحی
شده تا یک متخصص n8n هیچ محدودیتی حس نکند: هر رویدادی بیرون می‌رود، طیف گسترده‌ای از
اکشن‌ها داخل می‌آید، هر دو جهت با HMAC امضا می‌شوند، و **هیچ وابستگی اجباری** به n8n
وجود ندارد — همان webhookها با Make، Zapier یا کد خودتان هم کار می‌کنند.

> همه چیز **اختیاری و پیش‌فرض خاموش** است. تا وقتی در پنل فعال نکنید، `fire_event()`
> یک no-op ارزان است و رفتار فعلی ربات/فروشگاه تغییری نمی‌کند.

تنظیمات از مسیر پنل: **منوی «اتوماسیون / n8n»** (`panel/automation.php`).

---

## ۱) جهت خروجی — رویدادها به n8n

وقتی اتفاقی در ربات می‌افتد، یک `POST` JSON به هر «مقصد» فعال فرستاده می‌شود.

**ساختار بدنهٔ ارسالی (Envelope):**

```json
{
  "event": "order.shipped",
  "data": {
    "order_id": "1023",
    "carrier": "tipax",
    "carrier_name": "تیپاکس",
    "tracking_code": "TPX123456",
    "tracking_url": "https://tipaxco.com/tracking?code=TPX123456"
  },
  "bot_id": 0,
  "fired_at": "2026-06-08T12:00:00+03:30",
  "source": "mirzabot"
}
```

**هدرها:**

| هدر               | مقدار                                            |
|-------------------|--------------------------------------------------|
| `Content-Type`    | `application/json`                               |
| `X-Mirzabot-Event`| نام رویداد (مثلاً `order.shipped`)               |
| `X-Mirzabot-Bot`  | شناسهٔ ربات                                       |
| `X-Signature`     | `sha256=` + HMAC-SHA256 بدنه با **Secret** پنل   |

### رویدادهای موجود

| رویداد                  | توضیح                                  |
|-------------------------|----------------------------------------|
| `order.created`         | سفارش جدید ثبت شد                       |
| `order.paid`            | سفارش پرداخت شد                         |
| `order.status_changed`  | وضعیت سفارش تغییر کرد                   |
| `order.shipped`         | کد رهگیری ثبت شد / ارسال شد             |
| `order.delivered`       | سفارش تحویل شد                          |
| `order.canceled`        | سفارش لغو شد                            |
| `payment.received`      | پرداخت دریافت شد                        |
| `user.registered`       | کاربر جدید ثبت‌نام کرد                  |
| `user.balance_changed`  | موجودی کاربر تغییر کرد                  |
| `service.created`       | سرویس/اشتراک جدید ساخته شد              |
| `service.expiring`      | سرویس رو به انقضا                       |
| `ticket.created`        | تیکت پشتیبانی جدید                      |
| `custom.trigger`        | تریگر دلخواه (برای دکمه‌های سفارشی)     |

> هر مقصد می‌تواند روی **«همهٔ رویدادها»** تنظیم شود یا فقط چند رویداد را تیک بزند.
> می‌توانید چند مقصد بسازید (مثلاً یک ورک‌فلو برای سفارش‌ها، یکی برای کاربران).

### تأیید امضا در n8n (اختیاری ولی توصیه‌شده)

در یک نود **Function/Code** صحت امضا را چک کنید:

```js
const crypto = require('crypto');
const secret = 'YOUR_SECRET'; // همان Secret پنل
const body = JSON.stringify($json.body); // بدنهٔ خام
const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
if (expected !== $json.headers['x-signature']) {
  throw new Error('Invalid signature');
}
return $json;
```

---

## ۲) جهت ورودی — کنترل ربات از n8n

n8n با نود **HTTP Request** به اندپوینت زیر درخواست می‌زند:

```
POST https://YOUR-DOMAIN/api/automation.php
Authorization: Bearer <inbound_token>
Content-Type: application/json
```

پاسخ همیشه: `{ "ok": true, "result": ... }` یا `{ "ok": false, "error": ... }`.

اختیاری: می‌توانید هدر `X-Signature: sha256=HMAC(body, secret)` هم بفرستید تا علاوه بر
توکن، امضا هم بررسی شود.

### اکشن‌های مجاز

| `action`             | پارامترها                                              | کار                          |
|----------------------|--------------------------------------------------------|------------------------------|
| `send_message`       | `chat_id`, `text`, `parse_mode?`, `reply_markup?`     | ارسال پیام به یک کاربر       |
| `broadcast`          | `chat_ids[]`, `text`, `parse_mode?`                   | ارسال به چند کاربر           |
| `set_order_status`   | `order_id`, `status`                                  | تغییر وضعیت سفارش             |
| `set_order_tracking` | `order_id`, `carrier`, `tracking_code`, `bump_shipped?`| ثبت شرکت ارسال و کد رهگیری   |
| `adjust_balance`     | `user_id`, `amount` (مثبت/منفی)                       | افزایش/کاهش موجودی           |
| `get_order`          | `order_id`                                            | دریافت اطلاعات سفارش          |
| `get_user`           | `user_id`                                             | دریافت اطلاعات کاربر          |
| `fire_event`         | `event`, `data?`                                      | شلیک یک رویداد (زنجیره‌سازی)  |

### نمونه‌ها

ارسال پیام:
```json
{ "action": "send_message", "chat_id": 123456, "text": "سفارش شما ارسال شد 🚚" }
```

ثبت کد رهگیری (که خودش رویداد `order.shipped` را هم شلیک می‌کند):
```json
{ "action": "set_order_tracking", "order_id": "1023", "carrier": "tipax", "tracking_code": "TPX123456" }
```

شارژ کیف پول:
```json
{ "action": "adjust_balance", "user_id": "123456", "amount": 50000 }
```

---

## ۳) سناریوهای نمونهٔ n8n

- **سفارش جدید → پیام به تیم در تلگرام/اسلک/دیسکورد + ثبت ردیف در Google Sheets.**
- **پرداخت دریافت شد → صدور فاکتور در حسابداری (هلو/سپیدار/...) از طریق API آن‌ها.**
- **سفارش پرداخت‌شد → فراخوانی API تیپاکس برای ثبت مرسوله → سپس `set_order_tracking`
  برمی‌گردد و کد رهگیری را در ربات ثبت + به مشتری اطلاع می‌دهد.**
- **سرویس رو به انقضا → ارسال یادآوری چندکاناله (SMS + تلگرام).**
- **تیکت جدید → ساخت کارت در Trello/Jira و اعلان به پشتیبان مربوطه.**

چون رویدادها کل context را می‌فرستند و اکشن‌ها طیف وسیعی دارند، می‌توان تقریباً هر
گردش‌کاری را بدون تغییر کد هسته در n8n ساخت.

---

## ۴) دکمه‌های سفارشی ربات

در پنل «اتوماسیون / n8n» می‌توانید دکمه‌هایی برای **منوی اصلی ربات** بسازید. هر دکمه:

- **رویداد n8n** می‌زند (پیش‌فرض `custom.trigger`، یا هر نام دلخواه) همراه با context کاربر
  (`button_id`, `label`, `user_id`, `username`, `balance`).
- می‌تواند یک **پیام آماده** به کاربر بفرستد.
- یا فقط یک **لینک** باز کند (اگر فقط فیلد لینک پر شود، دکمه به‌صورت دکمهٔ URL ظاهر می‌شود).

نمونهٔ envelope هنگام کلیک:

```json
{
  "event": "custom.trigger",
  "data": { "button_id": "b1a2c3", "label": "درخواست مشاوره",
            "user_id": "123456", "username": "ali", "balance": 50000 },
  "bot_id": 0, "fired_at": "...", "source": "mirzabot"
}
```

> نمایش این دکمه‌ها در منو نیازمند فعال بودن حالت دکمه‌های شیشه‌ای منوی اصلی است
> (تنظیم `inlinebtnmain = oninline`). دکمه‌های لینک‌محور همیشه کار می‌کنند.

با این دکمه‌ها متخصص n8n می‌تواند هر گردش‌کاری دلخواه (مشاوره، درخواست سفارش خاص،
فرم‌ها، نظرسنجی، اتصال به CRM و...) را بدون تغییر کد هسته بسازد.

## مرجع توابع (`automation.php`)

| تابع                                              | کار                                            |
|---------------------------------------------------|------------------------------------------------|
| `fire_event($event, $data, $bot_id)`              | ارسال یک رویداد به همهٔ مقصدهای مشترک           |
| `emit_event($event, $data, $bot_id)` (در function.php) | پوشش امن: automation را تنبل لود می‌کند، no-op اگر خاموش |
| `get_automation_config($bot_id)`                  | خواندن تنظیمات                                 |
| `set_automation_config($cfg, $bot_id)`            | ذخیرهٔ تنظیمات                                  |
| `automation_sign($body, $secret)`                 | ساخت امضای HMAC                                |
| `automation_verify_signature($body,$secret,$sig)` | بررسی امن امضا (constant-time)                 |
| `automation_random_token($bytes)`                 | تولید توکن/Secret تصادفی                       |
| `automation_recent_logs($limit, $bot_id)`         | آخرین تحویل‌ها برای نمایش در پنل                |
| `automation_events()` / `automation_actions()`    | فهرست رویدادها / اکشن‌های مجاز                  |
| `automation_buttons($bot_id)` / `automation_button($id, $bot_id)` | دکمه‌های سفارشی / یافتن یک دکمه |

داده‌ها: `setting.automation_config` (JSON، سراسری) یا per-bot در `botsaz.setting`، و
جدول `automation_log` برای تاریخچهٔ تحویل.
