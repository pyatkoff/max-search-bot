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

def gate_passes(job, env, step_name=None):
    step = next(s for s in job['steps'] if s.get('name') == step_name) if step_name else job['steps'][-1]
    return subprocess.run(['bash', '-e', '-c', step['run']], env={**os.environ, **env}, capture_output=True).returncode == 0

# Required regression owns PR policy validation. Known companions omit only
# the identical selftest prelude, never their selector or native checks.
scope_steps = load('ci-change-scope.yml')['jobs']['scope']['steps']
policy_steps = [step for step in scope_steps if step.get('name') == 'Verify CI selection rules']
check(len(policy_steps) == 1, 'exactly one canonical policy validation step')
policy = policy_steps[0]
check(policy.get('if') == "github.event_name != 'pull_request' || !contains(fromJSON('[\"Workspace V2 visual QA\",\"Workspace incoming sound\",\"Channel post context\"]'), github.workflow)", 'only known PR companions may omit duplicate policy validation')
check(not policy.get('continue-on-error', False), 'policy validation failures remain fatal')
for command in ['node tests/run_ci_change_scope_regression.js', 'node tests/run_oauth_browser_report_regression.js', 'python3 tests/check_ci_workflow_scopes.py']:
    check(command in policy['run'], 'required policy validation retains ' + command)
selection_steps = [step for step in scope_steps if step.get('id') == 'scope']
check(len(selection_steps) == 1 and not selection_steps[0].get('if') and not selection_steps[0].get('continue-on-error', False), 'every caller must run the fail-closed selector')
check(load('regression-tests.yml')['name'] == 'Regression tests', 'required regression always owns PR policy validation')
for filename, name in [('workspace-v2-visual-qa.yml', 'Workspace V2 visual QA'), ('workspace-sound.yml', 'Workspace incoming sound'), ('channel-post-context.yml', 'Channel post context')]:
    check(load(filename)['name'] == name, 'known companion name changed: ' + filename)
for filename in ['regression-tests.yml', 'workspace-sound.yml', 'channel-post-context.yml', 'manager-personal-capacity.yml']:
    concurrency = load(filename).get('concurrency', {})
    check(concurrency.get('cancel-in-progress') == "${{ github.event_name == 'pull_request' }}", 'only superseded PRs may be cancelled: ' + filename)
    check(concurrency.get('group') == "ci-${{ github.workflow }}-${{ github.event_name == 'pull_request' && format('pr-{0}', github.event.pull_request.number) || format('run-{0}-{1}', github.run_id, github.run_attempt) }}", 'PR/workflow isolation and unique manual runs required: ' + filename)

check(set(regression['regression']['needs']) == {'change-scope','inventory','php-syntax','required','prepared-mysql','appleservice39-mysql','oauth'}, 'regression must await all branches')
# Source-result aggregation and mutable release admission are separate steps.
# Exercise every old truth-table case below against the source gate, while the
# existing Node policy suite executes admission/failure behavior with API fixtures.
aggregate = regression['regression']
source_gates = [s for s in aggregate['steps'] if s.get('name') == 'Require every gate']
admissions = [s for s in aggregate['steps'] if s.get('name') == 'Respect ready release priority']
check(len(source_gates) == len(admissions) == 1, 'one source gate and one final release admission')
source_gate, admission = source_gates[0], admissions[0]
check(not source_gate.get('if') and not source_gate.get('continue-on-error', False), 'source-result failures remain fatal')
check(aggregate['steps'].index(source_gate) < aggregate['steps'].index(admission), 'admission cannot replace source-result aggregation')
check(admission.get('if') == "github.event_name == 'pull_request'", 'every PR needs release admission; manual validation cannot merge')
check(admission.get('run') == 'node tools/release_priority.js' and not admission.get('continue-on-error', False), 'canonical admission executes and fails closed')
check(not aggregate.get('continue-on-error', False), 'aggregate cannot hide admission refusal')
check(aggregate.get('permissions') == {'contents': 'read', 'pull-requests': 'read'}, 'release admission cannot mutate GitHub state')
check(admission.get('env', {}).get('GITHUB_TOKEN') == '${{ secrets.GITHUB_TOKEN }}', 'admission uses only the built-in read capability')
checkout = next(s for s in aggregate['steps'] if s.get('uses') == 'actions/checkout@v4')
check(aggregate['steps'].index(checkout) < aggregate['steps'].index(admission) and checkout.get('with', {}).get('persist-credentials') is False, 'admission reads checked source without persisted token')
check(set(visual['screenshots']['needs']) == {'change-scope','full-screenshots','oauth-browser','tour-browser'}, 'visual aggregate must await all profile branches')
for name in ['regression-tests.yml','workspace-v2-visual-qa.yml','workspace-sound.yml','channel-post-context.yml']:
    check(load(name)['jobs']['change-scope']['uses'] == './.github/workflows/ci-change-scope.yml', name + ' must use canonical selector')
