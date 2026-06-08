# PLAN.md — تبدیل ربات فروش VPN به پلتفرم فروشگاهی عمومی و کاستومایزبل

> هدف کلان: این ربات/پنل که الان فقط برای فروش VPN ساخته شده را به یک **پلتفرم فروش چند-قالبی و کاستومایزبل** تبدیل کنیم. کاربر موقع راه‌اندازی یکی از **قالب‌های آمادهٔ کسب‌وکار** را انتخاب می‌کند که به کارش نزدیک‌تر است — فعلاً سه قالب: **VPN**، **آنلاین‌شاپ (shop)**، **ارسال فایل (files)** — سپس **همه‌چیز را از پنل تحت‌وب آزادانه ویرایش می‌کند** (اسم دکمه‌ها، اصطلاحات، نوع محصول، رسانه). قالب فقط «نقطهٔ شروع» است نه قفل. هستهٔ سیستم **خودکفا (بدون n8n)** است؛ اتصال به n8n فقط یک افزونهٔ اختیاری خواهد بود. (قالب AI agent فعلاً خارج از scope.)

> **روش کار (طبق AGENT_RULES):** هر استپ ۴ تا ۶ کار. بعد از هر استپ: آپدیت PLAN.md → commit + push به `origin main` → گزارش ۳ خطی → صبر برای «ادامه».
> **برنچ:** کار روی `main` انجام می‌شود (طبق AGENT_RULES).

---

## معماری چند-باته (Multi-bot / Multi-tenant) — مهم

پروژه از قبل از **چند ربات به‌ازای یک صاحب** پشتیبانی می‌کند (سیستم «ربات‌ساز»):
- جدول `botsaz`: هر ربات فرزند یک ردیف دارد با `id_user`, `bot_token`, `admin_ids`,
  `username`, و یک ستون **`setting` (JSON)** مخصوص خودش + `hide_panel`.
- هر ربات فرزند webhook جداگانه روی `vpnbot/{id_user}{username}/index.php` دارد
  (کد مشترک در `vpnbot/Default/` و آپدیت‌ها در `vpnbot/update/`).

➡️ یعنی یک کاربر می‌تواند **هم‌زمان یک ربات VPN و یک ربات آنلاین‌شاپ** داشته باشد.
به همین دلیل تنظیمات فروشگاه باید **per-bot** باشند، نه فقط سراسری:
- `store_mode`, `store_terminology`, `store_name`, `store_currency` علاوه بر جدول
  سراسری `setting` (برای ربات اصلی)، باید داخل `botsaz.setting` (JSON هر ربات فرزند) هم
  قابل ذخیره/خواندن باشند.
- لیبل‌ها (`botlabels`) باید یک ستون اختیاری `bot_id` (پیش‌فرض `0` = ربات اصلی) داشته
  باشند تا هر ربات فرزند override مستقل داشته باشد.
- محصولات/رسانه/کدها در آینده باید با `bot_id` ایزوله شوند تا کاتالوگ هر ربات جدا باشد.

> در استپ‌های بعدی، هرجا تنظیم فروشگاهی اضافه می‌کنیم، باید scope (سراسری vs per-bot)
> را رعایت کنیم. این موضوع در استپ ۹ (حالت فروشگاه) به‌صورت کامل پیاده می‌شود.

## سیستم قالب‌های آماده (Business Presets / Templates) — تصمیم کلیدی

به‌جای اینکه پروژه پیش‌فرض روی VPN باشد، موقع راه‌اندازی یا ساختِ یک ربات جدید، کاربر یک
**قالب آمادهٔ کسب‌وکار (preset)** انتخاب می‌کند که به ساختار کارش نزدیک است؛ سپس **همهٔ
فیلدها/لیبل‌ها/اصطلاحات را آزادانه ویرایش می‌کند**. preset فقط «نقطهٔ شروع/رفرنس» است،
نه قفل.

قالب‌های فاز فعلی (۳ قالب):
| کد قالب | نام | نمونهٔ کاربرد | واژگان اولیه |
|---------|-----|----------------|--------------|
| `vpn`   | فروش VPN | اکانت/کانفیگ | سرویس، حجم، لوکیشن، اشتراک |
| `shop`  | آنلاین‌شاپ | کالای فیزیکی/دیجیتال | محصول، سبد خرید، سفارش، ارسال |
| `files` | ارسال فایل | کانال فیلم/زیرنویس | فایل، اشتراک، دانلود |

- **AI agent فعلاً خارج از scope است.** دلیل: ماهیت AI agent «باز» است (n8n / تلگرام‌محض /
  API مستقیم)؛ دادن یک رفرنس آماده کاربر را محدود/گیج می‌کند به‌جای کمک. به فاز بعدی موکول شد.
- هر قالب = مجموعه‌ای از مقادیر اولیه برای: `store_terminology`، `botlabels`،
  منوها/کیبوردها، و `product_type` پیش‌فرض. در کد به‌صورت یک آرایهٔ presets نگه‌داری می‌شود
  (`config/presets.php` یا مشابه) و موقع انتخاب، در `setting` (ربات اصلی) یا `botsaz.setting`
  (ربات فرزند) کپی می‌شود — از آن لحظه کاملاً قابل ویرایش.
- چون پروژه multi-bot است: **هر ربات قالب خودش را دارد** (یک ربات `vpn`، یک ربات `shop`).

### نکتهٔ «ارسال فایل» (قالب files)
تلگرام برای هر فایل آپلودشده یک `file_id` دائمی می‌دهد. ما فقط `file_id` را در DB ذخیره
می‌کنیم و بعد از خرید با `sendDocument`/`copyMessage` می‌فرستیم. **بدون نیاز به ذخیره‌سازی،
سرور فایل یا سرویس بیرونی.**

## فلسفهٔ وابستگی‌ها: بدون n8n (self-contained) — تصمیم کلیدی

هستهٔ ربات باید **خودکفا** باشد: فقط PHP + MySQL + Telegram Bot API. **هیچ وابستگی اجباری
به n8n یا سرویس بیرونی نیست.** هر سه قالب فعلی ذاتاً request/response ساده‌اند و کاملاً
داخل PHP/DB قابل پیاده‌سازی‌اند (VPN: cURL مستقیم به پنل؛ shop: محصول→سفارش→پرداخت در DB؛
files: `file_id` تلگرام).

n8n فقط یک **افزونهٔ اختیاری** خواهد بود: بعداً یک «Outgoing Webhook» اضافه می‌کنیم که
روی رویدادها (سفارش جدید، پرداخت موفق، ...) در صورت تنظیم‌بودن یک URL، POST می‌زند. اگر URL
تنظیم نشده باشد، هیچ کاری انجام نمی‌شود. اینطوری:
- کاربر بدون n8n: چیزی کم ندارد.
- کاربر n8n‌دار: می‌تواند به workflowهای خودش وصل شود.

## دکمه‌های سفارشی و اکشن‌های n8n (Custom Button / Action Builder) — تصمیم کلیدی

پروژه باید **قابل‌گسترش (extensible)** باشد: کاربر می‌تواند قابلیت‌هایی که ما به‌صورت
پیش‌فرض نداریم را خودش به‌شکل **دکمه‌های سفارشی** بسازد و رفتارشان را به **workflowهای n8n**
وصل کند. همچنین می‌تواند **دکمه‌های رفرنس ما را غیرفعال کند** و نسخهٔ سفارشی خودش را
جایگزین کند (مثلاً جایگزینی «پشتیبانی» با یک منوی دولایه: «۱) پشتیبان AI ۲۴ساعته /
۲) پشتیبان واقعی»).

### انواع اکشن (Action Type)
| نوع | رفتار | پاسخ‌دهی | مثال |
|-----|--------|-----------|------|
| `internal` | اکشن رفرنس خودِ ربات | داخلی | پشتیبان واقعی، خرید، کیف پول |
| `n8n_notify` | یک‌بار POST به webhook n8n، **بدون انتظار پاسخ زنده** (fire-and-forget) | فقط ثبت/اعلان | ثبت درخواست همکاری، گزارش مشکل |
| `n8n_chat` | ورود کاربر به یک **session زنده دوطرفه**؛ هر پیام کاربر به webhook n8n می‌رود | n8n با **Telegram node خودش** (توکن همان ربات، در پروفایل n8n کاربر) مستقیم به همان `chat_id` جواب زنده می‌دهد | پشتیبان AI ۲۴ساعته |

