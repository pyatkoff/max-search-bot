// Optional native-browser acceptance, synthetic only. Run with installed Playwright.
const playwright=require('playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.resolve(__dirname,'../..'),origin='http://review-journal.test';
const shell=cp.execFileSync(process.env.PHP_BINARY||'php',['manager2/virtual-replies.php'],{cwd:root,encoding:'utf8'});
const id='a'.repeat(64),second='b'.repeat(64);
const detail={ok:true,case_id:id,payload:{history_incomplete:true,messages:[{id:1,sender_type:'tourist',created_at:'2026-10-22T12:00:00Z',text:'Вьетнам на двоих, 14 ночей. <img src=x onerror=window.BAD=true>'},{id:2,sender_type:'manager',created_at:'2026-10-22T12:01:00Z',text:'На какие даты планируете?'},{id:3,sender_type:'tourist',created_at:'2026-10-22T12:02:00Z',text:'В ноябре',media_unread:true}]},author_result:{draft:'Из какого города планируете вылет?',next_action:'clarify',reason:'Город вылета не указан.',checks:['Проверить актуальные предложения.']},review_result:{verdict:'pass',issues:[],corrected_draft:null,lesson_candidate:null},disposition:{status:'unresolved'}};
(async()=>{
  const engine=process.env.REVIEW_BROWSER_ENGINE||'chromium';
  if(!['chromium','webkit'].includes(engine))throw new Error('unsupported browser');
  const browser=await playwright[engine].launch({headless:true,...(process.env.REVIEW_BROWSER_CHROME?{executablePath:process.env.REVIEW_BROWSER_CHROME}:{}),...(engine==='chromium'?{args:['--no-sandbox']}:{})});
  let checks=0;
  try{
    for(const width of [390,430,768,1440]){
      const page=await browser.newPage({viewport:{width,height:900}});let pageNumber=0,mode='normal',hold=null;const calls=[],unexpected=[],errors=[];
      page.on('pageerror',e=>errors.push(e.message));
      await page.route('**/*',async route=>{
        const req=route.request(),url=new URL(req.url());
        if(url.origin!==origin){unexpected.push(req.url());return route.abort();}
        if(url.pathname==='/manager2/virtual-replies.php')return route.fulfill({contentType:'text/html',body:shell});
        if(url.pathname.startsWith('/manager2/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(root,url.pathname.slice(1)))});
        if(url.pathname==='/favicon.ico')return route.fulfill({status:204,body:''});
        if(url.pathname!=='/manager/virtual-reply-reviews-api.php'||req.method()!=='GET'){unexpected.push(req.method()+' '+url.pathname);return route.abort();}
        calls.push(Object.fromEntries(url.searchParams));
        if(mode==='denied')return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({ok:false,error:'forbidden'})});
        if(mode==='expired')return route.fulfill({status:401,contentType:'application/json',body:JSON.stringify({ok:false,error:'unauthorized'})});
        if(url.searchParams.has('case_id')){
          if(mode==='hold'){hold=()=>route.fulfill({contentType:'application/json',body:JSON.stringify(detail)});return;}
          if(mode==='unavailable')return route.fulfill({status:404,contentType:'application/json',body:JSON.stringify({ok:false,error:'review_unavailable'})});
          const result=structuredClone(detail);
          if(url.searchParams.get('case_id')===second){result.author_result.draft=null;result.author_result.next_action='prepare_selection';result.review_result={verdict:'revise',issues:[{explanation:'Синтетическое замечание.',evidence_message_ids:[1]}],corrected_draft:'Синтетическое исправление.',lesson_candidate:'Синтетическое предложение правила.'};}
          return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
        }
        pageNumber=url.searchParams.has('cursor')?2:1;
        return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,reviews:pageNumber===1?[{case_id:id,number:47,source_kind:'conversation',reference_time:'2026-10-22T12:00:00Z',verdict:'pass'},{case_id:second,number:46,source_kind:'telegram_archive',reference_time:'2026-10-21T12:00:00Z',verdict:'revise'}]:[{case_id:second,number:27,source_kind:'telegram_archive',reference_time:'2026-10-20T12:00:00Z',verdict:'revise'}],has_more:pageNumber===1,next_cursor:pageNumber===1?'synthetic_cursor':null})});
      });
      await page.goto(origin+'/manager2/virtual-replies.php');await page.locator('.row').first().waitFor();
      await page.locator('.row').first().click();await page.getByText('Из какого города планируете вылет?',{exact:true}).waitFor();
      assert.equal(await page.locator('#detail img').count(),0);assert.equal(await page.evaluate(()=>window.BAD),undefined);checks+=2;
      assert(await page.locator('#detail').textContent().then(t=>t.includes('Развёрнутого объяснения')&&t.includes('Предшествующая история неполная')));checks++;
      await page.locator('#detail summary').first().click();assert.equal(await page.locator('.message').count(),3);assert(await page.getByText('Вложение не просмотрено.',{exact:true}).isVisible());checks+=2;
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));checks++;
      const output=process.env.REVIEW_BROWSER_SCREENSHOTS;if(output){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,'review-journal-'+width+'.png'),fullPage:true});}
      await page.locator('.row').nth(1).click();await page.getByText('Синтетическое исправление.',{exact:true}).waitFor();assert(await page.locator('#detail').textContent().then(t=>t.includes('Текста для отправки нет')&&t.includes('Цитируемые сообщения в истории: 1')));checks++;
      await page.locator('#next').click();await page.getByText('Разбор №27 · Есть замечания',{exact:true}).waitFor();assert.equal(pageNumber,2);checks++;
      await page.locator('#previous').click();await page.getByText('Разбор №47 · Без замечаний',{exact:true}).waitFor();checks++;
      await page.locator('#verdict').selectOption('revise');await page.locator('.row').first().waitFor();assert.equal(calls.at(-1).verdict,'revise');assert(!('cursor' in calls.at(-1)));checks+=2;
      mode='unavailable';await page.locator('.row').first().click();await page.getByText(/Этот разбор больше недоступен/).waitFor();assert.equal(await page.getByText('Из какого города планируете вылет?',{exact:true}).count(),0);checks++;
      mode='hold';await page.locator('.row').first().click();await page.waitForFunction(()=>document.querySelector('#detail').textContent.includes('Загружаем'));
      while(!hold)await new Promise(setImmediate);const delayed=hold;
      mode='normal';await page.locator('#refresh').click();await page.locator('.row').first().waitFor();await delayed().catch(()=>{});assert(!await page.locator('#detail').textContent().then(t=>t.includes('Из какого города')));checks++;
      mode='expired';await page.locator('.row').first().click();await page.getByText('Войдите в кабинет, чтобы открыть разборы.',{exact:true}).waitFor();assert.equal(await page.locator('.row').count(),0);assert.equal(await page.locator('#detail').textContent(),'');checks+=2;
      mode='denied';await page.locator('#refresh').click();await page.getByText('Разборы доступны только администратору с доступом к AnyTour.',{exact:true}).waitFor();assert.equal(await page.locator('.row').count(),0);checks++;
      assert.deepEqual(unexpected,[]);assert.deepEqual(errors,[]);checks+=2;await page.close();
    }
  }finally{await browser.close();}
  console.log('PRIVATE REVIEW JOURNAL NATIVE BROWSER '+engine+': '+checks+' checks PASSED');
})().catch(e=>{console.error(e);process.exitCode=1});
