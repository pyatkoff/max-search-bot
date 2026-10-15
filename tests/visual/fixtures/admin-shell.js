const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');

const root=path.resolve(__dirname,'../../..'),origin='http://127.0.0.1:4173';
const entries=['/manager/admin.php','/manager2/admin.php'];
// Exercise the real PHP entrypoints, including their base URL and asset order.
// This is rendered in CI with PHP; there is no copied HTML or production access.
const shells=new Map(entries.map(entry=>[entry,execFileSync('php',[entry.slice(1)],{cwd:root,encoding:'utf8'})]));
const canonicalAssets=new Set(['admin.css','admin.js','admin-edit-safety.js','manager-time.js','manager-http-client.js']);

async function serveAdminShell(route){
  const request=route.request(),url=new URL(request.url());
  if(request.method()!=='GET'||url.origin!==origin)return false;
  if(shells.has(url.pathname)){
    await route.fulfill({contentType:'text/html',body:shells.get(url.pathname)});
    return true;
  }
  const canonical=url.pathname.match(/^\/manager\/assets\/([^/]+)$/)?.[1];
  const modern=url.pathname.match(/^\/manager2\/assets\/([^/]+)$/)?.[1];
  if(!canonicalAssets.has(canonical)&&modern!=='admin.css')return false;
  const asset=path.join(root,canonical?'manager':'manager2','assets',canonical||modern);
  await route.fulfill({contentType:asset.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(asset)});
  return true;
}

module.exports={root,origin,entries,serveAdminShell};
