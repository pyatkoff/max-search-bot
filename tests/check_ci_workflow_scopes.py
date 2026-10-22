#!/usr/bin/env python3
"""Exercise workflow gate programs and their dependency wiring without browsers."""
import itertools
import fnmatch
import json
import os
from pathlib import Path
import subprocess
import yaml

root = Path(__file__).resolve().parent.parent
workflows = root / '.github/workflows'
load = lambda name: yaml.safe_load((workflows / name).read_text())
regression = load('regression-tests.yml')['jobs']
visual = load('workspace-v2-visual-qa.yml')['jobs']
checks = 0

def check(condition, message):
    global checks
    assert condition, message
    checks += 1

def gate_passes(job, env):
    step = job['steps'][-1]
    return subprocess.run(['bash', '-e', '-c', step['run']], env={**os.environ, **env}, capture_output=True).returncode == 0

check(set(regression['regression']['needs']) == {'change-scope','inventory','php-syntax','required','prepared-mysql','oauth'}, 'regression must await all branches')
check(set(visual['screenshots']['needs']) == {'change-scope','full-screenshots','oauth-browser','tour-browser'}, 'visual aggregate must await all profile branches')
for name in ['regression-tests.yml','workspace-v2-visual-qa.yml','workspace-sound.yml','channel-post-context.yml']:
    check(load(name)['jobs']['change-scope']['uses'] == './.github/workflows/ci-change-scope.yml', name + ' must use canonical selector')
for name, jobs in [('workspace-v2-visual-qa.yml',['selection','firefox-history','firefox-notifications']),('workspace-sound.yml',['sound','native-mysql']),('channel-post-context.yml',['browser','native-mysql'])]:
    for job in jobs:
        check(load(name)['jobs'][job]['if'] == "needs.change-scope.outputs.mode == 'full'", name + '/' + job + ' must require full mode')
        check('change-scope' in load(name)['jobs'][job]['needs'], name + '/' + job + ' must await selector')
for job in ['required','prepared-mysql']:
    check(regression[job]['if'] == "needs.change-scope.outputs.mode == 'full' || needs.change-scope.outputs.mode == 'tour-selection'", 'selection retains full backend and MySQL: '+job)
    check('change-scope' in regression[job]['needs'],job+' awaits canonical selector')
check("needs.change-scope.outputs.mode == 'full'" in visual['browser-tests']['if'], 'full browser assembler must not run after OAuth-only skips')
check(set(visual['full-screenshots']['needs']) == {'browser-tests','firefox-history','firefox-notifications'}, 'full screenshot proof preserves all engines and Firefox')
check(visual['oauth-browser']['strategy']['matrix']['browser'] == ['chromium','webkit'], 'OAuth requires both browser engines')
oauth_steps = '\n'.join(s.get('run','') for s in visual['oauth-browser']['steps'])
check("--grep 'OAuth native (allow|deny|legacy policy control)'" in oauth_steps, 'OAuth scope must execute only native consent tests' )
check('verify_oauth_browser_report.js oauth-browser.json' in oauth_steps, 'OAuth cannot pass with missing or skipped consent cases')
check('tools/ci_change_scope.js' in str(load('ci-change-scope.yml')), 'canonical selector must execute')
tour=visual['tour-browser']
visual_document=load('workspace-v2-visual-qa.yml')
visual_events=visual_document.get('on',visual_document.get(True))
visual_paths=visual_events['pull_request']['paths']
component_paths=json.loads(subprocess.check_output(['node','-e',"process.stdout.write(JSON.stringify([...require('./tools/ci_change_scope').TOUR_SELECTION_PATHS]))"],cwd=root))
for component_path in component_paths:
    if not component_path.endswith('.md'):
        check(any(fnmatch.fnmatchcase(component_path,pattern) for pattern in visual_paths),'component path would skip its native gate: '+component_path)
