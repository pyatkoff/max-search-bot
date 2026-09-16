const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const base = 'http://127.0.0.1:4173';
const mediaUrl = '/manager/media-file.php?message_id=42&attachment=2';
const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1600"><rect width="1200" height="1600" fill="#dbeafe"/><rect x="80" y="80" width="1040" height="1440" rx="40" fill="#fff"/><text x="600" y="740" text-anchor="middle" font-family="sans-serif" font-size="72" fill="#173452">TEST PHOTO</text><text x="600" y="850" text-anchor="middle" font-family="sans-serif" font-size="48" fill="#173452">1200 x 1600</text></svg>';

async function setup(page, failed = false) {
  await page.route('**/manager/media-file.php?**', route => route.fulfill({
    status: failed ? 403 : 200, contentType: failed ? 'text/plain' : 'image/svg+xml',
    headers: { 'Cache-Control': 'no-store' }, body: failed ? 'Access denied' : svg,
  }));
  await page.goto(base + '/tests/visual/workspace-v2-fixture.html?view=conversation');
  await page.evaluate(() => {
    document.querySelector('.messages').id = 'messages';
    document.querySelector('.composer').id = 'composer';
    document.querySelector('.composer textarea').id = 'replyText';
    window.WorkspaceV2 = {
      S: { current: 8, manager: { id: 1 }, authGeneration: 1, detail: { conversation: { id: 8 } } },
      $: id => document.getElementById(id),
    };
  });
  await page.addScriptTag({ url: base + '/manager/assets/workspace-v2-conversation.js' });
  await page.evaluate(url => window.WorkspaceV2Conversation.renderMessages([
    { id: 42, direction: 'inbound', sender_type: 'customer', text: 'Синтетическое фото для проверки', attachments: [{ type: 'image', name: 'Фото', url }] },
  ]), mediaUrl);
  await page.locator('#replyText').fill('Несохранённый ответ — не отправлять');
  await page.locator('#replyText').blur();
  await page.getByRole('link', { name: 'Открыть фото', exact: true }).scrollIntoViewIfNeeded();
}

for (const width of [390, 430, 768, 1440]) {
  test.describe(`${width}px`, () => {
    test.use({ viewport: { width, height: 844 }, hasTouch: true, isMobile: width < 768 });
    test('photo tap and open link stay in the cabinet, close and retain draft', async ({ page, browserName }) => {
      await setup(page);
      const originalPage = page.url();
      const initialScroll = await page.locator('#messages').evaluate(el => el.scrollTop);
      await page.locator('.attachments img').tap();
      const viewer = page.getByRole('dialog', { name: 'Просмотр фото' });
      await expect(viewer).toBeVisible();
      await expect.poll(() => viewer.locator('img').evaluate(img => img.naturalWidth)).toBe(1200);
      await expect(viewer.locator('img')).toHaveAttribute('src', mediaUrl);
      expect(page.context().pages()).toHaveLength(1);
      expect(page.url()).toBe(originalPage);
      const close = viewer.getByRole('button', { name: 'Закрыть фото' });
      const box = await close.boundingBox();
      expect(box.height).toBeGreaterThanOrEqual(44);
      expect(box.x).toBeGreaterThanOrEqual(0);
      expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
      fs.mkdirSync('photo-viewer-artifacts', { recursive: true });
      await page.screenshot({ path: `photo-viewer-artifacts/${browserName}-${width}.png` });
      await viewer.getByRole('button', { name: 'Увеличить' }).tap();
      await expect(viewer.getByRole('button', { name: 'Уместить' })).toHaveAttribute('aria-pressed', 'true');
      await close.tap();
      await expect(viewer).toHaveCount(0);
      await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ — не отправлять');
      expect(await page.locator('#messages').evaluate(el => el.scrollTop)).toBe(initialScroll);
      await page.getByRole('link', { name: 'Открыть фото', exact: true }).tap();
      await expect(viewer).toBeVisible();
      expect(page.context().pages()).toHaveLength(1);
      await close.tap();
      await expect(viewer).toHaveCount(0);
    });
  });
}

test.describe('viewer safety', () => {
  test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
  test('download failure is visible instead of a silent tap', async ({ page }) => {
    await setup(page, true);
    await page.getByRole('link', { name: 'Открыть фото', exact: true }).tap();
    const viewer = page.getByRole('dialog', { name: 'Просмотр фото' });
    await expect(viewer).toBeVisible();
    await expect(viewer.getByRole('status')).toContainText('Не удалось загрузить фото');
    await expect(viewer.getByRole('button', { name: 'Повторить' })).toBeVisible();
    await viewer.getByRole('button', { name: 'Закрыть фото' }).tap();
    await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ — не отправлять');
  });
  test('auth recovery removes the private viewer and does not send or navigate', async ({ page }) => {
    await setup(page);
    await page.locator('.attachments img').tap();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.evaluate(() => window.WorkspaceV2Conversation.suspendForAuthRecovery());
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(page.context().pages()).toHaveLength(1);
    await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ — не отправлять');
  });
  test('keyboard activation and Escape restore focus', async ({ page }) => {
    await setup(page);
    const preview = page.getByRole('link', { name: 'Увеличить фото', exact: true });
    await preview.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(preview).toBeFocused();
  });
});
