#!/usr/bin/env python3
"""Exercise actual owner JSON authorization and fixed SSH gates without network/secrets."""
import copy
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / '.github/workflows/max-owner-tour-preview.yml').read_text()
OWNER = WORKFLOW.split('\n  owner-send:\n', 1)[1]
SHA = 'a' * 40
REMOTE_DIR = 'cd /var/www/anytoour/data/www/app.anytoour.ru'
COMMAND = 'php tools/max_owner_tour_preview.php --expected-sha="$expected_sha" --confirm-owner-send'


def run_blocks(source):
    lines, blocks = source.splitlines(), []
    for i, line in enumerate(lines):
        match = re.fullmatch(r'( +)run: \|', line)
        if not match:
            continue
        body = []
        for next_line in lines[i + 1:]:
            if next_line.strip() and len(next_line) - len(next_line.lstrip()) <= len(match[1]):
                break
            body.append(next_line)
        blocks.append(textwrap.dedent('\n'.join(body)))
    return blocks


blocks = run_blocks(OWNER)
validate, = [b for b in blocks if 'GITHUB_EVENT_PATH' in b]
send, = [b for b in blocks if 'ssh -o BatchMode=yes' in b]
guard, = re.findall(r'^    if: (.+)$', OWNER, re.M)
assert guard == "github.repository == 'pyatkoff/max-search-bot' && github.ref == 'refs/heads/main' && ((github.event_name == 'workflow_dispatch' && (inputs.confirm_owner_send == true || inputs.confirm_owner_recovery == true || inputs.confirm_owner_comparison == true || inputs.confirm_owner_variants == true || inputs.confirm_owner_telegram == true || inputs.confirm_owner_trio == true || inputs.confirm_owner_emphasis == true || inputs.confirm_owner_choice == true || inputs.confirm_owner_photo_choice == true || inputs.confirm_owner_live_quote == true)) || (github.event_name == 'issue_comment' && github.event.issue.number == 55 && !github.event.issue.pull_request && github.event.comment.user.login == 'pyatkoff' && github.event.comment.user.id == 226193297 && (startsWith(github.event.comment.body, '/owner-tour-preview ') || startsWith(github.event.comment.body, '/owner-tour-preview-recover ') || startsWith(github.event.comment.body, '/owner-tour-preview-compare ') || startsWith(github.event.comment.body, '/owner-tour-preview-variants ') || startsWith(github.event.comment.body, '/owner-tour-preview-telegram ') || startsWith(github.event.comment.body, '/owner-tour-preview-trio ') || startsWith(github.event.comment.body, '/owner-tour-preview-emphasis ') || startsWith(github.event.comment.body, '/owner-tour-choice-trial ') || startsWith(github.event.comment.body, '/owner-tour-photo-choice-trial ') || startsWith(github.event.comment.body, '/owner-tour-live-quote-trial '))))"
assert re.findall(r'^      ([a-z_]+):$', WORKFLOW.split('permissions:', 1)[0], re.M) == ['expected_sha', 'confirm_owner_send', 'confirm_owner_recovery', 'confirm_owner_comparison', 'confirm_owner_variants', 'confirm_owner_telegram', 'confirm_owner_trio', 'confirm_owner_emphasis', 'confirm_owner_choice', 'confirm_owner_photo_choice', 'confirm_owner_live_quote']
assert 'needs: regression' in OWNER and 'cancel-in-progress: false' in WORKFLOW
assert 'timeout-minutes: 20' in OWNER  # Bounded fixed six-message batch and nine MAX image uploads.
assert OWNER.index('GITHUB_EVENT_PATH') < OWNER.index('secrets.STANDBY_DEPLOY_SSH_KEY')
assert '${{ github.event.comment.body }}' not in WORKFLOW
assert 'issue_comment:\n    types: [created]' in WORKFLOW
assert 'EXPECTED_SHA: ${{ steps.authorize.outputs.expected_sha }}' in OWNER
assert send.count(COMMAND) == 1 and send.count(COMMAND.replace('--confirm-owner-send','--confirm-owner-recovery')) == 1
assert send.count(COMMAND.replace('--confirm-owner-send','--confirm-owner-comparison')) == 1
assert send.count(COMMAND.replace('--confirm-owner-send','--confirm-owner-variants')) == 1
TELEGRAM_COMMAND = COMMAND.replace('max_owner_tour_preview.php', 'telegram_owner_tour_preview.php').replace('--confirm-owner-send', '--confirm-owner-telegram')
assert send.count(TELEGRAM_COMMAND) == 1
TRIO_COMMAND = COMMAND.replace('max_owner_tour_preview.php', 'owner_tour_selection_preview.php').replace('--confirm-owner-send', '--confirm-owner-trio')
assert send.count(TRIO_COMMAND) == 1
EMPHASIS_COMMAND = COMMAND.replace('max_owner_tour_preview.php', 'owner_tour_card_emphasis_preview.php').replace('--confirm-owner-send', '--confirm-owner-emphasis')
assert send.count(EMPHASIS_COMMAND) == 1
assert "      - 'tools/owner_tour_card_emphasis_preview.php'" in WORKFLOW
assert "      - 'tests/run_owner_tour_card_emphasis_preview_regression.php'" in WORKFLOW
assert 'run: php tests/run_owner_tour_card_emphasis_preview_regression.php' in WORKFLOW
for path in ['tools/owner_tour_selection_preview.php', 'tests/run_owner_tour_selection_preview_regression.php']:
    assert "      - '" + path + "'" in WORKFLOW
