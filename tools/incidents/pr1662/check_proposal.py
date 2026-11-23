"""Exercise deploy.yml's #1662 control flow without connecting to a server."""
from pathlib import Path
from tempfile import TemporaryDirectory
import os
import subprocess
import sys
import yaml

APP = '302ba66b5739e369a4df94471c8cfe9c054dd070'
KIND = 'partial_install_1662'
jobs = yaml.safe_load(Path(sys.argv[1]).read_text())['jobs']
deploy = {step.get('id', step['name']): step for step in jobs['deploy']['steps']}
verify = {step.get('id', step['name']): step for step in jobs['verify']['steps']}
checks = 0


def check(ok, description):
    global checks
    if not ok:
        raise AssertionError(description)
    checks += 1


def run(shell, env):
    return subprocess.run(['bash', '-eo', 'pipefail', '-c', shell],
                          env={**os.environ, **env}, capture_output=True, text=True)


# The compatibility gates still allow only the original full install to build and sync.
check(deploy['bundle']['if'] == "steps.ssh_key.outcome == 'success' && steps.release.outputs.kind == 'installed'", 'bundle gate')
check(deploy['sync']['if'] == "steps.bundle.outcome == 'success'", 'sync gate')
migration_gate = ' '.join(deploy['migration']['if'].split())
expected_gate = ("steps.sync.outcome == 'success' || (steps.release.outcome == 'success' && "
                 "steps.release.outputs.kind == 'partial_install_1662' && "
                 f"steps.release.outputs.application_sha == '{APP}' && "
                 "steps.bundle.outcome == 'skipped' && steps.sync.outcome == 'skipped')")
check(migration_gate == expected_gate, 'migration admits exactly the original sync or the complete partial proof')
for stage, prior in [('max_webhook_reconcile','migration'), ('metrika_cron','max_webhook_reconcile'), ('installed','metrika_cron')]:
    check(deploy[stage]['if'] == f"steps.{prior}.outcome == 'success'", f'{stage} requires actual success of {prior}')
for stage in [deploy['installed'], verify['check'], verify['download'], verify['cleanup']]:
    check('application_sha || github.sha' in stage['env']['EXPECTED_SHA'], 'use admitted application for runtime operations')
for name in ('admission', 'status', 'resumed_publication'):
    check(verify[name]['env']['RELEASE_KIND'] == '${{ needs.deploy.outputs.kind }}', name + ' native reader needs the kind')
    check(verify[name]['env']['APPLICATION_SHA'] == '${{ needs.deploy.outputs.application_sha || github.sha }}', name + ' native reader needs the exact application')
for job in (deploy, verify):
    for step in job.values():
        if 'run' in step:
            syntax = subprocess.run(['bash', '-n'], input=step['run'], text=True)
            check(syntax.returncode == 0, step['name'] + ' shell syntax')