### جریان `n8n_chat` (مهم)
- کاربر روی دکمهٔ سفارشی می‌زند → ربات یک **session** باز می‌کند (state در DB روی کاربر).
- هر پیام بعدی کاربر، توسط ربات با payload `{chat_id, user_id, username, text, message_id, bot_id, action_key}`
  به **Webhook URL** آن اکشن POST می‌شود.
- پاسخ‌دهی **بر عهدهٔ خودِ n8n** است (Telegram node کاربر، توکن ربات در پروفایل n8nِ او
  می‌نشیند — دقیقاً مثل اتصال معمولی یک ربات به n8n). توکن از سیستم ما خارج نمی‌شود.
- session تا زمانی فعال است که کاربر «خروج/بازگشت» بزند → آن‌گاه کنترل به ربات برمی‌گردد.

### مدل اجرا (stateless) و انقضای session — تصمیم نهایی
- ربات با تلگرام **webhook/stateless** است: برای هر پیام یک‌بار اجرا می‌شود و بسته می‌شود.
  «session» در `n8n_chat` فقط یک **رکورد state در DB** روی کاربر است (مثل `n8n_chat:<action>`)،
  **نه** یک اتصال/پروسهٔ همیشه‌روشن. پس مصرف رم/CPU اضافه ندارد.
- جریان: پیام کاربر → webhook ما → اگر state کاربر `n8n_chat` بود، payload به webhook n8n
  POST می‌شود و اجرای ما تمام می‌شود → n8n خودش با Telegram node جواب را به کاربر می‌دهد.
- **`session_timeout` قابل‌تنظیم توسط مدیر** (پیش‌فرض ۵ دقیقه). هدف اصلی: کنترل **هزینهٔ
  AI/n8n** + جلوگیری از گیرماندن کاربر در حالت چت.
- انقضا **lazy** بررسی می‌شود (موقع پیام بعدی): اگر `now - last_activity > session_timeout`
  → session باطل، کاربر به منوی عادی برمی‌گردد. پیام جدید = اتصال مجدد. (بدون کرون/منبع دائمی.)

### ملاحظات
- هر اکشن یک ردیف در جدول `custom_actions` (با `bot_id` برای multi-bot): شامل
  `label_key`, `action_type`, `webhook_url`, `parent_id` (برای منوی چندلایه),
  `enabled`, `position`, `session_timeout`.
- override/disable دکمه‌های رفرنس: یک نگاشت «button → enabled/replaced_by_action_id».
- امنیت: امضای ساده (`X-Bot-Signature` با secret مشترک) روی POST به n8n تا n8n مطمئن شود
  درخواست واقعاً از این ربات است.
- این قابلیت در **استپ ۱۱** پیاده می‌شود (بعد از پایه‌ها)، اما معماری لیبل‌ها/منو/state در
  استپ‌های ۲ و ۷ طوری طراحی می‌شود که این لایه بعداً تمیز سوار شود.

## معماری هدف (خلاصه طراحی)

1. **برندینگ و اصطلاحات (Terminology) قابل‌ویرایش از پنل:**
   لیبل دکمه‌ها و واژگان (مثل «کاربر/مشتری»، «اشتراک/محصول»، «سرویس») الان داخل فایل‌های `lang/*.php` زیر کلید `textbot` هاردکد است. این‌ها به یک لایهٔ **DB-backed override** منتقل می‌شوند: جدول `botlabels` (key → value به‌ازای زبان). تابع رزولوِر لیبل، اول DB را چک می‌کند، اگر نبود به فایل زبان fallback می‌کند. ویرایش از یک صفحهٔ پنل مثل درگ‌ان‌دراپ کیبورد.

2. **نوع محصول (Product Type) عمومی:**
   جدول `product` با ستون `product_type` (مثلا `vpn` | `physical` | `digital_file` | `serial_code` | `service`) و یک ستون JSON `attributes` برای فیلدهای مخصوص هر نوع گسترش می‌یابد. فیلدهای VPN فعلی (Volume_constraint, Location, inbounds, …) دست‌نخورده می‌مانند و فقط برای `product_type=vpn` معنا دارند.

3. **رسانهٔ محصول (Media):**
   جدول `product_media` (product_id, type=image|video|audio, file_path, sort). آپلود از پنل، ذخیره روی دیسک (`uploads/products/`)، نمایش در ربات هنگام خرید (sendPhoto/sendVideo/sendAudio).

4. **کد/سریال محصول (Serial / License codes):**
   جدول `product_codes` (product_id, code, status=available|sold, buyer_id, sold_at). هنگام خرید نوع `serial_code`، یک کد آزاد به کاربر تحویل داده می‌شود.

5. ✅ **تخفیف و کد تخفیف (Discounts / Coupons):**
   روی زیرساخت موجود (`DiscountSell`, `Discount`, `Giftcodeconsumed`). گسترش برای تخفیف درصدی/مبلغی، ساخت دسته‌ای کد، سقف per-user، بازهٔ زمانی، scope محصول/دسته، و جدول `discount_usage`. **انجام شد**: `validate_discount_code()`، `generate_discount_codes()`، `record_discount_use()`، `panel/discounts.php`، جریان کد تخفیف در ربات.

6. ✅ **سفارش و ردگیری (Orders & Tracking):**
   روی `Payment_report` موجود. وضعیت سفارش، **کد رهگیری پستی واقعی** (پست/تیپاکس/چاپار/ماهکس + لینک رهگیری)، و اطلاع‌رسانی خودکار به کاربر. **انجام شد**: `order_statuses()`، `shipping_carriers()`، `set_order_status()`، `set_order_tracking()`، `notify_order_update()`، `panel/orders.php`، «سفارش‌های من» در ربات.

7. ✅ **آدرس و اطلاعات مشتری (Customer / Address):**
   جدول `customer_address` (user_id, bot_id, full_name, phone, province, city, postal_code, address, is_default). **انجام شد**: helperهای `address_save/list/get/default/set_default/delete/format`، جریان چندمرحله‌ای دریافت آدرس در ربات (`shop_handle_address_step`) برای محصولات `needs_address`، و نمایش آدرس در `panel/orders.php`.

8. ✅ **انبار و موجودی (Inventory):**
   موجودی در سطح محصول و واریانت (در `attributes` JSON). **انجام شد**: `product_tracks_stock()`، `product_stock()`، `product_decrement_stock()`؛ کاهش خودکار هنگام فروش، نمایش «ناموجود»/«تنها N عدد» در ربات، gate در `shop_product_available()`.

9. ✅ **جستجو و فیلتر پیشرفته (Search & Filter):**
   **انجام شد**: پنل `panel/search.php` (جستجوی یکپارچه در محصول/سفارش/کاربر + فیلتر نوع/وضعیت/بازهٔ قیمت)، و در ربات `shop_search_products()` + دکمهٔ جستجو + `/search`.

10. **حالت پیش‌فرض VPN:**
    مهاجرت‌ها non-destructive‌اند (`addFieldToTable`). نصب تازه = همان رفتار VPN. تغییر `store_mode`/`store_terminology` از پنل، تجربهٔ فروشگاه عمومی را فعال می‌کند.

11. **پروفایل پنل / حالت چندگانه (Panel Profiles — استپ ۸ه):**
    یک نصب می‌تواند به‌شکل کانتینرهای مستقل (Coolify) یا چندبات روی یک نصب اجرا شود؛ هر
    کدام باید «پروفایل» خودش را داشته باشد: `vpn` (پیش‌فرض)، `shop` (آنلاین‌شاپ)،
    `digital` (فایل/دانلود)، `channel` (کانال زیرنویس/اشتراک)، و `custom` (محیط خالی که
    خود کاربر با ویرایش پنل می‌سازد). منبع حالت با اولویت: **ENV `PANEL_MODE`** (سناریوی
    تک‌کانتینر/Coolify) ← `botsaz.setting.panel_mode` (per-bot چندباته) ← `setting.store_mode`
    (سراسری) ← `vpn`. UI انتخاب حالت در پنل تنظیمات. این زیربنای ویزارد راه‌اندازی (استپ ۹) است.

