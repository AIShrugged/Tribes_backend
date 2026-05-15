<?php

namespace App\Services\Issue;

use App\Models\Chat;
use App\Models\Issue;
use App\Services\Channel\ChannelBus;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class IncompleteContentNotifier
{
    public function __construct(
        private readonly ChannelBus $channelBus,
    ) {}

    /**
     * Notify the author of $issue that some required sections are missing.
     *
     * @param  string[]  $missingSectionKeys  e.g. ['context','dod']
     */
    public function notify(Issue $issue, array $missingSectionKeys): void
    {
        if (empty($missingSectionKeys)) {
            return;
        }

        $recipientUserId = $this->resolveRecipientUserId($issue);
        if ($recipientUserId === null) {
            Log::info('IncompleteContentNotifier: no eligible recipient, skipping', [
                'issue_id' => $issue->id,
            ]);
            return;
        }

        $issue->loadMissing('user.telegramUser');
        $author = $issue->user;
        if ($author === null || $author->id !== $recipientUserId) {
            $author = \App\Models\User::query()
                ->with('telegramUser')
                ->find($recipientUserId);
        }

        $missingLabels = $this->humanizeMissing($missingSectionKeys);

        $this->sendTelegram($author, $this->formatHtml($issue, $missingLabels), $issue);
        $this->postToInAppChat($author, $issue, $this->formatPlain($issue, $missingLabels));
    }

    private function resolveRecipientUserId(Issue $issue): ?int
    {
        $author = $issue->user;
        if ($author !== null && $author->is_demo !== true) {
            return $author->id;
        }

        // Meeting-sourced fallback: calendar owner.
        if ($issue->sourceable_type === \App\Models\CalendarEvent::class) {
            // sourceable.source isn't usually eager-loaded; fetch directly to avoid an
            // N+1 in batch validation runs.
            $event = \App\Models\CalendarEvent::query()
                ->with('source')
                ->find($issue->sourceable_id);
            $ownerId = $event?->source?->user_id;
            if ($ownerId !== null) {
                $owner = \App\Models\User::find($ownerId);
                if ($owner !== null && $owner->is_demo !== true) {
                    return $owner->id;
                }
            }
        }

        return null;
    }

    private function sendTelegram(?\App\Models\User $user, string $text, Issue $issue): void
    {
        $chatId = $user?->telegramUser?->telegram_user_id;
        if (! $chatId) {
            return;
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('IncompleteContentNotifier: telegram send failed', [
                'issue_id' => $issue->id,
                'user_id'  => $user?->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function postToInAppChat(?\App\Models\User $user, Issue $issue, string $text): void
    {
        if ($user === null) {
            return;
        }

        $chat = Chat::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($issue): void {
                if ($issue->team_id) {
                    $q->where('team_id', $issue->team_id);
                }
                if ($issue->organization_id) {
                    $q->orWhere('organization_id', $issue->organization_id);
                }
            })
            ->orderByDesc('updated_at')
            ->first();

        if ($chat === null) {
            Log::info('IncompleteContentNotifier: no chat found, skipping in-app', [
                'issue_id' => $issue->id,
                'user_id'  => $user->id,
            ]);
            return;
        }

        try {
            $this->channelBus->createChatAssistantMessage($chat, $text);
        } catch (\Throwable $e) {
            Log::warning('IncompleteContentNotifier: chat message failed', [
                'issue_id' => $issue->id,
                'chat_id'  => $chat->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  string[]  $missingSectionKeys
     * @return string[]
     */
    private function humanizeMissing(array $missingSectionKeys): array
    {
        $labels = (array) config('issue_validation.section_labels', []);
        return array_map(
            fn (string $key) => $labels[$key] ?? $key,
            $missingSectionKeys,
        );
    }

    /**
     * Telegram HTML payload (parse_mode=HTML).
     *
     * @param  string[]  $missingLabels
     */
    private function formatHtml(Issue $issue, array $missingLabels): string
    {
        $kind = $issue->isEpic() ? 'цели' : 'задаче';
        $name = e($issue->name);
        $missingStr = e(implode(', ', $missingLabels));
        $url = $this->frontendUrl($issue);

        return "📝 В {$kind} <a href=\"{$url}\">#{$issue->id} «{$name}»</a> не хватает: {$missingStr}.\nДозаполни в дашборде.";
    }

    /**
     * Plain-text payload (with inline URL) for the in-app AI Chat. The chat frontend does
     * not parse HTML, so a literal URL is the safe choice across renderers.
     *
     * @param  string[]  $missingLabels
     */
    private function formatPlain(Issue $issue, array $missingLabels): string
    {
        $kind = $issue->isEpic() ? 'цели' : 'задаче';
        $missingStr = implode(', ', $missingLabels);

        return "📝 В {$kind} #{$issue->id} «{$issue->name}» не хватает: {$missingStr}.\n"
            ."Дозаполни в дашборде: ".$this->frontendUrl($issue);
    }

    private function frontendUrl(Issue $issue): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return $frontend.'/dashboard/issues/'.$issue->id;
    }
}
