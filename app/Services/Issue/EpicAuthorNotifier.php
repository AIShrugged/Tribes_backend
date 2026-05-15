<?php

namespace App\Services\Issue;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class EpicAuthorNotifier
{
    /**
     * Notify each epic author with one Telegram message listing all his created/updated epics.
     *
     * @param  array{created: Collection<int, Issue>, updated: Collection<int, Issue>}  $result
     */
    public function notifyBatch(array $result, CalendarEvent $event): void
    {
        $created = $result['created'] ?? collect();
        $updated = $result['updated'] ?? collect();

        if ($created->isEmpty() && $updated->isEmpty()) {
            return;
        }

        $all = $created
            ->map(fn (Issue $e) => ['epic' => $e, 'kind' => 'created'])
            ->concat($updated->map(fn (Issue $e) => ['epic' => $e, 'kind' => 'updated']));

        $byAuthor = $all->groupBy(fn (array $row) => $row['epic']->user_id);

        foreach ($byAuthor as $userId => $rows) {
            $this->notifyOne((int) $userId, $rows, $event);
        }
    }

    private function notifyOne(int $userId, Collection $rows, CalendarEvent $event): void
    {
        $user = User::query()->with('telegramUser')->find($userId);
        $chatId = $user?->telegramUser?->telegram_user_id;
        if (! $chatId) {
            return;
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $chatId,
                'text'                     => $this->formatMessage($rows, $event),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EpicAuthorNotifier: send failed', [
                'user_id'           => $userId,
                'calendar_event_id' => $event->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage(Collection $rows, CalendarEvent $event): string
    {
        $meetingTitle = e($event->title ?? 'встреча');
        $frontendUrl  = rtrim((string) config('app.frontend_url'), '/');

        $lines = ["🎯 По встрече <b>{$meetingTitle}</b>:", ''];

        foreach ($rows as $row) {
            /** @var Issue $epic */
            $epic   = $row['epic'];
            $verb   = $row['kind'] === 'created' ? 'Создан эпик' : 'Обновлён эпик';
            $name   = e($epic->name);
            $url    = "{$frontendUrl}/dashboard/issues/{$epic->id}";
            $lines[] = "• {$verb}: <a href=\"{$url}\">{$name}</a>";
        }

        return implode("\n", $lines);
    }
}
