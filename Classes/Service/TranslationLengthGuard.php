<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

/**
 * Guards translated strings against a per-target character-length limit.
 *
 * Machine translations frequently expand relative to their source (German runs
 * noticeably longer than English, etc.), which can overflow fixed-size database
 * columns — e.g. an SEO title stored as `varchar(255)` — or break fixed-width
 * UI labels. This guard enforces a maximum length and, when a translation
 * exceeds it, returns a safe fallback: the translation cut to at most the
 * configured number of characters, preferring the last word boundary so words
 * are not split mid-character.
 *
 * Lengths are measured in Unicode characters via {@see mb_strlen()}, so the
 * project's multibyte target languages (Cyrillic for `uk`, Arabic for `ar`)
 * are counted correctly rather than by raw byte length.
 */
final class TranslationLengthGuard
{
    /**
     * Sentinel value meaning "no length limit".
     */
    public const int NO_LIMIT = 0;

    /**
     * Whether $text fits within $maxLength characters. A non-positive
     * $maxLength means "no limit" and always returns true.
     */
    public function withinLimit(string $text, int $maxLength): bool
    {
        if ($maxLength <= self::NO_LIMIT) {
            return true;
        }

        return mb_strlen($text) <= $maxLength;
    }

    /**
     * Returns $text unchanged when it fits within $maxLength characters;
     * otherwise returns a length-safe fallback cut to at most $maxLength
     * characters, preferring the last whole word inside the window. A single
     * word longer than the limit is hard-cut. The result is guaranteed never to
     * exceed $maxLength characters. A non-positive $maxLength disables the limit
     * and returns $text unchanged.
     */
    public function enforce(string $text, int $maxLength): string
    {
        if ($this->withinLimit($text, $maxLength)) {
            return $text;
        }

        $hardCut = rtrim(mb_substr($text, 0, $maxLength));

        $lastSpace = mb_strrpos($hardCut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $wordSafe = rtrim(mb_substr($hardCut, 0, $lastSpace));
            if ($wordSafe !== '') {
                return $wordSafe;
            }
        }

        return $hardCut;
    }
}