for name, jobs in [('workspace-v2-visual-qa.yml',['selection','firefox-history','firefox-notifications']),('workspace-sound.yml',['sound','native-mysql']),('channel-post-context.yml',['browser','native-mysql'])]:
    for job in jobs:
        check(load(name)['jobs'][job]['if'] == "needs.change-scope.outputs.mode == 'full'", name + '/' + job + ' must require full mode')
        check('change-scope' in load(name)['jobs'][job]['needs'], name + '/' + job + ' must await selector')
for job in ['required','prepared-mysql','appleservice39-mysql']:
    check('change-scope' in regression[job]['needs'],job+' awaits canonical selector')
check(regression['required']['if'] == "needs.change-scope.outputs.mode == 'full' || needs.change-scope.outputs.mode == 'tour-selection'", 'every selection profile retains architecture')
check(regression['prepared-mysql']['if'] == "(needs.change-scope.outputs.mode == 'full' && needs.change-scope.outputs.backend != 'workspace-style') || (needs.change-scope.outputs.mode == 'tour-selection' && needs.change-scope.outputs.backend != 'selection-style')", 'only audited styling may omit unchanged MySQL coverage')
expected_exclude = "${{ fromJSON(((needs.change-scope.outputs.mode == 'tour-selection' && needs.change-scope.outputs.backend == 'selection-style') || (needs.change-scope.outputs.mode == 'full' && needs.change-scope.outputs.backend == 'workspace-style')) && '[{\"group\":\"dialogue\"},{\"group\":\"website\"},{\"group\":\"manager\"},{\"group\":\"diagnostics\"}]' || '[]') }}"
check(regression['required']['strategy']['matrix'] == {
    'group':['architecture','dialogue','website','manager','diagnostics'], 'exclude':expected_exclude},
    'styling excludes exactly four unchanged server groups; other profiles keep all five')
check(source_gate['env']['BACKEND_PROFILE'] == '${{ needs.change-scope.outputs.backend }}', 'aggregate uses the actual backend selection')
pilot_mysql = regression['appleservice39-mysql']
check(pilot_mysql['if'] == regression['prepared-mysql']['if']
      and pilot_mysql['needs'] == 'change-scope'
      and pilot_mysql['runs-on'] == 'ubuntu-latest'
      and pilot_mysql['timeout-minutes'] == 8
      and not pilot_mysql.get('continue-on-error', False),
      'pilot native CAS is a fatal required job for each full backend profile')
check(pilot_mysql['services'] == {'mysql': {
    'image':'mysql:8.0',
    'env':{'MYSQL_ROOT_PASSWORD':'as39-test-only','MYSQL_ROOT_HOST':'%','MYSQL_DATABASE':'appleservice39_publisher_test'},
    'ports':['3306:3306'],
    'options':'--health-cmd="mysqladmin ping -h 127.0.0.1 -pas39-test-only" --health-interval=5s --health-timeout=5s --health-retries=20',
}}, 'pilot claims require their own isolated native database and readiness gate')
pilot_steps = pilot_mysql['steps']
check(len(pilot_steps) == 3
      and pilot_steps[0] == {'uses':'actions/checkout@v4'}
      and pilot_steps[1] == {'uses':'shivammathur/setup-php@v2','with':{
          'php-version':'8.3','extensions':'curl, mbstring, pdo_mysql','coverage':'none'}}
      and all('if' not in step and not step.get('continue-on-error', False) for step in pilot_steps),
      'native pilot fixture uses checked source and cannot be skipped or suppress failure')
