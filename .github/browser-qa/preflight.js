'use strict';
const fs=require('node:fs'),path=require('node:path');
(async()=>{
  const playwright=require('playwright');
  if(Object.hasOwn(process.env,'PLAYWRIGHT_BROWSERS_PATH'))throw new Error('Unexpected browser environment override');
  for(const name of ['chromium','firefox','webkit']){
    const browser=await playwright[name].launch({headless:true});
    try{
      const page=await browser.newPage({viewport:{width:390,height:430}});
      await page.setContent('<!doctype html><meta charset="utf-8"><style>body{background:#def;font:20px Arial}</style><h1>Подборка туров</h1><p>Готовое окружение</p>');
      if(await page.locator('h1').textContent()!=='Подборка туров')throw new Error('Native text rendering failed');
      const png=await page.screenshot();if(png.length<2000)throw new Error('Missing native capture');
      fs.writeFileSync(path.join(__dirname,'preflight-'+name+'.png'),png);
      process.stdout.write(JSON.stringify({browser:name,version:browser.version(),native:true,pngBytes:png.length})+'\n');
    }finally{await browser.close();}
  }
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