assert 'run: php tests/run_owner_tour_selection_preview_regression.php' in WORKFLOW
assert 'case "$owner_mode" in' in send
assert len(re.findall(r'^\s*ssh ', send, re.M)) == 1 and send.count(REMOTE_DIR) == 1
assert not re.search(r'\b(?:for|while|until|retry|retries)\b', send)
assert '${{' not in send and not re.search(r'https?://|--(?:url|user-id|conversation-id)', send)
assert re.search(r'permissions:\n  contents: read\n', WORKFLOW)

IDENTITY = {'login': 'pyatkoff', 'id': 226193297}
EVENT = {'repository': {'full_name': 'pyatkoff/max-search-bot', 'default_branch': 'main'},
         'sender': IDENTITY.copy(), 'action': 'created', 'issue': {'number': 55},
         'comment': {'user': IDENTITY.copy(), 'body': '/owner-tour-preview ' + SHA}}
cases = 0


def execute(expected=SHA, workflow=SHA, installed=SHA, unstaged=0, staged=0, event=None, env_changes=None):
    global cases
    cases += 1
    with tempfile.TemporaryDirectory(prefix='max-owner-tour-test-') as temp:
        directory = Path(temp)
        doubles = {
            'ssh': 'printf "%s\\n" "$*" >> "$SSH_LOG"\n/bin/bash -s -- "${@: -2:1}" "${@: -1}"\n',
            'git': 'case "$*" in\n"rev-parse HEAD") printf "%s\\n" "$INSTALLED_SHA";;\n"diff --quiet") exit "$UNSTAGED";;\n"diff --cached --quiet") exit "$STAGED";;\n*) exit 90;;\nesac\n',
            'php': 'printf "%s\\n" "$*" >> "$PHP_LOG"\n',
        }
        for name, body in doubles.items():
            executable = directory / name
            executable.write_text('#!/bin/bash\nset -euo pipefail\n' + body)
            executable.chmod(0o700)
        # Stub urllib in the actual authorizer process. No live GitHub read or token.
        (directory / 'sitecustomize.py').write_text(textwrap.dedent('''
            import json, os, urllib.request, urllib.error
            class Response:
                @property
                def status(self): return int(os.environ.get('READ_API_STATUS', '200'))
                def __enter__(self): return self
                def __exit__(self, *args): pass
                def read(self, limit):
                    assert limit == 65537
                    if os.environ.get('READ_API_BODY') == 'oversize': return b'x' * 65537
                    if os.environ.get('READ_API_BODY') == 'invalid': return b'not-json'
                    return json.dumps({'ref': 'refs/heads/main', 'object': {'type': 'commit', 'sha': os.environ['ACTUAL_MAIN_SHA']}}).encode()
            class Opener:
                def open(self, request, timeout):
                    assert request.full_url == 'https://api.github.com/repos/pyatkoff/max-search-bot/git/ref/heads/main'
                    assert request.get_method() == 'GET' and timeout == 15
                    assert request.get_header('Authorization') == 'Bearer READ_ONLY_TOKEN'
                    with open(os.environ['API_LOG'], 'a') as log: log.write('GET main\\n')
                    if os.environ.get('READ_API_STATUS') == 'error': raise urllib.error.URLError('offline refusal')
                    return Response()
            urllib.request.build_opener = lambda *handlers: Opener()
        '''))
        event_path = directory / 'event.json'
        event_path.write_text(json.dumps(EVENT if event is None else event))
        env = {**os.environ, 'PATH': str(directory) + ':/usr/bin:/bin',
               'EXPECTED_SHA': expected, 'CONFIRM_OWNER_SEND': 'true', 'CONFIRM_OWNER_RECOVERY': 'false', 'CONFIRM_OWNER_COMPARISON': 'false', 'CONFIRM_OWNER_VARIANTS': 'false', 'CONFIRM_OWNER_TELEGRAM': 'false', 'CONFIRM_OWNER_TRIO': 'false', 'CONFIRM_OWNER_EMPHASIS': 'false', 'CONFIRM_OWNER_CHOICE': 'false', 'CONFIRM_OWNER_PHOTO_CHOICE': 'false', 'CONFIRM_OWNER_LIVE_QUOTE': 'false', 'ACTUAL_MAIN_SHA': SHA,
               'PYTHONPATH': temp, 'REPOSITORY_READ_TOKEN': 'READ_ONLY_TOKEN', 'API_LOG': str(directory / 'api.log'),
               'GITHUB_SHA': workflow, 'GITHUB_EVENT_NAME': 'workflow_dispatch',
               'GITHUB_EVENT_PATH': str(event_path), 'GITHUB_OUTPUT': str(directory / 'output'),
               'GITHUB_REF': 'refs/heads/main', 'GITHUB_REPOSITORY': 'pyatkoff/max-search-bot',
               'GITHUB_WORKFLOW_REF': 'pyatkoff/max-search-bot/.github/workflows/max-owner-tour-preview.yml@refs/heads/main',
               'GITHUB_ACTOR': 'pyatkoff', 'GITHUB_ACTOR_ID': '226193297',
               'INSTALLED_SHA': installed, 'UNSTAGED': str(unstaged), 'STAGED': str(staged),
               'DEPLOY_HOST': 'offline.invalid', 'DEPLOY_USER': 'fixture', 'RUNNER_TEMP': temp,
               'PROBE_FIXTURE_CHECKOUT': temp, 'SSH_LOG': str(directory / 'ssh.log'),
               'PHP_LOG': str(directory / 'php.log'), **(env_changes or {})}
        result = subprocess.run(['/bin/bash', '-c', validate], env=env, capture_output=True, timeout=5)
        if result.returncode == 0:
            validated, mode = (directory / 'output').read_text().splitlines()
            assert mode in ['owner_mode=send','owner_mode=recover','owner_mode=compare','owner_mode=variants','owner_mode=telegram','owner_mode=trio','owner_mode=emphasis','owner_mode=choice','owner_mode=photo-choice','owner_mode=live-quote']
            env['OWNER_MODE'] = mode.split('=', 1)[1]
            assert re.fullmatch(r'expected_sha=[0-9a-f]{40}', validated)
            env['EXPECTED_SHA'] = validated.split('=', 1)[1]
            result = subprocess.run(['/bin/bash', '-c', send.replace(REMOTE_DIR, 'cd "$PROBE_FIXTURE_CHECKOUT"')],
                                    env=env, capture_output=True, timeout=5)
        logs = [(directory / name).read_text().splitlines() if (directory / name).exists() else []
                for name in ['ssh.log', 'php.log']]
        return result.returncode, *logs


