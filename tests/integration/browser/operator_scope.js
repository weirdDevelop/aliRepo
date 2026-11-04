// P-03 browser check: "selected sections only" with nothing ticked must not save (would mean ALL sections).
// Usage: node operator_scope.js <baseUrl> <adminUser> <adminPass>   → prints one JSON line
const { chromium } = require('playwright');
(async () => {
  const [base, user, pass] = process.argv.slice(2);
  const out = {};
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  const errors = []; page.on('pageerror', e => errors.push(String(e)));
  const reqs = []; page.on('request', r => { if (r.url().includes('api=operator_create')) reqs.push(r.url()); });
  try {
    await page.goto(base + '/index.php?page=login');
    await page.fill('input[name=username]', user);
    await page.fill('input[name=password]', pass);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(base + '/index.php#settings.operators');
    await page.waitForSelector('[data-act="op-new"]', { timeout: 15000 });
    await page.click('[data-act="op-new"]');
    await page.waitForSelector('#onUser');
    await page.fill('#onUser', 'trap_user');
    await page.fill('#onPass', 'longenough123');
    await page.click('.seg[data-name=opScope] [data-v=some]');
    for (const c of await page.$$('input[name=opPage]:checked')) await c.uncheck();
    out.checkedAfterUntick = (await page.$$('input[name=opPage]:checked')).length;
    await page.click('[data-ok]');
    await page.waitForTimeout(1200);
    out.toast = await page.evaluate(() => [...document.querySelectorAll('[class*=toast]')].map(t => t.textContent.trim()).filter(Boolean).join(' | '));
    out.createRequests = reqs.length;
    // positive control: tick one section → saves
    await page.check('input[name=opPage]');
    await page.click('[data-ok]');
    await page.waitForTimeout(1500);
    out.createRequestsAfterTick = reqs.length;
    if (process.env.SHOT) await page.screenshot({ path: process.env.SHOT });
  } catch (e) { out.error = String(e); }
  out.pageErrors = errors;
  console.log(JSON.stringify(out));
  await browser.close();
})();
