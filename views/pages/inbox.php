<?php /* pages/inbox.php — صندوق ورودی: پیام‌های دریافتی از مشتریان (دور ۳: تخصیص چت، سابقه‌ی کامل، پروفایل هر سوشال، ریسپانسیو) */ ?>
<div class="page-head ib-page-head">
  <div>
    <h1>صندوق ورودی</h1>
    <p>پیام‌هایی که مشتری‌ها از کانال‌ها و پیج‌های مختلف فرستاده‌اند — همه در یک‌جا</p>
  </div>
  <div class="ib-team" id="ibTeam"></div>
</div>

<div class="ib-viewbar">
  <div class="ib-seg" id="ibViewSeg" role="tablist" aria-label="نوع نمایش صندوق ورودی">
    <button type="button" data-v="tabs" onclick="ibSetView('tabs')">🗂 تب کانال‌ها</button>
    <button type="button" data-v="list" onclick="ibSetView('list')">☰ لیست یکجا</button>
  </div>
  <div class="ib-seg ib-seg-assign" id="ibAssignSeg" aria-label="فیلتر مسئول چت">
    <button type="button" data-a="" onclick="ibSetAssign('')">همه</button>
    <button type="button" data-a="me" onclick="ibSetAssign('me')">چت‌های من</button>
    <button type="button" data-a="none" onclick="ibSetAssign('none')">بی‌مسئول</button>
    <button type="button" data-a="others" onclick="ibSetAssign('others')">همکاران</button>
    <button type="button" data-a="ai" onclick="ibSetAssign('ai')">🤖 با AI</button>
  </div>
  <span class="ib-viewhint" id="ibViewHint"></span>
</div>
<div class="ib-tabs" id="ibTabs" role="tablist" aria-label="کانال‌ها"></div>
<div class="ib-subtabs" id="ibSubTabs"></div>

