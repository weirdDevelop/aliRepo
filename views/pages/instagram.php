<?php /* pages/instagram.php — بازطراحی کامل: لیست پیج‌ها ← فضای کاری جدا برای هر پیج + تنظیمات مشترک + ابزارها + اتصال و عیب‌یابی */ ?>
<style>
/* ===== همه چیز زیر .igx اسکوپ شده؛ روی صفحه‌های دیگر اثری ندارد ===== */
.igx{--igx-pc:var(--brand)}
.igx *{box-sizing:border-box}

/* ---- سربرگ ---- */
.igx-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.igx-head-ico{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;
  background:linear-gradient(135deg,#f58529,#dd2a7b 55%,#8134af);color:#fff;box-shadow:0 6px 16px -8px rgba(221,42,123,.6)}
.igx-head-txt{flex:1;min-width:180px}
.igx-head-txt h1{font-size:18px;font-weight:800;margin:0 0 2px}
.igx-head-txt p{font-size:12.5px;color:var(--ink-soft);margin:0}
.igx-conn-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:20px;border:1px solid var(--line);background:var(--surface);
  font-size:12px;font-weight:600;cursor:pointer;color:var(--ink);font-family:inherit}
.igx-conn-pill:hover{border-color:var(--accent)}
.igx-dot{width:8px;height:8px;border-radius:50%;background:var(--ink-faint);flex-shrink:0}
.igx-dot.ok{background:var(--ok);box-shadow:0 0 0 3px var(--ok-soft)}
.igx-dot.warn{background:var(--warn);box-shadow:0 0 0 3px var(--warn-soft)}
.igx-dot.err{background:var(--err);box-shadow:0 0 0 3px var(--err-soft)}

/* ---- ناوبری اصلی ---- */
.igx-topnav{display:flex;gap:4px;padding:4px;background:var(--surface);border:1px solid var(--line);border-radius:13px;margin-bottom:18px;overflow-x:auto;scrollbar-width:none}
.igx-topnav::-webkit-scrollbar{display:none}
.igx-topnav button{flex:1;min-width:max-content;display:flex;align-items:center;justify-content:center;gap:7px;border:0;background:transparent;color:var(--ink-soft);
  font-family:inherit;font-size:13px;font-weight:700;padding:10px 14px;border-radius:10px;cursor:pointer;transition:.15s}
.igx-topnav button:hover{color:var(--ink);background:var(--surface-2)}
.igx-topnav button.on{background:var(--brand-soft);color:var(--accent-ink)}

.igx-view{display:none}
.igx-view.on{display:block;animation:igxIn .2s ease}
@keyframes igxIn{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}

/* ---- کارت پیج‌ها (صفحه‌ی اصلی) ---- */
.igx-strip{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;border-radius:var(--radius);background:var(--surface);border:1px solid var(--line);margin-bottom:16px;font-size:12.5px}
.igx-strip .grow{flex:1;min-width:200px;color:var(--ink-soft);line-height:1.8}
.igx-strip b{color:var(--ink)}
.igx-warn-list{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
.igx-warn{display:flex;gap:10px;align-items:center;padding:10px 14px;border-radius:var(--radius-sm);background:var(--warn-soft);color:var(--warn);font-size:12.5px;font-weight:600}
.igx-warn .grow{flex:1}
.igx-warn.err{background:var(--err-soft);color:var(--err)}

.igx-pages{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
.igx-pcard{position:relative;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:18px;cursor:pointer;
  transition:border-color .15s,box-shadow .15s,transform .15s;overflow:hidden;text-align:start;font-family:inherit;color:var(--ink);width:100%}
.igx-pcard::before{content:"";position:absolute;inset:0 0 auto 0;height:4px;background:var(--pc)}
.igx-pcard:hover{border-color:var(--pc);box-shadow:var(--shadow);transform:translateY(-1px)}
.igx-pcard.off{opacity:.62}
.igx-pc-top{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.igx-av{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;color:#fff;background:var(--pc);flex-shrink:0}
.igx-av.sm{width:32px;height:32px;font-size:13px}
.igx-pc-name{font-weight:800;font-size:14.5px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.igx-pc-user{font-size:11.5px;color:var(--ink-faint);direction:ltr;text-align:right}
.igx-pc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:12px}
.igx-pc-stat{background:var(--surface-2);border-radius:10px;padding:8px 4px;text-align:center}
.igx-pc-stat b{display:block;font-size:15px;font-weight:800}
.igx-pc-stat span{display:block;font-size:10px;color:var(--ink-faint);margin-top:1px}
.igx-pc-foot{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:11.5px}
.igx-pc-foot .go{margin-inline-start:auto;color:var(--accent-ink);font-weight:700}

/* ---- چیدمان دوستونه: منوی کناری + محتوا ---- */
.igx-split{display:grid;grid-template-columns:230px minmax(0,1fr);gap:18px;align-items:start}
.igx-side{position:sticky;top:12px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:8px}
.igx-side-group{font-size:10.5px;font-weight:800;color:var(--ink-faint);padding:10px 10px 4px;letter-spacing:.02em}
.igx-side button{display:flex;align-items:center;gap:9px;width:100%;border:0;background:transparent;color:var(--ink-soft);font-family:inherit;font-size:13px;font-weight:600;
  padding:9px 10px;border-radius:9px;cursor:pointer;text-align:start;transition:.12s}
.igx-side button:hover{background:var(--surface-2);color:var(--ink)}
.igx-side button.on{background:var(--brand-soft);color:var(--accent-ink);font-weight:700}
.igx-side button .cnt{margin-inline-start:auto;font-size:10.5px;background:var(--line-soft);color:var(--ink-soft);padding:1px 7px;border-radius:10px}
.igx-side button.on .cnt{background:var(--surface);color:var(--accent-ink)}
.igx-side .ic{width:20px;text-align:center;flex-shrink:0}

.igx-sec{display:none}
.igx-sec.on{display:block;animation:igxIn .18s ease}
.igx-sec-head{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.igx-sec-head .t{flex:1;min-width:200px}
.igx-sec-head h2{font-size:16px;font-weight:800;margin:0 0 3px}
.igx-sec-head p{font-size:12.5px;color:var(--ink-soft);margin:0;line-height:1.75}
.igx-sec-actions{display:flex;gap:6px;flex-wrap:wrap}

/* ---- سربرگ فضای کاری پیج ---- */
.igx-ph{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:12px 14px;margin-bottom:16px;border-inline-start:4px solid var(--pc)}
.igx-ph .back{border:1px solid var(--line);background:var(--surface-2);border-radius:9px;height:32px;padding:0 10px;cursor:pointer;font-family:inherit;font-size:12px;color:var(--ink-soft);font-weight:600}
.igx-ph .back:hover{color:var(--ink);border-color:var(--accent)}
.igx-ph .nm{flex:1;min-width:160px}
.igx-ph .nm b{display:block;font-size:15px;font-weight:800}
.igx-ph .nm span{font-size:11.5px;color:var(--ink-faint)}
.igx-ph select{width:auto;min-width:170px;height:34px;padding:0 10px;font-size:12.5px}

/* ---- بلوک‌ها ---- */
.igx-box{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:14px}
.igx-box-h{display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap}
.igx-box-h h3{font-size:14px;font-weight:800;margin:0;flex:1;min-width:150px}
.igx-box-d{font-size:12.5px;color:var(--ink-soft);line-height:1.75;margin:0 0 14px}
.igx-box.danger{border-color:var(--err-soft)}
.igx-box.danger h3{color:var(--err)}
.igx-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.igx-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.igx-grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.igx .field{margin-bottom:12px}
.igx .field > label{display:block;font-size:12px;font-weight:700;color:var(--ink-soft);margin-bottom:5px}
.igx .field > label .muted{font-weight:400;font-size:11px}
.igx .hint{font-size:11.5px;color:var(--ink-faint);margin-top:4px;line-height:1.7}

/* ---- کاشی آمار ---- */
.igx-kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px}
.igx-kpi span{display:block;font-size:11.5px;color:var(--ink-soft);margin-bottom:4px}
.igx-kpi b{font-size:22px;font-weight:800}

/* ---- ردیف‌های لیستی ---- */
.igx-list{display:flex;flex-direction:column;gap:8px}
.igx-item{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid var(--line-soft);border-radius:var(--radius-sm);background:var(--surface);transition:border-color .12s}
.igx-item:hover{border-color:var(--line)}
.igx-item.off{opacity:.6}
.igx-item .bd{flex:1;min-width:0}
.igx-item .tt{font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.igx-item .ds{font-size:12px;color:var(--ink-soft);margin-top:4px;line-height:1.7;word-break:break-word}
.igx-item .mt{font-size:10.5px;color:var(--ink-faint);margin-top:4px}
.igx-item .ac{display:flex;gap:5px;flex-shrink:0;flex-wrap:wrap;align-items:center}
.igx .tag{font-size:10.5px;padding:2px 8px}

/* ---- وضعیت ویژگی‌ها (خلاصه) ---- */
.igx-feat{display:flex;align-items:center;gap:10px;padding:11px 4px;border-bottom:1px solid var(--line-soft);cursor:pointer}
.igx-feat:last-child{border-bottom:0}
.igx-feat:hover .nm{color:var(--accent-ink)}
.igx-feat .ic{width:30px;height:30px;border-radius:9px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.igx-feat .nm{flex:1;font-size:13px;font-weight:600}
.igx-feat .ar{color:var(--ink-faint);font-size:12px}

/* ---- سوییچ ---- */
.igx-sw{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line-soft);cursor:pointer}
.igx-sw:last-child{border-bottom:0}
.igx-sw .tx{flex:1;min-width:0}
.igx-sw .tx b{display:block;font-size:13px;font-weight:700}
.igx-sw .tx span{display:block;font-size:11.5px;color:var(--ink-soft);margin-top:2px;line-height:1.6}
.igx-sw input{position:absolute;opacity:0;width:0;height:0}
.igx-sw .knob{position:relative;width:40px;height:22px;border-radius:20px;background:var(--line);flex-shrink:0;transition:.15s}
.igx-sw .knob::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:.15s}
.igx-sw input:checked + .knob{background:var(--ok)}
.igx-sw input:checked + .knob::after{inset-inline-start:21px}
.igx-sw input:focus-visible + .knob{box-shadow:0 0 0 3px var(--brand-soft)}

/* ---- انتخاب سه‌حالته ---- */
.igx-seg{display:inline-flex;gap:3px;padding:3px;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;flex-wrap:wrap}
.igx-seg button{border:0;background:transparent;font-family:inherit;font-size:12px;font-weight:600;color:var(--ink-soft);padding:7px 12px;border-radius:7px;cursor:pointer}
.igx-seg button.on{background:var(--surface);color:var(--ink);box-shadow:var(--shadow-xs)}

/* ---- نوار ذخیره ---- */
.igx-savebar{position:sticky;bottom:10px;z-index:5;display:none;align-items:center;gap:10px;padding:10px 14px;margin-top:6px;border-radius:12px;
  background:var(--ink);color:var(--surface);box-shadow:var(--shadow-md);font-size:12.5px;font-weight:600}
.igx-savebar.on{display:flex}
.igx-savebar .grow{flex:1}
.igx-savebar .btn-ghost{background:transparent;color:inherit;border-color:rgba(255,255,255,.25)}

/* ---- پست‌ها ---- */
.igx-posts{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}
.igx-post{border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;background:var(--surface);display:flex;flex-direction:column}
.igx-post .im{aspect-ratio:1/1;background:var(--surface-2);display:flex;align-items:center;justify-content:center;font-size:28px;color:var(--ink-faint);position:relative}
.igx-post .im img{width:100%;height:100%;object-fit:cover;display:block}
.igx-post .im .bdg{position:absolute;top:8px;inset-inline-start:8px;display:flex;gap:4px;flex-wrap:wrap}
.igx-post .bd{padding:10px 12px;flex:1;display:flex;flex-direction:column;gap:6px}
.igx-post .cp{font-size:12px;line-height:1.6;color:var(--ink-soft);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:38px}
.igx-post .id{font-size:10px;color:var(--ink-faint)}
.igx-post .ac{display:flex;gap:4px;flex-wrap:wrap;margin-top:auto}
.igx-post .ac .btn{flex:1;justify-content:center;white-space:nowrap;padding:0 6px;gap:4px}
.igx-post.story .im{aspect-ratio:9/16}

/* ---- کشو و مودال ---- */
.igx-overlay{position:fixed;inset:0;background:rgba(10,13,22,.45);z-index:900;display:none;backdrop-filter:blur(2px)}
.igx-overlay.on{display:block}
.igx-drawer{position:fixed;top:0;bottom:0;inset-inline-end:0;width:min(560px,100vw);background:var(--surface);z-index:901;box-shadow:var(--shadow-lg);
  display:flex;flex-direction:column;transform:translateX(-105%);transition:transform .22s ease}
[dir="ltr"] .igx-drawer{transform:translateX(105%)}
.igx-drawer.on{transform:none!important}
.igx-drawer-h{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid var(--line)}
.igx-drawer-h h3{flex:1;margin:0;font-size:15px;font-weight:800}
.igx-drawer-b{flex:1;overflow:auto;padding:18px}
.igx-drawer-f{display:flex;gap:8px;padding:12px 18px;border-top:1px solid var(--line);background:var(--surface-2)}
.igx-x{width:32px;height:32px;border-radius:9px;border:1px solid var(--line);background:var(--surface);cursor:pointer;font-size:15px;color:var(--ink-soft)}
.igx-x:hover{color:var(--ink)}
.igx-modal{position:fixed;inset:0;z-index:902;background:rgba(10,13,22,.35);display:none;align-items:center;justify-content:center;padding:16px}
.igx-modal.on{display:flex}
.igx-modal-card{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);width:min(640px,100%);max-height:calc(100vh - 32px);display:flex;flex-direction:column}
.igx-modal-card.wide{width:min(900px,100%)}

.igx-step{border:1px solid var(--line-soft);border-radius:var(--radius-sm);padding:14px;margin-bottom:12px}
.igx-step-h{display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;margin-bottom:10px}
.igx-step-n{width:22px;height:22px;border-radius:50%;background:var(--accent);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.igx-check{display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer;margin:6px 0}
.igx-check input{width:auto}
.igx-inline{display:flex;gap:6px;align-items:center}
.igx-inline input{flex:1;min-width:0}

.igx-empty{text-align:center;padding:30px 16px;color:var(--ink-faint);font-size:12.5px;line-height:1.8}
.igx-empty .e{font-size:30px;opacity:.6;margin-bottom:6px}
.igx-loading{color:var(--ink-faint);font-size:12.5px;padding:12px 0}
.igx-result{margin-top:10px;font-size:12.5px;line-height:1.8}
.igx-result:empty{display:none}

/* ---- از کد قبلی (لاگ‌ها، ویترین، منوی بات) ---- */
.ig-log-row{border:1px solid var(--line-soft);border-radius:var(--radius-sm);margin-bottom:8px;overflow:hidden}
.ig-log-row:hover{border-color:var(--line)}
.ig-log-head{display:flex;align-items:center;gap:8px;padding:10px 13px;cursor:pointer;font-size:12px;flex-wrap:wrap}
.ig-log-head .grow{flex:1;min-width:0}
.ig-log-body{display:none;padding:0 13px 13px;border-top:1px solid var(--line-soft)}
.ig-log-row.open .ig-log-body{display:block}
.ig-log-row.open .ig-log-head{background:var(--surface-2)}
.ig-log-body pre{font-size:11px;white-space:pre-wrap;word-break:break-all;background:var(--surface-2);padding:10px;border-radius:9px;max-height:320px;overflow:auto;direction:ltr;text-align:left;margin:6px 0}
.ig-sc-card{border:1px solid var(--line-soft);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;background:var(--surface-2)}
.ig-sc-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.ig-sc-card-head b{font-size:12px;color:var(--ink-soft)}
.ig-sc-row{display:flex;gap:8px;margin-bottom:8px}
.ig-sc-row .field{flex:1;margin:0}
.ig-sc-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;flex-shrink:0;background:var(--surface)}
.ig-sc-btn-row{display:flex;gap:6px;margin-bottom:6px}
.ig-sc-btn-row input{flex:1;min-width:0}
.ig-bm-row{display:flex;gap:6px;margin-bottom:8px;align-items:center;background:var(--surface-2);padding:8px;border-radius:var(--radius-sm);flex-wrap:wrap}
.ig-bm-row input,.ig-bm-row select{flex:1;min-width:110px}
.ig-bm-bubble{max-width:82%;padding:9px 13px;border-radius:14px;font-size:12.5px;line-height:1.6}
.ig-bm-bubble.out{align-self:flex-start;background:var(--surface-2);color:var(--ink);border-bottom-left-radius:4px}
.ig-bm-bubble.in{align-self:flex-end;background:var(--accent);color:#fff;border-bottom-right-radius:4px}
.ig-bm-bubble-btns{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.ig-bm-bubble-btns button{font-size:11px;padding:6px 10px;border-radius:20px;border:1px solid var(--line);background:var(--surface);cursor:pointer;color:var(--ink);font-family:inherit}
.aplog-flow{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin:10px 0}
.aplog-node{border:1px solid var(--line);border-radius:10px;padding:10px;cursor:pointer;font-size:12px}
.aplog-node.on,.aplog-node:hover{border-color:var(--accent)}
.aplog-node b{display:block;font-size:18px;margin-top:2px}
.aplog-check{display:flex;gap:10px;padding:10px 0;border-bottom:1px dashed var(--line);font-size:13px}
.aplog-check:last-child{border-bottom:0}
.aplog-dot{width:9px;height:9px;border-radius:50%;margin-top:6px;flex:none}
.aplog-dot.ok{background:var(--ok)}.aplog-dot.warn{background:var(--warn)}.aplog-dot.error{background:var(--err)}
.aplog-filters{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:10px 0}
.aplog-filters select,.aplog-filters input{width:auto;min-width:130px;height:34px;padding:0 10px}

/* ---- موبایل ---- */
@media (max-width:900px){
  .igx-split{grid-template-columns:1fr;gap:12px}
  .igx-side{position:static;display:flex;gap:4px;overflow-x:auto;padding:5px;scrollbar-width:none}
  .igx-side::-webkit-scrollbar{display:none}
  .igx-side-group{display:none}
  .igx-side button{width:auto;flex-shrink:0;white-space:nowrap;padding:8px 11px}
  .igx-side button .cnt{margin-inline-start:4px}
  .igx-grid3,.igx-grid4{grid-template-columns:repeat(2,minmax(0,1fr))}
  .aplog-flow{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:560px){
  .igx-topnav{display:grid;grid-template-columns:1fr 1fr}
  .igx-topnav button{min-width:0;padding:9px 8px;font-size:12.5px}
  .igx-grid2{grid-template-columns:1fr}
  .igx-pages{grid-template-columns:1fr}
  .igx-posts{grid-template-columns:repeat(2,minmax(0,1fr))}
  .igx-ph select{width:100%}
  .igx-item{flex-wrap:wrap}
  .igx-item .ac{width:100%}
}
</style>

<div class="igx" id="igx">

<!-- ================= سربرگ ================= -->
<div class="igx-head">
  <div class="igx-head-ico">📸</div>
  <div class="igx-head-txt">
    <h1>اینستاگرام</h1>
    <p id="igxHeadSub">در حال بارگذاری…</p>
  </div>
  <button type="button" class="igx-conn-pill" onclick="igxGo('connect/yarapi')"><span class="igx-dot" id="igxConnDot"></span><span id="igxConnTxt">وضعیت اتصال…</span></button>
</div>

<nav class="igx-topnav" id="igxTopnav">
  <button type="button" data-v="home" onclick="igxGo('home')">📱 پیج‌ها</button>
  <button type="button" data-v="shared" onclick="igxGo('shared/rules')">🧩 تنظیمات مشترک</button>
  <button type="button" data-v="tools" onclick="igxGo('tools/sales')">🛒 فروش و ابزارها</button>
  <button type="button" data-v="connect" onclick="igxGo('connect/yarapi')">🔌 اتصال و عیب‌یابی</button>
</nav>

<!-- ================= ۱) لیست پیج‌ها ================= -->
<div class="igx-view" id="igxV-home">
  <div class="igx-warn-list" id="igxHomeWarn"></div>
  <div class="igx-sec-head">
    <div class="t">
      <h2>پیج‌های من</h2>
      <p>روی هر پیج بزن تا تنظیمات، قانون‌ها، پست‌ها و آمار همان پیج را جدا ببینی و مدیریت کنی.</p>
    </div>
    <div class="igx-sec-actions">
      <button class="btn btn-ghost btn-sm" onclick="igxRefreshHome()">↻ تازه‌سازی</button>
      <button class="btn btn-ghost btn-sm" onclick="igxGo('connect/yarapi')">+ افزودن / همگام‌سازی پیج</button>
    </div>
  </div>
  <div class="igx-pages" id="igxPages"><div class="igx-loading">در حال بارگذاری پیج‌ها…</div></div>
</div>

<!-- ================= ۲) فضای کاری یک پیج ================= -->
<div class="igx-view" id="igxV-page">
  <div class="igx-ph" id="igxPH">
    <button type="button" class="back" onclick="igxGo('home')">→ همه‌ی پیج‌ها</button>
    <div class="igx-av sm" id="igxPHAv"></div>
    <div class="nm"><b id="igxPHName"></b><span id="igxPHMeta"></span></div>
    <select id="igxPHSwitch" onchange="igxSwitchPage(this.value)" aria-label="رفتن به پیج دیگر"></select>
  </div>

  <div class="igx-split">
    <aside class="igx-side" id="igxPageSide">
      <div class="igx-side-group">نمای کلی</div>
      <button type="button" data-s="overview"><span class="ic">🏠</span>خلاصه</button>
      <div class="igx-side-group">تعامل</div>
      <button type="button" data-s="comments"><span class="ic">💬</span>کامنت‌ها</button>
      <button type="button" data-s="rules"><span class="ic">🎯</span>اتوماسیون کامنت<span class="cnt" id="igxCntRules">۰</span></button>
      <div class="igx-side-group">پاسخ دایرکت</div>
      <button type="button" data-s="autoreply"><span class="ic">⚡</span>پاسخ خودکار</button>
      <button type="button" data-s="ai"><span class="ic">🤖</span>هوش مصنوعی</button>
      <button type="button" data-s="flow"><span class="ic">📞</span>شماره، منو و فرم</button>
      <div class="igx-side-group">محتوا</div>
      <button type="button" data-s="content"><span class="ic">🗂</span>پست‌ها و استوری‌ها</button>
      <button type="button" data-s="publish"><span class="ic">🖼</span>انتشار</button>
      <button type="button" data-s="dmtools"><span class="ic">🎠</span>ویترین و ابزار دایرکت</button>
      <div class="igx-side-group">پیج</div>
      <button type="button" data-s="settings"><span class="ic">⚙️</span>تنظیمات پیج</button>
    </aside>

    <main>
      <!-- خلاصه -->
      <section class="igx-sec" data-sec="overview">
        <div class="igx-grid4" style="margin-bottom:14px">
          <div class="igx-kpi"><span>دایرکت دریافتی امروز</span><b id="igxKIn">·</b></div>
          <div class="igx-kpi"><span>پیام ارسالی امروز</span><b id="igxKOut">·</b></div>
          <div class="igx-kpi"><span>کامنت امروز</span><b id="igxKCm">·</b></div>
          <div class="igx-kpi"><span>قانون کامنت فعال</span><b id="igxKRules">·</b></div>
        </div>
        <div class="igx-grid2">
          <div class="igx-box">
            <div class="igx-box-h"><h3>وضعیت این پیج</h3></div>
            <p class="igx-box-d">روی هر مورد بزن تا تنظیمش را عوض کنی.</p>
            <div id="igxFeat"><div class="igx-loading">در حال بارگذاری…</div></div>
          </div>
          <div class="igx-box">
            <div class="igx-box-h"><h3>آخرین کامنت‌ها</h3><button class="btn btn-ghost btn-xs" onclick="igxGoTab('comments')">همه</button></div>
            <div id="igxOvComments" class="igx-list" style="margin-top:10px"><div class="igx-loading">در حال بارگذاری…</div></div>
          </div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>کارهای سریع</h3></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            <button class="btn btn-primary btn-sm" onclick="igRuleForm(null, {ig_account_id: IGX.pageId})">+ قانون کامنت جدید</button>
            <button class="btn btn-ghost btn-sm" onclick="igxGoTab('publish')">🖼 انتشار پست/استوری</button>
            <button class="btn btn-ghost btn-sm" onclick="igxGoTab('content')">🗂 دیدن پست‌ها</button>
            <a class="btn btn-ghost btn-sm" href="index.php?page=inbox">✉️ صندوق ورودی</a>
          </div>
        </div>
      </section>

      <!-- کامنت‌ها -->
      <section class="igx-sec" data-sec="comments">
        <div class="igx-sec-head">
          <div class="t"><h2>کامنت‌های این پیج</h2><p>آخرین ۱۰۰ کامنتی که روی پست‌های این پیج آمده، با وضعیت پاسخ خودکار.</p></div>
          <div class="igx-sec-actions"><button class="btn btn-ghost btn-sm" onclick="igxLoadComments(true)">↻ تازه‌سازی</button></div>
        </div>
        <div class="igx-box" style="padding:12px">
          <div class="igx-seg" id="igxCmFilter" style="margin-bottom:10px">
            <button type="button" class="on" data-f="">همه</button>
            <button type="button" data-f="replied">پاسخ داده‌شده</button>
            <button type="button" data-f="open">بی‌پاسخ</button>
          </div>
          <div id="igxComments" class="igx-list"></div>
        </div>
      </section>

      <!-- اتوماسیون کامنت -->
      <section class="igx-sec" data-sec="rules">
        <div class="igx-sec-head">
          <div class="t"><h2>اتوماسیون کامنت</h2><p>قانون‌های «کامنت کن X تا برات بفرستم» که فقط روی همین پیج اجرا می‌شوند. ریپلای عمومی، دایرکت و فالو اجباری.</p></div>
          <div class="igx-sec-actions"><button class="btn btn-primary btn-sm" onclick="igRuleForm(null, {ig_account_id: IGX.pageId})">+ قانون جدید</button></div>
        </div>
        <div id="igxRulesPage" class="igx-list"></div>
        <div id="igxRulesSharedNote" style="margin-top:14px"></div>
      </section>

      <!-- پاسخ خودکار -->
      <section class="igx-sec" data-sec="autoreply">
        <div class="igx-sec-head">
          <div class="t"><h2>پاسخ خودکار دایرکت</h2><p>وقتی دایرکتی با کلمه‌ی کلیدی می‌رسد، این پیج چه جوابی بدهد. قانون مخصوص پیج بر قانون عمومی مقدم است.</p></div>
        </div>
        <div class="igx-box">
          <label class="igx-sw"><span class="tx"><b>پاسخ خودکار روی این پیج</b><span>خاموش باشد، هیچ پاسخ کلمه‌کلیدی روی این پیج فرستاده نمی‌شود.</span></span><input type="checkbox" data-ps="autoreply_enabled"><span class="knob"></span></label>
          <label class="igx-sw"><span class="tx"><b>قانون‌های عمومی هم اجرا شوند</b><span id="igxARGlobalHint">قانون‌هایی که در صفحه‌ی «پاسخ خودکار» برای همه‌ی پیج‌ها تعریف شده‌اند.</span></span><input type="checkbox" data-ps="autoreply_global"><span class="knob"></span></label>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>قانون‌های مخصوص این پیج</h3></div>
          <div id="igxARRules" class="igx-list" style="margin:10px 0 14px"></div>
          <div class="igx-step" style="margin:0;background:var(--surface-2)">
            <div class="igx-step-h" id="igxARFormTitle">+ قانون جدید</div>
            <input type="hidden" id="igxARId">
            <div class="igx-grid2">
              <div class="field"><label>کلمه‌ی محرک</label><input id="igxARKw" placeholder="مثلاً: قیمت"></div>
              <div class="field"><label>نوع تطبیق</label>
                <select id="igxARType"><option value="contains">شامل این کلمه</option><option value="exact">دقیقاً همین</option><option value="starts_with">شروع با</option><option value="regex">Regex</option></select></div>
            </div>
            <div class="field"><label>متن پاسخ</label><textarea id="igxARText" rows="3" placeholder="جوابی که این پیج می‌فرستد…"></textarea></div>
            <div class="field" style="max-width:160px"><label>اولویت <span class="muted">(بزرگ‌تر = زودتر)</span></label><input id="igxARPri" type="number" value="0"></div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary btn-sm" id="igxARSave" onclick="igxARSave()">افزودن قانون</button>
              <button class="btn btn-ghost btn-sm" id="igxARCancel" style="display:none" onclick="igxARReset()">انصراف</button>
            </div>
          </div>
        </div>
      </section>

      <!-- هوش مصنوعی -->
      <section class="igx-sec" data-sec="ai">
        <div class="igx-sec-head">
          <div class="t"><h2>هوش مصنوعی این پیج</h2><p>وقتی هیچ قانونی جور نشد، AI جواب بدهد یا نه، و با چه لحن و اطلاعاتی.</p></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>پاسخ AI</h3><span class="tag tag-mute" id="igxAIGlobal"></span></div>
          <p class="igx-box-d">«طبق تنظیم کلی» یعنی همان حالتی که در صفحه‌ی هوش مصنوعی اعلان‌بان انتخاب شده.</p>
          <div class="igx-seg" data-ps-seg="ai_mode">
            <button type="button" data-val="inherit">طبق تنظیم کلی</button>
            <button type="button" data-val="auto">روشن</button>
            <button type="button" data-val="off">خاموش</button>
          </div>
        </div>
        <div class="igx-box">
          <div class="field"><label>دستورالعمل مخصوص این پیج <span class="muted">(لحن، محصولات، قیمت‌ها، چیزهایی که نباید گفته شود)</span></label>
            <textarea data-ps="ai_prompt" rows="6" placeholder="مثلاً: این پیج فروشگاه پوشاک بانوان است؛ کوتاه و مؤدبانه جواب بده و برای قیمت به سایت ارجاع بده."></textarea></div>
          <div class="field" style="margin:0"><label>امضای پاسخ AI <span class="muted">(خالی = امضای کلی)</span></label><input data-ps="ai_signature" placeholder="مثلاً: — پشتیبانی فروشگاه"></div>
        </div>
      </section>

      <!-- شماره، منو و فرم -->
      <section class="igx-sec" data-sec="flow">
        <div class="igx-sec-head">
          <div class="t"><h2>شماره، منو و فرم</h2><p>گرفتن اجباری شماره، منوی دکمه‌ای بات، فرم‌ها و اعلان پیام تازه، فقط برای همین پیج.</p></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>📞 گرفتن اجباری شماره</h3><span class="tag tag-mute" id="igxPGGlobal"></span></div>
          <p class="igx-box-d">تا کاربر شماره ندهد، فقط یادآوری شماره می‌گیرد و هیچ پاسخ دیگری فرستاده نمی‌شود.</p>
          <div class="igx-seg" data-ps-seg="phone_gate" style="margin-bottom:14px">
            <button type="button" data-val="inherit">طبق تنظیم کلی</button>
            <button type="button" data-val="on">روشن</button>
            <button type="button" data-val="off">خاموش</button>
          </div>
          <div class="igx-grid2">
            <div class="field"><label>متن درخواست شماره <span class="muted">(خالی = متن کلی)</span></label><textarea data-ps="phone_gate_text" rows="3" id="igxPGText"></textarea></div>
            <div class="field"><label>متن تشکر بعد از ثبت <span class="muted">(خالی = متن کلی)</span></label><textarea data-ps="phone_gate_thanks" rows="3" id="igxPGThanks"></textarea></div>
          </div>
        </div>
        <div class="igx-box">
          <label class="igx-sw"><span class="tx"><b>🧭 منوی بات</b><span>با «منو» یا «شروع» منوی دکمه‌ای باز شود. <a href="#shared/botmenu">ویرایش منو (مشترک)</a></span></span><input type="checkbox" data-ps="botmenu_enabled"><span class="knob"></span></label>
          <label class="igx-sw"><span class="tx"><b>📝 فرم‌ها و نظرسنجی</b><span>فرم‌های کلمه‌کلیدی روی این پیج فعال باشند. <a href="index.php?page=forms">ساخت فرم</a></span></span><input type="checkbox" data-ps="forms_enabled"><span class="knob"></span></label>
          <label class="igx-sw"><span class="tx"><b>🔔 اعلان پیام تازه</b><span>برای دایرکت تازه‌ی این پیج، پاپ‌آپ در پنل نشان داده شود.</span></span><input type="checkbox" data-ps="notify_enabled"><span class="knob"></span></label>
        </div>
      </section>

      <!-- پست‌ها و استوری‌ها -->
      <section class="igx-sec" data-sec="content">
        <div class="igx-sec-head">
          <div class="t"><h2>پست‌ها و استوری‌ها</h2><p>برای هر پست می‌توانی مستقیم قانون کامنت بسازی یا به AI بگویی آن پست درباره‌ی چیست.</p></div>
          <div class="igx-sec-actions">
            <div class="igx-seg" id="igxContentSeg">
              <button type="button" class="on" data-k="posts">پست‌ها</button>
              <button type="button" data-k="stories">استوری‌های فعال</button>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="igxLoadContent(true)">↻</button>
          </div>
        </div>
        <div id="igxContent"></div>
        <div style="text-align:center;margin-top:12px"><button class="btn btn-ghost btn-sm" id="igxPostsMore" style="display:none" onclick="igxLoadMorePosts()">پست‌های بیشتر</button></div>
      </section>

      <!-- انتشار -->
      <section class="igx-sec" data-sec="publish">
        <div class="igx-sec-head">
          <div class="t"><h2>انتشار پست یا استوری</h2><p>روی همین پیج، همین حالا یا در زمان دلخواه منتشر می‌شود. عکس PNG و WebP خودکار به JPG تبدیل می‌شود؛ ویدیو و چندعکسی را سرویس‌دهنده پشتیبانی نمی‌کند.</p></div>
        </div>
        <div class="igx-box">
          <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
            <label for="rcPubImage" id="rcPubDrop" style="width:150px;aspect-ratio:4/5;border:2px dashed var(--line);border-radius:var(--radius);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;cursor:pointer;overflow:hidden;background:var(--surface-2);flex-shrink:0;color:var(--ink-faint);font-size:12px;text-align:center;padding:8px">
              <img id="rcPubPreview" style="display:none;width:100%;height:100%;object-fit:cover">
              <span id="rcPubDropTxt">🖼<br>انتخاب عکس</span>
            </label>
            <input type="file" id="rcPubImage" accept="image/jpeg,image/png,image/webp" onchange="rcUploadForPublish(this)" style="display:none">
            <div style="flex:1;min-width:220px">
              <div id="rcPubImageStatus" class="hint" style="margin:0 0 10px"></div>
              <div class="field">
                <label style="display:flex;justify-content:space-between"><span>کپشن <span class="muted">(فقط پست)</span></span><span class="muted" id="rcPubCaptionCount">۰/۲۲۰۰</span></label>
                <textarea id="rcPubCaption" rows="5" oninput="rcPubCaptionCounter()" placeholder="خط اول را جذاب بنویس؛ اینستاگرام کپشن بلند را کوتاه نشان می‌دهد."></textarea>
              </div>
              <div class="field">
                <label>زمان انتشار</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                  <label style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="radio" name="rcPubWhen" value="now" checked onchange="rcPubWhenChanged()"> همین حالا</label>
                  <label style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="radio" name="rcPubWhen" value="later" onchange="rcPubWhenChanged()"> زمان‌بندی</label>
                  <input type="datetime-local" id="rcPubAt" style="display:none;max-width:230px" dir="ltr" oninput="document.getElementById('rcPubAtFa').textContent=this.value?'('+toFa(this.value)+')':''"><span id="rcPubAtFa" class="muted" style="font-size:12px"></span>
                </div>
                <div class="hint" id="rcPubAtHint" style="display:none">به ساعت ایران. پست در اولین اجرای cron بعد از این زمان منتشر می‌شود؛ اگر انتشار شکست بخورد تا ۳ بار دوباره امتحان می‌شود.</div>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-primary btn-sm" id="rcPubBtnPost" onclick="rcPublish('post')">انتشار پست</button>
                <button class="btn btn-ghost btn-sm" id="rcPubBtnStory" onclick="rcPublish('story')">انتشار استوری</button>
              </div>
              <div id="rcPubResult" class="igx-result"></div>
              <p class="hint">💡 عکس مربعی یا ۴:۵ برای پست، ۹:۱۶ برای استوری.</p>
            </div>
          </div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>صف انتشار و تاریخچه</h3><span class="tag tag-mute">همه‌ی پیج‌ها</span><button class="btn btn-ghost btn-xs" onclick="rcLoadPublishHistory()">↻</button></div>
          <div id="rcPublishHistory" class="igx-list" style="margin-top:10px"></div>
        </div>
      </section>

      <!-- ویترین و ابزار دایرکت -->
      <section class="igx-sec" data-sec="dmtools">
        <div class="igx-sec-head">
          <div class="t"><h2>ویترین و ابزار دایرکت</h2><p>ارسال کاروسل محصول به یک مخاطب از طرف این پیج، و دیدن رسانه‌ی استوری‌ای که کاربر رویش ریپلای زده.</p></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>🎠 ارسال ویترین کارتی</h3><button class="btn btn-ghost btn-xs" onclick="rcShowcaseExample()">📋 پر کردن با نمونه</button></div>
          <p class="igx-box-d">۱ تا ۱۰ کارت، هرکدام با عکس مربعی، عنوان کوتاه، توضیح و حداکثر ۳ دکمه‌ی لینک.</p>
          <div class="field"><label>شناسه‌ی مخاطب (contactId) <span class="muted">(از پروفایل گفتگو در صندوق ورودی)</span></label><input id="rcShowContact" class="mono" dir="ltr"></div>
          <div id="rcShowCardsBuilder"></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" onclick="rcShowcaseAddCard()">+ کارت</button>
            <button class="btn btn-primary btn-sm" onclick="rcSendShowcase()">ارسال ویترین</button>
          </div>
          <div id="rcShowResult" class="igx-result"></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>🔍 رسانه‌ی ریپلای استوری</h3></div>
          <p class="igx-box-d">شناسه‌ی رسانه (resource_id) را از پیام «↩️ ریپلای استوری» در صندوق ورودی بردار و اینجا بچسبان.</p>
          <div class="igx-inline"><input id="rcStoryResourceId" class="mono" dir="ltr" placeholder="resource_id"><button class="btn btn-primary btn-sm" onclick="rcLookupStoryMedia()">دریافت</button></div>
          <div id="rcStoryResult" class="igx-result"></div>
        </div>
      </section>

      <!-- تنظیمات پیج -->
      <section class="igx-sec" data-sec="settings">
        <div class="igx-sec-head">
          <div class="t"><h2>تنظیمات پیج</h2><p>نام و رنگ این پیج در صندوق ورودی، تقویم محتوا و همه‌جای اعلان‌بان.</p></div>
        </div>
        <div class="igx-box">
          <div class="igx-grid2">
            <div class="field"><label>نام نمایشی</label><input id="igxSLabel"></div>
            <div class="field"><label>رنگ برچسب</label>
              <div class="igx-inline"><input id="igxSColor" type="color" style="width:56px;flex:0 0 56px;height:40px;padding:3px"><span class="hint" style="margin:0">در صندوق ورودی کنار هر گفتگو دیده می‌شود.</span></div></div>
          </div>
          <button class="btn btn-primary btn-sm" onclick="igxSaveIdentity()">ذخیره</button>
        </div>
        <div class="igx-box">
          <label class="igx-sw" style="padding-top:0"><span class="tx"><b>پیج فعال باشد</b><span>غیرفعال باشد، رویدادهای این پیج پردازش نمی‌شود و پاسخ خودکاری نمی‌رود.</span></span><input type="checkbox" id="igxSActive" onchange="igxToggleActive(this.checked)"><span class="knob"></span></label>
        </div>
        <div class="igx-box" id="igxSConn"></div>
        <div class="igx-box danger">
          <div class="igx-box-h"><h3>حذف پیج</h3></div>
          <p class="igx-box-d">گفتگوهای قبلی در صندوق ورودی می‌مانند؛ فقط دیگر پاسخی از این پیج نمی‌رود. پیج یار API با همگام‌سازی بعدی دوباره اضافه می‌شود.</p>
          <button class="btn btn-danger-ghost btn-sm" onclick="igxDeletePage()">حذف این پیج</button>
        </div>
      </section>

      <div class="igx-savebar" id="igxSavebar">
        <span class="grow">تغییرات این پیج هنوز ذخیره نشده</span>
        <button class="btn btn-ghost btn-sm" onclick="igxPSDiscard()">لغو</button>
        <button class="btn btn-primary btn-sm" onclick="igxPSSave()">ذخیره</button>
      </div>
    </main>
  </div>
</div>

<!-- ================= ۳) تنظیمات مشترک ================= -->
<div class="igx-view" id="igxV-shared">
  <div class="igx-split">
    <aside class="igx-side" id="igxSharedSide">
      <div class="igx-side-group">کامنت</div>
      <button type="button" data-s="rules"><span class="ic">🎯</span>قانون‌های همه‌ی پیج‌ها<span class="cnt" id="igxCntShared">۰</span></button>
      <button type="button" data-s="knowledge"><span class="ic">🧠</span>دانش پست‌ها (AI)</button>
      <button type="button" data-s="forbidden"><span class="ic">🚫</span>موضوعات ممنوعه</button>
      <div class="igx-side-group">دایرکت</div>
      <button type="button" data-s="faq"><span class="ic">❓</span>سؤالات متداول</button>
      <button type="button" data-s="phone"><span class="ic">📞</span>گیت شماره (پیش‌فرض)</button>
      <button type="button" data-s="botmenu"><span class="ic">🧭</span>منوی بات</button>
    </aside>
    <main>
      <div class="igx-strip" style="margin-bottom:14px"><span class="grow">این‌ها روی <b>همه‌ی پیج‌ها</b> اعمال می‌شوند، مگر جایی که در تنظیمات همان پیج چیز دیگری انتخاب کرده باشی.</span></div>

      <section class="igx-sec" data-sec="rules">
        <div class="igx-sec-head">
          <div class="t"><h2>قانون‌های کامنت برای همه‌ی پیج‌ها</h2><p>این قانون‌ها روی هر پیجی اجرا می‌شوند. قانون مخصوص یک پیج را از فضای همان پیج بساز.</p></div>
          <div class="igx-sec-actions"><button class="btn btn-primary btn-sm" onclick="igRuleForm(null, {ig_account_id: ''})">+ قانون مشترک</button></div>
        </div>
        <div id="igxRulesShared" class="igx-list"></div>
        <div class="igx-box" style="margin-top:14px">
          <div class="igx-box-h"><h3>قانون‌های مخصوص هر پیج</h3></div>
          <div id="igxRulesByPage" class="igx-list" style="margin-top:10px"></div>
        </div>
      </section>

      <section class="igx-sec" data-sec="knowledge">
        <div class="igx-sec-head">
          <div class="t"><h2>دانش پست‌ها</h2><p>اگر کامنتی با هیچ قانونی جور نشد ولی برای آن پست دانش ثبت شده، AI با همین دانش زیر کامنت جواب می‌دهد. ساده‌ترین راه: از «پست‌ها و استوری‌ها»ی هر پیج، دکمه‌ی «دانش AI» را بزن.</p></div>
          <div class="igx-sec-actions"><button class="btn btn-primary btn-sm" onclick="igxKnowledgeModal('')">+ دانش جدید</button></div>
        </div>
        <div id="igKnowledgeList" class="igx-list"></div>
      </section>

      <section class="igx-sec" data-sec="forbidden">
        <div class="igx-sec-head">
          <div class="t"><h2>موضوعات ممنوعه در پاسخ عمومی</h2><p>اگر کامنت شامل یکی از این کلمه‌ها بود، جواب واقعی زیر کامنت نمی‌رود؛ فقط متن جایگزین عمومی می‌رود و جواب اصلی در دایرکت فرستاده می‌شود.</p></div>
        </div>
        <div class="igx-box" style="max-width:640px">
          <div class="field"><label>کلمات ممنوعه <span class="muted">(با کاما جدا کن)</span></label><input id="igForbiddenTopics" placeholder="قیمت,هزینه,مبلغ,تخفیف"></div>
          <div class="field"><label>متن عمومی جایگزین</label><input id="igForbiddenRedirect" placeholder="برات دایرکت فرستادم، اونجا رو ببین 💌"></div>
          <button class="btn btn-primary btn-sm" onclick="igSaveForbidden()">ذخیره</button>
        </div>
      </section>

      <section class="igx-sec" data-sec="faq">
        <div class="igx-sec-head">
          <div class="t"><h2>سؤالات متداول</h2><p>همیشه در دسترس دستیار AI است، هم برای کامنت و هم برای دایرکت. مثل «ساعت کاری‌تون چیه؟» یا «ارسال به شهرستان دارید؟».</p></div>
        </div>
        <div id="igFaqList" class="igx-list" style="margin-bottom:14px"></div>
        <div class="igx-box" style="max-width:640px">
          <div class="igx-box-h"><h3>+ سؤال تازه</h3></div>
          <div class="field" style="margin-top:10px"><label>سؤال</label><input id="igFaqQ" placeholder="مثلاً: ارسال به شهرستان دارید؟"></div>
          <div class="field"><label>پاسخ</label><textarea id="igFaqA" rows="3" placeholder="بله، با پست پیشتاز…"></textarea></div>
          <button class="btn btn-primary btn-sm" onclick="igFaqSave()">ذخیره</button>
        </div>
      </section>

      <section class="igx-sec" data-sec="phone">
        <div class="igx-sec-head">
          <div class="t"><h2>گیت شماره، تنظیم پیش‌فرض</h2><p>برای پیج‌هایی که گیت شماره‌شان «طبق تنظیم کلی» است. اگر همان شماره قبلاً در کانال دیگری ثبت شده باشد، دو پروفایل خودکار یکی می‌شوند.</p></div>
        </div>
        <div class="igx-box" style="max-width:640px">
          <label class="igx-sw" style="padding-top:0"><span class="tx"><b>گرفتن اجباری شماره</b><span>پیش‌فرض برای همه‌ی پیج‌ها</span></span><input type="checkbox" id="igPhoneGateEnabled"><span class="knob"></span></label>
          <div class="field" style="margin-top:12px"><label>متن درخواست شماره</label><textarea id="igPhoneGatePrompt" rows="2"></textarea></div>
          <div class="field"><label>متن تشکر بعد از دریافت شماره</label><textarea id="igPhoneGateThanks" rows="2"></textarea></div>
          <button class="btn btn-primary btn-sm" onclick="igSavePhoneGate()">ذخیره</button>
        </div>
      </section>

      <section class="igx-sec" data-sec="botmenu">
        <div class="igx-sec-head">
          <div class="t"><h2>منوی بات</h2><p>هر «گره» یک پیام با حداکثر ۳ دکمه است: رفتن به گره دیگر، لینک، یا درخواست شماره. کاربر با «شروع»، «منو» یا <span class="mono">/start</span> وارد گره ریشه می‌شود. روشن/خاموش بودنش برای هر پیج جداست.</p></div>
          <div class="igx-sec-actions">
            <button class="btn btn-ghost btn-sm" onclick="igBotLoadExample()">📋 منوی نمونه</button>
            <button class="btn btn-primary btn-sm" onclick="igBotNodeForm()">+ گره جدید</button>
          </div>
        </div>
        <div class="igx-grid2" style="align-items:start">
          <div>
            <div id="igBotNodesList" class="igx-list"></div>
          </div>
          <div class="igx-box">
            <div class="igx-box-h"><h3>🧪 تست بات</h3><button class="btn btn-ghost btn-xs" onclick="document.getElementById('igBotSimChat').innerHTML=''">پاک کردن</button></div>
            <p class="igx-box-d">مثل یک مشتری پیام بده؛ چیزی واقعاً ارسال نمی‌شود.</p>
            <div id="igBotSimChat" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;max-height:340px;min-height:120px;overflow:auto;background:var(--surface-2);border-radius:var(--radius-sm);padding:10px"></div>
            <div class="igx-inline"><input id="igBotSimInput" placeholder="مثلاً: شروع" onkeydown="if(event.key==='Enter') igBotSimSend()"><button class="btn btn-primary btn-sm" onclick="igBotSimSend()">ارسال</button></div>
          </div>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- ================= ۴) فروش و ابزارها ================= -->
<div class="igx-view" id="igxV-tools">
  <div class="igx-split">
    <aside class="igx-side" id="igxToolsSide">
      <button type="button" data-s="sales"><span class="ic">🛒</span>کاتالوگ و سفارش</button>
      <button type="button" data-s="media"><span class="ic">🖼</span>تحلیل رسانه (AI)</button>
      <button type="button" data-s="forms" onclick="location.href='index.php?page=forms'"><span class="ic">📝</span>فرم و نظرسنجی ↗</button>
    </aside>
    <main>
      <section class="igx-sec" data-sec="sales">
        <div class="igx-sec-head">
          <div class="t"><h2>کاتالوگ و سفارش</h2><p>نسخه‌ی ساده: کاتالوگ داخلی و ثبت سفارش برای پیگیری، بدون اتصال به انبار یا درگاه پرداخت.</p></div>
        </div>
        <div class="igx-grid2" style="align-items:start">
          <div class="igx-box">
            <div class="igx-box-h"><h3>📦 محصولات</h3><button class="btn btn-ghost btn-xs" onclick="igProductForm()">+ محصول</button></div>
            <div id="igProductFormCard" style="display:none;margin:12px 0;padding:12px;border-radius:var(--radius-sm);background:var(--surface-2)">
              <input type="hidden" id="igPId">
              <div class="field"><label>عنوان</label><input id="igPTitle"></div>
              <div class="igx-grid2">
                <div class="field"><label>قیمت (تومان)</label><input id="igPPrice" type="number"></div>
                <div class="field"><label>موجودی</label><input id="igPStock" type="number"></div>
              </div>
              <div style="display:flex;gap:8px"><button class="btn btn-primary btn-xs" onclick="igSaveProduct()">ذخیره</button><button class="btn btn-ghost btn-xs" onclick="document.getElementById('igProductFormCard').style.display='none'">انصراف</button></div>
            </div>
            <div id="igProductsList" class="igx-list" style="margin-top:10px"></div>
          </div>
          <div class="igx-box">
            <div class="igx-box-h"><h3>🧾 سفارش‌ها</h3></div>
            <div id="igOrdersList" class="igx-list" style="margin:10px 0 14px"></div>
            <div class="igx-step" style="margin:0;background:var(--surface-2)">
              <div class="igx-step-h">ثبت سفارش سریع</div>
              <div class="field"><label>عنوان کالا</label><input id="igOItemTitle"></div>
              <div class="igx-grid2">
                <div class="field"><label>تعداد</label><input id="igOQty" type="number" value="1"></div>
                <div class="field"><label>قیمت واحد (تومان)</label><input id="igOPrice" type="number"></div>
              </div>
              <div class="field"><label>شناسه‌ی شخص <span class="muted">(اختیاری)</span></label><input id="igOPersonId" type="number"></div>
              <div class="field"><label>یادداشت</label><input id="igONotes" placeholder="آدرس یا توضیح"></div>
              <button class="btn btn-primary btn-xs" onclick="igCreateOrder()">ثبت سفارش</button>
            </div>
          </div>
        </div>
      </section>

      <section class="igx-sec" data-sec="media">
        <div class="igx-sec-head">
          <div class="t"><h2>تحلیل عکس یا ویس با AI</h2><p>ابزار دستی؛ آدرس یک عکس (مثلاً تصویر یک پست) یا یک فایل صوتی را بده تا AI توضیحش بدهد یا رونویسی کند.</p></div>
        </div>
        <div class="igx-box" style="max-width:640px">
          <div class="field"><label>نوع</label>
            <div class="igx-seg" id="igMediaKindSeg">
              <button type="button" class="on" data-val="image">عکس</button>
              <button type="button" data-val="audio">ویس (رونویسی)</button>
            </div>
            <input type="hidden" id="igMediaKind" value="image">
          </div>
          <div class="field"><label>آدرس رسانه</label><input id="igMediaUrl" class="mono" dir="ltr" placeholder="https://..."></div>
          <div class="field" id="igMediaPromptField"><label>سؤال <span class="muted">(اختیاری)</span></label><input id="igMediaPrompt" placeholder="مثلاً: توضیح بده این عکس چیه"></div>
          <button class="btn btn-primary btn-sm" onclick="igAnalyzeMedia()">تحلیل کن</button>
          <div id="igMediaResult" class="igx-result"></div>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- ================= ۵) اتصال و عیب‌یابی ================= -->
<div class="igx-view" id="igxV-connect">
  <div class="igx-split">
    <aside class="igx-side" id="igxConnectSide">
      <div class="igx-side-group">اتصال</div>
      <button type="button" data-s="yarapi"><span class="ic">⚡</span>اتصال یار API</button>
      <button type="button" data-s="health"><span class="ic">🩺</span>سلامت اتصال</button>
      <div class="igx-side-group">لاگ</div>
      <button type="button" data-s="log"><span class="ic">🔌</span>لاگ یار API</button>
      <button type="button" data-s="raw"><span class="ic">🪵</span>لاگ خام API</button>
      <!-- اتصال مستقیم قدیمی: از دید کاربر پنهان؛ فقط با آدرس #connect/legacy باز می‌شود (برای پیج‌هایی که از قبل این‌طور وصل‌اند) -->
    </aside>
    <main>
      <section class="igx-sec" data-sec="yarapi">
        <div class="igx-sec-head">
          <div class="t"><h2>اتصال به یار API</h2><p>همه‌ی پیج‌هایی که در پنل یار API وصل کرده‌ای با <b>یک کلید API</b> اضافه می‌شوند؛ بدون Account Key و وب‌هوک جدا برای هر پیج.</p></div>
          <span id="apStateTag" class="tag">…</span>
        </div>
        <div class="igx-box">
          <div class="igx-step" style="background:var(--surface-2)">
            <div class="igx-step-h"><span class="igx-step-n">۱</span>در پنل یار API پیج‌ها را وصل کن و یک کلید <span class="mono">ak_</span> بساز</div>
            <div class="hint" style="margin:0">scopeهای لازم: <span class="mono">account:read, messages:read, messages:send, webhooks:manage</span> و برای انتشار: <span class="mono">content:publish</span></div>
          </div>
          <div class="igx-grid2">
            <div class="field"><label>آدرس یار API</label><input id="apBase" class="mono" dir="ltr" placeholder="https://yarapi.ir/api/v1">
              <div class="hint">اگر 404 بدهد، فرم <span dir="ltr">…/api/v1/index.php</span> خودکار امتحان می‌شود.</div></div>
            <div class="field"><label>کلید API</label><input id="apKey" class="mono" dir="ltr" placeholder="ak_live_..." autocomplete="off"></div>
          </div>
          <div class="field"><label>دریافت رویدادها</label>
            <select id="apMode" onchange="apSetMode()">
              <option value="webhook">وب‌هوک لحظه‌ای (نیاز به HTTPS) + polling پشتیبان هر ۱۰ دقیقه</option>
              <option value="polling">فقط polling هر دقیقه با cron (سرور بدون HTTPS)</option>
            </select>
          </div>
          <button class="btn btn-primary btn-sm" onclick="apSave()">ذخیره و همگام‌سازی پیج‌ها</button>
          <div id="apResult" class="igx-result"></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>وضعیت</h3></div>
          <div id="apInfo" class="igx-list" style="margin-top:10px"></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px">
            <button class="btn btn-ghost btn-sm" onclick="apSync()">↻ همگام‌سازی پیج‌ها</button>
            <button class="btn btn-ghost btn-sm" onclick="apRegisterWebhook()">ثبت خودکار وب‌هوک</button>
            <button class="btn btn-ghost btn-sm" onclick="apTestWebhook()">تست وب‌هوک</button>
            <button class="btn btn-ghost btn-sm" onclick="apPoll()">دریافت دستی رویدادها</button>
            <button class="btn btn-danger-ghost btn-sm" onclick="apDisconnect()">قطع اتصال</button>
          </div>
        </div>
      </section>

      <section class="igx-sec" data-sec="health">
        <div class="igx-sec-head">
          <div class="t"><h2>سلامت اتصال</h2><p>بررسی خودکار همه‌ی حلقه‌ها: اینستاگرام ← یار API ← اعلان‌بان و برگشت جواب.</p></div>
          <div class="igx-sec-actions"><button class="btn btn-ghost btn-sm" onclick="apLogHealth()">بررسی دوباره</button></div>
        </div>
        <div class="igx-box"><div id="apLogHealth"></div></div>
      </section>

      <section class="igx-sec" data-sec="log">
        <div class="igx-sec-head">
          <div class="t"><h2>لاگ کامل یار API</h2><p>هر درخواست به یار API، هر وب‌هوک ورودی (حتی ردشده‌ها با دلیل)، نتیجه‌ی پردازش هر رویداد و هر دور polling. روی هر ردیف بزن تا JSON کامل را ببینی.</p></div>
        </div>
        <div class="igx-box">
          <div class="aplog-flow" id="apLogFlow"></div>
          <div class="aplog-filters">
            <select id="apLogKind" onchange="apLogReload()"><option value="">همه‌ی انواع</option></select>
            <select id="apLogOk" onchange="apLogReload()"><option value="">موفق و ناموفق</option><option value="0">فقط خطاها</option><option value="1">فقط موفق</option></select>
            <input id="apLogQ" placeholder="جستجو" onkeydown="if(event.key==='Enter') apLogReload()" style="flex:1">
            <label class="igx-check" style="margin:0"><input type="checkbox" id="apLogAuto" onchange="apLogAutoToggle()"> خودکار</label>
            <button class="btn btn-ghost btn-sm" onclick="apLogReload()">↻</button>
            <button class="btn btn-danger-ghost btn-sm" onclick="apLogClear()">پاک‌کردن</button>
          </div>
          <div id="apLogList"></div>
          <div style="text-align:center;margin-top:10px"><button class="btn btn-ghost btn-sm" id="apLogMore" style="display:none" onclick="apLogLoad(true)">بیشتر</button></div>
          <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--line-soft);font-size:12px" class="muted">
            <label class="igx-check" style="margin:0"><input type="checkbox" id="apLogEnabled"> ثبت لاگ فعال</label>
            <label style="display:flex;align-items:center;gap:6px">نگهداری <input id="apLogDays" type="number" min="1" max="90" style="width:70px;height:32px;padding:0 8px"> روز</label>
            <button class="btn btn-ghost btn-sm" onclick="apLogSaveSettings()">ذخیره</button>
          </div>
        </div>
      </section>

      <section class="igx-sec" data-sec="raw">
        <div class="igx-sec-head">
          <div class="t"><h2>لاگ خام API</h2><p>JSONهای رفت‌وبرگشتی با API (ارسال، پاسخ کامنت، پست، فالو و رویدادهای ورودی). بهترین جا برای فهمیدن اینکه چرا یک ارسال «موفق» بود ولی چیزی دیده نشد.</p></div>
          <div class="igx-sec-actions">
            <label class="igx-check" style="margin:0"><input type="checkbox" id="igRawAuto" onchange="igToggleAutoRefresh()"> خودکار (۵ ثانیه)</label>
            <button class="btn btn-ghost btn-sm" onclick="igLoadRawLog()">↻</button>
            <button class="btn btn-danger-ghost btn-sm" onclick="igClearRawLog()">پاک‌کردن</button>
          </div>
        </div>
        <div id="igRawLogList"></div>
      </section>

      <section class="igx-sec" data-sec="legacy">
        <div class="igx-sec-head">
          <div class="t"><h2>اتصال مستقیم (روش قدیمی)</h2><p>فقط اگر یار API در دسترس نیست. هر پیج جدا با Account Key یا OAuth وصل می‌شود و وب‌هوک و Secret خودش را دارد (در «تنظیمات پیج» همان پیج).</p></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>➕ افزودن با Account Key</h3></div>
          <p class="igx-box-d">در پنل سرویس‌دهنده پیج را وصل کن و Account Key (شروع با <span class="mono">ak_</span>) را کپی کن.</p>
          <div class="igx-grid2">
            <div class="field"><label>نام پیج</label><input id="rcNewLabel" placeholder="مثلاً: فروشگاه اصلی"></div>
            <div class="field"><label>یوزرنیم <span class="muted">(اختیاری)</span></label><input id="rcNewUsername" class="mono" dir="ltr" placeholder="my_page"></div>
            <div class="field"><label>Account Key</label><input id="rcNewKey" class="mono" dir="ltr" placeholder="ak_..."></div>
            <div class="field"><label>رنگ</label><input id="rcNewColor" type="color" value="#6366f1" style="width:56px;height:40px;padding:3px"></div>
          </div>
          <button class="btn btn-primary btn-sm" onclick="rcAddAccount()">افزودن پیج</button>
          <div id="rcAddResult" class="igx-result"></div>
        </div>
        <div class="igx-box">
          <div class="igx-box-h"><h3>🔗 افزودن با OAuth</h3></div>
          <p class="igx-box-d">۱) Integration Key (<span class="mono">rk_</span>) را ذخیره کن ۲) آدرس برگشت را در پنل سرویس‌دهنده ثبت کن ۳) «شروع اتصال» را بزن.</p>
          <div class="field"><label>Integration Key</label><div class="igx-inline"><input id="rcIntegrationKey" class="mono" dir="ltr" placeholder="rk_..."><button class="btn btn-ghost btn-sm" onclick="rcSaveIntegrationKey()">ذخیره</button></div></div>
          <div id="rcIntegrationResult" class="igx-result" style="margin:-4px 0 10px"></div>
          <div class="field"><label>Allowed Return URL</label><div class="igx-inline"><input id="rcReturnUrl" class="mono" dir="ltr" readonly onclick="this.select()"><button class="btn btn-ghost btn-sm" onclick="igxCopy(document.getElementById('rcReturnUrl').value)">کپی</button></div></div>
          <label class="igx-check"><input type="checkbox" id="rcUseFreeTrial"> استفاده از تست رایگان</label>
          <button class="btn btn-primary btn-sm" style="margin-top:6px" onclick="rcStartOAuth()">شروع اتصال</button>
          <div id="rcOauthResult" class="igx-result"></div>
        </div>
        <div id="igMgmtBanner" class="hint"></div>
      </section>
    </main>
  </div>
