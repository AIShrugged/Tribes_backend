<?php

namespace App\Services;

use App\Models\TelegramLinkToken;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function __construct(
        private readonly ProfileLinkingService $profileLinking,
    ) {}

    public function generateLink(User $user): TelegramLinkToken
    {
        TelegramLinkToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        return TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function getLinkUrl(TelegramLinkToken $linkToken): string
    {
        $botUsername = config('telegram.bot_username');

        return "https://t.me/{$botUsername}?start={$linkToken->token}";
    }

    public function consumeToken(string $rawToken, int $telegramUserId, ?string $username): void
    {
        $linkToken = TelegramLinkToken::where('token', $rawToken)->first();

        if ($linkToken === null) {
            throw new \RuntimeException('Ссылка недействительна.');
        }

        if ($linkToken->used_at !== null) {
            throw new \RuntimeException('Ссылка уже была использована.');
        }

        if ($linkToken->expires_at->isPast()) {
            throw new \RuntimeException('Срок действия ссылки истёк.');
        }

        $this->profileLinking->linkTelegramUser($linkToken->user, $telegramUserId);

        if ($username !== null) {
            TelegramUser::where('telegram_user_id', $telegramUserId)
                ->update(['telegram_username' => $username]);
        }

        $linkToken->update(['used_at' => now()]);
    }
}
