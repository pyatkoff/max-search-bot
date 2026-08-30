import { test, expect } from '@playwright/test';

const fixture = 'http://127.0.0.1:4173/tests/visual/workspace-v2-fixture.html';
const pipelineAdmin = 'http://127.0.0.1:4173/tests/visual/pipeline-admin-fixture.html';

async function rect(locator) {
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  return box;
}

async function expectNoHorizontalOverflow(page) {
  const width = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth));
  const viewport = page.viewportSize();
  expect(viewport).not.toBeNull();
  expect(width).toBeLessThanOrEqual(viewport.width);
}

test('390px conversation keeps the composer usable and inside the viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(fixture);
  await page.locator('.conversation').click();
  await expectNoHorizontalOverflow(page);
  const composer = await rect(page.locator('.composer'));
  const textarea = await rect(page.locator('.composer textarea'));
  const send = await rect(page.locator('.composer button[type="submit"]'));
  expect(composer.x).toBeGreaterThanOrEqual(0);
  expect(composer.x + composer.width).toBeLessThanOrEqual(390);
  expect(textarea.width).toBeGreaterThanOrEqual(180);
  expect(send.x + send.width).toBeLessThanOrEqual(390);
});

test('390px focused composer hides shortcuts and preserves typing width', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(fixture);
  await page.locator('.conversation').click();
  await page.locator('.composer textarea').focus();
  const shortcuts = page.locator('.composer-shortcuts');
  await expect(shortcuts).toBeHidden();
  const textarea = await rect(page.locator('.composer textarea'));
  expect(textarea.width).toBeGreaterThanOrEqual(220);
});

test('390px chat bubbles and media cannot collapse into unusable cards', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(fixture);
  await page.locator('.conversation').click();
  const bubbles = await page.locator('.message-bubble').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().width));
  expect(Math.min(...bubbles)).toBeGreaterThanOrEqual(180);
  const media = await rect(page.locator('.message-media').first());
  expect(media.width).toBeGreaterThanOrEqual(180);
  expect(media.x + media.width).toBeLessThanOrEqual(390);
});

test('430px mobile lead and conversation surfaces stay viewport-bounded', async ({ page }) => {
  await page.setViewportSize({ width: 430, height: 932 });
  await page.goto(fixture);
  await page.locator('.lead').click();
  await expectNoHorizontalOverflow(page);
  const lead = await rect(page.locator('.lead-panel'));
  expect(lead.width).toBeLessThanOrEqual(430);
  await page.locator('.mobile-back').click();
  await page.locator('.conversation').click();
  const conversation = await rect(page.locator('.conversation-panel'));
  expect(conversation.width).toBeLessThanOrEqual(430);
});

test('1440px desktop keeps three usable zones', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(fixture);
  await expectNoHorizontalOverflow(page);
  const inbox = await rect(page.locator('.inbox-panel'));
  const conversation = await rect(page.locator('.conversation-panel'));
  const lead = await rect(page.locator('.lead-panel'));
  expect(inbox.width).toBeGreaterThanOrEqual(260);
  expect(conversation.width).toBeGreaterThanOrEqual(500);
  expect(lead.width).toBeGreaterThanOrEqual(300);
  expect(inbox.x + inbox.width).toBeLessThanOrEqual(conversation.x + 1);
  expect(conversation.x + conversation.width).toBeLessThanOrEqual(lead.x + 1);
});

test('390px pipeline admin editor remains usable without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(pipelineAdmin);
  await expectNoHorizontalOverflow(page);
  const card = await rect(page.locator('.card').first());
  const editor = await rect(page.locator('.editor'));
  expect(card.width).toBeLessThanOrEqual(390);
  expect(editor.width).toBeGreaterThanOrEqual(320);
  await expect(page.locator('.grid input')).toHaveCount(4);
});

test('1440px pipeline admin uses multi-column editor and bounded content width', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(pipelineAdmin);
  await expectNoHorizontalOverflow(page);
  const wrap = await rect(page.locator('.wrap'));
  const inputs = await page.locator('.grid input').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().width));
  expect(wrap.width).toBeLessThanOrEqual(1180);
  expect(Math.min(...inputs)).toBeGreaterThanOrEqual(200);
});
