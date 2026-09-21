const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const fs=require('node:fs');
const origin='http://127.0.0.1:4173';
const fixtures=JSON.parse(execFileSync('php',[path.join(__dirname,'fixtures/handoff-media.php')],{encoding:'utf8'}));
const draft='Черновик менеджера — не отправлять';

async function setup(page,scenario='photos',failed=false){
  const encoded=await page.evaluate(()=>{
    const c=document.createElement('canvas');c.width=640;c.height=480;
    const ctx=c.getContext('2d');ctx.fillStyle='#dbeafe';ctx.fillRect(0,0,640,480);
    ctx.fillStyle='#173452';ctx.font='32px sans-serif';ctx.textAlign='center';
    ctx.fillText('SYNTHETIC PHOTO',320,225);ctx.fillText('640 x 480',320,275);
    return c.toDataURL('image/png').split(',')[1];
  });
  const png=Buffer.from(encoded,'base64'),unexpected=[],media=[];
  await page.route('**/*',route=>{
    const r=route.request(),u=new URL(r.url());
    if(r.method()!=='GET'){unexpected.push(r.method()+' '+u.pathname);return route.abort();}
    const telegram=u.origin===origin&&u.pathname==='/manager/media-file.php'&&u.search==='?message_id=41&attachment=0';
    const max=r.url()==='https://media.example.test/handoff-fixture.png';
    if(telegram||max){
      media.push(r.url());
      return route.fulfill({status:failed?403:200,contentType:failed?'text/plain':'image/png',headers:{'Cache-Control':'no-store'},body:failed?'Synthetic access denied':png});
    }
    if(u.origin!==origin||u.pathname.endsWith('.php')){unexpected.push(r.url());return route.abort();}
    return route.continue();
  });
  await page.goto(origin+'/tests/visual/workspace-v2-fixture.html?view=conversation');
  await page.evaluate(()=>{
    // Match production relative media URL resolution without requesting a live endpoint.
    history.replaceState(null,'','/manager/');
    document.querySelector('.messages').id='messages';
    document.querySelector('.composer').id='composer';
    document.querySelector('.composer textarea').id='replyText';
    document.getElementById('replyText').value='Черновик менеджера — не отправлять';
    window.WorkspaceV2={S:{current:8,manager:{id:1},authGeneration:1,detail:{conversation:{id:8}}},$:id=>document.getElementById(id)};
  });
  await page.addScriptTag({url:origin+'/manager/assets/workspace-v2-conversation.js'});
  await page.evaluate(rows=>WorkspaceV2Conversation.renderMessages(rows),fixtures[scenario].messages);
  return {unexpected,media};
}

for(const width of [390,430,768,1440])test.describe('handoff photo '+width,()=>{
  test.use({viewport:{width,height:844},hasTouch:true,isMobile:width<768});
  test('PHP summary exposes original photos and both touch controls open the same-page viewer',async({page,browserName})=>{
    const observed=await setup(page),summary=page.locator('.msg.ai');
    await expect(summary).toHaveCount(1);
    await expect(summary.locator('.msgBody')).toContainText('Запрос туриста для менеджера');
    await expect(summary.locator('.msgBody')).toContainText('Посмотрите это фото, пожалуйста.');
    await expect(page.locator('.msg.customer').first().locator('.msgBody')).toHaveText(fixtures.photos.original[0].text);
    await expect(summary.locator('img')).toHaveCount(2);
    expect(fixtures.photos.messages.at(-1).attachments.map(a=>Object.keys(a).sort())).toEqual([['name','type','url'],['name','type','url']]);
    expect(JSON.stringify(fixtures.photos)).not.toContain('SYNTHETIC_SOURCE_REFERENCE');
    const urls=fixtures.photos.messages.at(-1).attachments.map(a=>a.url);
    for(let i=0;i<urls.length;i++){
      const photo=summary.locator('img').nth(i);
      await photo.scrollIntoViewIfNeeded();
      await expect.poll(()=>photo.evaluate(img=>img.naturalWidth)).toBe(640);
      await expect(photo).toHaveAttribute('src',urls[i]);
      const box=await photo.boundingBox();expect(box.width).toBeGreaterThan(100);
      expect(box.x).toBeGreaterThanOrEqual(0);expect(box.x+box.width).toBeLessThanOrEqual(width+1);
      fs.mkdirSync('photo-viewer-artifacts',{recursive:true});
      if(i===0)await page.screenshot({path:`photo-viewer-artifacts/${browserName}-${width}-summary.png`});
      if(i===0)await photo.tap();
      else await summary.getByRole('link',{name:'Открыть фото',exact:true}).nth(i).tap();
      const dialog=page.getByRole('dialog',{name:'Просмотр фото'});
      await expect(dialog).toBeVisible();
      await expect(dialog.locator('img')).toHaveAttribute('src',urls[i]);
      await expect.poll(()=>dialog.locator('img').evaluate(img=>img.naturalWidth)).toBe(640);
      expect(page.context().pages()).toHaveLength(1);expect(page.url()).toBe(origin+'/manager/');
      if(i===0)await page.screenshot({path:`photo-viewer-artifacts/${browserName}-${width}-summary-open.png`});
      await dialog.getByRole('button',{name:'Закрыть фото'}).tap();
      await expect(dialog).toHaveCount(0);await expect(page.locator('#replyText')).toHaveValue(draft);
    }
    expect(observed.media.some(url=>url.includes('message_id=0'))).toBe(false);
    expect(observed.unexpected).toEqual([]);
  });
});

test('unavailable original photo reports failure without navigation or sending',async({page})=>{
  const observed=await setup(page,'photos',true);
  await page.locator('.msg.ai').getByRole('link',{name:'Открыть фото',exact:true}).first().click();
  const dialog=page.getByRole('dialog',{name:'Просмотр фото'});
  await expect(dialog.getByRole('status')).toContainText('Не удалось загрузить фото');
  await dialog.getByRole('button',{name:'Закрыть фото'}).click();
  await expect(page.locator('#replyText')).toHaveValue(draft);expect(observed.unexpected).toEqual([]);
});
for(const scenario of ['text_only','missing_url'])test(scenario+' does not invent downloadable summary attachments',async({page})=>{
  const observed=await setup(page,scenario);
  await expect(page.locator('.msg.ai')).toHaveCount(1);
  await expect(page.locator('.msg.ai .attachments')).toHaveCount(0);
  expect(observed.media).toHaveLength(0);expect(observed.unexpected).toEqual([]);
});
test('first human reply removes the synthetic handoff, not original customer photos',async({page})=>{
  const observed=await setup(page,'after_reply');
  await expect(page.locator('.msg.ai')).toHaveCount(0);
  await expect(page.locator('.msg.customer img')).toHaveCount(2);
  await expect(page.locator('.msg.manager .msgBody')).toHaveText('Вижу фотографии, спасибо.');
  expect(observed.unexpected).toEqual([]);
});
