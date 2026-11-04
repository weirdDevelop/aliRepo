<?php /* pages/dashboard.php */
$pCount = (int)(Database::one("SELECT COUNT(*) c FROM eb_persons")['c'] ?? 0);
$mCount = (int)(Database::one("SELECT COUNT(*) c FROM eb_persons WHERE status='member'")['c'] ?? 0);
$identCount = (int)(Database::one("SELECT COUNT(*) c FROM eb_identities")['c'] ?? 0);
$sentCount = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE direction='out' AND status='sent'")['c'] ?? 0);
$inCount = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE direction='in'")['c'] ?? 0);
?>
<div class="page-head">
  <h1>سلام <?= htmlspecialchars(Auth::name()) ?> 👋</h1>
  <p>نمای کلی سیستم</p>
</div>

<!-- چک‌لیست راه‌اندازی (با JS لود می‌شود، اگر کامل شد پنهان) -->
<div id="setupCard" class="card card-pad" style="margin-bottom:16px;display:none">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
    <div style="font-weight:600">راه‌اندازی اعلان‌بان</div>
    <span class="muted" id="setupProgress" style="font-size:12.5px"></span>
  </div>
  <div style="height:6px;background:var(--line-soft);border-radius:10px;overflow:hidden;margin-bottom:16px">
    <div id="setupBar" style="height:100%;background:linear-gradient(90deg,var(--brand),var(--brand-2));width:0;transition:width .3s"></div>
  </div>
  <div id="setupSteps"></div>
</div>

<!-- آمار سریع -->
<div class="grid g-4" style="margin-bottom:16px">
  <div class="stat">
    <div class="lab">👥 کل مشتریان</div>
    <div class="num"><?= number_format($pCount) ?></div>
    <div class="note"><?= number_format($mCount) ?> عضو · <?= number_format($pCount-$mCount) ?> ناشناس</div>
  </div>
  <div class="stat">
    <div class="lab">📨 پیام ارسالی</div>
    <div class="num"><?= number_format($sentCount) ?></div>
    <div class="note"><?= number_format($inCount) ?> پیام دریافتی</div>
  </div>
  <div class="stat">
    <div class="lab">🔗 هویت‌ها</div>
    <div class="num"><?= number_format($identCount) ?></div>
    <div class="note">راه‌های ارتباطی ثبت‌شده</div>
  </div>
  <div class="stat">
    <div class="lab">✅ نرخ عضویت</div>
    <div class="num"><?= $pCount>0 ? round($mCount*100/$pCount) : 0 ?>٪</div>
    <div class="note">مشتریانی که شماره دارند</div>
  </div>
</div>

<!-- فعالیت اخیر -->
<div class="card" style="margin-top:16px">
  <div class="card-pad" style="font-weight:600;border-bottom:1px solid var(--line)">آخرین فعالیت</div>
  <div id="recentActivity"><div class="empty">در حال بارگذاری…</div></div>
</div>
