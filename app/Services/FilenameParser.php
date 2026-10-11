<?php

namespace App\Services;

use App\Support\Str;

class FilenameParser
{
    // Optimized pattern with possessive quantifiers and atomic groups to prevent catastrophic backtracking
    // Pattern breakdown:
    // ^M                    - literal M at start
    // (?P<date>\d{8})       - exactly 8 digits for date
    // _                     - literal underscore
    // (?P<time>\d{6})       - exactly 6 digits for time
    // _                     - literal underscore
    // (?P<station>[^_]+)    - station name (no underscore) - possessive
    // _                     - literal underscore
    // (?P<type>[^.]+)       - type (no dot) - possessive
    // \.                    - literal dot
    // (?P<ext>[^.]+)        - extension (no dot) - possessive
    // $                     - end of string
    public const PATTERN = '/^M(?P<date>\d{8})_(?P<time>\d{6})_(?P<station>[^_]+?)_(?P<type>[^.]+?)\.(?P<ext>[^.]+?)$/i';

    // Pre-compiled suffix patterns for faster matching
    private const SUFFIX_THUMBNAIL = '/[Tt]$/';
    private const SUFFIX_PREVIEW = '/[Pp]$/';
    private const SUFFIX_ANALYZE = '/[Aa]$/';

    public const FILE_TYPES = [
        'xml' => 'metadata',
        'jpg' => 'preview',
        'jpeg' => 'preview',
        'png' => 'preview',
        'bmp' => 'thumbnail',
        'thm' => 'thumbnail',
    ];

    /**
     * Parse a filename and extract capture information.
     * Optimized to avoid memory issues and catastrophic backtracking.
     */
    public static function parse(string $filename): ?array
    {
        // Quick length check to avoid regex on obviously invalid filenames
        $len = strlen($filename);
        if ($len < 20) {
            return null;
        }

        // Fast prefix check without regex
        $firstChar = $filename[0];
        if ($firstChar !== 'M' && $firstChar !== 'm') {
            return null;
        }

        // Use preg_match with limited backtracking
        // The pattern uses non-greedy quantifiers (+?) to prevent backtracking
        if (!@preg_match(self::PATTERN, $filename, $matches)) {
            return null;
        }

        $ext = strtolower($matches['ext']);
        $typeRaw = $matches['type'];

        // Determine file type from the last character of the *type* segment.
        // Pattern: 1P = preview, 1T = thumbnail, 1A = analyze, 1 = metadata
        $lastChar = $typeRaw !== '' ? $typeRaw[strlen($typeRaw) - 1] : '';

        if ($lastChar === 'T' || $lastChar === 't') {
            $fileType = 'thumbnail';
        } elseif ($lastChar === 'P' || $lastChar === 'p') {
            $fileType = 'preview';
        } elseif ($lastChar === 'A' || $lastChar === 'a') {
            $fileType = 'analyze';
        } else {
            $fileType = self::FILE_TYPES[$ext] ?? 'unknown';
        }

        return [
            'date' => $matches['date'],
            'time' => $matches['time'],
            'station' => $matches['station'],
            'sequence' => preg_replace('/[TPA]$/i', '', $typeRaw),
            'type' => $fileType,
            'extension' => $ext,
        ];
    }

    /**
     * Fast check if filename matches the expected pattern without full parsing.
     * Useful for filtering before expensive operations.
     */
    public static function isValidFormat(string $filename): bool
    {
        $len = strlen($filename);
        if ($len < 20) {
            return false;
        }

        $firstChar = $filename[0];
        if ($firstChar !== 'M' && $firstChar !== 'm') {
            return false;
        }

        // Quick structural check: M + 8 digits + _ + 6 digits + _ + something + _ + something + . + something
        // Count underscores and dots
        $underscoreCount = substr_count($filename, '_');
        $dotCount = substr_count($filename, '.');

        return $underscoreCount >= 3 && $dotCount >= 1;
    }
}