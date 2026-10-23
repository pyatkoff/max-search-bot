'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process');
const check=(ok,message)=>{if(!ok)throw new Error(message);};
const BASE='mcr.microsoft.com/playwright:v1.55.0-noble@sha256:ffc33305f7b4b04057ae4a0caa70aad4fde87454fb403a1a22e7f931707dfcf9';
const RECIPE=['Dockerfile','package.json','package-lock.json','run','verify-runtime.js','preflight.js'];
function recipeSha(root){return crypto.createHash('sha256').update(JSON.stringify(RECIPE.map(file=>[file,fs.readFileSync(path.join(root,file),'utf8')]))).digest('hex');}
function runtime(){
  const php=JSON.parse(cp.execFileSync('php',['-r',`echo json_encode(['version'=>PHP_VERSION,'modules'=>array_values(array_filter(['mbstring','pdo_sqlite','dom','curl','pdo_mysql'],fn($x)=>extension_loaded($x))),'sqlite'=>(new PDO('sqlite::memory:'))->query('SELECT 1')->fetchColumn()],JSON_THROW_ON_ERROR);`],{encoding:'utf8'}));
  const playwright=require('@playwright/test/package.json').version;
  check(process.versions.node.split('.')[0]==='22','Prepared image must use Node22');
  check(/^8\.3\./.test(php.version)&&php.modules.length===5&&Number(php.sqlite)===1,'Missing PHP8.3 or required native extensions');
  check(playwright==='1.55.0','Prepared image must use Playwright1.55.0');
  check(/^VERSION_ID="24\.04"$/m.test(fs.readFileSync('/etc/os-release','utf8')),'Prepared image must use Ubuntu24.04');
  check(process.platform==='linux'&&process.arch==='x64','Expected Linux amd64');
  return {node:process.version,php:php.version,playwright,platform:process.platform,arch:process.arch,baseImage:BASE};
}
function main(){
  const [mode,arg,...extra]=process.argv.slice(2);check(!extra.length,'Unexpected runtime arguments');
  const actual=runtime(),file=path.join(__dirname,'runtime.json');
  if(mode==='--build-manifest'){
    check(/^[a-f0-9]{64}$/.test(arg||''),'Exact recipe SHA required');
    fs.writeFileSync(file,JSON.stringify({schema:1,recipeSha:arg,...actual},null,2)+'\n');
  }else{
    check(mode==='--require-source'&&!arg,'Expected --require-source');
    check(!Object.hasOwn(process.env,'PLAYWRIGHT_BROWSERS_PATH'),'Browser environment override remains');
    const manifest=JSON.parse(fs.readFileSync(file,'utf8'));
    const pin=JSON.parse(fs.readFileSync('tools/browser_qa_environment.json','utf8'));
    check(manifest.schema===1&&pin.schema===1,'Invalid prepared environment schema');
    check(/^ghcr\.io\/pyatkoff\/max-search-bot-browser-qa@sha256:[a-f0-9]{64}$/.test(pin.image||'')&&process.env.QA_BROWSER_IMAGE===pin.image,'Expected the committed immutable image');
    check(manifest.recipeSha===pin.recipeSha&&pin.recipeSha===recipeSha('.github/browser-qa'),'Image recipe differs from current source');
    for(const [key,value] of Object.entries(actual))check(manifest[key]===value,'Prepared runtime changed: '+key);
    for(const browser of ['chromium','firefox','webkit']){
      const executable=require('playwright')[browser].executablePath();
      check(fs.realpathSync(executable).startsWith('/ms-playwright/'),'Unexpected native browser location');
      fs.accessSync(executable,fs.constants.X_OK);
    }
    process.stdout.write(JSON.stringify({prepared:true,image:pin.image,recipeSha:pin.recipeSha,...actual})+'\n');
  }
}
if(require.main===module){try{main();}catch(error){process.stderr.write('Prepared QA rejected: '+error.message+'\n');process.exitCode=1;}}
module.exports={recipeSha,RECIPE};
