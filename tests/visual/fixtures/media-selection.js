const {expect}=require('@playwright/test');

// A File selection is synchronous; its optional bounded local inspection is
// asynchronous. Preserve the original exact text/draft assertions after that
// actual result settles, rather than freezing the transient loading sentence.
async function waitForMediaPreflight(page,{advanceClock=false}={}){
 await expect.poll(async()=>{
  // Only the explicitly paused-clock CRUD fixture advances a visual frame.
  // Leave its independently tested 15s Inbox poll outside this scenario.
  if(advanceClock)await page.clock.runFor(16);
  return page.locator('#mediaSelection .mediaFileCheck').allTextContents().then(values=>values.filter(text=>text.startsWith('Проверяем файл')).length);
 }).toBe(0);
}
async function settledMediaSelectionText(page,options){await waitForMediaPreflight(page,options);return page.locator('#mediaSelection').textContent()}
module.exports={waitForMediaPreflight,settledMediaSelectionText};