---

## کشفیات از کد موجود (بررسی عمیق — مهم: دوباره نسازیم!)
بررسی واقعی کدبیس نشان داد بخش زیادی از زیرساخت فروشگاهی از قبل هست. روی این‌ها می‌سازیم:
- ✅ **شمارهٔ موبایل + احراز هویت**: `user.number` + تنظیم `get_number=onAuthenticationphone` + دکمهٔ request_contact + اعتبارسنجی شمارهٔ ایران (index.php خط ~397، ~2845؛ admin.php). → **دوباره نمی‌سازیم؛ در checkout فیزیکی از همین استفاده می‌کنیم.**
- ✅ **نام دلخواه کاربر**: `user.namecustom`. ثبت‌نام: `user.register`, `user.verify`.
- ✅ **کیف‌پول**: `user.Balance`. فاکتور/سفارش: `Payment_report` + `invoice` + `api/invoice.php`.
- ✅ **پرداخت**: کارت‌به‌کارت (`card_number`)، درگاه، رمزارز (`PaySetting`, `api/payment.php`).
- ✅ **تخفیف**: `DiscountSell` (کد فروش)، `Discount` (شارژ کیف‌پول)، `Giftcodeconsumed`.
- ✅ **همکاری در فروش (Affiliate)**: جدول `affiliates` + `user.affiliates/affiliatescount` + کدمعرف `codeInvitation`. → جنبهٔ فروشگاهی مهم که قبلاً در پلن نبود.
- ✅ **دسته‌بندی**: `category` + `api/category.php`. **گردونهٔ شانس**: `wheel_list`. **چرخهٔ خرید عمده**: `shopSetting`.
- ❌ **شکاف‌های واقعی فروشگاه فیزیکی (باید ساخته شوند):**
  - آدرس پستی ساختاریافته (استان/شهر/کدپستی/آدرس/گیرنده) — فقط `number` هست، آدرس نیست
  - وضعیت سفارش (در انتظار→ارسال→تحویل→لغو) — `Payment_report` وضعیت ندارد
  - **کد رهگیری مرسوله** (tracking_code واقعی + شرکت ارسال + لینک رهگیری) — `TrackingCode` در lang فقط لیبل ستون پنل است، نه کد رهگیری پست
  - روش ارسال انتخابی کاربر هنگام خرید + هزینهٔ ارسال در فاکتور
  - موجودی/انبار و کاهش خودکار

## جریان کامل خرید فیزیکی (Checkout Flow) — طراحی هدف
این جریان end-to-end در استپ‌های ۷ و ۸ب/۸ج پیاده می‌شود (حالت VPN دست‌نخورده):
1. کاربر محصول را می‌بیند (رسانه + توضیح + قیمت + واریانت‌های موجود).
2. اگر محصول واریانت دارد → انتخاب رنگ/سایز (فقط واریانت‌های دارای موجودی نمایش داده می‌شوند).
3. انتخاب تعداد → چک موجودی.
4. اگر `needs_address` → گرفتن/انتخاب آدرس:
   - اگر `user.number == none` و موبایل لازم است → request_contact (از سیستم موجود).
   - نام و نام‌خانوادگی گیرنده، استان، شهر، کدپستی، آدرس دقیق → ذخیره در `customer_address` (قابل انتخاب دفعهٔ بعد).
5. انتخاب **روش ارسال** (از `shipping_methods` محصول: پست/تیپاکس/پیک/حضوری) → هزینهٔ ارسال به فاکتور اضافه می‌شود.
6. ورود **کد تخفیف** (اختیاری) → اعتبارسنجی و کسر مبلغ.
7. نمایش خلاصهٔ سفارش (محصول + واریانت + تعداد + ارسال + تخفیف + جمع کل) → تأیید.
8. پرداخت (کیف‌پول/کارت‌به‌کارت/درگاه — سیستم موجود).
9. پس از پرداخت موفق: کاهش موجودی، ثبت سفارش با `order_status=paid`، اطلاع به ادمین.
10. ادمین در پنل: آماده‌سازی → ثبت **کد رهگیری + شرکت ارسال** → وضعیت `shipped` → اطلاع خودکار به کاربر با کد رهگیری.
11. کاربر در «سفارش‌های من»: مشاهدهٔ وضعیت، کد رهگیری و لینک رهگیری شرکت پستی.

---

## چک‌لیست جوانب یک پنل فروشگاهی حرفه‌ای (مرجع کامل)
هدف: هیچ جنبهٔ مهمی جا نماند. هر مورد یا انجام شده یا در یکی از استپ‌ها برنامه‌ریزی شده.

### محصول و کاتالوگ
- [x] انواع محصول (VPN/فیزیکی/فایل/کد/خدمت) — استپ ۳،۵
- [x] واریانت (رنگ/سایز) با موجودی و قیمت مستقل — استپ ۵ب
- [x] رسانهٔ محصول (تصویر/ویدیو/صوت) — استپ ۴
- [x] برند، SKU، گارانتی، وزن — استپ ۵ب
- [x] دسته‌بندی محصول (موجود: `api/category.php`) — اتصال به فیلتر/جستجو در استپ ۸د (`shop_search_products` + `panel/search.php`)
- [x] موجودی/انبار و کاهش خودکار + هشدار — استپ ۸ج (`product_stock()` + `product_decrement_stock()` + نمایش موجودی در ربات)
- [x] محصول ناموجود — استپ ۸ج (`shop_product_available()` با چک موجودی)

### قیمت و تخفیف
- [x] تخفیف درصدی/مبلغی — استپ ۸ (`discount_kind` percent/fixed در `DiscountSell`)
- [x] کد تخفیف + ساخت دسته‌ای + سقف per-user + بازهٔ زمانی + scope — استپ ۸ (`panel/discounts.php` + `generate_discount_codes()` + `discount_usage`)
- [x] حداقل مبلغ سفارش / سقف تخفیف — استپ ۸ (`min_order` / `max_amount`)
- [x] کد شارژ کیف‌پول (موجود: `Discount`) — حفظ می‌شود

### سفارش و پرداخت
- [x] فاکتور/پرداخت (موجود: `Payment_report`, `api/payment.php`, `api/invoice.php`)
- [x] کارت‌به‌کارت/درگاه/رمزارز (موجود: `card_number`, `PaySetting`)
- [x] سبد چندقلمی + تعداد (quantity) — استپ ۱۳ (`shop_cart_*` + ستون `user.shop_cart` + خلاصهٔ سفارش + `shop_checkout_cart`)
- [x] وضعیت سفارش (در انتظار→پرداخت→آماده‌سازی→ارسال→تحویل→لغو→مرجوعی) — استپ ۸ب (`order_statuses()` + `set_order_status()` + `panel/orders.php`)
- [x] **کد رهگیری پستی واقعی + شرکت ارسال + لینک رهگیری** (پست/تیپاکس/چاپار/ماهکس) — استپ ۸ب (`shipping_carriers()` + `carrier_tracking_url()` + `set_order_tracking()`)
- [x] روش‌های ارسال چندگانه (پست/تیپاکس/پیک/حضوری) + هزینه در فاکتور — استپ ۸ب (انتخاب شرکت در `panel/orders.php`؛ هزینه در `Payment_report.shipping_cost`)
- [x] «سفارش‌های من» در ربات + اطلاع‌رسانی تغییر وضعیت — استپ ۸ب (`shop_render_my_orders()` + `notify_order_update()`)