for bad in ['', 'a' * 39, 'A' * 40, SHA + '\n', '$(touch /must-not-run)', 'b' * 40]:
    code, ssh, php = execute(expected=bad)
    assert code != 0 and ssh == [] and php == [], repr(bad)
for kwargs in [{'installed': 'b' * 40}, {'unstaged': 1}, {'staged': 1}]:
    code, ssh, php = execute(**kwargs)
    assert code != 0 and len(ssh) == 1 and php == [], kwargs
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(env_changes={'GITHUB_EVENT_NAME': event_name})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/max_owner_tour_preview.php --expected-sha={SHA} --confirm-owner-send']

# Execute the real Python authorizer, not a second implementation of its policy.
for changes in [{'CONFIRM_OWNER_SEND': 'false'}, {'GITHUB_EVENT_NAME': 'push'},
                {'GITHUB_EVENT_NAME': 'pull_request'}, {'GITHUB_REF': 'refs/heads/feature'},
                {'GITHUB_WORKFLOW_REF': 'pyatkoff/max-search-bot/.github/workflows/max-owner-tour-preview.yml@refs/heads/feature'},
                {'GITHUB_REPOSITORY': 'someone/max-search-bot'}, {'ACTUAL_MAIN_SHA': 'b' * 40},
                {'REPOSITORY_READ_TOKEN': ''}, {'READ_API_STATUS': 'error'}, {'READ_API_STATUS': '403'},
                {'READ_API_STATUS': '302'}, {'READ_API_BODY': 'oversize'}, {'READ_API_BODY': 'invalid'}]:
    code, ssh, php = execute(env_changes=changes)
    assert code != 0 and ssh == [] and php == [], changes
