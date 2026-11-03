#!/usr/bin/env python3
"""Execute the five real production-source resolvers offline, without credentials."""
import argparse
import copy
import json
import os
from pathlib import Path
import re
import shutil
import shlex
import sys
import subprocess
import tempfile

WORKFLOWS = (
    'max-production-health.yml',
    'max-tls-preflight.yml',
    'telegram-production-health.yml',
    'live-session-diagnostics.yml',
    'modeled-conversion-monitor.yml',
)
TIMED_WORKFLOWS = {
    'live-session-diagnostics.yml': ('publish', '25 * * * *'),
    'modeled-conversion-monitor.yml': ('monitor', '2-59/5 * * * *'),
}
CANONICAL_REPOSITORY = 'pyatkoff/max-search-bot'
SHA_EXPRESSION = '${{ steps.source.outputs.sha }}'
DEPLOY_SHA = 'a' * 40
DOWNSTREAM_SHA = 'b' * 40
CHECKS = 0


def check(condition, message):
    global CHECKS
    if not condition:
        raise AssertionError(message)
    CHECKS += 1


def top_section(source, key):
    """Read only an unquoted top-level mapping block; no YAML package is needed."""
    lines = source.splitlines()
    starts = [i for i, line in enumerate(lines) if line == key + ':']
    if len(starts) != 1:
        raise AssertionError('Expected one top-level ' + key + ' block')
    body = []
    for line in lines[starts[0] + 1:]:
        if line and not line.startswith((' ', '#')):
            break
        body.append(line)
    return '\n'.join(body)


def step_blocks(source):
    starts = list(re.finditer(r'^      - ', source, re.MULTILINE))
    return [source[m.start():starts[i + 1].start() if i + 1 < len(starts) else len(source)]
            for i, m in enumerate(starts)]


def field(block, name):
    matches = re.findall(r'^        ' + re.escape(name) + r':\s*(\S[^\n]*)$', block, re.MULTILINE)
    return matches[0].strip() if len(matches) == 1 else None


def run_block(step):
    """Extract the actual literal shell block at the repository's step indentation."""
    lines = step.splitlines()
    starts = [i for i, line in enumerate(lines) if re.fullmatch(r'        run: \|[-+]?', line)]
    if len(starts) != 1:
        raise AssertionError('Expected one literal step run block')
    body = []
    for line in lines[starts[0] + 1:]:
        if line and not line.startswith('          '):
            break
        body.append(line[10:] if line else '')
    return '\n'.join(body) + '\n'



def script_block(step):
    """Extract a real github-script literal without evaluating YAML expressions."""
    lines = step.splitlines()
    starts = [i for i, line in enumerate(lines) if re.fullmatch(r'          script: \|[-+]?', line)]
    if len(starts) != 1:
        raise AssertionError('Expected one literal github-script block')
    body = []
    for line in lines[starts[0] + 1:]:
        if line and not line.startswith('            '):
            break
        body.append(line[12:] if line else '')
    return '\n'.join(body) + '\n'

def source_step(source, filename, job_name=None):
    if filename in TIMED_WORKFLOWS:
        wanted = job_name or TIMED_WORKFLOWS[filename][0]
        job_keys = re.findall(r'^  ([A-Za-z_][A-Za-z0-9_-]*):', top_section(source, 'jobs'), re.MULTILINE)
        check(job_keys == [wanted] and wanted == TIMED_WORKFLOWS[filename][0],
              filename + ': one canonical independent production job')
        match = re.search(r'^  ' + re.escape(wanted) + r':\n(.*?)(?=^  [A-Za-z_][A-Za-z0-9_-]*:\n|\Z)', source, re.MULTILINE | re.DOTALL)
        check(match is not None, filename + ': required independent job ' + wanted)
        source = source[:source.index('\njobs:')] + '\njobs:\n' + match.group(0)
    label = filename + ': '
    events = top_section(source, 'on')
    keys = re.findall(r'^  ([a-z_]+):', events, re.MULTILINE)
    expected = {'workflow_run', 'workflow_dispatch'}
    timed = filename in TIMED_WORKFLOWS
    if timed:
        expected.add('schedule')
    check(set(keys) == expected and len(keys) == len(expected), label + 'only deploy-completion and retained explicit triggers')
    for declaration in ('workflows: [Deploy production]', 'types: [completed]', 'branches: [main]'):
        check('    ' + declaration in events.splitlines(), label + 'missing upstream trigger ' + declaration)
    if timed:
        timer = TIMED_WORKFLOWS[filename][1]
        expected_if = "    if: (github.event_name != 'workflow_run' || github.event.workflow_run.conclusion == 'success') && (github.event_name != 'schedule' || github.event.schedule == '" + timer + "')"
        check(expected_if in source.splitlines(), label + 'success and exact hourly/check-only timer guard')
    else:
        check("    if: github.event_name != 'workflow_run' || github.event.workflow_run.conclusion == 'success'" in source.splitlines(),
              label + 'automatic job must require successful deployment')
    if timed:
        crons = re.findall(r"^    - cron: '([^']+)'$", events, re.MULTILINE)
        check(crons == [timer], label + 'exactly one independent canonical timer')
        group = 'production-live-session-diagnostics' if filename == 'live-session-diagnostics.yml' else 'anytour-modeled-conversion-monitor'
        check('      group: ' + group in source.splitlines()
              and '      cancel-in-progress: false' in source.splitlines(), label + 'independent active jobs must not cancel each other')

    steps = step_blocks(source)
    resolvers = [i for i, step in enumerate(steps) if field(step, 'id') == 'source']
    check(resolvers == [0], label + 'exactly one source resolver must precede checkout and credentials')
    checkout = [i for i, step in enumerate(steps) if field(step, 'uses') == 'actions/checkout@v4']
    provenance = [i for i, step in enumerate(steps) if field(step, 'id') == 'provenance']
    check(len(checkout) == len(provenance) == 1 and 0 < checkout[0] < provenance[0], label + 'checkout then provenance follow source resolution')
    check('          ref: ' + SHA_EXPRESSION in steps[checkout[0]].splitlines(), label + 'checkout uses the exact resolved SHA')
    secret_steps = [i for i, step in enumerate(steps) if '${{ secrets.' in step]
    check(secret_steps and min(secret_steps) > provenance[0]
          and '${{ secrets.' not in source[:source.index('    steps:')], label + 'provenance must finish before credentials are introduced')
    for step in (steps[0], steps[checkout[0]], steps[provenance[0]]):
        check(field(step, 'if') is None and field(step, 'continue-on-error') is None,
              label + 'source checkout and provenance cannot be optional or continue on error')
    provenance_run = run_block(steps[provenance[0]])
    check('test "$(git rev-parse HEAD)" = "$EXPECTED_SHA"' in provenance_run
          and 'bash tools/verify_deploy_main_provenance.sh' in provenance_run, label + 'canonical provenance checks checkout and current main')
    expected_values = re.findall(r'^          EXPECTED_SHA:\s*(.+)$', source, re.MULTILINE)
    check(len(expected_values) >= 2 and all(value == SHA_EXPRESSION for value in expected_values),
          label + 'provenance and production checks share the resolved SHA with no fallback')
    remote_steps = [step for step in steps if 'git rev-parse HEAD' in step and 'ssh ' in step]
    check(remote_steps and all('          EXPECTED_SHA: ' + SHA_EXPRESSION in step.splitlines()
                               and '"$EXPECTED_SHA"' in step for step in remote_steps), label + 'remote installed SHA comparison remains pinned')
    for step in steps:
        if '        run: |' in step:
            shell = run_block(step)
            if '$EXPECTED_SHA' in shell or '${EXPECTED_SHA}' in shell:
                check('          EXPECTED_SHA: ' + SHA_EXPRESSION in step.splitlines(),
                      label + 'every strict shell EXPECTED_SHA use has an exact step environment binding')
    resolver = run_block(steps[0])
    check('${{' not in resolver and field(steps[0], 'shell') == 'bash', label + 'resolver consumes event data through shell environment, not expression interpolation')
    return resolver


