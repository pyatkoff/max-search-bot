#!/usr/bin/env python3
from __future__ import print_function

from pathlib import Path
import shutil
import subprocess
import sys
from datetime import datetime

ROOT = Path(__file__).resolve().parent.parent
WEBHOOK = ROOT / 'webhook.php'
HANDLER = ROOT / 'handlers' / 'CallbackHandler.php'

text = WEBHOOK.read_text()
start_marker = 'function processQuery($query) {'
end_marker = 'function put_log_in($data){'

start = text.find(start_marker)
if start < 0:
    raise RuntimeError('processQuery start marker not found')

end = text.find(end_marker, start)
if end < 0:
    raise RuntimeError('put_log_in end marker not found')

original_block = text[start:end]
function_block = original_block.rstrip()

if not function_block.endswith('}'):
    raise RuntimeError('processQuery block does not end with }')

method_block = function_block.replace(
    'function processQuery($query)',
    'public static function handle($query)',
    1
)

handler_text = "<?php\n\nclass CallbackHandler\n{\n" + method_block + "\n}\n"

require_line = "require_once(__DIR__ . '/handlers/CallbackHandler.php');\n"
anchor = "require_once(__DIR__ . '/handlers/AiMessageHandler.php');\n"

if require_line not in text:
    if anchor not in text:
        raise RuntimeError('require anchor not found')
    text = text.replace(anchor, anchor + require_line, 1)

wrapper = "function processQuery($query) {\n\tCallbackHandler::handle($query);\n}\n\n\n"
text = text[:start] + wrapper + text[end:]

stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
backup = WEBHOOK.with_name('webhook.php.before_callback_handler_' + stamp)
shutil.copy2(str(WEBHOOK), str(backup))

handler_backup = None
if HANDLER.exists():
    handler_backup = HANDLER.with_name('CallbackHandler.php.before_refactor_' + stamp)
    shutil.copy2(str(HANDLER), str(handler_backup))

HANDLER.write_text(handler_text)
WEBHOOK.write_text(text)

checks = [HANDLER, WEBHOOK]
failed = False
for path in checks:
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
print('Callback bytes moved: ' + str(len(original_block.encode('utf-8'))))
