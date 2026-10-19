<?php

namespace App\Services;

/**
 * Reader for the UFOAnalyzer analysis file of a capture (the `A.xml` suffix).
 *
 * The file is written by UFOAnalyzer and looks like this:
 *
 *   <ufoanalyzer_record version="200" clip_name="M20190712_091431_TLP_5" ...>
 *     <ua2_objects>
 *       <ua2_object fs="61" fe="70" sec="0.150150" av="5.019607"
 *                   mag="-1.838501" class="J8_PPH" ...>
 *
 * The capture data lives in the attributes of the first `ua2_object` element;
 * `class` is the classification UFOAnalyzer assigned to the trail.
 *
 * The file is read in bounded chunks instead of being parsed as a whole
 * document: an analysis can carry a long trajectory (hundreds of kilobytes)
 * and the index build must stay flat in memory across millions of captures.
 */
class AnalyzeReader
{
    /**
     * Fields read from the `ua2_object` element.
     *
     * `class` is the trail classification; the others are the astronomical
     * parameters UFOAnalyzer computed for it.
     */
    public const FIELDS = ['class', 'fs', 'fe', 'sec', 'av', 'mag'];

    /** Fields returned as a number, null when the value is not numeric. */
    private const NUMERIC_FIELDS = ['fs', 'fe', 'sec', 'av', 'mag'];

    /** Bytes read from the head of the file before falling back to streaming. */
    private const HEAD_BYTES = 16384;

    /** Bytes read per chunk while streaming through the file. */
    private const CHUNK_BYTES = 65536;

    /**
     * Bytes kept between chunks so a tag split across two reads is still seen.
     */
    private const OVERLAP_BYTES = 4096;

    /**
     * Whether an analysis carries at least one of the read fields.
     *
     * A file with an analysed object that records none of them is not an
     * analysis the listing can show, so the capture stays "not analysed".
     *
     * @param  array<string,mixed>|null  $analysis
     */
    public static function carriesData(?array $analysis): bool
    {
        if ($analysis === null) {
            return false;
        }

        foreach ($analysis as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the analysis data of a capture.
     *
     * Returns null when the file is missing, unreadable or carries no
     * analysis, so a broken file never breaks an index build.
     *
     * @return array{class:?string,fs:?float,fe:?float,sec:?float,av:?float,mag:?float}|null
     */
    public function read(string $path): ?array
    {
        $attributes = $this->readObjectAttributes($path);

        if ($attributes === null) {
            return null;
        }

        $data = [];

        foreach (self::FIELDS as $field) {
            $value = $attributes[$field] ?? null;

            if ($value === null || $value === '') {
                $data[$field] = null;

                continue;
            }

            $data[$field] = in_array($field, self::NUMERIC_FIELDS, true)
                ? $this->toNumber($value)
                : $this->toUtf8($value);
        }

        return $data;
    }

    /**
     * Attributes of the first `ua2_object` element of an analysis file.
     *
     * The element is looked up anywhere in the document, so both the current
     * `ufoanalyzer_record` root and the older `UA` root are understood.
     *
     * @return array<string,string>|null
     */
    protected function readObjectAttributes(string $path): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $buffer = '';

            $head = fread($handle, self::HEAD_BYTES);

            if (!is_string($head) || $head === '') {
                return null;
            }

            $buffer .= $head;

            while (true) {
                if (($attributes = $this->matchObjectTag($buffer)) !== null) {
                    return $attributes;
                }

                if (feof($handle)) {
                    return null;
                }

                $chunk = fread($handle, self::CHUNK_BYTES);

                if (!is_string($chunk) || $chunk === '') {
                    return null;
                }

                // Only the tail of the buffer can hold a tag that was cut in
                // half by the previous read, so the rest is dropped before the
                // next chunk is appended. A tag longer than the overlap would
                // be missed, but no real one comes close.
                if (strlen($buffer) > self::OVERLAP_BYTES) {
                    $buffer = substr($buffer, -self::OVERLAP_BYTES);
                }

                $buffer .= $chunk;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extract the attributes of the first `ua2_object` tag found in a buffer.
     *
     * @return array<string,string>|null
     */
    protected function matchObjectTag(string $buffer): ?array
    {
        // `<ua2_objects` is the container and must not match: the word boundary
        // after `ua2_object` rules it out.
        if (preg_match('/<ua2_object\b([^>]*)>/i', $buffer, $matches) !== 1) {
            return null;
        }

        $attributes = [];

        // Both quote styles are accepted, and the attribute name is matched
        // case-insensitively so `Class` is found as well.
        if (preg_match_all(
            '/([a-z_][a-z0-9_]*)\s*=\s*(["\'])(.*?)\2/is',
            $matches[1],
            $found,
            PREG_SET_ORDER
        ) === false) {
            return null;
        }

        foreach ($found as $attribute) {
            $attributes[strtolower($attribute[1])] = $attribute[3];
        }

        return $attributes;
    }

    /**
     * Coerce an attribute value into a number, or null when it is not one.
     */
    protected function toNumber(string $value): ?float
    {
        $value = trim($value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Coerce a string read from XML into valid UTF-8.
     *
     * Analysis files are not guaranteed to be UTF-8; a Latin-1 value must be
     * converted rather than dropped, or json_encode() would fail on the
     * capture record and the whole index build would be lost.
     */
    protected function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
