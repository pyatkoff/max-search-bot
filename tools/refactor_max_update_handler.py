#!/usr/bin/env python3
from __future__ import print_function

from pathlib import Path
import shutil
import subprocess
import sys
from datetime import datetime

ROOT = Path(__file__).resolve().parent.parent
WEBHOOK = ROOT / 'webhook.php'
HANDLER = ROOT / 'handlers' / 'MaxUpdateHandler.php'

text = WEBHOOK.read_text()

# Remove the accidental duplicate cancel in /start with payload.
dup = "\t\t\tMaxSearchApi::cancelToursFollowup($chat_id);\n\t\t\tMaxSearchApi::cancelToursFollowup($chat_id);\n"
if dup in text:
    text = text.replace(dup, "\t\t\tMaxSearchApi::cancelToursFollowup($chat_id);\n", 1)

start_marker = "$content = file_get_contents('php://input');"
start = text.find(start_marker)
if start < 0:
    raise RuntimeError('transport start marker not found')

transport = text[start:].rstrip() + "\n"

# Convert the current bottom transport block into one handler method almost 1:1.
# The webhook-specific final response remains inside the method so runtime behavior is preserved.
method = transport
method = method.replace("$content = file_get_contents('php://input');", "$content = file_get_contents('php://input');", 1)
handler_text = "<?php\n\nclass MaxUpdateHandler\n{\n    public static function handle()\n    {\n" + ''.join('        ' + line if line.strip() else line for line in method.splitlines(True)) + "    }\n}\n"

require_line = "require_once(__DIR__ . '/handlers/MaxUpdateHandler.php');\n"
anchor = "require_once(__DIR__ . '/handlers/StateMessageHandler.php');\n"
head = text[:start]
if require_line not in head:
    if anchor not in head:
        raise RuntimeError('require anchor not found')
    head = head.replace(anchor, anchor + require_line, 1)

# Keep webhook as a small entry point.
new_text = head.rstrip() + "\n\nMaxUpdateHandler::handle();\n"

stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
backup = WEBHOOK.with_name('webhook.php.before_max_update_handler_' + stamp)
shutil.copy2(str(WEBHOOK), str(backup))

handler_backup = None
if HANDLER.exists():
    handler_backup = HANDLER.with_name('MaxUpdateHandler.php.before_refactor_' + stamp)
    shutil.copy2(str(HANDLER), str(handler_backup))

HANDLER.write_text(handler_text)
WEBHOOK.write_text(new_text)

failed = False
for path in [HANDLER, WEBHOOK]:
    rc = subprocess.call(['/usr/bin/php', '-l', str(path)])
    if rc != 0:
        failed = True

if failed:
    shutil.copy2(str(backup), str(WEBHOOK))
    if handler_backup is not None:
        shutil.copy2(str(handler_backup), str(HANDLER))
    elif HANDLER.exists():
        HANDLER.unlink()
    print('ROLLBACK: syntax check failed')
    sys.exit(1)

print('REFACTOR_OK')
print('Backup: ' + str(backup))
print('Created: ' + str(HANDLER))
print('MAX transport bytes moved: ' + str(len(transport.encode('utf-8'))))
print('Changed: duplicate cancelToursFollowup removed from /start payload branch.')
