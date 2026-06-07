# 🚀 Deploy Mirza Bot on Coolify

این پروژه برای دیپلوی روی **Coolify** آماده شده است. کل فرآیند خودکار است و
شما فقط باید **۴ متغیر** را دستی وارد کنید.

This project is container-ready for **Coolify**. Everything is automated and
you only have to enter **4 variables** manually.

---

## 🔑 متغیرهای دستی (Required env vars)

| متغیر | توضیح | مثال |
|-------|-------|------|
| `BOT_DOMAIN` | دامنه سرور (بدون `https://` و بدون `/`) | `bot.example.com` |
| `BOT_TOKEN` | توکن ربات تلگرام (از `@BotFather`) | `123456:ABC-DEF...` |
| `ADMIN_CHAT_ID` | آیدی عددی ادمین (از `@userinfobot`) | `123456789` |
| `BOT_USERNAME` | یوزرنیم ربات (بدون `@`) | `MyVpnBot` |

> بقیه چیزها (دیتابیس، ساخت جداول، تنظیم webhook، کران‌جاب‌ها) **خودکار** انجام می‌شود.

اختیاری (پیشنهاد می‌شود برای امنیت، رمز دیتابیس را عوض کنید):

| متغیر | پیش‌فرض |
|-------|---------|
| `DB_NAME` | `mirzabot` |
| `DB_USER` | `mirzabot` |
| `DB_PASSWORD` | `mirzabot` |
| `DB_ROOT_PASSWORD` | `mirzabot_root` |

---

## 📦 مراحل دیپلوی روی Coolify

1. **New Resource → Docker Compose** و این ریپازیتوری گیت را انتخاب کنید.
   - Coolify به طور خودکار `docker-compose.yml` را تشخیص می‌دهد.

2. وارد بخش **Environment Variables** شوید و ۴ متغیر بالا را وارد کنید:
   ```env
   BOT_DOMAIN=bot.example.com
   BOT_TOKEN=123456789:ABCdef...
   ADMIN_CHAT_ID=123456789
   BOT_USERNAME=MyVpnBot
   ```
   (و در صورت تمایل رمزهای دیتابیس را هم تغییر دهید)

3. در تنظیمات سرویس **`app`**، دامنه را روی `https://bot.example.com`
   تنظیم کنید (همان مقدار `BOT_DOMAIN`). Coolify به‌صورت خودکار SSL/Let's Encrypt
   را با Traefik فعال می‌کند. کانتینر داخلی روی پورت `80` گوش می‌دهد.

4. **Deploy** را بزنید.

به محض بالا آمدن:
- دیتابیس MariaDB ساخته و آماده می‌شود.
- فایل `config.php` به‌صورت خودکار از روی متغیرها ساخته می‌شود.
- `table.php` فراخوانی شده و تمام جداول ساخته می‌شوند.
- webhook تلگرام روی `https://$BOT_DOMAIN/index.php` تنظیم می‌شود.
- کران‌جاب‌ها داخل کانتینر فعال می‌شوند.
- یک پیام «✅ Mirza bot is deployed...» برای ادمین ارسال می‌شود.

سپس در تلگرام به ربات `/start` بدهید. 🎉

---

## 🩺 Healthcheck

- اپ: `GET /health.php` → پاسخ `ok` (بدون نیاز به دیتابیس).
- دیتابیس: healthcheck داخلی MariaDB.

---

## 🛠️ تست محلی (Local test)

```bash
cp .env.example .env      # مقادیر را پر کنید
docker compose up --build
```

> برای تست محلی، تلگرام به webhook با HTTPS و دامنه‌ی عمومی نیاز دارد؛
> پس روی `localhost` صرفاً برای بررسی بالا آمدن سرویس‌ها مناسب است.

---

## ❓ نکات

- اگر بعداً توکن یا دامنه را عوض کردید، فقط متغیر را در Coolify تغییر داده و
  سرویس را **Restart/Redeploy** کنید؛ `config.php` و webhook دوباره ساخته می‌شوند.
- دیتابیس روی volume به نام `mirza_db` ذخیره می‌شود و با ری‌دیپلوی پاک نمی‌شود.
- اگر می‌خواهید دیتابیس خارجی (managed) استفاده کنید، سرویس `mariadb` را حذف کرده
  و متغیرهای `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` را تنظیم کنید.