### مشتری
- [x] کیف‌پول/موجودی کاربر (موجود: `Balance`)
- [x] شمارهٔ موبایل + احراز هویت (موجود: `number`, request_contact, اعتبارسنجی ایران)
- [x] نام دلخواه / ثبت‌نام (موجود: `namecustom`, `register`, `verify`)
- [x] آدرس‌های پستی ساختاریافته: گیرنده، موبایل، استان، شهر، کدپستی، آدرس (چند آدرس + پیش‌فرض) — استپ ۸ج (جدول `customer_address` + جریان دریافت آدرس در چک‌آوت + نمایش در `panel/orders.php`)
- [x] تاریخچهٔ خرید کاربر — استپ ۸ب (`user_orders()` / «سفارش‌های من» در ربات)
- [x] مدیریت سفارش از داخل تلگرام (فهرست/جزئیات/تغییر وضعیت/ثبت کد رهگیری) همگام با پنل وب — استپ ۱۲ب (`admin.php`)

### بازاریابی و رشد
- [x] همکاری در فروش / زیرمجموعه‌گیری (موجود: `affiliates`, `codeInvitation`, `affiliatescount`)
- [x] گردونهٔ شانس (موجود: `wheel_list`)
- [x] کش‌بک (موجود: `chashbackextend` در shopSetting)
- [x] کد تخفیف + ساخت دسته‌ای — استپ ۸ (`panel/discounts.php`)

### مدیریت و گزارش (پنل)
- [x] داشبورد/آمار (موجود: `panel/` + `api/statbot.php`)
- [x] جستجو و فیلتر پیشرفتهٔ محصول/سفارش/کاربر (نام/SKU/کد رهگیری/قیمت/وضعیت) — استپ ۸د (`panel/search.php` + جستجوی محصول در ربات)
- [x] گزارش فروش/تخفیف — استپ ۱۴ (`panel/reports.php`: فروش + تخفیف، بازهٔ زمانی، روند، پرفروش‌ها)
- [x] برندینگ و واژگان قابل ویرایش — استپ ۱،۲
- [x] چند-باته (هر ربات تنظیمات خودش) — موجود (botsaz)
- [x] **حالت/پروفایل پنل چندگانه** (vpn/shop/digital/channel/custom) با اولویت ENV → per-bot → سراسری — استپ ۸ه (`panel_profiles()` + `panel_mode()` + `set_panel_mode()` + `panel/store.php`)

### راه‌اندازی و توسعه‌پذیری
- [x] قالب‌های آماده + ویزارد — استپ ۹
- [x] مستندات و نمونهٔ فروشگاه — استپ ۱۰
- [x] لایهٔ اتوماسیون باز + اتصال n8n (Webhook رویدادها + API اکشن‌ها، امضای HMAC، بدون قفل‌شدن) — استپ ۱۱ (`automation.php` + `api/automation.php` + `panel/automation.php` + `docs/n8n-automation.md`)
- [x] تنظیمات حمل‌ونقل (API اختصاصی هر شرکت) + ارسال رایگان — استپ ۱۲ (`get/set_shipping_config()` + `calc_shipping_cost()` + `panel/shipping.php`)

---

## استپ‌ها

- [x] استپ ۱ — زیرساخت اصطلاحات قابل‌ویرایش (DB + رزولور)  ✅ 2026-06-07
  - [x] ساخت جدول `botlabels` در `table.php` (id, label_key, lang, label_value, updated_at) به‌صورت non-destructive
  - [x] افزودن تنظیمات فروشگاه به جدول `setting`: `store_mode` (پیش‌فرض `vpn`)، `store_terminology` (JSON)، `store_name`، `store_currency`
  - [x] نوشتن تابع `bot_label($key, $lang, $default)` در `function.php` که اول `botlabels` را چک کند و سپس به `lang/*.php` fallback کند
  - [x] افزودن توابع کمکی `get_store_terminology()` و `store_term()` برای واژگان قابل‌ویرایش (مشتری/محصول/سرویس)
  - [x] اتصال آرایهٔ `$replacements` در `keyboard.php` به `bot_label()` تا override پنل روی منوی اصلی اعمال شود
  - [x] مستندسازی نگاشت کلیدهای `textbot` در `docs/labels-map.md`

- [x] استپ ۲ — صفحهٔ پنل برای ویرایش اصطلاحات و برندینگ  ✅ 2026-06-08
  - [x] توابع کمکی در `function.php`: `bot_label_default()`، `set_bot_label()` (upsert/delete override)، `set_store_terminology()` (سراسری + per-bot روی `botsaz.setting`)
  - [x] ساخت `panel/labels.php` (self-contained مثل `keyboard.php`/`settings.php`: GET رندر فرم + POST ذخیره با CSRF)
  - [x] انتخابگر scope ربات (ربات اصلی + ربات‌های فرزند از `botsaz`) — هر ربات اصطلاحات خودش
  - [x] سه بخش: برندینگ (نام/واحد پول، فقط ربات اصلی)، اصطلاحات فروشگاه، لیبل دکمه‌ها (با نمایش مقدار پیش‌فرض و نشان «ویرایش‌شده»)
  - [x] افزودن آیتم ناوبری «برندینگ و اصطلاحات» در `panel/inc/layout_head.php`
  - [x] منطق ذخیره: خالی/برابر با پیش‌فرض → حذف override (تمیز)؛ غیر این → ذخیره per-bot
  - یادداشت: API جدا و JS مجزا لازم نشد چون این کدبیس الگوی صفحهٔ self-contained دارد (ساده‌تر و کم‌ریسک‌تر). اتصال خروجی ربات در استپ ۱ از طریق `keyboard.php` و `bot_label()` انجام شده.

- [x] استپ ۳ — گسترش اسکیمای محصول به مدل عمومی  ✅ 2026-06-08
  - [x] افزودن ستون‌های `product_type` (پیش‌فرض `vpn`)، `attributes` (JSON)، `bot_id` به جدول `product` — هم در CREATE هم در مهاجرت `else` (non-destructive via `addFieldToTable`)
  - [x] ردیف‌های قدیمی به‌صورت خودکار `product_type='vpn'` می‌گیرند → رفتار VPN بدون تغییر
  - [x] رجیستری انواع محصول `product_types()` در `function.php` (vpn/physical/digital_file/serial_code/service) با تعریف فیلدهای هر نوع
  - [x] توابع کمکی: `is_valid_product_type()`, `product_attributes()`, `product_attr()`, `set_product_type()`
  - [x] مستندسازی شِمای جدید در `docs/product-model.md`
  - یادداشت: جدول‌های `product_media` و `product_codes` در استپ‌های ۴ و ۶ ساخته می‌شوند (نزدیک‌تر به محل استفاده).

- [x] استپ ۴ — آپلود رسانهٔ محصول (تصویر/ویدیو/موسیقی) در پنل  ✅ 2026-06-08
  - [x] ساخت جدول `product_media` (product_id, media_type, file_path, telegram_file_id, sort) در `table.php`
  - [x] ساخت دایرکتوری `uploads/products/` + `.htaccess` ایمن (غیرفعال‌سازی اجرای php) + قواعد `.gitignore`
  - [x] توابع کمکی `product_media_list/add/delete/detect` در `function.php` (اعتبارسنجی MIME، حذف فایل از دیسک)
  - [x] صفحهٔ `panel/product_media.php` (آپلود چندتایی، گالری پیش‌نمایش تصویر/ویدیو/صوت، حذف با CSRF)
  - [x] افزودن دکمهٔ «رسانه» به هر ردیف محصول در `panel/product.php`
  - یادداشت: به‌جای endpoint در `api/product.php`، صفحهٔ self-contained ساخته شد (الگوی رایج این کدبیس، امن‌تر برای آپلود فایل با CSRF نشست).
  - [ ] مدیریت حذف فایل فیزیکی هنگام حذف رسانه/محصول

