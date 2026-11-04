<?php /* views/layout.php — اسکلت اصلی پنل */ ?>
<?php
  $brand = class_exists('Brand') ? Brand::all() : ['name'=>'اعلان‌بان','tagline'=>'سیستم ارتباط چندکاناله','logo'=>'','accent'=>'#1d4ed8'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($brand['name']) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<style>
@font-face{font-family:'Vazirmatn';src:url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Regular.woff2') format('woff2');font-weight:400;font-display:swap}
@font-face{font-family:'Vazirmatn';src:url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Medium.woff2') format('woff2');font-weight:500;font-display:swap}
@font-face{font-family:'Vazirmatn';src:url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn-Bold.woff2') format('woff2');font-weight:700;font-display:swap}
:root{
  /* رنگ برند — پالت HSL کامل (با setBrand/Brand::accentCss بازنویسی می‌شود) */
  --brand:#5b3df5; --brand-2:#7c5cff; --brand-ink:#4a2fd6;
  --brand-soft:#efeafe; --brand-glow:rgba(91,61,245,.22);
  --accent:var(--brand); --accent-2:var(--brand-2); --accent-soft:var(--brand-soft); --accent-ink:var(--brand-ink);
  /* پس‌زمینه و سطوح */
  --bg:#eef1f7; --surface:#ffffff; --surface-2:#f6f8fc;
  --line:#e2e6ef; --line-soft:#edf0f7;
  --ink:#141926; --ink-soft:#5a6478; --ink-faint:#9099ab;
  /* سیگنال هوش مصنوعی/اتوماسیون */
  --gold:#c2710c; --gold-2:#e08a1e; --gold-soft:#fdf1df;
  --ok:#0e9d5b; --ok-soft:#e4f6ec; --warn:#c2710c; --warn-soft:#fdf1df;
  --err:#d83a3a; --err-soft:#fdeceb; --info:#2563eb; --info-soft:#e7effe;
  /* رنگ کانال‌ها */
  --ch-bale:#1ba6a0; --ch-telegram:#2aabee; --ch-roubika:#7b3fe4;
  --ch-sms:#0e9d5b; --ch-safir:#0e7490; --ch-email:#c2710c; --ch-voice:#d83a3a;
  --radius:14px; --radius-sm:9px; --radius-lg:20px;
  --shadow-xs:0 1px 2px rgba(20,24,40,.05);
  --shadow:0 3px 10px rgba(20,24,40,.07),0 1px 3px rgba(20,24,40,.05);
  --shadow-md:0 12px 30px rgba(20,24,40,.12);
  --shadow-lg:0 24px 60px rgba(20,24,40,.20);
  /* سازگاری با کلاس‌های قدیمی */
  --accent-soft:var(--brand-soft);
}
html[data-theme="dark"]{
  --bg:#0a0d16; --surface:#141926; --surface-2:#0f131e;
  --line:#232a3a; --line-soft:#1a2030;
  --ink:#e9ecf4; --ink-soft:#9aa3b6; --ink-faint:#646d82;
  --brand-soft:#1d1840; --brand-glow:rgba(124,92,255,.28);
  --accent-soft:var(--brand-soft);
  --gold:#e6a44b; --gold-2:#f3bb62; --gold-soft:#2a2113;
  --ok:#34d399; --ok-soft:#13291f; --warn:#fbbf24; --warn-soft:#2a2113;
  --err:#f87171; --err-soft:#2a1717; --info:#60a5fa; --info-soft:#15233c;
  --shadow-xs:0 1px 2px rgba(0,0,0,.4);
  --shadow:0 3px 12px rgba(0,0,0,.45); --shadow-md:0 12px 34px rgba(0,0,0,.55);
  --shadow-lg:0 26px 64px rgba(0,0,0,.65);
}
*{margin:0;padding:0;box-sizing:border-box}
button{font-family:inherit;cursor:pointer}
svg{display:block}
::selection{background:var(--accent-soft);color:var(--accent-ink)}
*:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
body{font-family:'Vazirmatn',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.75;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
.app{display:grid;grid-template-columns:264px 1fr;min-height:100vh}

/* ===== Sidebar — تجاری، theme-aware (روشن/تیره) ===== */
.side{position:sticky;top:0;height:100vh;display:flex;flex-direction:column;overflow:hidden;z-index:30;
  background:var(--surface);border-left:1px solid var(--line)}
html[data-theme="dark"] .side{background:linear-gradient(195deg,var(--surface) 0%,var(--surface-2) 100%)}
.side::before{content:"";position:absolute;top:-90px;right:-70px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,var(--brand-glow),transparent 70%);pointer-events:none;opacity:.7}
.brand{display:flex;align-items:center;gap:12px;padding:20px 18px 16px;position:relative}
.brand .dot,.brand-logo{width:42px;height:42px;border-radius:13px;flex-shrink:0}
.brand .dot{background:linear-gradient(135deg,var(--brand),var(--brand-2));box-shadow:0 8px 20px -5px var(--brand-glow),inset 0 1px 0 rgba(255,255,255,.3)}
.brand-logo{object-fit:contain}
.brand-txt{min-width:0;display:flex;flex-direction:column;line-height:1.35}
.brand-txt .nm{font-weight:800;font-size:16.5px;letter-spacing:-.3px;white-space:nowrap}
.brand-txt .tg{font-size:11px;color:var(--ink-faint);white-space:nowrap;font-weight:500}
.mobile-brand .brand-logo{width:30px;height:30px}
.nav{flex:1;overflow-y:auto;padding:6px 12px 14px;scrollbar-width:thin}
.nav::-webkit-scrollbar{width:6px}.nav::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px}
.nav-i{display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:11px;color:var(--ink-soft);text-decoration:none;font-size:13.5px;font-weight:500;margin:2px 0;transition:all .14s;position:relative}
.nav-i:hover{background:var(--surface-2);color:var(--ink)}
.nav-i.on{background:linear-gradient(100deg,var(--brand),var(--brand-2));color:#fff;font-weight:600;box-shadow:0 8px 18px -7px var(--brand-glow)}
.nav-i svg{width:18px;height:18px;flex-shrink:0;stroke-width:1.9;opacity:.8}
.nav-i:hover svg,.nav-i.on svg{opacity:1}
.nav-badge{margin-right:auto;background:var(--err,#ef4444);color:#fff;font-size:11px;font-weight:700;min-width:19px;height:19px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;box-shadow:0 2px 6px rgba(216,58,58,.35)}
.nav-i.on .nav-badge{background:rgba(255,255,255,.25)}
.nav-solo{margin-bottom:2px}
.nav-group{margin-top:4px}
.nav-group-head{display:flex;align-items:center;gap:6px;width:100%;padding:14px 11px 6px;background:none;border:none;cursor:pointer;font-family:inherit;font-size:10.5px;font-weight:800;color:var(--ink-faint);letter-spacing:.6px;text-align:right}
.nav-group-head>span:first-child{margin-left:auto}
.nav-group-head .chev{width:14px;height:14px;transition:transform .18s;opacity:.5}
.nav-group.open .nav-group-head .chev{transform:rotate(180deg)}
.nav-group-items{display:none;flex-direction:column;gap:2px;padding-right:8px}
.nav-group.open .nav-group-items{display:flex}
.nav-group-items .nav-i{font-size:13px;padding:9px 13px}
.grp-soon{background:var(--gold-soft);color:var(--gold);font-size:8.5px;font-weight:800;padding:1px 6px;border-radius:5px;letter-spacing:.2px;margin-right:4px}
.side-foot{border-top:1px solid var(--line);padding:12px;position:relative}
.upgrade{background:linear-gradient(135deg,var(--brand-soft),transparent);border:1px solid var(--line);border-radius:13px;padding:14px;margin-bottom:10px}
.upgrade .u-t{font-weight:800;font-size:12.5px;display:flex;align-items:center;gap:6px}
.upgrade .u-d{font-size:11px;color:var(--ink-faint);margin:5px 0 11px;line-height:1.6}
.upgrade .u-b{display:block;text-align:center;background:linear-gradient(100deg,var(--brand),var(--brand-2));color:#fff;font-weight:700;font-size:12px;padding:9px;border-radius:10px;text-decoration:none;box-shadow:0 6px 16px -8px var(--brand-glow)}
.side-foot .who,.who-mini{display:flex;align-items:center;gap:11px;padding:8px 6px;font-size:12.5px}
.who-txt{min-width:0;line-height:1.4}
.who-txt .n{font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.avatar{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;display:grid;place-items:center;font-size:14px;font-weight:800;flex-shrink:0;box-shadow:0 6px 16px -6px var(--brand-glow)}
.role,.who-txt .r{font-size:11px;color:var(--ink-faint)}
.logout{color:var(--err);text-decoration:none;font-size:12px;padding:6px 10px;display:inline-block}

/* ===== Main ===== */
.main{padding:0;max-width:none;min-width:0;display:flex;flex-direction:column;min-height:100vh;background:var(--bg)}
.page-head{margin-bottom:22px;position:relative;padding-right:14px}
.page-head::before{content:"";position:absolute;right:0;top:4px;bottom:4px;width:4px;border-radius:4px;background:linear-gradient(180deg,var(--brand),var(--brand-2))}
.page-head h1{font-size:21px;font-weight:800;letter-spacing:-.01em}
.page-head p{color:var(--ink-soft);font-size:13.5px;margin-top:4px;max-width:600px}

/* ===== Cards / fields / buttons ===== */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
.card-pad{padding:20px}
.card+.card{margin-top:16px}
.card-h{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.card-h .t{font-weight:700;font-size:14.5px}
.card-h .sub{font-size:12px;color:var(--ink-faint)}
.row{display:flex;gap:16px;flex-wrap:wrap}
.col{flex:1;min-width:min(240px,100%)}
/* ===== ریسپانسیو سراسری (دور ۳، مورد ۷) — هیچ صفحه‌ای نباید اسکرول افقی کل صفحه بخورد ===== */
.main,.main-inner,.card,.col{min-width:0}
.mono,code,pre{overflow-wrap:anywhere;word-break:break-word}
pre{white-space:pre-wrap;max-width:100%;overflow-x:auto}
img,video,canvas,svg{max-width:100%}
/* جدول‌های پهن: داخل کارت خودشان اسکرول افقی می‌خورند، نه کل صفحه */
.card:has(> table), .card:has(> .tbl), .card:has(> div > table), .tbl-wrap, .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
/* نوارهای تب: همیشه اسکرول افقی به‌جای شکستن/بیرون‌زدن */
.tabs,.pf-tabs,.an-tabs,.mkt-tabs,[role=tablist]{overflow-x:auto;scrollbar-width:none;max-width:100%}
.tabs::-webkit-scrollbar,.pf-tabs::-webkit-scrollbar,.an-tabs::-webkit-scrollbar,.mkt-tabs::-webkit-scrollbar,[role=tablist]::-webkit-scrollbar{display:none}
.drawer{max-width:100vw!important}
.grid{display:grid;gap:16px}
.g-2{grid-template-columns:repeat(2,1fr)} .g-3{grid-template-columns:repeat(3,1fr)} .g-4{grid-template-columns:repeat(4,1fr)}
label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-soft);margin-bottom:6px}
input,textarea,select,.inp{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:var(--radius-sm);font-family:inherit;font-size:13.5px;background:var(--surface-2);color:var(--ink);transition:.12s}
input:focus,textarea:focus,select:focus,.inp:focus{outline:none;border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--brand-soft)}
textarea{resize:vertical;min-height:84px;line-height:1.7}
.field{margin-bottom:14px}
.btn{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 15px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:.12s}
.btn:hover{background:var(--surface-2)}
.btn-primary{background:linear-gradient(100deg,var(--brand),var(--brand-2));color:#fff;border-color:transparent;box-shadow:0 6px 16px -8px var(--brand-glow)}
.btn-primary:hover{filter:brightness(1.05);background:linear-gradient(100deg,var(--brand),var(--brand-2))}
.btn-ghost{background:var(--surface);border:1px solid var(--line);color:var(--ink)}
.btn-ghost:hover{background:var(--surface-2)}
.btn-gold{background:linear-gradient(100deg,var(--gold),var(--gold-2));color:#fff;border-color:transparent}
.btn-sm{height:32px;padding:0 11px;font-size:12px}
.btn-xs{height:26px;padding:0 8px;font-size:10.5px;border-radius:7px}
.btn-danger-ghost{background:var(--surface);border:1px solid var(--line);color:var(--err)}
.btn-danger-ghost:hover{background:var(--err-soft)}

/* ===== badges / tags (پل سازگاری: tag و badge هر دو) ===== */
.tag,.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:7px;font-size:11.5px;font-weight:600}
.tag{border-radius:20px}
.b-dot{width:6px;height:6px;border-radius:50%;background:currentColor}
.tag-ok,.b-ok{background:var(--ok-soft);color:var(--ok)}
.tag-warn,.b-warn{background:var(--warn-soft);color:var(--warn)}
.tag-err,.b-err{background:var(--err-soft,#fdecea);color:var(--err,#b42318)}
.tag-accent,.b-info{background:var(--accent-soft);color:var(--accent-ink)}
.tag-mute,.b-mut{background:var(--line-soft);color:var(--ink-soft)}
.b-err{background:var(--err-soft);color:var(--err)}
.b-ai{background:var(--gold-soft);color:var(--gold)}
.chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:7px;background:var(--line-soft);color:var(--ink-soft)}
.cdot{width:8px;height:8px;border-radius:50%;display:inline-block;background:var(--ink-faint)}
.ch-bale{background:var(--ch-bale)}.ch-telegram{background:var(--ch-telegram)}.ch-roubika{background:var(--ch-roubika)}
.ch-sms{background:var(--ch-sms)}.ch-safir{background:var(--ch-safir)}.ch-bale_safir{background:var(--ch-safir)}.ch-email{background:var(--ch-email)}.ch-voice{background:var(--ch-voice)}
.ch-instagram{background:#e1306c}.ch-whatsapp{background:#25d366}.ch-linkedin{background:#0a66c2}.ch-twitter{background:#1d9bf0}
.ch-youtube{background:#ff0000}.ch-aparat{background:#ed145b}.ch-article{background:#0e7490}.ch-landing{background:#7c3aed}
.ch-seo{background:#16a34a}.ch-google_ads{background:#fbbc05}.ch-divar{background:#a62626}.ch-sheypoor{background:#f5a623}.ch-reportage{background:#6366f1}

/* ===== tables (پل سازگاری: table و .tbl) ===== */
table,.tbl{width:100%;border-collapse:collapse}
th,.tbl th{text-align:right;font-size:11.5px;font-weight:700;color:var(--ink-faint);padding:10px 14px;border-bottom:1px solid var(--line);white-space:nowrap;letter-spacing:.2px}
td,.tbl td{padding:12px 14px;border-bottom:1px solid var(--line-soft);font-size:13px}
tr:last-child td{border-bottom:none}
tbody tr:hover td,.tbl tbody tr:hover{background:var(--surface-2)}

/* ===== stat cards ===== */
.stat{position:relative;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px;overflow:hidden;box-shadow:var(--shadow-xs)}
.stat::after{content:"";position:absolute;top:0;right:0;left:0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand-2))}
.stat .lab{font-size:12.5px;color:var(--ink-soft);font-weight:600;display:flex;align-items:center;gap:8px}
.stat .num{font-size:26px;font-weight:800;margin-top:8px;letter-spacing:-.5px}
.stat .delta{font-size:11.5px;font-weight:700;margin-top:4px}
.stat .delta.up{color:var(--ok)} .stat .delta.down{color:var(--err)}

/* ===== avatar ===== */
.av,.av-sm{border-radius:11px;background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;display:grid;place-items:center;font-weight:700;flex-shrink:0}
.av{width:38px;height:38px;font-size:13px} .av-sm{width:30px;height:30px;font-size:11px;border-radius:9px}

/* ===== empty / misc ===== */
.empty{text-align:center;padding:46px 20px;color:var(--ink-faint);font-size:13px}
.empty .ico,.empty-state .ico{font-size:36px;opacity:.5;margin-bottom:10px}
.empty .t,.empty-state .t{font-size:15px;font-weight:700;color:var(--ink-soft);margin-bottom:4px}
.empty .d,.empty-state .d{font-size:12.5px;color:var(--ink-faint);margin-bottom:14px}
.empty-state{text-align:center;padding:48px 24px}
.soon-ribbon{display:flex;align-items:center;gap:9px;background:linear-gradient(100deg,var(--gold-soft),transparent);color:var(--gold);border:1px solid color-mix(in srgb,var(--gold) 28%,transparent);border-radius:11px;padding:11px 16px;font-size:12.5px;font-weight:700;margin-bottom:18px}
.demo-banner{display:flex;align-items:center;gap:9px;background:var(--info-soft);color:var(--info);border-radius:11px;padding:10px 14px;font-size:12.5px;font-weight:600;margin-bottom:16px}
.demo-banner svg{width:17px;height:17px;flex-shrink:0}
.note{font-size:12px;color:var(--ink-faint);margin-top:6px;line-height:1.7}
.token-bar{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-top:8px}
.token-bar-label,.token-bar .tl{font-size:12px;color:var(--ink-faint)}
.chip-btn,.cbtn{font-family:inherit;font-size:12px;padding:5px 11px;border:1px solid var(--line);background:var(--line-soft);color:var(--ink);border-radius:20px;cursor:pointer;transition:.12s}
.chip-btn:hover,.cbtn:hover{background:var(--accent-soft);border-color:var(--accent);color:var(--accent-ink)}
.chip-select{font-family:inherit;font-size:12px;padding:5px 9px;border:1px solid var(--line);background:var(--line-soft);color:var(--ink);border-radius:20px;cursor:pointer;max-width:180px;width:auto}
.alert{padding:11px 14px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:16px;display:none}
.alert-ok{background:var(--ok-soft);color:var(--ok)}
.alert-err{background:var(--err-soft);color:var(--err)}
/* همه‌ی پاپ‌آپ‌ها و پیام‌ها سمت چپ صفحه (منو سمت راست است) — با دکمه‌ی بستن */
#notifPopupStack{position:fixed;top:72px;left:16px;right:auto;z-index:9999;display:flex;flex-direction:column;gap:8px;width:340px;max-width:calc(100vw - 32px);pointer-events:none}
.notif-popup{position:relative;pointer-events:auto;background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow-md,0 8px 24px rgba(0,0,0,.15));padding:12px 14px;padding-inline-end:34px;cursor:pointer;text-decoration:none;color:inherit;display:block;animation:notifPopIn .25s ease-out}
.notif-popup .np-close{position:absolute;top:6px;inset-inline-end:6px;width:24px;height:24px;border:0;border-radius:6px;background:transparent;color:var(--ink-soft);font-size:17px;line-height:22px;cursor:pointer;padding:0;font-family:inherit}
.notif-popup .np-close:hover{background:var(--line-soft);color:var(--ink)}
.notif-popup.toast-ok{border-inline-start:4px solid var(--ok)}
.notif-popup.toast-err{border-inline-start:4px solid var(--err)}
.notif-popup.toast-ok .np-body,.notif-popup.toast-err .np-body{font-size:13px;color:var(--ink)}
.notif-popup.is-toast{cursor:default}
.np-dismiss-all{pointer-events:auto;align-self:flex-start;font-family:inherit;font-size:11.5px;border:1px solid var(--line);background:var(--surface);color:var(--ink-soft);border-radius:20px;padding:4px 10px;cursor:pointer;box-shadow:var(--shadow-md,0 4px 12px rgba(0,0,0,.1))}
@media(max-width:700px){#notifPopupStack{top:auto;bottom:16px;left:16px}}
.notif-popup:hover{background:var(--surface-2)}
.notif-popup .np-title{font-size:13px;font-weight:700;margin-bottom:3px}
.notif-popup .np-body{font-size:12px;color:var(--ink-soft)}
.notif-popup.closing{animation:notifPopOut .2s ease-in forwards}
@keyframes notifPopIn{from{opacity:0;transform:translateX(-14px)}to{opacity:1;transform:translateX(0)}}
@keyframes notifPopOut{from{opacity:1}to{opacity:0;transform:translateX(-14px)}}
.sep{height:1px;background:var(--line-soft);margin:18px 0}
.muted{color:var(--ink-soft)}
.mono{font-family:ui-monospace,monospace;direction:ltr;font-size:12px}
.seg{display:inline-flex;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:3px;gap:2px}
.seg button{border:none;background:none;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--ink-soft);padding:6px 13px;border-radius:7px}
.seg button.on{background:var(--surface);color:var(--ink);box-shadow:var(--shadow-xs)}

.mcal{display:grid;grid-template-columns:repeat(7,1fr);gap:7px}
.mcal-dow{text-align:center;font-size:11px;font-weight:700;color:var(--ink-faint);padding:6px 0}
.mcal-cell{min-height:96px;border:1px solid var(--line);border-radius:11px;padding:7px;background:var(--surface);cursor:pointer;display:flex;flex-direction:column;gap:3px;transition:.12s;overflow:hidden}
.mcal-cell:hover{border-color:var(--brand);box-shadow:0 4px 14px -6px var(--brand-glow)}
.mcal-cell.empty-cell{background:transparent;border:1px dashed var(--line-soft);cursor:default}
.mcal-cell.empty-cell:hover{box-shadow:none;border-color:var(--line-soft)}
.mcal-cell.today{border-color:var(--brand);box-shadow:inset 0 0 0 1px var(--brand)}
.mcal-num{font-size:12px;font-weight:800;color:var(--ink-soft);width:24px;height:24px;display:flex;align-items:center;justify-content:center;border-radius:7px;flex-shrink:0}
.mcal-cell.today .mcal-num{background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff}
.mcal-chip{display:flex;align-items:center;gap:5px;font-size:10.5px;background:var(--surface-2);border:1px solid var(--line);border-radius:7px;padding:3px 6px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:.1s}
.mcal-chip:hover{background:var(--brand-soft);border-color:var(--brand)}
.mcal-chip .cdot{width:7px;height:7px;flex-shrink:0}
.mcal-chip-txt{overflow:hidden;text-overflow:ellipsis}
.mcal-more{font-size:9.5px;color:var(--ink-faint);padding-right:2px}
@media(max-width:820px){
  .mcal{gap:4px}
  .mcal-cell{min-height:62px;padding:4px;border-radius:8px}
  .mcal-num{font-size:11px;width:20px;height:20px}
  .mcal-chip{font-size:9px;padding:2px 4px}
  .mcal-chip .cdot{width:6px;height:6px}
}
@media(max-width:480px){
  .mcal-dow{font-size:9px}
  .mcal-cell{min-height:48px}
  .mcal-chip-txt{display:none}
  .mcal-chip{justify-content:center;padding:3px}
}

.mkanban{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;align-items:start}
.seg{overflow-x:auto;max-width:100%;scrollbar-width:none}
.seg::-webkit-scrollbar{display:none}
.seg button{white-space:nowrap;flex-shrink:0}
.mkt-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center;padding:20px}
@media(max-width:1100px){ .mkanban{grid-template-columns:repeat(3,1fr)} }
@media(max-width:820px){
  .mkanban{grid-template-columns:repeat(2,1fr);gap:8px}
  .mkt-modal{padding:0;align-items:stretch;justify-content:stretch}
  .mkt-modal>.card{max-width:none!important;width:100%!important;max-height:100vh!important;border-radius:0!important}
}
@media(max-width:480px){
  .mkanban{grid-template-columns:1fr}
}

/* ===== mobile ===== */
.mobile-bar{display:none;align-items:center;gap:12px;margin-bottom:18px}
.menu-btn{display:grid;place-items:center;width:38px;height:38px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--surface);cursor:pointer;flex-shrink:0}
.menu-btn svg{width:20px;height:20px}
.mobile-brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:15px}
.side-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
.side-backdrop.show{display:block}
@media(max-width:820px){
  .app{grid-template-columns:1fr}
  .side{position:fixed;z-index:50;right:0;top:0;transform:translateX(100%);transition:transform .22s ease;width:248px;box-shadow:var(--shadow-md)}
  .side.open{transform:none}
  .main{padding:0}
  .row{flex-direction:column;gap:12px}
  .col{min-width:0;max-width:none!important}
  .g-2,.g-3,.g-4{grid-template-columns:1fr}
  .page-head h1{font-size:19px}
  table,.tbl{font-size:12px}
  th,td,.tbl th,.tbl td{padding:9px 10px}
  .card-pad{padding:16px}
  .mobile-bar{display:flex}
}
@media(max-width:820px){
  /* iOS: فونت کمتر از ۱۶px روی فیلد باعث زوم خودکار و به‌هم‌ریختن صفحه می‌شود */
  input:not([type=checkbox]):not([type=radio]),select,textarea{font-size:16px}
  .drawer{width:100vw!important}
  /* ستون‌ها در موبایل تمام‌عرض؛ max-width/min-width های inline صفحه‌ها نباید صفحه را پهن کنند */
  .row > .col{width:100%;max-width:100%!important;min-width:0!important;align-self:stretch;flex-basis:auto}
  .col.card,.card:has(table){overflow-x:auto;-webkit-overflow-scrolling:touch}
  .page-head{margin-bottom:14px}
  .page-head p{font-size:12.5px}
  .topbar{padding-inline:12px}
  .main-inner{padding:14px 12px}
}
@media(max-width:480px){
  .btn{padding:0 13px}
  .btn-sm{padding:0 10px}
  .grid{gap:10px}
  .stat .num{font-size:22px}
}
</style>
<?php if (class_exists('Brand')): ?>
<style id="brandAccent"><?= Brand::accentCss() ?></style>
<?php endif; ?>
<?php if (!empty($_GET['legacy'])): ?>
<style id="legacyEmbed">
/* حالت جاسازی در پنل جدید: بدون منو و سربرگ قدیمی، با رنگ‌های تم «کاج و زعفران» */
:root{--brand:#1D5C4D;--brand-2:#2F7A5E;--brand-ink:#134236;--brand-soft:#E3EEE9;--brand-glow:rgba(29,92,77,.22);--accent:#1D5C4D;--accent-2:#2F7A5E;--accent-soft:#E3EEE9;--accent-ink:#134236;
  --bg:#F2F0EA;--surface:#FFFFFF;--surface-2:#F8F7F3;--line:#E2DED3;--line-soft:#ECE9E1;--ink:#16201D;--ink-soft:#47534E;--ink-faint:#7A847F}
html[data-theme="dark"]{--bg:#0B1311;--surface:#111B18;--surface-2:#15211E;--line:#22322D;--line-soft:#1B2925;--ink:#E8EEEA;--ink-soft:#B3C0BA;--ink-faint:#84928C;--brand:#5CC2A2;--brand-2:#5CC2A2;--brand-soft:#16302A;--accent:#5CC2A2;--accent-soft:#16302A;--accent-ink:#9BDCC6}
body.legacy .side,body.legacy .side-backdrop,body.legacy .topbar,body.legacy .mobile-bar,body.legacy .appfoot{display:none!important}
body.legacy .app{display:block}
body.legacy .main-inner{padding:20px 24px 40px;max-width:none}
body{font-family:'Vazirmatn',Tahoma,sans-serif}
.btn-p,.btn.primary,.nav-i.on{background:var(--brand)!important;box-shadow:none!important}
</style>
<?php endif; ?>
</head>
<body<?= !empty($_GET['legacy']) ? ' class="legacy"' : '' ?>>
<script>
/* توابع حیاتی ناوبری — اینجا زود تعریف می‌شوند تا حتی اگر اسکریپت انتهای صفحه
   مشکل داشته باشد یا ناقص آپلود شود، باز/بسته‌شدن منو کار کند. */
function toggleNavGroup(btn){ try{ btn.closest('.nav-group').classList.toggle('open'); }catch(e){} }
</script>
<div class="app">
  <aside class="side">
    <div class="brand">
      <?php if (!empty($brand['logo'])): ?>
        <img class="brand-logo" src="<?= htmlspecialchars($brand['logo']) ?>" alt="logo">
      <?php else: ?>
        <span class="dot"></span>
      <?php endif; ?>
      <span class="brand-txt">
        <span class="nm"><?= htmlspecialchars($brand['name']) ?></span>
        <?php if (!empty($brand['tagline'])): ?><span class="tg"><?= htmlspecialchars($brand['tagline']) ?></span><?php endif; ?>
      </span>
    </div>
    <nav class="nav">
    <?php
      // آیکن هر صفحه
      $icons = [
        'dashboard' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'send'      => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'safir'     => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'sendlog'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/>',
        'queue'     => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'persons'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
        'groups'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'botbuilder'=> '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'forms'     => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'autoreply' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'campaigns' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'scheduled' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'templates' => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'links'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'stats'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'analytics' => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
        'apilog'    => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
        'channels'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'settings'  => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'support'   => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
        'marketing' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'ai'        => '<path d="M12 2l2.4 5.4L20 8l-4 4 1 6-5-3-5 3 1-6-4-4 5.6-.6L12 2z"/>',
        'webchat'   => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'notices'   => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/>',
      ];
      // برچسب هر صفحه
      $labels = [
        'dashboard'=>'داشبورد', 'inbox'=>'صندوق ورودی', 'send'=>'ارسال پیام', 'safir'=>'ارسال با شماره',
        'sendlog'=>'تاریخچه‌ی ارسال', 'queue'=>'صف ارسال', 'persons'=>'بانک مشتری', 'groups'=>'گروه و برچسب',
        'botbuilder'=>'بات‌ساز منو', 'forms'=>'فرم و نظرسنجی', 'autoreply'=>'پاسخ خودکار',
        'campaigns'=>'کمپین', 'scheduled'=>'زمان‌بندی', 'templates'=>'الگوها', 'links'=>'لینک رهگیری',
        'stats'=>'آمار', 'analytics'=>'آنالیتیکس', 'apilog'=>'لاگ سیستم', 'channels'=>'تنظیمات کانال', 'settings'=>'تنظیمات عمومی',
        'support'=>'پشتیبانی تیمی', 'marketing'=>'مارکتینگ و تیم', 'ai'=>'هوش مصنوعی',
        'webchat'=>'گفتگوی آنلاین سایت', 'notices'=>'اعلان سایت', 'instagram'=>'اینستاگرام',
      ];
      // ناوبری دوسطحی: گروه‌بندی منطقی
      $navGroups = [
        'ارتباطات' => ['inbox', 'instagram', 'send', 'safir', 'queue', 'sendlog', 'webchat'],
        'مخاطبان'  => ['persons', 'groups'],
        'اتوماسیون'=> ['botbuilder', 'forms', 'autoreply', 'ai', 'campaigns', 'scheduled'],
        'تیم'      => ['support'],
        'محتوا'    => ['templates', 'links', 'notices'],
        'تحلیل'    => ['stats', 'analytics', 'apilog'],
        'مارکتینگ' => ['marketing'],
        'تنظیمات'  => ['channels', 'settings'],
      ];
      // داشبورد بالای همه (بدون گروه)
      if (Auth::canAccess('dashboard')):
        $on = ($currentPage === 'dashboard') ? ' on' : '';
      ?>
      <a class="nav-i nav-solo<?= $on ?>" href="index.php?page=dashboard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= $icons['dashboard'] ?></svg>
        داشبورد
      </a>
      <?php endif; ?>

      <?php foreach ($navGroups as $groupTitle => $slugs):
        // فقط صفحاتی که اپراتور به آنها دسترسی دارد
        $visible = array_filter($slugs, fn($s) => Auth::canAccess($s));
        if (!$visible) continue;
        // آیا گروه فعلی شامل صفحه‌ی جاری است؟ (برای باز بودن پیش‌فرض)
        $groupActive = in_array($currentPage, $visible, true);
      ?>
      <div class="nav-group<?= $groupActive ? ' open' : '' ?>">
        <button type="button" class="nav-group-head" onclick="toggleNavGroup(this)">
          <span><?= $groupTitle ?></span>
          <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="nav-group-items">
          <?php foreach ($visible as $slug):
            $on = ($currentPage === $slug) ? ' on' : '';
            $badge = '';
            if ($slug === 'inbox' && class_exists('Inbox')) {
                $u = Inbox::unreadCount();
                if ($u > 0) $badge = '<span class="nav-badge" id="inboxBadge">' . ($u > 99 ? '۹۹+' : $u) . '</span>';
            }
          ?>
          <a class="nav-i<?= $on ?>" href="index.php?page=<?= $slug ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= $icons[$slug] ?? '' ?></svg>
            <?= $labels[$slug] ?? $slug ?><?= $badge ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php
        // گروه «به‌زودی» — ماژول‌های در دست توسعه (بدون داده، فقط پیش‌نمایش)
        // نکته: `support` از این گروه خارج شد و به «تیم» رفت، چون واقعاً پیاده شد
        // (پیام‌های آماده + چت داخلی تیم). تخصیص/شیفت/SLA هنوز باقی مانده و در خودِ
        // صفحه یادداشت شده، نه با یک نشان کلی «به‌زودی» روی کل صفحه.
        $soonSlugs = array_filter([], fn($s) => Auth::canAccess($s));
        if ($soonSlugs):
          $soonActive = in_array($currentPage, $soonSlugs, true);
      ?>
      <div class="nav-group<?= $soonActive ? ' open' : '' ?>">
        <button type="button" class="nav-group-head" onclick="toggleNavGroup(this)">
          <span>توسعه <span class="grp-soon">به‌زودی</span></span>
          <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="nav-group-items">
          <?php foreach ($soonSlugs as $slug): $on = ($currentPage === $slug) ? ' on' : ''; ?>
          <a class="nav-i<?= $on ?>" href="index.php?page=<?= $slug ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= $icons[$slug] ?? '' ?></svg>
            <?= $labels[$slug] ?? $slug ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </nav>
    <div class="side-foot">
      <div class="who">
        <span class="avatar"><?= htmlspecialchars(mb_substr(Auth::name(), 0, 1)) ?></span>
        <div class="who-txt">
          <div class="n"><?= htmlspecialchars(Auth::name()) ?></div>
          <div class="r"><?= Auth::roleLabel() ?></div>
        </div>
        <a class="logout" href="index.php?page=logout" title="خروج" style="margin-right:auto">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:17px;height:17px"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </div>
  </aside>
  <div class="side-backdrop" onclick="this.classList.remove('show');document.querySelector('.side').classList.remove('open')"></div>

  <main class="main">
    <div class="mobile-bar">
      <button class="menu-btn" onclick="document.querySelector('.side').classList.toggle('open');document.querySelector('.side-backdrop').classList.toggle('show')" aria-label="منو">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span class="mobile-brand">
        <?php if (!empty($brand['logo'])): ?><img class="brand-logo" src="<?= htmlspecialchars($brand['logo']) ?>" alt=""><?php else: ?><span class="dot"></span><?php endif; ?>
        <?= htmlspecialchars($brand['name']) ?>
      </span>
    </div>
    <header class="topbar">
      <div class="topbar-title" id="topbarTitle"></div>
      <div class="topbar-right">
        <div style="position:relative">
          <button class="topbar-btn" onclick="toggleNotifPanel()" id="notifBtn" title="اعلان‌ها">
            🔔<span id="notifDot" style="display:none;position:absolute;top:6px;inset-inline-end:6px;width:8px;height:8px;border-radius:50%;background:#ef4444"></span>
          </button>
          <div id="notifPanel" class="card" style="display:none;position:absolute;top:calc(100% + 8px);inset-inline-end:0;
               width:320px;max-height:400px;overflow:auto;z-index:50;padding:6px;box-shadow:var(--shadow-md)"></div>
        </div>
        <button class="topbar-btn" onclick="toggleTheme()" id="themeBtnTop" title="تغییر تم">🌙</button>
        <a class="topbar-user" href="index.php?page=profile">
          <span class="avatar-sm"><?= htmlspecialchars(mb_substr(Auth::name(), 0, 1)) ?></span>
          <span class="topbar-uname"><?= htmlspecialchars(Auth::name()) ?></span>
        </a>
        <a class="topbar-btn topbar-logout" href="index.php?page=logout" title="خروج از حساب">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </header>
    <div id="notifPopupStack" aria-live="polite"></div>
    <div class="main-inner">
      <div id="alert" class="alert"></div>
      <?php
        $pageFile = __DIR__ . '/pages/' . $currentPage . '.php';
        if (is_file($pageFile)) {
          try {
            require $pageFile;
          } catch (Throwable $e) {
            echo '<div class="card card-pad" style="border:1px solid var(--err);background:var(--err-soft)">'
               . '<div style="font-weight:700;color:var(--err);margin-bottom:8px">خطا در بارگذاری این صفحه</div>'
               . '<div style="font-size:13px;line-height:1.9">' . htmlspecialchars($e->getMessage()) . '</div>'
               . '<div class="muted" style="font-size:11px;margin-top:8px">' . htmlspecialchars(basename($e->getFile())) . ':' . (int)$e->getLine() . '</div>'
               . '</div>';
          }
        }
        else { echo '<div class="empty">صفحه یافت نشد</div>'; }
      ?>
    </div>
    <footer class="appfoot">
      <span><?= htmlspecialchars($brand['name']) ?><?= !empty($brand['tagline']) ? ' — ' . htmlspecialchars($brand['tagline']) : '' ?></span>
      <span class="appfoot-sep">·</span>
      <span><?= date('Y') ?></span>
    </footer>
  </main>
</div>

<style>
.topbar{display:flex;align-items:center;justify-content:space-between;height:66px;padding:0 24px;border-bottom:1px solid var(--line);background:color-mix(in srgb,var(--surface) 88%,transparent);backdrop-filter:saturate(1.5) blur(12px);position:sticky;top:0;z-index:20}
.topbar-title{font-size:14px;font-weight:700;color:var(--ink)}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-btn{width:38px;height:38px;border-radius:10px;border:1px solid transparent;background:none;display:grid;place-items:center;cursor:pointer;font-size:15px;color:var(--ink-soft);text-decoration:none;transition:.12s}
.topbar-btn:hover{background:var(--surface-2);color:var(--ink);border-color:var(--line)}
.topbar-user{display:flex;align-items:center;gap:8px;padding:4px 10px 4px 4px;border-radius:9px;text-decoration:none;color:var(--ink);transition:.15s}
.topbar-user:hover{background:var(--line-soft)}
.avatar-sm{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700}
.topbar-uname{font-size:13px;font-weight:600}
.main-inner{padding:24px 28px;flex:1;min-width:0}
.appfoot{padding:16px 28px;border-top:1px solid var(--line-soft);color:var(--ink-faint);font-size:12px;display:flex;align-items:center;gap:8px;justify-content:center}
.topbar-btn svg{width:17px;height:17px}
.topbar-logout{color:var(--err)}
.topbar-logout:hover{background:var(--err-soft);color:var(--err);border-color:var(--err-soft)}
.notif-row:hover{background:var(--surface-2)}
.who-mini{display:flex;align-items:center;gap:9px;padding:8px 10px;font-size:12.5px}
.who-mini .role{font-size:11px;color:var(--ink-faint)}
.main{display:flex;flex-direction:column;min-height:100vh}
@media(max-width:760px){.topbar{padding:10px 16px}.main-inner{padding:16px}.topbar-uname{display:none}}
/* دیت‌پیکر شمسی */
.jdp-pop{position:absolute;z-index:9999;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 8px 28px rgba(0,0,0,.16);padding:10px;width:248px;font-size:13px}
.jdp-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-weight:700}
.jdp-head button{background:none;border:none;font-size:20px;cursor:pointer;color:var(--ink-soft);width:28px;height:28px;border-radius:6px;line-height:1}
.jdp-head button:hover{background:var(--bg)}
.jdp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}
.jdp-dow{font-size:10.5px;color:var(--ink-faint);padding:4px 0;font-weight:600}
.jdp-day{padding:6px 0;border-radius:6px;cursor:pointer;transition:.1s}
.jdp-day:hover{background:var(--accent-soft);color:var(--accent-ink)}
.jdp-day.sel{background:var(--accent);color:#fff;font-weight:700}
.jdp-day.today{border:1px solid var(--accent)}
.jdp-foot{display:flex;justify-content:space-between;margin-top:8px;border-top:1px solid var(--line-soft);padding-top:8px}
.jdp-foot button{background:none;border:none;color:var(--accent-ink);cursor:pointer;font-size:12px;padding:4px 8px;border-radius:6px}
.jdp-foot button:hover{background:var(--bg)}
</style>

<script>
const CSRF = <?= json_encode(Csrf::token()) ?>;
// fetch helper برای API
async function api(name, data={}, isForm=false){
  let opts={method:'POST'};
  try { const sid=localStorage.getItem('ebSite'); if(sid) opts.headers={'X-EB-Site':sid}; } catch(e){}   // ۲.۱.۱: سایتِ انتخاب‌شده
  if(isForm){ data.append('_csrf',CSRF); opts.body=data; }
  else { data._csrf=CSRF; opts.headers=Object.assign(opts.headers||{}, {'Content-Type':'application/json'}); opts.body=JSON.stringify(data); }
  try {
    const r=await fetch('index.php?api='+name, opts);
    const text=await r.text();
    try { return JSON.parse(text); }
    catch(e){ return {ok:false, error:'پاسخ نامعتبر سرور: '+text.slice(0,120)}; }
  } catch(e){
    return {ok:false, error:'خطای شبکه: '+e.message};
  }
}
// پیام کوتاه (موفق/خطا) — مثل پاپ‌آپ اعلان‌ها، سمت چپ و با دکمه‌ی ×
function toast(msg, ok=true){
  const stack = document.getElementById('notifPopupStack');
  if(!stack){ alert(msg); return; }
  const el = document.createElement('div');
  el.className = 'notif-popup is-toast ' + (ok ? 'toast-ok' : 'toast-err');
  el.setAttribute('role', ok ? 'status' : 'alert');
  el.innerHTML = `<div class="np-body">${ok ? '✓ ' : '⚠️ '}${esc(String(msg ?? ''))}</div>`;
  popupAttach(el, stack, ok ? 5000 : 9000);
}

/** مشترک بین toast و پاپ‌آپ اعلان: دکمه‌ی ×، توقف تایمر با هاور، حداکثر ۵ تا، دکمه‌ی «بستن همه» */
function popupAttach(el, stack, ttl){
  let timer = null;
  const dismiss = () => {
    if (el.classList.contains('closing')) return;
    clearTimeout(timer);
    el.classList.add('closing');
    setTimeout(() => { el.remove(); popupSyncDismissAll(); }, 200);
  };
  el._dismiss = dismiss;
  const x = document.createElement('button');
  x.type = 'button'; x.className = 'np-close'; x.title = 'بستن'; x.setAttribute('aria-label', 'بستن'); x.textContent = '×';
  x.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); dismiss(); });
  el.appendChild(x);
  const arm = () => { clearTimeout(timer); if (ttl) timer = setTimeout(dismiss, ttl); };
  el.addEventListener('mouseenter', () => clearTimeout(timer));
  el.addEventListener('mouseleave', arm);
  const all = stack.querySelector('.np-dismiss-all');
  all ? stack.insertBefore(el, all) : stack.appendChild(el);
  arm();
  const items = stack.querySelectorAll('.notif-popup:not(.closing)');
  for (let i = 0; i < items.length - 5; i++) items[i].remove();
  popupSyncDismissAll();
  return dismiss;
}
function popupSyncDismissAll(){
  const stack = document.getElementById('notifPopupStack');
  if(!stack) return;
  const n = stack.querySelectorAll('.notif-popup:not(.closing)').length;
  let btn = stack.querySelector('.np-dismiss-all');
  if (n >= 2 && !btn) {
    btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'np-dismiss-all'; btn.textContent = '× بستن همه';
    btn.addEventListener('click', () => stack.querySelectorAll('.notif-popup').forEach(p => p._dismiss ? p._dismiss() : p.remove()));
    stack.appendChild(btn);
  } else if (n < 2 && btn) btn.remove();
}

// ==================== فید اعلان داخل پنل (مورد ۲ و ۵) ====================
// خوانده‌شده کاملاً محلی است (localStorage) — این تیم کوچک است و هرکس مرورگر
// خودش را دارد؛ نیازی به وضعیت per-user سمت سرور نبود (نکته‌ی طراحی در Notifier.php).
let NOTIF_LAST_SEEN_ID = parseInt(localStorage.getItem('eb_notif_last_seen') || '0', 10);
let NOTIF_ITEMS = []; // آخرین‌ها برای نمایش در پنل (حداکثر ۲۰ تا نگه داشته می‌شود)
let NOTIF_SOUND_CTX = null;

function notifBeep(){
  try {
    NOTIF_SOUND_CTX = NOTIF_SOUND_CTX || new (window.AudioContext || window.webkitAudioContext)();
    const ctx = NOTIF_SOUND_CTX;
    const o = ctx.createOscillator(), g = ctx.createGain();
    o.type = 'sine'; o.frequency.value = 880;
    g.gain.setValueAtTime(0.0001, ctx.currentTime);
    g.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
    o.connect(g); g.connect(ctx.destination);
    o.start(); o.stop(ctx.currentTime + 0.36);
  } catch(e) { /* مرورگر صدا را نمی‌گذارد بدون تعامل کاربر — بی‌صدا رد شو */ }
}

function notifUpdateDot(){
  const dot = document.getElementById('notifDot');
  if(dot) dot.style.display = NOTIF_ITEMS.length ? '' : 'none';
}

// پاپ‌آپ واقعیِ داخل صفحه — چیزی که قبلاً نبود: تا حالا اعلان یا داخل یک پنل کشویی (نیاز به
// کلیک زنگوله) بود، یا نوتیفِ مرورگر (فقط اگر کاربر اجازه داده باشد و گاهی حتی وقتی تب باز
// است دیده نمی‌شود، بسته به مرورگر/سیستم‌عامل). این یکی همیشه، همین داخل صفحه، دیده می‌شود.
function showNotifPopup(n){
  const stack = document.getElementById('notifPopupStack');
  if(!stack) return;
  const icon = {message:'💬', form:'📋'}[n.type] || '🔔';
  const el = document.createElement('a');
  el.className = 'notif-popup';
  el.href = n.link || '#';
  el.innerHTML = `<div class="np-title">${icon} ${esc(n.title)}</div>${n.body ? `<div class="np-body">${esc(n.body)}</div>` : ''}`;
  // ۱۰ ثانیه خودکار بسته می‌شود (با هاور موس متوقف می‌شود)؛ دکمه‌ی × هم دارد
  const dismiss = popupAttach(el, stack, 10000);
  el.addEventListener('click', function(e){
    if (!n.link) e.preventDefault();
    dismiss();
  });
}

function renderNotifPanel(){
  const box = document.getElementById('notifPanel');
  if(!box) return;
  if(!NOTIF_ITEMS.length){
    box.innerHTML = '<div class="muted" style="padding:14px;text-align:center;font-size:12.5px">اعلانی نیست</div>';
    return;
  }
  const icon = {message:'💬', form:'📋'};
  box.innerHTML = NOTIF_ITEMS.map(n => `
    <a href="${esc(n.link || '#')}" style="display:block;padding:9px 10px;border-radius:8px;text-decoration:none;color:inherit" class="notif-row">
      <div style="font-size:12.5px;font-weight:600">${icon[n.type]||'🔔'} ${esc(n.title)}</div>
      ${n.body ? `<div class="muted" style="font-size:11.5px;margin-top:2px">${esc(n.body)}</div>` : ''}
      <div class="muted" style="font-size:10px;margin-top:2px">${toFa(n.created_at)}</div>
    </a>`).join('');
}

function toggleNotifPanel(){
  const box = document.getElementById('notifPanel');
  if(!box) return;
  const willShow = box.style.display === 'none';
  box.style.display = willShow ? '' : 'none';
  if(willShow){
    // باز کردن پنل یعنی دید — نقطه‌ی قرمز پاک شود (لیست برای مرور می‌ماند)
    if(NOTIF_ITEMS.length){
      localStorage.setItem('eb_notif_last_seen', String(NOTIF_ITEMS[NOTIF_ITEMS.length-1].id));
    }
    document.getElementById('notifDot').style.display = 'none';
  }
}
document.addEventListener('click', function(e){
  const box = document.getElementById('notifPanel');
  if(!box || box.style.display === 'none') return;
  if(!e.target.closest('#notifPanel') && e.target.id !== 'notifBtn' && !e.target.closest('#notifBtn')){
    box.style.display = 'none';
  }
});

async function notifPoll(){
  try {
    const d = await api('notifications_poll', {after: NOTIF_LAST_SEEN_ID || 0});
    if(!d.ok) return;
    if(NOTIF_LAST_SEEN_ID === 0){
      // اولین poll این تب: فقط نقطه‌ی شروع را ثبت کن، سیل اعلان قدیمی نده
      NOTIF_LAST_SEEN_ID = d.latest_id || 0;
      localStorage.setItem('eb_notif_last_seen', String(NOTIF_LAST_SEEN_ID));
      return;
    }
    if(d.items && d.items.length){
      NOTIF_ITEMS = [...NOTIF_ITEMS, ...d.items].slice(-20);
      NOTIF_LAST_SEEN_ID = d.latest_id || NOTIF_LAST_SEEN_ID;
      // اعلانی که یک بار نشان داده شد، با رفتن به صفحه‌ی دیگر دوباره نمی‌آید
      try { localStorage.setItem('eb_notif_last_seen', String(NOTIF_LAST_SEEN_ID)); } catch(e) {}
      notifBeep();
      renderNotifPanel();
      notifUpdateDot();
      d.items.forEach(n => showNotifPopup(n));
      if(window.Notification && Notification.permission === 'granted'){
        d.items.forEach(n => {
          try {
            const browserNotif = new Notification(n.title, {body: n.body || '', tag: 'eb-notif-'+n.id});
            browserNotif.onclick = function(){ window.focus(); if(n.link) location.href = n.link; };
          } catch(e) { /* بی‌اثر */ }
        });
      }
      // اگر صندوق ورودی باز است، فهرست گفتگوها را هم تازه کن
      if(typeof loadConvos === 'function' && document.getElementById('convoRows')) loadConvos(IB_PAGE || 1);
    }
  } catch(e) { /* شبکه موقت قطع بود؛ poll بعدی جبران می‌کند */ }
}

// اجازه‌ی اعلان مرورگر فقط با یک تعامل کاربر قابل درخواست است؛ اولین کلیک روی
// زنگوله بهانه‌ی خوبی است، بدون این‌که یک popup مزاحمِ جدا نشان بدهیم.
document.addEventListener('DOMContentLoaded', function(){
  const btn = document.getElementById('notifBtn');
  if(btn && window.Notification && Notification.permission === 'default'){
    btn.addEventListener('click', function once(){
      Notification.requestPermission();
      btn.removeEventListener('click', once);
    }, {once:true});
  }
});
// داخل پنل جدید (قاب)، اعلان‌ها را خود پنل نشان می‌دهد؛ اینجا دوباره نشان داده نشوند
const EB_EMBEDDED = (function(){ try { return window.self !== window.top; } catch(e) { return true; } })();
if (!EB_EMBEDDED) { setInterval(notifPoll, 12000); notifPoll(); }
function esc(s){return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
// (toggleNavGroup بالای body تعریف شده)
// تم تاریک/روشن
function applyTheme(t){
  document.documentElement.setAttribute('data-theme', t);
  const b=document.getElementById('themeBtn');
  if(b) b.textContent = t==='dark' ? '☀️ حالت روشن' : '🌙 حالت تاریک';
  const bt=document.getElementById('themeBtnTop');
  if(bt) bt.textContent = t==='dark' ? '☀️' : '🌙';
}
function toggleTheme(){
  const cur=document.documentElement.getAttribute('data-theme')==='dark'?'dark':'light';
  const next=cur==='dark'?'light':'dark';
  try{ localStorage.setItem('eb_theme', next); }catch(e){}
  applyTheme(next);
}
(function(){ let t='light'; try{ t=localStorage.getItem('eb_theme')||'light'; }catch(e){} applyTheme(t); })();
// تبدیل HEX به HSL برای پیش‌نمایش زنده‌ی رنگ برند
function hexToHsl(hex){
  hex=String(hex||'').replace('#',''); if(hex.length===3) hex=hex.split('').map(c=>c+c).join('');
  let r=parseInt(hex.slice(0,2),16)/255, g=parseInt(hex.slice(2,4),16)/255, b=parseInt(hex.slice(4,6),16)/255;
  let mx=Math.max(r,g,b), mn=Math.min(r,g,b), h=0, s=0, l=(mx+mn)/2;
  if(mx!==mn){ let d=mx-mn; s=l>.5?d/(2-mx-mn):d/(mx+mn);
    if(mx===r) h=(g-b)/d+(g<b?6:0); else if(mx===g) h=(b-r)/d+2; else h=(r-g)/d+4; h/=6; }
  return [Math.round(h*360), Math.round(s*100), Math.round(l*100)];
}
// پیش‌نمایش زنده‌ی رنگ برند (ذخیره‌ی دائمی از طریق api در صفحه‌ی تنظیمات انجام می‌شود)
function setBrand(hex){
  const [h,s]=hexToHsl(hex);
  const isDark=document.documentElement.getAttribute('data-theme')==='dark';
  const css=`:root{--brand:hsl(${h} ${s}% ${isDark?62:55}%);--brand-2:hsl(${h} ${Math.min(s+6,100)}% ${isDark?70:64}%);`
    +`--brand-ink:hsl(${h} ${s}% ${isDark?70:44}%);--brand-soft:hsl(${h} ${Math.min(s,80)}% ${isDark?16:95}%);`
    +`--brand-glow:hsla(${h} ${s}% 55% / .28);--accent-ink:var(--brand-ink);}`;
  let tag=document.getElementById('brandVars');
  if(!tag){ tag=document.createElement('style'); tag.id='brandVars'; document.head.appendChild(tag); }
  tag.textContent=css;
  document.querySelectorAll('[data-swatch]').forEach(el=>el.style.borderColor=(el.dataset.swatch===hex?'var(--ink)':'transparent'));
}
// عنوان صفحه‌ی فعلی در هدر
(function(){
  var titles=<?= json_encode($labels + ['dashboard'=>'داشبورد'], JSON_UNESCAPED_UNICODE) ?>;
  var cur=<?= json_encode($currentPage) ?>;
  var el=document.getElementById('topbarTitle');
  if(el) el.textContent = titles[cur] || '';
})();
// helper حالت خالی: emptyState('🔍','چیزی نیست','توضیح', '<button…>')
function emptyState(ico, title, desc, action){
  return `<div class="empty-state"><div class="ico">${ico||'📭'}</div><div class="t">${esc(title||'')}</div>${desc?`<div class="d">${esc(desc)}</div>`:''}${action||''}</div>`;
}

// ---- توکن‌های شخصی‌سازی (نام، موبایل، لینک رهگیری) ----
// درج رشته در محل cursor یک textarea/input
function insertToken(elId, token){
  var el = document.getElementById(elId);
  if(!el) return;
  var s = el.selectionStart, e = el.selectionEnd, v = el.value;
  if(s==null){ el.value = v + token; }
  else { el.value = v.slice(0,s) + token + v.slice(e); el.selectionStart = el.selectionEnd = s + token.length; }
  el.focus();
}
// بارگذاری لینک‌های رهگیری در یک <select> (کش سراسری در همین صفحه)
var _trackLinks = null;
async function loadTrackLinks(selId){
  var sel = document.getElementById(selId);
  if(!sel) return;
  if(_trackLinks === null){
    try { var r = await api('links_list'); _trackLinks = (r && r.links) ? r.links : []; }
    catch(e){ _trackLinks = []; }
  }
  // گزینه‌ی اول (placeholder) حفظ، بقیه ساخته شوند
  var first = sel.options[0] ? sel.options[0].outerHTML : '<option value="">🔗 لینک رهگیری…</option>';
  if(!_trackLinks.length){
    sel.innerHTML = first + '<option value="" disabled>— لینکی ساخته نشده —</option>';
    return;
  }
  sel.innerHTML = first + _trackLinks.map(function(l){
    var lbl = (l.title && l.title.trim()) ? l.title
            : (l.kind === 'tel' ? (l.tel || l.target_url) : l.target_url);
    if(l.category) lbl = l.category + ' › ' + lbl;
    if(l.kind === 'tel') lbl = '📞 ' + lbl;
    return '<option value="'+esc(l.code)+'">'+esc(lbl.slice(0,40))+'</option>';
  }).join('');
}
// درج توکن لینک رهگیری {link:CODE} و ریست select
function insertTrackLink(targetId, selId){
  var sel = document.getElementById(selId);
  if(!sel || !sel.value) return;
  insertToken(targetId, '{link:'+sel.value+'}');
  sel.value = '';
}
// تبدیل تاریخ میلادی (رشته‌ی YYYY-MM-DD یا با ساعت) به شمسی
function g2j(gy,gm,gd){
  var gdm=[0,31,59,90,120,151,181,212,243,273,304,334],jy;
  if(gy>1600){jy=979;gy-=1600;}else{jy=0;gy-=621;}
  var gy2=(gm>2)?(gy+1):gy;
  var days=(365*gy)+(Math.floor((gy2+3)/4))-(Math.floor((gy2+99)/100))+(Math.floor((gy2+399)/400))-80+gd+gdm[gm-1];
  jy+=33*Math.floor(days/12053);days%=12053;
  jy+=4*Math.floor(days/1461);days%=1461;
  if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}
  var jm,jd;
  if(days<186){jm=1+Math.floor(days/31);jd=1+(days%31);}
  else{jm=7+Math.floor((days-186)/30);jd=1+((days-186)%30);}
  return [jy,jm,jd];
}
function toFa(dt){
  if(!dt) return '';
  // قبول: "2026-06-07 14:30:00" یا "2026-06-07T14:30" یا "2026-06-07"
  var m=String(dt).match(/(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if(!m) return esc(dt);
  var j=g2j(+m[1],+m[2],+m[3]);
  var p=n=>String(n).padStart(2,'0');
  var out=j[0]+'/'+p(j[1])+'/'+p(j[2]);
  if(m[4]) out+=' '+m[4]+':'+m[5];
  return out;
}
// شمسی → میلادی (معکوس g2j). ورودی: jy,jm,jd. خروجی: [gy,gm,gd]
function j2g(jy,jm,jd){
  var gy;
  if(jy>979){gy=1600;jy-=979;}else{gy=621;}
  var days=(365*jy)+(Math.floor(jy/33)*8)+Math.floor(((jy%33)+3)/4)+78+jd+((jm<7)?(jm-1)*31:((jm-7)*30)+186);
  gy+=400*Math.floor(days/146097);days%=146097;
  if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
  gy+=4*Math.floor(days/1461);days%=1461;
  if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
  var gd=days+1, sal_a=[0,31,((gy%4===0&&gy%100!==0)||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31],gm;
  for(gm=0;gm<13;gm++){var v=sal_a[gm];if(gd<=v)break;gd-=v;}
  return [gy,gm,gd];
}
// تبدیل ارقام فارسی/عربی به انگلیسی (برای ورودی کاربر)
function enNum(s){ return String(s==null?'':s).replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }
function faNum(s){ return String(s==null?'':s).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]); }

/* ===== دیت‌پیکر شمسی سبک (بدون کتابخانه) =====
   استفاده: یک <input data-jdate id="x"> بساز و jdpInit() را صدا بزن.
   مقدار واقعی (میلادی YYYY-MM-DD) در input.dataset.value ذخیره می‌شود؛
   متن نمایشی شمسی است. getJDate(id) مقدار میلادی را برمی‌گرداند ('' اگر خالی). */
var JDP_MONTHS=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
var JDP_DOW=['ش','ی','د','س','چ','پ','ج'];
function jToday(){ var d=new Date(); return g2j(d.getFullYear(),d.getMonth()+1,d.getDate()); }
function getJDate(id){ var el=document.getElementById(id); return (el&&el.dataset.value)?el.dataset.value:''; }
function setJDate(id, greg){ // greg: 'YYYY-MM-DD' یا ''
  var el=document.getElementById(id); if(!el) return;
  if(!greg){ el.dataset.value=''; el.value=''; return; }
  var m=String(greg).match(/(\d{4})-(\d{2})-(\d{2})/); if(!m) return;
  var j=g2j(+m[1],+m[2],+m[3]);
  el.dataset.value=greg;
  el.value=faNum(j[0]+'/'+String(j[1]).padStart(2,'0')+'/'+String(j[2]).padStart(2,'0'));
}
function jdpInit(){
  document.querySelectorAll('input[data-jdate]').forEach(function(inp){
    if(inp._jdpBound) return; inp._jdpBound=true;
    inp.readOnly=true; inp.style.cursor='pointer'; inp.placeholder=inp.placeholder||'انتخاب تاریخ';
    inp.addEventListener('click',function(e){ jdpOpen(inp); e.stopPropagation(); });
  });
}
var _jdpPop=null, _jdpInput=null, _jdpY=0, _jdpM=0;
function jdpOpen(inp){
  jdpClose();
  _jdpInput=inp;
  var cur=inp.dataset.value? g2j.apply(null, inp.dataset.value.split('-').map(Number)) : jToday();
  _jdpY=cur[0]; _jdpM=cur[1];
  _jdpPop=document.createElement('div'); _jdpPop.className='jdp-pop';
  document.body.appendChild(_jdpPop);
  var r=inp.getBoundingClientRect();
  _jdpPop.style.top=(r.bottom+window.scrollY+4)+'px';
  _jdpPop.style.left=(r.left+window.scrollX)+'px';
  jdpRender();
  setTimeout(function(){ document.addEventListener('click', jdpOutside); },0);
}
function jdpOutside(e){ if(_jdpPop && !_jdpPop.contains(e.target) && e.target!==_jdpInput) jdpClose(); }
function jdpClose(){ if(_jdpPop){ _jdpPop.remove(); _jdpPop=null; document.removeEventListener('click', jdpOutside); } }
function jdpRender(){
  if(_jdpM<1){_jdpM=12;_jdpY--;} if(_jdpM>12){_jdpM=1;_jdpY++;}
  // طول ماه شمسی: ۱-۶ → ۳۱، ۷-۱۱ → ۳۰، ۱۲ → ۲۹/۳۰
  var len = _jdpM<=6?31:(_jdpM<=11?30:(jdpIsLeap(_jdpY)?30:29));
  // روز هفته‌ی اول ماه
  var g=j2g(_jdpY,_jdpM,1); var jsDow=new Date(g[0],g[1]-1,g[2]).getDay(); // 0=یکشنبه
  var startCol=(jsDow+1)%7; // ستون شنبه‌محور
  var sel = _jdpInput.dataset.value || '';
  var todayG=new Date(), todayStr=todayG.getFullYear()+'-'+String(todayG.getMonth()+1).padStart(2,'0')+'-'+String(todayG.getDate()).padStart(2,'0');
  var h='<div class="jdp-head"><button type="button" onclick="jdpNav(1)">‹</button>'
    +'<span>'+JDP_MONTHS[_jdpM-1]+' '+faNum(_jdpY)+'</span>'
    +'<button type="button" onclick="jdpNav(-1)">›</button></div>';
  h+='<div class="jdp-grid">';
  JDP_DOW.forEach(function(d){ h+='<div class="jdp-dow">'+d+'</div>'; });
  for(var i=0;i<startCol;i++) h+='<div></div>';
  for(var d=1;d<=len;d++){
    var gg=j2g(_jdpY,_jdpM,d);
    var gs=gg[0]+'-'+String(gg[1]).padStart(2,'0')+'-'+String(gg[2]).padStart(2,'0');
    var cls='jdp-day'+(gs===sel?' sel':'')+(gs===todayStr?' today':'');
    h+='<div class="'+cls+'" onclick="jdpPick(\''+gs+'\')">'+faNum(d)+'</div>';
  }
  h+='</div><div class="jdp-foot"><button type="button" onclick="jdpPick(\'\')">پاک‌کردن</button>'
    +'<button type="button" onclick="jdpToday()">امروز</button></div>';
  _jdpPop.innerHTML=h;
}
function jdpIsLeap(jy){ var r=jy%33; return [1,5,9,13,17,22,26,30].indexOf(r)!==-1; }
function jdpNav(dir){ _jdpM+=dir; jdpRender(); }
function jdpToday(){ var t=jToday(); _jdpY=t[0]; _jdpM=t[1]; jdpRender(); }
function jdpPick(greg){
  var inp=_jdpInput;
  if(greg){ setJDate(inp.id, greg); } else { inp.dataset.value=''; inp.value=''; }
  jdpClose();
  if(inp.getAttribute('data-onpick')){ try{ eval(inp.getAttribute('data-onpick')); }catch(e){} }
}
// راه‌اندازی خودکار دیت‌پیکرها (هم حالا، هم بعد از لود اسکریپت صفحه)
jdpInit();
document.addEventListener('DOMContentLoaded', jdpInit);

</script>
<?php
  $jsFile = __DIR__ . '/pages/' . $currentPage . '.js.php';
  if (is_file($jsFile)) { echo '<script>'; require $jsFile; echo '</script>'; }
?>
<?php if (!empty($_GET['legacy'])): ?>
<script>
/* حالت جاسازی: تم پنل والد، و ناوبری بین صفحه‌ها از طریق پنل جدید */
(function(){
  try{ var t=parent.document.documentElement.getAttribute('data-theme'); if(!t){ t=parent.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'; } document.documentElement.setAttribute('data-theme',t);}catch(e){}
  document.addEventListener('click',function(e){
    var a=e.target.closest('a[href]'); if(!a) return;
    var m=(a.getAttribute('href')||'').match(/index\.php\?page=([a-z_]+)/); if(!m || a.target==='_blank') return;
    e.preventDefault(); try{ parent.postMessage({eb:'go', page:m[1]}, location.origin); }catch(err){ location.href=a.href+'&legacy=1'; }
  }, true);
})();
</script>
<?php endif; ?>
<script src="assets/hscroll.js?v=<?= rawurlencode(ELB_VERSION) ?>"></script>
</body>
</html>
