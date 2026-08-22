FULL FIX4 — ANALYTICS ONLY
Основа: рабочий FULL FIX3. Логика подбора не менялась.

Новые/контролируемые стадии funnel:
bot_started
start_search
ai_start
country_selected
tourists_selected (adults / children)
search_ready
show_tours
site_open
manager_request
phone_received
followup_sent
tours_found
channel_click

YCLID:
в новом funnel.csv колонка YclidText.
Значение записывается как текст с ведущим апострофом:
'4422580153025036287
Это защищает длинный yclid от округления Excel/Sheets.
На yclid, который отправляется в Метрику, изменение НЕ влияет.

ПОСЛЕ УСТАНОВКИ один раз открой:
https://anytour.online/max-search/rotate_funnel_fix4.php
Старый funnel.csv будет сохранён как funnel_before_fix4_YYYYMMDD_HHMMSS.csv.
Новый funnel.csv создастся автоматически при следующем событии.
После успешного запуска rotate_funnel_fix4.php его можно удалить с сервера.

config.php не менять.
