// 2.3.0 browser check of the access-role editor (Settings → نقش‌های دسترسی).
// Usage: node access_roles.js <baseUrl> <admin> <adminPass> <operator> <operatorPass>  → one JSON line
const { chromium } = require('playwright');
async function login(page, base, u, p) {
  await page.goto(base + '/index.php?page=login');
  await page.fill('input[name=username]', u);
  await page.fill('input[name=password]', p);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
}
(async () => {
  const [base, admin, apass, op, opass] = process.argv.slice(2);
  const out = {};
  const browser = await chromium.launch();
  const errors = [];
  try {
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    page.on('pageerror', e => errors.push(String(e)));
    await login(page, base, admin, apass);
    await page.goto(base + '/index.php#settings.access');
    await page.waitForSelector('[data-act="acc-new"]', { timeout: 15000 });
    out.emptyState = (await page.textContent('#setBody')).includes('هنوز نقشی ساخته نشده');
    await page.click('[data-act="acc-new"]');
    await page.waitForSelector('#accName');
    await page.fill('#accName', 'ui-templates');
    await page.check('input[name=accKey][value="templates.page.view"]');
    out.adminOnlyDisabled = await page.isDisabled('input[name=accKey][value="settings.page.view"]');
    await page.click('[data-ok]');
    await page.waitForSelector('tr[data-role]', { timeout: 10000 });
    out.roleListed = (await page.textContent('#setBody')).includes('ui-templates');
    // assign to the operator
    const row = page.locator('tr[data-user]', { hasText: op });
    await row.locator('[data-act="acc-user"]').click();
    await page.waitForSelector('input[name=accUR]');
    await page.check('input[name=accUR]');
    await page.click('[data-ok]');
    await page.waitForTimeout(800);
    out.assigned = (await page.locator('tr[data-user]', { hasText: op }).textContent()).includes('ui-templates');
    // effective view
    await page.locator('tr[data-user]', { hasText: op }).locator('[data-act="acc-eff"]').click();
    await page.waitForTimeout(800);
    out.effectiveShowsRole = (await page.textContent('body')).includes('نقش «ui-templates»');
    await page.screenshot({ path: process.env.SHOT || '/dev/null' }).catch(() => {});
    // operator now sees Templates in the navigation
    const ctx2 = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const p2 = await ctx2.newPage();
    p2.on('pageerror', e => errors.push(String(e)));
    await login(p2, base, op, opass);
    await p2.waitForTimeout(1500);
    out.operatorPageAllow = await p2.evaluate(() => (window.D && D.pageAllow) || []);
  } catch (e) { out.error = String(e); }
  out.pageErrors = errors;
  console.log(JSON.stringify(out));
  await browser.close();
})();
