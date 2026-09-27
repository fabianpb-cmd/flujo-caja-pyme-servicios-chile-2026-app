<?php

namespace App\Support\Security;

class CorporateEmailDomain
{
    public const DOMAIN = 'tdatconsulting.cl';

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isAllowed(string $email): bool
    {
        $normalized = self::normalize($email);
        $separator = strrpos($normalized, '@');

        return $separator !== false
            && substr($normalized, $separator + 1) === self::DOMAIN;
    }
}
