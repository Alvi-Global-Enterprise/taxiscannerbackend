<?php

declare(strict_types=1);

namespace App\Domain\Taxi\Services;

/**
 * Normalises UK postcodes for coverage matching.
 * Stores/compares primarily on outward code (e.g. M1 from M1 1AE).
 */
final class UkPostcodeNormalizer
{
    public function normalize(?string $postcode): ?string
    {
        if ($postcode === null) {
            return null;
        }

        $cleaned = strtoupper(preg_replace('/\s+/', '', trim($postcode)) ?? '');

        if ($cleaned === '' || ! preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d?[A-Z]{0,2}$/', $cleaned)) {
            // Allow outward-only codes like M1, SW1A
            if (preg_match('/^[A-Z]{1,2}\d[A-Z\d]?$/i', $cleaned)) {
                return strtoupper($cleaned);
            }

            return $cleaned !== '' ? $cleaned : null;
        }

        return $cleaned;
    }

    public function outcode(?string $postcode): ?string
    {
        $normalized = $this->normalize($postcode);

        if ($normalized === null) {
            return null;
        }

        // Full postcode: outward is everything except last 3 inward chars
        if (strlen($normalized) > 3 && preg_match('/^[A-Z]{1,2}\d[A-Z\d]?/', $normalized)) {
            $inward = substr($normalized, -3);
            if (preg_match('/^\d[A-Z]{2}$/', $inward)) {
                return substr($normalized, 0, -3);
            }
        }

        return $normalized;
    }
}