<div class="inbox-wrap card" id="inboxWrap">
  <!-- ستون گفتگوها -->
  <div class="inbox-list">
    <div class="inbox-list-head">
      <input id="ibSearch" placeholder="جستجوی نام، شماره یا @یوزرنیم…" onkeydown="if(event.key==='Enter')loadConvos()">
      <div class="inbox-filters">
        <select id="ibChannel" onchange="IB_IGACC=0;loadConvos()">
          <option value="">همه کانال‌ها</option>
          <option value="bale">بله</option><option value="telegram">تلگرام</option>
          <option value="roubika">روبیکا</option><option value="sms">پیامک</option>
          <option value="bale_safir">صفیر</option><option value="email">ایمیل</option>
          <option value="webchat">گفتگوی سایت</option>
          <option value="instagram">اینستاگرام</option>
        </select>
        <label class="ib-unread-toggle">
          <input type="checkbox" id="ibUnread" onchange="loadConvos()"> خوانده‌نشده
        </label>
        <label class="ib-unread-toggle">
          <input type="checkbox" id="ibBlocked" onchange="loadConvos()"> بلاک‌شده
        </label>
      </div>
    </div>
    <div id="convoRows" class="inbox-convos"><div class="empty">در حال بارگذاری…</div></div>
    <div id="ibPager" class="inbox-pager"></div>
  </div>

  <!-- ستون گفتگوی باز -->
  <div class="inbox-thread" id="threadPane">
    <div class="inbox-empty-state" id="threadEmpty">
      <div style="font-size:40px;opacity:.4">💬</div>
      <p class="muted">یک گفتگو را از فهرست انتخاب کنید</p>
    </div>
    <div id="threadContent" class="thread-content" style="display:none">
      <div class="thread-head" id="threadHead"></div>
      <div class="ib-assignbar" id="ibAssignBar"></div>
      <div class="ib-filterbar" id="ibFilterBar" style="display:none"></div>
      <div class="thread-body" id="threadBody"></div>
      <div class="thread-reply" id="threadReply">
        <div class="ib-reply-lock" id="ibReplyLock" style="display:none"></div>
        <textarea id="replyText" placeholder="پاسخ خود را بنویسید…" rows="2"
                  oninput="ibTyping()" onkeydown="if(event.key==='Enter'&&(event.ctrlKey||event.metaKey))sendReply()"></textarea>
        <div id="attachLabel" class="muted" style="font-size:11px;margin-bottom:6px" onclick="clearAttach()"></div>
        <div class="thread-reply-foot">
          <div class="thread-reply-tools">
            <label class="btn btn-ghost btn-sm" id="attachBtn" style="cursor:pointer;margin:0">
              📎 <span class="ib-hide-sm">عکس</span>
              <input type="file" accept="image/*" style="display:none" onchange="onAttachPick(this)">
            </label>
            <button class="btn btn-ghost btn-sm" id="aiDraftBtn" onclick="aiDraft()" style="display:none"
                    title="پیش‌نویس پاسخ با هوش مصنوعی — دکمه‌ی ارسال دست خودت است">✦ <span class="ib-hide-sm">پیش‌نویس AI</span></button>
            <div style="position:relative">
              <button class="btn btn-ghost btn-sm" onclick="toggleCannedMenu()" title="پیام‌های آماده‌ی تیم">📋 <span class="ib-hide-sm">پیام آماده</span></button>
              <div id="cannedMenu" class="card" style="display:none;position:absolute;bottom:calc(100% + 6px);right:0;
                   width:280px;max-width:80vw;max-height:280px;overflow:auto;z-index:20;padding:6px;box-shadow:var(--shadow-md)"></div>
            </div>
            <span id="replyHint" class="muted ib-reply-hint"></span>
          </div>
          <button class="btn btn-primary btn-sm" id="replyBtn" onclick="sendReply()">ارسال پاسخ</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ستون سوم: پروفایل و زمینه‌ی مشتری (در صفحه‌های کوچک‌تر کشویی) -->
  <div class="inbox-profile" id="profilePane">
    <div class="ip-mobile-head"><b>اطلاعات مشتری</b><button class="btn btn-ghost btn-sm" onclick="ibToggleProfile(false)">✕</button></div>
    <div class="inbox-profile-empty" id="profileEmpty">
      <span class="muted" style="font-size:12px">اطلاعات مشتری</span>
    </div>
    <div id="profileContent" style="display:none"></div>
  </div>
  <div class="ip-backdrop" id="ipBackdrop" onclick="ibToggleProfile(false)"></div>
</div>

<!-- پنجره‌ی عمومی (انتقال، آمار پشتیبان‌ها، سابقه‌ی تخصیص) -->
<div class="ib-modal-bg" id="ibModalBg" onclick="if(event.target===this)ibCloseModal()">
  <div class="ib-modal card" role="dialog" aria-modal="true">
    <div class="ib-modal-head"><b id="ibModalTitle"></b><button class="btn btn-ghost btn-sm" onclick="ibCloseModal()">✕</button></div>
    <div class="ib-modal-body" id="ibModalBody"></div>
  </div>
</div>