check(pilot_steps[2]['run'] == 'php tests/mysql/appleservice39-publisher.php'
      and pilot_steps[2]['env'] == {
          'APPLESERVICE39_TEST_MYSQL_DSN':'mysql:host=127.0.0.1;port=3306;dbname=appleservice39_publisher_test;charset=utf8mb4',
          'APPLESERVICE39_TEST_MYSQL_USER':'root','APPLESERVICE39_TEST_MYSQL_PASSWORD':'as39-test-only'},
      'actual migration and independent claim workers execute on the isolated fixture')
check(source_gate['env']['APPLESERVICE39_MYSQL_RESULT'] == '${{ needs.appleservice39-mysql.result }}',
      'required aggregate consumes the actual pilot native job result')
check('native-mysql' not in load('publish-appleservice39-pilot.yml')['jobs'],
      'pilot native fixture has one mandatory aggregate owner')
scope_document = load('ci-change-scope.yml')
scope_events = scope_document.get('on',scope_document.get(True))
check(scope_events['workflow_call']['outputs']['backend']['value'] == '${{ jobs.scope.outputs.backend }}'
      and scope_document['jobs']['scope']['outputs']['backend'] == '${{ steps.scope.outputs.backend }}',
      'backend profile is bound through the canonical scope job')
check('node tests/check_ci_selection_style_scope.js' in policy['run'], 'style selector selftests are mandatory')
check("needs.change-scope.outputs.mode == 'full'" in visual['browser-tests']['if'], 'full browser assembler must not run after OAuth-only skips')
check(set(visual['full-screenshots']['needs']) == {'browser-tests','firefox-history','firefox-notifications'}, 'full screenshot proof preserves all engines and Firefox')
check(visual['oauth-browser']['strategy']['matrix']['browser'] == ['chromium','webkit'], 'OAuth requires both browser engines')
oauth_steps = '\n'.join(s.get('run','') for s in visual['oauth-browser']['steps'])
check("--grep 'OAuth native (allow|deny|legacy policy control)'" in oauth_steps, 'OAuth scope must execute only native consent tests' )
check('verify_oauth_browser_report.js oauth-browser.json' in oauth_steps, 'OAuth cannot pass with missing or skipped consent cases')
check('tools/ci_change_scope.js' in str(load('ci-change-scope.yml')), 'canonical selector must execute')
tour=visual['tour-browser']
tour_parts=visual['tour-browser-parts']
visual_document=load('workspace-v2-visual-qa.yml')
visual_events=visual_document.get('on',visual_document.get(True))
visual_paths=visual_events['pull_request']['paths']
def visual_triggered(*changed_paths):
    # GitHub applies ordered include/exclude patterns to each changed path,
    # then starts the workflow if any path remains included.
    for changed_path in changed_paths:
        included=False
        for pattern in visual_paths:
            excluded=pattern.startswith('!')
            if fnmatch.fnmatchcase(changed_path,pattern[1:] if excluded else pattern):
                included=not excluded
        if included:
            return True
    return False

component_paths=json.loads(subprocess.check_output(['node','-e',"process.stdout.write(JSON.stringify([...require('./tools/ci_change_scope').TOUR_SELECTION_PATHS]))"],cwd=root))
regression_document=load('regression-tests.yml')
regression_events=regression_document.get('on',regression_document.get(True))
check('paths' not in regression_events['pull_request'] and 'paths-ignore' not in regression_events['pull_request'], 'every component change retains required backend regression invocation')
for component_path in component_paths:
    if not component_path.endswith('.md'):
        check(visual_triggered(component_path),'component path would skip its native gate: '+component_path)
editor_manifest='modules/tour-selections/editor.json'
check(visual_triggered(editor_manifest),'manifest-only releases and contract changes need their selected native gate')
check(visual_triggered('modules/tour-selections/editor-tours.php'),'wrapper-only edits must run complete native selection coverage')
check(visual_triggered('tests/visual/fixtures/selection-private-editor.js'),'private editor fixture-only edits must run complete native selection coverage')
editor_document=load('tour-selection-editor-module.yml')
editor_events=editor_document.get('on',editor_document.get(True))
for event in ['pull_request','push']:
    for editor_path in [editor_manifest,'modules/tour-selections/editor-tours.php','manager/assets/workspace-v2-tour-selection.js','manager/assets/workspace-v2-tour-selection.css']:
        check(any(fnmatch.fnmatchcase(editor_path,pattern) for pattern in editor_events[event]['paths']),event+' must retain the exact editor package gate: '+editor_path)
