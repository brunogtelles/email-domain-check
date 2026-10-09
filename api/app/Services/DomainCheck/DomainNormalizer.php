<?php

namespace App\Services\DomainCheck;

/**
 * Turns user input (domain, e-mail address, URL...) into a bare lowercase domain.
 */
final class DomainNormalizer
{
    private const DOMAIN_PATTERN = '/^(?:(?!-)[a-z0-9_-]{1,63}(?<!-)\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /**
     * Return the domain for the given input, or null when it is not a valid domain / e-mail.
     *
     * Examples: "John <john@Example.com>" => "example.com", "https://www.example.com/path" => "www.example.com".
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = (string) preg_replace('/^mailto:/i', '', $value);

        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }

        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);
        $value = preg_split('#[/?\#\s<>,;]#', $value)[0];
        $value = (string) preg_replace('/:\d+$/', '', $value);
        $value = rtrim(mb_strtolower($value), '.');

        if ($value === '') {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false) {
                return null;
            }

            $value = $ascii;
        }

        if (strlen($value) > 253 || preg_match(self::DOMAIN_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }
}
