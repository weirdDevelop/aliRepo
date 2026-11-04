/* hscroll.js — نوارهای تب افقی (صفحه‌های کلاسیک): دکمه‌ی چپ/راست، اسکرول با چرخ موس و کشیدن.
   روی هر نواری که از عرضش بیشتر محتوا دارد خودکار فعال می‌شود؛ نیازی به تغییر HTML صفحه‌ها نیست. */
(function () {
  var SEL = '.tabs,.pf-tabs,.an-tabs,.mkt-tabs,[role=tablist],.seg,.ib-seg,.ib-tabs,.ib-subtabs,.th-ids,.ib-team,.ib-viewbar,.igx-topnav,.igx-side,.hscroll';
  var css = '.hsx{scroll-behavior:auto}'
    + '.hsx>.hsx-btn{position:sticky;z-index:3;flex:none!important;align-self:center;width:28px!important;min-width:28px!important;height:28px!important;padding:0!important;margin-top:0!important;margin-bottom:0!important;border-radius:50%!important;border:1px solid var(--line,#ddd)!important;background:var(--surface,#fff)!important;color:var(--ink,#222)!important;box-shadow:0 2px 8px rgba(0,0,0,.14);display:none;align-items:center;justify-content:center;cursor:pointer;font:700 16px/1 system-ui,sans-serif!important}'
    + '.hsx>.hsx-btn:hover{background:var(--surface-2,#f4f4f4)!important}'
    + '.hsx>.hsx-prev{inset-inline-start:0;margin-inline-end:-28px!important;margin-inline-start:0!important}'
    + '.hsx>.hsx-next{inset-inline-end:0;margin-inline-start:-28px!important;margin-inline-end:0!important}'
    + '.hsx.can-prev>.hsx-prev,.hsx.can-next>.hsx-next{display:inline-flex}'
    + '.hsx.dragging{cursor:grabbing;user-select:none}.hsx.dragging *{pointer-events:none}'
    + '@media (pointer:fine){.hsx{scrollbar-width:thin}.hsx::-webkit-scrollbar{display:block!important;height:5px}.hsx::-webkit-scrollbar-thumb{background:var(--line,#ccc);border-radius:4px}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function isRow(el) { var d = getComputedStyle(el).display; return d === 'flex' || d === 'inline-flex'; }
  function update(el) {
    var max = el.scrollWidth - el.clientWidth, x = Math.abs(el.scrollLeft);
    el.classList.toggle('can-prev', max > 2 && x > 2);
    el.classList.toggle('can-next', max > 2 && max - x > 2);
  }
  function mk(el, cls, dir, label, ch) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'hsx-btn ' + cls; b.tabIndex = -1;
      b.setAttribute('aria-label', label); b.textContent = ch;
      b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); el.scrollBy({left: dir * el.clientWidth * 0.7, behavior: 'smooth'}); });
      return b;
  }
  function addButtons(el) {
    var rtl = getComputedStyle(el).direction === 'rtl';
    // در راست‌به‌چپ «قبلی» سمت راست است
    if (!el.querySelector(':scope > .hsx-prev')) el.insertBefore(mk(el, 'hsx-prev', rtl ? 1 : -1, 'قبلی', rtl ? '›' : '‹'), el.firstChild);
    if (!el.querySelector(':scope > .hsx-next')) el.appendChild(mk(el, 'hsx-next', rtl ? -1 : 1, 'بعدی', rtl ? '‹' : '›'));
  }
  function enhance(el) {
    if (el.__hsx) { if (el.scrollWidth > el.clientWidth + 2) addButtons(el); update(el); return; }
    if (!isRow(el) || el.scrollWidth <= el.clientWidth + 2) return;
    el.__hsx = true; el.classList.add('hsx');
    var rtl = getComputedStyle(el).direction === 'rtl';
    addButtons(el);
    el.addEventListener('scroll', function () { update(el); }, {passive: true});
    el.addEventListener('wheel', function (e) {
      if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && el.scrollWidth > el.clientWidth) { e.preventDefault(); el.scrollLeft -= e.deltaY * (rtl ? 1 : -1); }
    }, {passive: false});
    var sx = null, sl = 0, moved = false;
    el.addEventListener('pointerdown', function (e) { if (e.pointerType !== 'mouse' || e.button || e.target.closest('.hsx-btn')) return; sx = e.clientX; sl = el.scrollLeft; moved = false; });
    el.addEventListener('pointermove', function (e) { if (sx === null) return; var dx = e.clientX - sx; if (Math.abs(dx) > 5) { moved = true; el.classList.add('dragging'); el.scrollLeft = sl - dx; } });
    var end = function () { sx = null; setTimeout(function () { el.classList.remove('dragging'); }, 0); };
    el.addEventListener('pointerup', end); el.addEventListener('pointerleave', end);
    el.addEventListener('click', function (e) { if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; } }, true);
    var on = el.querySelector('.on,.active,[aria-selected=true]');
    if (on && on.scrollIntoView) on.scrollIntoView({block: 'nearest', inline: 'nearest'});
    update(el);
  }
  function scan() {
    var list = document.querySelectorAll(SEL);
    for (var i = 0; i < list.length; i++) enhance(list[i]);
  }
  var t = null;
  function soon() { clearTimeout(t); t = setTimeout(scan, 120); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
  window.addEventListener('resize', soon);
  if (window.MutationObserver) new MutationObserver(soon).observe(document.documentElement, {childList: true, subtree: true});
  window.ebHScroll = scan;
})();