check(tour['strategy']['matrix']['browser']==['chromium','webkit'],'selection requires both engines')
check(tour['if']=="needs.change-scope.outputs.mode == 'tour-selection' || needs.change-scope.outputs.tour-proof == 'true'",'CI edits commission scoped runner while full gates remain')
tour_steps='\n'.join(s.get('run','') for s in tour['steps'])
check('--list --reporter=json > tour-selection-expected.json' in tour_steps and '--grep' not in tour_steps,'selection executes and lists the entire component, without grep')
check(tour_steps.count('--forbid-only')==2 and 'verify_tour_selection_browser_report.js tour-selection-browser.json tour-selection-expected.json' in tour_steps,'selection requires complete native execution and evidence')
plan_upload=next(step for step in visual['selection']['steps'] if step.get('id')=='plan-artifact')
check(plan_upload['uses']=='actions/upload-artifact@v4','immutable plan ID comes from actual upload')
check(visual['selection']['outputs']['plan-id']=='${{ steps.plan-artifact.outputs.artifact-id }}','selection publishes actual artifact ID')
plan_steps=visual['browser-parts']['steps']
plan_guard=next(step for step in plan_steps if step.get('name')=='Require the immutable selection plan artifact ID')
plan_download=next(step for step in plan_steps if step.get('name')=='Read exact current selection plan')
check(plan_download['with']=={'artifact-ids':'${{ needs.selection.outputs.plan-id }}','merge-multiple':True,'path':'.'},'failed-only rerun reads original immutable artifact, not a fabricated attempt name')
check(plan_steps.index(plan_guard)<plan_steps.index(plan_download),'empty ID cannot fall through to download every artifact')
prepared=json.loads((root/'tools/browser_qa_environment.json').read_text())
native_jobs={
    'workspace-v2-visual-qa.yml':['selection','browser-parts','firefox-history','firefox-notifications','oauth-browser','tour-browser'],
    'channel-post-context.yml':['browser'],
    'workspace-sound.yml':['sound'],
    'workspace-photo-viewer.yml':['photo-opening'],
    'manager-personal-capacity.yml':['capacity'],
    'manager2-preview.yml':['preview-smoke'],
    'manager2-browser-measurement.yml':['browser'],
    'telegram-dialogue-archive.yml':['setup-browser'],
}
for name,jobs in native_jobs.items():
    document=load(name)
    check(document['permissions']['contents']=='read' and document['permissions']['packages']=='read',name+' only reads source/packages')
    for job_name in jobs:
        job=document['jobs'][job_name]
        check(job['container']['image']==prepared['image'] and job['env']['QA_BROWSER_IMAGE']==prepared['image'],name+'/'+job_name+' must use the committed digest')
        check(job['container']['credentials']=={'username':'${{ github.actor }}','password':'${{ secrets.GITHUB_TOKEN }}'},name+'/'+job_name+' only uses the built-in registry credential')
        check(job['container']['options']=='--init --shm-size=1g',name+'/'+job_name+' preserves browser process/shared-memory support')
        steps=job['steps']
        checkout=next(i for i,s in enumerate(steps) if s.get('uses')=='actions/checkout@v4')
        check(steps[checkout+1].get('run','').splitlines()==['git config --global --add safe.directory "$GITHUB_WORKSPACE"','node /opt/manager2-qa/verify-runtime.js --require-source'],name+'/'+job_name+' must verify the actual runtime immediately after checkout')
        check(all(s.get('shell',job['defaults']['run']['shell'])=='bash /opt/manager2-qa/run {0}' for s in steps if 'run' in s),name+'/'+job_name+' cannot bypass the prepared native shell')
        check(not any(s.get('uses','').startswith(('actions/setup-node@','shivammathur/setup-php@','actions/cache@')) for s in steps),name+'/'+job_name+' must not provision another runtime/cache')
        commands='\n'.join(s.get('run','') for s in steps)
        check(not any(command in commands for command in ['apt-get','npm install','npm ci','playwright install']),name+'/'+job_name+' must not reinstall OS/browser/packages')
    events=document.get('on',document.get(True))
    for event in ['pull_request','push']:
        if event in events:
            paths=events[event]['paths']
            for source in ['.github/browser-qa/run','tools/browser_qa_environment.json','tools/browser_qa_environment.js']:
                check(any(fnmatch.fnmatchcase(source,pattern) for pattern in paths),name+' skips a prepared environment change: '+source)