editor_steps='\n'.join(step.get('run','') for step in editor_document['jobs']['editor-module']['steps'])
for command in ['python3 tests/run_tour_selection_editor_module.py','node tests/run_tour_selection_editor_host.js',
                'python3 modules/tour-selections/build-editor.py build','python3 modules/tour-selections/build-editor.py verify',
                'php modules/tour-selections/tests/test_editor_deploy.php','php tests/run_tour_selection_editor_private.php']:
    check(command in editor_steps,'component scope cannot remove package, host, install/rollback or private HTTP proof: '+command)
collector_readme='extensions/selection-collector/README.md'
check(not visual_triggered(collector_readme),'collector README-only change must not launch the full native panel')
for runtime_path in ['extensions/selection-collector/popup.js','extensions/selection-collector/manifest.json',
                     'extensions/selection-collector/build_package.py','extensions/selection-collector/anytour-selection-collector.zip',
                     'extensions/selection-collector/new-adapter.js']:
    check(visual_triggered(runtime_path),'collector runtime/package path must retain a native gate: '+runtime_path)
    check(visual_triggered(collector_readme,runtime_path),'README must not suppress another changed runtime path: '+runtime_path)
check(tour['strategy']['matrix']['browser']==['chromium','webkit'],'selection requires both engines')
component_condition="needs.change-scope.outputs.mode == 'tour-selection' || needs.change-scope.outputs.tour-proof == 'true'"
check(tour_parts['if']==component_condition,'CI edits commission both component parts while full gates remain')
check(tour_parts['needs']=='change-scope' and set(tour['needs'])=={'change-scope','tour-browser-parts'},'component parts and assembly retain canonical dependencies')
check(tour['if']=='always() && ('+component_condition+')','selected assembly must reject missing or failed parts')
check(tour_parts['strategy']['matrix']=={'browser':['chromium','webkit'],'part':[0,1]},'exactly two isolated parts per engine')
check(tour_parts['strategy']['fail-fast'] is False and tour['strategy']['fail-fast'] is False,'one failed part cannot cancel its successful siblings')
part_steps=tour_parts['steps']
native_list=next(s for s in part_steps if s.get('name')=='List the complete component inventory')
check(native_list['run']=='npx playwright test tests/visual/manager-tour-selection.spec.js --browser=${{ matrix.browser }} --workers=1 --forbid-only --list --reporter=json > tour-selection-expected.json','each part starts from a fresh complete unfiltered native inventory')
part_run=next(s for s in part_steps if s.get('name')=='Execute this exact component part')
part_record=next(s for s in part_steps if s.get('name')=='Require original component part evidence')
check(part_run['run']=='node tools/verify_tour_selection_browser_report.js run-part ${{ matrix.browser }} ${{ matrix.part }}','component executes the canonical exact-key allocation')
check(part_record['run']=='node tools/verify_tour_selection_browser_report.js record-part ${{ matrix.browser }} ${{ matrix.part }}','each part must verify execution before producing a receipt')
check(part_steps.index(native_list)<part_steps.index(part_run)<part_steps.index(part_record),'full listing precedes filtered execution and proof')
for step in [native_list,part_run,part_record]:
    check(not step.get('if') and not step.get('continue-on-error',False),'component evidence cannot ignore a failed producer')
part_upload=next(s for s in part_steps if s.get('uses')=='actions/upload-artifact@v4')
check(part_upload['with']['name']=='tour-selection-part-${{ matrix.browser }}-${{ matrix.part }}-${{ github.sha }}','part artifacts have source/engine/part identity')
check(part_upload['with']['path'].splitlines()==['tour-selection-part-receipt.json','tour-selection-browser.json','tour-selection-expected.json','tour-selection-filtered.json','manager2-artifacts/case-part-${{ matrix.part }}--tour-selection-*.png','test-results/**/error-context.md'],'retain all original reports and namespaced component PNG without importing full-only captures')
check(tour['env']['QA_PARTS_RESULT']=='${{ needs.tour-browser-parts.result }}','assembly uses the real matrix result')
check(tour['steps'][0]['name']=='Require successful component parts','failed parts stop assembly before any download')
for result in ['success','failure','skipped','cancelled','timed_out','']:
    check(gate_passes(tour,{'QA_PARTS_RESULT':result},'Require successful component parts')==(result=='success'),'component assembly hid matrix result '+result)
