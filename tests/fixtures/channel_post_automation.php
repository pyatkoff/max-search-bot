<?php
// Provider-shaped synthetic fixtures. No credentials or customer records.
return [
    'max' => [
        'sender' => ['user_id' => 123, 'name' => 'SMM'],
        'recipient' => ['chat_id' => -80095447388179, 'chat_type' => 'channel'],
        'timestamp' => 1791972294000,
        'url' => 'https://max.ru/c/-80095447388179/AaE53iyDMmw',
        'body' => [
            'mid' => 'mid.ffffb727534f6fed01a139de2c83326c',
            'text' => "  Турция — 7 ночей, 2 взрослых. 150 000 ₽ за всех\n",
            'markup' => [['type' => 'strong', 'from' => 2, 'length' => 6]],
            'attachments' => [
                ['type' => 'image', 'payload' => ['photo_id' => 901, 'token' => 'fixture-private-image-token', 'url' => 'https://cdn.example.test/photo.jpg?signature=fixture']],
                ['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'link', 'text' => 'Подробнее', 'url' => 'https://example.test/tour']]]]],
            ],
        ],
        'link' => ['type' => 'forward', 'mid' => 'mid.original'],
    ],
    'telegram' => [
        'message_id' => 61,
        'date' => 1791972294,
        'sender_chat' => ['id' => -1001234567890, 'type' => 'channel', 'title' => 'AnyTour — test'],
        'chat' => ['id' => -1001234567890, 'type' => 'channel', 'title' => 'AnyTour — test', 'username' => 'anytour_test'],
        'caption' => "Турция — 7 ночей, 2 взрослых. 150 000 ₽ за всех\n",
        'caption_entities' => [['type' => 'bold', 'offset' => 0, 'length' => 6]],
        'photo' => [
            ['file_id' => 'photo-small', 'file_unique_id' => 'photo-unique-small', 'width' => 90, 'height' => 90],
            ['file_id' => 'photo-large', 'file_unique_id' => 'photo-unique-large', 'width' => 900, 'height' => 900],
        ],
        'reply_markup' => ['inline_keyboard' => [[['text' => 'Подробнее', 'url' => 'https://example.test/tour']]]],
    ],
];