<style>
/* ---- سربرگ و نوار تیم ---- */
.ib-page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ib-team{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.ib-team .ib-chip-me{display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:6px 10px;font-size:12px;font-weight:600}
.ib-team .dot{width:8px;height:8px;border-radius:50%;background:var(--ok)}
.ib-team .dot.off{background:var(--warn)}
/* ---- انتخاب نوع نمایش ---- */
.ib-viewbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.ib-seg{display:inline-flex;gap:3px;padding:3px;background:var(--surface);border:1px solid var(--line);border-radius:11px;max-width:100%;overflow-x:auto;scrollbar-width:none}
.ib-seg::-webkit-scrollbar{display:none}
.ib-seg button{border:0;background:transparent;font-family:inherit;font-size:12.5px;font-weight:700;color:var(--ink-soft);padding:8px 12px;border-radius:8px;cursor:pointer;white-space:nowrap;flex-shrink:0}
.ib-seg button.on{background:var(--brand-soft);color:var(--accent-ink)}
.ib-viewhint{font-size:11.5px;color:var(--ink-faint)}
.ib-tabs{display:none;gap:4px;overflow-x:auto;scrollbar-width:none;border-bottom:1px solid var(--line);margin-bottom:10px}
.ib-tabs::-webkit-scrollbar{display:none}
.ib-tabs.on{display:flex}
.ib-tab{display:inline-flex;align-items:center;gap:7px;flex-shrink:0;border:0;background:transparent;font-family:inherit;font-size:13px;font-weight:700;color:var(--ink-soft);
  padding:10px 14px;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-1px;white-space:nowrap}
.ib-tab:hover{color:var(--ink)}
.ib-tab.on{color:var(--accent-ink);border-bottom-color:var(--accent)}
.ib-tab .n{font-size:10.5px;font-weight:600;color:var(--ink-faint)}
.ib-tab .u{background:var(--accent);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px}
.ib-subtabs{display:none;gap:6px;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;margin-bottom:10px;padding-bottom:2px}
.ib-subtabs::-webkit-scrollbar{display:none}
.ib-subtabs.on{display:flex}
.ib-sub{display:inline-flex;align-items:center;gap:6px;flex-shrink:0;border:1px solid var(--line);background:var(--surface);border-radius:20px;padding:5px 11px;font-family:inherit;font-size:12px;font-weight:600;color:var(--ink-soft);cursor:pointer;white-space:nowrap}
.ib-sub .d{width:8px;height:8px;border-radius:50%}
.ib-sub.on{border-color:var(--pc,var(--accent));color:var(--ink);box-shadow:inset 0 0 0 1px var(--pc,var(--accent))}
.ib-sub .u{background:var(--accent);color:#fff;font-size:9.5px;border-radius:9px;padding:0 5px;min-width:16px;text-align:center}
.ib-mode-tabs #ibChannel{display:none}

/* ---- سه ستون ---- */
.inbox-wrap{display:flex;height:calc(100vh - 250px);min-height:460px;overflow:hidden;padding:0;position:relative}
.inbox-list{width:330px;flex-shrink:0;border-left:1px solid var(--line);display:flex;flex-direction:column;min-height:0}
.inbox-list-head{padding:12px;border-bottom:1px solid var(--line-soft)}
.inbox-list-head #ibSearch{width:100%;margin-bottom:8px}
.inbox-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.inbox-filters select{flex:1;min-width:110px}
.ib-unread-toggle{display:inline-flex;align-items:center;gap:4px;font-size:12px;white-space:nowrap;cursor:pointer}
.ib-unread-toggle input{width:auto}
.inbox-convos{flex:1;overflow-y:auto;min-height:0}
.convo{display:flex;gap:10px;padding:11px 12px;border-bottom:1px solid var(--line-soft);cursor:pointer;transition:.12s}
.convo:hover{background:var(--bg)}
.convo.active{background:var(--accent-soft)}
.convo-avatar{width:38px;height:38px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;position:relative}
.convo-avatar .eye{position:absolute;bottom:-3px;left:-3px;background:var(--surface);border:1px solid var(--line);border-radius:10px;font-size:9px;padding:0 3px;line-height:15px}
.convo-main{flex:1;min-width:0}
.convo-top{display:flex;justify-content:space-between;align-items:center;gap:6px}
.convo-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.convo-time{font-size:10.5px;color:var(--ink-faint);white-space:nowrap}
.convo-preview{font-size:12px;color:var(--ink-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;min-width:0}
.convo-preview .me{color:var(--ink-faint)}
.convo-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0}
.convo-tags{margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;align-items:center}
.as-chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:600;border-radius:7px;padding:2px 7px;background:var(--surface-2,var(--bg));color:var(--ink-soft);border:1px solid var(--line-soft);white-space:nowrap}
.as-chip.mine{background:var(--ok-soft);color:var(--ok);border-color:transparent}
.as-chip.other{background:var(--info-soft);color:var(--info);border-color:transparent}
.as-chip.ai{background:var(--accent-soft);color:var(--accent-ink);border-color:transparent}
.as-chip.none{background:var(--warn-soft);color:var(--warn);border-color:transparent}
.inbox-pager{padding:8px;display:flex;gap:6px;justify-content:center;align-items:center;border-top:1px solid var(--line-soft)}

.inbox-thread{flex:1;display:flex;flex-direction:column;min-width:0;min-height:0}
.thread-content{display:flex;flex-direction:column;height:100%;min-height:0}
.inbox-empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px}
.thread-head{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px}
.thread-head .th-main{display:flex;align-items:center;gap:8px;min-width:0}
.thread-head .th-title{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.thread-head .th-ids{margin-top:3px;display:flex;flex-wrap:nowrap;gap:4px;overflow-x:auto;scrollbar-width:none;max-width:100%}
.thread-head .th-ids::-webkit-scrollbar{display:none}
.thread-head .th-ids .chip{flex-shrink:0}
.thread-head .th-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
.ib-back{display:none}
.ib-more{position:relative}
.ib-more-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:30;min-width:200px;padding:6px;box-shadow:var(--shadow-md)}
.ib-more-menu.on{display:block}
.ib-more-menu button,.ib-more-menu a{display:block;width:100%;text-align:right;margin-bottom:2px}
.ib-assignbar{display:flex;align-items:center;gap:6px 8px;flex-wrap:wrap;padding:7px 16px;border-bottom:1px solid var(--line-soft);font-size:12.5px;line-height:1.6;background:var(--surface)}
.ib-assignbar .btn{flex-shrink:0}
.ib-assignbar:empty{display:none}
.ib-assignbar .grow{flex:1;min-width:180px}
.ib-assignbar .sub{font-size:11px;color:var(--ink-faint)}
.ib-assignbar .who{font-weight:700}
.ib-assignbar.taken{background:var(--info-soft)}
.ib-assignbar.ai{background:var(--accent-soft)}
.ib-assignbar.none{background:var(--warn-soft)}
.ib-viewers{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;color:var(--ink-soft)}
.ib-viewers .pulse{width:7px;height:7px;border-radius:50%;background:var(--ok);animation:ibPulse 1.4s infinite}
@keyframes ibPulse{0%{box-shadow:0 0 0 0 rgba(14,157,91,.5)}70%{box-shadow:0 0 0 6px rgba(14,157,91,0)}100%{box-shadow:0 0 0 0 rgba(14,157,91,0)}}
.ib-filterbar{display:flex;align-items:center;gap:8px;padding:6px 16px;font-size:12px;background:var(--bg);border-bottom:1px solid var(--line-soft)}
.thread-body{flex:1;overflow-y:auto;padding:16px;background:var(--bg);min-height:0}
.ib-load-older{display:flex;justify-content:center;margin-bottom:12px}
.ib-day{text-align:center;margin:10px 0;font-size:11px;color:var(--ink-faint)}
.ib-day span{background:var(--surface);border:1px solid var(--line-soft);padding:2px 10px;border-radius:10px}
.thread-reply{padding:10px 16px;border-top:1px solid var(--line);position:relative}
.thread-reply textarea{width:100%;resize:vertical;margin-bottom:8px;min-height:44px}
.thread-reply-foot{display:flex;justify-content:space-between;align-items:center;gap:8px}
#replyBtn{white-space:nowrap;flex-shrink:0}
.thread-reply-tools{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}
.ib-reply-hint{font-size:11px;min-width:0;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.thread-reply-tools{flex-wrap:nowrap!important;overflow:hidden}
.ib-reply-lock{position:absolute;inset:0;z-index:5;background:color-mix(in srgb,var(--surface) 88%,transparent);display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;padding:10px;text-align:center;font-size:12.5px;backdrop-filter:blur(2px)}

/* حباب‌ها */
.t-bubble{margin-bottom:10px;max-width:78%;padding:9px 12px;border-radius:12px;font-size:13px;line-height:1.7;word-break:break-word;position:relative}
.t-bubble.in{background:var(--surface);border:1px solid var(--line);margin-left:auto;border-bottom-right-radius:4px}
.t-bubble.out{background:var(--accent-soft);color:var(--accent-ink);margin-right:auto;border-bottom-left-radius:4px}
.t-bubble.out.auto{background:var(--surface);color:var(--ink);border:1.5px dashed color-mix(in srgb,var(--accent) 45%,transparent)}
.t-bubble.in.comment{background:color-mix(in srgb,#e1306c 7%,var(--surface));border-color:color-mix(in srgb,#e1306c 30%,transparent)}
.t-bubble.out.creply{border-style:dotted}
.t-bubble .t-kind{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:10.5px;font-weight:700;margin-bottom:4px;color:var(--ink-soft)}
.t-bubble .t-kind .k{background:var(--accent-soft);color:var(--accent-ink);border-radius:6px;padding:1px 7px}
.t-bubble.in.comment .t-kind .k{background:#e1306c1f;color:#c1265c}
.t-bubble .t-meta{font-size:10px;color:var(--ink-faint);margin-top:4px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.t-bubble.out.failed{background:var(--err-soft);color:var(--err)}
.t-bubble.msg-deleted{opacity:.55;filter:grayscale(.3)}
.t-activity{margin:8px auto 12px;max-width:86%;background:var(--surface);border:1px solid var(--line-soft);border-radius:12px;padding:8px 12px;font-size:12px;color:var(--ink-soft);display:flex;gap:8px;align-items:flex-start}
.t-activity .ic{font-size:16px;line-height:1.4}
.t-activity b{color:var(--ink)}
.msg-del-btn{background:transparent;border:0;cursor:pointer;font-size:11px;opacity:.5;padding:0;margin-inline-start:6px;line-height:1}
.msg-del-btn:hover{opacity:1}

/* ستون سوم: پروفایل */
.inbox-profile{width:300px;flex-shrink:0;border-right:1px solid var(--line);overflow-y:auto;background:var(--surface)}
.ip-mobile-head{display:none;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--surface);z-index:2}
.ip-backdrop{display:none}
.inbox-profile-empty{padding:20px;text-align:center}
.ip-section{padding:14px 16px;border-bottom:1px solid var(--line-soft)}
.ip-avatar{width:64px;height:64px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:24px;margin:0 auto 10px}
.ip-name{text-align:center;font-weight:700;font-size:15px}
.ip-sub{text-align:center;font-size:12px;color:var(--ink-soft);margin-top:2px}
.ip-stat-row{display:flex;gap:8px;margin-top:12px}
.ip-stat{flex:1;text-align:center;background:var(--bg);border-radius:8px;padding:8px 4px}
.ip-stat b{display:block;font-size:16px}
.ip-stat span{font-size:10.5px;color:var(--ink-faint)}
.ip-label{font-size:11px;color:var(--ink-faint);font-weight:600;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center}
.ip-chips{display:flex;flex-wrap:wrap;gap:4px}
.ip-idn{border:1px solid var(--line-soft);border-radius:10px;padding:8px 10px;margin-bottom:6px;cursor:pointer;transition:.12s;background:var(--surface)}
.ip-idn:hover{border-color:var(--line)}
.ip-idn.on{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-soft)}
.ip-idn .r1{display:flex;align-items:center;justify-content:space-between;gap:6px}
.ip-idn .r2{display:flex;gap:8px;font-size:10.5px;color:var(--ink-faint);margin-top:4px;flex-wrap:wrap}
.ip-idn .det{display:none;margin-top:8px;padding-top:8px;border-top:1px dashed var(--line-soft);font-size:11.5px;line-height:1.9}
.ip-idn.on .det{display:block}
.ip-idn .det .k{color:var(--ink-faint)}
.ip-cm{font-size:11.5px;padding:6px 0;border-bottom:1px dashed var(--line-soft)}
.ip-cm:last-child{border-bottom:0}
.ib-hide-sm{}

/* پنجره */
.ib-modal-bg{display:none;position:fixed;inset:0;background:rgba(15,15,30,.45);z-index:80;align-items:center;justify-content:center;padding:16px}
.ib-modal-bg.on{display:flex}
.ib-modal{width:520px;max-width:100%;max-height:88vh;display:flex;flex-direction:column;padding:0}
.ib-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--line)}
.ib-modal-body{padding:14px 16px;overflow-y:auto}
.ib-agent{display:flex;align-items:center;gap:10px;padding:9px 10px;border:1px solid var(--line-soft);border-radius:10px;margin-bottom:6px;cursor:pointer}
.ib-agent:hover{border-color:var(--accent)}
.ib-agent.sel{border-color:var(--accent);background:var(--accent-soft)}
.ib-agent .av{width:30px;height:30px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0}
.ib-stat-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.ib-stat-tbl th,.ib-stat-tbl td{padding:8px 6px;border-bottom:1px solid var(--line-soft);text-align:right;white-space:nowrap}
.ib-stat-tbl th{font-size:11px;color:var(--ink-faint);font-weight:600}
.ib-hist{font-size:12px;padding:6px 0;border-bottom:1px dashed var(--line-soft)}

/* ---- ریسپانسیو ---- */
@media(max-width:1399px){
  .inbox-profile{position:absolute;top:0;bottom:0;left:0;width:330px;max-width:92%;z-index:40;transform:translateX(-105%);transition:transform .22s ease;box-shadow:var(--shadow-md)}
  .inbox-wrap.ip-open .inbox-profile{transform:none}
  .inbox-wrap.ip-open .ip-backdrop{display:block;position:absolute;inset:0;background:rgba(0,0,0,.25);z-index:39}
  .ip-mobile-head{display:flex}
}
@media(max-width:1100px){ .inbox-list{width:290px} }
@media(max-width:900px){ .inbox-list{width:260px} .ib-hide-sm{display:none} }
@media(max-width:760px){
  .inbox-wrap{height:calc(100vh - 170px);height:calc(100dvh - 170px);min-height:420px}
  .inbox-list{width:100%;border-left:none}
  .inbox-thread{display:none;width:100%}
  .inbox-wrap.ib-thread-open .inbox-list{display:none}
  .inbox-wrap.ib-thread-open .inbox-thread{display:flex}
  .ib-back{display:inline-flex}
  .thread-head{padding:10px 12px}
  .thread-head .th-actions .ib-hide-mob{display:none}
  .ib-assignbar{padding:8px 12px}
  .ib-assignbar .sub{display:none}
  .thread-body{padding:12px 10px}
  .t-bubble{max-width:90%}
  .thread-reply{padding:8px 10px}
  .ib-viewhint{display:none}
  .ib-page-head{margin-bottom:10px}
  .ib-page-head p{display:none}
  .ib-page-head .ib-team{width:100%;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}
  .ib-page-head .ib-team > *{flex-shrink:0}
  .ib-viewbar{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;margin-bottom:8px}
  .ib-viewbar > *{flex-shrink:0}
  body.ib-mob-thread .ib-page-head, body.ib-mob-thread .ib-viewbar, body.ib-mob-thread #ibTabs, body.ib-mob-thread #ibSubTabs{display:none!important}
  .inbox-profile{width:100%;max-width:100%}
  .ib-modal-bg{align-items:flex-end;padding:0}
  .ib-modal{border-radius:16px 16px 0 0;max-height:92vh}
}
</style>