def event_payload():
    return {'action': 'completed', 'repository': {'full_name': CANONICAL_REPOSITORY},
            'workflow_run': {'id': 12345678901, 'run_attempt': 1, 'name': 'Deploy production',
                             'status': 'completed', 'conclusion': 'success', 'event': 'push',
                             'head_repository': {'full_name': CANONICAL_REPOSITORY},
                             'head_branch': 'main', 'head_sha': DEPLOY_SHA}}


def execute(resolver, filename, label, payload, event='workflow_run', ref='refs/heads/main',
            runner_sha=DOWNSTREAM_SHA, repository=CANONICAL_REPOSITORY, expected_sha=None,
            upstream_id='none', upstream_attempt='none', raw_event=None, current_attempt='1'):
    bash = shutil.which('bash')
    jq = shutil.which('jq')
    if not bash or not jq:
        raise RuntimeError('Existing runner bash and jq are required; no installation is attempted')
    with tempfile.TemporaryDirectory(prefix='production-gate-sequence-') as temporary:
        work = Path(temporary)
        binary_dir = work / 'bin'
        binary_dir.mkdir()
        # Only jq and shell builtins are available: no git, SSH, curl, CLI or credentials.
        (binary_dir / 'jq').symlink_to(jq)
        event_file = work / 'event.json'
        event_file.write_text(raw_event if raw_event is not None else json.dumps(payload), encoding='utf-8')
        output_file = work / 'output'
        output_file.touch()
        env = {'PATH': str(binary_dir), 'LC_ALL': 'C', 'GITHUB_EVENT_NAME': event,
               'GITHUB_EVENT_PATH': str(event_file), 'GITHUB_OUTPUT': str(output_file),
               'GITHUB_REPOSITORY': repository, 'GITHUB_REF': ref, 'GITHUB_SHA': runner_sha,
               'RUNNER_TEMP': str(work), 'GITHUB_RUN_ATTEMPT': current_attempt}
        result = subprocess.run([bash, '--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', resolver],
                                cwd=work, env=env, text=True, capture_output=True, timeout=5)
        output = output_file.read_text(encoding='utf-8')
        if expected_sha is None:
            check(result.returncode != 0 and output == '', filename + ': ' + label + ' must fail without emitting any fallback source')
        else:
            expected = f'sha={expected_sha}\nupstream_run_id={upstream_id}\nupstream_run_attempt={upstream_attempt}\n'
            check(result.returncode == 0 and output == expected, filename + ': ' + label + ' wrong source outcome: '
                  + repr({'code': result.returncode, 'output': output, 'stderr': result.stderr[:400]}))


def behavior_cases(resolver, filename):
    valid = event_payload()
    execute(resolver, filename, 'deploy head differs from downstream workflow SHA', valid,
            expected_sha=DEPLOY_SHA, upstream_id='12345678901', upstream_attempt='1')
    dispatched = copy.deepcopy(valid)
    dispatched['workflow_run'].update(event='workflow_dispatch', run_attempt=2)
    execute(resolver, filename, 'successful manually dispatched main deployment', dispatched,
            expected_sha=DEPLOY_SHA, upstream_id='12345678901', upstream_attempt='2')
    bad_fields = {
        'head_sha': (None, '', 'a' * 39, 'a' * 41, 'g' * 40, 'A' * 40, DEPLOY_SHA + '\n', 123, [], {}),
        'head_branch': ('feature/source', None),
        'head_repository': ({'full_name': 'foreign/max-search-bot'}, None),
        'name': ('Regression tests', None),
        'conclusion': ('failure', 'cancelled', None),
        'status': ('in_progress', 'queued'),
        'event': ('pull_request', 'pull_request_target', 'schedule'),
        'id': (None, 0, -1, 1.5, '12345678901'),
        'run_attempt': (None, 0, 1.5, '1'),
    }
    for name, values in bad_fields.items():
        for value in values:
            payload = copy.deepcopy(valid)
            payload['workflow_run'][name] = value
            execute(resolver, filename, 'untrusted ' + name + '=' + repr(value), payload)
    missing = copy.deepcopy(valid)
    del missing['workflow_run']['head_sha']
    execute(resolver, filename, 'missing upstream head never falls back to downstream SHA', missing)
    execute(resolver, filename, 'missing upstream run', {})
    started = copy.deepcopy(valid)
    started['action'] = 'requested'
    execute(resolver, filename, 'upstream completion action required', started)
    execute(resolver, filename, 'malformed event JSON', {}, raw_event='{broken')
    execute(resolver, filename, 'foreign workflow repository', valid, repository='foreign/max-search-bot')
    for event in ('push', 'pull_request', 'repository_dispatch'):
        execute(resolver, filename, 'unsupported downstream event ' + event, valid, event=event)
    execute(resolver, filename, 'manual main uses its own exact SHA', {}, event='workflow_dispatch', expected_sha=DOWNSTREAM_SHA)
    execute(resolver, filename, 'manual feature branch cannot access production', {}, event='workflow_dispatch', ref='refs/heads/feature/source')
    execute(resolver, filename, 'manual main tag is not the main branch', {}, event='workflow_dispatch', ref='refs/tags/main')
    for malformed in ('', 'short', 'A' * 40, DOWNSTREAM_SHA + '\n'):
        execute(resolver, filename, 'manual malformed SHA ' + repr(malformed), {}, event='workflow_dispatch', runner_sha=malformed)
    timer = TIMED_WORKFLOWS.get(filename, (None, None))[1]
    execute(resolver, filename, 'existing canonical timer only', {'schedule': timer or '25 * * * *'}, event='schedule',
            expected_sha=DOWNSTREAM_SHA if timer is not None else None)
    execute(resolver, filename, 'timer cannot inspect a feature branch', {}, event='schedule', ref='refs/heads/feature/source')




