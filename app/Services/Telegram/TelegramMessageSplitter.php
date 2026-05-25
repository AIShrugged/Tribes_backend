<?php

namespace App\Services\Telegram;

class TelegramMessageSplitter
{
    /**
     * Telegram counts UTF-16 code units and has a hard 4096 unit limit.
     * Keep a margin for part headers and formatting edge cases.
     */
    private const TEXT_MAX = 3900;

    /**
     * @return string[]
     */
    public function split(string $text, int $limit = self::TEXT_MAX): array
    {
        $text = trim($text);

        if ($text === '' || $this->utf16Length($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while ($this->utf16Length($remaining) > $limit) {
            $headEnd = $this->codePointPrefixForUtf16Budget($remaining, $limit);
            $head = mb_substr($remaining, 0, $headEnd);

            $cut = $this->lastSeparator($head, ["\n\n", "\n", ' ']);
            if ($cut <= 0) {
                $cut = $headEnd;
            }

            $chunks[] = trim(mb_substr($remaining, 0, $cut));
            $remaining = ltrim(mb_substr($remaining, $cut));
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    private function utf16Length(string $text): int
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return is_string($utf16) ? (int) (strlen($utf16) / 2) : mb_strlen($text);
    }

    private function codePointPrefixForUtf16Budget(string $text, int $budget): int
    {
        $count = mb_strlen($text);
        $units = 0;

        for ($i = 0; $i < $count; $i++) {
            $units += $this->utf16Length(mb_substr($text, $i, 1));

            if ($units > $budget) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * @param  string[]  $separators
     */
    private function lastSeparator(string $text, array $separators): int
    {
        foreach ($separators as $separator) {
            $position = mb_strrpos($text, $separator);
            if ($position !== false && $position > 0) {
                return $position + mb_strlen($separator);
            }
        }

        return 0;
    }
}
