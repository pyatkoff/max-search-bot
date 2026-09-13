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

for (const width of [390, 430, 768, 1440]) {
  test(`${width}px My conversations keep reply markers after reading`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('http://127.0.0.1:4173/tests/visual/workspace-v2-inbox-activity-fixture.html');
    await page.evaluate(() => {
      document.querySelector('.inboxList').id = 'inboxList';
      window.WorkspaceV2 = {
        S: { queue: 'mine', current: 1, leadTaskFilter: '' },
        $: id => document.getElementById(id),
        esc: value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
        statusText: () => 'Менеджер', outcomeText: () => '', formatWait: () => '',
      };
    });
    await page.addScriptTag({ url: 'http://127.0.0.1:4173/manager/assets/workspace-v2-inbox.js' });
    await page.evaluate(() => {
      window.replyRows = [
        { id: 1, display_name: 'Анна', manager_name: 'Светлана', awaiting_manager_reply: 1, unread_count: 2, last_text: 'Подскажите стоимость, пожалуйста.', operational_task_due_state: 'none' },
        { id: 2, display_name: 'Михаил', manager_name: 'Светлана', awaiting_manager_reply: 1, unread_count: 0, last_text: 'Жду вашего ответа.', operational_task_due_state: 'upcoming' },
        { id: 3, display_name: 'Ирина', manager_name: 'Светлана', awaiting_manager_reply: 0, unread_count: 0, last_text: 'Отправила варианты.', last_sender_type: 'manager', operational_task_due_state: 'today' },
      ];
      window.WorkspaceV2Inbox.renderList(window.replyRows);
    });
    await expect(page.locator('.taskQueueSection').first()).toHaveText('Ждут ответа · 2');
    await expect(page.locator('.leadReplyStatus')).toHaveCount(2);
    await expect(page.locator('.needsReply')).toHaveCount(2);
    await page.evaluate(() => window.WorkspaceV2Inbox.markRead(1));
    await expect(page.locator('.unreadBadge')).toHaveCount(0);
    await expect(page.locator('[data-conversation-id="1"] .leadReplyStatus')).toHaveText('Ждёт ответа');
    await expectNoHorizontalOverflow(page);
    for (const badge of await page.locator('.leadReplyStatus').all()) {
      const b = await rect(badge);
      expect(b.width).toBeGreaterThan(70);
      expect(b.x + b.width).toBeLessThanOrEqual(width);
    }
    await page.screenshot({ path: `visual-artifacts/mine-pending-${width}.png`, fullPage: true });
    await page.evaluate(() => {
      window.replyRows[0].awaiting_manager_reply = 0;
      window.WorkspaceV2Inbox.renderList([window.replyRows[1], window.replyRows[2], window.replyRows[0]]);
    });
    await expect(page.locator('[data-conversation-id="1"] .leadReplyStatus')).toHaveCount(0);
    await expect(page.locator('.leadReplyStatus')).toHaveCount(1);
    await page.evaluate(() => {
      window.WorkspaceV2.S.queue = 'all';
      window.WorkspaceV2Inbox.renderList(window.replyRows);
    });
    await expect(page.locator('.leadReplyStatus, .taskQueueSection.reply')).toHaveCount(0);
  });
}

for (const width of [390, 1440]) {
  test(`${width}px filtered My refresh keeps cards on pipeline failure`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('http://127.0.0.1:4173/tests/visual/workspace-v2-inbox-load-failure-fixture.html');
    const before = await page.locator('#inboxList').innerHTML();
    await page.evaluate(() => {
      window.fallbackCalls = 0;
      window.WorkspaceV2 = {
        S: { queue: 'mine', leadSearch: 'Анна', viewMode: 'list' },
        $: id => document.getElementById(id),
        esc: value => String(value), statusText: () => '', outcomeText: () => '', formatWait: () => '',
        pipe: async () => ({ ok: false, http_status: 500 }),
        api: async () => { window.fallbackCalls++; return { ok: true, conversations: [{ id: 999, display_name: 'Unrelated lead' }] }; },
      };
    });
    await page.addScriptTag({ url: 'http://127.0.0.1:4173/manager/assets/workspace-v2-inbox.js' });
    expect(await page.evaluate(() => window.WorkspaceV2Inbox.load())).toBe(false);
    expect(await page.evaluate(() => window.fallbackCalls)).toBe(0);
    expect(await page.locator('#inboxList').innerHTML()).toBe(before);
    await expect(page.locator('#inboxList')).toHaveAttribute('data-stale', 'true');
    await expect(page.locator('#inboxLoadStatus')).toContainText('Показаны последние загруженные данные.');
    await expect(page.getByRole('button', { name: 'Повторить', exact: true })).toBeVisible();
    await expectNoHorizontalOverflow(page);
  });
}

const telegramPhoto = Buffer.from('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', 'base64');
for (const width of [390, 430, 768, 1440]) {
  test(`${width}px forwarded Telegram photo keeps caption and opens full media`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.route('**/manager/media-file.php?message_id=42&attachment=0', route =>
      route.fulfill({ status: 200, contentType: 'image/png', body: telegramPhoto }));
    await page.route('**/manager/media-file.php?message_id=43&attachment=0', route =>
      route.fulfill({ status: 502, contentType: 'text/plain', body: 'Не удалось получить вложение' }));
    await page.goto(base + '?view=conversation');
    await page.evaluate(() => {
      document.querySelector('.messages').id = 'messages';
      window.WorkspaceV2 = { S: {}, $: id => document.getElementById(id) };
    });
    await page.addScriptTag({ url: 'http://127.0.0.1:4173/manager/assets/workspace-v2-conversation.js' });
    await page.evaluate(() => window.WorkspaceV2Conversation.renderMessages([
      { sender_type: 'manager', text: 'Какой вариант проверить?' },
      { sender_type: 'customer', text: '<b>Hotel Example</b>, Beach Villa, всё включено',
        attachments: [{ type: 'image', name: 'Фото.jpg', url: '/manager/media-file.php?message_id=42&attachment=0' }] },
      { sender_type: 'customer', text: 'Этого варианта' },
    ]));
    const photo = page.locator('.msg.customer img');
    await expect(photo).toBeVisible();
    await expect.poll(() => photo.evaluate(img => img.naturalWidth)).toBe(560);
    await expect(page.locator('.msg.customer .msgBody').first()).toHaveText('<b>Hotel Example</b>, Beach Villa, всё включено');
    await expect(page.locator('.msgBody b')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Открыть фото', exact: true })).toHaveAttribute('href', '/manager/media-file.php?message_id=42&attachment=0');
    await expectNoHorizontalOverflow(page);
    await photo.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `visual-artifacts/workspace-v2-${width}-telegram-media.png`, fullPage: true });
    await page.evaluate(() => window.WorkspaceV2Conversation.renderMessages([
      { sender_type: 'customer', text: '', attachments: [
        { type: 'image', name: 'Фото.jpg', url: '/manager/media-file.php?message_id=43&attachment=0' }
      ] },
    ]));
    await expect(page.getByRole('link', { name: 'Не удалось загрузить: Фото.jpg. Открыть файл', exact: true })).toBeVisible();
    await expectNoHorizontalOverflow(page);
  });
}

