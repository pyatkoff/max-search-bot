const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const base = 'http://127.0.0.1:4173';
test('manager can explicitly enable and test incoming sound', async ({page}) => {
  await page.goto(base + '/tests/visual/workspace-v2-fixture.html?view=inbox');
  await page.evaluate(() => {
    const root = document.createElement('div');
    root.id = 'notificationStatus';
    document.querySelector('.inboxHead').appendChild(root);
    window.WorkspaceV2 = {S: {manager: {id: 1}, authGeneration: 1, authExpired: false}};
  });
  if (fs.existsSync('manager/assets/workspace-v2-sound.js')) {
    await page.addScriptTag({url: base + '/manager/assets/workspace-v2-sound.js'});
    await page.evaluate(() => window.WorkspaceV2Sound.activate(1));
  }
  await expect(page.getByRole('button', {name: 'Включить звук', exact: true})).toBeVisible();
  await expect(page.getByRole('button', {name: 'Проверить звук', exact: true})).toBeVisible();
});
