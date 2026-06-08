<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Product media manager (Step 4).
 * Upload / list / delete images, videos and audio for a single product.
 * Files are stored under /uploads/products and served as static media.
 */

$pid = (int) ($_GET['pid'] ?? ($_POST['pid'] ?? 0));
$product = $pid ? db_fetch($pdo, "SELECT * FROM product WHERE id = ?", [$pid]) : null;
if (!$product) {
    flash('error', 'محصول یافت نشد.');
    header('Location: product.php');
    exit;
}

$uploadDirAbs = dirname(__DIR__) . '/uploads/products';
$relPrefix    = 'uploads/products'; // relative to project root, stored in DB

// --- Handle delete ---
if (isset($_GET['delete'])) {
    csrf_check_get();
    product_media_delete((int) $_GET['delete']);
    flash('success', 'رسانه حذف شد.');
    header('Location: product_media.php?pid=' . $pid);
    exit;
}

// --- Handle upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrf_check_post();

    if (empty($_FILES['media']) || !is_array($_FILES['media']['name'])) {
        flash('error', 'فایلی انتخاب نشده است.');
        header('Location: product_media.php?pid=' . $pid);
        exit;
    }

    if (!is_dir($uploadDirAbs)) {
        @mkdir($uploadDirAbs, 0755, true);
    }

    $maxBytes = 25 * 1024 * 1024; // 25 MB per file
    $okCount = 0;
    $errCount = 0;
    $names = $_FILES['media']['name'];

    for ($i = 0; $i < count($names); $i++) {
        if (($_FILES['media']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp  = $_FILES['media']['tmp_name'][$i];
        $size = (int) ($_FILES['media']['size'][$i] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $errCount++;
            continue;
        }
        $detect = product_media_detect($tmp, $names[$i]);
        if ($detect === null) {
            $errCount++;
            continue; // disallowed type
        }
        [$mediaType, $ext] = $detect;
        $fname = $pid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest  = $uploadDirAbs . '/' . $fname;
        if (@move_uploaded_file($tmp, $dest)) {
            @chmod($dest, 0644);
            product_media_add($pid, $relPrefix . '/' . $fname, $mediaType);
            $okCount++;
        } else {
            $errCount++;
        }
    }

    if ($okCount > 0) {
        flash('success', $okCount . ' فایل با موفقیت آپلود شد.' . ($errCount ? ' (' . $errCount . ' فایل ناموفق)' : ''));
    } else {
        flash('error', 'هیچ فایلی آپلود نشد. فقط تصویر/ویدیو/صوت تا ۲۵ مگابایت مجاز است.');
    }
    header('Location: product_media.php?pid=' . $pid);
    exit;
}

$media = product_media_list($pid);

$flashOk = get_flash('success');
$flashErr = get_flash('error');

$pageTitle = 'رسانهٔ محصول';
$activeNav = 'product';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
    <div>
        <div style="font-size:1rem;font-weight:800;color:var(--text)"><?= htmlspecialchars($product['name_product'] ?? '') ?></div>
        <div style="font-size:.8rem;color:var(--mute)">مدیریت تصویر، ویدیو و صوت این محصول</div>
    </div>
    <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> بازگشت به محصولات</a>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="card fade-up d1" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">آپلود رسانه</div>
            <div class="card-subtitle">تصویر (jpg/png/webp/gif)، ویدیو (mp4/webm)، صوت (mp3/ogg/wav) — حداکثر ۲۵ مگابایت</div>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" action="product_media.php?pid=<?= $pid ?>">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="pid" value="<?= $pid ?>">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <input type="file" name="media[]" multiple
                    accept="image/*,video/mp4,video/webm,audio/mpeg,audio/ogg,audio/wav"
                    class="input" style="flex:1;min-width:220px;padding:8px">
                <button type="submit" class="btn btn-primary"><?= icon('plus', 14) ?> آپلود</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-up d2">
    <div class="card-head">
        <div class="card-title">رسانه‌های موجود <small style="color:var(--dim);font-weight:400">(<?= count($media) ?>)</small></div>
    </div>
    <div class="card-body">
        <?php if (empty($media)): ?>
            <div style="color:var(--mute);text-align:center;padding:24px">هنوز رسانه‌ای اضافه نشده است.</div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px">
                <?php foreach ($media as $m):
                    $src = '../' . htmlspecialchars($m['file_path']);
                    $type = $m['media_type'];
                    ?>
                    <div style="border:1px solid var(--bd);border-radius:10px;overflow:hidden;background:var(--sf2)">
                        <div style="height:130px;display:flex;align-items:center;justify-content:center;background:#0003">
                            <?php if ($type === 'image'): ?>
                                <img src="<?= $src ?>" alt="" style="max-width:100%;max-height:130px;object-fit:contain">
                            <?php elseif ($type === 'video'): ?>
                                <video src="<?= $src ?>" controls style="max-width:100%;max-height:130px"></video>
                            <?php elseif ($type === 'audio'): ?>
                                <div style="padding:10px;width:100%"><audio src="<?= $src ?>" controls style="width:100%"></audio></div>
                            <?php else: ?>
                                <?= icon('package', 36) ?>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px">
                            <span class="tag tag-info" style="font-size:.68rem"><?= htmlspecialchars($type) ?></span>
                            <a href="product_media.php?pid=<?= $pid ?>&delete=<?= (int) $m['id'] ?>&_csrf=<?= csrf_token() ?>"
                                class="btn btn-no btn-sm btn-icon" title="حذف"
                                data-confirm="این رسانه حذف شود؟"><?= icon('trash', 13) ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
