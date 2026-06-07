# GitHub Actions workflow (manual activation)

به‌دلایل امنیتی، این فایل workflow اینجا (خارج از `.github/workflows`) قرار داده
شده است. برای فعال کردن build خودکار image روی GHCR، فقط یک مرحله‌ی ساده لازم است:

```bash
mkdir -p .github/workflows
cp deploy/github-workflow/docker-image.yml .github/workflows/docker-image.yml
git add .github/workflows/docker-image.yml
git commit -m "ci: enable GHCR docker image build"
git push
```

> این کار را باید با حساب گیت‌هاب خودتان (نه از طریق یک App محدود) انجام دهید،
> چون افزودن فایل به `.github/workflows` نیاز به دسترسی `workflows` دارد.

بعد از push:
- به تب **Actions** بروید؛ workflow «Build & Push Docker Image» اجرا می‌شود.
- image در `ghcr.io/<owner>/<repo>:latest` منتشر می‌شود.
- اگر می‌خواهید Coolify بدون لاگین pull کند، پکیج را در
  GitHub → **Packages** → Package settings → **Public** کنید.