downloads=[s for s in tour['steps'] if s.get('uses')=='actions/download-artifact@v4']
check([s['with'] for s in downloads]==[
    {'name':'tour-selection-part-${{ matrix.browser }}-0-${{ github.sha }}','path':'tour-parts/${{ matrix.browser }}/part-0'},
    {'name':'tour-selection-part-${{ matrix.browser }}-1-${{ github.sha }}','path':'tour-parts/${{ matrix.browser }}/part-1'}],
    'download exactly two current-source artifacts into separate original directories')
assembly=next(s for s in tour['steps'] if s.get('name')=='Require complete current-source component evidence')
check(assembly['run']=='node tools/verify_tour_selection_browser_report.js assemble ${{ matrix.browser }} tour-parts tour-evidence' and not assembly.get('if') and not assembly.get('continue-on-error',False),'component union must verify before publication')
assembled_upload=next(s for s in tour['steps'] if s.get('uses')=='actions/upload-artifact@v4')
check(assembled_upload['with']['name']=='tour-selection-browser-${{ matrix.browser }}-${{ github.sha }}' and assembled_upload['with']['path']=='tour-evidence/' and not assembled_upload.get('if'),'stable complete component artifact retains nested original proofs only after successful assembly')
check(tour['steps'].index(assembly)<tour['steps'].index(assembled_upload),'failed proof cannot become a complete artifact')
check('playwright test' not in '\n'.join(s.get('run','') for s in tour['steps']) and 'container' not in tour,'assembly does not execute browsers again')
check(visual_triggered('tools/manager2_visual_shards.js'),'shared allocator changes require native commissioning')
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
    'workspace-v2-visual-qa.yml':['selection','browser-parts','firefox-history','firefox-notifications','oauth-browser','tour-browser-parts'],
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
        check(steps[checkout+1].get('run','').splitlines()==['chown "$(id -u):$(id -g)" "$HOME"','git config --global --add safe.directory "$GITHUB_WORKSPACE"','node /opt/manager2-qa/verify-runtime.js --require-source'],name+'/'+job_name+' must verify the actual runtime immediately after checkout')
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
    valid = dict(CI_MODE=mode,BACKEND_PROFILE='full',SCOPE_RESULT='success',INVENTORY_RESULT='success',SYNTAX_RESULT='success',REQUIRED_RESULT='success' if full_backend else 'skipped',PREPARED_MYSQL_RESULT='success' if full_backend else 'skipped',APPLESERVICE39_MYSQL_RESULT='success' if full_backend else 'skipped',OAUTH_RESULT='success' if mode=='oauth' else 'skipped')
    cases=[valid]
    for key in ['SCOPE_RESULT','INVENTORY_RESULT','SYNTAX_RESULT','REQUIRED_RESULT','PREPARED_MYSQL_RESULT','APPLESERVICE39_MYSQL_RESULT','OAUTH_RESULT']:
        for value in ['success','failure','skipped','cancelled','timed_out','']:
            cases.append({**valid,key:value})
    for env in cases:
        expected = env['SCOPE_RESULT'] == env['INVENTORY_RESULT'] == env['SYNTAX_RESULT'] == 'success' and ((mode == 'oauth' and env['OAUTH_RESULT'] == 'success' and env['REQUIRED_RESULT'] == env['PREPARED_MYSQL_RESULT'] == env['APPLESERVICE39_MYSQL_RESULT'] == 'skipped') or (full_backend and env['REQUIRED_RESULT'] == env['PREPARED_MYSQL_RESULT'] == env['APPLESERVICE39_MYSQL_RESULT'] == 'success' and env['OAUTH_RESULT'] == 'skipped'))
        check(gate_passes(regression['regression'],env,'Require every gate') == expected, 'regression gate accepted wrong results: ' + str(env))
# Exercise the actual aggregate, including absent/foreign profile results.
style_valid = dict(CI_MODE='tour-selection',BACKEND_PROFILE='selection-style',
    SCOPE_RESULT='success',INVENTORY_RESULT='success',SYNTAX_RESULT='success',
    REQUIRED_RESULT='success',PREPARED_MYSQL_RESULT='skipped',APPLESERVICE39_MYSQL_RESULT='skipped',OAUTH_RESULT='skipped')