def monitor_source_ready_cases(source):
    """Run the actual installation-race gate in Node with an offline GitHub stub."""
    steps = step_blocks(source)
    installed = [(i, step) for i, step in enumerate(steps) if field(step, 'id') == 'installed']
    ready = [(i, step) for i, step in enumerate(steps) if field(step, 'id') == 'source_ready']
    decision = [(i, step) for i, step in enumerate(steps) if field(step, 'id') == 'decision']
    check(len(installed) == len(ready) == len(decision) == 1,
          'monitor: one installed observation, source-ready gate and notification decision')
    installed_index, installed_step = installed[0]
    ready_index, ready_step = ready[0]
    check(installed_index < ready_index < decision[0][0],
          'monitor: installed source and active-installation admission precede notification decision')
    check('          EXPECTED_SHA: ' + SHA_EXPRESSION in installed_step.splitlines()
          and '          EXPECTED_SHA: ' + SHA_EXPRESSION in ready_step.splitlines()
          and '          INSTALLED_SHA: ${{ steps.installed.outputs.sha }}' in ready_step.splitlines(),
          'monitor: installation observations retain fixed trusted source bindings')
    check('timeout 15s ssh ' in run_block(installed_step),
          'monitor: installed-source transport probe has a finite outer timeout')
    check(field(ready_step, 'uses') == 'actions/github-script@v7'
          and field(ready_step, 'if') is None and field(ready_step, 'continue-on-error') is None
          and '          retries: 0' in ready_step.splitlines(),
          'monitor: source-ready gate is mandatory with no retries or error suppression')
    ready_if = "steps.source_ready.outputs.ready == 'true'"
    guards = {
        'Prepare existing pinned encrypted evidence runtime': ready_if,
        'Locate latest retained encrypted monitor state': ready_if,
        'Download retained ciphertext only': ready_if + " && steps.retained.outputs.present == 'true'",
        'Read fixed installed technical health and bootstrap route when required': ready_if,
        'Prepare one immutable notification intent': ready_if,
        'Persist encrypted intent before any Telegram send': "steps.decision.outputs.send == 'true'",
        'Send only this original retained intent': "steps.decision.outputs.send == 'true' && steps.durable_intent.outcome == 'success'",
        'Retain final encrypted state': "always() && steps.decision.outcome == 'success'",
        'Record sanitized external monitoring receipt': "always() && steps.decision.outcome == 'success'",
        'Verify technical monitoring result': "always() && steps.decision.outcome == 'success'",
    }
    for name, expected_if in guards.items():
        matches = [(i, step) for i, step in enumerate(steps) if step.startswith('      - name: ' + name + '\n')]
        check(len(matches) == 1 and ready_index < matches[0][0]
              and field(matches[0][1], 'if') == expected_if,
              'monitor: state, send and always-step admission remains gated: ' + name)

    node = shutil.which('node')
    if not node:
        raise RuntimeError('Existing runner Node is required; no installation is attempted')
    script = script_block(ready_step)
    check('${{' not in script, 'monitor: active-installation gate consumes trusted environment only')
    active = {'name': 'Deploy production', 'head_sha': DEPLOY_SHA, 'head_branch': 'main',
              'head_repository': {'full_name': CANONICAL_REPOSITORY}, 'event': 'push',
              'conclusion': None, 'status': 'in_progress'}
    cases = [
        ('installed source already matches', {'installed': DEPLOY_SHA, 'list_error': True}, 'true', 1),
        ('unavailable installed source must reach actual health handling',
         {'installed': 'unavailable', 'list_error': True}, 'true', 1),
        ('no matching deployment', {'runs': []}, 'true', 2),
        ('changed authoritative main', {'main': DOWNSTREAM_SHA}, None, 1),
        ('main API error', {'main_error': True}, None, 1),
        ('deployment API error', {'list_error': True}, None, 2),
        ('invalid deployment list', {'runs': None}, None, 2),
        ('oversize deployment metadata', {'runs': [active] * 21}, None, 2),
        ('missing deployment metadata', {'deploy_response': {'data': {}}}, None, 2),
        ('invalid main metadata', {'main_response': {'data': {'object': None}}}, None, 1),
    ]
    for status in ('queued', 'in_progress', 'requested', 'waiting', 'pending'):
        run = copy.deepcopy(active)
        run['status'] = status
        cases.append(('identified active deployment ' + status, {'runs': [run]}, 'false', 2))
    for name, values in {
        'status': ('completed', 'cancelled', 'failure'),
        'conclusion': ('failure', 'cancelled', 'success'),
        'name': ('Regression tests',),
        'head_sha': (DOWNSTREAM_SHA,),
        'head_branch': ('feature/source',),
        'head_repository': ({'full_name': 'foreign/max-search-bot'}, None),
        'event': ('pull_request', 'workflow_run', 'schedule'),
    }.items():
        for value in values:
            run = copy.deepcopy(active)
            run[name] = value
            cases.append(('non-active or foreign deployment ' + name + '=' + repr(value),
                          {'runs': [run]}, 'true', 2))
    manual = copy.deepcopy(active)
    manual['event'] = 'workflow_dispatch'
    cases.append(('identified manual main installation', {'runs': [manual]}, 'false', 2))
    for name in ('expected', 'installed'):
        for value in (None, '', 'a' * 39, 'a' * 41, 'g' * 40, 'A' * 40, DEPLOY_SHA + '\n'):
            cases.append(('invalid trusted ' + name + '=' + repr(value), {name: value}, None, 0))

    harness = r"""
const fs = require('fs');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const source = fs.readFileSync(process.argv[3], 'utf8');
const outputs = {}, calls = [];
const context = {repo: {owner: 'pyatkoff', repo: 'max-search-bot'}};
const github = {rest: {
  git: {getRef: async args => {
    calls.push('main');
    if (args.owner !== context.repo.owner || args.repo !== context.repo.repo ||
        args.ref !== 'heads/main' || args.request?.timeout !== 10000)
      throw new Error('unexpected_main_lookup');
    if (fixture.main_error) throw new Error('synthetic_main_api_error');
    return Object.prototype.hasOwnProperty.call(fixture, 'main_response')
      ? fixture.main_response : {data: {object: {sha: fixture.main}}};
  }},
  actions: {listWorkflowRuns: async args => {
    calls.push('deploy');
    if (args.owner !== context.repo.owner || args.repo !== context.repo.repo ||
        args.workflow_id !== 'deploy.yml' || args.branch !== 'main' ||
        args.head_sha !== process.env.EXPECTED_SHA || args.per_page !== 20 ||
        args.request?.timeout !== 10000) throw new Error('unexpected_deployment_lookup');
    if (fixture.list_error) throw new Error('synthetic_deployment_api_error');
    return Object.prototype.hasOwnProperty.call(fixture, 'deploy_response')
      ? fixture.deploy_response : {data: {workflow_runs: fixture.runs}};
  }}
}};
const core = {
  setOutput: (key, value) => {
    if (key !== 'ready' || !['true', 'false'].includes(value)) throw new Error('unexpected_ready_output');
    outputs[key] = value;
  },
  info: () => {}
};
globalThis.fetch = () => { throw new Error('network_is_forbidden'); };
const denyRequire = () => { throw new Error('runtime_import_is_forbidden'); };
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
(async () => {
  let failed = false;
  try { await new AsyncFunction('github', 'context', 'core', 'require', source)(github, context, core, denyRequire); }
  catch (error) { failed = true; }
  process.stdout.write(JSON.stringify({failed, outputs, calls}));
})().catch(() => process.exit(2));
"""
    for name, patch, expected_ready, expected_calls in cases:
        fixture = {'expected': DEPLOY_SHA, 'installed': DOWNSTREAM_SHA, 'main': DEPLOY_SHA, 'runs': [active]}
        fixture.update(patch)
        with tempfile.TemporaryDirectory(prefix='monitor-source-ready-') as temporary:
            work = Path(temporary)
            fixture_file, source_file, harness_file = work / 'fixture.json', work / 'gate.js', work / 'harness.js'
            fixture_file.write_text(json.dumps(fixture), encoding='utf-8')
            source_file.write_text(script, encoding='utf-8')
            harness_file.write_text(harness, encoding='utf-8')
            env = {'PATH': str(work), 'LC_ALL': 'C'}
            for field_name, env_name in (('expected', 'EXPECTED_SHA'), ('installed', 'INSTALLED_SHA')):
                if isinstance(fixture[field_name], str):
                    env[env_name] = fixture[field_name]
            completed = subprocess.run([node, str(harness_file), str(fixture_file), str(source_file)],
                                       cwd=work, env=env, text=True, capture_output=True, timeout=5)
            check(completed.returncode == 0, 'monitor: offline actual JS gate execution completed: ' + name)
            outcome = json.loads(completed.stdout)
            check(len(outcome['calls']) == expected_calls,
                  'monitor: bounded exact API admission: ' + name)
            if expected_ready is None:
                check(outcome['failed'] and outcome['outputs'] == {},
                      'monitor: unsafe installation metadata cannot open notification state: ' + name)
            else:
                check(not outcome['failed'] and outcome['outputs'] == {'ready': expected_ready},
                      'monitor: identified installation admission is exact: ' + name)