- [x] استپ ۵ — فرم محصول داینامیک بر اساس نوع محصول (پنل)  ✅ 2026-06-08
  - [x] افزودن انتخابگر `product_type` در مدال‌های افزودن و ویرایش محصول
  - [x] رندر داینامیک فیلدهای مخصوص هر نوع با JS (از `window.PRODUCT_TYPES` که از `product_types()` تزریق می‌شود)
  - [x] پشتیبانی از انواع فیلد: text / number / textarea / bool در `panel/js/product.js`
  - [x] حفظ کامل فیلدهای VPN برای `product_type=vpn` (پیش‌فرض؛ بدون تغییر رفتار فعلی)
  - [x] ذخیرهٔ نوع + فیلدها در ستون `attributes` (JSON) از طریق `set_product_type()` در هندلرهای add/edit (با اعتبارسنجی سمت‌سرور؛ فقط فیلدهای مجاز هر نوع نگه داشته می‌شوند)
  - [x] پر شدن مقادیر attributes هنگام ویرایش (decode از JSON محصول)

- [x] استپ ۵ب — غنی‌سازی انواع محصول برای رفرنس کامل  ✅ 2026-06-08
  - [x] نوع فیلد جدید `repeater` (جدول ردیف‌های تکرارشونده) در `product_types()` و `panel/js/product.js`
  - [x] کالای فیزیکی: برند، SKU، گارانتی، موجودی کل، وزن، نیاز به آدرس
  - [x] **واریانت محصول (variants)**: هر رنگ/سایز ردیف مستقل با موجودی و اختلاف قیمت جداگانه (color/size/sku/stock/price_diff)
  - [x] **روش‌های ارسال (shipping_methods)**: پست/تیپاکس/پیک/حضوری، هرکدام هزینه و زمان مستقل (name/price/days)
  - [x] فایل دیجیتال: `file_type` به‌صورت `select` (document/photo/video/audio)
  - [x] رندر JS برای `select` (dropdown از options) و `repeater` (افزودن/حذف ردیف، نام `attr[key][i][col]` → آرایهٔ تودرتو در PHP)
  - [x] `set_product_type()` پاک‌سازی هوشمند: حذف ردیف‌های خالی repeater، فقط ستون‌های مجاز، نرمال‌سازی bool، اعتبارسنجی select
  - [x] استایل جدول repeater + `.field-hint` در `panel/product.php`

- [x] استپ ۶ — مدیریت کد/سریال محصول (پنل + توابع)  ✅ 2026-06-08
  - [x] جدول `product_codes` در `table.php` (product_id, code, status=available|sold, buyer_id, order_id, sold_at) non-destructive با ایندکس روی (product_id, status)
  - [x] UI افزودن کدها به‌صورت دسته‌ای (هر خط یک کد) در `panel/product_codes.php` با حذف تکراری‌ها
  - [x] نمایش شمارش کدهای available/sold/total (کارت آمار) + هشدار «هیچ کد آزادی موجود نیست»
  - [x] دکمهٔ «کدها» در لیست محصولات فقط برای نوع `serial_code` با بَج تعداد آزاد (قرمز اگر صفر)
  - [x] توابع `product_codes_list/count/add_bulk/delete` + `deliver_serial_code()` در `function.php`
  - [x] منطق تحویل اتمیک: `deliver_serial_code()` با تراکنش + `FOR UPDATE` تا یک کد دوبار به دو خریدار داده نشود (آمادهٔ استفاده در checkout استپ ۷)
  - [x] حذف فقط کدهای آزاد مجاز است (تاریخچهٔ فروش حفظ می‌شود)
  - یادداشت: مثل استپ ۴، صفحهٔ self-contained ساخته شد (الگوی کدبیس) به‌جای endpoint در `api/product.php`.

- [x] استپ ۷ — منطق خرید عمومی در ربات (Checkout) بر اساس نوع محصول  ✅ 2026-06-08
  - [x] **مسیر خرید کاملاً مجزا و ایزوله از VPN**: شاخهٔ `shoplist`/`shopview_`/`shopbuy_` + دستور `/shop` در ابتدای زنجیرهٔ dispatch `index.php` (قبل از شاخه‌های VPN) → هیچ تماسی با منطق VPN ندارد
  - [x] نمایش محصول: رسانه (عکس/ویدیو/صوت از `product_media` با file_id یا URL دامنه) + نام + توضیح + قیمت + دکمهٔ خرید/بازگشت
  - [x] لیست فروشگاه: `shop_render_list()` فقط محصولات `product_type <> 'vpn'` با اسکوپ ربات (`bot_id`)
  - [x] تحویل بر اساس نوع: `digital_file` → ارسال فایل تلگرام (photo/video/audio/document)؛ `serial_code` → `deliver_serial_code()` اتمیک (استپ ۶)؛ `service` → پیام تحویل؛ `physical` → ثبت سفارش + پیام هماهنگی (جریان کامل آدرس/ارسال در استپ ۸ج)
  - [x] چک موجودی پیش از خرید (`shop_product_available()`؛ برای serial_code نیاز به کد آزاد)
  - [x] چک کیف‌پول + کسر `Balance` + ثبت سفارش در `Payment_report` (با ستون‌های جدید `product_id`/`order_status`)
  - [x] **بازگشت خودکار وجه** اگر تحویل کد/فایل ممکن نشد (refund safety)
  - [x] اطلاع‌رسانی سفارش جدید به ادمین‌ها
  - [x] گسترش `Payment_report` (non-destructive) با ستون‌های فروشگاهی: product_id, order_status, quantity, variant, tracking_code, shipping_carrier, carrier_name, shipping_method, shipping_cost, address_id, admin_note (زیرساخت استپ ۸ب)
  - [x] توابع جدید در `function.php`: `store_mode/store_currency/shop_product/shop_product_list/shop_product_available/shop_render_list/shop_render_product/shop_deliver_product/shop_record_order/shop_handle_callback`
  - [x] رگرسیون: مسیر VPN دست‌نخورده (شاخهٔ shop فقط روی الگوهای `shop*` فعال می‌شود؛ همهٔ فایل‌ها lint سالم)
  - [ ] واریانت/تعداد/خلاصهٔ سفارش پیش از پرداخت و هزینهٔ ارسال در فاکتور → در استپ ۸ج (همراه آدرس و موجودی) تکمیل می‌شود
  - یادداشت: دکمهٔ فروشگاه به منوی اصلی اضافه نشد چون کیبورد اصلی قابل‌تنظیم/داینامیک است (ریسک رگرسیون VPN)؛ به‌جای آن دستور `/shop` + کال‌بک‌ها. اتصال به منو در ویزارد/قالب استپ ۹ انجام می‌شود.

- [x] استپ ۸ — تخفیف‌ها و کدهای تخفیف حرفه‌ای (Discounts / Coupons)
  - زیرساخت موجود کشف‌شده: `DiscountSell` (codeDiscount/limitDiscount/usedDiscount/type/time/agent)، `Discount` (کد شارژ کیف‌پول)، `Giftcodeconsumed`. روی این‌ها می‌سازیم نه از صفر.
  - [ ] گسترش `DiscountSell` (non-destructive): `discount_kind` (percent|fixed)، `max_amount` (سقف تخفیف درصدی)، `min_order` (حداقل مبلغ سفارش)، `start_at`/`expire_at` (بازهٔ اعتبار)، `per_user_limit` (سقف به‌ازای هر کاربر)، `product_scope` (JSON: all|category|product ids)، `bot_id`، `enabled`
  - [ ] **ساخت دسته‌ای کد تخفیف**: تولید N کد یکتا با پیشوند دلخواه (مثلا `EID-XXXX`) در یک عملیات
  - [ ] **تخفیف چندتایی/گروهی**: اعمال یک قانون تخفیف روی چند محصول/دسته هم‌زمان (product_scope)
  - [ ] جدول `discount_usage` (code, user_id, order_id, amount, used_at) برای کنترل دقیق سقف per-user و گزارش
  - [ ] صفحهٔ پنل `panel/discounts.php` (self-contained، per-bot): لیست/ساخت/ویرایش/فعال‌غیرفعال، ساخت دسته‌ای، نمایش used/limit، انقضا
  - [ ] منطق `validate_discount_code($code, $user_id, $cart)` در `function.php`: چک نوع، بازهٔ زمانی، حداقل سفارش، سقف کلی و per-user، scope محصول → بازگشت مبلغ تخفیف یا خطای دقیق
  - [ ] ورود کد تخفیف توسط کاربر در ربات هنگام خرید + نمایش مبلغ نهایی پس از تخفیف
  - [ ] گزارش استفاده از تخفیف در پنل (چند بار، چه مبلغی، چه کسی)
  - [ ] رگرسیون: سیستم کد شارژ کیف‌پول `Discount` فعلی دست‌نخورده

