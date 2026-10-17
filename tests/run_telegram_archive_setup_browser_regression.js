'use strict';
const assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const readline=require('node:readline');
const fs=require('node:fs');
const path=require('node:path');
const playwright=require('playwright');
const root=path.resolve(__dirname,'..');
const engine=process.env.BROWSER||'chromium';
const output=process.env.TELEGRAM_ARCHIVE_QA_DIR||path.join(root,'telegram-archive-visual-artifacts');
const syntheticToken='55555:ABCDEFGHIJKLMNOPQRSTUVWX';
fs.mkdirSync(output,{recursive:true});
let checks=0;
function check(name,value){assert.ok(value,name);checks++;process.stdout.write('PASS '+name+'\n');}
async function fixture(){
    const child=spawn(process.env.PHP_BINARY||'php',['tests/telegram_archive_browser_fixture.php'],{cwd:root,stdio:['pipe','pipe','pipe']});
    const lines=readline.createInterface({input:child.stdout});
    let error='';child.stderr.on('data',data=>{error+=data.toString();});
    let timer;
    let first;
    try { first=await Promise.race([new Promise((resolve,reject)=>{lines.once('line',resolve);child.once('exit',code=>reject(new Error('Fixture exited '+code+': '+error)));}),new Promise((resolve,reject)=>{timer=setTimeout(()=>reject(new Error('Fixture start timeout')),10000);})]); }
    catch(error){child.stdin.end();throw error;}
    finally{clearTimeout(timer);}
    const data=JSON.parse(first);
    return {url:data.url,close:async()=>{child.stdin.end('\n');await new Promise(resolve=>child.once('exit',resolve));lines.close();}};
}
async function login(context,origin,role){
    const response=await context.request.get(origin+'/__fixture_login?role='+role);
    const cookie=response.headers()['set-cookie'];
    assert.ok(cookie);
    const value=/anytour_manager_panel=([^;]+)/.exec(cookie)[1];
    await context.addCookies([{name:'anytour_manager_panel',value,domain:'127.0.0.1',path:'/manager/',secure:false,httpOnly:true,sameSite:'Lax'}]);
}
(async()=>{
    const browser=await playwright[engine].launch({headless:true});
    try {
        for(const width of [390,430,768,1440]){
            const host=await fixture();
            const context=await browser.newContext({viewport:{width,height:960},locale:'ru-RU'});
            const blocked=[];const errors=[];
            await context.route('**/*',route=>{if(new URL(route.request().url()).origin===host.url)return route.continue();blocked.push(route.request().url());return route.abort();});
            const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
            try{
                await page.goto(host.url+'/manager/telegram-archive.php');
                check(engine+' '+width+' anonymous page has no credential form',await page.locator('#tokenForm').count()===0);
                await login(context,host.url,'admin');
                await page.goto(host.url+'/manager/telegram-archive.php');
                await page.getByText('Нужен токен',{exact:true}).waitFor();
                check(engine+' '+width+' setup has no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
                await page.screenshot({path:path.join(output,'archive-initial-'+engine+'-'+width+'.png'),fullPage:true});
                await page.locator('#botToken').fill(syntheticToken);
                await page.getByRole('button',{name:'Подключить',exact:true}).click();
                await page.getByText('Готов к подключению',{exact:true}).waitFor();
                check(engine+' '+width+' token is cleared after private setup',await page.locator('#botToken').inputValue()==='');
                await context.request.get(host.url+'/__fixture_connect');
                await page.getByRole('button',{name:'Обновить',exact:true}).click();
                await page.getByText('Ожидает включения',{exact:true}).waitFor();
                const pending=await (await context.request.get(host.url+'/__fixture_message')).json();
                check(engine+' '+width+' pending account remains outside capture',pending.state==='account_not_approved');
                await page.getByRole('combobox').selectOption('12');
                await page.getByRole('button',{name:'Включить сбор',exact:true}).click();
                await page.getByText('Сбор включён',{exact:true}).waitFor();
                const captured=await (await context.request.get(host.url+'/__fixture_message')).json();
                check(engine+' '+width+' selected manager approval enables real runtime capture',captured.stored===1);
                check(engine+' '+width+' approved account layout remains within viewport',await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
                await page.screenshot({path:path.join(output,'archive-approved-'+engine+'-'+width+'.png'),fullPage:true});
                await page.getByRole('combobox').selectOption('shared');
                const sharedResponse=page.waitForResponse(response=>response.url()===host.url+'/manager/telegram-archive-api.php'&&response.request().method()==='POST'&&response.request().postDataJSON().account_type==='shared');
                await page.getByRole('button',{name:'Сохранить',exact:true}).click();
                const sharedResult=await(await sharedResponse).json();
                check(engine+' '+width+' shared selection persists approval with null personal manager',sharedResult.ok&&sharedResult.accounts[0].approved&&sharedResult.accounts[0].manager_id===null);
                await page.getByRole('button',{name:'Обновить',exact:true}).click();
                await page.getByText('Сбор включён',{exact:true}).waitFor();
                check(engine+' '+width+' refreshed shared account retains its explicit selection',await page.getByRole('combobox').inputValue()==='shared');
                const sharedCaptured=await(await context.request.get(host.url+'/__fixture_message')).json();
                check(engine+' '+width+' approved shared account passes the real capture guard',sharedCaptured.state==='archived');
                check(engine+' '+width+' shared account layout remains within viewport',await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
                await page.screenshot({path:path.join(output,'archive-shared-'+engine+'-'+width+'.png'),fullPage:true});
                await page.getByRole('button',{name:'Остановить сбор',exact:true}).click();
                await page.getByText('Ожидает включения',{exact:true}).waitFor();
                const stopped=await(await context.request.get(host.url+'/__fixture_message')).json();
                check(engine+' '+width+' stop action returns shared account to pending and fences capture',stopped.state==='account_not_approved');
                await login(context,host.url,'manager');
                await page.getByRole('button',{name:'Обновить',exact:true}).click();
                await page.getByText('Для настройки нужен доступ администратора проекта.',{exact:true}).waitFor();
                check(engine+' '+width+' role loss clears previously loaded account metadata',!await page.locator('#accounts').textContent().then(text=>text.includes('Тестовый менеджер')));
                check(engine+' '+width+' no page exceptions or external requests',errors.length===0&&blocked.length===0);
            }finally{await context.close();await host.close();}
        }
    }finally{await browser.close();}
    process.stdout.write('TOTAL '+checks+' | PASS '+checks+'\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
