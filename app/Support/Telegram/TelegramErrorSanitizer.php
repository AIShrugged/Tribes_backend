<?php

namespace App\Support\Telegram;

/**
 * Strips Telegram bot token from error/exception messages before persisting or logging.
 *
 * The Telegram SDK (irazasyed/telegram-bot-sdk) includes the full request URL in
 * Guzzle exception traces, e.g.
 *   "Client error: `POST https://api.telegram.org/bot12345:ABCDE/sendMessage` ..."
 *
 * Without sanitization that token ends up in DB columns (issue_nudges.error,
 * decision_followups.error) and in laravel.log — making backups, staging clones,
 * and grep'able log archives effective vaults of the production bot token.
 */
final class TelegramErrorSanitizer
{
    public static function sanitize(string $message): string
    {
        // bot{digits}:{base64-ish} — Telegram bot token format
        return (string) preg_replace('#/bot\d+:[A-Za-z0-9_-]+/#', '/bot***/', $message);
    }
}
