<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates Jira-like project codes (prefixes) for organizations.
 *
 * The prefix is derived from the organization name and is globally unique
 * across all organizations. It is 3–10 uppercase characters, always starts
 * with a letter, and is generated once at creation time (immutable afterwards).
 *
 * Base rule (matches the product spec examples "Auchan" -> AUC,
 * "Dev_coding" -> DEV): take the first 3 letters of the transliterated,
 * letters-only name.
 *
 * Collision resolution (agreed with product):
 *   1. Letter variation — keep the first two letters and vary the third,
 *      preferring the initials of subsequent words, then the remaining
 *      letters of the name in order ("Dev_frontend-code" -> DEV taken -> DEF).
 *   2. Numeric suffix — DEV, DEV2, DEV3 … once letter variants are exhausted.
 */
class ProjectCodeGenerator
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 10;

    /** Format for a valid (possibly user-supplied) code: 3–10 chars, letter-led, alphanumeric. */
    public const FORMAT_REGEX = '/^[A-Za-z][A-Za-z0-9]{2,9}$/';

    /**
     * Generate a globally-unique code for the given organization name.
     *
     * @param  array<int, string>  $reserved  Codes already allocated in this batch
     *                                        (used during backfill before they hit the DB).
     */
    public function generate(string $name, array $reserved = []): string
    {
        $reservedSet = [];
        foreach ($reserved as $code) {
            $reservedSet[strtoupper($code)] = true;
        }

        foreach ($this->candidates($name) as $candidate) {
            if (! isset($reservedSet[$candidate]) && ! $this->taken($candidate)) {
                return $candidate;
            }
        }

        return $this->randomFallback($reservedSet);
    }

    /**
     * Ordered stream of code candidates: base, then letter variations, then numeric suffixes.
     *
     * @return \Generator<int, string>
     */
    public function candidates(string $name): \Generator
    {
        $ascii = Str::ascii($name);
        preg_match_all('/[A-Za-z]+/', $ascii, $matches);
        $words = $matches[0];
        $lettersOnly = strtoupper(implode('', $words));

        $base = $this->buildBase($lettersOnly);
        yield $base;

        if (strlen($base) >= 2) {
            $prefix = substr($base, 0, 2);
            foreach ($this->variationLetters($words, $lettersOnly, $base) as $letter) {
                yield $prefix.$letter;
            }
        }

        for ($i = 2; $i <= 999; $i++) {
            $suffix = (string) $i;
            $room = self::MAX_LENGTH - strlen($suffix);
            yield substr($base, 0, max(1, $room)).$suffix;
        }
    }

    /** First 3 letters of the name, padded to the minimum and capped at the maximum length. */
    private function buildBase(string $lettersOnly): string
    {
        $base = substr($lettersOnly, 0, self::MIN_LENGTH);

        if ($base === '') {
            $base = 'ORG';
        }

        while (strlen($base) < self::MIN_LENGTH) {
            $base .= 'X';
        }

        return substr($base, 0, self::MAX_LENGTH);
    }

    /**
     * Third-character candidates for letter variation, in priority order:
     * initials of subsequent words first, then all remaining letters of the name.
     *
     * @param  array<int, string>  $words
     * @return array<int, string>
     */
    private function variationLetters(array $words, string $lettersOnly, string $base): array
    {
        $priority = [];

        foreach (array_slice($words, 1) as $word) {
            $priority[] = strtoupper($word[0]);
        }

        foreach (str_split($lettersOnly) as $letter) {
            $priority[] = $letter;
        }

        // Skip the base's own third character so we don't re-yield the base itself.
        $seen = [strlen($base) >= 3 ? $base[2] : '' => true];
        $out = [];

        foreach ($priority as $letter) {
            if (! isset($seen[$letter])) {
                $seen[$letter] = true;
                $out[] = $letter;
            }
        }

        return $out;
    }

    private function taken(string $code): bool
    {
        return DB::table('organizations')->where('code', $code)->exists();
    }

    /** Last-resort random code — effectively never reached given the candidate stream above. */
    private function randomFallback(array $reservedSet): string
    {
        for ($i = 0; $i < 1000; $i++) {
            $code = preg_replace('/[^A-Z]/', 'X', strtoupper(Str::random(self::MIN_LENGTH)));

            if (! isset($reservedSet[$code]) && ! $this->taken($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique organization code.');
    }
}