for body in ['/owner-tour-preview ' + SHA + '\n', '/owner-tour-preview ' + SHA + ' extra',
             '/owner-tour-preview ' + SHA.upper(), '/owner-tour-preview ' + 'a' * 39,
             ' /owner-tour-preview ' + SHA, '/owner-tour-preview  ' + SHA,
             '/owner-tour-preview $(touch /must-not-run)', 'ordinary owner comment']:
    event = copy.deepcopy(EVENT)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
for path, value in [(('comment', 'user', 'login'), 'pyatkoff-bot'), (('comment', 'user', 'id'), 1),
                    (('sender', 'id'), 1), (('sender', 'login'), 'another'),
                    (('issue', 'number'), 56), (('issue', 'pull_request'), None),
                    (('repository', 'full_name'), 'someone/max-search-bot'),
                    (('repository', 'default_branch'), 'feature'), (('action',), 'edited')]:
    event = copy.deepcopy(EVENT)
    node = event
    for key in path[:-1]:
        node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for actor in [{'GITHUB_ACTOR': 'someone'}, {'GITHUB_ACTOR_ID': '1'}]:
    code, ssh, php = execute(env_changes={'GITHUB_EVENT_NAME': 'issue_comment', **actor})
    assert code != 0 and ssh == [] and php == [], actor