- [x] استپ ۸ب — سفارش، وضعیت و کد رهگیری (Orders & Tracking)
  - [ ] گسترش `Payment_report` (non-destructive): `order_status` (pending|paid|preparing|shipped|delivered|canceled|refunded)، `tracking_code` (کد رهگیری مرسوله)، `shipping_carrier` (post|tipax|courier|pickup|custom)، `carrier_name` (نام دلخواه شرکت)، `shipping_method`، `shipping_cost`، `address_id`، `product_id`، `variant` (JSON رنگ/سایز)، `quantity`، `admin_note`
  - [ ] رجیستری شرکت‌های ارسال + الگوی لینک رهگیری: `shipping_carriers()` در function.php (پست ایران→`tracking.post.ir/?id={code}`، تیپاکس، پیک، حضوری، سفارشی) — لینک رهگیری به کاربر داده شود
  - [ ] صفحهٔ پنل `panel/orders.php` (self-contained، per-bot): لیست با فیلتر وضعیت/تاریخ، جزئیات کامل سفارش (محصول، واریانت، تعداد، آدرس گیرنده، روش ارسال)، تغییر وضعیت، ثبت کد رهگیری + انتخاب شرکت ارسال، یادداشت ادمین
  - [ ] اطلاع‌رسانی خودکار به کاربر در ربات هنگام تغییر وضعیت (آماده‌سازی/ارسال‌شد + کد رهگیری + لینک رهگیری/تحویل‌شد/لغو)
  - [ ] دکمهٔ «سفارش‌های من» در ربات: وضعیت + کد رهگیری + لینک رهگیری + جزئیات
  - [ ] توابع `set_order_status()`, `set_order_tracking()`, `notify_order_update()`, `order_status_label()` در `function.php`
  - [ ] رگرسیون: سفارش‌های VPN فعلی (که وضعیت ندارند) دست‌نخورده

- [x] استپ ۸ج — آدرس مشتری و انبار/موجودی (Address & Inventory)
  - [ ] جدول `customer_address` (id, user_id, bot_id, recipient_name, recipient_phone, province, city, postal_code, address_line, plaque, unit, is_default, created_at) — نام‌ونام‌خانوادگی گیرنده، موبایل گیرنده، استان، شهر، کدپستی، آدرس، پلاک، واحد
  - [ ] جریان گرفتن آدرس در ربات (step به step): اگر موبایل ندارد → request_contact (سیستم موجود)؛ سپس گیرنده→استان→شهر→کدپستی(اعتبارسنجی ۱۰ رقم)→آدرس→پلاک/واحد؛ ذخیره و قابل انتخاب دفعهٔ بعد
  - [ ] مدیریت آدرس‌ها در ربات: لیست آدرس‌ها، افزودن، حذف، تعیین پیش‌فرض
  - [ ] صفحهٔ پنل: نمایش کامل آدرس گیرنده در جزئیات سفارش (قابل کپی برای چاپ برچسب پستی)
  - [ ] **منطق موجودی**: کاهش خودکار `stock` (محصول یا واریانت انتخابی) به اندازهٔ `quantity` هنگام فروش موفق؛ بازگردانی هنگام لغو/مرجوعی
  - [ ] نمایش «ناموجود» و جلوگیری از خرید وقتی موجودی صفر است (سطح محصول و سطح واریانت)
  - [ ] هشدار کم‌بودن موجودی در پنل (آستانهٔ قابل تنظیم در shopSetting) + بَج «ناموجود/کم» در لیست محصولات
  - [ ] توابع `decrement_stock()`, `restore_stock()`, `check_availability()`, `variant_stock()` در `function.php`
  - [ ] اعتبارسنجی کدپستی/شمارهٔ موبایل ایران (تبدیل ارقام فارسی→انگلیسی)

- [x] استپ ۸د — جستجو و فیلتر پیشرفته (Search & Filter)
  - [ ] **پنل — محصولات**: جستجوی متنی (نام/SKU/برند)، فیلتر نوع محصول، دسته، بازهٔ قیمت، وضعیت موجودی + مرتب‌سازی
  - [ ] **پنل — سفارش‌ها**: جستجو بر اساس کد رهگیری/شناسهٔ کاربر/شماره سفارش، فیلتر وضعیت و بازهٔ تاریخ
  - [ ] **پنل — کاربران**: جستجوی موجود را بهبود (نام/یوزرنیم/آیدی) — اگر قبلاً نیست اضافه شود
  - [ ] **ربات**: دکمهٔ «جستجوی محصول» + فیلتر دسته/قیمت برای حالت فروشگاهی
  - [ ] پیاده‌سازی با query پارامتری امن (PDO bind) و حفظ فیلترها در URL (GET) برای صفحه‌بندی
  - [ ] رگرسیون: لیست‌های موجود بدون فیلتر هم درست کار کنند (فیلترها اختیاری)

- [x] استپ ۹ — قالب‌های کسب‌وکار (Presets) + ویزارد راه‌اندازی (per-bot)
  - [x] تعریف ۵ قالب در `panel_presets()` (function.php): `vpn`, `shop`, `digital`, `channel`, `custom` (واژگان + لیبل + product_type پیش‌فرض)
  - [x] ویزارد انتخاب قالب (`panel/wizard.php`)؛ `apply_panel_preset()` مقادیر را به `setting` یا `botsaz.setting` می‌نشاند (per-bot)
  - [x] صفحهٔ «تنظیمات فروشگاه» (`panel/store.php`) + ویزارد: قالب، واحد پول، واژگان (همه قابل ویرایش)
  - [x] رعایت scope: قالب per-bot/per-container (ENV `PANEL_MODE` > botsaz.setting > global)
  - [x] قفل پنل وقتی `PANEL_MODE` در ENV ست است (تک‌منبع حقیقت)
  - [x] اطمینان از سازگاری حالت VPN پیش‌فرض (رگرسیون) — بدون تنظیم، خروجی `vpn`

- [x] استپ ۱۰ — مستندات، نمونهٔ فروشگاه و جمع‌بندی
  - [x] نوشتن `docs/store-guide.md` (راهنمای کامل فروشگاه: پروفایل/PANEL_MODE/ویزارد/تخفیف/سفارش/موجودی/آدرس/جستجو/جریان خرید)
  - [x] افزودن `PANEL_MODE` و بخش Multi-Container به `DEPLOY_COOLIFY.md`
  - [x] نقشهٔ توابع کلیدی + یادداشت‌های ایمنی رگرسیون در سند
  - [x] قالب نمونهٔ «آنلاین‌شاپ» (`shop` preset) به‌عنوان مثال آماده در ویزارد

- [x] استپ ۱۱ — لایهٔ اتوماسیون باز + دکمه‌های سفارشی + اکشن‌های n8n
  - [x] پیکربندی JSON در `setting.automation_config` (سبک‌تر از جدول مجزا) + جدول `automation_log`
  - [x] خروجی: `fire_event()`/`emit_event()` با ۱۳ رویداد، امضای `X-Signature` (HMAC-SHA256)، چند مقصد با فیلتر رویداد
  - [x] ورودی: `api/automation.php` با Bearer token + ۸ اکشن (`send_message`/`broadcast`/`set_order_status`/`set_order_tracking`/`adjust_balance`/`get_order`/`get_user`/`fire_event`)
  - [x] دکمه‌های سفارشی منوی اصلی ربات: شلیک رویداد `custom.trigger`/دلخواه + پیام آماده + دکمهٔ لینک (`cbtn_<id>` در `index.php`، تزریق در `keyboard.php`)
  - [x] صفحهٔ پنل `panel/automation.php` (مقصدها، Secret، توکن، دکمه‌ها، لاگ تحویل) + مستند `docs/n8n-automation.md`
  - [x] رگرسیون: همه‌چیز opt-in و پیش‌فرض خاموش؛ no-op وقتی غیرفعال است