</div>

<!-- ================= کشوی فرم قانون کامنت ================= -->
<div class="igx-overlay" id="igxOverlay" onclick="igxCloseAll()"></div>
<div class="igx-drawer" id="igRuleFormCard" role="dialog" aria-modal="true" aria-labelledby="igRuleFormTitle">
  <div class="igx-drawer-h">
    <h3 id="igRuleFormTitle">قانون جدید</h3>
    <button class="btn btn-ghost btn-xs" onclick="igLoadRuleExample()">📋 نمونه</button>
    <button class="igx-x" onclick="igxCloseAll()" aria-label="بستن">✕</button>
  </div>
  <div class="igx-drawer-b">
    <input type="hidden" id="igRId">
    <div class="igx-step">
      <div class="igx-step-h"><span class="igx-step-n">۱</span>کجا</div>
      <div class="field"><label>نام قانون <span class="muted">(فقط برای خودت)</span></label><input id="igRName" placeholder="مثلاً: هدیه‌ی پست جشنواره"></div>
      <div class="field"><label>پیج</label><select id="igRAccountId"></select></div>
      <label class="igx-check"><input type="checkbox" id="igRTriggerComment" checked> با کامنت زیر پست</label>
      <div class="field" style="margin-inline-start:24px">
        <div class="igx-inline"><input id="igRMediaId" class="mono" dir="ltr" placeholder="شناسه‌ی پست — خالی = همه‌ی پست‌ها"><button class="btn btn-ghost btn-sm" onclick="igxPickPost()">انتخاب پست</button></div>
      </div>
      <label class="igx-check"><input type="checkbox" id="igRTriggerStory"> با ریپلای روی استوری</label>
      <div class="field" style="margin-inline-start:24px;margin-bottom:0"><input id="igRStoryId" class="mono" dir="ltr" placeholder="شناسه‌ی استوری — خالی = همه‌ی استوری‌ها"></div>
    </div>
    <div class="igx-step">
      <div class="igx-step-h"><span class="igx-step-n">۲</span>چه وقت</div>
      <div class="field"><label>کلمه‌ی کلیدی <span class="muted">(خالی = هر کامنتی؛ چند کلمه با کاما)</span></label><input id="igRKeyword" placeholder="قیمت, هزینه, چند"></div>
      <div class="igx-grid2">
        <div class="field"><label>نوع تطبیق</label><select id="igRMatchMode"><option value="contains">شامل یکی از کلمات</option><option value="exact">دقیقاً همان کلمه</option></select></div>
        <div class="field"><label>کلمات مستثنا</label><input id="igRExcludeKeywords" placeholder="اسپم, تبلیغ"></div>
      </div>
      <label class="igx-check"><input type="checkbox" id="igROncePerUser"> فقط یک‌بار برای هر کاربر</label>
      <details style="margin-top:8px"><summary class="muted" style="cursor:pointer;font-size:12px">بازه‌ی زمانی (اختیاری)</summary>
        <div class="igx-grid2" style="margin-top:10px">
          <div class="field"><label>از</label><input id="igRStartAt" type="datetime-local"></div>
          <div class="field"><label>تا</label><input id="igREndAt" type="datetime-local"></div>
        </div>
      </details>
    </div>
    <div class="igx-step">
      <div class="igx-step-h"><span class="igx-step-n">۳</span>چه کار</div>
      <div class="field"><label>ریپلای عمومی زیر کامنت <span class="muted">(اختیاری)</span></label><textarea id="igRPublicReply" rows="2" placeholder="فرستادم، دایرکتت رو چک کن 💌"></textarea></div>
      <label class="igx-check"><input type="checkbox" id="igRRequireFollow" onchange="igToggleFollowFields()"> فالو اجباری قبل از ارسال محتوای اصلی</label>
      <div id="igRFollowFields" style="display:none;margin:8px 0 0;padding:12px;border-radius:var(--radius-sm);background:var(--surface-2)">
        <div class="field"><label>متن درخواست فالو</label><textarea id="igRFollowPrompt" rows="2" placeholder="اول پیجمون رو فالو کن، بعد دکمه‌ی زیر رو بزن 🙏"></textarea></div>
        <div class="field"><label>متن دکمه‌ی تأیید</label><input id="igRConfirmPhrase" value="فالو کردم"></div>
        <div class="field" style="margin:0"><label>اگر هنوز فالو نکرده بود</label><textarea id="igRNotYet" rows="2" placeholder="هنوز فالو نکردی 🙈"></textarea></div>
      </div>
      <div class="field" style="margin-top:12px"><label>دایرکت نهایی (محتوای اصلی)</label><textarea id="igRDmText" rows="4" placeholder="لینک، کد تخفیف یا اطلاعات…"></textarea></div>
    </div>
    <label class="igx-check"><input type="checkbox" id="igRActive" checked> قانون فعال باشد</label>
  </div>
  <div class="igx-drawer-f">
    <button class="btn btn-primary btn-sm" onclick="igSaveRule()">ذخیره‌ی قانون</button>
    <button class="btn btn-ghost btn-sm" onclick="igxCloseAll()">انصراف</button>
  </div>
