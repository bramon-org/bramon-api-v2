<?php

namespace App\Support;

class Str
{
    /**
     * Check if string ends with any of the given values.
     */
    public static function endsWith(string $haystack, array|string $needles): bool
    {
        $needles = is_array($needles) ? $needles : [$needles];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string starts with any of the given values.
     */
    public static function startsWith(string $haystack, array|string $needles): bool
    {
        $needles = is_array($needles) ? $needles : [$needles];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}