#!/usr/bin/env node
'use strict';

// Execute the actual selector and workflow transport against disposable Git
// histories. No product tests, browsers, database or remote actions run here.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');
const { SELECTION_CLI_TEST_PATHS, selectionCliTestsOnlyChanges } = require('../tools/ci_change_scope');
const root = path.resolve(__dirname, '..');
const selector = path.join(root, 'tools/ci_change_scope.js');
const scope = fs.readFileSync(path.join(root, '.github/workflows/ci-change-scope.yml'), 'utf8');
const body = /        id: scope\n        run: \|\n((?:          [^\n]*\n)+)/.exec(scope);
assert.ok(body, 'canonical scope must expose its executable transport');
const shellQuote = value => "'" + value.replaceAll("'", "'\\''") + "'";
const script = body[1].replace(/^ {10}/gm, '')
  .replace('node tools/ci_change_scope.js', 'node ' + shellQuote(selector));
const files = [
  'tests/run_manager_tour_selection_regression.php',
  'tests/run_manager_tour_delivery_regression.php',
  'tests/run_manager_tour_share_regression.php',
  'tests/run_manager_tour_collector_regression.php',
  'tests/run_manager_tour_selection_mcp_regression.php',
];
const changed = file => ({ path: file, status: 'M', oldMode: '100644', newMode: '100644' });
let assertions = 0;
function check(actual, expected, label) { assert.deepEqual(actual, expected, label); assertions++; }
check(SELECTION_CLI_TEST_PATHS instanceof Set, true, 'explicit CLI entrypoint set');
check([...SELECTION_CLI_TEST_PATHS].sort(), [...files].sort(), 'exact five audited CLI entrypoints');
for (const file of files) check(selectionCliTestsOnlyChanges([changed(file)]), true, 'isolated CLI entrypoint '+file);
check(selectionCliTestsOnlyChanges(files.map(changed)), true, 'combined CLI entrypoints');
for (const changes of [null, undefined, [], [{}], [null], 'tests']) {
  check(selectionCliTestsOnlyChanges(changes), false, 'empty or malformed change list');
}
const mixed = [
  ['manager/assets/workspace-v2-tour-selection.js', 'tour-selection'],
  ['manager/assets/workspace-v2-tour-selection.css', 'tour-selection'],
  ['tests/support/manager-tour-selection.php', 'tour-selection'],
  ['tests/visual/fixtures/selection-private-editor.js', 'tour-selection'],
  ['tests/visual/manager-tour-selection.spec.js', 'tour-selection'],
  // This JS test exports helpers consumed by the real browser suite.
  ['tests/run_manager_tour_collector_behavior_regression.js', 'tour-selection'],
  ['docs/MANAGER_TOUR_SELECTIONS.md', 'tour-selection'],
  ['manager/ai-oauth.php', 'full'],
  ['manager/lib/ManagerHttp.php', 'full'],
  ['migrations/999_fixture.sql', 'full'],
  ['tools/ci_change_scope.js', 'full'],
];
for (const [file] of mixed) {
  check(selectionCliTestsOnlyChanges([changed(files[0]), changed(file)]), false, 'mixed input '+file);
}
for (const file of files) {
  for (const status of ['A','D','R100','C100','T','U','',undefined]) {
    check(selectionCliTestsOnlyChanges([{...changed(file),status}]), false, 'unsupported status '+file+'/'+status);
  }
  for (const [oldMode,newMode] of [['100644','100755'],['100755','100755'],['120000','120000'],['160000','160000'],[undefined,undefined]]) {
    check(selectionCliTestsOnlyChanges([{...changed(file),oldMode,newMode}]), false, 'unsupported mode '+file);
  }
  check(selectionCliTestsOnlyChanges([{...changed(file),previousPath:'old.php'}]), false, 'copy/rename '+file);
  for (const adjacent of ['./'+file,file+'.bak',file+'/extra']) {
    check(selectionCliTestsOnlyChanges([changed(adjacent)]), false, 'unknown adjacent path '+adjacent);
  }
}

const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'selection-cli-scope-'));
const repo = path.join(temp, 'repo');
fs.mkdirSync(repo);
const eventPath = path.join(temp, 'event.json'), output = path.join(temp, 'outputs');
const git = args => execFileSync('git', args, {cwd:repo, encoding:'utf8', stdio:['ignore','pipe','pipe']}).trim();
const put = (file, content) => { const target=path.join(repo,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,content); };
const editor = 'modules/tour-selections/editor.json', publicManifest = 'modules/tour-selections/module.json';
const manifest = (name, contract) => ({schema:1,name,version:'1.0.0',contract,
  files:{'style.css':'selection/selection.css'},backend_inputs:['manager/lib/ManagerHttp.php']});
