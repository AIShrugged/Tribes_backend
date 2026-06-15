<?php

namespace App\Services\Telegram;

/**
 * Converts the agent's Markdown output into the small HTML subset Telegram
 * accepts (parse_mode=HTML). HTML is preferred over legacy Markdown V1 because
 * escaping is trivial (only &, <, >) and the parser is far less likely to reject
 * the whole message over a stray character.
 *
 * Code spans/blocks are extracted before escaping so their contents are never
 * reinterpreted as emphasis, then restored verbatim at the end.
 */
class TelegramHtmlFormatter
{
    public function toHtml(string $markdown): string
    {
        $text = str_replace("\r\n", "\n", $markdown);

        $placeholders = [];
        $store = function (string $html) use (&$placeholders): string {
            $key = "\x00PH".count($placeholders)."\x00";
            $placeholders[$key] = $html;

            return $key;
        };

        // Fenced code blocks ```lang\n...``` → <pre>…</pre>
        $text = preg_replace_callback('/```[a-zA-Z0-9_+\-]*\n?(.*?)```/s', function (array $m) use ($store): string {
            return $store('<pre>'.$this->escape($m[1]).'</pre>');
        }, $text) ?? $text;

        // Inline code `...` → <code>…</code>
        $text = preg_replace_callback('/`([^`\n]+)`/', function (array $m) use ($store): string {
            return $store('<code>'.$this->escape($m[1]).'</code>');
        }, $text) ?? $text;

        // Escape everything that's left before injecting our own tags.
        $text = $this->escape($text);

        // Links [text](url) → <a href="url">text</a>
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m): string {
            return '<a href="'.$m[2].'">'.$m[1].'</a>';
        }, $text) ?? $text;

        // Block-level before inline emphasis so leading "*"/"-" bullets aren't
        // mistaken for italic markers.
        $text = preg_replace('/^\s{0,3}#{1,6}\s*(.+?)\s*$/m', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/^(\s*)[-*]\s+/m', '$1• ', $text) ?? $text;

        // Inline emphasis. Bold (**) before italic (*).
        $text = preg_replace('/\*\*([^\n]+?)\*\*/', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/~~([^\n]+?)~~/', '<s>$1</s>', $text) ?? $text;
        $text = preg_replace('/\*([^*\n]+?)\*/', '<i>$1</i>', $text) ?? $text;

        return strtr($text, $placeholders);
    }

    /**
     * Strip Markdown/HTML markers to a plain-text fallback used when Telegram
     * still rejects the formatted payload.
     */
    public function toPlainText(string $markdown): string
    {
        $text = str_replace("\r\n", "\n", $markdown);
        $text = preg_replace('/```[a-zA-Z0-9_+\-]*\n?(.*?)```/s', '$1', $text) ?? $text;
        $text = str_replace('`', '', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text;
        $text = preg_replace('/^(\s*)[-*]\s+/m', '$1• ', $text) ?? $text;
        $text = str_replace(['**', '__', '~~'], '', $text);

        return $text;
    }

    private function escape(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }
}
