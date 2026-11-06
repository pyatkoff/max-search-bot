#!/usr/bin/env node
'use strict';

// Run the real selector and the scope step against disposable Git histories.
// No browser/provider/database calls and no production inputs are involved.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');
const { selectionStyleOnlyChanges } = require('../tools/ci_change_scope');
const root = path.resolve(__dirname, '..');
const selector = path.join(root, 'tools/ci_change_scope.js');
const scope = fs.readFileSync(path.join(root, '.github/workflows/ci-change-scope.yml'), 'utf8');
const body = /        id: scope\n        run: \|\n((?:          [^\n]*\n)+)/.exec(scope);
assert.ok(body, 'canonical scope must expose its executable transport');
const script = body[1].replace(/^ {10}/gm, '')
  .replace('node tools/ci_change_scope.js', 'node ' + JSON.stringify(selector));
const css = 'manager/assets/workspace-v2-tour-selection.css';
const publicCss = 'selection/selection.css';
const spec = 'tests/visual/manager-tour-selection.spec.js';
const editor = 'modules/tour-selections/editor.json';
const publicManifest = 'modules/tour-selections/module.json';
const changed = name => ({ path: name, status: 'M', oldMode: '100644', newMode: '100644' });
let assertions = 0;
function check(actual, expected, label) { assert.deepEqual(actual, expected, label); assertions++; }
check(selectionStyleOnlyChanges([changed(css)]), true, 'editor style');
check(selectionStyleOnlyChanges([changed(publicCss), changed(spec), changed(publicManifest)]), true, 'style with visual test and validated manifest candidate');
for (const value of [null, [], [{}], [changed(editor)], [changed(spec)]]) {
  check(selectionStyleOnlyChanges(value), false, 'at least one exact stylesheet is required');
}
for (const file of ['manager/assets/workspace-v2-tour-selection.js', 'manager/assets/workspace-v2-tour-selection-host.js',
  'manager/index.php', 'manager/tour-selections-api.php', 'services/ManagerTourSelectionService.php',
  'manager/lib/ManagerHttp.php', 'migrations/999_example.sql', 'tests/support/manager-tour-selection.php',
  'tests/visual/fixtures/selection-private-editor.js', 'docs/MANAGER_TOUR_SELECTIONS.md',
  'extensions/selection-collector/content.css', './'+css, css+'.bak', css+'/extra']) {
  check(selectionStyleOnlyChanges([changed(css), changed(file)]), false, 'mixed or adjacent owner '+file);
}
for (const file of [css, publicCss, spec, editor, publicManifest]) {
  for (const status of ['A','D','T','R100','C100','U','',undefined]) {
    check(selectionStyleOnlyChanges([changed(css), {...changed(file), status}]), false, 'status '+file+'/'+status);
  }
  for (const [oldMode,newMode] of [['100644','100755'],['100755','100755'],['120000','120000'],['160000','160000'],[undefined,undefined]]) {
    check(selectionStyleOnlyChanges([changed(css), {...changed(file), oldMode, newMode}]), false, 'mode '+file);
  }
  check(selectionStyleOnlyChanges([changed(css), {...changed(file), previousPath:'old'}]), false, 'copy/rename '+file);
}

