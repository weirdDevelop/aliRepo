<?php
require __DIR__ . '/_auth.php';
auth_open('ورود', 'همه‌ی گفتگوهای مشتری، در یک پنل.', 'دایرکت اینستاگرام، تلگرام، بله، پیامک و گفتگوی سایت را از یک صندوق ورودی جواب بدهید.');
?>
    <h1>ورود به پنل</h1>
    <div class="sub">با نام کاربری و رمزی که مدیر برایتان ساخته وارد شوید.</div>
    <form method="post" action="index.php?page=login" novalidate>
      <?= Csrf::field() ?>
      <div class="field"><label for="u">نام کاربری</label><input class="inp ltr" id="u" name="username" autocomplete="username" autofocus required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
      <div class="field"><label for="p">رمز عبور</label><input class="inp ltr" id="p" name="password" type="password" autocomplete="current-password" required></div>
      <?php if (!empty($loginError)): ?>
        <div class="callout err" role="alert"><svg class="ic"><use href="#i-warning-circle"></use></svg><span><?= htmlspecialchars($loginError) ?></span></div>
      <?php endif; ?>
      <button class="btn btn-p" type="submit">ورود</button>
    </form>
<?php auth_close([['tray', 'صندوق ورودی مشترک با تخصیص و SLA'], ['instagram-logo', 'اینستاگرام چندپیجی با اتوماسیون کامنت'], ['sparkle', 'دستیار هوش مصنوعی برای پاسخ و پیش‌نویس'], ['hard-drives', 'نصب روی سرور خودتان؛ داده‌ها پیش خودتان می‌ماند']]); ?>
