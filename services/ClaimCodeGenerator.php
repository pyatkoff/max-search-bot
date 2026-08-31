<?php

declare(strict_types=1);

/**
 * Native claim code generator independent from Bitrix global helpers.
 */
final class ClaimCodeGenerator
{
    private const ALPHABET = 'abcdefghijklnmopqrstuvwxyz0123456789';

    public static function generate(int $length = 10): string
    {
        if ($length <= 0) {
            throw new InvalidArgumentException('Claim code length must be positive');
        }

        $alphabetLength = strlen(self::ALPHABET);
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