</div>

<!-- ================= کشوی گره منوی بات ================= -->
<div class="igx-drawer" id="igBotNodeFormCard" role="dialog" aria-modal="true" aria-labelledby="igBotNodeFormTitle">
  <div class="igx-drawer-h"><h3 id="igBotNodeFormTitle">گره جدید</h3><button class="igx-x" onclick="igxCloseAll()" aria-label="بستن">✕</button></div>
  <div class="igx-drawer-b">
    <input type="hidden" id="botNId">
    <div class="igx-grid2">
      <div class="field"><label>کلید گره <span class="muted">(انگلیسی، یکتا)</span></label><input id="botNKey" class="mono" dir="ltr" placeholder="main"></div>
      <div class="field"><label>عنوان</label><input id="botNTitle" placeholder="منوی اصلی"></div>
    </div>
    <label class="igx-check"><input type="checkbox" id="botNIsRoot"> گره ریشه (با «شروع» / «منو» باز می‌شود)</label>
    <div class="field" style="margin-top:10px"><label>متن پیام</label><textarea id="botNText" rows="3" placeholder="سلام! چطور می‌تونم کمکتون کنم؟"></textarea></div>
    <div class="igx-step-h" style="margin-top:6px">دکمه‌ها <span class="muted" style="font-weight:400;font-size:11px">(حداکثر ۳)</span></div>
    <div id="botNButtons"></div>
    <button class="btn btn-ghost btn-xs" onclick="igBotAddButtonRow()">+ دکمه</button>
    <div class="igx-step-h" style="margin-top:16px">شاخه با کلمه <span class="muted" style="font-weight:400;font-size:11px">(اختیاری)</span></div>
    <div id="botNRules"></div>
    <button class="btn btn-ghost btn-xs" onclick="igBotAddRuleRow()">+ شرط</button>
  </div>
  <div class="igx-drawer-f">
    <button class="btn btn-primary btn-sm" onclick="igBotSaveNode()">ذخیره</button>
    <button class="btn btn-ghost btn-sm" onclick="igxCloseAll()">انصراف</button>
  </div>
</div>

<!-- ================= مودال عمومی ================= -->
<div class="igx-modal" id="igxModal" onclick="if(event.target===this) igxCloseModal()">
  <div class="igx-modal-card" id="igxModalCard" role="dialog" aria-modal="true" aria-labelledby="igxModalTitle">
    <div class="igx-drawer-h"><h3 id="igxModalTitle"></h3><button class="igx-x" onclick="igxCloseModal()" aria-label="بستن">✕</button></div>
    <div class="igx-drawer-b" id="igxModalBody"></div>
    <div class="igx-drawer-f" id="igxModalFoot"></div>
  </div>
</div>

</div>
