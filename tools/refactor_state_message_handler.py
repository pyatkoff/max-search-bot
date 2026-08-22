#!/usr/bin/env python3
from __future__ import print_function

from pathlib import Path
import shutil
import subprocess
import sys
from datetime import datetime

ROOT = Path(__file__).resolve().parent.parent
WEBHOOK = ROOT / 'webhook.php'
HANDLER = ROOT / 'handlers' / 'StateMessageHandler.php'

text = WEBHOOK.read_text()

start_marker = "            elseif($status==MaxSearchApi::$statusCityChoose)"
end_marker = "\n\t\t}\n\n\t\t//MaxSearchApi::showCalendarButtons"

start = text.find(start_marker)
if start < 0:
    raise RuntimeError('state handler start marker not found')

end = text.find(end_marker, start)
if end < 0:
    raise RuntimeError('state handler end marker not found')

original_block = text[start:end]

# Inside the standalone handler the first branch must start with `if`, not `elseif`.
method_body = original_block.replace(
    "elseif($status==MaxSearchApi::$statusCityChoose)",
    "if($status==MaxSearchApi::$statusCityChoose)",
    1
)

handler_text = (
    "<?php\n\n"
    "class StateMessageHandler\n"
    "{\n"
    "    public static function handle($message, $chat_id, $status)\n"
    "    {\n"
    + method_body +
    "\n    }\n"
    "}\n"
)

replacement = (
    "            else\n"
    "            {\n"
    "                StateMessageHandler::handle($message, $chat_id, $status);\n"
    "            }"
)

# Replace using positions from the untouched webhook first.
new_text = text[:start] + replacement + text[end:]

# Then add the require; doing this after positional replacement avoids offset bugs.
require_line = "require_once(__DIR__ . '/handlers/StateMessageHandler.php');\n"
anchor = "require_once(__DIR__ . '/handlers/CallbackHandler.php');\n"
if require_line not in new_text:
    if anchor not in new_text:
        raise RuntimeError('require anchor not found')
    new_text = new_text.replace(anchor, anchor + require_line, 1)

stamp = datetime.now().strftime('%Y%m%d_%H%M%S')
backup = WEBHOOK.with_name('webhook.php.before_state_handler_' + stamp)
shutil.copy2(str(WEBHOOK), str(backup))

handler_backup = None
if HANDLER.exists():
    handler_backup = HANDLER.with_name('StateMessageHandler.php.before_refactor_' + stamp)
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
print('State message bytes moved: ' + str(len(original_block.encode('utf-8'))))