def monitor_record_cases(source):
    """Execute the real final receipt step with a network-free SSH recorder."""
    steps = [step for step in step_blocks(source)
             if step.startswith('      - name: Record sanitized external monitoring receipt\n')]
    check(len(steps) == 1, 'monitor: one canonical external receipt step')
    step = steps[0]
    check(field(step, 'continue-on-error') is None
          and not re.search(r'^    continue-on-error:', source, re.MULTILINE),
          'monitor: failed receipt cannot be suppressed at step or job level')
    expected_env = {
        'EXPECTED_SHA': SHA_EXPRESSION,
        'MONITOR_EVENT': '${{ github.event_name }}',
        'MONITOR_RUN_ID': '${{ github.run_id }}',
        'MONITOR_RUN_ATTEMPT': '${{ github.run_attempt }}',
    }
    for key, value in expected_env.items():
        check('          ' + key + ': ' + value in step.splitlines(),
              'monitor: trusted receipt context binding ' + key)
    shell = run_block(step)
    bash = shutil.which('bash')
    if not bash:
        raise RuntimeError('Existing runner bash is required')
    cases = [
        ({}, True),
        ({'MONITOR_EVENT': 'workflow_run'}, True),
        ({'MONITOR_EVENT': 'workflow_dispatch'}, True),
        ({'MONITOR_RUN_ATTEMPT': '2'}, True),
        ({'MONITOR_EVENT': 'push'}, False),
        ({'MONITOR_EVENT': "schedule'; echo synthetic"}, False),
        ({'MONITOR_RUN_ID': '01'}, False),
        ({'MONITOR_RUN_ID': '1; echo synthetic'}, False),
        ({'MONITOR_RUN_ATTEMPT': '0'}, False),
        ({'MONITOR_RUN_ATTEMPT': '1 2'}, False),
        ({'EXPECTED_SHA': 'A' * 40}, False),
        ({'EXPECTED_SHA': DEPLOY_SHA + "'"}, False),
        ({'SYNTHETIC_SSH_EXIT': '1'}, True),
    ]
    for overrides, valid in cases:
        with tempfile.TemporaryDirectory(prefix='monitor-receipt-source-') as temporary:
            work = Path(temporary)
            binary = work / 'bin'
            binary.mkdir()
            trace = work / 'ssh-trace.json'
            ssh = binary / 'ssh'
            ssh.write_text('#!' + sys.executable + '\n'
                           'import json, os, sys\n'
                           'with open(os.environ["SSH_TRACE"], "w", encoding="utf-8") as f:\n'
                           '    json.dump(sys.argv[1:], f)\n'
                           'sys.exit(int(os.environ.get("SYNTHETIC_SSH_EXIT", "0")))\n', encoding='utf-8')
            ssh.chmod(0o700)
            (work / 'monitor-result.json').write_text('{"synthetic":true}\n', encoding='utf-8')
            env = {'PATH': str(binary), 'LC_ALL': 'C', 'RUNNER_TEMP': str(work),
                   'SSH_TRACE': str(trace), 'DEPLOY_USER': 'synthetic-user', 'DEPLOY_HOST': 'synthetic-host',
                   'EXPECTED_SHA': DEPLOY_SHA, 'MONITOR_EVENT': 'schedule',
                   'MONITOR_RUN_ID': '12345678901', 'MONITOR_RUN_ATTEMPT': '1', 'SYNTHETIC_SSH_EXIT': '0'}
            env.update(overrides)
            completed = subprocess.run([bash, '--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', shell],
                                       cwd=work, env=env, text=True, capture_output=True, timeout=5)
            if not valid:
                check(completed.returncode != 0 and not trace.exists(),
                      'monitor: invalid trusted context cannot reach SSH ' + repr(overrides))
                continue
            if env['SYNTHETIC_SSH_EXIT'] == '1':
                check(completed.returncode != 0 and trace.exists(),
                      'monitor: actual SSH record failure makes the real receipt step fail')
                check('external_monitor_receipt_unavailable' in completed.stdout,
                      'monitor: failed receipt remains explicitly observable')
            else:
                check(completed.returncode == 0 and trace.exists(),
                      'monitor: valid trusted context reaches one record ' + repr(overrides))
            args = json.loads(trace.read_text(encoding='utf-8'))
            remote = shlex.split(args[-1])
            record_index = remote.index('record')
            check(remote[record_index + 1:] == [
                '--event=' + env['MONITOR_EVENT'], '--run-id=' + env['MONITOR_RUN_ID'],
                '--run-attempt=' + env['MONITOR_RUN_ATTEMPT'], '--source-sha=' + env['EXPECTED_SHA']],
                'monitor: exact validated provenance remains quoted through remote command')



