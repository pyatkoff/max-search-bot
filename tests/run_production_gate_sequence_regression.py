#!/usr/bin/env python3
"""Execute the four real production-source resolvers offline, without credentials."""
import argparse
import copy
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

WORKFLOWS = (
    'max-production-health.yml',
    'max-tls-preflight.yml',
    'telegram-production-health.yml',
    'live-session-diagnostics.yml',
)
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


def source_step(source, filename):
    label = filename + ': '
    events = top_section(source, 'on')
    keys = re.findall(r'^  ([a-z_]+):', events, re.MULTILINE)
    expected = {'workflow_run', 'workflow_dispatch'}
    live = filename == 'live-session-diagnostics.yml'
    if live:
        expected.add('schedule')
    check(set(keys) == expected and len(keys) == len(expected), label + 'only deploy-completion and retained explicit triggers')
    for declaration in ('workflows: [Deploy production]', 'types: [completed]', 'branches: [main]'):
        check('    ' + declaration in events.splitlines(), label + 'missing upstream trigger ' + declaration)
    check("    if: github.event_name != 'workflow_run' || github.event.workflow_run.conclusion == 'success'" in source.splitlines(),
          label + 'automatic job must require successful deployment')
    if live:
        check("    - cron: '25 * * * *'" in events.splitlines(), label + 'existing hourly schedule retained exactly')
        concurrency = top_section(source, 'concurrency')
        check('  group: production-live-session-diagnostics' in concurrency.splitlines()
              and '  cancel-in-progress: false' in concurrency.splitlines(), label + 'active diagnostic capture must not be cancelled')

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
            upstream_id='none', upstream_attempt='none', raw_event=None):
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
               'RUNNER_TEMP': str(work)}
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
    live = filename == 'live-session-diagnostics.yml'
    execute(resolver, filename, 'existing live timer only', {'schedule': '25 * * * *'}, event='schedule',
            expected_sha=DOWNSTREAM_SHA if live else None)
    execute(resolver, filename, 'timer cannot inspect a feature branch', {}, event='schedule', ref='refs/heads/feature/source')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args()
    for filename in WORKFLOWS:
        source = (args.root / '.github/workflows' / filename).read_text(encoding='utf-8')
        behavior_cases(source_step(source, filename), filename)
    print(f'PRODUCTION GATE SEQUENCE: {CHECKS} PASS (offline real resolver execution)')


if __name__ == '__main__':
    main()
