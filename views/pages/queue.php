<?php /* pages/queue.php — صف ارسال انبوه */ ?>
<div class="page-head">
  <h1>صف ارسال</h1>
  <p>کارهای ارسال انبوه در صف قرار می‌گیرند و در پس‌زمینه (با cron یا همین صفحه) تکمیل می‌شوند</p>
</div>

<div id="qSummary" class="card card-pad" style="margin-bottom:16px;display:none">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="tag tag-accent" id="qActiveJobs">۰ کار فعال</span>
    <span class="tag tag-mute" id="qPending">۰ در انتظار</span>
    <span id="qPushState" class="muted" style="font-size:12px"></span>
  </div>
</div>

<div class="card">
  <table class="tbl">
    <thead><tr>
      <th>عنوان</th><th>وضعیت</th><th>پیشرفت</th><th>نتیجه</th><th>زمان</th><th></th>
    </tr></thead>
    <tbody id="qRows"><tr><td colspan="6" class="empty">در حال بارگذاری…</td></tr></tbody>
  </table>
</div>