def monitor_schedule_cases(root, sources):
    """One scheduled sender owns the existing confidential state and concurrency."""
    live = sources['live-session-diagnostics.yml']
    monitor = sources['modeled-conversion-monitor.yml']
    check(monitor.splitlines()[0] == 'name: Monitor modeled conversion delivery',
          'monitor: canonical independent workflow name')
    check(not re.search(r'^  monitor:', top_section(live, 'jobs'), re.MULTILINE)
          and 'tools/modeled_conversion_monitor.py' not in live,
          'live: hourly publication contains no monitoring job or notification sender')
    needle = 'tools/modeled_conversion_monitor.py --phase send'
    senders = []
    for path in sorted((root / '.github/workflows').iterdir()):
        if path.suffix not in ('.yml', '.yaml'):
            continue
        source = path.read_text(encoding='utf-8')
        if needle in source and re.search(r'^  schedule:', source, re.MULTILINE):
            senders.append(path.name)
    check(senders == ['modeled-conversion-monitor.yml'],
          'monitor: exactly one scheduled workflow owns the notification sender')
    check(monitor.count(needle) == 1,
          'monitor: retained intent has one canonical send command')


def pipeline_activation_cases(root):
    """Execute the original activation resolver and retain its handoff boundaries."""
    filename = 'modeled-pipeline-monitor-activation.yml'
    source = (root / '.github/workflows' / filename).read_text(encoding='utf-8')
    events = top_section(source, 'on')
    check(re.findall(r'^  ([a-z_]+):', events, re.MULTILINE) == ['workflow_run'],
          'activation: only completion of the original monitor can trigger the handoff')
    for declaration in ('workflows: [Monitor modeled conversion delivery]', 'types: [completed]', 'branches: [main]'):
        check('    ' + declaration in events.splitlines(), 'activation: exact upstream ' + declaration)
    expected_if = "    if: github.event.workflow_run.conclusion == 'success' && github.event.workflow_run.event == 'workflow_run' && contains(github.event.workflow_run.head_commit.message, '[modeled-pipeline-monitor-activation-v1]')"
    check(expected_if in source.splitlines(), 'activation: successful original monitor and explicit launch flag')
    check('      group: anytour-modeled-conversion-monitor' in source.splitlines()
          and '      cancel-in-progress: false' in source.splitlines(),
          'activation: same serialized role handoff as the canonical external monitor')
    steps = step_blocks(source)
    by_id = {field(step, 'id'): i for i, step in enumerate(steps) if field(step, 'id')}
    check(by_id.get('source') == 0, 'activation: source admission precedes every credential')
    checkout = [i for i, step in enumerate(steps) if field(step, 'uses') == 'actions/checkout@v4']
    provenance = by_id.get('provenance')
    secret_steps = [i for i, step in enumerate(steps) if '$' + '{{ secrets.' in step]
    check(len(checkout) == 1 and provenance is not None and 0 < checkout[0] < provenance
          and secret_steps and provenance < min(secret_steps), 'activation: checkout/provenance before protected access')
    check('          ref: ' + SHA_EXPRESSION in steps[checkout[0]].splitlines(),
          'activation: checkout consumes only the admitted source')
    for index in (0, checkout[0], provenance):
        check(field(steps[index], 'if') is None and field(steps[index], 'continue-on-error') is None,
              'activation: source boundaries cannot be skipped')
    check('bash tools/verify_deploy_main_provenance.sh' in run_block(steps[provenance]),
          'activation: authoritative main guard retained')
    resolver = run_block(steps[0])
    check('$' + '{{' not in resolver and field(steps[0], 'shell') == 'bash',
          'activation: actual resolver reads event data without expression interpolation')
    valid = {'action': 'completed', 'repository': {'full_name': CANONICAL_REPOSITORY},
             'workflow_run': {'id': 12345678901, 'run_attempt': 1,
                              'name': 'Monitor modeled conversion delivery', 'workflow_id': 406167317,
                              'status': 'completed', 'conclusion': 'success', 'event': 'workflow_run',
                              'head_repository': {'full_name': CANONICAL_REPOSITORY},
                              'head_branch': 'main', 'head_sha': DEPLOY_SHA,
                              'head_commit': {'message': 'Install reviewed monitor [modeled-pipeline-monitor-activation-v1]'}}}
    execute(resolver, filename, 'valid launch uses exact monitor head', valid, expected_sha=DEPLOY_SHA,
            upstream_id='12345678901', upstream_attempt='1')
    bad_fields = {
        'workflow_id': (None, 0, 406167318, '406167317'),
        'name': ('Deploy production', None),
        'head_repository': ({'full_name': 'foreign/max-search-bot'}, None),
        'head_branch': ('feature/source', 'refs/tags/main', None),
        'head_sha': (None, '', 'a' * 39, 'a' * 41, 'A' * 40, 'g' * 40, DEPLOY_SHA + '\n'),
        'status': ('in_progress', 'queued', None),
        'conclusion': ('failure', 'cancelled', None),
        'event': ('push', 'schedule', 'workflow_dispatch'),
        'run_attempt': (None, 0, 2, '1'),
        'id': (None, 0, -1, 1.5, '12345678901'),
        'head_commit': (None, {}, {'message': 'ordinary installation'}, {'message': None}, {'message': 123}),
    }
    for key, values in bad_fields.items():
        for value in values:
            payload = copy.deepcopy(valid)
            payload['workflow_run'][key] = value
            execute(resolver, filename, 'reject upstream ' + key + '=' + repr(value), payload)
    missing = copy.deepcopy(valid)
    del missing['workflow_run']['workflow_id']
    execute(resolver, filename, 'missing upstream workflow identifier', missing)
    execute(resolver, filename, 'missing original monitor event', {})
    execute(resolver, filename, 'malformed event JSON', {}, raw_event='{broken')
    started = copy.deepcopy(valid)
    started['action'] = 'requested'
    execute(resolver, filename, 'upstream must be completed', started)
    execute(resolver, filename, 'foreign executing repository', valid, repository='foreign/max-search-bot')
    execute(resolver, filename, 'tag cannot supply the activation source', valid, ref='refs/tags/main')
    execute(resolver, filename, 'feature ref cannot supply the activation source', valid, ref='refs/heads/feature/source')
    execute(resolver, filename, 'current run rerun cannot repeat activation', valid, current_attempt='2')
    for event in ('push', 'schedule', 'workflow_dispatch', 'repository_dispatch'):
        execute(resolver, filename, 'unsupported current event ' + event, valid, event=event)

    record = [i for i, step in enumerate(steps)
              if step.startswith('      - name: Persist the final external handoff receipt\n')]
    check(len(record) == 1 and all(key in by_id for key in ('staged', 'external_ack', 'activated', 'final', 'final_ack')),
          'activation: exactly one complete handoff and final receipt')
    check(by_id['staged'] < by_id['external_ack'] < by_id['activated'] < by_id['final'] < by_id['final_ack'] < record[0],
          'activation: inactive stage then external ciphertext ACK then activate then final role ACK then record')
    guards = {'staged': "steps.split.outcome == 'success'",
              'external_ack': "steps.staged.outcome == 'success'",
              'activated': "steps.external_ack.outcome == 'success'",
              'final': "steps.activated.outcome == 'success'",
              'final_ack': "steps.final.outcome == 'success'"}
    for key, condition in guards.items():
        check(field(steps[by_id[key]], 'if') == condition
              and field(steps[by_id[key]], 'continue-on-error') is None,
              'activation: required previous ACK guards ' + key)
    check(field(steps[record[0]], 'if') == "steps.final_ack.outcome == 'success'"
          and field(steps[record[0]], 'continue-on-error') is None,
          'activation: external receipt requires the final encrypted state ACK')
    check('          EXTERNAL_INTENT_ARTIFACT_ID: ' + '$' + '{{ steps.external_ack.outputs.artifact-id }}'
          in steps[by_id['activated']].splitlines(), 'activation: server receives the acknowledged immutable artifact identifier')
    check(not re.search(r'--phase[ \t]+send\b', source) and not re.search(r'--cron\b', source)
          and not re.search(r'\bm\.send\s*\(', source) and '--bootstrap-test' not in source,
          'activation: no notification send, manual checker cycle or bootstrap test')

    prefix = '$' + '{{ runner.temp }}/'
    allowed = {prefix + 'pipeline-external-intent/state.age', prefix + 'pipeline-external-final/state.age',
               prefix + 'pipeline-handoff/staged.json', prefix + 'pipeline-handoff/activated.json'}
    paths = []
    for step in steps:
        if field(step, 'uses') != 'actions/upload-artifact@v4':
            continue
        lines = step.splitlines()
        starts = [i for i, line in enumerate(lines) if re.match(r'^          path:', line)]
        check(len(starts) == 1, 'activation: each artifact has one bounded path declaration')
        index = starts[0]
        value = lines[index].split('path:', 1)[1].strip()
        if value == '|':
            values = []
            for line in lines[index + 1:]:
                if not line.startswith('            '):
                    break
                values.append(line.strip())
        else:
            values = [value]
        check(values and all(value in allowed for value in values),
              'activation: artifacts contain only ciphertext or sanitized stage receipts')
        paths.extend(values)
    check(set(paths) == allowed and len(paths) == len(allowed),
          'activation: plaintext state/key/donor files cannot enter any artifact path')


