<?php

namespace App\Services;

use App\Support\Str;

class FilenameParser
{
    // Optimized pattern: use possessive quantifiers and avoid catastrophic backtracking
    // Pattern breakdown:
    // ^M                    - literal M at start
    // (?P<date>\d{8})       - exactly 8 digits for date
    // _                     - literal underscore
    // (?P<time>\d{6})       - exactly 6 digits for time
    // _                     - literal underscore
    // (?P<station>[^_]+)    - station name (no underscore)
    // _                     - literal underscore
    // (?P<type>[^.]+)       - type (no dot)
    // \.                    - literal dot
    // (?P<ext>[^.]+)        - extension (no dot, more efficient than .+)
    // $                     - end of string
    public const PATTERN = '/^M(?P<date>\d{8})_(?P<time>\d{6})_(?P<station>[^_]+)_(?P<type>[^.]+)\.(?P<ext>[^.]+)$/i';

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
     */
    public static function parse(string $filename): ?array
    {
        // Quick length check to avoid regex on obviously invalid filenames
        if (strlen($filename) < 20 || $filename[0] !== 'M' && $filename[0] !== 'm') {
            return null;
        }

        if (!preg_match(self::PATTERN, $filename, $matches)) {
            return null;
        }

        $ext = strtolower($matches['ext']);
        $typeRaw = $matches['type'];

        // Determine file type based on suffix
        // Pattern: 1P = preview, 1T = thumbnail, 1A = analyze, 1 = metadata
        if (Str::endsWith($typeRaw, ['T', 't'])) {
            $fileType = 'thumbnail';
        } elseif (Str::endsWith($typeRaw, ['P', 'p'])) {
            $fileType = 'preview';
        } elseif (Str::endsWith($typeRaw, ['A', 'a'])) {
            $fileType = 'analyze';
        } else {
            $fileType = self::FILE_TYPES[$ext] ?? 'unknown';
        }

        return [
            'date' => $matches['date'],
            'time' => $matches['time'],
            'station' => $matches['station'],
            'type' => $fileType,
            'extension' => $ext,
        ];
    }
}