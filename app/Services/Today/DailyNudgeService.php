<?php

namespace App\Services\Today;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\DailyNudge;
use App\Models\Issue;
use App\Models\Source;
use App\Models\User;
use App\Services\Meeting\MeetingContextService;
use App\Services\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DailyNudgeService
{
    public function __construct(
        private readonly MeetingContextService $meetingContext,
    ) {}

    /**
     * Get nudge for user+date. Returns from DB if fresh, null otherwise.
     */
    public function getCached(int $userId, Carbon $date): ?string
    {
        $nudge = DailyNudge::query()
            ->where('user_id', $userId)
            ->where('date', $date->format('Y-m-d'))
            ->first();

        if (! $nudge || $nudge->isExpired()) {
            return null;
        }

        return $nudge->text;
    }

    /**
     * Generate nudge text for a user on a given date.
     * Saves to DB. Returns cached if fresh.
     */
    public function generate(User $user, Carbon $date): ?string
    {
        // Check DB first
        $existing = DailyNudge::query()
            ->where('user_id', $user->id)
            ->where('date', $date->format('Y-m-d'))
            ->first();

        if ($existing && ! $existing->isExpired()) {
            return $existing->text;
        }

        try {
            $context = $this->buildContext($user, $date);

            if (empty($context)) {
                return null;
            }

            $prompt = $this->buildPrompt($context);

            $response = OpenRouterClient::chat(
                messages: [new MessageDTO('user', $prompt)],
                model: config('ai.providers.openrouter.models.today_nudge', 'google/gemini-3.1-pro-preview'),
                maxTokens: 1024,
            );

            $nudge = trim($response);
            $nudge = trim($nudge, '"\'');

            if (empty($nudge) || mb_strlen($nudge) > 500) {
                return null;
            }

            $this->save($user->id, $date, $nudge);

            return $nudge;
        } catch (\Throwable $e) {
            Log::warning('DailyNudgeService: failed to generate nudge', [
                'user_id' => $user->id,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function save(int $userId, Carbon $date, string $text): void
    {
        DailyNudge::updateOrCreate(
            ['user_id' => $userId, 'date' => $date->format('Y-m-d')],
            [
                'text' => $text,
                'expires_at' => $date->copy()->endOfDay(),
            ],
        );
    }

    private function buildContext(User $user, Carbon $date): array
    {
        $context = [];

        $startOfDay = $date->copy()->startOfDay()->utc();
        $endOfDay = $date->copy()->endOfDay()->utc();

        $sourceIds = Source::query()->where('user_id', $user->id)->pluck('id');

        $events = CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds) {
                $q->whereHas('sources', fn($sq) => $sq->where('user_id', $user->id));
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
            })
            ->whereBetween('starts_at', [$startOfDay, $endOfDay])
            ->orderBy('starts_at')
            ->get();

        if ($events->isNotEmpty()) {
            $context['events'] = $events->map(fn(CalendarEvent $e) => $e->title)->implode(', ');
        }

        $overdue = Issue::query()
            ->withoutTrashed()
            ->where('assignee_id', $user->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::today())
            ->limit(10)
            ->get();

        if ($overdue->isNotEmpty()) {
            $context['overdue_tasks'] = $overdue->map(function (Issue $i) {
                $days = (int) Carbon::parse($i->due_date)->diffInDays(Carbon::today());
                return "{$i->name} ({$days}d overdue)";
            })->implode('; ');
        }

        $userEventIds = CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds) {
                $q->whereHas('sources', fn($sq) => $sq->where('user_id', $user->id));
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
            })
            ->pluck('id');

        $allOpen = $userEventIds->isEmpty() ? collect() : Issue::query()
            ->withoutTrashed()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $userEventIds)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with('sourceable')
            ->limit(20)
            ->get();

        $staleTasks = collect();
        foreach ($allOpen as $issue) {
            $syncs = $this->meetingContext->countSyncsSinceCreated($issue);
            if ($syncs >= 2) {
                $staleTasks->push("{$issue->name} ({$syncs} syncs without progress)");
            }
        }

        if ($staleTasks->isNotEmpty()) {
            $context['stale_tasks'] = $staleTasks->implode('; ');
        }

        $waiting = Issue::query()
            ->withoutTrashed()
            ->where('assignee_id', $user->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->limit(10)
            ->get();

        if ($waiting->isNotEmpty()) {
            $context['waiting_on_you'] = $waiting->count() . ' tasks assigned to you';
        }

        return $context;
    }

    private function buildPrompt(array $context): string
    {
        $data = '';

        if (isset($context['events'])) {
            $data .= "- Сегодняшние встречи: {$context['events']}\n";
        }
        if (isset($context['overdue_tasks'])) {
            $data .= "- Просроченные задачи: {$context['overdue_tasks']}\n";
        }
        if (isset($context['stale_tasks'])) {
            $data .= "- Застрявшие задачи (без прогресса >2 синков): {$context['stale_tasks']}\n";
        }
        if (isset($context['waiting_on_you'])) {
            $data .= "- Задачи, ожидающие тебя: {$context['waiting_on_you']}\n";
        }

        return <<<PROMPT
Ты — AI-ассистент руководителя. Проанализируй данные и напиши ОДНО предложение — самое важное наблюдение или совет на сегодня. Максимум 200 символов. На русском языке.

Данные:
{$data}
Ответь только текстом предупреждения, без кавычек и форматирования.
PROMPT;
    }
}
