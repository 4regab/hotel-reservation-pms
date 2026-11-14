// Drives the guest booking flow in a real browser (Chrome) and takes screenshots at 375, 768 and 1280 px.
// Needs playwright-core (PW_REQUIRE=<path to a package.json next to node_modules with it>) and a running stack.
// Usage: node tests/e2e/browser-flow.mjs <outDir>
import { createRequire } from 'node:module';
const require = createRequire(process.env.PW_REQUIRE || import.meta.url);
const { chromium } = require('playwright-core');

const out = process.argv[2] || '.';
const base = process.env.E2E_BASE || 'http://127.0.0.1:8100';
const widths = [[375, 800, 'mobile'], [768, 900, 'tablet'], [1280, 900, 'desktop']];
const day = (n) => new Date(Date.now() + n * 864e5 + 8 * 36e5).toISOString().slice(0, 10);
const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', args: ['--no-sandbox'] });

for (const [w, h, name] of widths) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  const shot = (n) => page.screenshot({ path: `${out}/flow-${n}-${name}.png`, fullPage: true });
  const email = `flow.${name}.${Date.now()}@example.test`;

  await page.goto(base + '/register');
  await page.fill('#f-fullName', 'Flow Tester');
  await page.fill('#f-email', email);
  await page.fill('#f-phone', '+63 917 000 2222');
  await page.fill('#f-password', 'flow-test-pass-1');
  await page.fill('#f-password2', 'flow-test-pass-1');
  await page.check('#f-terms');
  await Promise.all([page.waitForURL('**/account'), page.click('main button:has-text("Create account")')]);
  await shot('1-account');

  await page.goto(`${base}/search?checkIn=${day(30 + widths.findIndex((x) => x[2] === name))}&checkOut=${day(32 + widths.findIndex((x) => x[2] === name))}&adults=2&rooms=1`);
  await shot('2-results');
  await Promise.all([page.waitForURL('**/book'), page.click('article:has-text("Deluxe") button:has-text("Select")')]);
  await shot('3-details');
  await Promise.all([page.waitForURL('**/book/pay/**'), page.click('button:has-text("Hold room and continue")')]);
  const countdown = await page.textContent('[data-countdown-text]');
  await shot('4-pay');
  await Promise.all([page.waitForURL('**/mock/paymongo/checkout/**'), page.click('button:has-text("securely")')]);
  // The test checkout: a declined card first (back to the pay page, hold still counting), then the success card.
  await page.fill('#cc-number', '4111111111111111');
  await page.fill('#cc-exp', '1230');
  await page.fill('#cc-cvc', '123');
  await page.fill('#cc-name', 'Flow Tester');
  await Promise.all([page.waitForURL('**/book/pay/**'), page.click('[data-card-form] button')]);
  await page.waitForSelector('text=declined');
  await Promise.all([page.waitForURL('**/mock/paymongo/checkout/**'), page.click('button:has-text("securely")')]);
  await page.fill('#cc-number', '4343434343434345');
  await page.fill('#cc-exp', '1230');
  await page.fill('#cc-cvc', '123');
  await page.fill('#cc-name', 'Flow Tester');
  await shot('4b-checkout');
  await Promise.all([page.waitForURL('**/book/confirmation/**'), page.click('[data-card-form] button')]);
  await page.waitForSelector('text=Your booking is confirmed', { timeout: 40000 });
  await shot('5-confirmed');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  console.log(`${name}: countdown started at ${countdown}, confirmed in browser, overflowX=${overflow}, console errors=${errors.length}`);
  if (errors.length) console.log(errors.join('\n'));
  await ctx.close();
}
await browser.close();