const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'selection-style-scope-'));
const repo = path.join(temp, 'repo');
fs.mkdirSync(repo);
const eventPath = path.join(temp, 'event.json'), output = path.join(temp, 'output.txt');
const git = args => execFileSync('git', args, { cwd: repo, encoding:'utf8', stdio:['ignore','pipe','pipe'] }).trim();
const put = (name, content) => { const file=path.join(repo,name);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,content); };
const manifest = (name, contract) => ({schema:1,name,version:'1.0.0',contract,files:{'style.css':css},backend_inputs:['manager/lib/ManagerHttp.php']});
const editorBase=manifest('anytour-selection-editor','tour-selection-editor-host-v1');
const publicBase=manifest('anytour-selection-public','selection-php-view-v1');
try {
  git(['init','-q']);git(['config','user.name','CI fixture']);git(['config','user.email','ci@example.invalid']);git(['config','core.filemode','true']);
  for (const file of [css, publicCss, spec, 'manager/assets/workspace-v2-tour-selection.js', 'manager/lib/ManagerHttp.php',
    'manager/tour-selections-api.php','docs/MANAGER_TOUR_SELECTIONS.md','migrations/999_example.sql']) put(file,'base\n');
  put(editor,JSON.stringify(editorBase));put(publicManifest,JSON.stringify(publicBase));
  git(['add','.']);git(['commit','-qm','base']);const base=git(['rev-parse','HEAD']);
  function run(head, expectedMode, expectedBackend, label, override={}) {
    fs.writeFileSync(eventPath,JSON.stringify({pull_request:{base:{sha:base},head:{sha:head}}}));fs.writeFileSync(output,'');
    const result=spawnSync('bash',['-e','-c',script],{cwd:repo,encoding:'utf8',env:{...process.env,
      GITHUB_EVENT_NAME:'pull_request',GITHUB_EVENT_PATH:eventPath,GITHUB_OUTPUT:output,...override}});
    check(result.status,0,label+' exits successfully');
    const selected=JSON.parse(result.stdout);
    check(selected.mode,expectedMode,label+' browser scope');
    const fields=Object.fromEntries(fs.readFileSync(output,'utf8').trim().split('\n').map(line=>line.split('=')));
    check(fields,{mode:expectedMode,'tour-proof':String(selected.proveTourSelection===true),backend:expectedBackend,browser:expectedMode},label+' exact transported outputs');
    return selected;
  }
  function scenario(label, mutate, mode='tour-selection', backend='full') {
    git(['reset','--hard','-q',base]);git(['clean','-fdq']);mutate();git(['add','-A']);git(['commit','-qm',label]);
    return run(git(['rev-parse','HEAD']),mode,backend,label);
  }
  scenario('CSS only',()=>put(css,'.selection{color:red}\n'),'tour-selection','selection-style');
  const styleHead=git(['rev-parse','HEAD']);
  for (const eventName of ['push','workflow_dispatch','']) run(styleHead,'full','full','manual/non-PR '+eventName,{GITHUB_EVENT_NAME:eventName});
  scenario('public style',()=>put(publicCss,'.selection{color:red}\n'),'tour-selection','selection-style');
  scenario('styles and native assertions',()=>{put(css,'changed\n');put(publicCss,'changed\n');put(spec,'changed tests\n');},'tour-selection','selection-style');
  scenario('styles and both version bumps',()=>{
    put(css,'changed\n');put(editor,JSON.stringify({...editorBase,version:'1.0.1'}));
    put(publicManifest,JSON.stringify({...publicBase,version:'1.0.1'}));
  },'tour-selection','selection-style');
  scenario('editor manifest alone',()=>put(editor,JSON.stringify({...editorBase,version:'1.0.1'})));
  scenario('spec alone',()=>put(spec,'changed\n'));
  for (const file of ['manager/assets/workspace-v2-tour-selection.js','manager/tour-selections-api.php','docs/MANAGER_TOUR_SELECTIONS.md']) {
    scenario('style plus '+file,()=>{put(css,'changed\n');put(file,'changed\n');});
  }
  for (const file of ['manager/lib/ManagerHttp.php','migrations/999_example.sql','unknown.js','tools/ci_change_scope.js']) {
    scenario('style plus shared '+file,()=>{put(css,'changed\n');put(file,'changed\n');},'full');
  }
  for (const [file,original] of [[editor,editorBase],[publicManifest,publicBase]]) {
    for (const patch of [{contract:'changed'},{files:{'style.css':'other.css'}},{backend_inputs:['other.php']},{schema:2},{unknown:true}]) {
      scenario('changed manifest contract '+file+Object.keys(patch)[0],()=>{
        put(css,'changed\n');put(file,JSON.stringify({...original,version:'1.0.1',...patch}));
      },'full');
    }
    scenario('broken manifest '+file,()=>{put(css,'changed\n');put(file,'{broken');},'full');
    scenario('non-version manifest edit '+file,()=>{put(css,'changed\n');put(file,JSON.stringify(original,null,2));},'full');
  }
  scenario('CSS deletion',()=>fs.unlinkSync(path.join(repo,css)),'full');
  scenario('CSS rename',()=>fs.renameSync(path.join(repo,css),path.join(repo,css+'.old')),'full');
  scenario('CSS executable',()=>fs.chmodSync(path.join(repo,css),0o755),'full');
  scenario('CSS symlink',()=>{fs.unlinkSync(path.join(repo,css));fs.symlinkSync('other.css',path.join(repo,css));},'full');
  run(base,'full','full','empty diff');run('f'.repeat(40),'full','full','missing commit');
  git(['reset','--hard','-q',base]);git(['clean','-fdq']);put('docs/base-change.md','new main\n');git(['add','.']);git(['commit','-qm','new base']);
  const nextBase=git(['rev-parse','HEAD']);
  fs.writeFileSync(eventPath,JSON.stringify({pull_request:{base:{sha:nextBase},head:{sha:styleHead}}}));fs.writeFileSync(output,'');
  const blocked=spawnSync('bash',['-e','-c',script],{cwd:repo,encoding:'utf8',env:{...process.env,
    GITHUB_EVENT_NAME:'pull_request',GITHUB_EVENT_PATH:eventPath,GITHUB_OUTPUT:output}});
  check(blocked.status,1,'stale event base fails');check(fs.readFileSync(output,'utf8'),'','blocked scope emits no partial outputs');
} finally {fs.rmSync(temp,{recursive:true,force:true});}
console.log(`PASS ${assertions} selection-style scope and output assertions`);