for event_name in ['workflow_dispatch', 'issue_comment']:
    event = copy.deepcopy(EVENT)
    event['comment']['body'] = '/owner-tour-preview-recover ' + SHA
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_RECOVERY': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/max_owner_tour_preview.php --expected-sha={SHA} --confirm-owner-recovery']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}]:
    code, ssh, php = execute(env_changes={'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_RECOVERY': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for body in ['/owner-tour-preview-recover ' + SHA + '\n', '/owner-tour-preview-recover ' + SHA + ' extra',
             '/owner-tour-preview-recover ' + SHA.upper(), '/owner-tour-preview-recover $(touch /must-not-run)']:
    event = copy.deepcopy(EVENT)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body

# The separately requested comparison has its own exact owner-only activation.
comparison = copy.deepcopy(EVENT)
comparison['comment']['body'] = '/owner-tour-preview-compare ' + SHA
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=comparison, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_COMPARISON': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/max_owner_tour_preview.php --expected-sha={SHA} --confirm-owner-comparison']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'CONFIRM_OWNER_RECOVERY': 'true'},
                {'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40}]:
    code, ssh, php = execute(event=comparison, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_COMPARISON': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for body in ['/owner-tour-preview-compare ' + SHA + '\n', '/owner-tour-preview-compare ' + SHA + ' extra',
             '/owner-tour-preview-compare ' + SHA.upper(), '/owner-tour-preview-compare $(touch /must-not-run)']:
    event = copy.deepcopy(comparison)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
for path, value in [(('comment', 'user', 'id'), 1), (('sender', 'id'), 1),
                    (('issue', 'number'), 56), (('issue', 'pull_request'), {}), (('action',), 'edited')]:
    event = copy.deepcopy(comparison)
    node = event
    for key in path[:-1]:
        node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
# The two fixed native variants retain all existing owner and provenance gates.
variants = copy.deepcopy(EVENT)
variants['comment']['body'] = '/owner-tour-preview-variants ' + SHA
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=variants, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_VARIANTS': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/max_owner_tour_preview.php --expected-sha={SHA} --confirm-owner-variants']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'CONFIRM_OWNER_RECOVERY': 'true'},
                {'CONFIRM_OWNER_COMPARISON': 'true'}, {'GITHUB_ACTOR': 'another'},
                {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40}]:
    code, ssh, php = execute(event=variants, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_VARIANTS': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for body in ['/owner-tour-preview-variants ' + SHA + '\n', '/owner-tour-preview-variants ' + SHA + ' extra',
             '/owner-tour-preview-variants $(touch /must-not-run)']:
    event = copy.deepcopy(variants)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
telegram = copy.deepcopy(EVENT)
telegram['comment']['body'] = '/owner-tour-preview-telegram ' + SHA
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=telegram, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_TELEGRAM': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/telegram_owner_tour_preview.php --expected-sha={SHA} --confirm-owner-telegram']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'CONFIRM_OWNER_RECOVERY': 'true'},
                {'CONFIRM_OWNER_COMPARISON': 'true'}, {'CONFIRM_OWNER_VARIANTS': 'true'},
                {'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40}]:
    code, ssh, php = execute(event=telegram, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_TELEGRAM': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for path, value in [(('comment', 'user', 'id'), 1), (('sender', 'id'), 1), (('issue', 'number'), 56), (('action',), 'edited')]:
    event = copy.deepcopy(telegram)
    node = event
    for key in path[:-1]: node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for body in ['/owner-tour-preview-telegram ' + SHA + '\n', '/owner-tour-preview-telegram ' + SHA + ' extra',
             '/owner-tour-preview-telegram $(touch /must-not-run)']:
    event = copy.deepcopy(telegram)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
# A fixed three-hotel batch has one exact activation, never arbitrary chat/content input.
trio = copy.deepcopy(EVENT)
trio['comment']['body'] = '/owner-tour-preview-trio ' + SHA
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=trio, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_TRIO': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/owner_tour_selection_preview.php --expected-sha={SHA} --confirm-owner-trio']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'CONFIRM_OWNER_RECOVERY': 'true'},
                {'CONFIRM_OWNER_COMPARISON': 'true'}, {'CONFIRM_OWNER_VARIANTS': 'true'},
                {'CONFIRM_OWNER_TELEGRAM': 'true'}, {'GITHUB_ACTOR': 'another'},
                {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40}]:
    code, ssh, php = execute(event=trio, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_TRIO': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for path, value in [(('comment', 'user', 'id'), 1), (('sender', 'id'), 1),
                    (('issue', 'number'), 56), (('issue', 'pull_request'), {}), (('action',), 'edited')]:
    event = copy.deepcopy(trio)
    node = event
    for key in path[:-1]: node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for body in ['/owner-tour-preview-trio ' + SHA + '\n', '/owner-tour-preview-trio ' + SHA + ' extra',
             '/owner-tour-preview-trio ' + SHA.upper(), '/owner-tour-preview-trio $(touch /must-not-run)',
             '/owner-tour-preview-trio ' + SHA + ' --chat-id=3']:
    event = copy.deepcopy(trio)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
