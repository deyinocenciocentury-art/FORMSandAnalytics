// Run against the isolated WordPress development site with its local mail sink.
// SMF_TEST_URL=http://127.0.0.1:8080/?p=4 node tests/frontend.mjs
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const url = process.env.SMF_TEST_URL || 'http://127.0.0.1:8080/?p=4';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const failures = [];
page.on('pageerror', (error) => failures.push(error.message));
const form = page.locator('.smf-form').first();
const current = () => form.locator('.smf-question:visible');
const next = () => form.locator('.smf-button-next').click();

try {
  await page.goto(url, { waitUntil: 'networkidle' });
  assert.equal(await form.locator('.smf-question').count(), 14, 'supplied survey has fourteen questions');
  assert.equal(await current().count(), 1, 'exactly one question is visible');
  assert.equal(await current().getAttribute('data-question-id'), 'q_01');
  const publicConfiguration = await form.locator('.smf-config').textContent();
  assert.ok(!publicConfiguration.includes('recipients') && !publicConfiguration.includes('@soulmarke.com'), 'notification recipients remain private');
  await form.screenshot({ path: '/tmp/soulmarke-desktop.png' });

  await current().locator('.smf-choice').last().click();
  assert.ok(await current().locator('.smf-other').isVisible(), 'Something else reveals its detail field');
  await next();
  assert.equal(await current().getAttribute('data-question-id'), 'q_01', 'Other detail is required when selected');
  assert.ok(await current().locator('.smf-field-error').isVisible());
  await current().locator('.smf-other-input').fill('Frontend browser test: peer-led partnerships');
  await next();
  assert.equal(await current().getAttribute('data-question-id'), 'q_02');
  await form.locator('.smf-button-back').click();
  assert.equal(await current().locator('.smf-other-input').inputValue(), 'Frontend browser test: peer-led partnerships', 'Back preserves answers');
  await current().locator('.smf-choice').first().click();
  assert.ok(await current().locator('.smf-other').isHidden(), 'deselecting Other hides its field');
  assert.equal(await current().locator('.smf-other-input').inputValue(), '', 'deselecting Other clears stale detail');
  await current().locator('.smf-choice').last().click();
  await current().locator('.smf-other-input').fill('Frontend browser test: peer-led partnerships');
  await next();

  for (let question = 2; question <= 13; question += 1) {
    assert.equal(await current().getAttribute('data-question-id'), `q_${String(question).padStart(2, '0')}`);
    if (question === 10) {
      const choices = current().locator('.smf-choice');
      for (let choice = 0; choice < 4; choice += 1) await choices.nth(choice).click();
      assert.equal(await current().locator('input[type="checkbox"]:checked').count(), 3, 'Q10 enforces its three-choice limit');
      assert.ok(await current().locator('.smf-field-error').isVisible());
      await choices.nth(2).click();
      await choices.last().click();
      await current().locator('.smf-other-input').fill('Frontend browser test: shared learning space');
    } else {
      await current().locator('.smf-choice').first().click();
    }
    await next();
  }
  assert.equal(await current().getAttribute('data-question-id'), 'q_14');
  const comment = current().locator('textarea');
  await comment.fill('Frontend browser test response.');
  await comment.press('End');
  await comment.press('Enter');
  await comment.type('Second line retained.');
  assert.equal(await current().getAttribute('data-question-id'), 'q_14', 'Enter adds a newline in a multiline response');
  assert.ok((await comment.inputValue()).includes('\n'));

  let injectError = true;
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const payload = new URLSearchParams(route.request().postData() || '');
    if (payload.get('action') === 'soulmarke_submit' && injectError) {
      injectError = false;
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Please review the highlighted answer.', errors: { q_01: 'Review your first answer.' } } }) });
    } else await route.continue();
  });
  await form.locator('.smf-button-submit').click();
  await page.waitForFunction(() => document.querySelector('.smf-form .smf-question:not([hidden])')?.dataset.questionId === 'q_01');
  assert.equal(await current().locator('.smf-answer-control').first().isEnabled(), true, 'server validation error restores enabled controls');
  assert.equal(await page.evaluate(() => document.activeElement?.classList.contains('smf-answer-control')), true, 'server validation error focuses its control');
  assert.ok(await current().locator('.smf-field-error').isVisible());
  for (let question = 1; question < 14; question += 1) await next();
  const realSubmission = page.waitForResponse((response) => response.url().includes('/wp-admin/admin-ajax.php') && response.request().postData()?.includes('action=soulmarke_submit'));
  await form.locator('.smf-button-submit').click();
  const response = await realSubmission;
  const result = await response.json();
  assert.equal(result.success, true, `real AJAX submission succeeded: ${JSON.stringify(result)}`);
  await form.locator('.smf-success').waitFor({ state: 'visible' });
  assert.ok(await form.locator('.smf-question-form').isHidden());
  const submitted = new URLSearchParams(response.request().postData());
  const answers = JSON.parse(submitted.get('answers'));
  const details = JSON.parse(submitted.get('other_answers'));
  assert.equal(Object.keys(answers).length, 14, 'all fourteen answers are submitted');
  assert.equal(answers.q_10.length, 3);
  assert.equal(details.q_01, 'Frontend browser test: peer-led partnerships');
  assert.equal(details.q_10, 'Frontend browser test: shared learning space');

  const mobile = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });
  mobile.on('pageerror', (error) => failures.push(error.message));
  await mobile.goto(url, { waitUntil: 'networkidle' });
  assert.equal(await mobile.locator('.smf-question:visible').count(), 1);
  assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'mobile page has no horizontal overflow');
  assert.equal(await mobile.locator('.smf-form').evaluate((element) => element.scrollWidth <= element.clientWidth + 1), true, 'mobile form has no horizontal overflow');
  await mobile.locator('.smf-form').screenshot({ path: '/tmp/soulmarke-mobile.png' });
  assert.deepEqual(failures, [], 'frontend has no uncaught JavaScript errors');
  console.log('PASS: fourteen-question flow, Back, Other validation, max-three choices, multiline Enter, server-error recovery, real submission, privacy, and mobile layout.');
  console.log('Screenshots: /tmp/soulmarke-desktop.png and /tmp/soulmarke-mobile.png');
} finally {
  await browser.close();
}
