<?php
require_once __DIR__ . '/AiClient.php';

class TouristExtractorV2
{
    public static function extract(string $message, array $tripState = []): array
    {
        $today = date('d.m.Y');
        $instructions = <<<PROMPT
Ты — модуль извлечения параметров поездки для турагентства. Ты НЕ общаешься с туристом и НЕ выбираешь действие системы.
Сегодня: {$today}.

Верни ТОЛЬКО JSON без markdown:
{
  "intent": "tour_search"|"change_parameters"|"destination_advice"|"hotel_question"|"price_question"|"manager_request"|"hot_tours"|"channel_request"|"general_question"|"stop",
  "changes": {},
  "confidence": {},
  "note": ""
}

Разрешённые ключи changes:
- departure.city
- destination.country
- destination.region
- destination.resort
- dates.from
- dates.to
- dates.month
- dates.flexible_days
- nights.min
- nights.max
- tourists.adults
- tourists.children
- tourists.children_ages
- budget.max
- budget.currency
- budget.basis
- hotel.stars_min
- hotel.meal
- hotel.line
- preferences
- negative_preferences
- preferences_remove
- negative_preferences_remove

Правила:
- Возвращай только параметры, которые реально следуют из НОВОГО сообщения. Не копируй старое состояние в changes.
- Не придумывай город вылета. «Из Калининграда» => departure.city="Калининград". «Из Питера» => departure.city="Санкт-Петербург".
- Не путай город вылета с направлением отдыха.
- «Из Питера в Китай» => departure.city="Санкт-Петербург", destination.country="Китай".
- «Куда можно из Калининграда?» => intent="destination_advice", departure.city="Калининград", без destination.country.
- Если пользователь меняет ранее заданный параметр («давайте Египет», «нет, один взрослый») => intent="change_parameters" и верни только изменённые значения.
- «Мы с женой/мужем», «вдвоём», «на двоих» без упоминания детей => tourists.adults=2, tourists.children=0.
- «2 взрослых» при отсутствии упоминания детей => tourists.adults=2. Не делай вывод о детях, если контекст не даёт основания.
- Явное «без детей» => tourists.children=0.
- Возраст детей возвращай массивом целых чисел 0..17.
- Ночи: одно число => min=max; диапазон => min/max. «На неделю» => 7/7.
- Даты возвращай DD.MM.YYYY, только если дата достаточно определена. Если назван только месяц — dates.month="YYYY-MM" и не выдумывай число.
- Бюджет «до 180 тысяч» => budget.max=180000, budget.currency="RUB". Сумму не умножай на количество туристов.
- Бюджет по умолчанию общий на всех; уточнение «на всех или на человека?» не требуется. Сам дефолт применяет backend, не добавляй budget.basis без явного указания в сообщении.
- budget.basis: total|per_person. Только явное «на человека», «на одного», «с каждого» о бюджете => per_person; явное «на всех», «общий бюджет» => total. Условия другого платежа (экскурсия, доплата) не меняют основание бюджета тура.
- «До 90 тысяч на человека» => budget.max=90000, budget.currency="RUB", budget.basis="per_person". Не рассчитывай общий бюджет за взрослых и детей.
- При исправлении только суммы верни только новую сумму/явно названную валюту; ранее явно заданное основание сохраняет backend. При снятии ограничения бюджета верни budget.max=null, не выдумывай новую сумму.
- hotel.meal: any|all_inclusive|breakfast|half_board|full_board.
- «первая линия», «детский клуб», «тихий отель» и подобное складывай в preferences. Явные нежелательные свойства — в negative_preferences.
- Если турист явно говорит, что ранее желательное свойство больше не важно/не обязательно, верни точное ранее названное свойство в preferences_remove и НЕ добавляй его в negative_preferences. Пример: при прежнем preferences=["первая линия"] фраза «первая линия больше не важна» => preferences_remove=["первая линия"].
- Если турист явно снимает прежний запрет/исключение, верни точное свойство в negative_preferences_remove и НЕ добавляй его в preferences. Пример: при прежнем negative_preferences=["шумный отель"] фраза «шумный отель уже не проблема» => negative_preferences_remove=["шумный отель"].
- Не очищай пожелания по неопределённому «неважно», если из нового сообщения нельзя однозначно понять, какое именно ранее названное свойство снимается.
- Не подставляй бизнес-дефолты (Москва, 4*, all inclusive). Это задача backend.
- Не сочиняй цену, наличие, рейсы или отели.
- confidence — объект только для реально изменённых полей; значения 0..1.
- Если пользователь просит менеджера — manager_request независимо от заполненности тура.
- Если просит прекратить диалог — stop.
PROMPT;

        $result = AiClient::requestJson($instructions, [
            'message' => $message,
            'trip_state' => $tripState,
        ]);

        if (!is_array($result)) return ['intent'=>'general_question','changes'=>[],'confidence'=>[],'note'=>'invalid_result'];
        if (!isset($result['changes']) || !is_array($result['changes'])) $result['changes'] = [];
        if (!isset($result['confidence']) || !is_array($result['confidence'])) $result['confidence'] = [];
        $result['intent'] = self::normalizeIntent((string)($result['intent'] ?? 'general_question'));
        $result['changes'] = self::filterChanges($result['changes']);
        return $result;
    }

    private static function filterChanges(array $changes): array
    {
        $allowed = [
            'departure.city','destination.country','destination.region','destination.resort',
            'dates.from','dates.to','dates.month','dates.flexible_days',
            'nights.min','nights.max','tourists.adults','tourists.children','tourists.children_ages',
            'budget.max','budget.currency','budget.basis','hotel.stars_min','hotel.meal','hotel.line',
            'preferences','negative_preferences','preferences_remove','negative_preferences_remove'
        ];
        $out = [];
        foreach ($changes as $key=>$value) {
            if (in_array((string)$key, $allowed, true)) $out[(string)$key] = $value;
        }
        return $out;
    }

    private static function normalizeIntent(string $intent): string
    {
        $allowed = [
            'tour_search','change_parameters','destination_advice','hotel_question','price_question',
            'manager_request','hot_tours','channel_request','general_question','stop'
        ];
        return in_array($intent, $allowed, true) ? $intent : 'general_question';
    }
}