# The separately requested restrained emoji example has its own exact owner activation.
emphasis = copy.deepcopy(EVENT)
emphasis['comment']['body'] = '/owner-tour-preview-emphasis ' + SHA
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=emphasis, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_EMPHASIS': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/owner_tour_card_emphasis_preview.php --expected-sha={SHA} --confirm-owner-emphasis']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'CONFIRM_OWNER_RECOVERY': 'true'},
                {'CONFIRM_OWNER_COMPARISON': 'true'}, {'CONFIRM_OWNER_VARIANTS': 'true'},
                {'CONFIRM_OWNER_TELEGRAM': 'true'}, {'CONFIRM_OWNER_TRIO': 'true'}, {'CONFIRM_OWNER_CHOICE': 'true'},
                {'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40}]:
    code, ssh, php = execute(event=emphasis, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_EMPHASIS': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for path, value in [(('comment', 'user', 'id'), 1), (('sender', 'id'), 1),
                    (('issue', 'number'), 56), (('issue', 'pull_request'), {}), (('action',), 'edited')]:
    event = copy.deepcopy(emphasis)
    node = event
    for key in path[:-1]: node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for body in ['/owner-tour-preview-emphasis ' + SHA + '\n', '/owner-tour-preview-emphasis ' + SHA + ' extra',
             '/owner-tour-preview-emphasis ' + SHA.upper(), '/owner-tour-preview-emphasis $(touch /must-not-run)',
             '/owner-tour-preview-emphasis ' + SHA + ' --chat-id=3']:
    event = copy.deepcopy(emphasis)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
# Functional choices have a separate command; old preview activations cannot invoke it.
choice = copy.deepcopy(EVENT)
choice['comment']['body'] = '/owner-tour-choice-trial ' + SHA
CHOICE_COMMAND = COMMAND.replace('max_owner_tour_preview.php', 'owner_tour_choice_trial.php').replace('--confirm-owner-send', '--confirm-owner-choice')
assert send.count(CHOICE_COMMAND) == 1
assert "      - 'tools/owner_tour_choice_trial.php'" in WORKFLOW
assert "      - 'tests/run_owner_tour_choice_trial_regression.php'" in WORKFLOW
assert 'run: php tests/run_owner_tour_choice_trial_regression.php' in WORKFLOW
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=choice, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_CHOICE': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/owner_tour_choice_trial.php --expected-sha={SHA} --confirm-owner-choice']
for changes in [{'CONFIRM_OWNER_SEND': 'true'}, {'CONFIRM_OWNER_RECOVERY': 'true'},
                {'CONFIRM_OWNER_COMPARISON': 'true'}, {'CONFIRM_OWNER_VARIANTS': 'true'},
                {'CONFIRM_OWNER_TELEGRAM': 'true'}, {'CONFIRM_OWNER_TRIO': 'true'}, {'CONFIRM_OWNER_EMPHASIS': 'true'},
                {'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40}]:
    code, ssh, php = execute(event=choice, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_CHOICE': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for path, value in [(('comment', 'user', 'id'), 1), (('sender', 'id'), 1),
                    (('issue', 'number'), 56), (('issue', 'pull_request'), {}), (('action',), 'edited')]:
    event = copy.deepcopy(choice)
    node = event
    for key in path[:-1]: node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for body in ['/owner-tour-choice-trial ' + SHA + '\n', '/owner-tour-choice-trial ' + SHA + ' extra',
             '/owner-tour-choice-trial ' + SHA.upper(), '/owner-tour-choice-trial $(touch /must-not-run)',
             '/owner-tour-choice-trial ' + SHA + ' --chat-id=3', '/owner-tour-preview-choice ' + SHA]:
    event = copy.deepcopy(choice)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
# Native photo choices retain a separate activation and all eight historical selectors.
photo_choice = copy.deepcopy(EVENT)
photo_choice['comment']['body'] = '/owner-tour-photo-choice-trial ' + SHA
PHOTO_CHOICE_COMMAND = COMMAND.replace('max_owner_tour_preview.php', 'owner_tour_photo_choice_trial.php').replace('--confirm-owner-send', '--confirm-owner-photo-choice')
assert send.count(PHOTO_CHOICE_COMMAND) == 1
for path in ['tools/owner_tour_photo_choice_trial.php', 'services/TourOfferCardRenderer.php',
             'tests/run_owner_tour_photo_choice_trial_regression.php', 'tests/run_tour_offer_card_renderer_regression.php']:
    assert "      - '" + path + "'" in WORKFLOW
assert 'run: php tests/run_owner_tour_photo_choice_trial_regression.php' in WORKFLOW
assert 'run: php tests/run_tour_offer_card_renderer_regression.php' in WORKFLOW
assert WORKFLOW.count("startsWith(github.event.comment.body, '/owner-tour-photo-choice-trial ')") == 2
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=photo_choice, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_PHOTO_CHOICE': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/owner_tour_photo_choice_trial.php --expected-sha={SHA} --confirm-owner-photo-choice']
for changes in [{'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40},
                {'GITHUB_REF': 'refs/heads/feature'}, {'REPOSITORY_READ_TOKEN': ''}, {'READ_API_STATUS': '302'}]:
    code, ssh, php = execute(event=photo_choice, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_PHOTO_CHOICE': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for kwargs in [{'installed': 'b' * 40}, {'unstaged': 1}, {'staged': 1}]:
    code, ssh, php = execute(event=photo_choice, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_PHOTO_CHOICE': 'true'}, **kwargs)
    assert code != 0 and len(ssh) == 1 and php == [], kwargs
for path, value in [(('comment', 'user', 'login'), 'pyatkoff-bot'), (('comment', 'user', 'id'), 1),
                    (('sender', 'id'), 1), (('issue', 'number'), 56), (('issue', 'pull_request'), {}),
                    (('action',), 'edited')]:
    event = copy.deepcopy(photo_choice)
    node = event
    for key in path[:-1]: node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for body in ['/owner-tour-photo-choice-trial ' + SHA + '\n', '/owner-tour-photo-choice-trial ' + SHA + ' extra',
             '/owner-tour-photo-choice-trial ' + SHA.upper(), '/owner-tour-photo-choice-trial $(touch /must-not-run)',
             '/owner-tour-photo-choice-trial ' + SHA + ' --chat-id=3', '/owner-tour-preview-photo-choice ' + SHA,
             '/owner-tour-photo-choice-trial ' + SHA + '\n/owner-tour-choice-trial ' + SHA]:
    event = copy.deepcopy(photo_choice)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body

# Live quotes use one separate fixed entrypoint; none of the nine older modes can select it.
live_quote = copy.deepcopy(EVENT)
live_quote['comment']['body'] = '/owner-tour-live-quote-trial ' + SHA
LIVE_QUOTE_COMMAND = COMMAND.replace('max_owner_tour_preview.php', 'owner_tour_live_quote_trial.php').replace('--confirm-owner-send', '--confirm-owner-live-quote')
assert send.count(LIVE_QUOTE_COMMAND) == 1
for path in ['tools/owner_tour_live_quote_trial.php', 'tests/fixtures/owner_live_quote_trial.php']:
    assert "      - '" + path + "'" in WORKFLOW
assert 'run: php tests/fixtures/owner_live_quote_trial.php' in WORKFLOW
assert WORKFLOW.index('run: php tests/run_owner_tour_photo_choice_trial_regression.php') < WORKFLOW.index('run: php tests/fixtures/owner_live_quote_trial.php')
assert WORKFLOW.count("startsWith(github.event.comment.body, '/owner-tour-live-quote-trial ')") == 2
for event_name in ['workflow_dispatch', 'issue_comment']:
    code, ssh, php = execute(event=live_quote, env_changes={'GITHUB_EVENT_NAME': event_name,
        'CONFIRM_OWNER_SEND': 'false', 'CONFIRM_OWNER_LIVE_QUOTE': 'true'})
    assert code == 0 and len(ssh) == 1
    assert php == [f'tools/owner_tour_live_quote_trial.php --expected-sha={SHA} --confirm-owner-live-quote']
for changes in [{'GITHUB_ACTOR': 'another'}, {'GITHUB_ACTOR_ID': '1'}, {'ACTUAL_MAIN_SHA': 'b' * 40},
                {'GITHUB_REF': 'refs/heads/feature'}, {'REPOSITORY_READ_TOKEN': ''}, {'READ_API_STATUS': '302'},
                {'GITHUB_WORKFLOW_REF': 'pyatkoff/max-search-bot/.github/workflows/max-owner-tour-preview.yml@refs/heads/feature'}]:
    code, ssh, php = execute(event=live_quote, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_LIVE_QUOTE': 'true', **changes})
    assert code != 0 and ssh == [] and php == [], changes
for kwargs in [{'installed': 'b' * 40}, {'unstaged': 1}, {'staged': 1}]:
    code, ssh, php = execute(event=live_quote, env_changes={'CONFIRM_OWNER_SEND': 'false',
        'CONFIRM_OWNER_LIVE_QUOTE': 'true'}, **kwargs)
    assert code != 0 and len(ssh) == 1 and php == [], kwargs
for path, value in [(('comment', 'user', 'login'), 'pyatkoff-bot'), (('comment', 'user', 'id'), 1),
                    (('sender', 'id'), 1), (('issue', 'number'), 56), (('issue', 'pull_request'), {}),
                    (('action',), 'edited')]:
    event = copy.deepcopy(live_quote)
    node = event
    for key in path[:-1]: node = node[key]
    node[path[-1]] = value
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], (path, value)
for body in ['/owner-tour-live-quote-trial ' + SHA + '\n', '/owner-tour-live-quote-trial ' + SHA + ' extra',
             '/owner-tour-live-quote-trial ' + SHA.upper(), '/owner-tour-live-quote-trial $(touch /must-not-run)',
             '/owner-tour-live-quote-trial ' + SHA + ' --chat-id=3', '/owner-tour-preview-live-quote ' + SHA,
             '/owner-tour-live-quote-trial ' + SHA + '\n/owner-tour-photo-choice-trial ' + SHA,
             '/owner-tour-photo-choice-trial ' + SHA + ' /owner-tour-live-quote-trial ' + SHA]:
    event = copy.deepcopy(live_quote)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body

# Every pair of ten confirmation flags fails before SSH/secrets.
flags = ['CONFIRM_OWNER_SEND', 'CONFIRM_OWNER_RECOVERY', 'CONFIRM_OWNER_COMPARISON',
         'CONFIRM_OWNER_VARIANTS', 'CONFIRM_OWNER_TELEGRAM', 'CONFIRM_OWNER_TRIO',
         'CONFIRM_OWNER_EMPHASIS', 'CONFIRM_OWNER_CHOICE', 'CONFIRM_OWNER_PHOTO_CHOICE', 'CONFIRM_OWNER_LIVE_QUOTE']
for index, first in enumerate(flags):
    for second in flags[index + 1:]:
        changes = {flag: 'false' for flag in flags}
        changes.update({first: 'true', second: 'true'})
        code, ssh, php = execute(env_changes=changes)
        assert code != 0 and ssh == [] and php == [], (first, second)
for body in ['/owner-tour-choice-trial ' + SHA + '\n/owner-tour-preview-emphasis ' + SHA,
             '/owner-tour-preview-emphasis ' + SHA + ' /owner-tour-choice-trial ' + SHA]:
    event = copy.deepcopy(EVENT)
    event['comment']['body'] = body
    code, ssh, php = execute(event=event, env_changes={'GITHUB_EVENT_NAME': 'issue_comment'})
    assert code != 0 and ssh == [] and php == [], body
print(f'MAX OWNER TOUR WORKFLOW: {cases} actual JSON authorization/fixed shell scenarios PASSED')
