#!/usr/bin/env python3
"""Exercise workflow gate programs and their dependency wiring without browsers."""
import itertools
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
check(set(visual['screenshots']['needs']) == {'change-scope','full-screenshots','oauth-browser'}, 'visual aggregate must await both branches')
for name in ['regression-tests.yml','workspace-v2-visual-qa.yml','workspace-sound.yml','channel-post-context.yml']:
    check(load(name)['jobs']['change-scope']['uses'] == './.github/workflows/ci-change-scope.yml', name + ' must use canonical selector')
for name, jobs in [('regression-tests.yml',['required','prepared-mysql']),('workspace-v2-visual-qa.yml',['selection','firefox-history','firefox-notifications']),('workspace-sound.yml',['sound','native-mysql']),('channel-post-context.yml',['browser','native-mysql'])]:
    for job in jobs:
        check(load(name)['jobs'][job]['if'] == "needs.change-scope.outputs.mode == 'full'", name + '/' + job + ' must require full mode')
        check('change-scope' in load(name)['jobs'][job]['needs'], name + '/' + job + ' must await selector')
check("needs.change-scope.outputs.mode == 'full'" in visual['browser-tests']['if'], 'full browser assembler must not run after OAuth-only skips')
check(set(visual['full-screenshots']['needs']) == {'browser-tests','firefox-history','firefox-notifications'}, 'full screenshot proof preserves all engines and Firefox')
check(visual['oauth-browser']['strategy']['matrix']['browser'] == ['chromium','webkit'], 'OAuth requires both browser engines')
oauth_steps = '\n'.join(s.get('run','') for s in visual['oauth-browser']['steps'])
check("--grep 'OAuth native (allow|deny|legacy policy control)'" in oauth_steps, 'OAuth scope must execute only native consent tests' )
check('verify_oauth_browser_report.js oauth-browser.json' in oauth_steps, 'OAuth cannot pass with missing or skipped consent cases')
check('tools/ci_change_scope.js' in str(load('ci-change-scope.yml')), 'canonical selector must execute')
for mode in ['oauth','full','', 'invalid']:
    valid = dict(CI_MODE=mode,SCOPE_RESULT='success',INVENTORY_RESULT='success',SYNTAX_RESULT='success',REQUIRED_RESULT='success' if mode=='full' else 'skipped',PREPARED_MYSQL_RESULT='success' if mode=='full' else 'skipped',OAUTH_RESULT='success' if mode=='oauth' else 'skipped')
    cases=[valid]
    for key in ['SCOPE_RESULT','INVENTORY_RESULT','SYNTAX_RESULT','REQUIRED_RESULT','PREPARED_MYSQL_RESULT','OAUTH_RESULT']:
        for value in ['success','failure','skipped','cancelled']:
            cases.append({**valid,key:value})
    for env in cases:
        expected = env['SCOPE_RESULT'] == env['INVENTORY_RESULT'] == env['SYNTAX_RESULT'] == 'success' and ((mode == 'oauth' and env['OAUTH_RESULT'] == 'success' and env['REQUIRED_RESULT'] == env['PREPARED_MYSQL_RESULT'] == 'skipped') or (mode == 'full' and env['REQUIRED_RESULT'] == env['PREPARED_MYSQL_RESULT'] == 'success' and env['OAUTH_RESULT'] == 'skipped'))
        check(gate_passes(regression['regression'],env) == expected, 'regression gate accepted wrong results: ' + str(env))
for mode,scope,full,oauth in itertools.product(['oauth','full','','invalid'], ['success','failure','skipped','cancelled'], ['success','failure','skipped','cancelled'], ['success','failure','skipped','cancelled']):
    env=dict(CI_MODE=mode,SCOPE_RESULT=scope,FULL_RESULT=full,OAUTH_RESULT=oauth)
    expected=scope=='success' and ((mode=='oauth' and oauth=='success' and full=='skipped') or (mode=='full' and full=='success' and oauth=='skipped'))
    check(gate_passes(visual['screenshots'],env)==expected,'visual gate accepted wrong results: '+str(env))
print(f'PASS {checks} workflow selection and gate cases')
