<?php

namespace App\Services\Issue;

use App\Models\IssueConflict;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class ConflictAuthorNotifier
{
    /**
     * Notify each issue author with one Telegram message summarising all
     * conflict groups their issues appear in.
     *
     * @param  string[]  $groupUuids
     */
    public function notify(array $groupUuids): void
    {
        if (empty($groupUuids)) {
            return;
        }

        $rows = IssueConflict::query()
            ->whereIn('conflict_group_uuid', $groupUuids)
            ->where('status', IssueConflict::STATUS_OPEN)
            ->with(['issue.user.telegramUser'])
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // Group rows by (group_uuid). Each group has multiple members; we collect
        // unique issue → author. Each author may have several groups.
        $byAuthor = $this->groupByAuthor($rows);

        foreach ($byAuthor as $userId => $groupsForAuthor) {
            $this->notifyOne((int) $userId, $groupsForAuthor);
        }
    }

    /**
     * @return array<int, array<string, array{uuid:string, members:array<int, array{id:int,name:string}>, fields:string[], summary:string}>>
     *         userId => groupUuid => group payload
     */
    private function groupByAuthor(Collection $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $issue = $row->issue;
            $author = $issue?->user;
            if ($author === null || $author->is_demo === true) {
                continue;
            }

            $uuid = $row->conflict_group_uuid;
            $userId = $author->id;

            $grouped[$userId] ??= [];
            $grouped[$userId][$uuid] ??= [
                'uuid'    => $uuid,
                'members' => [],
                'fields'  => [],
                'summary' => $row->conflict_summary,
            ];

            $alreadyHasMember = false;
            foreach ($grouped[$userId][$uuid]['members'] as $m) {
                if ($m['id'] === $issue->id) {
                    $alreadyHasMember = true;
                    break;
                }
            }
            if (! $alreadyHasMember) {
                $grouped[$userId][$uuid]['members'][] = [
                    'id'   => $issue->id,
                    'name' => (string) $issue->name,
                ];
            }
            if (! in_array($row->field, $grouped[$userId][$uuid]['fields'], true)) {
                $grouped[$userId][$uuid]['fields'][] = $row->field;
            }
        }

        return $grouped;
    }

    private function notifyOne(int $userId, array $groups): void
    {
        $user = User::query()->with('telegramUser')->find($userId);
        $chatId = $user?->telegramUser?->telegram_user_id;

        if (! $chatId) {
            Log::info('ConflictAuthorNotifier: no telegram for author, skipping', [
                'user_id' => $userId,
                'groups'  => count($groups),
            ]);
            return;
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $chatId,
                'text'                     => $this->formatMessage($groups),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ConflictAuthorNotifier: telegram send failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, array{uuid:string, members:array<int, array{id:int,name:string}>, fields:string[], summary:string}>  $groups
     */
    private function formatMessage(array $groups): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $fieldLabels = [
            'requirements' => 'требования',
            'due_date'     => 'дедлайн',
            'assignee'     => 'исполнитель',
        ];

        $lines = ['⚠️ <b>Найдены конфликты по вашим задачам:</b>', ''];

        foreach (array_values($groups) as $i => $group) {
            $num = $i + 1;
            $links = array_map(
                fn (array $m) => '<a href="'.e("{$frontend}/dashboard/issues/{$m['id']}").'">'.e($m['name']).'</a>',
                $group['members'],
            );
            $fields = array_map(
                fn (string $f) => $fieldLabels[$f] ?? $f,
                $group['fields']
            );
            $fieldStr = $fields ? ' ('.e(implode(', ', $fields)).')' : '';

            $lines[] = "{$num}. ".implode(', ', $links).$fieldStr;
            $summary = trim((string) $group['summary']);
            if ($summary !== '') {
                $lines[] = '   '.e($summary);
            }
        }

        return implode("\n", $lines);
    }
}