check(gate_passes(aggregate,style_valid,'Require every gate'),'audited styling baseline passes')
for field in ['SCOPE_RESULT','INVENTORY_RESULT','SYNTAX_RESULT','REQUIRED_RESULT','PREPARED_MYSQL_RESULT','APPLESERVICE39_MYSQL_RESULT','OAUTH_RESULT']:
    for value in ['success','failure','skipped','cancelled','timed_out','']:
        check(gate_passes(aggregate,{**style_valid,field:value},'Require every gate') == (value == style_valid[field]),
              'styling gate cannot hide a missing or incorrect result: '+field+'='+value)
for mode,profile in itertools.product(['oauth','full','tour-selection','','unknown'],['selection-style','workspace-style','full','','unknown']):
    check(gate_passes(aggregate,{**style_valid,'CI_MODE':mode,'BACKEND_PROFILE':profile},'Require every gate')
          == ((mode == 'tour-selection' and profile == 'selection-style') or (mode == 'full' and profile == 'workspace-style')),
          'a styling result cannot certify another or missing profile')

# Independently exercise the source-result gate for the new backend profile.
# Its browser mode remains full; existing full visual truth tables still apply.
workspace_valid = {**style_valid,'CI_MODE':'full','BACKEND_PROFILE':'workspace-style'}
check(gate_passes(aggregate,workspace_valid,'Require every gate'),'workspace styling baseline passes')
for field in ['SCOPE_RESULT','INVENTORY_RESULT','SYNTAX_RESULT','REQUIRED_RESULT','PREPARED_MYSQL_RESULT','APPLESERVICE39_MYSQL_RESULT','OAUTH_RESULT']:
    for value in ['success','failure','skipped','cancelled','timed_out','']:
        check(gate_passes(aggregate,{**workspace_valid,field:value},'Require every gate') == (value == workspace_valid[field]),
              'workspace styling cannot hide a missing or incorrect result: '+field+'='+value)
check(visual_triggered('manager/assets/workspace-v2-tour-choices.css'),'booking stylesheet must launch full browser proof')

for mode in ['full','tour-selection','oauth']:
    backend = mode != 'oauth'
    full_valid = dict(CI_MODE=mode,SCOPE_RESULT='success',INVENTORY_RESULT='success',SYNTAX_RESULT='success',
        REQUIRED_RESULT='success' if backend else 'skipped',PREPARED_MYSQL_RESULT='success' if backend else 'skipped',APPLESERVICE39_MYSQL_RESULT='success' if backend else 'skipped',
        OAUTH_RESULT='skipped' if backend else 'success')
    for profile in ['', 'unknown', 'selection-style', 'workspace-style']:
        check(not gate_passes(aggregate,{**full_valid,'BACKEND_PROFILE':profile},'Require every gate'),
              'full/OAuth results need their exact declared profile')
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

# Metadata-only release closure joins the existing post-deploy tail.
# It must not change value operations, timers, check execution, or credentials.
tail_workflow = load('modeled-conversion-value-export.yml')
tail_events = tail_workflow.get('on', tail_workflow.get(True))
check(set(tail_events) == {'workflow_run', 'workflow_dispatch', 'push'}, 'only the bounded native terminal continuation adds a trigger')
check(tail_events['push'] == {'branches':['ops/recover-pr1505-terminal-20261112']}, 'terminal continuation is one fixed owner branch, no timer')
recovery = tail_workflow['jobs']['release-slot-recovery']
check(recovery['if'] == "github.event_name == 'push' && github.ref == 'refs/heads/ops/recover-pr1505-terminal-20261112'", 'terminal continuation cannot run as a normal deploy tail or manual dispatch')
check(recovery['permissions'] == {'contents':'write','actions':'read','pull-requests':'read','issues':'write'}, 'continuation keeps metadata-only authority')
check(recovery['timeout-minutes'] == 8, 'continuation has a finite metadata deadline')
check(recovery['steps'][0]['with'] == {'ref':'${{ github.sha }}','persist-credentials':False}, 'continuation loads its genuine source')
check(len(recovery['steps']) == 4 and recovery['steps'][2]['uses'] == 'actions/upload-artifact@v4', 'continuation preserves one proof before terminal write')
check(recovery['steps'][3]['name'] == 'Release only the admitted publisher after all original mandatory gates', 'continuation has an independently authenticated native terminal')
check('secrets.' not in str(recovery) and 'ssh' not in str(recovery).lower(), 'terminal continuation has no deployment credential or server operation')
check(tail_events['workflow_run'] == {'workflows':['Deploy production'], 'types':['completed']},
      'release closure follows only existing production completion')
