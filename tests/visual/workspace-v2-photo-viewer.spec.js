const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const base = 'http://127.0.0.1:4173';
const mediaUrl = '/manager/media-file.php?message_id=42&attachment=2';

async function setup(page, failed = false) {
  // Raster pixels have fixed natural dimensions across engines. WebKit reports
  // SVG naturalWidth using the rendered viewport, so SVG is not a photo fixture.
  const encoded = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 1200; canvas.height = 1600;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#dbeafe'; ctx.fillRect(0, 0, 1200, 1600);
    ctx.fillStyle = '#fff'; ctx.fillRect(80, 80, 1040, 1440);
    ctx.fillStyle = '#173452'; ctx.textAlign = 'center';
    ctx.font = '72px sans-serif'; ctx.fillText('TEST PHOTO', 600, 740);
    ctx.font = '48px sans-serif'; ctx.fillText('1200 x 1600', 600, 850);
    return canvas.toDataURL('image/png').split(',')[1];
  });
  const response = { failed, body: Buffer.from(encoded, 'base64') };
  await page.route('**/manager/media-file.php?**', route => route.fulfill({
    status: response.failed ? 403 : 200, contentType: response.failed ? 'text/plain' : 'image/png',
    headers: { 'Cache-Control': 'no-store' }, body: response.failed ? 'Access denied' : response.body,
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
  if (!failed) await expect.poll(() => page.locator('.attachments img').evaluate(img => img.naturalWidth)).toBe(1200);
  return response;
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
      const fitted = await viewer.locator('img').boundingBox();
      expect(fitted.width).toBeLessThan(width);
      expect(fitted.height).toBeLessThan(844);
      fs.mkdirSync('photo-viewer-artifacts', { recursive: true });
      await page.screenshot({ path: `photo-viewer-artifacts/${browserName}-${width}.png` });
      await viewer.getByRole('button', { name: 'Увеличить' }).tap();
      await expect(viewer.getByRole('button', { name: 'Уместить' })).toHaveAttribute('aria-pressed', 'true');
      await expect.poll(() => viewer.locator('img').evaluate(img => Math.round(img.getBoundingClientRect().width))).toBe(1200);
      await viewer.getByRole('button', { name: 'Уместить' }).tap();
      await expect.poll(() => viewer.locator('img').evaluate(img => img.getBoundingClientRect().width)).toBeLessThan(width);
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
  test('download failure is visible and retry retains the protected URL', async ({ page }) => {
    const response = await setup(page, true);
    await page.getByRole('link', { name: 'Открыть фото', exact: true }).tap();
    const viewer = page.getByRole('dialog', { name: 'Просмотр фото' });
    await expect(viewer).toBeVisible();
    await expect(viewer.getByRole('status')).toContainText('Не удалось загрузить фото');
    await expect(viewer.getByRole('button', { name: 'Повторить' })).toBeVisible();
    response.failed = false;
    await viewer.getByRole('button', { name: 'Повторить' }).tap();
    await expect.poll(() => viewer.locator('img').evaluate(img => img.naturalWidth)).toBe(1200);
    await expect(viewer.locator('img')).toHaveAttribute('src', mediaUrl);
    await expect(viewer.getByRole('button', { name: 'Повторить' })).toBeHidden();
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
  test('navigation and removal of the source attachment close the viewer', async ({ page }) => {
    await setup(page);
    await page.locator('.attachments img').tap();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.evaluate(() => window.dispatchEvent(new PopStateEvent('popstate')));
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.locator('.attachments img').tap();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.evaluate(() => window.WorkspaceV2Conversation.renderMessages([]));
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator('#replyText')).toHaveValue('Несохранённый ответ — не отправлять');
  });
  test('fallback without native dialog follows the original URL in the same tab', async ({ page }) => {
    await setup(page);
    await page.evaluate(() => { HTMLDialogElement.prototype.showModal = undefined; });
    await Promise.all([
      page.waitForURL(base + mediaUrl),
      page.getByRole('link', { name: 'Открыть фото', exact: true }).tap(),
    ]);
    expect(page.context().pages()).toHaveLength(1);
  });
  test('summary media retains the original message URL rather than synthetic zero', async ({ page }) => {
    await setup(page);
    await page.evaluate(url => window.WorkspaceV2Conversation.renderMessages([
      { id: 0, sender_type: 'ai', text: 'Синтетическая сводка', attachments: [{ type: 'image', name: 'Фото', url }] },
    ]), mediaUrl);
    await page.getByRole('link', { name: 'Открыть фото', exact: true }).tap();
    await expect(page.getByRole('dialog').locator('img')).toHaveAttribute('src', mediaUrl);
    await expect.poll(() => page.getByRole('dialog').locator('img').evaluate(img => img.naturalWidth)).toBe(1200);
  });
});