check(set(load('manager2-preview.yml').get('on',load('manager2-preview.yml').get(True)))=={'push','workflow_dispatch'},'preview remains a separate non-release workflow')
builder=load('browser-qa-environment.yml')['jobs']['image']
builder_names=[s.get('name') for s in builder['steps']]
check(builder_names.index('Verify PHP and native Chromium Firefox WebKit before publication')<builder_names.index('Publish and record the immutable environment digest'),'unverified native environment cannot be published')
check(builder['permissions']=={'contents':'read','packages':'write'},'only the separate environment builder may publish a package')
for value in ['123','', '0','01','1,2','foreign','-1']:
    result=subprocess.run(['bash','-e','-c',plan_guard['run']],env={**os.environ,'PLAN_ARTIFACT_ID':value},capture_output=True).returncode
    check((result==0)==(value=='123'),'plan artifact ID guard rejected wrong value: '+value)
for mode in ['oauth','full','tour-selection','', 'invalid']:
    full_backend=mode in ['full','tour-selection']
    valid = dict(CI_MODE=mode,SCOPE_RESULT='success',INVENTORY_RESULT='success',SYNTAX_RESULT='success',REQUIRED_RESULT='success' if full_backend else 'skipped',PREPARED_MYSQL_RESULT='success' if full_backend else 'skipped',OAUTH_RESULT='success' if mode=='oauth' else 'skipped')
    cases=[valid]
    for key in ['SCOPE_RESULT','INVENTORY_RESULT','SYNTAX_RESULT','REQUIRED_RESULT','PREPARED_MYSQL_RESULT','OAUTH_RESULT']:
        for value in ['success','failure','skipped','cancelled']:
            cases.append({**valid,key:value})
    for env in cases:
        expected = env['SCOPE_RESULT'] == env['INVENTORY_RESULT'] == env['SYNTAX_RESULT'] == 'success' and ((mode == 'oauth' and env['OAUTH_RESULT'] == 'success' and env['REQUIRED_RESULT'] == env['PREPARED_MYSQL_RESULT'] == 'skipped') or (full_backend and env['REQUIRED_RESULT'] == env['PREPARED_MYSQL_RESULT'] == 'success' and env['OAUTH_RESULT'] == 'skipped'))
        check(gate_passes(regression['regression'],env) == expected, 'regression gate accepted wrong results: ' + str(env))
for mode,scope,full,oauth in itertools.product(['oauth','full','','invalid'], ['success','failure','skipped','cancelled'], ['success','failure','skipped','cancelled'], ['success','failure','skipped','cancelled']):
    env=dict(CI_MODE=mode,SCOPE_RESULT=scope,FULL_RESULT=full,OAUTH_RESULT=oauth,TOUR_RESULT='skipped',TOUR_PROOF='false')
    expected=scope=='success' and ((mode=='oauth' and oauth=='success' and full=='skipped') or (mode=='full' and full=='success' and oauth=='skipped'))
    check(gate_passes(visual['screenshots'],env)==expected,'visual gate accepted wrong results: '+str(env))
for mode,proof,full,oauth,tour_result in [('tour-selection','false','skipped','skipped','success'),('full','true','success','skipped','success')]:
    valid=dict(CI_MODE=mode,TOUR_PROOF=proof,SCOPE_RESULT='success',FULL_RESULT=full,OAUTH_RESULT=oauth,TOUR_RESULT=tour_result)
    check(gate_passes(visual['screenshots'],valid),'new profile baseline must pass')
    for field in ['SCOPE_RESULT','FULL_RESULT','OAUTH_RESULT','TOUR_RESULT']:
        for value in ['success','failure','skipped','cancelled','timed_out','']:
            env={**valid,field:value}
            check(gate_passes(visual['screenshots'],env)==(value==valid[field]),'new profile hid missing/foreign evidence: '+str(env))
    for field,values in [('CI_MODE',['','unknown','oauth']),('TOUR_PROOF',['','invalid','0','1'])]:
        for value in values:
            check(not gate_passes(visual['screenshots'],{**valid,field:value}),'malformed profile/proof may not pass')
print(f'PASS {checks} workflow selection and gate cases')