check(tail_workflow['concurrency'] == {'group':'anytour-modeled-value-configuration', 'cancel-in-progress':False},
      'existing post-deploy serialization retained')
check(tail_workflow['permissions'] == {'contents':'read', 'actions':'read'}, 'business job permissions unchanged')
close = tail_workflow['jobs']['release-slot']
check(close['needs'] == 'configure', 'value operation must finish or be declared skipped before closing')
expected_close_if = "always() && github.event_name == 'workflow_run' && github.ref == 'refs/heads/main' && github.repository == 'pyatkoff/max-search-bot' && github.event.workflow_run.name == 'Deploy production' && github.event.workflow_run.event == 'push' && github.event.workflow_run.head_branch == 'main' && github.event.workflow_run.head_repository.full_name == github.repository && github.event.workflow_run.conclusion == 'success' && (github.event.workflow_run.run_attempt == 1 || github.event.workflow_run.run_attempt == 2) && (needs.configure.result == 'success' || needs.configure.result == 'skipped')"
check(close['if'] == expected_close_if, 'only original or proven verification recovery may enter the fail-closed closer')
check(close['permissions'] == {'contents':'write', 'actions':'read', 'pull-requests':'read', 'issues':'write'},
      'only the metadata closer may publish the finite diagnostics record and terminal comment')
check(close['runs-on'] == 'ubuntu-latest' and close['timeout-minutes'] == 20, 'bounded existing server runner')
check(len(close['steps']) == 4, 'proof preparation and upload precede the only terminal mutation in the same job')
checkout, prepared, publication, closing = close['steps']
check(checkout['uses'] == 'actions/checkout@v4' and checkout['with'] == {
    'ref':'${{ github.event.workflow_run.head_sha }}', 'persist-credentials':False},
    'closer runs exact repository source without persisted token')
check(prepared['id'] == 'prepared'
      and prepared['run'] == 'node tools/release_slot_close.js --prepare-closure --proof "$RUNNER_TEMP/deployment-release-publication.json" >> "$GITHUB_OUTPUT"'
      and prepared['env'] == {'GITHUB_TOKEN':'${{ github.token }}','CONFIGURE_RESULT':'${{ needs.configure.result }}'}
      and not prepared.get('continue-on-error') and not prepared.get('if'),
      'prepare waits for the original gates and saves the exact terminal candidate before closure')
check(publication['name'] == 'Bind the terminal candidate to the original closer run — ${{ steps.prepared.outputs.record_digest }}'
      and publication['uses'] == 'actions/upload-artifact@v4'
      and publication['if'] == "steps.prepared.outputs.publication_artifact != ''"
      and publication['with'] == {
          'name':'${{ steps.prepared.outputs.publication_artifact }}',
          'path':'${{ runner.temp }}/deployment-release-publication.json',
          'if-no-files-found':'error','retention-days':90}
      and not publication.get('continue-on-error'),
      'original successful publication binds its exact digest and contains only the finite metadata proof')
check(closing['name'] == 'Release only the admitted publisher after all original mandatory gates'
      and closing['run'] == 'node tools/release_slot_close.js'
      and closing['env'] == {'GITHUB_TOKEN':'${{ github.token }}','CONFIGURE_RESULT':'${{ needs.configure.result }}',
                            'PREPARED_RECORD_DIGEST':'${{ steps.prepared.outputs.record_digest }}'}
      and closing['if'] == "steps.prepared.outputs.record_digest != ''"
      and not closing.get('continue-on-error'),
      'only the exact prepared result may reach the final single terminal mutation')
check('${{ secrets.' not in json.dumps(close), 'closer never receives production credentials')
check('release-slot-close:v1' in (root/'docs/RELEASE_PRIORITY.md').read_text(),
      'single existing publisher opt-in is documented')


