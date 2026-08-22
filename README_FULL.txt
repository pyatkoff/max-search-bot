MAX SEARCH — FULL PRODUCTION TEST

База: подтверждённый рабочий STEP 4E.

В комплект возвращены:
- весь STEP 4E: payload, auto-AI, Москва, вдвоём=без детей, Турция/Египет defaults,
  короткие исправления, "неделю", человеческие даты, "дорого";
- consultant-v2 AiRouter (intent/question/correction/stop), без замены рабочего стартового маршрута;
- менеджер + request_contact + ручной телефон (они уже были в стабильном классе);
- цель max_manager_request;
- цель max_phone после успешного создания лида;
- цель max_show_tours при реальном открытии сайта;
- follow-up после открытия сайта;
- предложение MAX-канала с yclid/region/campaign;
- tracking клика в канал;
- funnel.csv: bot_started, ai_text, search_ready, show_tours, site_open,
  manager_request, phone_received, followup_sent, tours_found, channel_click;
- funnel_report.php.

ВАЖНО:
config.php НЕ входит в архив и не заменяется.

Заменить файлы:
webhook.php
maxsearchbaseclass.php
maxsearchclass.php
ai/AiRouter.php
ai/AiClient.php
open_tours.php
open_channel.php
cron_followup.php
funnel_report.php

Контрольный откат: max-search-step4e-human-dates.zip + совместимые open-файлы.

Проверка:
1 /start
2 "Египет на двоих в октябре на неделю"
3 "в начале октября" или "ближайшая"
4 Показать туры -> сайт открывается
5 cron_followup.php -> follow-up
6 Нужна помощь менеджера -> запрос телефона
7 отправить телефон -> лид + max_phone
8 предложение канала -> переход
9 funnel_report.php