def pipeline_cron_reconcile_cases(root):
    """Execute the byte-exact reconciler with synthetic status and crontab only."""
    tool = root / 'tools/reconcile_modeled_conversion_monitor_cron.sh'
    original = tool.read_bytes()
    text = original.decode('utf-8')
    check(not re.search(r'/(?:usr/)?(?:s?bin)/crontab\b', text),
          'pipeline cron: crontab lookup stays replaceable by the isolated fixture')
    check('ssh ' not in text and 'curl ' not in text,
          'pipeline cron: reconciliation contains no remote transport')
    bash = shutil.which('bash')
    if not bash or not Path('/usr/bin/python3').is_file():
        raise RuntimeError('Existing server CI bash and system Python are required')
    status_good = {'schema_version': 1, 'project': 'anytour', 'counter_id': '98615635',
                   'role': 'pipeline', 'configured': True}
    fake_status = r"""import json, os, subprocess, sys
from pathlib import Path
if sys.argv[1:] == ['--cron']:
    root = Path(__file__).resolve().parent.parent
    lock = root.parent / 'max-search-bot-modeled-pipeline/cron.lock'
    timeout_argv = Path('/proc/' + str(os.getppid()) + '/cmdline').read_bytes().split(b'\0')
    timeout_argv = [arg.decode('utf-8') for arg in timeout_argv if arg]
    lock_probe = subprocess.run(['/usr/bin/flock', '-n', str(lock), '/usr/bin/true'],
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=2)
    record = {'cwd': str(Path.cwd().resolve()), 'trigger': os.environ.get('MODELED_MONITOR_TRIGGER'),
              'argv': sys.argv[1:], 'lock_held': lock_probe.returncode == 1, 'timeout_argv': timeout_argv}
    with Path(os.environ['TICK_CALLS']).open('a', encoding='utf-8') as f:
        f.write(json.dumps(record) + '\n')
    expected_timeout = ['/usr/bin/timeout', '45s', '/usr/bin/python3', str(Path(__file__).resolve()), '--cron']
    valid = (Path.cwd().resolve() == root and record['trigger'] == 'cron'
             and record['lock_held'] and timeout_argv == expected_timeout)
    raise SystemExit(0 if valid else 93)
with Path(os.environ['STATUS_CALLS']).open('a', encoding='utf-8') as f:
    f.write(json.dumps(sys.argv[1:]) + '\n')
if sys.argv[1:] != ['--status']:
    raise SystemExit(91)
sys.stdout.write(os.environ['SYNTHETIC_STATUS_JSON'] + '\n')
raise SystemExit(int(os.environ.get('SYNTHETIC_STATUS_EXIT', '0')))
"""
    fake_crontab = r"""import json, os, sys
from pathlib import Path
base = Path(os.environ['CRON_FIXTURE']).resolve()
counter = base / 'read-count'
calls = base / 'crontab-calls.jsonl'
table = base / 'crontab'
def record(value):
    with calls.open('a', encoding='utf-8') as f:
        f.write(json.dumps(value) + '\n')
args = sys.argv[1:]
if args == ['-l']:
    count = int(counter.read_text() if counter.exists() else '0') + 1
    counter.write_text(str(count))
    record({'kind': 'read', 'number': count})
    if str(count) == os.environ.get('SYNTHETIC_READ_FAIL_AT'):
        raise SystemExit(1)
    if str(count) == os.environ.get('SYNTHETIC_DRIFT_AT'):
        table.write_text(table.read_text() + '# synthetic foreign writer\n')
    sys.stdout.write(table.read_text())
    raise SystemExit(0)
if len(args) != 1:
    raise SystemExit(92)
candidate = Path(args[0]).resolve()
if base not in candidate.parents:
    raise SystemExit(93)
value = candidate.read_text()
record({'kind': 'install', 'text': value})
table.write_text(value)
if os.environ.get('SYNTHETIC_INSTALL_FAIL_ONCE') == '1' and not (base / 'install-failed').exists():
    (base / 'install-failed').write_text('1')
    raise SystemExit(1)
raise SystemExit(0)
"""
    cases = [
        ('inactive empty', False, '', {}, True, False),
        ('inactive existing owned row', False, 'owned', {}, False, False),
        ('active preserves foreign rows', True, 'foreign', {}, True, False),
        ('second reconciliation is idempotent', True, 'foreign', {}, True, True),
        ('duplicate owned rows collapse to one', True, 'duplicates', {}, True, False),
        ('malformed status JSON', True, 'foreign', {'SYNTHETIC_STATUS_JSON': '{broken'}, False, False),
        ('status configured type mismatch', True, 'foreign', {'SYNTHETIC_STATUS_JSON': json.dumps(dict(status_good, configured='true'))}, False, False),
        ('foreign status project', True, 'foreign', {'SYNTHETIC_STATUS_JSON': json.dumps(dict(status_good, project='foreign'))}, False, False),
        ('status CLI failure', True, 'foreign', {'SYNTHETIC_STATUS_EXIT': '1'}, False, False),
        ('initial crontab read failure', True, 'foreign', {'SYNTHETIC_READ_FAIL_AT': '1'}, False, False),
        ('preinstall crontab read failure', True, 'foreign', {'SYNTHETIC_READ_FAIL_AT': '2'}, False, False),
        ('failed install restores previous table', True, 'foreign', {'SYNTHETIC_INSTALL_FAIL_ONCE': '1'}, False, False),
        ('concurrent foreign edit is preserved', True, 'foreign', {'SYNTHETIC_DRIFT_AT': '2'}, False, False),
    ]
    for name, configured, initial, overrides, valid, twice in cases:
        with tempfile.TemporaryDirectory(prefix='pipeline-cron-fixture-') as temporary:
            work = Path(temporary).resolve()
            project = work / 'project'
            tools = project / 'tools'
            tools.mkdir(parents=True)
            private = work / 'max-search-bot-modeled-pipeline'
            private.mkdir(mode=0o700)
            staging = work / 'temporary'
            staging.mkdir(mode=0o700)
            binary = work / 'bin'
            binary.mkdir()
            actual = tools / tool.name
            actual.write_bytes(original)
            check(actual.read_bytes() == original, 'pipeline cron: byte-exact source copied: ' + name)
            (tools / 'modeled_conversion_monitor_local.py').write_text(fake_status, encoding='utf-8')
            for command in ('dirname', 'mktemp', 'awk', 'stat', 'chmod', 'cmp', 'rm'):
                executable = shutil.which(command)
                if not executable:
                    raise RuntimeError('Existing CI command is required: ' + command)
                (binary / command).symlink_to(executable)
            crontab = binary / 'crontab'
            crontab.write_text('#!' + sys.executable + '\n' + fake_crontab, encoding='utf-8')
            crontab.chmod(0o700)
            owned = str(project / 'tools/modeled_conversion_monitor_local.py') + ' --cron'
            unrelated = '# synthetic existing jobs\n* * * * * /synthetic/existing-job\n'
            if initial == 'owned':
                before = '*/10 * * * * ' + owned + '\n'
            elif initial == 'duplicates':
                before = unrelated + '# max-search-bot modeled pipeline monitor\n' + '*/10 * * * * ' + owned + '\n* * * * * ' + owned + '\n'
            elif initial == 'foreign':
                before = unrelated
            else:
                before = ''
            table = work / 'crontab'
            table.write_text(before, encoding='utf-8')
            env = {'PATH': str(binary), 'LC_ALL': 'C', 'TMPDIR': str(staging),
                   'CRON_FIXTURE': str(work), 'STATUS_CALLS': str(work / 'status-calls.jsonl'),
                   'TICK_CALLS': str(work / 'tick-calls.jsonl'),
                   'SYNTHETIC_STATUS_JSON': json.dumps(dict(status_good, configured=configured)),
                   'SYNTHETIC_STATUS_EXIT': '0'}
            env.update(overrides)
            completed = subprocess.run([bash, '--noprofile', '--norc', str(actual)], cwd=project,
                                       env=env, text=True, capture_output=True, timeout=10)
            after = table.read_text(encoding='utf-8')
            records = [json.loads(line) for line in (work / 'crontab-calls.jsonl').read_text().splitlines()] \
                if (work / 'crontab-calls.jsonl').exists() else []
            installs = [row for row in records if row['kind'] == 'install']
            check((completed.returncode == 0) == valid, 'pipeline cron: actual reconcile outcome: ' + name)
            status_calls = [json.loads(line) for line in (work / 'status-calls.jsonl').read_text().splitlines()]
            check(status_calls == [['--status']], 'pipeline cron: never executes a manual cycle: ' + name)
            if not valid:
                if overrides.get('SYNTHETIC_INSTALL_FAIL_ONCE') == '1':
                    check(after == before and len(installs) == 2 and installs[-1]['text'] == before,
                          'pipeline cron: failed partial install restores the saved original')
                elif overrides.get('SYNTHETIC_DRIFT_AT') == '2':
                    check(after == before + '# synthetic foreign writer\n' and installs == [],
                          'pipeline cron: concurrent writer is not overwritten')
                else:
                    check(after == before and installs == [], 'pipeline cron: refusal preserves every prior row: ' + name)
                continue
            if not configured:
                check(after == before and installs == [], 'pipeline cron: inactive empty table is unchanged')
                continue
            canonical = ('*/5 * * * * cd "' + str(project) + '" && MODELED_MONITOR_TRIGGER=cron /usr/bin/flock -n '
                         + str(private / 'cron.lock') + ' /usr/bin/timeout 45s /usr/bin/python3 '
                         + owned + ' >/dev/null 2>&1\n')
            expected = unrelated + '# max-search-bot modeled pipeline monitor\n' + canonical
            check(after == expected and sum(owned in line and not line.lstrip().startswith('#')
                                           for line in after.splitlines()) == 1,
                  'pipeline cron: foreign rows and exactly one owned schedule: ' + name)
            check((private / 'cron.lock').is_file() and (private / 'cron.lock').stat().st_mode & 0o777 == 0o600,
                  'pipeline cron: private owned lock has required permissions: ' + name)
            if name == 'active preserves foreign rows':
                # Execute exactly the installed cron command, with only its five schedule fields removed.
                # The synthetic target enforces the real CLI's cwd/origin guard; no provider is imported.
                shell = shutil.which('sh')
                if not shell or not all(Path(path).is_file() for path in
                                        ('/usr/bin/flock', '/usr/bin/timeout', '/usr/bin/true')):
                    raise RuntimeError('Existing CI shell, flock and timeout are required')
                home = work / 'cron home'
                home.mkdir()
                rows = [line for line in after.splitlines() if owned in line and not line.lstrip().startswith('#')]
                command = rows[0].split(None, 5)[5]
                tick = subprocess.run([shell, '-c', command], cwd=home, env=env,
                                      text=True, capture_output=True, timeout=5)
                ticks = [json.loads(line) for line in (work / 'tick-calls.jsonl').read_text().splitlines()]
                expected_timeout = ['/usr/bin/timeout', '45s', '/usr/bin/python3',
                                    str(tools / 'modeled_conversion_monitor_local.py'), '--cron']
                check(tick.returncode == 0 and ticks == [{
                    'cwd': str(project), 'trigger': 'cron', 'argv': ['--cron'],
                    'lock_held': True, 'timeout_argv': expected_timeout}],
                    'pipeline cron: actual installed command enters canonical cwd and runs under its origin, lock and timeout')
                prefix = 'cd "' + str(project) + '" && '
                check(command.startswith(prefix), 'pipeline cron: canonical cwd is selected before the monitor command')
                legacy = subprocess.run([shell, '-c', command[len(prefix):]], cwd=home, env=env,
                                        text=True, capture_output=True, timeout=5)
                ticks = [json.loads(line) for line in (work / 'tick-calls.jsonl').read_text().splitlines()]
                check(legacy.returncode != 0 and len(ticks) == 2 and ticks[-1]['cwd'] == str(home)
                      and ticks[-1]['trigger'] == 'cron' and ticks[-1]['lock_held'] is True,
                      'pipeline cron: same target really rejects the former command from cron HOME')
                check(table.read_text(encoding='utf-8') == after
                      and [json.loads(line) for line in (work / 'status-calls.jsonl').read_text().splitlines()] == [['--status']],
                      'pipeline cron: executing the synthetic job changes no installed or foreign rows and never repeats status')
            if twice:
                repeated = subprocess.run([bash, '--noprofile', '--norc', str(actual)], cwd=project,
                                          env=env, text=True, capture_output=True, timeout=10)
                check(repeated.returncode == 0 and table.read_text(encoding='utf-8') == after,
                      'pipeline cron: second reconciliation is byte-idempotent')
                status_calls = [json.loads(line) for line in (work / 'status-calls.jsonl').read_text().splitlines()]
                check(status_calls == [['--status'], ['--status']], 'pipeline cron: repeated reconcile still never ticks')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args()
    sources = {filename: (args.root / '.github/workflows' / filename).read_text(encoding='utf-8')
               for filename in WORKFLOWS}
    monitor_schedule_cases(args.root, sources)
    for filename, source in sources.items():
        behavior_cases(source_step(source, filename), filename)
        if filename == 'modeled-conversion-monitor.yml':
            monitor_source_ready_cases(source)
            monitor_record_cases(source)
    pipeline_activation_cases(args.root)
    pipeline_cron_reconcile_cases(args.root)
    print(f'PRODUCTION GATE SEQUENCE: {CHECKS} PASS (offline real resolver execution)')


if __name__ == '__main__':
    main()
