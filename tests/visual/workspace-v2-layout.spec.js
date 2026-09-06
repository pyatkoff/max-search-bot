const { test, expect } = require('@playwright/test');

const base = 'http://127.0.0.1:4173/tests/visual/workspace-v2-fixture.html';
const pipelineAdmin = 'http://127.0.0.1:4173/tests/visual/pipeline-admin-fixture.html';

test('conversation renders bot headings without interpreting message HTML', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + '?view=conversation');
  await page.evaluate(() => {
    document.querySelector('.messages').id = 'messages';
    window.WorkspaceV2 = { S: {}, $: id => document.getElementById(id) };
  });
  await page.addScriptTag({ url: 'http://127.0.0.1:4173/manager/assets/workspace-v2-conversation.js' });
  await page.evaluate(() => window.WorkspaceV2Conversation.renderMessages([
    { sender_type: 'ai', text: '📱 <b>Менеджер пока не успел ответить</b>\nМожно продолжить ждать.' },
    { sender_type: 'customer', text: '<b>Мой текст</b>' },
    { sender_type: 'ai', text: '<img src=x onerror="window.messageHtmlExecuted=true">' },
  ]));
  await expect(page.locator('.msg.ai strong')).toHaveText('Менеджер пока не успел ответить');
  await expect(page.locator('.msg.customer .msgBody')).toHaveText('<b>Мой текст</b>');
  await expect(page.locator('.msgBody img, .msgBody script')).toHaveCount(0);
  expect(await page.evaluate(() => window.messageHtmlExecuted)).toBeUndefined();
  await expectNoHorizontalOverflow(page);
});

async function rect(locator) {
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  return box;
}

async function expectNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(() => ({
    viewport: window.innerWidth,
    root: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(overflow.root).toBeLessThanOrEqual(overflow.viewport + 1);
  expect(overflow.body).toBeLessThanOrEqual(overflow.viewport + 1);
}

for (const width of [390, 430, 768]) {
  test(`${width}px reply stays reachable with expanded mobile browser controls`, async ({ page }) => {
    const visibleHeight = 650;
    const largeHeight = 800;
    await page.setViewportSize({ width, height: visibleHeight });
    // Headless browsers equate vh and dvh. Model expanded browser controls by
    // resolving legacy vh against the large viewport; leave dvh and all layout
    // declarations intact so production min/max-height interactions are tested.
    await page.route('**/manager/assets/*.css', async route => {
      const response = await route.fetch();
      const body = (await response.text()).replace(/\b(\d+(?:\.\d+)?)vh\b/g,
        (_, value) => `${Number(value) * largeHeight / 100}px`);
      await route.fulfill({ response, body });
    });
    await page.goto(base + '?view=conversation&stress=chat');
    await page.evaluate(() => document.body.classList.add('workspaceMobileDetail'));
    for (const height of [visibleHeight, 550]) {
      await page.setViewportSize({ width, height });
      const zone = await rect(page.locator('.conversationZone'));
      const composer = await rect(page.locator('.composer'));
      const send = page.locator('.composer .sendBtn');
      const sendBox = await rect(send);
      expect(zone.y + zone.height).toBeLessThanOrEqual(height + 1);
      expect(composer.y).toBeGreaterThanOrEqual(0);
      expect(composer.y + composer.height).toBeLessThanOrEqual(height + 1);
      expect(sendBox.y + sendBox.height).toBeLessThanOrEqual(height + 1);
      await send.click({ trial: true });
      const reply = page.locator('.composer textarea');
      await reply.fill('Здравствуйте! Проверяю варианты для вас.');
      await expect(reply).toHaveValue('Здравствуйте! Проверяю варианты для вас.');
      await reply.blur();
      await expectNoHorizontalOverflow(page);
    }
    await page.goto(base + '?view=lead');
    const lead = await rect(page.locator('.leadZone'));
    expect(lead.y + lead.height).toBeLessThanOrEqual(551);
  });
}

test('390px conversation keeps the composer usable and inside the viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + '?view=conversation');
  await expectNoHorizontalOverflow(page);

  const composer = await rect(page.locator('.composer'));
  const surface = await rect(page.locator('.composerSurface'));
  const textarea = await rect(page.locator('.composer textarea'));
  const send = await rect(page.locator('.composer .sendBtn'));
  const quick = await rect(page.locator('.quickReplies'));

  expect(composer.width).toBeGreaterThanOrEqual(370);
  expect(surface.width).toBeGreaterThanOrEqual(360);
  expect(textarea.width).toBeGreaterThanOrEqual(220);
  expect(textarea.height).toBeGreaterThanOrEqual(46);
  expect(send.width).toBeLessThanOrEqual(50);
  expect(surface.x).toBeGreaterThanOrEqual(0);
  expect(surface.x + surface.width).toBeLessThanOrEqual(390);
  expect(quick.y).toBeGreaterThanOrEqual(surface.y + surface.height - 1);
});

test('390px focused composer hides shortcuts and preserves typing width', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + '?view=conversation');
  const textarea = page.locator('.composer textarea');
  await textarea.focus();
  await expect(page.locator('.quickReplies')).toBeHidden();
  const box = await rect(textarea);
  expect(box.width).toBeGreaterThanOrEqual(220);
  expect(box.height).toBeGreaterThanOrEqual(46);
});

test('390px chat bubbles and media cannot collapse into unusable cards', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + '?view=conversation&stress=chat');
  await expectNoHorizontalOverflow(page);

  const customerBoxes = await page.locator('.msg.customer').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().width));
  expect(customerBoxes.length).toBeGreaterThan(2);
  expect(Math.min(...customerBoxes)).toBeGreaterThanOrEqual(96);

  const mediaBubble = await rect(page.locator('.msg:has(.attachments)').last());
  const image = await rect(page.locator('.msg:has(.attachments) img').last());
  expect(mediaBubble.width).toBeGreaterThanOrEqual(220);
  expect(mediaBubble.width).toBeLessThanOrEqual(330);
  expect(image.width).toBeGreaterThanOrEqual(mediaBubble.width - 24);
});

test('430px mobile lead and conversation surfaces stay viewport-bounded', async ({ page }) => {
  await page.setViewportSize({ width: 430, height: 932 });
  await page.goto(base + '?view=lead');
  await expectNoHorizontalOverflow(page);
  const lead = await rect(page.locator('.leadZone'));
  expect(lead.width).toBeLessThanOrEqual(431);
  expect(lead.x).toBeGreaterThanOrEqual(0);
});

test('1440px desktop keeps three usable zones', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(base);
  await expectNoHorizontalOverflow(page);
  const inbox = await rect(page.locator('.inboxZone'));
  const conversation = await rect(page.locator('.conversationZone'));
  const lead = await rect(page.locator('.leadZone'));
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
