<?php

namespace App\Services\Agent\Support;

/**
 * Wraps untrusted, user-generated content so the LLM treats it as DATA, not
 * instructions. Strips any attempt to forge the delimiter so the content cannot
 * "break out" of the wrapper.
 */
class UntrustedContent
{
    public static function wrap(string $body, string $origin, string $note): string
    {
        $body = str_ireplace(['<untrusted_data>', '</untrusted_data>'], '', $body);

        return "<untrusted_data origin=\"{$origin}\">\n"
            . $note . "\n"
            . $body . "\n"
            . '</untrusted_data>';
    }
}
