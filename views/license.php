<?php
/** views/license.php — فعال‌سازی لایسنس راست‌چین (قبل از ورود به پنل) */
require __DIR__ . '/_auth.php';
$st = License::status();
auth_open('فعال‌سازی لایسنس', 'یک قدم تا شروع کار.', 'اعلان‌بان را با نام کاربری راست‌چین و کد سفارش خریدتان روی همین دامنه فعال کنید. فعال‌سازی یک‌بار انجام می‌شود.');
?>
    <?php if (!Auth::isAdmin()): ?>
      <h1>پنل هنوز فعال نشده</h1>
      <div class="sub">فعال‌سازی لایسنس فقط با حساب مدیر ممکن است. از مدیر پنل بخواهید وارد شود و لایسنس را فعال کند.</div>
      <a class="btn" href="index.php?page=logout">خروج</a>
    <?php else: ?>
      <h1>فعال‌سازی لایسنس</h1>
      <div class="sub">اطلاعات خرید از <?= License::MARKET ?> را وارد کنید. کد سفارش در «پروفایل › سفارش‌ها»ی راست‌چین دیده می‌شود.</div>
      <?php if ($st['mismatch']): ?>
        <div class="callout warn" style="margin-bottom:14px"><svg class="ic"><use href="#i-warning"></use></svg><span>لایسنس فعلی برای دامنه‌ی <b class="ltr"><?= htmlspecialchars($st['licensed_domain']) ?></b> صادر شده و با این دامنه هم‌خوانی ندارد. برای انتقال، کد بازیابی بگیرید.</span></div>
      <?php endif; ?>
      <form method="post" action="index.php?page=license" novalidate>
        <?= Csrf::field() ?>
        <div><span class="domain-chip"><svg class="ic"><use href="#i-globe-simple"></use></svg>دامنه: <b class="ltr"><?= htmlspecialchars($st['domain']) ?></b></span></div>
        <div class="field"><label for="ru">نام کاربری راست‌چین</label><input class="inp ltr" id="ru" name="username" autocomplete="off" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
        <div class="field"><label for="ro">کد سفارش</label><input class="inp ltr" id="ro" name="order_id" inputmode="numeric" autocomplete="off" required value="<?= htmlspecialchars($_POST['order_id'] ?? '') ?>"></div>
        <details <?= !empty($_POST['recovery']) ? 'open' : '' ?>><summary class="small" style="cursor:pointer;color:var(--accent-ink)">کد بازیابی دارم (نصب دوباره یا انتقال دامنه)</summary>
          <div class="field" style="margin-top:10px"><label for="rc">کد بازیابی</label><input class="inp ltr mono" id="rc" name="recovery" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off" value="<?= htmlspecialchars($_POST['recovery'] ?? '') ?>"><span class="hint">از پشتیبانی اعلان‌بان بگیرید؛ برای همین دامنه و کد سفارش صادر می‌شود.</span></div>
        </details>
        <?php if (!empty($licenseError)): ?>
          <div class="callout err" role="alert"><svg class="ic"><use href="#i-warning-circle"></use></svg><span><?= htmlspecialchars($licenseError) ?></span></div>
        <?php endif; ?>
        <button class="btn btn-p" type="submit">فعال‌سازی</button>
        <a class="btn btn-g" href="index.php?page=logout">خروج</a>
      </form>
    <?php endif; ?>
<?php auth_close([['seal-check', 'فعال‌سازی یک‌باره روی دامنه‌ی شما'], ['download-simple', 'به‌روزرسانی رایگان در دوره‌ی پشتیبانی'], ['lock-key', 'اطلاعات خرید فقط با راست‌چین بررسی می‌شود']]); ?>
