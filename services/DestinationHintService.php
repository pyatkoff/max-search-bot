<?php

/**
 * Conservative deterministic destination hints for well-known resorts/regions.
 * Only seeds a country when neither AI nor current dialogue state already has one.
 */
class DestinationHintService
{
    // A compound entry carries its existing search separator and the parts of its complete name.
    private const RESORTS = [
        'Египет' => [
            'хургада',
            ['[-\s]?', ['эль', '(?:кусейр|кусеир|каусер|кузейр)']],
            ['[-\s]?', ['марса', 'алам']],
            'шарм',
            ['[-\s]', ['шарм', 'эль', 'шейх']],
        ],
        'Турция' => ['алан(?:ь|и)я', 'анталья', 'кемер', 'белек', 'сид[еэ]'],
        'ОАЭ' => [
            'дубай',
            ['[-\s]', ['рас', 'эль', 'хайм[ае]']],
            'фуджейр[ае]',
            'шардж[ае]',
        ],
        'Таиланд' => ['пхукет', 'паттай[яе]', ['[-\s]?', ['као', 'лак']]],
    ];

    public static function countryFromText(string $text): string
    {
        foreach (self::RESORTS as $country => $names) {
            foreach ($names as $name) {
                $pattern = is_array($name) ? implode($name[0], $name[1]) : $name;
                if (preg_match('~\b'.$pattern.'\b~iu', $text)) {
                    return $country;
                }
            }
        }

        return '';
    }

    /** Exact complete compound names, including the spelling with omitted separators. */
    public static function isStandaloneCompoundResort(string $text): bool
    {
        if (strlen($text) > 128) {
            return false;
        }
        foreach (self::RESORTS as $names) {
            foreach ($names as $name) {
                if (is_array($name) && preg_match('~\A'.implode('[-\h]?', $name[1]).'\z~iuD', $text) === 1) {
                    return true;
                }
            }
        }
        return false;
    }
    public static function seedCountry(array $parameters, string $text, array $current): array
    {
        if (!empty($parameters['country']) || !empty($current['country'])) {
            return $parameters;
        }

        $country = self::countryFromText($text);
        if ($country !== '') {
            $parameters['country'] = $country;
        }

        return $parameters;
    }
}
