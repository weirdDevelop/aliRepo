<?php /* pages/links.php */ ?>
<div class="page-head"><h1>لینک رهگیری</h1><p>لینک کوتاه بساز، در پیام بگذار، و ببین چه کسی، با چه دستگاهی و از کجا کلیک کرد</p></div>
<div class="row">
  <div class="col card card-pad" style="max-width:400px">
    <div style="font-weight:600;margin-bottom:14px">لینک جدید</div>

    <div class="field"><label>عنوان (اختیاری)</label><input id="lnkTitle" placeholder="مثلاً تخفیف نوروزی"></div>

    <div class="field">
      <label>نوع مقصد</label>
      <select id="lnkKind" onchange="onLnkKindChange()">
        <option value="url">🔗 آدرس وب</option>
        <option value="tel">📞 شماره تماس</option>
      </select>
    </div>

    <div class="field">
      <label id="lnkUrlLabel">آدرس مقصد</label>
      <input id="lnkUrl" class="mono" placeholder="https://example.com/offer" dir="ltr">
      <div class="note" id="lnkUrlHint" style="margin-top:5px;font-size:11.5px">آدرس کامل یا فقط دامنه (خودش https اضافه می‌شود).</div>
    </div>

    <div class="field">
      <label>دسته (اختیاری)</label>
      <input id="lnkCat" list="lnkCatList" placeholder="استوری، ریلز، سایت، …">
      <datalist id="lnkCatList"></datalist>
    </div>

    <button class="btn btn-primary" onclick="createLink()">ساخت لینک کوتاه</button>
    <div id="lnkResult" class="note" style="margin-top:10px"></div>
  </div>

  <div class="col card">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)">
      <input id="lnkQ" placeholder="جستجو در عنوان یا آدرس…" style="flex:1;min-width:150px" oninput="lnkDebounced()">
      <select id="lnkFilterCat" onchange="loadLinks()" style="width:auto">
        <option value="">همه‌ی دسته‌ها</option>
        <option value="__none__">بدون دسته</option>
      </select>
      <select id="lnkFilterKind" onchange="loadLinks()" style="width:auto">
        <option value="">وب و تماس</option>
        <option value="url">فقط وب</option>
        <option value="tel">فقط تماس</option>
      </select>
    </div>
    <table><thead><tr>
      <th>عنوان</th><th>دسته</th><th>لینک کوتاه</th><th>کلیک</th><th>تاریخ ساخت</th><th></th>
    </tr></thead>
    <tbody id="lnkRows"><tr><td colspan="6" class="empty">…</td></tr></tbody></table>
  </div>
</div>

<div id="lnkBackdrop" class="drawer-backdrop" onclick="closeLnk()"></div>
<aside id="lnkDrawer" class="drawer">
  <div class="drawer-head">
    <strong id="lnkDrawerTitle">جزئیات لینک</strong>
    <button class="menu-btn" onclick="closeLnk()" style="width:32px;height:32px">✕</button>
  </div>
  <div class="drawer-body">
    <div id="lnkBody"></div>

    <!-- فرم ویرایش (مورد ۴) — همان drawer، حالت دوم -->
    <div id="lnkEdit" style="display:none">
      <div class="field"><label>عنوان</label><input id="edTitle"></div>
      <div class="field">
        <label>نوع مقصد</label>
        <select id="edKind" onchange="onEdKindChange()">
          <option value="url">🔗 آدرس وب</option>
          <option value="tel">📞 شماره تماس</option>
        </select>
      </div>
      <div class="field">
        <label id="edUrlLabel">آدرس مقصد</label>
        <input id="edUrl" class="mono" dir="ltr">
      </div>
      <div class="field">
        <label>دسته</label>
        <input id="edCat" list="lnkCatList" placeholder="بدون دسته">
      </div>
      <div class="note" style="margin-bottom:12px;font-size:11.5px">
        کد کوتاه عوض نمی‌شود، پس لینک‌هایی که قبلاً فرستادی همچنان کار می‌کنند و به مقصد تازه می‌روند.
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary" onclick="saveLnkEdit()">ذخیره</button>
        <button class="btn btn-ghost" onclick="openLnk(LNK_ID)">لغو</button>
      </div>
      <div id="edResult" class="note" style="margin-top:10px"></div>
    </div>
  </div>
</aside>
<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:440px;max-width:92vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
/* نوار سهم هر مقدار در تفکیک‌ها (مورد ۱۱) */
.bd-row{display:flex;align-items:center;gap:8px;font-size:12px;padding:3px 0}
.bd-name{flex:0 0 40%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bd-bar{flex:1;height:7px;background:var(--bg);border-radius:4px;overflow:hidden}
.bd-fill{height:100%;background:var(--accent);border-radius:4px}
.bd-num{flex:0 0 30px;text-align:left;color:var(--ink-faint);font-size:11px}
</style>
