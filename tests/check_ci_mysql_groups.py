#!/usr/bin/env python3
"""Check the native MySQL partition and execute its real shell with a PHP stub.

No application code, database, provider or GitHub request runs in this check.
The ordinary matrix jobs remain responsible for actual native MySQL evidence.
"""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile

import yaml

GROUPS = {
    'replies': ['tests/run_manager_prepared_reply_regression.php'],
    'migrations': [
        'tests/mysql/manager-preparation-migrations.php',
        'tests/mysql/virtual-reply-sampling.php',
        'tests/mysql/virtual-reply-controls.php',
        'tests/mysql/manager-tour-delivery.php',
        'tests/run_active_budget_regression.php --prepared-mysql',
    ],
    'queues': [
        'tests/mysql/hotel-work-queue.php',
        'tests/mysql/hotel-work-events.php',
        'tests/mysql/development-work-queue.php',
        'tests/mysql/development-work-events.php',
    ],
}
CONNECTION = {
    'PREPARED_REPLY_MYSQL_DSN': 'mysql:host=127.0.0.1;port=3306;dbname=maxbot_prepared_test;charset=utf8mb4',
    'PREPARED_REPLY_MYSQL_USER': 'root',
    'PREPARED_REPLY_MYSQL_PASS': 'prepared-fixture-only',
}


def verify(document):
    checks = 0

    def check(condition, message):
        nonlocal checks
        if not condition:
            raise AssertionError(message)
        checks += 1

    jobs = document['jobs']
    job = jobs['prepared-mysql']
    check(job['strategy'] == {
        'fail-fast': False, 'max-parallel': 3,
        'matrix': {'group': list(GROUPS)},
    }, 'all three independent groups must execute; no exclusion or ignored matrix failure')
    check(job['runs-on'] == 'ubuntu-latest' and 'container' not in job,
          'each matrix job requires its own hosted runner and service')
    check(job['needs'] == 'change-scope' and job['if'] == (
        "(needs.change-scope.outputs.mode == 'full' && "
        "needs.change-scope.outputs.backend != 'workspace-style') || "
        "(needs.change-scope.outputs.mode == 'tour-selection' && "
        "needs.change-scope.outputs.backend != 'selection-style')"),
        'existing full/component selection must remain unchanged')
    check(not job.get('continue-on-error', False), 'a failing MySQL group must remain fatal')
    check(job['services'] == {'mysql': {
        'image': 'mysql:8.0',
        'env': {'MYSQL_ROOT_PASSWORD': 'prepared-fixture-only',
                'MYSQL_ROOT_HOST': '%', 'MYSQL_DATABASE': 'maxbot_prepared_test'},
        'ports': ['3306:3306'],
        'options': '--health-cmd="mysqladmin ping -h 127.0.0.1 -pprepared-fixture-only" '
                   '--health-interval=5s --health-timeout=5s --health-retries=20',
    }}, 'each job retains the original ephemeral MySQL service and readiness checks')
    steps = job['steps']
    check(len(steps) == 3 and steps[0] == {'uses': 'actions/checkout@v4'}
          and steps[1] == {'uses': 'shivammathur/setup-php@v2', 'with': {
              'php-version': '8.2', 'extensions': 'mbstring, pdo_mysql', 'coverage': 'none'}},
          'native PHP environment and clean checkout must be retained')
    check(all('if' not in step and not step.get('continue-on-error', False) for step in steps),
          'no selected group step may be skipped or suppress an error')
    step = steps[2]
    check(step['shell'] == 'bash' and step['env'] == {
        'MYSQL_TEST_GROUP': '${{ matrix.group }}', **CONNECTION},
        'group selector and safe connection must bind directly to the matrix')
    expected = ['set -euo pipefail', 'case "$MYSQL_TEST_GROUP" in']
    for group, commands in GROUPS.items():
        expected.extend([group + ')', *['php ' + command for command in commands], ';;'])
    expected.extend(['*) exit 2 ;;', 'esac'])
    script = step['run']
    check([line.strip() for line in script.splitlines() if line.strip()] == expected,
          'all ten commands must execute exactly once in their original group order')
    check(len({command for commands in GROUPS.values() for command in commands}) == 10,
          'partition must not duplicate any command')
    aggregate = jobs['regression']
    check('prepared-mysql' in aggregate['needs'] and aggregate['if'] == 'always()'
          and not aggregate.get('continue-on-error', False),
          'existing final aggregate must await the whole matrix without ignoring failure')
    gates = [step for step in aggregate['steps'] if step.get('id') == 'source_checks']
    check(len(gates) == 1 and gates[0]['env']['PREPARED_MYSQL_RESULT'] == '${{ needs.prepared-mysql.result }}'
          and 'test "$PREPARED_MYSQL_RESULT" = success' in gates[0]['run']
          and not gates[0].get('continue-on-error', False) and 'if' not in gates[0],
          'source aggregate must use the actual matrix result and require success')
    owners = [step for step in jobs['inventory']['steps']
              if step.get('run') == 'python3 tests/check_ci_mysql_groups.py']
    check(len(owners) == 1 and 'if' not in owners[0]
          and not owners[0].get('continue-on-error', False),
          'this bounded partition check must execute once in the existing inventory job')

    with tempfile.TemporaryDirectory(prefix='mysql-partition-') as temp:
        folder = Path(temp)
        stub = folder / 'php'
        stub.write_text('#!' + sys.executable + '\n' + '''import json, os, shlex, sys
with open(os.environ['CALL_LOG'], 'a') as log:
    log.write(json.dumps({'args': sys.argv[1:], 'dsn': os.environ.get('PREPARED_REPLY_MYSQL_DSN')}) + '\\n')
sys.exit(37 if sys.argv[1:] == shlex.split(os.environ.get('FAIL_ON', '')) else 0)
''')
        stub.chmod(0o700)
        log = folder / 'calls.jsonl'

        def execute(group, fail_on=''):
            log.write_text('')
            env = {'PATH': temp + os.pathsep + '/usr/bin:/bin',
                   'CALL_LOG': str(log), 'FAIL_ON': fail_on, **CONNECTION}
            if group is not None:
                env['MYSQL_TEST_GROUP'] = group
            result = subprocess.run(['bash', '--noprofile', '--norc', '-e', '-c', script],
                                    cwd=temp, env=env, capture_output=True, timeout=5)
            calls = [json.loads(line) for line in log.read_text().splitlines()]
            return result.returncode, calls

        for group, commands in GROUPS.items():
            code, calls = execute(group)
            check(code == 0 and calls == [
                {'args': shlex.split(command), 'dsn': CONNECTION['PREPARED_REPLY_MYSQL_DSN']}
                for command in commands], 'actual shell lost/duplicated coverage in ' + group)
            for index, command in enumerate(commands):
                code, calls = execute(group, command)
                check(code == 37 and [call['args'] for call in calls] == [
                    shlex.split(item) for item in commands[:index + 1]],
                    'actual shell must stop and propagate failure from ' + command)
        for group in [None, '', 'unknown']:
            code, calls = execute(group)
            check(code != 0 and calls == [], 'missing or unknown group must not pass')
    return checks


if __name__ == '__main__':
    root = Path(__file__).resolve().parent.parent
    try:
        count = verify(yaml.safe_load((root / '.github/workflows/regression-tests.yml').read_text()))
    except (AssertionError, KeyError, TypeError, ValueError, OSError, subprocess.SubprocessError) as error:
        print('MySQL group contract FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
    print(f'MySQL group contract: {count} PASS (shell fixture; native MySQL runs separately)')
