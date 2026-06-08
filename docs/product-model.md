# مدل عمومی محصول (Generic Product Model)

این سند مدل محصول گسترش‌یافته را توضیح می‌دهد که ربات را از «فقط VPN» به یک فروشگاه
عمومی چند-نوعی تبدیل می‌کند. همه تغییرات **non-destructive** هستند: محصولات VPN موجود
دست‌نخورده می‌مانند و به‌صورت پیش‌فرض `product_type = 'vpn'` می‌گیرند.

## ستون‌های جدید جدول `product`

| ستون           | نوع                    | پیش‌فرض | توضیح                                                |
|----------------|------------------------|---------|------------------------------------------------------|
| `product_type` | `varchar(40)`          | `vpn`   | نوع محصول (یکی از کلیدهای `product_types()`)          |
| `attributes`   | `JSON`                 | `NULL`  | فیلدهای مخصوص هر نوع (به‌جز VPN که ستون اختصاصی دارد) |
| `bot_id`       | `INT`                  | `0`     | scope چند-باته (۰ = ربات اصلی، >۰ = `botsaz.id`)     |

> فیلدهای VPN (`Volume_constraint`, `Location`, `inbounds`, `proxies`, ...) همان ستون‌های
> اختصاصی قبلی باقی می‌مانند و فقط برای `product_type = 'vpn'` معنا دارند.

## انواع محصول (`product_types()` در `function.php`)

| کد           | برچسب                | فیلدهای attributes                                  |
|--------------|----------------------|-----------------------------------------------------|
| `vpn`        | سرویس VPN (پیش‌فرض)  | — (از ستون‌های اختصاصی)                              |
| `physical`   | کالای فیزیکی         | `stock`, `weight`, `needs_address`                  |
| `digital_file`| فایل دیجیتال         | `file_id`, `file_type`, `caption`                   |
| `serial_code`| کد/سریال (لایسنس)    | `code_format`                                       |
| `service`    | خدمت/سرویس عمومی     | `delivery_note`                                     |

هر فیلد یک ساختار دارد: `['key','label','type','hint']` با
`type ∈ {text, number, textarea, bool}` — که در استپ ۵ برای ساخت **فرم داینامیک محصول**
در پنل استفاده می‌شود.

## توابع کمکی (`function.php`)

- `product_types()` — رجیستری انواع و فیلدهایشان.
- `is_valid_product_type($type)` — اعتبارسنجی کد نوع.
- `product_attributes($rowOrJson)` — decode امن JSON به آرایه.
- `product_attr($rowOrJson, $key, $default)` — خواندن یک attribute.
- `set_product_type($product_id, $type, $attributes)` — ذخیرهٔ نوع + attributes
  (فقط فیلدهای مجاز همان نوع نگه داشته می‌شوند).

## سازگاری

- محصولات قدیمی → `product_type = 'vpn'`، `attributes = NULL` → رفتار قبلی بدون تغییر.
- `serial_code` در استپ ۶ به جدول `product_codes` متصل می‌شود.
- `digital_file` از `file_id` تلگرام استفاده می‌کند (بدون نیاز به ذخیره‌سازی فایل).
