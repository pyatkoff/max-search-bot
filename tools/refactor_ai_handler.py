#!/usr/bin/env python3
from __future__ import print_function

import os
import shutil
import subprocess
import sys
from datetime import datetime

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
WEBHOOK = os.path.join(ROOT, 'webhook.php')
HANDLER = os.path.join(ROOT, 'handlers', 'AiMessageHandler.php')

START = "            if($status==MaxSearchApi::$statusAi || !$status || $status==MaxSearchApi::$statusStart)\n            {\n"
END = "            }\n            elseif($status==MaxSearchApi::$statusCityChoose)"
INCLUDE = "require_once(__DIR__ . '/handlers/AiMessageHandler.php');\n"
INCLUDE_AFTER = "require_once(__DIR__ . '/handlers/AiDateHandler.php');\n"


def fail(message):
    print('ERROR: ' + message)
    sys.exit(1)


def php_lint(path):
    proc = subprocess.Popen(['php', '-l', path], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    out = proc.communicate()[0]
    if not isinstance(out, str):
        out = out.decode('utf-8', 'replace')
    print(out.rstrip())
    return proc.returncode == 0


with open(WEBHOOK, 'r') as fh:
    source = fh.read()

if 'AiMessageHandler::handle($message, $chat_id);' in source:
    print('ALREADY_REFACTORED')
    sys.exit(0)

start_pos = source.find(START)
if start_pos < 0:
    fail('AI branch start marker not found')

body_start = start_pos + len(START)
end_pos = source.find(END, body_start)
if end_pos < 0:
    fail('AI branch end marker not found')

body = source[body_start:end_pos]
if len(body.strip()) < 1000:
    fail('AI branch looks unexpectedly small; refusing to modify production')

handler_source = """<?php
require_once(__DIR__ . '/../ai/AiRouter.php');
require_once(__DIR__ . '/AiDateHandler.php');

class AiMessageHandler
{
    public static function handle($message, $chat_id)
    {
%s
    }
}
""" % body

replacement = """            if($status==MaxSearchApi::$statusAi || !$status || $status==MaxSearchApi::$statusStart)
            {
                AiMessageHandler::handle($message, $chat_id);
            }
            elseif($status==MaxSearchApi::$statusCityChoose)"""

new_source = source[:start_pos] + replacement + source[end_pos + len(END):]

if INCLUDE not in new_source:
    if INCLUDE_AFTER not in new_source:
        fail('include insertion marker not found')
    new_source = new_source.replace(INCLUDE_AFTER, INCLUDE_AFTER + INCLUDE, 1)

stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
webhook_backup = WEBHOOK + '.before_ai_handler_' + stamp
handler_backup = None
shutil.copy2(WEBHOOK, webhook_backup)
if os.path.exists(HANDLER):
    handler_backup = HANDLER + '.before_refactor_' + stamp
    shutil.copy2(HANDLER, handler_backup)

try:
    with open(HANDLER, 'w') as fh:
        fh.write(handler_source)
    with open(WEBHOOK, 'w') as fh:
        fh.write(new_source)

    ok_handler = php_lint(HANDLER)
    ok_webhook = php_lint(WEBHOOK)
    if not (ok_handler and ok_webhook):
        raise RuntimeError('syntax check failed')
except Exception as exc:
    shutil.copy2(webhook_backup, WEBHOOK)
    if handler_backup:
        shutil.copy2(handler_backup, HANDLER)
    elif os.path.exists(HANDLER):
        os.unlink(HANDLER)
    print('ROLLBACK: ' + str(exc))
    sys.exit(1)

print('REFACTOR_OK')
print('Backup: ' + webhook_backup)
print('Created: ' + HANDLER)
print('AI branch bytes moved: ' + str(len(body)))
