<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

/**
 * Decides whether one overlay field needs a translation.
 *
 * A field is translated when the German source has text and one of these is true:
 * - the overlay value is still empty
 * - the overlay still holds the German source and no snapshot exists yet
 * - the snapshot of the last translation differs from the current German source
 *
 * Already translated values are left alone when the snapshot is missing. The
 * caller stores a snapshot afterwards so the next run can see German edits.
 */
final class OverlayFieldDiff
{
    public const REASON_EMPTY = 'empty';

    public const REASON_STILL_GERMAN = 'still-german';

    public const REASON_SOURCE_CHANGED = 'source-changed';

    /**
     * @param array<string, mixed> $snapshot Decoded l10n_diffsource / l18n_diffsource object
     */
    public static function reason(string $sourceValue, mixed $overlayValue, array $snapshot, string $field): ?string
    {
        if ($sourceValue === '') {
            return null;
        }

        $overlay = self::stringify($overlayValue);
        if ($overlay === '' || $overlay === '0') {
            return self::REASON_EMPTY;
        }

        if (self::hasScalarSnapshot($snapshot, $field)) {
            if ((string) $snapshot[$field] !== $sourceValue) {
                return self::REASON_SOURCE_CHANGED;
            }

            return null;
        }

        if ($overlay === $sourceValue) {
            return self::REASON_STILL_GERMAN;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $sourceFields
     *
     * @return array{snapshot: array<string, mixed>, changed: bool}
     */
    public static function withSourceValues(array $snapshot, array $sourceFields): array
    {
        $changed = false;
        foreach ($sourceFields as $field => $value) {
            $stringValue = (string) $value;
            if (!array_key_exists($field, $snapshot) || (string) $snapshot[$field] !== $stringValue) {
                $snapshot[$field] = $stringValue;
                $changed = true;
            }
        }

        return ['snapshot' => $snapshot, 'changed' => $changed];
    }

    public static function characters(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function hasScalarSnapshot(array $snapshot, string $field): bool
    {
        if (!array_key_exists($field, $snapshot)) {
            return false;
        }

        return is_scalar($snapshot[$field]) || $snapshot[$field] === null;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