- [x] استپ ۱۱ب — رفع باگ دکمه‌های سفارشی + فیلتر دکمه‌های VPN  ✅ 2026-06-08
  - [x] رفع باگ ماندگاری: `set_automation_config()` بعد از نوشتن خام، `clearSelectCache()` صدا می‌زند (PR #12)
  - [x] حذف دکمه‌های مخصوص VPN در حالت فروشگاه: `strip_vpn_only_buttons()` + `vpn_only_keyboard_keys()` (PR #12)
  - [x] نمایش دکمه‌های سفارشی روی reply keyboard + هندلر تپ متنی `custom_button_by_label()` (PR #13)

- [x] استپ ۱۲ — ویرایشگر بصری دکمه‌ها (Visual Button Flow) — جایگزین سیستم دکمهٔ تخت
  > هدف: درخت نود-محور (مثل n8n/ComfyUI) برای کل دکمه‌های ربات. هر دکمه = نود؛ فرزندان نود = دکمه‌های مرحلهٔ بعد. ۹ فاز.
  - [x] فاز ۱ — لایهٔ داده (`flow.php` + اسکیمای `table.php`)  ✅ 2026-06-08 — PR #14 (merged)
    - [x] مدل `{nodes, edges, meta}` با edges = منبع حقیقت سلسله‌مراتب؛ تک‌ریشهٔ محافظت‌شده
    - [x] `get/set_button_flow`, `flow_normalise_tree`, `flow_validate_tree` (یکتایی id/بدون یال آویزان/تک‌والد/تشخیص حلقه)
    - [x] تاریخچهٔ نسخه‌ها (snapshot) + `flow_build_from_legacy` + ستون‌ها/جداول `button_flow*`
  - [x] فاز ۲ — بوم React Flow (`panel/flow.php` + `panel/js/flow_editor.js` + nav)  ✅ 2026-06-08 — PR #14 (merged)
    - [x] React 18 + React Flow 11 از CDN (بدون build)، RTL، تم تیره؛ نمایش/درگ/MiniMap
    - [x] JSON API: `?api=tree|save|history|restore|migrate` با CSRF
  - [x] فاز ۳ — عملیات نود  ✅ 2026-06-08 — PR #14 (merged)
    - [x] افزودن فرزند (درگ از پورت→رها در فضای خالی→فرم)، افزودن نود ریشه
    - [x] ویرایش (دابل‌کلیک→پنل کناری)، حذف آبشاری با تأیید، اتصال/قطع با قواعد تک‌والد/بدون‌حلقه
    - [x] محافظت نود سیستمی (غیرقابل حذف مستقیم، نوع قفل، بنر هشدار)
  - [x] فاز ۴ — نود ورودی + فایل + امنیت  ✅ 2026-06-08 — PR #14 (merged)
    - [x] `input_mode` (متن/عکس/سند/رسانه/هرنوع) + اعتبارسنجی متن (none/number/length/regex + min/max)
    - [x] محدودیت فایل (فرمت مجاز/حجم/تعداد/MIME) + بلاک‌لیست سخت فرمت‌های خطرناک (php/exe/sh/js/html/svg…)
    - [x] پاکسازی XSS متن + محدودیت تلاش (max_attempts + بازهٔ زمانی) + توابع `flow_validate_input_text/file`, `flow_sanitize_text`, `flow_blocked_extensions`
  - [x] فاز ۵ — نود شرط (انشعاب معتبر/نامعتبر)  ✅ 2026-06-08 — PR #15 (open)
    - [x] `condition_source` (last/input/n8n) + شاخه‌های `{label, op, value, goto}` با ۱۴ عملگر
    - [x] `flow_eval_condition()` (اولین تطبیق برنده) + لینک خودکار goto به فرزندان به ترتیب اتصال
    - [x] ویرایشگر شاخه در پنل کناری
  - [x] فاز ۶ — نود n8n (sync + async)  ✅ 2026-06-08 — PR #15 (open)
    - [x] `flow_breadcrumb()`/`flow_path_ids()` (مسیر خرید→نقدی→درگاه→بانک ملی) + `flow_n8n_payload()`
    - [x] `flow_n8n_send()`: async = fire-and-forget با تایم‌اوت ۸۰۰ms (ضد قفل سرور)؛ sync = انتظار + parse JSON
    - [x] فرم n8n در پنل کناری (mode/endpoint/timeout/tag/send_path/send_inputs/wait_message)
  - [x] فاز ۷ — موتور اجرای زمان‌چت در ربات + دکمه‌های back/home خودکار  ✅ 2026-06-08
    - [x] ماژول جدید `flow_runtime.php`: state کاربر روی `button_flow_state` (get/save/clear/has_await با upsert)
    - [x] پیمایش درخت هنگام تپ دکمه: کیبورد inline با `flowgo_<id>` برای فرزندان + اجرای نوع نود (message/action/input/condition/n8n)
    - [x] دکمه‌های «🔙 بازگشت» (`flowback`) و «🏠 منوی اصلی» (`flowhome`) خودکار در نودهای غیرریشه طبق `auto_back`/`auto_home`
    - [x] اجرای نود ورودی (متن/عکس/سند + اعتبارسنجی + محدودیت تلاش)، نود شرط (resolve مقدار + branch jump)، نود n8n (async/sync + ذخیرهٔ پاسخ)
    - [x] نود action: دکمهٔ inline با callback بیلت‌این (بدون re-entry خطرناک در زنجیرهٔ dispatch)
    - [x] رگرسیون: gate در `index.php` فقط با پیشوند `flow*` یا await فعال؛ وقتی `flow_is_active`=false کل شرط short-circuit و رفتار قبلی ربات دست‌نخورده
  - [x] فاز ۸ — Undo/Redo (Ctrl+Z) + UI تاریخچهٔ نسخه‌ها برای rollback  ✅ 2026-06-08
    - [x] Undo/Redo سمت کلاینت: استک snapshot (سقف ۵۰)، pushUndo قبل از هر تغییر معنادار (افزودن/حذف/اتصال/جابه‌جایی پایان درگ/ویرایش فرم/migrate)
    - [x] میان‌برها: Ctrl+Z واگرد، Ctrl+Y و Ctrl+Shift+Z ازنو؛ دکمه‌های ↶/↷ تاپ‌بار با فعال/غیرفعال خودکار
    - [x] مودال «🕓 تاریخچه»: لیست نسخه‌های `flow_history_list` (تاریخ/سازنده/یادداشت/تعداد نود) + دکمهٔ بازگردانی (`?api=restore`)
    - [x] بازگردانی، نسخهٔ فعلی را هم snapshot می‌کند (خودِ restore قابل واگرد است) + ریست استک undo بعد از load/restore
    - [x] تست منطق undo/redo: ۸/۸ پاس؛ `node --check` سالم؛ ناحیهٔ PHP پنل balanced
  - [x] فاز ۹ — محافظت کامل دکمه‌های سیستمی + تأییدیه‌های حذف/ویرایش  ✅ 2026-06-08
    - [x] محافظت نود ریشه به‌صورت سرور-ساید در `flow_normalise_tree` (هر بار `root.system=true` اجبار می‌شود؛ کلاینت نمی‌تواند آن را بردارد) + دکمه‌های legacy عمداً قابل‌ویرایش می‌مانند
    - [x] تأییدیهٔ دومرحله‌ای **ویرایش** نود سیستمی در `savePanel` (قرینهٔ تأیید دومرحله‌ای حذف که از قبل بود)
    - [x] بنر هشدار + قفل نوع نود برای نودهای سیستمی (از قبل) + حفظ پرچم `system` هنگام ویرایش
    - [x] حذف بلوک مخرب `?flowtest=1` از `panel/diag.php` (که درخت زندهٔ ربات را با داده‌ی نمونه overwrite می‌کرد) — صفر فراخوانی `set_button_flow` باقی ماند
    - [x] `node --check` سالم

  > 🎉 استپ ۱۲ (Visual Button Flow) با اتمام فاز ۹ کامل شد — همهٔ ۹ فاز انجام و merge شدند.

  - [x] استپ ۱۲پ — رفع سه ایراد گزارش‌شدهٔ کاربر در ویرایشگر بصری  ✅ 2026-06-08
    - [x] **ایراد ۱ (چیدمان درختی):** الگوریتم چیدمان سلسله‌مراتبی `computeTreeLayout`
      (سبک Reingold–Tilford؛ والد روی فرزندان وسط‌چین، بدون هم‌پوشانی زیردرخت‌ها) در
      `panel/js/flow_editor.js`. اجرای خودکار هنگام `load` و `migrate` وقتی موقعیت‌ها
      صفر/هم‌پوشان‌اند (`positionsNeedLayout`) + دکمهٔ دستی «🌿 مرتب‌سازی درختی» +
      `fitView` خودکار. حالا کل درخت به‌صورت نقشهٔ خوانا دیده می‌شود.
    - [x] **ایراد ۲ (قطع تک‌پیوند):** یال سفارشی `FlowEdge` با دکمهٔ 🗑 روی hover/select
      (`EdgeLabelRenderer`/`getBezierPath`) → `cutEdge` فقط همان یال را حذف می‌کند بدون
      حذف نود؛ زیردرختِ جدا می‌تواند دوباره به والد دیگری وصل شود (مثل n8n/ComfyUI).
    - [x] **ایراد ۳ (اعمال‌نشدن ویرایش/حذف دکمه‌های import‌شده):** ریشه‌یابی شد که دکمهٔ
      «تستس» یک دکمهٔ legacy در `automation_config['buttons']` بود و فلو فقط یک *کپی* از آن
      می‌ساخت. راه‌حل آشتی‌دهنده:
      - migration حالا `legacy_id` را در `config` نود نگه می‌دارد (`flow_normalise_config`
        آن را حفظ می‌کند) و دکمه‌های import‌شده را `flow_managed=true` علامت می‌زند.
      - `flow_sync_legacy_buttons()` در پایان `set_button_flow()`: ویرایش نود → آپدیت دکمهٔ
        legacy؛ حذف نود → حذف دکمهٔ legacy (با تطبیق `legacy_id` و fallback روی label).
      - `keyboard.php`: وقتی فلو فعال است، دکمه‌های `flow_managed` از کیبورد ربات حذف
        می‌شوند تا دوبار (هم reply هم inline) نمایش داده نشوند؛ دکمه‌های import‌نشده
        دست‌نخورده می‌مانند.
      - `panel/keyboard.php`: id دکمه‌ها هنگام ذخیرهٔ مجدد دیگر بازتولید نمی‌شود (فیلد مخفی
        `btn_id` + تطبیق label) تا پیوند با نود فلو نشکند و `flow_managed` حفظ شود.
    - [x] تست‌ها: `php -l` همهٔ فایل‌ها سالم، `node --check` سالم، توازن براکت‌ها OK،
      تست واحد چیدمان (وسط‌چینی والد/عمق/عدم هم‌پوشانی) و تست سینک (آپدیت/حذف/حفظ
      دکمهٔ import‌نشده) همگی PASS.

- [x] استپ ۱۳ — سبد خرید چندقلمی + تعداد + خلاصهٔ سفارش (Multi-item Cart)  ✅ 2026-06-08
  - [x] مدل دادهٔ سبد در ستون اختصاصی `user.shop_cart` (JSON) — کاملاً ایزوله از VPN (`Processing_value` دست‌نخورده)؛ مهاجرت non-destructive در `table.php`
  - [x] توابع سبد در `function.php`: `shop_cart_get/save/clear/add/set_qty/remove/count` + `shop_line_unit_price` (با اختلاف قیمت واریانت) + `shop_cart_resolve` (خوددرمان: حذف ردیف محصول حذف‌شده)
  - [x] نمایش سبد `shop_render_cart`: هر ردیف با دکمه‌های ➖/➕/🗑، جمع کل، تسویه/کد تخفیف/ادامهٔ خرید/خالی‌کردن
  - [x] تسویهٔ چندقلمی `shop_checkout_cart`: چک موجودی همهٔ اقلام، اعمال کد تخفیف روی جمع سبد، کسر یکجای کیف‌پول، ثبت سفارش هر ردیف، کاهش موجودی (variant-aware)، تحویل به‌ازای تعداد، اطلاع ادمین
  - [x] کال‌بک‌ها در `shop_handle_callback` (`cartadd_`/`cartinc_`/`cartdec_`/`cartdel_`/`shopcart`/`cartclear`/`cartcoupon`/`cartcheckout`) + کد تخفیف سطح سبد (`shop_cart_coupon`)
  - [x] دکمهٔ «➕ افزودن به سبد» + «🛒 سبد خرید» در نمای محصول و لیست (با شمارندهٔ تعداد)؛ gate در `index.php` فقط روی الگوهای `cart*`/`shopcart` (رگرسیون VPN امن)
  - [x] تست منطق سبد: ۱۰/۱۰ پاس؛ ناحیهٔ PHP `function.php`/`index.php`/`table.php` balanced

- [x] استپ ۱۴ — گزارش فروش و تخفیف (Sales & Discount Report)  ✅ 2026-06-08
  - [x] صفحهٔ `panel/reports.php` (self-contained، read-only، فقط ردیف‌های فروشگاه `product_id IS NOT NULL` → VPN دست‌نخورده)
  - [x] فیلتر بازهٔ زمانی (امروز/۷ روز/۳۰ روز/کل) با کوئری پارامتری امن (PDO bind)
  - [x] KPIهای فروش: مجموع فروش، تعداد سفارش، میانگین ارزش سفارش، تعداد اقلام (فقط وضعیت‌های درآمدزا paid/processing/shipped/delivered)
  - [x] تفکیک فروش بر اساس وضعیت سفارش + جدول پرفروش‌ترین محصولات (top 10 با درآمد/تعداد)
  - [x] نمودار میله‌ای روند فروش ۱۴ روز اخیر (بدون وابستگی JS، CSS خالص)
  - [x] گزارش تخفیف از `discount_usage`: دفعات استفاده، مجموع تخفیف، پرکاربردترین کدها، کاربران با بیشترین تخفیف
  - [x] آیتم ناوبری «گزارش فروش» در `panel/inc/layout_head.php` (داخل بلوک حالت فروشگاه) + تست منطق تجمیع ۱۱/۱۱ پاس

---

## 🐛 باگ‌های یافته‌شده
- [استپ ۱] خطای CI (نه کد): GitHub Actions در مرحلهٔ Docker Buildx از `registry-1.docker.io`
  با `net/http: request canceled (Client.Timeout)` شکست خورد — یک timeout موقتی شبکه‌ای در
  رانر است، نه باگ کد ما. راه‌حل: Re-run jobs. اگر تکرار شد، در استپ بعدی workflow را مقاوم
  می‌کنیم (افزودن retry / docker.io mirror). فعلاً نیازی به تغییر کد نیست.

## ✅ نکتهٔ معماری ثبت‌شده (استپ ۱)
- کشف شد پروژه از قبل multi-bot است (جدول `botsaz` + روتر `vpnbot/`). بنابراین در استپ ۱
  زیرساخت per-bot اضافه شد: ستون `bot_id` در `botlabels`، پارامتر `$bot_id` در `bot_label()`
  و `get_store_terminology()` با fallback به ربات اصلی (bot_id=0). جزئیات کامل per-bot در
  استپ ۹ پیاده می‌شود.

---

## یادداشت‌های معماری
- مهاجرت‌های DB همیشه non-destructive با `addFieldToTable()` (در `function.php` خط ۱۲۹۹).
- لیبل دکمه‌ها در کیبورد به‌صورت کلید نمادین (مثل `text_sell`) ذخیره می‌شوند و در زمان نمایش رزولو می‌شوند → برای override کافی است لایهٔ رزولور (`bot_label`) را قبل از fallback به فایل زبان قرار دهیم.
- VPN = پیش‌فرض. هیچ مسیر خرید VPN نباید در این فاز بشکند (رگرسیون در هر استپ مرتبط).