const editorBase=manifest('anytour-selection-editor','tour-selection-editor-host-v1');
const publicBase=manifest('anytour-selection-public','selection-php-view-v1');
const fields = () => Object.fromEntries(fs.readFileSync(output,'utf8').trim().split('\n').filter(Boolean).map(line=>line.split('=')));
function execute(command, overrides={}) {
  fs.writeFileSync(output,'');
  return spawnSync('bash',['-e','-c',command],{cwd:repo,encoding:'utf8',timeout:15000,env:{...process.env,
    GITHUB_EVENT_NAME:'pull_request',GITHUB_EVENT_PATH:eventPath,GITHUB_OUTPUT:output,...overrides}});
}
try {
  git(['init','-q']);git(['config','user.name','CI fixture']);git(['config','user.email','ci@example.invalid']);git(['config','core.filemode','true']);
  for (const file of [...files,...mixed.map(([file])=>file)]) put(file,'base\n');
  put(editor,JSON.stringify(editorBase));put(publicManifest,JSON.stringify(publicBase));
  git(['add','.']);git(['commit','-qm','base']);const base=git(['rev-parse','HEAD']);
  function run(head, mode, browser, label, overrides={}, proof=false, expectedBase=base) {
    fs.writeFileSync(eventPath,JSON.stringify({pull_request:{base:{sha:expectedBase},head:{sha:head}}}));
    const result=execute(script,overrides);
    check(result.status,0,label+' executes: '+result.stderr);
    const selected=JSON.parse(result.stdout);
    check(fields(),{mode,'tour-proof':String(proof),backend:'full',browser},label+' exact workflow outputs');
    if(browser==='none') {
      check(selected,{mode:'tour-selection',reason:'selection_cli_tests_only',backend:'full',browser:'none',proveTourSelection:false},label+' exact audited declaration');
    } else check(selected.browser??selected.mode,browser,label+' ordinary browser scope');
    return head;
  }
  function scenario(label, mutate, mode='tour-selection', browser=mode, proof=false) {
    git(['reset','--hard','-q',base]);git(['clean','-fdq']);mutate();git(['add','-A']);git(['commit','-qm',label]);
    return run(git(['rev-parse','HEAD']),mode,browser,label,{},proof);
  }
  for(const file of files) scenario('CLI only '+file,()=>put(file,'changed\n'),'tour-selection','none');
  const cliHead=scenario('all five CLI tests',()=>files.forEach(file=>put(file,'changed\n')),'tour-selection','none');
  for(const [file,mode] of mixed) {
    scenario('CLI plus '+file,()=>{put(files[0],'changed\n');put(file,'changed\n');},mode,mode,file==='tools/ci_change_scope.js');
  }
  scenario('MCP test plus component runtime',()=>{put(files[4],'changed\n');put(mixed[0][0],'changed\n');},'full');
  scenario('CLI plus unknown new test',()=>{put(files[0],'changed\n');put('tests/run_manager_tour_unknown_regression.php','new\n');},'full');
  for(const [file,original] of [[editor,editorBase],[publicManifest,publicBase]]) {
    scenario('CLI plus version-only '+file,()=>{put(files[0],'changed\n');put(file,JSON.stringify({...original,version:'1.0.1'}));});
    scenario('CLI plus changed manifest contract '+file,()=>{put(files[0],'changed\n');put(file,JSON.stringify({...original,version:'1.0.1',contract:'unreviewed'}));},'full');
  }
  scenario('CLI deletion',()=>fs.unlinkSync(path.join(repo,files[0])),'full');
  scenario('CLI rename',()=>fs.renameSync(path.join(repo,files[0]),path.join(repo,files[0]+'.old')),'full');
  scenario('CLI executable mode',()=>fs.chmodSync(path.join(repo,files[0]),0o755),'full');
  scenario('CLI symlink',()=>{fs.unlinkSync(path.join(repo,files[0]));fs.symlinkSync('other.php',path.join(repo,files[0]));},'full');
  for(const event of ['push','workflow_dispatch','']) run(cliHead,'full','full','non-PR '+event,{GITHUB_EVENT_NAME:event});
  run(base,'full','full','empty diff');run('f'.repeat(40),'full','full','unavailable commit');
  run(cliHead,'full','full','unavailable event',{GITHUB_EVENT_PATH:path.join(temp,'absent.json')});
  git(['reset','--hard','-q',base]);git(['clean','-fdq']);put('new-base.txt','main moved\n');git(['add','.']);git(['commit','-qm','new base']);
  fs.writeFileSync(eventPath,JSON.stringify({pull_request:{base:{sha:git(['rev-parse','HEAD'])},head:{sha:cliHead}}}));
  const blocked=execute(script);
  check(blocked.status,1,'stale base fails before any mode output');check(fields(),{},'stale base cannot declare browser skips');

  // Exercise the existing shell's defense against malformed selector output,
  // separately from valid Git classifications. It must never normalize a
  // foreign/missing declaration into permission to omit native evidence.
  const fake=path.join(temp,'selector-result.js');
  fs.writeFileSync(fake,"const fs=require('node:fs');const r=JSON.parse(process.env.SYNTHETIC_SCOPE_RESULT);fs.appendFileSync(process.env.GITHUB_OUTPUT,`mode=${r.mode}\\ntour-proof=${r.proveTourSelection===true}\\n`);console.log(JSON.stringify(r));\n");
  const transport=script.replace(shellQuote(selector),shellQuote(fake));
  const valid={mode:'tour-selection',reason:'selection_cli_tests_only',backend:'full',browser:'none',proveTourSelection:false};
  for(const patch of [{mode:'full'},{mode:'unknown'},{reason:'other'},{reason:undefined},{backend:'selection-style'},
    {backend:undefined},{proveTourSelection:true},{proveTourSelection:undefined},{browser:'unknown'},{browser:''},{browser:null}]) {
    const result=execute(transport,{SYNTHETIC_SCOPE_RESULT:JSON.stringify({...valid,...patch})});
    check(result.status!==0,true,'malformed browser declaration fails: '+JSON.stringify(patch));
    check(fields().browser,undefined,'failed transport publishes no browser exemption');
  }
} finally {fs.rmSync(temp,{recursive:true,force:true});}
console.log(`PASS ${assertions} isolated CLI scope and output assertions`);