# Installation and verification retain the existing job count and serialization.
deployment = load('deploy.yml')
dj = deployment['jobs']
check(set(dj) == {'verify-inventory','verify-syntax','verify-groups','deploy','verify'},
      'no new release queue or job identifiers')
check(dj['deploy']['needs'] == ['verify-inventory','verify-syntax','verify-groups']
      and dj['deploy']['if'] == 'always()', 'source aggregate cannot skip a missing group')
check(dj['deploy']['steps'][0]['name'] == 'Require the complete deployment verification'
      and 'test "$GITHUB_RUN_ATTEMPT" = 1' in dj['deploy']['steps'][0]['run'],
      'installer replay stopped before checkout or protected access')
check(dj['verify']['needs'] == 'deploy', 'verification cannot precede installation')
vs = dj['verify']['steps']
admit = next(i for i,s in enumerate(vs) if s.get('id') == 'admission')
key = next(i for i,s in enumerate(vs) if s.get('id') == 'ssh_key')
check(admit < key and not vs[admit].get('continue-on-error')
      and '--admit-verification' in vs[admit]['run'], 'read-only admission before verification credential')
for phase in ['check','download','status','cleanup']:
    s = next(s for s in vs if s.get('id') == phase)
    check(not s.get('continue-on-error', False), 'API step conclusion must preserve real failure: '+phase)
    check("steps.admission.outputs.run_"+phase+" == 'true'" in s['if'],
          'accepted phase cannot repeat: '+phase)
check('MAX_SEARCH_APPLY_AI_PROFILE' not in json.dumps(dj['verify'])
      and '--apply' not in json.dumps(dj['verify'])
      and 'export_debug_logs.php' not in json.dumps(dj['verify'])
      and 'git reset --hard' not in json.dumps(dj['verify']),
      'verification does not repeat installer mutations or export')
check('--check-only' in next(s for s in vs if s.get('id') == 'check')['run'],
      'verification uses read-only log boundary checks')
for job in dj.values():
    for step in job['steps']:
        if 'run' in step:
            check(subprocess.run(['bash','-n'],input=step['run'],text=True,capture_output=True).returncode == 0,
                  'actual deployment shell syntax: '+step.get('name','unnamed'))
for filename in ['max-tls-preflight.yml','max-production-health.yml','telegram-production-health.yml',
                 'publish-conversation-diagnostics.yml','live-session-diagnostics.yml','tour-selection-host.yml',
                 'modeled-conversion-monitor.yml','modeled-conversion-value-export.yml']:
    w = load(filename)
    check("format('deploy-proof:v1:{0}:{1}', github.event.workflow_run.id, github.event.workflow_run.run_attempt)" in w['run-name'],
          'exact upstream attempt metadata: '+filename)
tls_continuation = load('max-tls-preflight.yml')
tls_events = tls_continuation.get('on',tls_continuation.get(True))
check(set(tls_events) == {'push','workflow_run','workflow_dispatch'}, 'TLS continuation adds no timer or new workflow')
check(tls_events['push'] == {'branches':['ops/recover-pr1505-terminal-20261112']}, 'TLS has only the same fixed native continuation branch')
check(tls_continuation['permissions'] == {'contents':'read','actions':'read'}, 'TLS gate gains no write capability')
tls_steps = tls_continuation['jobs']['strict-tls-preflight']['steps']
check(next(s for s in tls_steps if s.get('id') == 'provenance')['if'] == "github.event_name != 'push'", 'only the authenticated ops continuation separates source and installed identity')
tls_resolve = next(s for s in tls_steps if s.get('id') == 'release')
check(tls_resolve['run'] == 'node tools/deployment_release.js resolve-consumer >> "$GITHUB_OUTPUT"', 'source-checked admission always precedes TLS credentials')
tls_key = next(s for s in tls_steps if s.get('name') == 'Prepare SSH private key')
check(tls_steps.index(tls_resolve) < tls_steps.index(tls_key), 'no TLS credential before exact continuation admission')
activation = load('modeled-pipeline-monitor-activation.yml')
check("format('monitor-proof:v1:{0}:{1}', github.event.workflow_run.id, github.event.workflow_run.run_attempt)" in activation['run-name'],
      'activation provenance follows the exact original monitor')

print(f'PASS {checks} workflow selection and gate cases')

