<?php /* pages/profile.php */ ?>
<div class="page-head"><h1>پروفایل من</h1><p>اطلاعات حساب و تغییر رمز عبور</p></div>
<div class="row">
  <div class="col card card-pad" style="max-width:420px">
    <div style="font-weight:600;margin-bottom:14px">اطلاعات حساب</div>
    <div id="profInfo"><div class="empty">…</div></div>
  </div>
  <div class="col card card-pad" style="max-width:420px">
    <div style="font-weight:600;margin-bottom:14px">تغییر نام کاربری</div>
    <div class="field"><label>نام کاربری جدید</label><input id="pfUsername"></div>
    <button class="btn btn-ghost btn-sm" onclick="changeUsername()">تغییر نام کاربری</button>
    <div class="sep" style="margin:18px 0"></div>
    <div style="font-weight:600;margin-bottom:14px">تغییر رمز عبور</div>
    <div class="field"><label>رمز فعلی</label><input id="pfCur" type="password"></div>
    <div class="field"><label>رمز جدید</label><input id="pfNew" type="password" placeholder="حداقل ۴ کاراکتر"></div>
    <button class="btn btn-primary btn-sm" onclick="changePassword()">تغییر رمز</button>
    <div id="pfMsg" class="note" style="margin-top:8px"></div>
  </div>
</div>
