#!/usr/bin/env python3
from pathlib import Path
from datetime import datetime
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
p = root / 'handlers' / 'AiMessageHandler.php'
s = p.read_text()

start_marker = "                // FULL FIX1: дата может быть частью первого длинного запроса.\n"
end_marker = "                @file_put_contents(\n                    __DIR__.'/ai_debug.log',\n                    \"ROUTE AFTER AI: APPLY_PARAMETERS\\n\",\n"

start = s.find(start_marker)
if start < 0:
    raise RuntimeError('start marker not found')
end = s.find(end_marker, start)
if end < 0:
    raise RuntimeError('end marker not found')

replacement = '''                // Даты из текста пользователя разбираются единым DateParser через AiDateHandler.\n                // Это одновременно служит DATE GUARD: если пользователь явно назвал месяц,\n                // не принимаем случайную AI-дату из другого месяца.\n                $resolvedUserDate = AiDateHandler::rememberMonthFromText($chat_id, $userText);\n\n                if (!empty($resolvedUserDate['date'])) {\n                    $params['date'] = $resolvedUserDate['date'];\n                } elseif (!empty($resolvedUserDate['month'])) {\n                    // Назван месяц, но точный день/период не определён — спрашиваем уточнение.\n                    $params['date'] = null;\n                } elseif (!empty($params['date'])) {\n                    // AI-дата допустима, если пользователь не назвал противоречащий ей месяц.\n                    AiDateHandler::clear($chat_id);\n                }\n\n'''

backup = p.with_name(p.name + '.before_inline_date_refactor_' + datetime.now().strftime('%Y%m%d_%H%M%S'))
backup.write_text(s)
new_s = s[:start] + replacement + s[end:]
p.write_text(new_s)

result = subprocess.call(['php', '-l', str(p)])
if result != 0:
    p.write_text(s)
    print('ROLLBACK: syntax check failed')
    sys.exit(result or 1)

print('REFACTOR_OK')
print('Backup: ' + str(backup))
print('Changed: AiMessageHandler now uses DateParser/AiDateHandler for all inline month/date guarding.')
