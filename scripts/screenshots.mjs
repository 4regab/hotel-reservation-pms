// Takes screenshots of pages at three widths with a local Chrome. Development aid, not part of the site.
// Usage: node scripts/screenshots.mjs <outDir> [--login email:password[:otpSecretCodeCmd]] path1 path2 ...
// Needs playwright-core (npm i playwright-core somewhere on NODE_PATH) and CHROME_BIN (default /usr/bin/google-chrome).
import { createRequire } from 'node:module';
const require = createRequire(process.env.PW_REQUIRE || import.meta.url);
const { chromium } = require('playwright-core');

const args = process.argv.slice(2);
const out = args.shift();
let login = null;
if (args[0] === '--login') { args.shift(); login = args.shift().split(':'); }
const base = process.env.E2E_BASE || 'http://127.0.0.1:8100';
const widths = [[375, 800, 'mobile'], [768, 900, 'tablet'], [1280, 900, 'desktop']];

const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
for (const [w, h, name] of widths) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h } });
  const page = await ctx.newPage();
  if (login) {
    await page.goto(base + '/login');
    await page.fill('#f-email', login[0]);
    await page.fill('#f-password', login[1]);
    if (login[2]) await page.fill('#f-otp', login[2]);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Sign in"):not(header *)')]);
  }
  for (const p of args) {
    const res = await page.goto(base + p, { waitUntil: 'networkidle' });
    const file = `${out}/${p.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home'}-${name}.png`;
    await page.screenshot({ path: file, fullPage: true });
    // horizontal overflow check: a page should never scroll sideways
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    console.log(`${res.status()} ${name} ${p} overflowX=${overflow}`);
  }
  await ctx.close();
}
await browser.close();
