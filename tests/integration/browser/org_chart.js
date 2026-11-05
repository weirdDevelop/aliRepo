// 2.4.0 browser check: admin manages org units in Settings; an operator sees the read-only chart.
// Usage: node org_chart.js <base> <admin> <adminPass> <operator> <operatorPass> <managerUsername>  → one JSON line
const { chromium } = require('playwright');
async function login(page, base, u, p) {
  await page.goto(base + '/index.php?page=login');
  await page.fill('input[name=username]', u);
  await page.fill('input[name=password]', p);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
}
(async () => {
  const [base, admin, apass, op, opass, mgr] = process.argv.slice(2);
  const out = {};
  const browser = await chromium.launch();
  const errors = [];
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 950 } });
    page.on('pageerror', e => errors.push(String(e)));
    await login(page, base, admin, apass);
    await page.goto(base + '/index.php#settings.org');
    await page.waitForSelector('[data-act="org-new"]', { timeout: 15000 });
    // department
    await page.click('[data-act="org-new"]');
    await page.waitForSelector('#ouName');
    await page.fill('#ouName', 'UI Support');
    await page.click('[data-ok]');
    await page.waitForSelector('.org-node', { timeout: 10000 });
    // team under it
    await page.click('[data-act="org-new"]');
    await page.waitForSelector('#ouName');
    await page.fill('#ouName', 'UI Shift A');
    await page.selectOption('#ouType', 'team');
    await page.selectOption('#ouParent', { label: 'UI Support' });
    await page.click('[data-ok]');
    await page.waitForTimeout(800);
    out.nodes = await page.$$eval('.org-node b', els => els.map(e => e.textContent));
    // open the team, add the manager
    await page.locator('.org-node', { hasText: 'UI Shift A' }).locator('[data-act="org-open"]').click();
    await page.waitForSelector('#omUser');
    await page.selectOption('#omUser', { label: mgr });
    await page.selectOption('#omRole', 'manager');
    await page.click('[data-act="org-add"]');
    await page.waitForTimeout(1200);
    out.teamShowsManager = (await page.locator('.org-node', { hasText: 'UI Shift A' }).textContent()).includes(mgr);
    // add the operator as a member — the UI re-opens the unit drawer after each change
    await page.waitForSelector('#omUser');
    await page.selectOption('#omUser', { label: op });
    await page.selectOption('#omRole', 'member');
    await page.click('[data-act="org-add"]');
    await page.waitForTimeout(1200);
    // the parent dropdown must not offer the unit itself or its descendants (cycle guard in UI)
    await page.keyboard.press('Escape').catch(() => {});
    await page.goto(base + '/index.php#settings.org');
    await page.waitForSelector('.org-node');
    await page.locator('.org-node', { hasText: 'UI Support' }).first().locator('[data-act="org-open"]').click();
    await page.waitForSelector('#oeParent');
    out.parentOptions = await page.$$eval('#oeParent option', os => os.map(o => o.textContent));
    await page.keyboard.press('Escape').catch(() => {});
    // import preview opens
    await page.goto(base + '/index.php#settings.org');
    await page.waitForSelector('[data-act="org-import"]');
    await page.click('[data-act="org-import"]');
    await page.waitForSelector('input[name=oiSrc]', { timeout: 10000 });
    out.importSources = await page.$$eval('input[name=oiSrc]', is => is.map(i => i.value));
    // operator: read-only chart page
    const ctx2 = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const p2 = await ctx2.newPage();
    p2.on('pageerror', e => errors.push(String(e)));
    await login(p2, base, op, opass);
    await p2.goto(base + '/index.php#org');
    await p2.waitForSelector('.org-node', { timeout: 15000 });
    const txt = await p2.textContent('.page');
    out.opSeesUnits = txt.includes('UI Support') && txt.includes('UI Shift A');
    out.opSeesManager = txt.includes(mgr);
    out.opSeesManageButtons = await p2.$$eval('[data-act="org-open"]', b => b.length);
    out.opChain = txt.includes('گزارش‌دهی شما') && txt.slice(txt.indexOf('گزارش‌دهی شما')).includes(mgr);
  } catch (e) { out.error = String(e); }
  out.pageErrors = errors;
  console.log(JSON.stringify(out));
  await browser.close();
})();
