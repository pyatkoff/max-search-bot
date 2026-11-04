#!/usr/bin/env node
'use strict';

// Exercise the real selector, including raw Git modes and immutable base/head
// reads. This adds no job and never contacts production or a browser/provider.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync, spawnSync} = require('node:child_process');
const scope = require('../tools/ci_change_scope');
const selector = path.resolve(__dirname, '../tools/ci_change_scope.js');
const manifest = 'modules/tour-selections/module.json';
const editor = 'modules/tour-selections/editor.json';
const component = 'selection/selection.css';
const baseManifest = {schema:1, name:'anytour-selection-public', version:'0.1.2',
  contract:'selection-php-view-v1', files:{'view.php':'selection/view.php'},
  backend_inputs:['services/ManagerTourSelectionShareService.php']};
const nextManifest = {...baseManifest,version:'0.1.3'};
const editorManifest = {...baseManifest,name:'anytour-selection-editor',contract:'tour-selection-editor-host-v1'};
const text = value => JSON.stringify(value);
const change = (file, fields={}) => ({status:'M',path:file,oldMode:'100644',newMode:'100644',...fields});
let checks = 0;
function check(actual, expected, message) {assert.deepEqual(actual, expected, message); checks++;}
function classify(changes, before=text(baseManifest), after=text(nextManifest)) {
  return scope.classifySelectionCompanionChanges(changes,()=>[before,after]).mode;
}
check(scope.TOUR_SELECTION_PATHS.has(manifest),false,'public manifest is not a standalone browser scope owner');
check(classify([change(component),change(manifest)]),'tour-selection','version accompanies an audited presentation change');
check(classify([change(manifest),change(component)]),'tour-selection','raw-diff ordering is immaterial');
for (const file of scope.TOUR_SELECTION_PATHS) {
  const expected=scope.classifyChanges([change(file)]).mode==='tour-selection'?'tour-selection':'full';
  check(classify([change(file),change(manifest)]),expected,'every companion keeps its existing scope: '+file);
}
for (const changes of [[],null,[{}],[change(manifest)],
  [change(manifest),change('docs/MANAGER_TOUR_SELECTIONS.md')],
  [change(manifest),change('extensions/selection-collector/README.md')],
  [change(manifest),change('manager/ai-oauth.php')],
  [change(manifest),change(component),change('manager/_bootstrap.php')],
  [change(manifest),change(component),change('services/ManagerAuthService.php')],
  [change(manifest),change(component),change('migrations/999_example.sql')],
  [change(manifest),change(component),change('tools/ci_change_scope.js')],
  [change(manifest),change(component),change('tests/check_ci_public_manifest_scope.js')],
  [change(manifest),change(component),change('selection/unknown-helper.js')],
  [change(manifest),change(component),change(manifest)]]) {
  check(classify(changes),'full','unknown/mixed/metadata-only input does not narrow');
}
for (const status of ['A','D','R100','C100','T','U',undefined]) {
  check(classify([change(component),change(manifest,{status})]),'full','manifest status '+status);
}
for (const fields of [{oldMode:undefined},{newMode:undefined},{oldMode:'000000'},
  {oldMode:'100755',newMode:'100755'},{oldMode:'120000',newMode:'120000'},
  {oldMode:'160000',newMode:'160000'},{previousPath:manifest}]) {
  check(classify([change(component),change(manifest,fields)]),'full','manifest modes/copies are verified');
}
for (const file of ['./'+manifest,manifest+'.bak',manifest+'/extra']) {
  check(classify([change(component),change(file)]),'full','lookalike path is not a manifest');
}
check(scope.publicManifestVersionOnlyChange(text(baseManifest),text(nextManifest)),true,'only public version changes');
check(scope.publicManifestVersionOnlyChange(text(baseManifest),text(Object.fromEntries(Object.entries(nextManifest).reverse()))),true,'key ordering is irrelevant');
for (const patch of [{schema:2},{name:'other'},{contract:'selection-php-view-v2'},
  {files:{'view.php':'selection/other.php'}},{backend_inputs:['services/ManagerAuthService.php']},
  {backend_inputs:[...baseManifest.backend_inputs,...baseManifest.backend_inputs]},
  {unknown:true},{files:[]},{files:{}},{files:null},{backend_inputs:[]},
  {backend_inputs:[7]},...['','0.1','0.1.3-dev','0.1.3\n','../0.1.3',null,3].map(version=>({version}))]) {
  check(classify([change(component),change(manifest)],text(baseManifest),text({...nextManifest,...patch})),'full','version cannot hide a contract/dependency change');
}
for (const malformed of ['{broken','null','[]','{}','"manifest"']) {
  check(classify([change(component),change(manifest)],malformed),'full','invalid base is full');
  check(classify([change(component),change(manifest)],text(baseManifest),malformed),'full','invalid head is full');
}
check(classify([change(component),change(manifest)],text(baseManifest),text(baseManifest)),'full','no version change is not an admission');
check(scope.classifySelectionCompanionChanges([change(component),change(manifest)],()=>{throw new Error('unavailable');}).mode,'full','unavailable immutable content keeps full checks');
check(scope.editorManifestVersionOnlyChange(text(editorManifest),text({...editorManifest,version:'0.1.3'})),true,'existing editor version behavior survives');
check(scope.editorManifestVersionOnlyChange(text(baseManifest),text(nextManifest)),false,'public identity cannot pass as editor');
check(scope.publicManifestVersionOnlyChange(text(editorManifest),text({...editorManifest,version:'0.1.3'})),false,'editor identity cannot pass as public');
for (const changes of [[change(component)],[change('manager/ai-oauth.php')],[],null,[change('unknown')]]) {
  check(scope.classifySelectionCompanionChanges(changes,()=>{throw new Error('must not read');}),scope.classifyChanges(changes),'no public manifest means exactly original classification');
}

