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

### 🧩 نقش پنل (Multi-Container)

برای اجرای **چند نقش روی چند کانتینر** (مثلاً یک کانتینر VPN، یکی آنلاین‌شاپ، یکی کانال)،
به هر کانتینر متغیر `PANEL_MODE` بدهید. هر کانتینر همین پروژه است با ربات/دامنهٔ جداگانه.

| متغیر | مقادیر مجاز | پیش‌فرض | توضیح |
|-------|-------------|---------|-------|
| `PANEL_MODE` | `vpn` \| `shop` \| `digital` \| `channel` \| `custom` | `vpn` | نقش این کانتینر |

> اگر `PANEL_MODE` ست شود، تغییر پروفایل از داخل پنل قفل می‌شود (تک‌منبع حقیقت = ENV).
> اگر ست نشود، رفتار پیش‌فرض `vpn` است و چیزی تغییر نمی‌کند.
> جزئیات کامل در [`docs/store-guide.md`](docs/store-guide.md).

> ✅ **زنجیرهٔ تحویل (مهم):** `PANEL_MODE` حالا در هر دو فایل compose
> (`docker-compose.yml` و `docker-compose.image.yml`) لیست شده و در
> `docker/entrypoint.sh` اعتبارسنجی و داخل `config.php` تزریق می‌شود
> (`putenv`). یعنی مقداری که در Coolify ست می‌کنید **تضمینی** به ربات،
> پنل وب و کرون‌جاب‌ها می‌رسد. در نسخه‌های قبلی این متغیر در compose لیست
> نشده بود و در عمل هرگز به کانتینر نمی‌رسید (ربات همیشه vpn می‌ماند).
> بعد از تغییر `PANEL_MODE` حتماً **Redeploy** بزنید تا config.php بازسازی شود.

---

## 🚄 روش پیشنهادی: نصب با Docker Image (سریع‌ترین)

اگر می‌خواهی موقع اضافه کردن پروژه‌ی جدید روی Coolify، **بدون build** و فقط با
**pull کردن یک image آماده** نصب کنی، این روش بهترینه.

### مرحله ۱ — یک‌بار image را بساز (خودکار)
ابتدا workflow را فعال کنید (یک‌بار، از حساب گیت‌هاب خودتان):

```bash
mkdir -p .github/workflows
cp deploy/github-workflow/docker-image.yml .github/workflows/docker-image.yml
git add .github/workflows/docker-image.yml && git commit -m "ci: enable GHCR build" && git push
```

> چرا دستی؟ افزودن فایل به `.github/workflows` نیاز به دسترسی `workflows` دارد که
> از طریق برخی App‌ها مسدود است. جزئیات در `deploy/github-workflow/README.md`.

سپس به محض push شدن کد روی `main` (یا اجرای دستی از تب Actions در گیت‌هاب)،
این ورک‌فلو به‌صورت خودکار image را build کرده و روی **GHCR** منتشر می‌کند:

```
ghcr.io/<owner>/<repo>:latest
# مثال:
ghcr.io/saeedkhoshafsar/mirzabot:latest
```

> 💡 برای اینکه image **public** شود (تا Coolify بدون لاگین pull کند)، یک‌بار به
> GitHub → repo → **Packages** → پکیج → **Package settings** → *Change visibility*
> → **Public** بروید. (یا در Coolify یک Registry credential با توکن GHCR بسازید.)

### مرحله ۲ — در Coolify
دو حالت دارید:

**حالت A — Docker Image (تک‌کانتینر، دیتابیس جدا):**
1. **New Resource → Docker Image** و این تگ را وارد کنید:
   `ghcr.io/saeedkhoshafsar/mirzabot:latest`
2. متغیرها را ست کنید: ۴ متغیر اصلی + `DB_HOST/DB_NAME/DB_USER/DB_PASSWORD`
   (به یک دیتابیس MySQL/MariaDB که جداگانه در Coolify ساخته‌اید اشاره کند).
3. پورت `80` را expose و دامنه را روی `https://$BOT_DOMAIN` ست کنید.

**حالت B — Docker Compose با image (پیشنهادی، دیتابیس همراه):**
1. **New Resource → Docker Compose** و فایل **`docker-compose.image.yml`** را انتخاب کنید.
2. متغیر `MIRZA_IMAGE` را روی image خودتان ست کنید (پیش‌فرض همین ریپو است).
3. ۴ متغیر اصلی را وارد کنید و دامنه‌ی سرویس `app` را تنظیم کنید.
4. **Deploy**. (دیتابیس MariaDB هم خودکار همراهش بالا می‌آید.)

> این روش (B) راحت‌ترین حالت است: فقط image را pull می‌کند و دیتابیس را هم
> خودش می‌سازد. هیچ build داخل Coolify انجام نمی‌شود.

---

## 📦 روش دوم: Build از روی سورس (Docker Compose)

مراحل دیپلوی روی Coolify

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
