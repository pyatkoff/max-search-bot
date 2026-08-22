#!/usr/bin/env python3
from pathlib import Path
import shutil
import subprocess
import sys
from datetime import datetime

ROOT = Path(__file__).resolve().parents[1]
WEBHOOK = ROOT / 'webhook.php'


def replace_between(text, start_marker, end_marker, replacement):
    start = text.find(start_marker)
    if start < 0:
        raise RuntimeError('start marker not found: {}'.format(start_marker[:80]))
    end = text.find(end_marker, start)
    if end < 0:
        raise RuntimeError('end marker not found: {}'.format(end_marker[:80]))
    return text[:start] + replacement + text[end:]


def main():
    if not WEBHOOK.exists():
        print('ERROR: {} not found'.format(WEBHOOK))
        return 1

    original = WEBHOOK.read_text()
    s = original

    require_marker = "require_once(__DIR__ . '/ai/AiRouter.php');\n"
    require_line = "require_once(__DIR__ . '/handlers/AiDateHandler.php');\n"
    if require_line not in s:
        if require_marker not in s:
            raise RuntimeError('AiRouter require marker not found')
        s = s.replace(require_marker, require_marker + require_line, 1)

    # Remove legacy pending-month helper functions from webhook.php.
    helper_start = 'function maxPendingMonthFile($chatId) {'
    helper_end = 'function maxUserAsTelegramLike(array $user) {'
    if helper_start in s:
        s = replace_between(s, helper_start, helper_end, helper_end)

    # All remaining clears go through the dedicated handler/store.
    s = s.replace('maxClearPendingMonth($chat_id);', 'AiDateHandler::clear($chat_id);')

    # Replace the large short-answer date block with one service call.
    short_start = '''                // Если месяц уже был назван предыдущим сообщением,\n                // понимаем короткий ответ на уточнение даты без AI:\n                // "начало", "середина", "конец", "14", "14 числа".\n'''
    short_end = '''                // Если сейчас не хватает только возраста детей, короткий ответ\n'''
    short_replacement = '''                // Короткий ответ на ранее названный месяц: "начало", "середина", "конец", "14".\n                if (in_array('date', $missingNow, true)) {\n                    $shortDateValue = AiDateHandler::resolvePendingShortDate($chat_id, $userText);\n\n                    if ($shortDateValue !== '') {\n                        MaxSearchApi::saveLastValue(\n                            $chat_id,\n                            MaxSearchApi::$statusDate,\n                            $shortDateValue\n                        );\n\n                        $missingAfterDate = MaxSearchApi::getAiMissingFields($chat_id);\n\n                        if (empty($missingAfterDate)) {\n                            MaxSearchApi::showCheckButtons($chat_id);\n                        } else {\n                            $dateFallback = [\n                                'city'=>'Из какого города планируете вылет?',\n                                'country'=>'Куда хотите поехать?',\n                                'adults'=>'Сколько будет взрослых туристов?',\n                                'children'=>'Будут дети? Если да — сколько?',\n                                'child_ages'=>'Сколько лет детям?',\n                                'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',\n                                'meal'=>'Какое питание предпочитаете?',\n                                'nights'=>'На сколько ночей планируете поездку?',\n                                'date'=>'Какая ориентировочная дата вылета?'\n                            ];\n\n                            MaxSearchApi::setStatus($chat_id, MaxSearchApi::$statusAi);\n                            MaxSearchApi::MaxSend(\n                                $dateFallback[$missingAfterDate[0]] ?? 'Уточните, пожалуйста, параметры поездки.',\n                                $chat_id\n                            );\n                        }\n\n                        return;\n                    }\n                }\n\n'''
    if short_start in s:
        s = replace_between(s, short_start, short_end, short_replacement + short_end)

    # Replace local month/date parser with DateParser + PendingMonthStore via AiDateHandler.
    local_start = '''                    // Дата для коротких сообщений.\n'''
    local_end = '''                    if (!empty($localParams)) {\n'''
    local_replacement = '''                    // Дата для коротких сообщений вынесена в отдельный обработчик.\n                    $localDateResolved = AiDateHandler::rememberMonthFromText($chat_id, $userText);\n                    $localMonthOnly = !empty($localDateResolved['month']) && empty($localDateResolved['date']);\n                    if (!empty($localDateResolved['date'])) {\n                        $localParams['date'] = $localDateResolved['date'];\n                    }\n\n'''
    if local_start in s:
        s = replace_between(s, local_start, local_end, local_replacement + local_end)

    if s == original:
        print('NO_CHANGES')
        return 0

    backup = WEBHOOK.with_name('webhook.php.before_date_modules_{}'.format(datetime.now().strftime('%Y%m%d_%H%M%S')))
    shutil.copy2(str(WEBHOOK), str(backup))
    WEBHOOK.write_text(s)

    lint = subprocess.Popen(
        ['php', '-l', str(WEBHOOK)],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT
    )
    output = lint.communicate()[0]
    if not isinstance(output, str):
        output = output.decode('utf-8', 'replace')
    print(output.strip())

    if lint.returncode != 0:
        shutil.copy2(str(backup), str(WEBHOOK))
        print('ROLLBACK: syntax check failed')
        return lint.returncode

    print('REFACTOR_OK')
    print('Backup: {}'.format(backup))
    print('Changed: webhook.php now uses handlers/AiDateHandler.php for pending month and local/short date parsing.')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as e:
        print('ERROR: {}'.format(e))
        raise