const temp=fs.mkdtempSync(path.join(os.tmpdir(),'ci-public-version-'));
try {
  const git=args=>execFileSync('git',args,{cwd:temp,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
  const put=(file,content)=>{const target=path.join(temp,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,content);};
  const commit=()=>{git(['add','-A']);git(['commit','-qm','synthetic selector fixture']);return git(['rev-parse','HEAD']);};
  git(['init','-q']);git(['config','user.name','CI scope fixture']);git(['config','user.email','ci@example.invalid']);git(['config','core.filemode','true']);
  put(manifest,text(baseManifest));put(component,'/* base */\n');put(editor,text(editorManifest));
  put('manager/_bootstrap.php','<?php // shared\n');
  const base=commit();
  function run(head, eventName='pull_request', eventBase=base) {
    const eventFile=path.join(temp,'event.json'),outputFile=path.join(temp,'output.txt');
    fs.writeFileSync(eventFile,text({pull_request:{base:{sha:eventBase},head:{sha:head}}}));
    fs.writeFileSync(outputFile,'');
    const result=spawnSync(process.execPath,[selector],{cwd:temp,encoding:'utf8',env:{...process.env,
      GITHUB_EVENT_NAME:eventName,GITHUB_EVENT_PATH:eventFile,GITHUB_OUTPUT:outputFile}});
    return {status:result.status,value:JSON.parse(result.stdout.trim()),output:fs.readFileSync(outputFile,'utf8')};
  }
  function scenario(setup, expected, message) {
    git(['reset','--hard',base]);git(['clean','-fdq']);setup();const head=commit();const result=run(head);
    check(result.status,0,message+' exits normally');check(result.value.mode,expected,message);
    check(result.output.includes('mode='+expected+'\n'),true,message+' publishes exact mode');
    return head;
  }
  const eligible=scenario(()=>{put(manifest,text(nextManifest));put(component,'/* appearance updated */\n');},'tour-selection','real Git public-version/presentation diff is scoped');
  // Mutable worktree contents cannot replace immutable Git manifest evidence.
  put(manifest,text({...nextManifest,backend_inputs:['services/ManagerAuthService.php']}));
  check(run(eligible).value.mode,'tour-selection','selector reads pinned Git blobs, not current disk contents');
  check(run(eligible,'workflow_dispatch').value.mode,'full','manual validation retains full coverage');
  scenario(()=>put(manifest,text(nextManifest)),'full','manifest-only diff has no false browser admission');
  scenario(()=>{put(manifest,text(nextManifest));put('docs/MANAGER_TOUR_SELECTIONS.md','docs');},'full','manifest plus documentation is full');
  scenario(()=>{put(manifest,text({...nextManifest,files:{'view.php':'selection/other.php'}}));put(component,'changed');},'full','changed package path is full');
  scenario(()=>{put(manifest,text(nextManifest));put(component,'changed');put('manager/_bootstrap.php','<?php // changed shared');},'full','mixed shared shell is full');
  scenario(()=>{put(manifest,text(nextManifest));put(component,'changed');put(editor,text({...editorManifest,version:'0.1.3',backend_inputs:['new.php']}));},'full','public companion never bypasses editor contract check');
  scenario(()=>{put(manifest,text(nextManifest));put(component,'changed');put(editor,text({...editorManifest,version:'0.1.3'}));},'tour-selection','both version-only manifests with scoped presentation');
  scenario(()=>{fs.rmSync(path.join(temp,manifest));put(component,'changed');},'full','deleted manifest is full');
  scenario(()=>{put(manifest,text(nextManifest));fs.chmodSync(path.join(temp,manifest),0o755);put(component,'changed');},'full','manifest executable-bit change is full');
  scenario(()=>{fs.rmSync(path.join(temp,manifest));fs.symlinkSync('editor.json',path.join(temp,manifest));put(component,'changed');},'full','manifest symlink is full');
  scenario(()=>{put(manifest,text(nextManifest));put(component,'changed');put('tools/ci_change_scope.js','// policy');},'full','policy edits retain full coverage');
  git(['reset','--hard',base]);git(['clean','-fdq']);put('main-only.txt','new upstream');const newBase=commit();
  const stale=run(eligible,'pull_request',newBase);
  check(stale.status,1,'stale base remains a fatal preflight');check(stale.output,'','stale base cannot publish a passing scope');
  check(stale.value.blocked,true,'stale base retains original blocked outcome');
} finally {fs.rmSync(temp,{recursive:true,force:true});}
console.log('PUBLIC MANIFEST CI SCOPE: '+checks+' PASS');
