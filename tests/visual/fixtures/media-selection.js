const {expect}=require('@playwright/test');

// A File selection is synchronous; its optional bounded local inspection is
// asynchronous. Preserve the original exact text/draft assertions after that
// actual result settles, rather than freezing the transient loading sentence.
async function waitForMediaPreflight(page){
 await expect.poll(()=>page.locator('#mediaSelection .mediaFileCheck').allTextContents().then(values=>values.filter(text=>text.startsWith('Проверяем файл')).length)).toBe(0);
}
async function settledMediaSelectionText(page){await waitForMediaPreflight(page);return page.locator('#mediaSelection').textContent()}
module.exports={waitForMediaPreflight,settledMediaSelectionText};
