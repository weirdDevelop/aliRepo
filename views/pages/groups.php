<?php /* pages/groups.php */ ?>
<div class="page-head"><h1>گروه و برچسب</h1><p>دسته‌بندی مشتریان برای ارسال هدفمند</p></div>
<div class="row">
  <div class="col card card-pad" style="max-width:340px">
    <div style="font-weight:600;margin-bottom:14px">گروه جدید</div>
    <div class="field"><label>نام گروه</label><input id="grpName"></div>
    <div class="field"><label>رنگ</label><input id="grpColor" type="color" value="#2563eb" style="height:40px"></div>
    <button class="btn btn-primary btn-sm" onclick="createGrp()">ایجاد گروه</button>
    <div class="sep"></div>
    <div style="font-weight:600;margin-bottom:14px">برچسب جدید</div>
    <div class="field"><label>نام برچسب</label><input id="tagName"></div>
    <button class="btn btn-primary btn-sm" onclick="createTag()">ایجاد برچسب</button>
  </div>
  <div class="col">
    <div class="card" style="margin-bottom:16px">
      <div class="card-pad" style="font-weight:600;border-bottom:1px solid var(--line)">گروه‌ها</div>
      <table><tbody id="grpRows"><tr><td class="empty">…</td></tr></tbody></table>
    </div>
    <div class="card">
      <div class="card-pad" style="font-weight:600;border-bottom:1px solid var(--line)">برچسب‌ها</div>
      <table><tbody id="tagRows"><tr><td class="empty">…</td></tr></tbody></table>
    </div>
  </div>
</div>