with TemporaryDirectory() as temp:
    tmp = Path(temp)
    scripts = {
        'node': '''test "$1:$2" = tools/deployment_release.js:admit-partial-install-1662
test "${HELPER_OK:-yes}" = yes || exit 71
printf 'kind=partial_install_1662\\napplication_sha=%s\\n' "${HELPER_APP:-$APPLICATION_SHA}"''',
        'curl': '''[[ " $* " == *" -I "* ]] && [[ " $* " == *" -o /dev/null "* ]] || exit 72
printf %s "${HEAD_CODE:-403}"''',
        'ssh-keyscan': "printf '%s\\n' 'example ssh-ed25519 AA=='",
        'ssh-keygen': """cat >/dev/null
printf '256 %s example (ED25519)\\n' "${HOST_FINGERPRINT:-SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0}" """,
        'ssh': '''[[ " $* " == *" -o StrictHostKeyChecking=yes "* ]] || exit 73
script="$(cat)"
path=/var/www/anytoour/data/www/app.anytoour.ru
bash -es -- "${@: -1}" <<< "${script//"$path"/"$RUNNER_TEMP"}"''',
        'git': '''case "$*" in
    'rev-parse HEAD') printf %s "${REMOTE_SHA:-$APPLICATION_SHA}";;
    'diff --quiet --no-ext-diff') test "${TRACKED_CLEAN:-yes}" = yes;;
    'diff --cached --quiet --no-ext-diff') test "${INDEX_CLEAN:-yes}" = yes;;
    *) exit 74;;
esac''',
    }
    for name, content in scripts.items():
        path = tmp / name
        path.write_text('#!/bin/bash\nset -eu\n' + content + '\n')
        path.chmod(0o700)
    output = tmp / 'outputs'
    base = dict(PATH=temp + ':' + os.environ['PATH'], RUNNER_TEMP=temp,
                GITHUB_OUTPUT=str(output), RELEASE_PLAN=KIND, APPLICATION_SHA=APP,
                DEPLOY_HOST='example', DEPLOY_USER='operator')
    for overrides, expected in [
        ({}, True), ({'HEAD_CODE':'404'}, True), ({'HEAD_CODE':'200'}, False),
        ({'HEAD_CODE':'500'}, False), ({'APPLICATION_SHA':'0'*40}, False),
        ({'REMOTE_SHA':'f'*40}, False), ({'TRACKED_CLEAN':'no'}, False), ({'INDEX_CLEAN':'no'}, False),
        ({'HOST_FINGERPRINT':'mismatch'}, False), ({'HELPER_OK':'no'}, False),
        ({'HELPER_APP':'0'*40}, False), ({'RELEASE_PLAN':'unrecognized'}, False)
    ]:
        output.write_text('')
        result = run(deploy['release']['run'], {**base, **overrides})
        check((result.returncode == 0) == expected, f"partial proof {overrides}: {result.stderr}")
        check(output.read_text() == (f'kind={KIND}\napplication_sha={APP}\n' if expected else ''),
              f"no early admission output {overrides}")
    output.write_text('')
    result = run(deploy['release']['run'], {**base, 'RELEASE_PLAN':'install', 'HELPER_OK':'no'})
    check(result.returncode == 0 and output.read_text() == 'kind=installed\n', 'normal install unaffected')

base = dict(PROVENANCE_OUTCOME='success', PLAN_OUTCOME='success', RELEASE_OUTCOME='success',
            KEY_OUTCOME='success', BUNDLE_OUTCOME='skipped', SYNC_OUTCOME='skipped',
            MIGRATION_OUTCOME='success', MAX_WEBHOOK_OUTCOME='success',
            METRIKA_CRON_OUTCOME='success', INSTALLED_OUTCOME='success', RELEASE_KIND=KIND)
final = deploy['Fail failed installation']['run']
check(run(final, base).returncode == 0, 'actual partial completion')
for k in ('PROVENANCE_OUTCOME','PLAN_OUTCOME','RELEASE_OUTCOME','KEY_OUTCOME','MIGRATION_OUTCOME',
          'MAX_WEBHOOK_OUTCOME','METRIKA_CRON_OUTCOME','INSTALLED_OUTCOME'):
    check(run(final, {**base,k:'failure'}).returncode != 0, k+' failure')
for k in ('BUNDLE_OUTCOME','SYNC_OUTCOME'):
    check(run(final, {**base,k:'success'}).returncode != 0, k+' replay')
check(run(final, {**base,'RELEASE_KIND':'installed'}).returncode != 0, 'full install cannot inherit partial skips')
check(run(final, {**base,'RELEASE_KIND':'installed','BUNDLE_OUTCOME':'success','SYNC_OUTCOME':'success'}).returncode == 0,
      'normal full install')
unchanged = {**base, 'RELEASE_KIND':'application_unchanged',
             **{k:'skipped' for k in ('MIGRATION_OUTCOME','MAX_WEBHOOK_OUTCOME','METRIKA_CRON_OUTCOME','INSTALLED_OUTCOME')}}
check(run(final, unchanged).returncode == 0, 'normal unchanged proof')
check(run(final, {**base,'RELEASE_KIND':'unknown'}).returncode != 0, 'unknown kind')
print(f'{checks} workflow checks passed')
