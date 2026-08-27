<?php

/**
 * Conservative deterministic destination hints for well-known resorts/regions.
 * Only seeds a country when neither AI nor current dialogue state already has one.
 */
class DestinationHintService
{
    public static function countryFromText(string $text): string
    {
        $patterns = [
            'Египет' => [
                '/\bхургада\b/ui',
                '/\bэль[-\s]?(?:кусейр|кусеир|каусер|кузейр)\b/ui',
                '/\bмарса[-\s]?алам\b/ui',
                '/\bшарм(?:[-\s]эль[-\s]шейх)?\b/ui',
            ],
            'Турция' => [
                '/\bалан(?:ь|и)я\b/ui',
                '/\bанталья\b/ui',
                '/\bкемер\b/ui',
                '/\bбелек\b/ui',
                '/\bсид[еэ]\b/ui',
            ],
            'ОАЭ' => [
                '/\bдубай\b/ui',
                '/\bрас[-\s]эль[-\s]хайм[ае]\b/ui',
                '/\bфуджейр[ае]\b/ui',
                '/\bшардж[ае]\b/ui',
            ],
            'Таиланд' => [
                '/\bпхукет\b/ui',
                '/\bпаттай[яе]\b/ui',
                '/\bкао[-\s]?лак\b/ui',
            ],
        ];

        foreach ($patterns as $country => $countryPatterns) {
            foreach ($countryPatterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return $country;
                }
            }
        }

        return '';
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
