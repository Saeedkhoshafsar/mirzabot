# نگاشت لیبل‌های ربات (labels-map)

این سند، نگاشت بین **کلیدهای نمادین کیبورد** (که در `setting.keyboardmain` ذخیره می‌شوند) و **کلیدهای زبان** (در `lang/*.php` زیر بخش `textbot`) را مستند می‌کند. این نگاشت پایهٔ سیستم **اصطلاحات قابل‌ویرایش از پنل** است.

## معماری رزولوِر لیبل

ترتیب اولویت در تابع `bot_label($key, $lang, $default, $bot_id)` (در `function.php`):

1. **`botlabels` (DB) — per-bot:** override به‌ازای `bot_id` جاری (ربات فرزند).
2. **`botlabels` (DB) — ربات اصلی:** override با `bot_id = 0`.
3. **`lang/<lang>.php` → `textbot`:** مقدار پیش‌فرض فایل زبان.
4. **`$default` یا خود کلید:** اگر هیچ‌کدام نبود.

> `$bot_id` اگر داده نشود، از ثابت `BOT_ID` (که روتر ربات فرزند ست می‌کند) خوانده می‌شود؛
> در غیر این صورت `0` (ربات اصلی). این از سیستم چند-باتهٔ `botsaz` پشتیبانی می‌کند تا یک
> صاحب بتواند هم‌زمان ربات VPN و ربات آنلاین‌شاپ با اصطلاحات متفاوت داشته باشد.

با این روش، تغییر نام دکمه‌ها از پنل بدون دست‌زدن به فایل‌های زبان ممکن می‌شود.

## نگاشت کلید کیبورد ← کلید textbot

کلیدهای نمادین در `keyboardmain` به‌صورت زیر در `keyboard.php` (ریشهٔ پروژه) به لیبل واقعی تبدیل می‌شوند:

| کلید نمادین کیبورد        | کلید `textbot`         | مقدار پیش‌فرض (fa)        |
|---------------------------|------------------------|---------------------------|
| `text_sell`               | `sell`                 | 🔐 خرید اشتراک            |
| `text_extend`             | `extend`               | ♻️ تمدید سرویس            |
| `text_usertest`           | `userTest`             | (تست)                     |
| `text_wheel_luck`         | `wheelLuck`            | (گردونه شانس)             |
| `text_Purchased_services` | `purchasedServices`    | 🛍 سرویس های من           |
| `accountwallet`           | `accountWallet`        | 🏦 کیف پول + شارژ         |
| `text_affiliates`         | `affiliates`           | 👥 زیر مجموعه گیری        |
| `text_Tariff_list`        | `tariffList`           | 💵 تعرفه اشتراک ها        |
| `text_support`            | `support`              | ☎️ پشتیبانی               |
| `text_help`               | `help`                 | 📚 آموزش                  |

> منبع نگاشت: آرایهٔ `$replacements` در `keyboard.php` خطوط ۳۱–۴۲.

## کلیدهای textbot قابل‌ویرایش (نمونه)

این‌ها رایج‌ترین لیبل‌های قابل‌سفارشی‌سازی هستند (همگی زیر `textbot` در `lang/*.php`):

`accountWallet`, `addBalance`, `affiliates`, `agentPanel`, `cartToCart`,
`cryptoPayment`, `discount`, `extend`, `faq`, `help`, `purchasedServices`,
`requestAgent`, `sell`, `support`, `tariffList`, `userTest`, `wheelLuck`.

## اصطلاحات فروشگاه (store terminology)

واژگان عمومی (مستقل از دکمه‌ها) در `setting.store_terminology` به‌صورت JSON ذخیره و با
`get_store_terminology()` / `store_term($term)` خوانده می‌شوند:

| term       | پیش‌فرض   | کاربرد                              |
|------------|-----------|--------------------------------------|
| `customer` | کاربر     | مخاطب ربات (در فروشگاه: مشتری)        |
| `product`  | محصول     | آیتم فروش (در VPN: اشتراک/سرویس)      |
| `service`  | سرویس     | سرویس تحویل‌شده                       |
| `order`    | سفارش     | فاکتور/سفارش                          |
| `wallet`   | کیف پول   | کیف پول کاربر                         |
| `store`    | فروشگاه   | نام عمومی فروشگاه                     |

## تنظیمات حالت فروشگاه (`setting`)

| ستون                | پیش‌فرض | توضیح                                   |
|---------------------|---------|------------------------------------------|
| `store_mode`        | `vpn`   | `vpn` (پیش‌فرض/رفرنس) یا `general`        |
| `store_name`        | خالی    | نام فروشگاه                              |
| `store_currency`    | خالی    | واحد پول نمایشی                          |
| `store_terminology` | `{}`    | JSON اصطلاحات قابل‌ویرایش                 |
