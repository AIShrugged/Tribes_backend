<?php

namespace App\Console\Commands;

use App\Models\CriticalPathDailyReminder;
use App\Models\CriticalPathNode;
use App\Services\CriticalPath\CriticalPathNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendCriticalPathDailyRemindersCommand extends Command
{
    protected $signature = 'critical-path:send-daily-reminders
        {--date= : Reminder date in YYYY-MM-DD format}
        {--test-user= : Send all messages to this Telegram user ID without writing reminder logs}';

    protected $description = 'Send one daily batched Telegram reminder for critical-path issues per participant';

    public function handle(CriticalPathNotificationService $notificationService): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), config('app.timezone'))->toDateString()
            : Carbon::today(config('app.timezone'))->toDateString();

        $testUser = $this->option('test-user');
        $nodes = $this->loadCriticalNodes();

        if ($nodes->isEmpty()) {
            $this->info('No critical path issues found.');

            return self::SUCCESS;
        }

        $batches = $this->buildUserBatches($nodes, $date, (bool) $testUser);
        $sent = 0;

        foreach ($batches as $userId => $items) {
            $user = $items->first()['user'];

            $sentOk = $notificationService->notifyParticipantBatch(
                $user,
                $items,
                $testUser ? (int) $testUser : null,
            );

            if (! $sentOk) {
                continue;
            }

            $sent++;

            if ($testUser) {
                continue;
            }

            foreach ($items as $item) {
                CriticalPathDailyReminder::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'issue_id' => $item['issue']->id,
                        'date' => $date,
                    ],
                    [
                        'graph_id' => $item['graph_id'],
                        'sent_at' => now(),
                    ],
                );
            }
        }

        $this->info("Sent {$sent} critical path reminder batch(es).");

        return self::SUCCESS;
    }

    private function loadCriticalNodes(): Collection
    {
        return CriticalPathNode::query()
            ->where('node_type', 'issue')
            ->where('is_critical', true)
            ->whereHas('graph', fn ($query) => $query->where('status', 'ready'))
            ->whereHas('issue', fn ($query) => $query->whereIn('status', ['open', 'in_progress']))
            ->with([
                'graph:id,team_id,organization_id,status',
                'graph.team:id,name',
                'issue:id,name,status,priority,due_date,assignee_id,user_id,team_id,organization_id',
                'issue.assignee.telegramUser',
                'issue.user.telegramUser',
            ])
            ->orderBy('early_start')
            ->get();
    }

    private function buildUserBatches(Collection $nodes, string $date, bool $testMode): Collection
    {
        $batches = collect();
        $seen = [];

        foreach ($nodes as $node) {
            $issue = $node->issue;

            if (! $issue) {
                continue;
            }

            $recipients = collect([$issue->assignee, $issue->user])
                ->filter()
                ->unique('id');

            foreach ($recipients as $user) {
                if (! $testMode && ! $user->telegramUser?->telegram_user_id) {
                    continue;
                }

                $key = $user->id.':'.$issue->id;
                if (isset($seen[$key])) {
                    continue;
                }

                if (! $testMode && $this->alreadySent($user->id, $issue->id, $date)) {
                    continue;
                }

                $seen[$key] = true;

                $batches->put($user->id, $batches->get($user->id, collect())->push([
                    'user' => $user,
                    'issue' => $issue,
                    'node' => $node,
                    'graph_id' => $node->graph_id,
                    'team' => $node->graph?->team,
                ]));
            }
        }

        return $batches;
    }

    private function alreadySent(int $userId, int $issueId, string $date): bool
    {
        return CriticalPathDailyReminder::query()
            ->where('user_id', $userId)
            ->where('issue_id', $issueId)
            ->whereDate('date', $date)
            ->exists();
    }
}
